<?php

declare(strict_types=1);

/**
 * ============================================================
 * EMSHOP Swoole 服务
 * ============================================================
 *
 * 什么是 Swoole？
 *   Swoole 是 PHP 的一个扩展，可以让 PHP 像 Node.js 一样常驻内存运行，
 *   不再是"每次请求启动→执行→销毁"的传统模式。
 *   好处：性能高、可以做定时任务、可以做异步队列消费。
 *
 * 本文件的作用：
 *   1. 启动一个 HTTP API 服务（端口 9601）—— 供后台"Swoole监控"页面查询状态
 *   2. 启动队列消费者 —— 每隔 2 秒检查 em_delivery_queue 表，取出待发货任务执行
 *   3. 启动定时任务 —— 每隔 60 秒检查超时未支付的订单并自动关闭
 *
 * 进程模型（简要说明）：
 *   ┌─────────────┐
 *   │ Master 进程  │  主进程，负责管理 Worker 进程，不处理业务
 *   └──────┬──────┘
 *          │ fork（创建子进程）
 *     ┌────┴────┐
 *     ▼         ▼
 *  Worker #0  Worker #1    工作进程，每个都是独立进程
 *  (定时器+请求) (仅请求)   Worker #0 额外负责定时任务和队列消费
 *
 * 使用方式：
 *   php swoole/server.php start    启动服务（前台运行，Ctrl+C 停止）
 *   php swoole/server.php stop     停止服务
 *   php swoole/server.php status   查看是否在运行
 */

// 定义项目根目录（dirname(__DIR__) 就是 swoole/ 的上一级目录，即项目根目录）
define('EM_ROOT', dirname(__DIR__));

// ============================================================
// 配置项
// ============================================================
// 为什么用 define 常量而不是 $config 数组？
// 因为 Swoole 启动时会 fork（复制）出多个 Worker 子进程，
// 普通 PHP 变量在 fork 后的子进程闭包里可能丢失，但 define 的常量全局可用。

define('SW_HOST', '0.0.0.0');          // 监听地址，0.0.0.0 表示所有网卡都能访问
define('SW_PORT', 9601);               // 监听端口，后台监控页面通过这个端口通信
define('SW_PID_FILE', EM_ROOT . '/swoole/swoole.pid');  // PID 文件，记录主进程 ID，用于 stop/status
define('SW_LOG_FILE', EM_ROOT . '/swoole/swoole.log');  // 日志文件，Swoole 内部日志输出到这里
define('SW_HEARTBEAT_FILE', EM_ROOT . '/swoole/swoole.heartbeat');  // 心跳文件，前台 Dispatcher 据此判断 swoole 是否存活
define('SW_QUEUE_INTERVAL', 2);        // 队列消费间隔（秒），每隔 2 秒查一次队列
define('SW_TIMER_INTERVAL', 60);       // 定时任务间隔（秒），每隔 60 秒执行一次
define('SW_HEARTBEAT_INTERVAL', 5);    // 心跳间隔（秒），前台阈值比它大得多（15s）留出缓冲

// ============================================================
// 命令解析（start / stop / status）
// ============================================================
// $argv 是 PHP CLI 模式下的命令行参数数组
// $argv[0] = "swoole/server.php"（脚本自身）
// $argv[1] = "start" 或 "stop" 或 "status"（用户传入的命令）
$command = $argv[1] ?? 'start';

switch ($command) {
    case 'stop':
        // 读取 PID 文件，向主进程发送 SIGTERM 信号（优雅关闭）
        $pid = getPid(SW_PID_FILE);
        if ($pid && posix_kill($pid, 0)) {  // posix_kill($pid, 0) 检查进程是否存在
            posix_kill($pid, SIGTERM);       // 发送终止信号
            echo "Swoole server stopped (PID: {$pid})\n";
        } else {
            echo "Swoole server is not running\n";
        }
        exit(0);

    case 'status':
        $pid = getPid(SW_PID_FILE);
        if ($pid && posix_kill($pid, 0)) {
            echo "Swoole server is running (PID: {$pid})\n";
        } else {
            echo "Swoole server is not running\n";
        }
        exit(0);

    case 'reload':
        // 平滑重启所有 Worker：Master 收到 SIGUSR1 后会逐个替换 Worker，
        // 期间 HTTP 请求不丢、PID 不变；常用于"加新插件 / 改插件代码"后让代码生效。
        // 注意：定时器任务会在 worker 重启那一刻短暂丢一拍（最多 SW_TIMER_INTERVAL 秒），可接受。
        $pid = getPid(SW_PID_FILE);
        if ($pid && posix_kill($pid, 0)) {
            // SIGUSR1 = swoole 内置的"reload all workers"信号，无需我们写 handler
            posix_kill($pid, SIGUSR1);
            echo "Swoole server reload signal sent (PID: {$pid})\n";
        } else {
            echo "Swoole server is not running, cannot reload\n";
            exit(1);
        }
        exit(0);

    case 'start':
        break;  // 继续往下执行启动逻辑

    default:
        echo "Usage: php server.php {start|stop|status|reload}\n";
        exit(1);
}

// ============================================================
// 创建 HTTP 服务器
// ============================================================
// Swoole\Http\Server 是一个内置的 HTTP 服务器，类似 Nginx 但是用 PHP 写逻辑。
// 它会常驻内存，监听指定的端口，收到 HTTP 请求时触发 on('request') 回调。
$server = new Swoole\Http\Server(SW_HOST, SW_PORT);

// 服务器配置
$server->set([
    // Worker 进程数量。每个 Worker 是一个独立进程，可以并行处理请求。
    // 一般设为 CPU 核心数的 1-2 倍。开发环境 2 个足够。
    'worker_num'      => 2,

    // 是否守护进程化（后台运行）。
    // false = 前台运行（终端可以看到输出，Ctrl+C 可停止）
    // true  = 后台运行（终端关闭后服务不停止，需要用 stop 命令停止）
    'daemonize'       => false,

    // PID 文件路径。Swoole 启动后自动把主进程 PID 写入这个文件，
    // stop/status 命令通过读取这个文件来找到进程。
    'pid_file'        => SW_PID_FILE,

    // 日志文件路径。Swoole 内部的日志（如 Worker 崩溃、重启等）输出到这里。
    'log_file'        => SW_LOG_FILE,

    // 日志级别。SWOOLE_LOG_INFO = 记录一般信息和警告。
    // 可选：SWOOLE_LOG_DEBUG(最详细) / SWOOLE_LOG_INFO / SWOOLE_LOG_WARNING / SWOOLE_LOG_ERROR
    'log_level'       => SWOOLE_LOG_INFO,
]);

// 服务启动时间（用于计算运行时长）
$startTime = time();

// 运行统计（内存中维护，服务重启后归零）
$stats = [
    'queue_processed' => 0,  // 队列任务成功处理数
    'queue_failed'    => 0,  // 队列任务失败数
    'timers_run'      => 0,  // 定时任务执行次数
];

// ============================================================
// 事件回调：服务启动 (on start)
// ============================================================
// 当主进程启动完成后触发，只执行一次。
// 注意：这是在 Master 进程中执行的，不是 Worker 进程。
$server->on('start', function (Swoole\Http\Server $server) use (&$startTime) {
    echo "EMSHOP Swoole Server started at " . SW_HOST . ":" . SW_PORT . "\n";
    echo "PID: {$server->master_pid}\n";
    $startTime = time();
});

// ============================================================
// 事件回调：Worker 进程启动 (on workerStart)
// ============================================================
// 每个 Worker 子进程启动时都会触发。如果 worker_num=2，则触发 2 次（$workerId=0 和 $workerId=1）。
//
// 关键点：Worker 是 fork（复制）出来的子进程，主进程中加载的类和数据库连接不能直接用，
// 所以我们在这里重新加载整个业务框架（init.php）。
$server->on('workerStart', function (Swoole\Http\Server $server, int $workerId) use (&$stats) {

    // ----- WSL 环境特殊处理 -----
    // 如果在 WSL（Windows 下的 Linux 子系统）中运行，
    // 数据库装在 Windows 侧，WSL 里的 127.0.0.1 是自己而不是 Windows，
    // 需要通过网关 IP 连接 Windows 侧的 MySQL。
    // 生产环境（Linux 独立服务器）不需要这段，数据库直接用 127.0.0.1 就行。
    if (PHP_OS_FAMILY === 'Linux' && is_file('/proc/version') && str_contains(file_get_contents('/proc/version'), 'microsoft')) {
        $gwIp = trim(shell_exec("ip route show default 2>/dev/null | awk '{print \$3}'") ?: '');
        if ($gwIp !== '') {
            // 设置环境变量，Database 类会读取这个变量覆盖 config.php 中的 host
            putenv("EM_DB_HOST={$gwIp}");
        }
    }

    // 加载 EMSHOP 业务框架（自动加载器、数据库、配置、钩子、插件等）
    require EM_ROOT . '/init.php';

    // ----- 定时器只在 Worker #0 上启动 -----
    // 避免多个 Worker 同时消费队列导致重复执行。
    // Worker #1 只负责处理 HTTP 请求，不启动定时器。
    if ($workerId !== 0) {
        return;
    }

    // ----- 心跳文件：启动时立即写一次，避免启动瞬间前台判挂 -----
    // 前台 Dispatcher 用 filemtime(swoole.heartbeat) 判断本服务是否存活
    @touch(SW_HEARTBEAT_FILE);

    // ----- 启动时记一次代码版本号快照 -----
    // 后台启用/禁用/安装/卸载带 Swoole:true 标记的插件时，会调 PluginModel::bumpSwooleCodeVersion()
    // 推进 swoole_code_version。timer 里会对比当前值 vs 此 boot 快照，
    // 不一致就 $server->reload()，让 worker 重新 require init.php 加载新代码。
    Config::reload();
    $bootCodeVersion = (string) (Config::get('swoole_code_version') ?? '');

    // ----- 暴露 swoole_worker_start 钩子（任意间隔定时器入口）-----
    // 插件想要"每 10s / 5s / 任意间隔"跑代码时，挂这个钩子并在里面调 Swoole\Timer::tick()。
    // 钩子参数：($server, $workerId)；仅 worker #0 触发，避免多 worker 重复注册同一个 timer。
    //
    // 用法示例（插件主文件里）：
    //   addAction('swoole_worker_start', function ($server, $workerId) {
    //       Swoole\Timer::tick(10000, function () {   // 每 10 秒
    //           // 你的逻辑
    //       });
    //   });
    //
    // 注意：worker reload 时 Timer 会一起被销毁，新 worker 重跑本钩子重新注册，无需手动清理。
    try {
        doAction('swoole_worker_start', $server, $workerId);
    } catch (Throwable $e) {
        error_log('[Swoole Hook] swoole_worker_start: ' . $e->getMessage());
    }

    // ----- 定时器 1：队列消费 -----
    // Swoole\Timer::tick(毫秒, 回调) 每隔指定时间执行一次回调函数。
    // 这里每 2 秒检查一次 em_delivery_queue 表，取出待处理的发货任务执行。
    Swoole\Timer::tick(SW_QUEUE_INTERVAL * 1000, function () use (&$stats) {
        try {
            processQueue($stats);
        } catch (Throwable $e) {
            error_log("[Queue Error] " . $e->getMessage());
        }
    });

    // ----- 定时器 2：定时任务 + 代码版本检测自动 reload -----
    // 每 60 秒执行一次：先检查代码版本号有没有被后台 bump（插件改动），变了就 reload；
    // 否则继续走 runTimerTasks()。runTimerTasks 内部还会再 Config::reload()，影响极小。
    Swoole\Timer::tick(SW_TIMER_INTERVAL * 1000, function () use (&$stats, $server, $bootCodeVersion) {
        try {
            // bootCodeVersion 是 use by-value 的快照，只在 worker 启动时采样一次；
            // reload 后整个 worker 重启 → 新 worker 重跑 workerStart → 重新采样新值
            Config::reload();
            $current = (string) (Config::get('swoole_code_version') ?? '');
            if ($current !== '' && $current !== $bootCodeVersion) {
                error_log("[Swoole] code version changed ({$bootCodeVersion} -> {$current}), reloading workers");
                $server->reload();
                return; // 此 tick 不再继续，老 worker 会被替换
            }
            runTimerTasks($stats);
        } catch (Throwable $e) {
            error_log("[Timer Error] " . $e->getMessage());
        }
    });

    // ----- 定时器 3：心跳 -----
    // 每 5 秒 touch 一次心跳文件，前台据此判断 swoole 是否存活。
    // 用 touch 只改 mtime、不写内容，I/O 开销几乎为 0。
    Swoole\Timer::tick(SW_HEARTBEAT_INTERVAL * 1000, function () {
        @touch(SW_HEARTBEAT_FILE);
    });

    echo "Worker #{$workerId} timers started\n";
});

// ============================================================
// 事件回调：收到 HTTP 请求 (on request)
// ============================================================
// 每当有 HTTP 请求到达端口 9601 时触发。
// $request  = 请求对象（包含 URL、GET/POST 参数、Headers 等）
// $response = 响应对象（用于返回 JSON 数据）
//
// 这些 API 供后台"Swoole监控"页面调用，不是给前台用户的。
$server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($server, &$startTime, &$stats) {
    // 设置响应头
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->header('Access-Control-Allow-Origin', '*');  // 允许跨域（后台页面可能和 Swoole 不同端口）

    // 获取请求路径（如 /status、/queue/recent）
    $path = $request->server['request_uri'] ?? '/';

    switch ($path) {
        // ===== 获取服务状态 =====
        case '/status':
            // $server->stats() 返回 Swoole 内部统计信息（Worker 数、连接数等）
            $swooleStats = $server->stats();
            $prefix = Database::prefix();

            // 从数据库查询队列任务统计
            $queueStats = Database::fetchOne(
                "SELECT COUNT(*) as total,
                    SUM(status='pending') as pending, SUM(status='processing') as processing,
                    SUM(status='success') as success, SUM(status='failed') as failed
                 FROM {$prefix}delivery_queue"
            );

            $response->end(json_encode([
                'code' => 200,
                'data' => [
                    'running'     => true,                           // 能响应就说明在运行
                    'pid'         => $server->master_pid,            // 主进程 PID
                    'uptime'      => time() - $startTime,            // 运行时长（秒）
                    'workers'     => $swooleStats['worker_num'] ?? 0,      // Worker 进程数
                    'connections' => $swooleStats['connection_num'] ?? 0,   // 当前连接数
                    'queue'       => [                               // 队列统计
                        'total'      => (int) ($queueStats['total'] ?? 0),
                        'pending'    => (int) ($queueStats['pending'] ?? 0),
                        'processing' => (int) ($queueStats['processing'] ?? 0),
                        'success'    => (int) ($queueStats['success'] ?? 0),
                        'failed'     => (int) ($queueStats['failed'] ?? 0),
                    ],
                    'stats' => $stats,  // 本次启动以来的处理统计
                ],
            ], JSON_UNESCAPED_UNICODE));
            break;

        // ===== 获取最近 20 条队列任务 =====
        case '/queue/recent':
            $prefix = Database::prefix();
            $rows = Database::query(
                "SELECT id, order_id, task_type, goods_type, status, attempts, max_attempts, last_error, created_at, completed_at
                 FROM {$prefix}delivery_queue ORDER BY id DESC LIMIT 20"
            );
            $response->end(json_encode(['code' => 200, 'data' => $rows], JSON_UNESCAPED_UNICODE));
            break;

        // ===== 重试失败的任务 =====
        case '/queue/retry':
            if ($request->server['request_method'] !== 'POST') {
                $response->end(json_encode(['code' => 400, 'msg' => 'POST only']));
                break;
            }
            $id = (int) ($request->post['id'] ?? 0);
            if ($id > 0) {
                $prefix = Database::prefix();
                // 将失败的任务重置为 pending 状态，清除错误信息和重试次数
                Database::execute(
                    "UPDATE {$prefix}delivery_queue SET status='pending', attempts=0, last_error=NULL, next_retry_at=NULL WHERE id=? AND status='failed'",
                    [$id]
                );
            }
            $response->end(json_encode(['code' => 200, 'msg' => '已重置']));
            break;

        default:
            $response->end(json_encode(['code' => 404, 'msg' => 'Not Found']));
    }
});

// ============================================================
// 启动服务器
// ============================================================
// 调用 start() 后，程序会阻塞在这里（不会执行后面的代码），
// 直到收到 SIGTERM 信号（stop 命令）或 Ctrl+C 才会退出。
$server->start();

// ============================================================
// 辅助函数（定义在 start() 后面也没问题，PHP 函数声明会被提升）
// ============================================================

/**
 * 读取 PID 文件，返回进程 ID。
 */
function getPid(string $pidFile): int
{
    if (!is_file($pidFile)) {
        return 0;
    }
    return (int) file_get_contents($pidFile);
}

/**
 * 队列消费函数。
 *
 * 执行流程：
 * 1. 从 em_delivery_queue 取一条 pending/retry 状态的任务
 * 2. 用 UPDATE 抢占（防止多进程重复消费）
 * 3. 调用商品类型插件的 order_paid 钩子执行发货
 * 4. 成功则标记 success，失败则重试或标记 failed
 *
 * 重试策略：
 * - 最多重试 3 次（max_attempts 字段控制）
 * - 重试间隔递增：第1次 30秒，第2次 120秒，第3次 300秒
 * - 超过最大重试次数后标记为 failed，需要管理员在后台手动处理
 */
function processQueue(array &$stats): void
{
    // 常驻进程每次处理任务前刷新一次 Config 缓存，
    // 确保管理员在 admin 里改的开关/费率能被 Worker 即时看到
    // （否则 Worker 里的 Config::$items 是启动那一刻读的旧值）
    Config::reload();

    $prefix = Database::prefix();

    // 查找一条待处理的任务（pending 或 retry 状态，且到达重试时间）
    $task = Database::fetchOne(
        "SELECT * FROM {$prefix}delivery_queue
         WHERE status IN ('pending','retry') AND (next_retry_at IS NULL OR next_retry_at <= NOW())
         ORDER BY id ASC LIMIT 1"
    );

    if (!$task) {
        return;  // 没有待处理任务，直接返回
    }

    $taskId = (int) $task['id'];

    // 抢占式更新：将状态改为 processing，同时 attempts +1。
    // 如果 affected_rows=0，说明被其他 Worker 抢走了，直接返回。
    $affected = Database::execute(
        "UPDATE {$prefix}delivery_queue SET status='processing', attempts=attempts+1 WHERE id=? AND status IN ('pending','retry')",
        [$taskId]
    );
    if ($affected === 0) {
        return;  // 任务已被其他进程抢走
    }

    try {
        $orderId = (int) $task['order_id'];
        $orderGoodsId = (int) $task['order_goods_id'];
        $goodsType = $task['goods_type'];
        $payload = json_decode($task['payload'] ?? '{}', true) ?: [];

        // 调用商品类型插件的发货钩子。
        // 例如 goods_type = 'virtual_card' 时，会调用虚拟卡密商品插件的发货逻辑（从卡密库取卡密发给用户）。
        doAction("goods_type_{$goodsType}_order_paid", $orderId, $orderGoodsId, json_encode($payload));

        // 发货成功，标记任务完成
        Database::execute(
            "UPDATE {$prefix}delivery_queue SET status='success', completed_at=NOW() WHERE id=?",
            [$taskId]
        );
        $stats['queue_processed']++;

        // 检查该订单所有商品是否都已发货完成，完成则更新订单状态
        OrderModel::checkDeliveryComplete($orderId);

    } catch (Throwable $e) {
        // 发货失败，判断是否还能重试
        $attempts = (int) $task['attempts'] + 1;
        $maxAttempts = (int) $task['max_attempts'];

        // 永久性失败（插件识别出业务错误，如上游余额不足等）直接跳过重试
        $isPermanent = $e instanceof PermanentDeliveryException;

        if ($isPermanent || $attempts >= $maxAttempts) {
            // 重试次数已耗尽或业务错误不可重试 → 标记为失败
            Database::execute(
                "UPDATE {$prefix}delivery_queue SET status='failed', last_error=? WHERE id=?",
                [($isPermanent ? '[永久失败] ' : '') . $e->getMessage(), $taskId]
            );
            $stats['queue_failed']++;
        } else {
            // 设置下次重试时间（递增间隔：30秒 → 120秒 → 300秒）
            $delay = min(300, 30 * pow(2, $attempts - 1));
            Database::execute(
                "UPDATE {$prefix}delivery_queue SET status='retry', last_error=?, next_retry_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?",
                [$e->getMessage(), $delay, $taskId]
            );
        }
    }
}

/**
 * 定时任务函数。
 *
 * 目前包含：
 * - 订单超时检查：将超过指定时间未支付的订单自动标记为 expired（已过期）
 *
 * 后续可在此函数中添加更多定时任务，如：
 * - 主动查询型发货的轮询
 * - 数据统计汇总
 * - 缓存清理
 */
function runTimerTasks(array &$stats): void
{
    // 常驻进程跑定时任务前也刷新一次 Config 缓存
    Config::reload();

    $prefix = Database::prefix();

    // 订单超时关闭：从后台配置读取超时时间（默认 30 分钟）
    $expireMinutes = (int) (Config::get('shop_order_expire_minutes', '30') ?: 30);

    // 将超时的 pending 订单标记为 expired
    $expired = Database::execute(
        "UPDATE {$prefix}order SET status='expired'
         WHERE status='pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$expireMinutes]
    );

    if ($expired > 0) {
        error_log("[Timer] Expired {$expired} orders");
    }

    // 插件可通过 addAction('swoole_timer_tick') 注册周期任务
    // （这里是每 60 秒一次；插件内部如需更长间隔自行做节流）
    try {
        doAction('swoole_timer_tick');
    } catch (Throwable $e) {
        error_log('[Timer Hook] ' . $e->getMessage());
    }

    $stats['timers_run']++;
}
