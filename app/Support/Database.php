<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * PDO 单例封装
 *
 * 统一使用预处理语句，关闭模拟预处理（真正的服务端预处理），
 * 兼容 MySQL 5.7 / 8.0 与 MariaDB 10.4+。
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** 当前事务嵌套层级（0 表示无活动事务） */
    private static int $transactionLevel = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = (array) Config::get('database', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['database'] ?? ''),
            (string) ($config['charset'] ?? 'utf8mb4')
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // 关闭模拟预处理，使用真实的服务端预处理，防止 SQL 注入
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $e) {
            throw new PDOException('数据库连接失败：' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }

    /**
     * 查询多行
     *
     * @param  array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    /**
     * 查询单行，不存在返回 null
     *
     * @param  array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * 查询单个值（第一行第一列）
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * 执行写操作，返回受影响行数
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    /**
     * 插入并返回自增主键
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function insert(string $sql, array $bindings = []): int
    {
        self::run($sql, $bindings);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * 事务包装
     *
     * 支持嵌套调用：最外层开启真实事务，内层使用 SAVEPOINT。
     * 内层异常只回滚到对应 SAVEPOINT 并向上抛出，最外层负责最终提交/回滚。
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo      = self::pdo();
        $level    = self::$transactionLevel;
        $savepoint = 'sp_' . $level;

        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT `' . $savepoint . '`');
        }

        self::$transactionLevel = $level + 1;

        try {
            $result = $callback($pdo);

            self::$transactionLevel = $level;

            if ($level === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT `' . $savepoint . '`');
            }

            return $result;
        } catch (Throwable $e) {
            self::$transactionLevel = $level;

            if ($pdo->inTransaction()) {
                if ($level === 0) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT `' . $savepoint . '`');
                }
            }

            throw $e;
        }
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private static function run(string $sql, array $bindings): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            // 顺序参数（0 基）转成 PDO 的 1 基
            $parameter = is_int($key) ? $key + 1 : $key;
            $statement->bindValue($parameter, $value, self::paramType($value));
        }

        $statement->execute();

        return $statement;
    }

    private static function paramType(mixed $value): int
    {
        if (is_int($value) || is_bool($value)) {
            return PDO::PARAM_INT;
        }
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }
}
