<?php

declare(strict_types=1);

/**
 * 插件配置存储类。
 *
 * 配置存储在 options 表中，以 (type, title, scope, name) 四元组区分。
 * 插件可通过 Storage::getInstance('插件名') 获取自己的存储实例；
 * scope 由请求入口写入的 $GLOBALS['__em_current_scope'] 决定（主站 'main' / 商户 'merchant_{id}'），
 * 对插件作者透明 —— 同一份 setting.php 在不同端调用时自动读写对应 scope 的行。
 *
 * @example
 * $storage = Storage::getInstance('tips');
 * $storage->setValue('api_key', 'hello');
 * $value = $storage->getValue('api_key');
 * $storage->deleteAllName('YES'); // 插件卸载时清理所有配置
 */
final class Storage
{
    private string $plugin;
    private string $scope;
    private array $data = [];
    private bool $loaded = false;

    /** @var array<string, self> */
    private static array $instances = [];

    /**
     * 获取指定插件的存储实例（按 plugin+scope 做单例）。
     * scope 优先从 $GLOBALS['__em_current_scope'] 读，缺省 'main'。
     */
    public static function getInstance(string $plugin): self
    {
        $scope = (string) ($GLOBALS['__em_current_scope'] ?? 'main');
        if ($scope === '') $scope = 'main';
        $key = $plugin . '@' . $scope;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($plugin, $scope);
        }
        return self::$instances[$key];
    }

    private function __construct(string $plugin, string $scope)
    {
        $this->plugin = $plugin;
        $this->scope = $scope;
    }

    /**
     * 读取插件配置。
     */
    public function getValue(string $key, $default = null)
    {
        $this->ensureLoaded();
        if (array_key_exists($key, $this->data)) {
            $val = $this->data[$key];
            // 尝试 JSON 解码
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                return $decoded;
            }
            return $val;
        }
        return $default;
    }

    /**
     * 写入插件配置。
     *
     * @param string $key 配置键
     * @param mixed $value 配置值（数组/对象会自动 JSON 编码）
     */
    public function setValue(string $key, $value): bool
    {
        $this->ensureLoaded();

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $cond = $this->makeCondition($key);

        $table = Database::prefix() . 'options';
        $sqlCheck = sprintf(
            'SELECT COUNT(*) AS cnt FROM `%s` WHERE `type` = ? AND `title` = ? AND `scope` = ? AND `name` = ?',
            $table
        );
        $row = Database::fetchOne($sqlCheck, [$cond['type'], $cond['title'], $cond['scope'], $cond['name']]);
        $exists = $row !== null && (int) $row['cnt'] > 0;

        if ($exists) {
            $sql = sprintf(
                'UPDATE `%s` SET `content` = ? WHERE `type` = ? AND `title` = ? AND `scope` = ? AND `name` = ?',
                $table
            );
            Database::execute($sql, [(string) $value, $cond['type'], $cond['title'], $cond['scope'], $cond['name']]);
        } else {
            $sql = sprintf(
                'INSERT INTO `%s` (`type`, `title`, `scope`, `name`, `content`) VALUES (?, ?, ?, ?, ?)',
                $table
            );
            Database::execute($sql, [$cond['type'], $cond['title'], $cond['scope'], $cond['name'], (string) $value]);
        }

        $this->data[$key] = (string) $value;
        return true;
    }

    /**
     * 删除指定的插件配置（仅当前 scope）。
     */
    public function deleteValue(string $key): bool
    {
        $cond = $this->makeCondition($key);
        $table = Database::prefix() . 'options';
        $sql = sprintf(
            'DELETE FROM `%s` WHERE `type` = ? AND `title` = ? AND `scope` = ? AND `name` = ?',
            $table
        );
        Database::execute($sql, [$cond['type'], $cond['title'], $cond['scope'], $cond['name']]);
        unset($this->data[$key]);
        return true;
    }

    /**
     * 删除插件所有配置（插件卸载时调用；仅当前 scope，不影响其它 scope）。
     *
     * @param string $confirm 通常传 'YES' 作为确认
     */
    public function deleteAllName(string $confirm): bool
    {
        if ($confirm !== 'YES') {
            return false;
        }

        $table = Database::prefix() . 'options';
        $sql = sprintf('DELETE FROM `%s` WHERE `type` = ? AND `title` = ? AND `scope` = ?', $table);
        Database::execute($sql, ['plugin', $this->plugin, $this->scope]);
        $this->data = [];
        return true;
    }

    /**
     * 批量获取插件配置。
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        $this->ensureLoaded();
        return $this->data;
    }

    /**
     * 构造配置条件（type + title + scope + name）。
     */
    private function makeCondition(string $key): array
    {
        return [
            'type'  => 'plugin',
            'title' => $this->plugin,
            'scope' => $this->scope,
            'name'  => $key,
        ];
    }

    /**
     * 确保配置已加载（按当前 scope 过滤）。
     */
    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->data = [];

        try {
            $table = Database::prefix() . 'options';
            $sql = sprintf(
                'SELECT `name`, `content` FROM `%s` WHERE `type` = ? AND `title` = ? AND `scope` = ?',
                $table
            );
            $rows = Database::query($sql, ['plugin', $this->plugin, $this->scope]);

            foreach ($rows as $row) {
                $name = isset($row['name']) ? (string) $row['name'] : '';
                if ($name === '') {
                    continue;
                }
                $this->data[$name] = isset($row['content']) ? (string) $row['content'] : '';
            }
        } catch (Throwable $e) {
            $this->data = [];
        }
    }
}
