<?php

declare(strict_types=1);

/**
 * 读取同级目录下的 .env（可选）：
 *   - 本地开发在项目根放一个 .env 即可覆盖下方默认 DB 配置
 *   - 生产环境不需要放 .env，会直接使用硬编码默认值
 *   - 格式：KEY=VALUE，每行一条；支持 # 开头的注释、两端成对引号
 */
$env = [];
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        // 去掉成对的引号（单/双都支持）
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[0] === substr($v, -1)) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
}
$E = static fn(string $k, $def = null) => $env[$k] ?? $def;

/**
 * 授权服务器线路列表。
 * 优先级：.env 里 LICENSE_URL_{N} / LICENSE_URL_{N}_NAME（N 从 0 开始）> 下方生产默认。
 * 只要 .env 里有任一条就会整体替换默认（本地开发典型用法）。
 * 数组元素结构：['url' => 'https://xxx/', 'name' => '线路名']，url 必须以 / 结尾（LicenseClient 约定）。
 */
$licenseUrls = [];
for ($i = 0; $i < 10; $i++) {
    $url = trim((string) ($env['LICENSE_URL_' . $i] ?? ''));
    if ($url === '') continue;
    $licenseUrls[] = [
        'url'  => rtrim($url, '/') . '/',
        'name' => trim((string) ($env['LICENSE_URL_' . $i . '_NAME'] ?? ('线路 ' . ($i + 1)))),
    ];
}
if ($licenseUrls === []) {
    $licenseUrls = [
        ['url' => 'https://emshop.ihehe.me/',   'name' => '官方线路'],
        ['url' => 'http://154.44.8.63:10000/',  'name' => '备用线路'],
    ];
}

return [
    'db' => [
        'host'     => $E('DB_HOST', '127.0.0.1'),
        'port'     => (int) $E('DB_PORT', 3306),
        'dbname'   => $E('DB_NAME', 'emshop'),
        'username' => $E('DB_USER', 'emshop'),
        'password' => $E('DB_PASS', '123456'),
        'charset'  => 'utf8mb4',
        'prefix'   => $E('DB_PREFIX', 'em_'),
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
    'license_urls' => $licenseUrls,
];
