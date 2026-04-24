<?php

declare(strict_types=1);

/**
 * 导航数据模型。
 *
 * 支持2级导航：顶级(parent_id=0) 和 二级(parent_id>0)。
 * 类型：system(系统导航) / custom(自定义) / goods_cat(商品分类) / blog_cat(博客分类)
 */
final class NaviModel
{
    private string $table;

    public function __construct()
    {
        $this->table = Database::prefix() . 'navi';
    }

    /**
     * 获取所有导航（按 sort 排序）。
     */
    public function getAll(): array
    {
        $sql = sprintf('SELECT * FROM `%s` ORDER BY `sort` ASC, `id` ASC', $this->table);
        return Database::query($sql, []);
    }

    /**
     * 获取所有已启用的导航，构建为树形结构（前台使用）。
     *
     * @return array 顶级导航数组，每项含 children 子数组
     */
    public function getEnabledTree(): array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `status` = 1 ORDER BY `sort` ASC, `id` ASC', $this->table);
        $rows = Database::query($sql, []);

        $top = [];
        $childMap = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            if ((int) $row['parent_id'] === 0) {
                $top[$row['id']] = $row;
            } else {
                $childMap[(int) $row['parent_id']][] = $row;
            }
        }

        foreach ($top as &$item) {
            if (isset($childMap[$item['id']])) {
                $item['children'] = $childMap[$item['id']];
            }
        }
        unset($item);

        return array_values($top);
    }

    /**
     * 获取所有顶级导航。
     */
    public function getTopLevel(): array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `parent_id` = 0 ORDER BY `sort` ASC, `id` ASC', $this->table);
        return Database::query($sql, []);
    }

    /**
     * 按 ID 获取单条。
     */
    public function findById(int $id): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `id` = ? LIMIT 1', $this->table);
        return Database::fetchOne($sql, [$id]);
    }

    /**
     * 创建导航。
     */
    public function create(array $data): int
    {
        $fields = ['parent_id', 'name', 'type', 'type_ref_id', 'link', 'icon', 'target', 'sort', 'status', 'is_system'];

        $cols = [];
        $placeholders = [];
        $params = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $cols[] = '`' . $field . '`';
                $placeholders[] = '?';
                $params[] = (string) $data[$field];
            }
        }

        $cols[] = '`created_at`';
        $placeholders[] = 'NOW()';
        $cols[] = '`updated_at`';
        $placeholders[] = 'NOW()';

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            implode(', ', $cols),
            implode(', ', $placeholders)
        );

        Database::execute($sql, $params);
        return (int) Database::fetchOne('SELECT LAST_INSERT_ID() as id', [])['id'];
    }

    /**
     * 更新导航。
     */
    public function update(int $id, array $data): bool
    {
        $fields = ['parent_id', 'name', 'type', 'type_ref_id', 'link', 'icon', 'target', 'sort', 'status'];

        $sets = [];
        $params = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = '`' . $field . '` = ?';
                $params[] = (string) $data[$field];
            }
        }

        if ($sets === []) {
            return false;
        }

        $sets[] = '`updated_at` = NOW()';
        $params[] = $id;

        $sql = sprintf('UPDATE `%s` SET %s WHERE `id` = ? LIMIT 1', $this->table, implode(', ', $sets));
        return Database::execute($sql, $params) > 0;
    }

    /**
     * 删除导航（系统导航不可删除）。
     */
    public function delete(int $id): bool
    {
        $sql = sprintf('DELETE FROM `%s` WHERE `id` = ? AND `is_system` = 0 LIMIT 1', $this->table);
        return Database::execute($sql, [$id]) > 0;
    }

    /**
     * 检查是否有子导航。
     */
    public function hasChildren(int $parentId): bool
    {
        $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s` WHERE `parent_id` = ?', $this->table);
        $row = Database::fetchOne($sql, [$parentId]);
        return $row !== null && (int) $row['cnt'] > 0;
    }

    /**
     * 批量更新排序。
     *
     * @param array $sortData [[id => sort], ...]
     */
    public function batchUpdateSort(array $sortData): void
    {
        $sql = sprintf('UPDATE `%s` SET `sort` = ?, `updated_at` = NOW() WHERE `id` = ? LIMIT 1', $this->table);
        foreach ($sortData as $id => $sort) {
            Database::execute($sql, [(int) $sort, (int) $id]);
        }
    }

    /**
     * 获取导航总数。
     */
    public function count(): int
    {
        $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s`', $this->table);
        $row = Database::fetchOne($sql, []);
        return $row !== null ? (int) $row['cnt'] : 0;
    }
}
