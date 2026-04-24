<?php

if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

/**
 * 自定义页面模型（WordPress 式 Pages）。
 *
 * 数据表：em_page
 * 作用：存管站点的静态页（如"关于我们"、"联系方式"），可挂到导航。
 *
 * @package EM\Core\Model
 */
class PageModel
{
    /**
     * 获取页面列表（后台管理：含草稿 / 软删除按条件过滤）
     *
     * @param array  $where   筛选：status / keyword
     * @param int    $page    页码
     * @param int    $limit   每页条数
     * @param string $orderBy 排序
     * @return array{total:int, page:int, limit:int, list:array<int,array>}
     */
    public static function getList(array $where = [], int $page = 1, int $limit = 20, string $orderBy = 'sort ASC, id DESC'): array
    {
        $prefix = Database::prefix();
        $conditions = ['deleted_at IS NULL'];
        $params = [];

        if (isset($where['status']) && $where['status'] !== '') {
            $conditions[] = 'status = ?';
            $params[] = (int) $where['status'];
        }

        if (!empty($where['keyword'])) {
            $conditions[] = '(title LIKE ? OR slug LIKE ?)';
            $kw = '%' . $where['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $conditions);

        $total = Database::query("SELECT COUNT(*) as count FROM {$prefix}page {$whereSql}", $params);
        $totalCount = (int) ($total[0]['count'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "SELECT id, title, slug, status, template_name, sort, views_count, created_at, updated_at
                FROM {$prefix}page
                {$whereSql}
                ORDER BY {$orderBy}
                LIMIT {$offset}, {$limit}";
        $list = Database::query($sql, $params);

        return [
            'total' => $totalCount,
            'page'  => $page,
            'limit' => $limit,
            'list'  => $list,
        ];
    }

    /**
     * 按 ID 获取页面（含 content）
     */
    public static function getById(int $id): ?array
    {
        $prefix = Database::prefix();
        $row = Database::fetchOne(
            "SELECT * FROM {$prefix}page WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        return $row ?: null;
    }

    /**
     * 按 slug 获取"已发布"页面 —— 前台路由使用
     */
    public static function getBySlug(string $slug): ?array
    {
        $prefix = Database::prefix();
        $row = Database::fetchOne(
            "SELECT * FROM {$prefix}page WHERE slug = ? AND status = 1 AND deleted_at IS NULL LIMIT 1",
            [$slug]
        );
        return $row ?: null;
    }

    /**
     * slug 是否已被占用（排除自身）
     */
    public static function slugExists(string $slug, int $excludeId = 0): bool
    {
        $prefix = Database::prefix();
        if ($excludeId > 0) {
            $row = Database::fetchOne(
                "SELECT id FROM {$prefix}page WHERE slug = ? AND id != ? AND deleted_at IS NULL LIMIT 1",
                [$slug, $excludeId]
            );
        } else {
            $row = Database::fetchOne(
                "SELECT id FROM {$prefix}page WHERE slug = ? AND deleted_at IS NULL LIMIT 1",
                [$slug]
            );
        }
        return $row !== null;
    }

    /**
     * 创建页面
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $fields = ['title', 'slug', 'content', 'status', 'template_name',
                   'seo_title', 'seo_keywords', 'seo_description', 'sort'];
        $insert = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $insert[$f] = $data[$f];
            }
        }
        $id = Database::insert('page', $insert);
        return (int) $id;
    }

    /**
     * 更新页面
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): bool
    {
        $fields = ['title', 'slug', 'content', 'status', 'template_name',
                   'seo_title', 'seo_keywords', 'seo_description', 'sort'];
        $sets = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "`{$f}` = ?";
                $params[] = $data[$f];
            }
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $prefix = Database::prefix();
        return Database::execute(
            "UPDATE {$prefix}page SET " . implode(', ', $sets) . " WHERE id = ?",
            $params
        ) > 0;
    }

    /**
     * 软删除页面
     */
    public static function delete(int $id): bool
    {
        $prefix = Database::prefix();
        return Database::execute(
            "UPDATE {$prefix}page SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL",
            [$id]
        ) > 0;
    }

    /**
     * 浏览量 +1（前台访问时调用）
     */
    public static function incrementViews(int $id): void
    {
        $prefix = Database::prefix();
        Database::execute(
            "UPDATE {$prefix}page SET views_count = views_count + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * 获取所有已发布页面的 id/title/slug 简要列表 —— 供导航管理选择页面时用
     *
     * @return array<int, array{id:int, title:string, slug:string}>
     */
    public static function getPublishedSimple(): array
    {
        $prefix = Database::prefix();
        return Database::query(
            "SELECT id, title, slug FROM {$prefix}page WHERE status = 1 AND deleted_at IS NULL ORDER BY sort ASC, id DESC"
        );
    }
}
