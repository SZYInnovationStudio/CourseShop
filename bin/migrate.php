<?php

declare(strict_types=1);

/**
 * 数据库迁移执行器（P2 部署交付物）
 *
 * 用法示例：
 *   php bin/migrate.php                  # 执行 database/migrations 下所有未应用的迁移
 *   php bin/migrate.php --status         # 仅列出「已应用 / 待应用」，不执行
 *   php bin/migrate.php --pretend        # 仅打印将要执行的文件，不执行
 *   php bin/migrate.php --dir=database/migrations
 *   php bin/migrate.php --force          # APP_ENV=production 时必须显式加上才会执行
 *
 * 说明：
 * - 首次运行会自动创建 `migrations` 表（filename 唯一 + checksum + applied_at）记录迁移状态。
 * - 迁移文件按文件名升序执行；单个文件可包含多条 SQL，脚本按分号切分逐条执行，
 *   并正确跳过字符串 / 注释中的分号。
 * - 执行失败会输出错误并以状态码 1 退出，且不会写入已应用记录。
 */

use App\Support\Config;
use App\Support\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

$log = static function (string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
};
$error = static function (string $message): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
};

$options = getopt('', ['status', 'pretend', 'dir::', 'force']);

$statusOnly = array_key_exists('status', $options);
$pretend    = array_key_exists('pretend', $options);
$force      = array_key_exists('force', $options);

/** 迁移目录：默认 database/migrations，--dir= 可覆盖（相对路径基于项目根目录） */
$dir = isset($options['dir']) && is_string($options['dir']) && trim($options['dir']) !== ''
    ? trim($options['dir'])
    : BASE_PATH . '/database/migrations';

if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir) !== 1) {
    $dir = BASE_PATH . '/' . ltrim($dir, '/\\');
}
$dir = rtrim($dir, '/\\');

/**
 * 初始化迁移记录表（幂等）
 */
function migrateEnsureTable(): void
{
    Database::pdo()->exec(
        "CREATE TABLE IF NOT EXISTS `migrations` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
            `filename`   VARCHAR(255)    NOT NULL COMMENT '迁移文件名（含扩展名）',
            `checksum`   CHAR(64)        NOT NULL DEFAULT '' COMMENT '文件内容 SHA-256 校验和',
            `applied_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '应用时间',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_migrations_filename` (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='迁移记录表'"
    );
}

/**
 * 按分号切分 SQL 脚本。
 *
 * 正确处理：单引号 / 双引号 / 反引号包裹的字符串、反斜杠转义、
 * `--` 与 `#` 行注释、`/* ... *\/` 块注释。
 *
 * @return array<int, string>
 */
function migrateSplitStatements(string $sql): array
{
    $statements = [];
    $buffer     = '';
    $length     = strlen($sql);
    $quote      = '';   // 当前字符串定界符：' " ` 之一，空表示不在字符串内

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($quote === '') {
            // 行注释：-- 或 #
            if (($char === '-' && $next === '-') || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }

            // 块注释：/* ... */
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = $end === false ? $length : $end + 1;
                continue;
            }

            // 语句结束
            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            }

            $buffer .= $char;
            continue;
        }

        // 字符串内部：反斜杠转义，连同下一个字符一起吞掉
        if ($char === '\\') {
            $buffer .= $char . $next;
            $i++;
            continue;
        }

        if ($char === $quote) {
            // 连续两个定界符视为字面量（如 ''）
            if ($next === $quote) {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            $quote = '';
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * 执行一个迁移文件，返回成功执行的语句条数。
 */
function migrateRunFile(string $file): int
{
    $content = (string) file_get_contents($file);
    // 去掉 UTF-8 BOM
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

    $pdo   = Database::pdo();
    $count = 0;

    foreach (migrateSplitStatements($content) as $statement) {
        // 不用 exec()：迁移文件含 PREPARE/EXECUTE 等会返回结果集的语句，
        // exec() 不消费结果集，后续语句会报 2014（unbuffered queries are active）。
        $stmt = $pdo->query($statement);
        if ($stmt instanceof PDOStatement) {
            $stmt->fetchAll();
            $stmt->closeCursor();
        }
        $count++;
    }

    return $count;
}

/**
 * 在事务中执行迁移文件并写入已应用记录，返回执行的语句条数。
 *
 * 注意：MySQL / MariaDB 的 DDL 语句会隐式提交事务，因此对含 DDL 的迁移无法真正回滚；
 * 这里的事务主要保证「SQL 执行」与「写入迁移记录」尽量原子（对纯 DML 迁移完全有效），
 * 并在失败时回滚未提交的部分。
 */
function migrateApplyFile(string $file, string $name, string $checksum): int
{
    $pdo = Database::pdo();
    $pdo->beginTransaction();

    try {
        $count = migrateRunFile($file);

        Database::execute(
            'INSERT INTO `migrations` (`filename`, `checksum`, `applied_at`) VALUES (?, ?, NOW())',
            [$name, $checksum]
        );

        // DDL 会隐式提交事务，此时可能已不在事务中，需判断后再提交
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

// ------------------------------------------------------------------
// 主流程
// ------------------------------------------------------------------

try {
    migrateEnsureTable();
} catch (Throwable $e) {
    $error('初始化 migrations 表失败：' . $e->getMessage());
    exit(1);
}

/** @var array<string, array<string, mixed>> $applied */
$applied = [];
foreach (Database::select('SELECT `filename`, `checksum`, `applied_at` FROM `migrations`') as $row) {
    $applied[(string) $row['filename']] = $row;
}

if (!is_dir($dir)) {
    $error('迁移目录不存在：' . $dir);
    exit(1);
}

$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (!array_key_exists($name, $applied)) {
        $pending[] = $file;
    }
}

$appEnv = (string) Config::get('app.env', 'production');

// --status：只打印状态
if ($statusOnly) {
    $log('迁移目录：' . $dir);

    $appliedNames = array_keys($applied);
    sort($appliedNames, SORT_STRING);

    $log('已应用迁移（' . count($appliedNames) . '）：');
    if ($appliedNames === []) {
        fwrite(STDOUT, '    （无）' . PHP_EOL);
    }
    foreach ($appliedNames as $name) {
        $at = (string) ($applied[$name]['applied_at'] ?? '');
        fwrite(STDOUT, '    [x] ' . $name . ($at !== '' ? '  (' . $at . ')' : '') . PHP_EOL);
    }

    $log('待应用迁移（' . count($pending) . '）：');
    if ($pending === []) {
        fwrite(STDOUT, '    （无）' . PHP_EOL);
    }
    foreach ($pending as $file) {
        fwrite(STDOUT, '    [ ] ' . basename($file) . PHP_EOL);
    }

    exit(0);
}

// --pretend：只打印将要执行的文件
if ($pretend) {
    if ($pending === []) {
        $log('没有待应用的迁移。');
    } else {
        $log('以下迁移将被执行（--pretend，仅打印不执行）：');
        foreach ($pending as $file) {
            fwrite(STDOUT, '    ' . basename($file) . PHP_EOL);
        }
    }
    exit(0);
}

if ($pending === []) {
    $log('没有待应用的迁移，数据库已是最新。');
    exit(0);
}

// 生产环境守卫：APP_ENV=production 时必须显式 --force
if ($appEnv === 'production' && !$force) {
    $error('当前为生产环境（APP_ENV=production），拒绝执行迁移。');
    $error('确认无误后请显式加上 --force：php bin/migrate.php --force');
    exit(1);
}

$log('开始执行迁移：共 ' . count($pending) . ' 个待应用文件。');

$succeeded = 0;
foreach ($pending as $file) {
    $name     = basename($file);
    $checksum = (string) (hash_file('sha256', $file) ?: '');

    $log('执行迁移：' . $name);

    try {
        $count = migrateApplyFile($file, $name, $checksum);
    } catch (Throwable $e) {
        $error('迁移执行失败：' . $name);
        $error('错误信息：' . $e->getMessage());
        $error('本次文件未写入已应用记录，修复后重新执行即可。');
        exit(1);
    }

    $succeeded++;
    $log('迁移完成：' . $name . '（执行 ' . $count . ' 条语句）');
}

$log('全部完成：本次成功应用 ' . $succeeded . ' 个迁移。');
exit(0);
