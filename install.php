<?php

declare(strict_types=1);

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
