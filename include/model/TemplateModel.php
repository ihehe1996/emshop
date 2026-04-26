<?php

declare(strict_types=1);

/**
 * 模板数据模型。
 *
 * 负责模板扫描、头信息解析、安装记录和 PC / 手机端启用状态维护。
 *
 * scope 约定：
 *   'main'          → 主站作用域
 *   'merchant_{id}' → 商户 id 对应的独立作用域
 * 物理文件（content/template/xxx）在全站共享一份，DB 记录按 scope 隔离。
 */
final class TemplateModel
{
    private string $table;

    public function __construct()
    {
        $this->table = Database::prefix() . 'template';
    }

    /**
     * 确保模板表存在。
     */
    public function ensureTable(): void
    {
        try {
            $table = $this->table;
            Database::statement(
                "CREATE TABLE IF NOT EXISTS `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(64) NOT NULL COMMENT '模板目录名/标识',
                    `scope` VARCHAR(64) NOT NULL DEFAULT 'main' COMMENT '作用域：main=主站 / merchant_{id}=商户独立安装',
                    `title` VARCHAR(128) NOT NULL COMMENT '模板显示名称',
                    `version` VARCHAR(32) NOT NULL DEFAULT '1.0.0' COMMENT '模板版本',
                    `author` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '模板作者',
                    `author_url` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '作者主页',
                    `template_url` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '模板主页',
                    `description` TEXT NOT NULL COMMENT '模板描述',
                    `preview` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '预览图',
                    `header_file` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '模板头文件',
                    `callback_file` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '回调文件',
                    `plugin_file` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '系统挂载文件',
                    `is_active_pc` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否为PC端启用模板',
                    `is_active_mobile` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否为手机端启用模板',
                    `config` TEXT NOT NULL COMMENT '模板配置',
                    `installed_at` DATETIME NULL COMMENT '安装时间',
                    `updated_at` DATETIME NULL COMMENT '更新时间',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_name_scope` (`name`, `scope`),
                    KEY `idx_is_active_pc` (`is_active_pc`),
                    KEY `idx_is_active_mobile` (`is_active_mobile`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE='utf8mb4_unicode_ci' COMMENT='模板表'"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * 扫描 content/template 目录，返回磁盘上的模板信息。
     *
     * 磁盘文件全站共享，不按 scope 隔离；由 DB 记录决定"在哪个 scope 下生效"。
     *
     * @return array<string, array<string, mixed>>
     */
    public function scanTemplates(): array
    {
        $templates = [];
        $templateDir = EM_ROOT . '/content/template';
        if (!is_dir($templateDir)) {
            return $templates;
        }

        $entries = scandir($templateDir);
        if ($entries === false) {
            return $templates;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryDir = $templateDir . '/' . $entry;
            if (!is_dir($entryDir)) {
                continue;
            }

            $headerFile = $entryDir . '/header.php';
            if (!is_file($headerFile)) {
                continue;
            }

            $header = $this->parseHeader($headerFile);
            if ($header === null) {
                continue;
            }

            $header['name'] = $entry;
            $header['header_file'] = 'header.php';
            $header['callback_file'] = is_file($entryDir . '/callback.php') ? 'callback.php' : '';
            $header['plugin_file'] = is_file($entryDir . '/plugin.php') ? 'plugin.php' : '';
            $header['preview'] = is_file($entryDir . '/preview.jpg') ? '/content/template/' . $entry . '/preview.jpg' : '';

            $templates[$entry] = $header;
        }

        return $templates;
    }

    /**
     * 解析模板 header.php 中的头信息。
     *
     * @return array<string, mixed>|null
     */
    public function parseHeader(string $headerFile): ?array
    {
        if (!is_file($headerFile) || !is_readable($headerFile)) {
            return null;
        }

        $fp = fopen($headerFile, 'r');
        if ($fp === false) {
            return null;
        }

        $header = [
            'name' => '',
            'title' => '',
            'version' => '1.0.0',
            'author' => '',
            'author_url' => '',
            'template_url' => '',
            'description' => '',
            'preview' => '',
        ];

        $inComment = false;
        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line);
            if (preg_match('/^\s*\/\*\s*$/', $line) || preg_match('/^\s*\/\*\*\s*$/', $line)) {
                $inComment = true;
                continue;
            }

            if ($inComment && preg_match('/^\s*\*\/\s*$/', $line)) {
                $inComment = false;
                continue;
            }

            if ($inComment) {
                $line = preg_replace('/^\s*\*\s*/', '', $line);
                $line = trim((string) $line);

                if (preg_match('/^Template\s+Name:\s*(.+)$/i', $line, $m)) {
                    $header['title'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Version:\s*(.+)$/i', $line, $m)) {
                    $header['version'] = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Template\s+Url:\s*(.+)$/i', $line, $m)) {
                    $header['template_url'] = trim($m[1]);
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
                if (preg_match('/^Author\s+Url:\s*(.+)$/i', $line, $m)) {
                    $header['author_url'] = trim($m[1]);
                    continue;
                }
            }

            if (!$inComment && preg_match('/^\s*<\?php\s*/', $line)) {
                continue;
            }
        }

        fclose($fp);

        if ($header['title'] === '') {
            return null;
        }

        return $header;
    }

    /**
     * 获取指定 scope 下所有已安装模板记录。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllInstalled(string $scope): array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `scope` = ? ORDER BY `id` ASC', $this->table);
        return Database::query($sql, [$scope]);
    }

    /**
     * 按模板名称 + scope 查询安装记录。
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name, string $scope): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `name` = ? AND `scope` = ? LIMIT 1', $this->table);
        return Database::fetchOne($sql, [$name, $scope]);
    }

    /**
     * 判断模板是否已在指定 scope 下安装。
     */
    public function isInstalled(string $name, string $scope): bool
    {
        $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s` WHERE `name` = ? AND `scope` = ? LIMIT 1', $this->table);
        $row = Database::fetchOne($sql, [$name, $scope]);
        return $row !== null && (int) $row['cnt'] > 0;
    }

    /**
     * 安装模板到指定 scope 并写入数据库。
     *
     * @param array<string, mixed> $info
     */
    public function install(string $name, array $info, string $scope): int
    {
        $fields = [
            'name', 'title', 'version', 'author', 'author_url', 'template_url',
            'description', 'preview', 'header_file', 'callback_file',
            'plugin_file', 'is_active_pc', 'is_active_mobile', 'config',
        ];

        $cols = [];
        $placeholders = [];
        $params = [];

        foreach ($fields as $field) {
            $cols[] = '`' . $field . '`';
            $placeholders[] = '?';
            if ($field === 'config') {
                $params[] = '{}';
            } elseif ($field === 'is_active_pc') {
                $params[] = '0';
            } elseif ($field === 'is_active_mobile') {
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
        return (int) Database::fetchOne('SELECT LAST_INSERT_ID() AS id', [])['id'];
    }

    /**
     * 卸载模板记录（仅当前 scope，其它 scope 不受影响）。
     */
    public function uninstall(string $name, string $scope): bool
    {
        $sql = sprintf('DELETE FROM `%s` WHERE `name` = ? AND `scope` = ? LIMIT 1', $this->table);
        return Database::execute($sql, [$name, $scope]) > 0;
    }

    /**
     * 更新模板元数据或配置（仅当前 scope）。
     */
    public function update(string $name, array $data, string $scope): bool
    {
        $fields = ['title', 'version', 'author', 'author_url', 'template_url', 'description', 'preview', 'callback_file', 'plugin_file', 'config'];
        $sets = [];
        $params = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $sets[] = '`' . $field . '` = ?';
            $val = $data[$field];
            if (is_array($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $params[] = (string) $val;
        }

        if ($sets === []) {
            return false;
        }

        $sets[] = '`updated_at` = NOW()';
        $params[] = $name;
        $params[] = $scope;

        $sql = sprintf('UPDATE `%s` SET %s WHERE `name` = ? AND `scope` = ? LIMIT 1', $this->table, implode(', ', $sets));
        return Database::execute($sql, $params) > 0;
    }

    /**
     * 获取指定 scope + 终端当前启用的模板名。
     */
    public function getActiveTheme(string $client, string $scope): string
    {
        $column = $this->getActiveColumn($client);
        $sql = sprintf('SELECT `name` FROM `%s` WHERE `%s` = 1 AND `scope` = ? LIMIT 1', $this->table, $column);
        $row = Database::fetchOne($sql, [$scope]);
        return $row !== null ? (string) $row['name'] : '';
    }

    /**
     * 将模板设为指定 scope + 终端的当前启用模板。
     * 切换时只会清该 scope 下的旧启用态，不会影响其它 scope。
     */
    public function setActiveTheme(string $client, string $name, string $scope): bool
    {
        $column = $this->getActiveColumn($client);
        Database::execute(sprintf('UPDATE `%s` SET `%s` = 0 WHERE `scope` = ?', $this->table, $column), [$scope]);
        $affected = Database::execute(
            sprintf('UPDATE `%s` SET `%s` = 1, `updated_at` = NOW() WHERE `name` = ? AND `scope` = ? LIMIT 1', $this->table, $column),
            [$name, $scope]
        );
        return $affected > 0;
    }

    /**
     * 取消指定 scope + 终端的当前启用模板。
     */
    public function clearActiveTheme(string $client, string $scope): bool
    {
        $column = $this->getActiveColumn($client);
        $affected = Database::execute(
            sprintf('UPDATE `%s` SET `%s` = 0, `updated_at` = NOW() WHERE `%s` = 1 AND `scope` = ?', $this->table, $column, $column),
            [$scope]
        );
        return $affected > 0;
    }

    /**
     * 判断模板是否已在指定 scope + 终端启用。
     */
    public function isActive(string $name, string $client, string $scope): bool
    {
        $column = $this->getActiveColumn($client);
        $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s` WHERE `name` = ? AND `scope` = ? AND `%s` = 1 LIMIT 1', $this->table, $column);
        $row = Database::fetchOne($sql, [$name, $scope]);
        return $row !== null && (int) $row['cnt'] > 0;
    }

    /**
     * 检查某个 name 对应的物理模板目录是否已被任何 scope 安装（用于判断磁盘是否被占用）。
     * install 流程靠它决定"是否还需要下载 zip"——文件已在其他 scope 装过就不用重复下载。
     */
    public function existsInAnyScope(string $name): bool
    {
        $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s` WHERE `name` = ? LIMIT 1', $this->table);
        $row = Database::fetchOne($sql, [$name]);
        return $row !== null && (int) $row['cnt'] > 0;
    }

    /**
     * 判断模板是否提供设置页。
     */
    public function hasSettingFile(string $name): bool
    {
        return is_file(EM_ROOT . '/content/template/' . $name . '/setting.php');
    }

    /**
     * 获取模板设置页绝对路径。
     */
    public function getSettingFilePath(string $name): string
    {
        return EM_ROOT . '/content/template/' . $name . '/setting.php';
    }

    /**
     * 获取模板生命周期回调文件绝对路径。
     */
    public function getCallbackFilePath(string $name): string
    {
        return EM_ROOT . '/content/template/' . $name . '/callback.php';
    }

    /**
     * 获取模板挂载文件绝对路径。
     */
    public function getPluginFilePath(string $name): string
    {
        return EM_ROOT . '/content/template/' . $name . '/plugin.php';
    }

    /**
     * 获取模板预览图 URL。
     */
    public function getPreviewUrl(string $name): string
    {
        return is_file(EM_ROOT . '/content/template/' . $name . '/preview.jpg') ? '/content/template/' . $name . '/preview.jpg' : '';
    }

    /**
     * 将终端标识映射为数据库字段名。
     */
    private function getActiveColumn(string $client): string
    {
        if ($client === 'pc') {
            return 'is_active_pc';
        }
        if ($client === 'mobile') {
            return 'is_active_mobile';
        }
        throw new RuntimeException('未知终端类型');
    }
}
