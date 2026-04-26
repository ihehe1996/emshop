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
    /**
     * "全站统一走主站"分类清单。
     *
     * 这几个分类属于商城的底层能力：支付通道（钱归谁）/ 商品类型（卡密 / 实物 等核心行为）/
     * 商品增强（库存模糊化等）。它们由主站统一管理、统一启停，**商户站不再独立装 / 启停**：
     *
     *   - init.php 加载插件时：商户 scope 跑这里命中的插件按主站启用名单加载
     *   - 商户后台 appstore / plugin 页面：禁止装这些分类的插件
     *
     * 其它分类（系统扩展 / 系统插件 / 对接插件 等）保持按 scope 独立安装，给商户保留差异化扩展空间。
     */
    public const MAIN_ONLY_CATEGORIES = ['支付插件', '商品类型', '商品增强'];

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
            // Swoole: true/yes/1/on  → 标记本插件会注册 swoole 钩子（如 swoole_timer_tick），
            // 启用/禁用/安装/卸载本插件后需要触发 swoole worker 重载，否则旧代码继续跑
            'swoole' => false,
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
                if (preg_match('/^Swoole:\s*(.+)$/i', $line, $m)) {
                    // 兼容 true/yes/1/on/y 这几种"开"的写法，其它一律视为 false
                    $v = strtolower(trim($m[1]));
                    $header['swoole'] = in_array($v, ['true', 'yes', '1', 'on', 'y'], true);
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
     * 该插件是否在主文件 header 注释里声明了 `Swoole: true`。
     *
     * 用途：插件启用/禁用/安装/卸载时，仅当此方法返回 true 才需要 bump swoole 代码版本号
     * 触发 swoole worker reload —— 避免每次操作"前端 / UI 类"无关插件也白白 reload。
     */
    public function isSwoolePlugin(string $name, string $scope): bool
    {
        $mainFile = EM_ROOT . '/content/plugin/' . $name . '/' . $name . '.php';
        $header = $this->parseHeader($mainFile);
        if ($header === null) return false;
        return !empty($header['swoole']);
    }

    /**
     * 推进 swoole 代码版本号。swoole worker 在 timer tick 前对比此值，
     * 发现变了就 $server->reload() 自我重启，加载新插件代码。
     *
     * 调用时机：任何"会改变 swoole worker 内部代码"的动作完成之后 ——
     * 启用/禁用/安装/卸载带 Swoole:true 的插件、覆盖式更新插件文件等。
     */
    public static function bumpSwooleCodeVersion(): void
    {
        // 用毫秒+随机后缀避免同一秒内连续两次操作版本号相同
        $version = sprintf('%d.%04d', time(), random_int(0, 9999));
        Config::set('swoole_code_version', $version);
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
     * 取插件运行时加载名单 —— init.php 用它决定 include 哪些主文件。
     *
     * 规则：
     *   - 主站 scope：返回 main 启用的所有插件名（与 getEnabledNames 一致）
     *   - 商户 scope：MAIN_ONLY_CATEGORIES 的强制从 main 取启用名；其它分类从商户自身 scope 取
     *
     * @return array<int, string>
     */
    public function getRuntimeNames(string $scope): array
    {
        if ($scope === 'main') {
            return $this->getEnabledNames('main');
        }

        $cats = self::MAIN_ONLY_CATEGORIES;
        $placeholders = implode(',', array_fill(0, count($cats), '?'));

        // 主站强制类
        $sqlMain = sprintf(
            'SELECT `name` FROM `%s`
              WHERE `scope` = ? AND `is_enabled` = 1 AND `category` IN (' . $placeholders . ')',
            $this->table
        );
        $mainRows = Database::query($sqlMain, array_merge(['main'], $cats));

        // 商户自身的非强制类
        $sqlSelf = sprintf(
            'SELECT `name` FROM `%s`
              WHERE `scope` = ? AND `is_enabled` = 1 AND `category` NOT IN (' . $placeholders . ')',
            $this->table
        );
        $selfRows = Database::query($sqlSelf, array_merge([$scope], $cats));

        $names = array_unique(array_merge(
            array_column($mainRows, 'name'),
            array_column($selfRows, 'name')
        ));
        return array_values($names);
    }

    /**
     * 取指定 scope 下、属于某个分类的已启用插件（含 main_file）。
     *
     * 用途：PaymentService 取"主站启用的支付插件"时不能依赖 init.php 已加载的钩子
     * （init.php 只会按当前 scope 加载），需要按需读 DB + include 主文件再触发钩子。
     *
     * @return array<int, array{name:string, main_file:string}>
     */
    public function getEnabledByCategory(string $category, string $scope): array
    {
        $sql = sprintf(
            'SELECT `name`, `main_file` FROM `%s`
               WHERE `category` = ? AND `scope` = ? AND `is_enabled` = 1
               ORDER BY `id` ASC',
            $this->table
        );
        $rows = Database::query($sql, [$category, $scope]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name'      => (string) $row['name'],
                'main_file' => (string) ($row['main_file'] ?: ($row['name'] . '.php')),
            ];
        }
        return $out;
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
