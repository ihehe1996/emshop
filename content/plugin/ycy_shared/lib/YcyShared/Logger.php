<?php

declare(strict_types=1);

namespace YcyShared;

use Throwable;

final class Logger
{
    /**
     * @param array<string, mixed> $detail
     */
    public static function info(string $action, string $message, array $detail = []): void
    {
        self::write('info', $action, $message, $detail);
    }

    /**
     * @param array<string, mixed> $detail
     */
    public static function warning(string $action, string $message, array $detail = []): void
    {
        self::write('warning', $action, $message, $detail);
    }

    /**
     * @param array<string, mixed> $detail
     */
    public static function error(string $action, string $message, array $detail = []): void
    {
        self::write('error', $action, $message, $detail);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function write(string $level, string $action, string $message, array $detail): void
    {
        try {
            require_once EM_ROOT . '/include/model/SystemLogModel.php';
            if (!class_exists('SystemLogModel')) {
                return;
            }
            $model = new \SystemLogModel();
            $action = 'ycy_shared/' . $action;
            if ($level === 'error') {
                $model->error('system', $action, $message, $detail);
            } elseif ($level === 'warning' || $level === 'warn') {
                $model->warning('system', $action, $message, $detail);
            } else {
                $model->info('system', $action, $message, $detail);
            }
        } catch (Throwable $e) {
            // 日志写入失败不影响主流程
        }
    }
}

