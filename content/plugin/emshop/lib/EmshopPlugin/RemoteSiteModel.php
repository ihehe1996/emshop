<?php

declare(strict_types=1);

namespace EmshopPlugin;

use Database;

/**
 * 插件表 em_emshop_remote_site 的读写（表在 emshop_callback.php 的 callback_init 中创建）。
 */
final class RemoteSiteModel
{
    private static function table(): string
    {
        return Database::prefix() . 'emshop_remote_site';
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return Database::query(
            'SELECT * FROM `' . self::table() . '` ORDER BY `id` ASC'
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = Database::fetchOne(
            'SELECT * FROM `' . self::table() . '` WHERE `id` = ? LIMIT 1',
            [$id]
        );
        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $row = self::sanitize($data, false);
        return (int) Database::insert('emshop_remote_site', $row);
    }

    /**
     * @param array<string, mixed> $data
     * @param bool                   $allowEmptySecret 为 true 时从 $data 去掉 secret 键（编辑留空不修改）
     */
    public static function update(int $id, array $data, bool $allowEmptySecret = false): bool
    {
        if ($id <= 0) {
            return false;
        }
        $row = self::sanitize($data, $allowEmptySecret);
        if ($row === []) {
            return false;
        }
        return Database::update('emshop_remote_site', $row, $id) > 0;
    }

    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return Database::execute(
            'DELETE FROM `' . self::table() . '` WHERE `id` = ?',
            [$id]
        );
    }

    /**
     * 列表展示用：不返回明文 secret。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function rowForList(array $row): array
    {
        $secret = (string) ($row['secret'] ?? '');
        unset($row['secret']);
        $row['secret_masked'] = $secret === '' ? '—' : ('••••' . (strlen($secret) > 4 ? substr($secret, -4) : ''));
        $row['has_secret'] = $secret !== '' ? 1 : 0;
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function sanitize(array $data, bool $omitEmptySecret): array
    {
        $allowed = ['name', 'base_url', 'appid', 'secret', 'enabled', 'remark'];
        $out = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $out[$k] = $data[$k];
        }

        if (isset($out['name'])) {
            $out['name'] = mb_substr(trim((string) $out['name']), 0, 100, 'UTF-8');
        }
        if (isset($out['base_url'])) {
            $u = rtrim(trim((string) $out['base_url']), '/');
            if ($u !== '') {
                $u .= '/';
            }
            $out['base_url'] = mb_substr($u, 0, 500, 'UTF-8');
        }
        if (isset($out['appid'])) {
            $out['appid'] = preg_replace('/\D/', '', (string) $out['appid']) ?? '';
            $out['appid'] = mb_substr($out['appid'], 0, 32, 'UTF-8');
        }
        if (array_key_exists('secret', $out)) {
            $sec = (string) $out['secret'];
            if ($omitEmptySecret && $sec === '') {
                unset($out['secret']);
            } else {
                $out['secret'] = mb_substr(trim($sec), 0, 256, 'UTF-8');
            }
        }
        if (isset($out['enabled'])) {
            $out['enabled'] = ((int) $out['enabled']) === 1 ? 1 : 0;
        }
        if (isset($out['remark'])) {
            $out['remark'] = mb_substr(trim((string) $out['remark']), 0, 500, 'UTF-8');
        }

        return $out;
    }
}
