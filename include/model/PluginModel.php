<?php

declare(strict_types=1);

/**
 * 插件数据模型。
 *
 * 负责插件的安装、卸载、启用、禁用等数据库操作，
 * 以及扫描插件目录、解析插件头信息等。
 *
 * scope 约定：
 *   'main'          → 主站作用域
 *   'merchant_{id}' → 商户 id 对应的独立作用域
 * 物理文件（content/plugin/xxx）在全站共享一份，DB 记录按 scope 隔离。
 */
final class PluginModel
{
    private string $table;

    public function __construct()
    {
        $this->table = Database::prefix() . 'plugin';
    }


    /**
     * 扫描插件目录，返回所有磁盘上的插件及其头信息。
     *
     * 磁盘文件是全站共享的，不按 scope 隔离；由 DB 记录决定"在哪个 scope 下生效"。
     *
     * @return array<string, array<string, mixed>>
     */
    public function scanPlugins(): array
    {
        $plugins = [];
        $pluginDir = EM_ROOT . '/content/plugin';

        if (!is_dir($pluginDir)) {
            return $plugins;
        }
        $entries = scandir($pluginDir);



        foreach ($entries as $entry) {

            if ($entry === '.' || $entry === '..' || !is_dir($pluginDir . '/' . $entry)) {
                continue;
            }


            $pluginFile = $pluginDir . '/' . $entry . '/' . $entry . '.php';
            if (!is_file($pluginFile)) {
                continue;
            }


            $header = $this->parseHeader($pluginFile);

            if ($header === null) {
                continue;
            }

            $plugins[$entry] = $header;
            $plugins[$entry]['name'] = $entry;
            $plugins[$entry]['main_file'] = $entry . '.php';
        }

        return $plugins;
    }

    /**
     * 解析插件头信息。
     *
     * @return array<string, mixed>|null
     */
    public function parseHeader(string $pluginFile): ?array
    {
        if (!is_file($pluginFile) || !is_readable($pluginFile)) {
            return null;
        }

        $fp = fopen($pluginFile, 'r');
        if ($fp === false) {
            return null;
        }

        $header = [
            'name' => '',
            'title' => '',
            'version' => '1.0.0',
            'author' => '',
            'author_url' => '',
            'description' => '',
            'category' => '',
            'icon' => '',
            'preview' => '',
            'custom' => false,
        ];

        $inComment = false;
        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line);
            // 检测注释块开始
            if (preg_match('/^\s*\/\*\*\s*$/', $line)) {
                $inComment = true;
                continue;
            }



            // 检测注释块结束
            if ($inComment && preg_match('/^\s*\*\/\s*$/', $line)) {
                $inComment = false;
                continue;
            }



            // 解析注释行： * Plugin Name: xxx 或 * @xxx yyy
            if ($inComment) {
                $line = preg_replace('/^\s*\*\s*/', '', $line);
                $line = trim($line);

                // 直接写法：Plugin Name: xxx
                if (preg_match('/^Plugin\s+Name:\s*(.+)$/i', $line, $m)) {
                    $header['title'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Version:\s*(.+)$/i', $line, $m)) {
                    $header['version'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Plugin\s+URL:\s*(.+)$/i', $line, $m)) {
                    $header['author_url'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Description:\s*(.+)$/i', $line, $m)) {
                    $header['description'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Author:\s*(.+)$/i', $line, $m)) {
                    $header['author'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Author\s+URL:\s*(.+)$/i', $line, $m)) {
                    $header['author_url'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Category:\s*(.+)$/i', $line, $m)) {
                    $header['category'] = trim($m[1]);
                    continue;
                }
                // 自定义 / 本地开发插件标记：头部写 "Custom: true" 即跳过中心服务授权校验
                if (preg_match('/^Custom:\s*(.+)$/i', $line, $m)) {
                    $v = strtolower(trim($m[1]));
                    $header['custom'] = in_array($v, ['1', 'y', 'yes', 'true', 'on'], true);
                    continue;
                }

                // @标签写法：@PluginName xxx 或 @Version xxx
                if (preg_match('/^@(?:PluginName|Title)\s+(.+)$/i', $line, $m)) {
                    if ($header['title'] === '') {
                        $header['title'] = trim($m[1]);
                    }
                    continue;
                }
                if (preg_match('/^@Version\s+(.+)$/i', $line, $m)) {
                    $header['version'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^@Author(?:URL)?\s+(.+)$/i', $line, $m)) {
                    if (stripos($line, '@AuthorURL') === 0) {
                        $header['author_url'] = trim($m[1]);
                    } else {
                        $header['author'] = trim($m[1]);
                    }
                    continue;
                }
                if (preg_match('/^@Description\s+(.+)$/i', $line, $m)) {
                    $header['description'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^@Category\s+(.+)$/i', $line, $m)) {
                    $header['category'] = trim($m[1]);
                    continue;
                }
            }

            // 如果遇到非注释的 PHP 代码（通常是 define 或 function），停止解析
            if (!$inComment && preg_match('/^\s*<\?php\s/', $line)) {
                break;
            }
        }

        fclose($fp);

        if ($header['title'] === '' && $header['name'] === '') {
            return null;
        }

        return $header;
    }

    /**
     * 获取指定 scope 下所有已安装的插件（包含数据库信息）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllInstalled(string $scope): array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `scope` = ? ORDER BY `id` ASC',
            $this->table
        );
        return Database::query($sql, [$scope]);
    }

    /**
     * 按名称 + scope 查找插件。
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name, string $scope): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `name` = ? AND `scope` = ? LIMIT 1',
            $this->table
        );
        return Database::fetchOne($sql, [$name, $scope]);
    }

    /**
     * 按 ID 查找插件（id 全局唯一，无需 scope）。
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `id` = ? LIMIT 1',
            $this->table
        );
        return Database::fetchOne($sql, [$id]);
    }

    /**
     * 安装插件到指定 scope（写入数据库）。
     *
     * @param array<string, mixed> $info 从 parseHeader 解析出的信息
     */
    public function install(string $name, array $info, string $scope): int
    {
        $fields = [
            'name', 'title', 'version', 'author', 'author_url',
            'description', 'category', 'icon', 'preview',
            'main_file', 'setting_file', 'show_file', 'is_enabled',
        ];

        $cols = [];
        $placeholders = [];
        $params = [];

        foreach ($fields as $field) {
            $cols[] = '`' . $field . '`';
            $placeholders[] = '?';
            if ($field === 'is_enabled') {
                $params[] = '0';
            } else {
                $params[] = (string) ($info[$field] ?? '');
            }
        }

        // scope 字段单独追加
        $cols[] = '`scope`';
        $placeholders[] = '?';
        $params[] = $scope;

        $cols[] = '`installed_at`';
        $placeholders[] = 'NOW()';
        $cols[] = '`updated_at`';
        $placeholders[] = 'NOW()';

        // 覆盖 name 字段
        $keyName = array_search('`name`', $cols, true);
        if ($keyName !== false) {
            $params[$keyName] = $name;
        }

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
     * 卸载插件（只删当前 scope 下的记录，其它 scope 不受影响）。
     */
    public function uninstall(string $name, string $scope): bool
    {
        $sql = sprintf(
            'DELETE FROM `%s` WHERE `name` = ? AND `scope` = ? LIMIT 1',
            $this->table
        );
        return Database::execute($sql, [$name, $scope]) > 0;
    }

    /**
     * 启用插件（仅当前 scope）。
     */
    public function enable(string $name, string $scope): bool
    {
        $sql = sprintf(
            'UPDATE `%s` SET `is_enabled` = 1, `updated_at` = NOW() WHERE `name` = ? AND `scope` = ? LIMIT 1',
            $this->table
        );
        return Database::execute($sql, [$name, $scope]) > 0;
    }

    /**
     * 禁用插件（仅当前 scope）。
     */
    public function disable(string $name, string $scope): bool
    {
        $sql = sprintf(
            'UPDATE `%s` SET `is_enabled` = 0, `updated_at` = NOW() WHERE `name` = ? AND `scope` = ? LIMIT 1',
            $this->table
        );
        return Database::execute($sql, [$name, $scope]) > 0;
    }

    /**
     * 更新插件信息（仅当前 scope）。
     *
     * @param array<string, mixed> $data
     */
    public function update(string $name, array $data, string $scope): bool
    {
        $fields = ['title', 'version', 'author', 'author_url', 'description', 'category', 'setting_file', 'show_file', 'config'];

        $sets = [];
        $params = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = '`' . $field . '` = ?';
                $val = $data[$field];
                if (is_array($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $params[] = (string) $val;
            }
        }

        if ($sets === []) {
            return false;
        }

        $sets[] = '`updated_at` = NOW()';
        $params[] = $name;
        $params[] = $scope;

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `name` = ? AND `scope` = ? LIMIT 1',
            $this->table,
            implode(', ', $sets)
        );

        return Database::execute($sql, $params) > 0;
    }

    /**
     * 获取指定 scope 下已启用的插件名列表。
     *
     * @return array<int, string>
     */
    public function getEnabledNames(string $scope): array
    {
        $sql = sprintf(
            'SELECT `name` FROM `%s` WHERE `is_enabled` = 1 AND `scope` = ?',
            $this->table
        );
        $rows = Database::query($sql, [$scope]);
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row['name'];
        }
        return $names;
    }

    /**
     * 检查插件在指定 scope 下是否已安装。
     */
    public function isInstalled(string $name, string $scope): bool
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS cnt FROM `%s` WHERE `name` = ? AND `scope` = ? LIMIT 1',
            $this->table
        );
        $row = Database::fetchOne($sql, [$name, $scope]);
        return $row !== null && (int) $row['cnt'] > 0;
    }

    /**
     * 检查插件在指定 scope 下是否已启用。
     */
    public function isEnabled(string $name, string $scope): bool
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS cnt FROM `%s` WHERE `name` = ? AND `scope` = ? AND `is_enabled` = 1 LIMIT 1',
            $this->table
        );
        $row = Database::fetchOne($sql, [$name, $scope]);
        return $row !== null && (int) $row['cnt'] > 0;
    }

    /**
     * 统计指定 scope 下已安装插件数量。
     */
    public function countInstalled(string $scope): int
    {
        $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s` WHERE `scope` = ?', $this->table);
        $row = Database::fetchOne($sql, [$scope]);
        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * 检查某个 name 对应的物理插件目录是否已被任何 scope 安装（用于判断磁盘是否被占用）。
     * install 流程靠它决定"是否还需要下载 zip"——文件已在其他 scope 装过就不用重复下载。
     */
    public function existsInAnyScope(string $name): bool
    {
        $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s` WHERE `name` = ? LIMIT 1', $this->table);
        $row = Database::fetchOne($sql, [$name]);
        return $row !== null && (int) $row['cnt'] > 0;
    }
}
