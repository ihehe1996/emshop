<?php

declare(strict_types=1);

// 兼容旧入口：优先引导到 /install/ 在线安装
if (php_sapi_name() !== 'cli') {
    $lock = __DIR__ . '/content/cache/install.lock';
    if (is_file($lock)) {
        http_response_code(403);
        echo "already installed\n";
        exit;
    }
    header('Location: /install/');
    exit;
}

require __DIR__ . '/init.php';

try {
    /** @var InstallService $installer */
    $installer = new InstallService();
    $installer->setup();
    echo "install success\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
