<?php

declare(strict_types=1);

/**
 * 数据库访问类。
 *
 * 优先使用 mysqli，若环境不支持则自动回退到 PDO MySQL。
 * 这样可以兼容更多 PHP 7.4 ~ 8.5 环境。
 */
final class Database
{
    /**
     * @var array<string, mixed>
     */
    private static $config = [];

    /**
     * @var string|null
     */
    private static $driver = null;

    /**
     * @var mysqli|PDO|null
     */
    private static $connection = null;

    /**
     * 返回数据库配置。
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        if (self::$config !== []) {
            return self::$config;
        }

        self::$config = EM_CONFIG['db'];
        // 环境变量覆盖（Swoole WSL 环境下数据库 host 可能不同）
        $envHost = getenv('EM_DB_HOST');
        if ($envHost !== false && $envHost !== '') {
            self::$config['host'] = $envHost;
        }
        return self::$config;
    }

    /**
     * 返回表前缀。
     */
    public static function prefix(): string
    {
        return (string) self::config()['prefix'];
    }

    /**
     * 返回当前使用的驱动名称。
     */
    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        if (extension_loaded('mysqli')) {
            self::$driver = 'mysqli';
            return self::$driver;
        }

        if (extension_loaded('pdo_mysql')) {
            self::$driver = 'pdo';
            return self::$driver;
        }

        throw new RuntimeException('当前 PHP 环境既不支持 mysqli，也不支持 pdo_mysql，无法连接数据库');
    }

    /**
     * 获取数据库连接。
     *
     * @param bool $withoutDatabase 为 true 时只连接到数据库服务，不指定 dbname
     * @return mysqli|PDO
     */
    public static function connect(bool $withoutDatabase = false)
    {
        if (!$withoutDatabase && self::$connection !== null) {
            return self::$connection;
        }

        $driver = self::driver();
        if ($driver === 'mysqli') {
            $connection = self::createMysqliConnection($withoutDatabase);
        } else {
            $connection = self::createPdoConnection($withoutDatabase);
        }

        if (!$withoutDatabase) {
            self::$connection = $connection;
        }

        return $connection;
    }

    /**
     * 执行查询并返回一行结果。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = self::query($sql, $params);
        if ($rows === []) {
            return null;
        }

        return $rows[0];
    }

    /**
     * 按主键查单条记录（自动加前缀）。
     *
     * @param string $table 表名（不含前缀）
     * @param int    $id    主键 id
     * @return array<string, mixed>|null
     */
    public static function find(string $table, int $id): ?array
    {
        if ($id <= 0) return null;
        $sql = 'SELECT * FROM `' . self::prefix() . $table . '` WHERE `id` = ? LIMIT 1';
        return self::fetchOne($sql, [$id]);
    }

    /**
     * 执行查询并返回全部结果。
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $sql, array $params = []): array
    {
        if (self::driver() === 'mysqli') {
            $stmt = self::prepareMysqli($sql, $params);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];

            if ($result !== false) {
                $rows = $result->fetch_all(MYSQLI_ASSOC);
                $result->free();
            }

            $stmt->close();
            return $rows;
        }

        /** @var PDO $pdo */
        $pdo = self::connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * 执行写入、更新、删除语句。
     *
     * @param array<string, mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        if (self::driver() === 'mysqli') {
            $stmt = self::prepareMysqli($sql, $params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected;
        }

        /** @var PDO $pdo */
        $pdo = self::connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->rowCount();
    }

    /**
     * 执行不带绑定参数的原始 SQL。
     */
    public static function statement(string $sql, bool $withoutDatabase = false): bool
    {
        if (self::driver() === 'mysqli') {
            return (bool) self::connect($withoutDatabase)->query($sql);
        }

        return self::connect($withoutDatabase)->exec($sql) !== false;
    }

    /**
     * 插入数据到指定表。
     *
     * @param string $table 不带前缀的表名
     * @param array<string, mixed> $data 字段名 => 值
     * @return int 返回插入的自增 ID
     */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $table = self::prefix() . $table;
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($data);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        // INSERT 返回 lastInsertId，UPDATE/DELETE 返回 affected rows
        if (self::driver() === 'mysqli') {
            /** @var mysqli $conn */
            $conn = self::connect();
            $types = '';
            foreach ($values as $v) {
                $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
            }
            $refs = [];
            $refs[] = &$types;
            foreach ($values as $i => $v) {
                $refs[] = &$values[$i];
            }
            $stmt = $conn->prepare($sql);
            if ($refs !== []) {
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
            return $id;
        }

        /** @var PDO $pdo */
        $pdo = self::connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return (int) $pdo->lastInsertId();
    }

    /**
     * 更新指定表的数据。
     *
     * @param string $table 不带前缀的表名
     * @param array<string, mixed> $data 字段名 => 新值
     * @param int|array|string $where 主键值（直接是 ID）、或 WHERE 条件字符串、或 WHERE 条件数组 ['id' => ?]
     * @param array<int, mixed> $whereParams 当 $where 为字符串时的绑定参数
     * @return int 受影响的行数
     */
    public static function update(string $table, array $data, $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }

        $table = self::prefix() . $table;
        $sets = [];
        $params = [];

        foreach ($data as $key => $value) {
            $sets[] = "`{$key}` = ?";
            $params[] = $value;
        }

        // 处理 WHERE 条件
        if (is_int($where) || is_string($where) && preg_match('/^\d+$/', (string) $where)) {
            // 纯数字 ID
            $whereCond = '`id` = ?';
            $params[] = (int) $where;
        } elseif (is_string($where) && $where !== '') {
            // 字符串条件，如 "id = ? AND status = ?"
            $whereCond = $where;
            foreach ($whereParams as $wp) {
                $params[] = $wp;
            }
        } elseif (is_array($where) && $where !== []) {
            // 数组条件，如 ['id' => 5, 'status' => 1]
            $conds = [];
            foreach ($where as $k => $v) {
                $conds[] = "`{$k}` = ?";
                $params[] = $v;
            }
            $whereCond = implode(' AND ', $conds);
        } else {
            throw new InvalidArgumentException('update() 的 $where 参数无效');
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $whereCond);
        return self::execute($sql, $params);
    }

    /**
     * 开启事务。
     */
    public static function begin(): void
    {
        if (self::driver() === 'mysqli') {
            self::connect()->begin_transaction();
        } else {
            self::connect()->beginTransaction();
        }
    }

    /**
     * 提交事务。
     */
    public static function commit(): void
    {
        self::connect()->commit();
    }

    /**
     * 回滚事务。
     */
    public static function rollBack(): void
    {
        self::connect()->rollBack();
    }

    /**
     * 返回底层原始连接，仅在必须时使用。
     *
     * @param bool $withoutDatabase
     * @return mysqli|PDO
     */
    public static function rawConnection(bool $withoutDatabase = false)
    {
        return self::connect($withoutDatabase);
    }

    /**
     * 创建 mysqli 连接。
     *
     * @return mysqli
     */
    private static function createMysqliConnection(bool $withoutDatabase): mysqli
    {
        $config = self::config();
        $database = $withoutDatabase ? '' : (string) $config['dbname'];

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = mysqli_init();
        $mysqli->real_connect(
            (string) $config['host'],
            (string) $config['username'],
            (string) $config['password'],
            $database,
            (int) $config['port']
        );
        $mysqli->set_charset((string) $config['charset']);

        return $mysqli;
    }

    /**
     * 创建 PDO 连接。
     *
     * @return PDO
     */
    private static function createPdoConnection(bool $withoutDatabase): PDO
    {
        $config = self::config();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=%s',
            $config['host'],
            (int) $config['port'],
            $withoutDatabase ? '' : 'dbname=' . $config['dbname'] . ';',
            $config['charset']
        );

        return new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * mysqli 只支持问号占位符，因此需要把命名参数转换掉。
     *
     * @param array<string, mixed> $params
     * @return mysqli_stmt
     */
    private static function prepareMysqli(string $sql, array $params): mysqli_stmt
    {
        $normalizedSql = self::convertNamedToQuestion($sql, $params);
        $stmt = self::connect()->prepare($normalizedSql);

        if ($params !== []) {
            $values = array_values($params);
            $types = '';
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            $references = [];
            $references[] = $types;
            foreach ($values as $index => $value) {
                $references[] = &$values[$index];
            }

            call_user_func_array([$stmt, 'bind_param'], $references);
        }

        return $stmt;
    }

    /**
     * 把 :name 占位符转换成 ?，并按出现顺序重排参数。
     *
     * @param array<string, mixed> $params
     */
    private static function convertNamedToQuestion(string $sql, array &$params): string
    {
        $ordered = [];
        $convertedSql = preg_replace_callback('/:[a-zA-Z_][a-zA-Z0-9_]*/', function (array $matches) use (&$ordered, $params) {
            $name = substr($matches[0], 1);
            $ordered[] = array_key_exists($name, $params) ? $params[$name] : null;
            return '?';
        }, $sql);

        // 只有实际替换了命名占位符时才覆盖 params
        if ($ordered !== []) {
            $params = $ordered;
        }
        return $convertedSql === null ? $sql : $convertedSql;
    }
}
