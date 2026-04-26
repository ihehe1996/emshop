<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * Swoole 监控页面。
 */
adminRequireLogin();

$csrfToken = Csrf::token();
// Swoole API 地址（后台系统设置中配置 swoole_api_url，生产环境填 http://127.0.0.1:9601）
$swooleApiUrl = Config::get('swoole_api_url', 'http://127.0.0.1:9601');

// AJAX 请求：代理转发到 Swoole HTTP API
if (Request::isPost()) {
    $action = (string) Input::post('_action', '');
    $csrf = (string) Input::post('csrf_token', '');

    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    switch ($action) {
        case 'status':
            $result = swooleApiGet($swooleApiUrl . '/status');
            if ($result === null) {
                Response::success('', ['running' => false]);
            } else {
                Response::success('', $result['data'] ?? ['running' => false]);
            }
            break;

        case 'queue_recent':
            $result = swooleApiGet($swooleApiUrl . '/queue/recent');
            Response::success('', ['list' => $result['data'] ?? []]);
            break;

        case 'queue_retry':
            $id = (int) Input::post('id', 0);
            swooleApiPost($swooleApiUrl . '/queue/retry', ['id' => $id]);
            Response::success('已重置', ['csrf_token' => Csrf::refresh()]);
            break;

        // ===== 启动 swoole（未运行时才允许）=====
        case 'start': {
            assertShellAvailable();
            if (PHP_OS_FAMILY !== 'Linux') {
                Response::error('启动操作仅在 Linux 环境可用（swoole 不支持 Windows 原生）');
            }
            // 已在跑就别重复启动
            if (swooleApiGet($swooleApiUrl . '/status') !== null) {
                Response::success('Swoole 已在运行', ['csrf_token' => Csrf::refresh()]);
            }
            $php = swoolePhpBinary();
            $script = escapeshellarg(EM_ROOT . '/swoole/server.php');
            $log = escapeshellarg(EM_ROOT . '/swoole/startup.log');
            // nohup + 重定向 + & + 关闭 stdin —— 让进程脱离 fpm 进程组，
            // 否则 fpm 回收 worker 时会把 swoole 一起带走
            $cmd = "nohup {$php} {$script} start > {$log} 2>&1 < /dev/null &";
            @exec($cmd);
            // 给 swoole 1.5 秒预热再返回，让前端 loadStatus 能立刻拿到 running=true
            usleep(1_500_000);
            Response::success('已发出启动指令', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        // ===== 停止 swoole =====
        case 'stop': {
            assertShellAvailable();
            if (PHP_OS_FAMILY !== 'Linux') {
                Response::error('停止操作仅在 Linux 环境可用');
            }
            $php = swoolePhpBinary();
            $script = escapeshellarg(EM_ROOT . '/swoole/server.php');
            $output = []; $rc = 0;
            @exec("{$php} {$script} stop 2>&1", $output, $rc);
            $msg = trim(implode("\n", $output));
            Response::success($msg !== '' ? $msg : '已停止', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        // ===== 重启（实际是 reload：平滑替换 worker，不丢请求）=====
        case 'reload': {
            assertShellAvailable();
            if (PHP_OS_FAMILY !== 'Linux') {
                Response::error('重启操作仅在 Linux 环境可用');
            }
            // reload 必须 swoole 在跑，否则 SIGUSR1 没人接
            if (swooleApiGet($swooleApiUrl . '/status') === null) {
                Response::error('Swoole 未运行，请先启动');
            }
            $php = swoolePhpBinary();
            $script = escapeshellarg(EM_ROOT . '/swoole/server.php');
            $output = []; $rc = 0;
            @exec("{$php} {$script} reload 2>&1", $output, $rc);
            $msg = trim(implode("\n", $output));
            Response::success($msg !== '' ? $msg : '已发送 reload 信号', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        default:
            Response::error('未知操作');
    }
}

// 页面渲染
if ((string) Input::get('_popup', '') !== '') {
    // 弹窗模式（iframe 嵌入）：只渲染监控内容 + 必要的 CSS/JS，不套后台框架（避免侧边栏重复出现）
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Swoole 监控</title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="/admin/static/css/reset-layui.css">
    <link rel="stylesheet" href="/admin/static/css/admin.css">
    <script src="/content/static/lib/jquery.min.3.5.1.js"></script>
    <script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
    <script src="/admin/static/js/admin.js"></script>
    <script>window.adminCsrfToken = <?php echo json_encode($csrfToken); ?>;</script>
    <style>
        /* 覆盖 admin.css 对 html/body 的"全局锁死滚动"策略（后台主框架依赖它做内滚动）
           在弹窗 iframe 模式下我们需要由 body 接管滚动，否则内容超高时会被裁掉 */
        html, body { height: auto; overflow: auto; }
        body { background: #f8fafc; margin: 0; }
        /* 弹窗里不需要 admin-content 的 flex:1 逻辑，解除它以便正常按内容流布局 */
        .swoole-popup-body { padding: 16px 20px 24px; }
    </style>
</head>
<body>
<div class="swoole-popup-body"><?php include __DIR__ . '/view/swoole.php'; ?></div>
</body>
</html>
    <?php
    exit;
}

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/swoole.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/swoole.php';
    require __DIR__ . '/index.php';
}

/**
 * 检查 exec/shell_exec 是否被 php.ini disable_functions 禁用，被禁就直接返回错误。
 */
function assertShellAvailable(): void
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    foreach (['exec', 'shell_exec'] as $fn) {
        if (in_array($fn, $disabled, true) || !function_exists($fn)) {
            Response::error("php.ini 禁用了 {$fn}，无法在网页内控制 swoole 进程，请手动在终端执行命令");
        }
    }
}

/**
 * 取一个能用的 php 可执行文件路径。
 *
 * 注意 PHP_BINARY 在 fpm 模式下是 php-fpm，不是 cli 的 php，所以优先用 `which php` 找 cli。
 * 找不到再退回 PHP_BINARY（同目录下通常有 php cli 二进制），实在不行硬编码 'php' 走 $PATH。
 */
function swoolePhpBinary(): string
{
    $which = trim((string) @shell_exec('command -v php 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        return escapeshellarg($which);
    }
    if (defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
        // fpm 路径 = /usr/bin/php-fpm，同目录的 php cli = /usr/bin/php
        $cliCandidate = dirname(PHP_BINARY) . '/php';
        if (is_executable($cliCandidate)) {
            return escapeshellarg($cliCandidate);
        }
        return escapeshellarg(PHP_BINARY);
    }
    return 'php';
}

/**
 * 请求 Swoole HTTP API（GET）。
 */
function swooleApiGet(string $url): ?array
{
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    return json_decode($body, true);
}

/**
 * 请求 Swoole HTTP API（POST）。
 */
function swooleApiPost(string $url, array $data): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    return json_decode($body, true);
}
