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
 * 安装锁（存在则视为已安装）。
 */
const EM_INSTALL_LOCK = EM_ROOT . '/content/cache/install.lock';

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

function installer_is_config_present(): bool
{
    return is_file(EM_ROOT . '/config.php');
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
    $config = [
        'db' => [
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => (int) ($db['port'] ?? 3306),
            'dbname' => (string) ($db['dbname'] ?? ''),
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'charset' => (string) ($db['charset'] ?? 'utf8mb4'),
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

    return "<?php\n\nreturn " . $export . ";\n";
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

    $lockDir = dirname(EM_INSTALL_LOCK);
    $lockDirWritable = is_dir($lockDir) && is_writable($lockDir);
    $rootWritable = is_writable(EM_ROOT);
    $configExists = installer_is_config_present();
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
    $dbCharset = is_array($db) ? (string) ($db['charset'] ?? 'utf8mb4') : 'utf8mb4';
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
    <style>
        :root { --bg:#0b1220; --card:#0f1a2f; --muted:#9fb3c8; --text:#e6eef8; --brand:#4C7D71; --danger:#ef4444; --warn:#f59e0b; --ok:#22c55e; }
        *{ box-sizing:border-box; }
        body{ margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft Yahei",sans-serif; background:radial-gradient(1200px 600px at 20% -10%, rgba(76,125,113,.25), transparent 55%), radial-gradient(900px 500px at 110% 10%, rgba(59,130,246,.18), transparent 55%), var(--bg); color:var(--text); }
        .wrap{ max-width:980px; margin:0 auto; padding:28px 16px 60px; }
        .top{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; }
        .title{ font-size:20px; font-weight:700; letter-spacing:.2px; }
        .sub{ color:var(--muted); font-size:13px; }
        .card{ background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)); border:1px solid rgba(255,255,255,.08); border-radius:16px; padding:18px; box-shadow:0 12px 30px rgba(0,0,0,.25); }
        .grid{ display:grid; grid-template-columns: 1fr; gap:14px; }
        @media (min-width: 900px){ .grid{ grid-template-columns: 1fr 1fr; } }
        .section-title{ font-weight:700; margin:0 0 10px; font-size:14px; color:#dbeafe; }
        .kv{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-radius:12px; background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06); }
        .kv b{ font-weight:600; color:#cfe1ff; }
        .tag{ font-size:12px; padding:2px 8px; border-radius:999px; border:1px solid rgba(255,255,255,.12); color:var(--muted); }
        .tag.ok{ color:#bbf7d0; border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); }
        .tag.bad{ color:#fecaca; border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }
        .tag.warn{ color:#fde68a; border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10); }
        .msg{ margin:0 0 12px; padding:12px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.10); background:rgba(255,255,255,.04); color:var(--text); }
        .msg.ok{ border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.10); }
        .msg.bad{ border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.10); }
        .msg.warn{ border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.10); }
        form{ display:block; margin-top:12px; }
        .row{ display:grid; grid-template-columns: 1fr; gap:10px; margin-bottom:10px; }
        @media (min-width: 900px){ .row.two{ grid-template-columns: 1fr 1fr; } }
        label{ font-size:12px; color:var(--muted); display:block; margin:0 0 6px; }
        input{ width:100%; padding:11px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(0,0,0,.18); color:var(--text); outline:none; }
        input:focus{ border-color: rgba(76,125,113,.8); box-shadow:0 0 0 3px rgba(76,125,113,.18); }
        .actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:6px; }
        button{ padding:11px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background:linear-gradient(135deg, rgba(76,125,113,.95), rgba(76,125,113,.65)); color:white; font-weight:600; cursor:pointer; }
        button.secondary{ background:rgba(255,255,255,.06); color:var(--text); }
        button:disabled{ opacity:.55; cursor:not-allowed; }
        .hint{ font-size:12px; color:var(--muted); margin-top:8px; line-height:1.6; }
        .codebox{ margin-top:10px; padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,.10); background:rgba(0,0,0,.22); overflow:auto; font-family:Consolas,Monaco,monospace; font-size:12px; white-space:pre; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <div class="title">EMSHOP 在线安装</div>
            <div class="sub">安装完成后将生成 `config.php` 并写入安装锁：`content/cache/install.lock`</div>
        </div>
        <div class="sub">版本：<?php echo htmlspecialchars(defined('EM_VERSION') ? (string) EM_VERSION : 'unknown'); ?></div>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="msg <?php echo htmlspecialchars($m['type']); ?>"><?php echo htmlspecialchars($m['text']); ?></div>
    <?php endforeach; ?>

    <div class="grid">
        <div class="card">
            <div class="section-title">环境检查</div>
            <div class="kv"><b>PHP 版本</b><span class="tag <?php echo $phpOk ? 'ok' : 'bad'; ?>"><?php echo htmlspecialchars(PHP_VERSION); ?></span></div>
            <div class="kv"><b>mysqli 扩展</b><span class="tag <?php echo $hasMysqli ? 'ok' : 'warn'; ?>"><?php echo $hasMysqli ? '已启用' : '未启用'; ?></span></div>
            <div class="kv"><b>pdo_mysql 扩展</b><span class="tag <?php echo $hasPdo ? 'ok' : 'warn'; ?>"><?php echo $hasPdo ? '已启用' : '未启用'; ?></span></div>
            <div class="kv"><b>根目录可写（用于写 config.php）</b><span class="tag <?php echo $rootWritable ? 'ok' : 'bad'; ?>"><?php echo $rootWritable ? '可写' : '不可写'; ?></span></div>
            <div class="kv"><b>`content/cache/` 可写（用于写安装锁）</b><span class="tag <?php echo $lockDirWritable ? 'ok' : 'bad'; ?>"><?php echo $lockDirWritable ? '可写' : '不可写'; ?></span></div>
            <div class="kv"><b>检测到 config.php</b><span class="tag <?php echo $configExists ? 'warn' : 'ok'; ?>"><?php echo $configExists ? '已存在' : '不存在'; ?></span></div>
            <div class="kv"><b>已安装锁</b><span class="tag <?php echo $installed ? 'warn' : 'ok'; ?>"><?php echo $installed ? '已安装' : '未安装'; ?></span></div>

            <div class="hint">
                如果根目录不可写，向导仍会生成配置内容供你手动创建 `config.php`。\n
                如果已经存在 `install.lock`，安装入口将被禁用（需要手动删除锁才可重新安装）。
            </div>
        </div>

        <div class="card">
            <div class="section-title">开始安装</div>

            <?php if ($installed): ?>
                <div class="hint">当前检测为“已安装”。如需重新安装，请先删除 `content/cache/install.lock`（谨慎：可能覆盖现有数据库）。</div>
                <div class="actions">
                    <a href="../" style="text-decoration:none"><button class="secondary" type="button">返回首页</button></a>
                </div>
            <?php else: ?>
                <form method="post" action="?action=install">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">

                    <div class="row two">
                        <div>
                            <label>数据库 Host</label>
                            <input name="db[host]" value="<?php echo htmlspecialchars($dbHost); ?>" placeholder="127.0.0.1">
                        </div>
                        <div>
                            <label>端口</label>
                            <input name="db[port]" value="<?php echo htmlspecialchars($dbPort); ?>" placeholder="3306">
                        </div>
                    </div>
                    <div class="row">
                        <div>
                            <label>数据库名（将自动创建，如不存在）</label>
                            <input name="db[dbname]" value="<?php echo htmlspecialchars($dbName); ?>" placeholder="em_cc">
                        </div>
                    </div>
                    <div class="row two">
                        <div>
                            <label>数据库用户名</label>
                            <input name="db[username]" value="<?php echo htmlspecialchars($dbUser); ?>" placeholder="root">
                        </div>
                        <div>
                            <label>数据库密码</label>
                            <input name="db[password]" value="<?php echo htmlspecialchars($dbPass); ?>" placeholder="">
                        </div>
                    </div>
                    <div class="row two">
                        <div>
                            <label>字符集</label>
                            <input name="db[charset]" value="<?php echo htmlspecialchars($dbCharset); ?>" placeholder="utf8mb4">
                        </div>
                        <div>
                            <label>表前缀</label>
                            <input name="db[prefix]" value="<?php echo htmlspecialchars($dbPrefix); ?>" placeholder="em_">
                        </div>
                    </div>

                    <div class="row two">
                        <div>
                            <label>管理员用户名</label>
                            <input name="admin[username]" value="<?php echo htmlspecialchars($adminUsername); ?>" placeholder="admin">
                        </div>
                        <div>
                            <label>管理员邮箱</label>
                            <input name="admin[email]" value="<?php echo htmlspecialchars($adminEmail); ?>" placeholder="admin@example.com">
                        </div>
                    </div>
                    <div class="row two">
                        <div>
                            <label>管理员密码</label>
                            <input name="admin[password]" value="" placeholder="建议 8 位以上">
                        </div>
                        <div>
                            <label>确认密码</label>
                            <input name="admin[password2]" value="" placeholder="">
                        </div>
                    </div>

                    <?php if ($configExists): ?>
                        <div class="hint" style="margin-top:6px;color:#fde68a">检测到根目录已有 `config.php`。安装会尝试覆盖它（并不删除旧数据库）。请确认你确实要重新安装。</div>
                        <div class="row">
                            <div>
                                <label><input type="checkbox" name="overwrite_config" value="1" style="width:auto;vertical-align:middle;margin-right:6px">允许覆盖 `config.php`</label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="actions">
                        <button class="secondary" type="submit" name="mode" value="test">仅测试数据库连接</button>
                        <button type="submit" name="mode" value="install">执行安装</button>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <?php if (isset($defaults['generated_config_php']) && is_string($defaults['generated_config_php']) && $defaults['generated_config_php'] !== ''): ?>
        <div class="card" style="margin-top:14px">
            <div class="section-title">生成的 config.php（用于手动创建/排错）</div>
            <div class="codebox"><?php echo htmlspecialchars($defaults['generated_config_php']); ?></div>
        </div>
    <?php endif; ?>
</div>
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
if ($action !== 'install') {
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
    'charset' => trim((string) ($db['charset'] ?? 'utf8mb4')),
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
    $messages[] = ['type' => 'bad', 'text' => '`content/cache/` 不可写：无法写入安装锁'];
}

$configPath = EM_ROOT . '/config.php';
$configExists = is_file($configPath);
if ($configExists && (string) ($_POST['overwrite_config'] ?? '') !== '1') {
    $messages[] = ['type' => 'bad', 'text' => '检测到已有 config.php：如需覆盖请勾选“允许覆盖 config.php”'];
}

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
        'charset' => $dbClean['charset'] ?: 'utf8mb4',
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

