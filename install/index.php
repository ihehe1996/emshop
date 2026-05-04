<?php

declare(strict_types=1);

// 在线安装向导入口：不依赖 init.php / config.php

if (php_sapi_name() === 'cli') {
    http_response_code(400);
    echo "installer is web-only\n";
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('EM_ROOT', dirname(__DIR__));

require_once EM_ROOT . '/include/lib/Autoloader.php';
Autoloader::register([
    EM_ROOT . '/include/lib',
    EM_ROOT . '/include/model',
    EM_ROOT . '/include/service',
    EM_ROOT . '/include/controller',
]);

/**
 * 读取系统版本（不加载 init.php，避免依赖 config.php/数据库）。
 */
function installer_system_version(): string
{
    $initFile = EM_ROOT . '/init.php';
    if (!is_file($initFile) || !is_readable($initFile)) {
        return 'unknown';
    }
    $content = file_get_contents($initFile);
    if ($content === false) {
        return 'unknown';
    }
    if (preg_match("/define\\(\\s*'EM_VERSION'\\s*,\\s*'([^']+)'\\s*\\)\\s*;/", $content, $m)) {
        return (string) $m[1];
    }
    return 'unknown';
}

/**
 * 安装锁（存在则视为已安装）。
 */
const EM_INSTALL_LOCK = EM_ROOT . '/install/install.lock';

/**
 * 生成/读取安装向导 CSRF token。
 */
function installer_csrf_token(): string
{
    if (!isset($_SESSION['__em_installer_csrf']) || !is_string($_SESSION['__em_installer_csrf']) || $_SESSION['__em_installer_csrf'] === '') {
        $_SESSION['__em_installer_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['__em_installer_csrf'];
}

function installer_require_csrf(): void
{
    $posted = $_POST['csrf'] ?? '';
    $expected = $_SESSION['__em_installer_csrf'] ?? '';
    if (!is_string($posted) || !is_string($expected) || $posted === '' || $expected === '' || !hash_equals($expected, $posted)) {
        Response::error('请求校验失败，请刷新页面重试');
    }
}

function installer_is_installed(): bool
{
    return is_file(EM_INSTALL_LOCK);
}

/**
 * 不依赖 Database 类的连接测试（避免 Database 连接失败时触发 Emmsg::error 的致命参数错误）。
 *
 * @param array<string, mixed> $db
 * @return array{ok:bool, driver:string, message:string}
 */
function installer_test_db_connection(array $db): array
{
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $user = (string) ($db['username'] ?? '');
    $pass = (string) ($db['password'] ?? '');

    // 先优先 mysqli
    if (extension_loaded('mysqli')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @mysqli_connect($host, $user, $pass, null, $port);
        if ($mysqli === false) {
            return [
                'ok' => false,
                'driver' => 'mysqli',
                'message' => 'mysqli 连接失败：' . (string) mysqli_connect_error(),
            ];
        }
        @mysqli_close($mysqli);
        return ['ok' => true, 'driver' => 'mysqli', 'message' => 'mysqli 连接成功'];
    }

    if (extension_loaded('pdo_mysql')) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
            new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            return ['ok' => true, 'driver' => 'pdo', 'message' => 'PDO 连接成功'];
        } catch (Throwable $e) {
            return ['ok' => false, 'driver' => 'pdo', 'message' => 'PDO 连接失败：' . $e->getMessage()];
        }
    }

    return ['ok' => false, 'driver' => 'none', 'message' => '环境缺少 mysqli 或 pdo_mysql 扩展'];
}

/**
 * 生成 config.php 内容。
 *
 * @param array<string, mixed> $db
 */
function installer_generate_config_php(array $db): string
{
    $lockGuard = <<<'PHP'
// 未安装（缺少安装锁）时：自动跳转到在线安装向导
if (!is_file(__DIR__ . '/install/install.lock')) {
    header('Location: /install/');
    exit;
}
PHP;

    $config = [
        'db' => [
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => (int) ($db['port'] ?? 3306),
            'dbname' => (string) ($db['dbname'] ?? ''),
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            // 安装固定使用 utf8mb4，避免用户误选导致表/索引不兼容
            'charset' => 'utf8mb4',
            'prefix' => (string) ($db['prefix'] ?? 'em_'),
        ],
        'auth' => [
            'session_key' => 'em_admin_auth',
            'remember_cookie' => 'em_admin_remember',
            'remember_days_default' => 7,
            'remember_days_checked' => 365,
            'csrf_key' => 'em_admin_csrf',
            'throttle_key' => 'em_admin_login_throttle',
            'max_attempts' => 5,
            'lock_minutes' => 5,
        ],
        'avatar' => '/content/static/img/default-admin-avatar.jpg',
        'placeholder_img' => '/content/static/img/img-1.png',
        'license_urls' => [
            ['url' => 'https://emshop.ihehe.me/', 'name' => '官方线路'],
            ['url' => 'http://154.44.8.63:10000/', 'name' => '备用线路'],
        ],
    ];

    $export = var_export($config, true);
    $export = preg_replace('/^(\s*)array\s*\(/m', '$1[', (string) $export);
    $export = preg_replace('/\)(,?)$/m', ']$1', (string) $export);

    return "<?php\n\n" . $lockGuard . "\n\nreturn " . $export . ";\n";
}

/**
 * 输出一个简单的安装页 UI（不复用前台模板，避免依赖 init.php）。
 *
 * @param array<int, array{type:string, text:string}> $messages
 * @param array<string, mixed> $defaults
 */
function installer_render(array $messages, array $defaults): void
{
    $csrf = installer_csrf_token();
    $phpOk = PHP_VERSION_ID >= 70400;
    $hasMysqli = extension_loaded('mysqli');
    $hasPdo = extension_loaded('pdo_mysql');
    $systemVersion = installer_system_version();

    $lockDir = dirname(EM_INSTALL_LOCK);
    $lockDirWritable = is_dir($lockDir) && is_writable($lockDir);
    $cacheDir = EM_ROOT . '/content/cache';
    $cacheDirWritable = is_dir($cacheDir) && is_writable($cacheDir);
    $rootWritable = is_writable(EM_ROOT);
    $installed = installer_is_installed();

    $d = function (string $key, $fallback = '') use ($defaults) {
        return isset($defaults[$key]) ? (string) $defaults[$key] : (string) $fallback;
    };
    $db = $defaults['db'] ?? [];
    $dbHost = is_array($db) ? (string) ($db['host'] ?? '127.0.0.1') : '127.0.0.1';
    $dbPort = is_array($db) ? (string) ($db['port'] ?? '3306') : '3306';
    $dbName = is_array($db) ? (string) ($db['dbname'] ?? '') : '';
    $dbUser = is_array($db) ? (string) ($db['username'] ?? '') : '';
    $dbPass = is_array($db) ? (string) ($db['password'] ?? '') : '';
    $dbPrefix = is_array($db) ? (string) ($db['prefix'] ?? 'em_') : 'em_';

    $adminUsername = (string) (($defaults['admin']['username'] ?? '') ?: 'admin');
    $adminEmail = (string) (($defaults['admin']['email'] ?? '') ?: '');

    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>EMSHOP 在线安装</title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <style>
        :root{
            --bg:#f6f8fb; --card:#ffffff; --muted:#64748b; --text:#0f172a;
            --line:rgba(2,6,23,.08); --soft:rgba(2,6,23,.06);
            --brand:#4C7D71; --brand2:#5a9486;
            --danger:#ef4444; --warn:#f59e0b; --ok:#22c55e;
        }
        *{ box-sizing:border-box; }
        body{
            margin:0;
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft Yahei",sans-serif;
            background:
                radial-gradient(1200px 600px at 12% -10%, rgba(76,125,113,.16), transparent 60%),
                radial-gradient(900px 500px at 110% 6%, rgba(59,130,246,.10), transparent 60%),
                var(--bg);
            color:var(--text);
        }
        .wrap{ max-width:1100px; margin:0 auto; padding:26px 16px 64px; }
        .header{
            display:flex; align-items:flex-start; justify-content:space-between; gap:14px;
            padding:18px 18px; border:1px solid var(--line); border-radius:18px;
            background:linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.75));
            box-shadow:0 10px 26px rgba(2,6,23,.06);
            margin-bottom:14px;
        }
        .brandRow{ display:flex; align-items:center; gap:12px; }
        .logo{
            width:40px; height:40px; border-radius:12px;
            background:linear-gradient(135deg, rgba(76,125,113,.95), rgba(90,148,134,.78));
            box-shadow:0 10px 18px rgba(76,125,113,.22);
        }
        .hTitle{ font-size:18px; font-weight:800; letter-spacing:.2px; margin:0; }
        .hSub{ margin:6px 0 0; color:var(--muted); font-size:13px; line-height:1.55; }
        .meta{ text-align:right; color:var(--muted); font-size:12.5px; }
        .meta b{ color:#0f172a; font-weight:700; }

        .layout{ display:grid; grid-template-columns: 1fr; gap:14px; }
        @media (min-width: 980px){ .layout{ grid-template-columns: 360px 1fr; } }

        .card{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:18px;
            padding:16px;
            box-shadow:0 10px 26px rgba(2,6,23,.06);
        }
        .cardTitle{ font-weight:800; margin:0 0 10px; font-size:14px; color:#0b2540; }
        .divider{ height:1px; background:var(--soft); margin:12px 0; }

        .steps{ list-style:none; margin:0; padding:0; display:grid; gap:10px; }
        .step{
            display:flex; gap:10px; align-items:flex-start;
            padding:10px 12px; border-radius:14px;
            background:#f8fafc; border:1px solid var(--soft);
        }
        .dot{
            width:22px; height:22px; border-radius:999px;
            background:rgba(76,125,113,.12); color:var(--brand);
            display:flex; align-items:center; justify-content:center;
            font-size:12px; font-weight:800; flex:0 0 auto;
            border:1px solid rgba(76,125,113,.18);
        }
        .step b{ display:block; font-size:13px; margin-bottom:2px; }
        .step span{ display:block; color:var(--muted); font-size:12px; line-height:1.55; }

        .checks{ display:grid; gap:10px; }
        .checkRow{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-radius:14px; background:#f8fafc; border:1px solid var(--soft); }
        .checkRow b{ font-size:13px; font-weight:700; }
        .tag{ font-size:12px; padding:2px 8px; border-radius:999px; border:1px solid rgba(2,6,23,.12); color:var(--muted); background:#fff; }
        .tag.ok{ color:#166534; border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); }
        .tag.bad{ color:#991b1b; border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }
        .tag.warn{ color:#92400e; border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); }

        .alerts{ display:grid; gap:10px; margin-bottom:12px; }
        .msg{
            display:flex; gap:10px; align-items:flex-start;
            padding:12px 12px; border-radius:14px;
            border:1px solid var(--line); background:#fff;
        }
        .msg .ico{
            width:22px; height:22px; border-radius:8px;
            display:flex; align-items:center; justify-content:center;
            font-size:13px; font-weight:900; flex:0 0 auto;
            background:#f1f5f9; color:#334155;
            border:1px solid rgba(2,6,23,.08);
        }
        .msg.ok{ border-color:rgba(34,197,94,.30); background:rgba(34,197,94,.08); }
        .msg.ok .ico{ background:rgba(34,197,94,.14); color:#166534; border-color:rgba(34,197,94,.18); }
        .msg.bad{ border-color:rgba(239,68,68,.30); background:rgba(239,68,68,.08); }
        .msg.bad .ico{ background:rgba(239,68,68,.14); color:#991b1b; border-color:rgba(239,68,68,.18); }
        .msg.warn{ border-color:rgba(245,158,11,.30); background:rgba(245,158,11,.08); }
        .msg.warn .ico{ background:rgba(245,158,11,.14); color:#92400e; border-color:rgba(245,158,11,.18); }
        .msg p{ margin:0; font-size:13px; line-height:1.65; }

        form{ display:block; margin-top:10px; }
        .section{ padding:12px 12px; border:1px solid var(--soft); border-radius:16px; background:#ffffff; }
        .sectionH{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin:0 0 10px; }
        .sectionH b{ font-size:13px; }
        .sectionH span{ font-size:12px; color:var(--muted); }
        .row{ display:grid; grid-template-columns: 1fr; gap:10px; margin-bottom:10px; }
        @media (min-width: 980px){ .row.two{ grid-template-columns: 1fr 1fr; } }
        label{ font-size:12px; color:var(--muted); display:block; margin:0 0 6px; }
        input{
            width:100%; padding:11px 12px; border-radius:14px;
            border:1px solid rgba(2,6,23,.12);
            background:#ffffff; color:var(--text); outline:none;
        }
        input::placeholder{ color:#94a3b8; }
        input:focus{ border-color: rgba(76,125,113,.85); box-shadow:0 0 0 3px rgba(76,125,113,.14); }
        .actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
        button{
            padding:11px 14px; border-radius:14px;
            border:1px solid rgba(76,125,113,.22);
            background:linear-gradient(135deg, rgba(76,125,113,.96), rgba(90,148,134,.86));
            color:white; font-weight:800; cursor:pointer;
            box-shadow:0 10px 20px rgba(76,125,113,.16);
        }
        button.secondary{
            background:#ffffff; color:#0f172a;
            border-color:rgba(2,6,23,.14); box-shadow:none;
            font-weight:700;
        }
        button:disabled{ opacity:.55; cursor:not-allowed; }

        .hint{ font-size:12px; color:var(--muted); margin-top:10px; line-height:1.7; }
        details{ margin-top:12px; }
        summary{
            cursor:pointer; list-style:none;
            padding:10px 12px; border-radius:14px;
            border:1px solid var(--soft); background:#f8fafc;
            font-size:13px; font-weight:800;
        }
        summary::-webkit-details-marker{ display:none; }
        .codebox{
            margin-top:10px; padding:12px; border-radius:14px;
            border:1px solid rgba(2,6,23,.10);
            background:#0b1220; color:#e6eef8;
            overflow:auto; font-family:Consolas,Monaco,monospace; font-size:12px; white-space:pre;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div class="brandRow">
            <div class="logo" aria-hidden="true"></div>
            <div>
                <h1 class="hTitle">EMSHOP 在线安装</h1>
                <p class="hSub">完成后将写入安装锁 `install/install.lock`，并生成/更新 `config.php`（含安装跳转保护）。</p>
            </div>
        </div>
        <div class="meta">
            <div><b>系统版本</b>：<?php echo htmlspecialchars($systemVersion); ?></div>
            <div style="margin-top:6px">字符集：<b>utf8mb4</b></div>
        </div>
    </div>

    <?php if (!empty($messages)): ?>
        <div class="alerts">
            <?php foreach ($messages as $m): ?>
                <div class="msg <?php echo htmlspecialchars($m['type']); ?>">
                    <div class="ico">
                        <?php echo $m['type'] === 'ok' ? '✓' : ($m['type'] === 'warn' ? '!' : '×'); ?>
                    </div>
                    <p><?php echo htmlspecialchars($m['text']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="layout">
        <div>
            <div class="card">
                <div class="cardTitle">安装步骤</div>
                <ul class="steps">
                    <li class="step"><div class="dot">1</div><div><b>环境检查</b><span>检查 PHP 版本、扩展与目录写权限。</span></div></li>
                    <li class="step"><div class="dot">2</div><div><b>配置数据库</b><span>填写数据库连接信息（安装将自动建库建表）。</span></div></li>
                    <li class="step"><div class="dot">3</div><div><b>创建管理员</b><span>设置后台管理员账号用于首次登录。</span></div></li>
                    <li class="step"><div class="dot">4</div><div><b>执行安装</b><span>初始化数据结构并写入安装锁。</span></div></li>
                </ul>
                <div class="divider"></div>
                <div class="cardTitle" style="margin-top:0">环境状态</div>
                <div class="checks">
                    <div class="checkRow"><b>PHP 版本</b><span class="tag <?php echo $phpOk ? 'ok' : 'bad'; ?>"><?php echo htmlspecialchars(PHP_VERSION); ?></span></div>
                    <div class="checkRow"><b>mysqli 扩展</b><span class="tag <?php echo $hasMysqli ? 'ok' : 'warn'; ?>"><?php echo $hasMysqli ? '已启用' : '未启用'; ?></span></div>
                    <div class="checkRow"><b>pdo_mysql 扩展</b><span class="tag <?php echo $hasPdo ? 'ok' : 'warn'; ?>"><?php echo $hasPdo ? '已启用' : '未启用'; ?></span></div>
                    <div class="checkRow"><b>根目录可写（写 config.php）</b><span class="tag <?php echo $rootWritable ? 'ok' : 'bad'; ?>"><?php echo $rootWritable ? '可写' : '不可写'; ?></span></div>
                    <div class="checkRow"><b>`content/cache/` 可写</b><span class="tag <?php echo $cacheDirWritable ? 'ok' : 'bad'; ?>"><?php echo $cacheDirWritable ? '可写' : '不可写'; ?></span></div>
                    <div class="checkRow"><b>`install/` 可写（写安装锁）</b><span class="tag <?php echo $lockDirWritable ? 'ok' : 'bad'; ?>"><?php echo $lockDirWritable ? '可写' : '不可写'; ?></span></div>
                    <div class="checkRow"><b>安装锁</b><span class="tag <?php echo $installed ? 'warn' : 'ok'; ?>"><?php echo $installed ? '已安装' : '未安装'; ?></span></div>
                </div>
                <div class="hint">
                    如果根目录不可写，页面会给出生成的 `config.php` 内容供手动创建。\n
                    如果已存在安装锁，安装入口将被禁用（需手动删除 `install/install.lock` 才能重新安装）。
                </div>
            </div>
        </div>

        <div class="card">
            <div class="cardTitle">安装配置</div>

            <?php if ($installed): ?>
                <div class="section">
                    <div class="sectionH"><b>已安装</b><span>安装入口已锁定</span></div>
                    <div class="hint">当前检测为“已安装”。如需重新安装，请先删除 `install/install.lock`（谨慎：可能覆盖现有数据库）。</div>
                    <div class="actions">
                        <a href="../" style="text-decoration:none"><button class="secondary" type="button">返回首页</button></a>
                    </div>
                </div>
            <?php else: ?>
                <form id="installForm" method="post" action="?action=install">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">

                    <div class="section">
                        <div class="sectionH"><b>数据库</b><span>将自动创建数据库与表结构</span></div>
                        <div class="row two">
                            <div>
                                <label>Host</label>
                                <input name="db[host]" value="<?php echo htmlspecialchars($dbHost); ?>" placeholder="127.0.0.1">
                            </div>
                            <div>
                                <label>端口</label>
                                <input name="db[port]" value="<?php echo htmlspecialchars($dbPort); ?>" placeholder="3306">
                            </div>
                        </div>
                        <div class="row">
                            <div>
                                <label>数据库名</label>
                                <input name="db[dbname]" value="<?php echo htmlspecialchars($dbName); ?>" placeholder="em_cc">
                            </div>
                        </div>
                        <div class="row two">
                            <div>
                                <label>用户名</label>
                                <input name="db[username]" value="<?php echo htmlspecialchars($dbUser); ?>" placeholder="root">
                            </div>
                            <div>
                                <label>密码</label>
                                <input name="db[password]" value="<?php echo htmlspecialchars($dbPass); ?>" placeholder="">
                            </div>
                        </div>
                        <div class="row">
                            <div>
                                <label>表前缀</label>
                                <input name="db[prefix]" value="<?php echo htmlspecialchars($dbPrefix); ?>" placeholder="em_">
                            </div>
                        </div>
                    </div>

                    <div class="divider"></div>

                    <div class="section">
                        <div class="sectionH"><b>管理员</b><span>用于首次登录后台</span></div>
                        <div class="row two">
                            <div>
                                <label>用户名</label>
                                <input name="admin[username]" value="<?php echo htmlspecialchars($adminUsername); ?>" placeholder="admin">
                            </div>
                            <div>
                                <label>邮箱</label>
                                <input name="admin[email]" value="<?php echo htmlspecialchars($adminEmail); ?>" placeholder="admin@example.com">
                            </div>
                        </div>
                        <div class="row two">
                            <div>
                                <label>密码</label>
                                <input name="admin[password]" value="" placeholder="至少 6 位，建议更长">
                            </div>
                            <div>
                                <label>确认密码</label>
                                <input name="admin[password2]" value="" placeholder="">
                            </div>
                        </div>
                    </div>

                    <div class="actions">
                        <button class="secondary" type="button" id="btnTestDb">测试数据库连接</button>
                        <button type="submit" name="mode" value="install">执行安装</button>
                    </div>

                    <div class="hint">提示：安装成功后会写入安装锁并禁止再次安装。</div>
                </form>
            <?php endif; ?>

            <?php if (isset($defaults['generated_config_php']) && is_string($defaults['generated_config_php']) && $defaults['generated_config_php'] !== ''): ?>
                <details>
                    <summary>查看生成的 config.php（仅用于手动创建/排错）</summary>
                    <div class="codebox"><?php echo htmlspecialchars($defaults['generated_config_php']); ?></div>
                </details>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
<script>
// 安装页仅用 layer.msg 做提示（不强依赖其它模块）
(function () {
  function getForm() {
    return document.getElementById('installForm');
  }

  function postForm(url, form) {
    const fd = new FormData(form);
    return fetch(url, {
      method: 'POST',
      body: new URLSearchParams(fd),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(r => r.json());
  }

  function msg(text, ok) {
    if (window.layui && typeof layui.msg === 'function') {
      layui.msg(text);
    } else {
      alert(text);
    }
  }

  const btn = document.getElementById('btnTestDb');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const form = getForm();
    if (!form) return;

    btn.disabled = true;
    postForm('?action=test_db', form)
      .then(res => {
        const ok = res && res.code === 200;
        msg((res && res.msg) ? res.msg : (ok ? '连接成功' : '连接失败'), ok);
      })
      .catch(e => {
        msg('请求失败：' + (e && e.message ? e.message : '未知错误'), false);
      })
      .finally(() => {
        btn.disabled = false;
      });
  });
})();
</script>
</body>
</html>
    <?php
    exit;
}

// 已安装：直接给出提示（不允许走 action）
if (installer_is_installed()) {
    installer_render([['type' => 'warn', 'text' => '检测到已安装锁，安装入口已禁用。']], []);
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ($action !== 'install' && $action !== 'test_db') {
    installer_render([], []);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('非法请求');
}

installer_require_csrf();

$mode = (string) ($_POST['mode'] ?? 'test');
$db = $_POST['db'] ?? [];
$admin = $_POST['admin'] ?? [];
if (!is_array($db)) $db = [];
if (!is_array($admin)) $admin = [];

$dbClean = [
    'host' => trim((string) ($db['host'] ?? '127.0.0.1')),
    'port' => (int) ($db['port'] ?? 3306),
    'dbname' => trim((string) ($db['dbname'] ?? '')),
    'username' => trim((string) ($db['username'] ?? '')),
    'password' => (string) ($db['password'] ?? ''),
    // 安装固定使用 utf8mb4（不展示给用户，也不允许覆盖）
    'charset' => 'utf8mb4',
    'prefix' => trim((string) ($db['prefix'] ?? 'em_')),
];

$adminClean = [
    'username' => trim((string) ($admin['username'] ?? 'admin')),
    'email' => trim((string) ($admin['email'] ?? '')),
    'password' => (string) ($admin['password'] ?? ''),
    'password2' => (string) ($admin['password2'] ?? ''),
];

$messages = [];

// 基础校验
if (PHP_VERSION_ID < 70400) {
    $messages[] = ['type' => 'bad', 'text' => 'PHP 版本过低：需要 7.4+'];
}
if (!extension_loaded('mysqli') && !extension_loaded('pdo_mysql')) {
    $messages[] = ['type' => 'bad', 'text' => '缺少 mysqli 或 pdo_mysql 扩展，无法安装'];
}
if ($dbClean['dbname'] === '') {
    $messages[] = ['type' => 'bad', 'text' => '请填写数据库名'];
}
if ($dbClean['username'] === '') {
    $messages[] = ['type' => 'bad', 'text' => '请填写数据库用户名'];
}
if ($dbClean['prefix'] === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbClean['prefix'])) {
    $messages[] = ['type' => 'bad', 'text' => '表前缀仅允许字母/数字/下划线'];
}

$generatedConfig = installer_generate_config_php($dbClean);

// AJAX 测试数据库连接：返回 JSON，不刷新页面
if ($action === 'test_db') {
    $test = installer_test_db_connection($dbClean);
    if ($test['ok']) {
        Response::success($test['message'], ['driver' => $test['driver']]);
    }
    Response::error($test['message'], ['driver' => $test['driver']]);
}

if ($mode === 'test') {
    $test = installer_test_db_connection($dbClean);
    $messages[] = ['type' => $test['ok'] ? 'ok' : 'bad', 'text' => $test['message']];
    installer_render($messages, ['db' => $dbClean, 'admin' => $adminClean, 'generated_config_php' => $generatedConfig]);
}

// install 模式：进一步校验管理员信息
if ($adminClean['email'] === '' || !filter_var($adminClean['email'], FILTER_VALIDATE_EMAIL)) {
    $messages[] = ['type' => 'bad', 'text' => '请填写有效的管理员邮箱'];
}
if ($adminClean['password'] === '' || strlen($adminClean['password']) < 6) {
    $messages[] = ['type' => 'bad', 'text' => '管理员密码至少 6 位'];
}
if ($adminClean['password'] !== $adminClean['password2']) {
    $messages[] = ['type' => 'bad', 'text' => '两次输入的管理员密码不一致'];
}
if ($adminClean['username'] === '' || !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $adminClean['username'])) {
    $messages[] = ['type' => 'bad', 'text' => '管理员用户名需为 3-50 位字母/数字/下划线'];
}

// 目录权限
$lockDir = dirname(EM_INSTALL_LOCK);
if (!is_dir($lockDir) || !is_writable($lockDir)) {
    $messages[] = ['type' => 'bad', 'text' => '`install/` 不可写：无法写入安装锁'];
}

$cacheDir = EM_ROOT . '/content/cache';
if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
    $messages[] = ['type' => 'bad', 'text' => '`content/cache/` 不可写：系统运行时无法写缓存，请先修复目录权限'];
}

$configPath = EM_ROOT . '/config.php';

if ($messages !== []) {
    installer_render($messages, ['db' => $dbClean, 'admin' => $adminClean, 'generated_config_php' => $generatedConfig]);
}

// 连接测试（确保不会触发 Database 失败路径）
$test = installer_test_db_connection($dbClean);
if (!$test['ok']) {
    $messages[] = ['type' => 'bad', 'text' => $test['message']];
    installer_render($messages, ['db' => $dbClean, 'admin' => $adminClean, 'generated_config_php' => $generatedConfig]);
}

// 切换到项目 Database：用内存 EM_CONFIG 启动 InstallService
define('EM_CONFIG', [
    'db' => [
        'host' => $dbClean['host'],
        'port' => $dbClean['port'],
        'dbname' => $dbClean['dbname'],
        'username' => $dbClean['username'],
        'password' => $dbClean['password'],
        'charset' => 'utf8mb4',
        'prefix' => $dbClean['prefix'] ?: 'em_',
    ],
]);

try {
    $installer = new InstallService();
    $installer->setup([
        'admin' => [
            'username' => $adminClean['username'],
            'email' => $adminClean['email'],
            'password' => $adminClean['password'],
        ],
    ]);
} catch (Throwable $e) {
    $messages[] = ['type' => 'bad', 'text' => '安装执行失败：' . $e->getMessage()];
    installer_render($messages, ['db' => $dbClean, 'admin' => $adminClean, 'generated_config_php' => $generatedConfig]);
}

// 写 config.php（可写则落盘；不可写则仍给出内容供手动复制）
if (is_writable(EM_ROOT)) {
    $written = @file_put_contents($configPath, $generatedConfig, LOCK_EX);
    if ($written === false) {
        $messages[] = ['type' => 'warn', 'text' => '数据库已初始化，但写入 config.php 失败：请手动创建 config.php（下方已生成）'];
    }
} else {
    $messages[] = ['type' => 'warn', 'text' => '数据库已初始化，但根目录不可写：请手动创建 config.php（下方已生成）'];
}

// 写安装锁（用于禁用安装入口）
$lockPayload = json_encode([
    'installed_at' => date('c'),
    'db' => [
        'host' => $dbClean['host'],
        'port' => $dbClean['port'],
        'dbname' => $dbClean['dbname'],
        'prefix' => $dbClean['prefix'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(EM_INSTALL_LOCK, (string) $lockPayload, LOCK_EX);

$messages[] = ['type' => 'ok', 'text' => '安装完成：已初始化数据库并写入安装锁。'];
installer_render($messages, ['db' => $dbClean, 'admin' => $adminClean, 'generated_config_php' => $generatedConfig]);

