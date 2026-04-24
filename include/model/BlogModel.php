<?php

if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

/**
 * 文章模型
 *
 * @package EM\Core\Model
 */
class BlogModel
{
    /**
     * 获取文章列表（前台：仅已发布、未删除）
     *
     * @param array  $where   筛选条件：category_id / keyword / status
     * @param int    $page    页码
     * @param int    $limit   每页条数
     * @param string $orderBy 排序
     * @return array{total:int, page:int, limit:int, list:array}
     */
    public static function getList(array $where = [], int $page = 1, int $limit = 20, string $orderBy = 'a.is_top DESC, a.sort ASC, a.id DESC'): array
    {
        $prefix = Database::prefix();
        $conditions = ['a.deleted_at IS NULL'];
        $params = [];

        if (isset($where['status']) && $where['status'] !== '') {
            $conditions[] = 'a.status = ?';
            $params[] = (int) $where['status'];
        }

        if (!empty($where['category_id'])) {
            $conditions[] = 'a.category_id = ?';
            $params[] = (int) $where['category_id'];
        }

        if (!empty($where['keyword'])) {
            $conditions[] = '(a.title LIKE ? OR a.excerpt LIKE ?)';
            $kw = '%' . $where['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        // 标签筛选
        $joinTag = '';
        if (!empty($where['tag_id'])) {
            $joinTag = "INNER JOIN {$prefix}blog_tag_relation btr ON a.id = btr.blog_id AND btr.tag_id = ?";
            $params[] = (int) $where['tag_id'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $conditions);

        $total = Database::query(
            "SELECT COUNT(*) as count FROM {$prefix}blog a {$joinTag} {$whereSql}",
            $params
        );
        $totalCount = (int) ($total[0]['count'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "SELECT a.*, c.name as category_name,
                       COALESCE(u.nickname, u.username, '管理员') as author
                FROM {$prefix}blog a
                {$joinTag}
                LEFT JOIN {$prefix}blog_category c ON a.category_id = c.id
                LEFT JOIN {$prefix}user u ON a.user_id = u.id
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
     * 获取文章详情（含分类名）
     *
     * @return array|null
     */
    public static function getById(int $id): ?array
    {
        $prefix = Database::prefix();
        $result = Database::query(
            "SELECT a.*, c.name as category_name,
                    COALESCE(u.nickname, u.username, '管理员') as author
             FROM {$prefix}blog a
             LEFT JOIN {$prefix}blog_category c ON a.category_id = c.id
             LEFT JOIN {$prefix}user u ON a.user_id = u.id
             WHERE a.id = ? LIMIT 1",
            [$id]
        );
        return $result[0] ?? null;
    }

    /**
     * 获取热门文章（按浏览量排序）
     *
     * @return array<array{id:int, title:string}>
     */
    public static function getPopular(int $limit = 5): array
    {
        $prefix = Database::prefix();
        return Database::query(
            "SELECT id, title, views_count FROM {$prefix}blog
             WHERE status = 1 AND deleted_at IS NULL
             ORDER BY views_count DESC, id DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * 获取上一篇/下一篇文章 ID
     *
     * @return array{prev_id:int|null, next_id:int|null}
     */
    public static function getPrevNextId(int $id): array
    {
        $prefix = Database::prefix();

        $prev = Database::fetchOne(
            "SELECT id, title FROM {$prefix}blog
             WHERE id < ? AND status = 1 AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            [$id]
        );
        $next = Database::fetchOne(
            "SELECT id, title FROM {$prefix}blog
             WHERE id > ? AND status = 1 AND deleted_at IS NULL
             ORDER BY id ASC LIMIT 1",
            [$id]
        );

        return [
            'prev_id'    => $prev ? (int) $prev['id'] : null,
            'prev_title' => $prev['title'] ?? null,
            'next_id'    => $next ? (int) $next['id'] : null,
            'next_title' => $next['title'] ?? null,
        ];
    }

    /**
     * 递增文章浏览量
     */
    public static function incrementViews(int $id): void
    {
        $prefix = Database::prefix();
        Database::execute(
            "UPDATE {$prefix}blog SET views_count = views_count + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * 创建文章
     *
     * @return int 新文章 ID
     */
    public static function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::insert('blog', $data);
    }

    /**
     * 更新文章
     */
    public static function update(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::update('blog', $data, $id) !== false;
    }

    /**
     * 逻辑删除文章
     */
    public static function delete(int $id): bool
    {
        return Database::update('blog', [
            'status'     => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
        ], $id) !== false;
    }

    /**
     * 物理删除文章
     */
    public static function forceDelete(int $id): bool
    {
        $prefix = Database::prefix();
        return Database::execute("DELETE FROM {$prefix}blog WHERE id = ?", [$id]) > 0;
    }
}
