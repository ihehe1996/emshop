<?php

declare(strict_types=1);

namespace YcyShared;

use Database;

/**
 * 上游站点数据访问。
 *
 * em_ycy_site 是插件自建表（见 ycy_shared_callback.php callback_init）。
 */
final class SiteModel
{
    private static function table(): string
    {
        return Database::prefix() . 'ycy_site';
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM `' . self::table() . '`';
        if ($enabledOnly) $sql .= ' WHERE `enabled` = 1';
        $sql .= ' ORDER BY `id` ASC';
        return Database::query($sql);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) return null;
        $row = Database::fetchOne('SELECT * FROM `' . self::table() . '` WHERE `id` = ? LIMIT 1', [$id]);
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $row = self::sanitize($data);
        $id = Database::insert('ycy_site', $row);
        return (int) $id;
    }

    public static function update(int $id, array $data): bool
    {
        if ($id <= 0) return false;
        $row = self::sanitize($data);
        return Database::update('ycy_site', $row, $id) > 0;
    }

    public static function delete(int $id): bool
    {
        if ($id <= 0) return false;
        return Database::execute('DELETE FROM `' . self::table() . '` WHERE `id` = ?', [$id]) > 0;
    }

    public static function touchSyncedAt(int $id): void
    {
        Database::execute('UPDATE `' . self::table() . '` SET `last_synced_at` = NOW() WHERE `id` = ?', [$id]);
    }

    /**
     * 规范化字段：白名单 + 类型转换，防止外部传入无效列。
     */
    private static function sanitize(array $data): array
    {
        $allowed = [
            'name', 'version', 'host', 'app_id', 'app_key',
            'markup_ratio', 'min_markup', 'enabled', 'remark',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $data)) continue;
            $out[$k] = $data[$k];
        }

        // 校准字段
        if (isset($out['version'])) {
            $out['version'] = in_array($out['version'], ['v3', 'v4'], true) ? $out['version'] : 'v3';
        }
        if (isset($out['host'])) {
            $out['host'] = rtrim((string) $out['host'], '/');
        }
        if (isset($out['markup_ratio'])) {
            $out['markup_ratio'] = max(1.0, (float) $out['markup_ratio']);
        }
        if (isset($out['min_markup'])) {
            $out['min_markup'] = max(1.0, (float) $out['min_markup']);
        }
        if (isset($out['enabled'])) {
            $out['enabled'] = $out['enabled'] ? 1 : 0;
        }
        return $out;
    }
}
