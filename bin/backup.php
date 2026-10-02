<?php

declare(strict_types=1);

/**
 * 数据库 / 文件备份脚本（P2 部署交付物）
 *
 * 用法示例：
 *   php bin/backup.php                          # 备份数据库 + storage/uploads + storage/private
 *   php bin/backup.php --db-only                # 只备份数据库
 *   php bin/backup.php --no-videos              # 含文件，但不打包 storage/private/videos
 *   php bin/backup.php --dir=storage/backups    # 指定备份输出目录
 *   php bin/backup.php --keep=20                # 备份后仅保留最近 20 份
 *
 * 说明：
 * - 数据库使用 mysqldump / mariadb-dump 导出，压缩为 .sql.gz，并生成同名 .json 元数据。
 * - 文件备份（默认开启）把 storage/uploads 与 storage/private 打包为同名 .files.zip。
 * - 可用 .env 配置 DB_DUMP_BINARY 指定 dump 可执行文件路径。
 *
 * 安全提示：数据库密码通过临时 --defaults-extra-file 传给 dump 进程，进程结束后立即删除，
 * 避免密码出现在命令行参数（同机其他用户可通过 ps 看到）。
 */

use App\Support\Config;
use App\Support\Env;

require dirname(__DIR__) . '/app/bootstrap.php';

$log = static function (string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
};
$error = static function (string $message): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
};

// ------------------------------------------------------------------
// 通用工具
// ------------------------------------------------------------------

/** 把相对路径按项目根目录解析为绝对路径（跨平台） */
function backupResolvePath(string $path): string
{
    if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) === 1) {
        return rtrim($path, '/\\');
    }

    return rtrim(BASE_PATH . '/' . ltrim($path, '/\\'), '/\\');
}

/** 在 PATH 中查找可执行文件（Windows 自动尝试 .exe/.bat/.cmd 后缀） */
function backupFindInPath(string $name): ?string
{
    if ($name === '') {
        return null;
    }

    $path = (string) getenv('PATH');
    if ($path === '') {
        $path = (string) ($_SERVER['PATH'] ?? '');
    }

    $extensions = DIRECTORY_SEPARATOR === '\\'
        ? ['.exe', '.bat', '.cmd', '']
        : [''];

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        if ($directory === '') {
            continue;
        }

        foreach ($extensions as $extension) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * 探测数据库工具二进制。
 *
 * 优先级：环境变量指定 > PATH > Windows 常见安装位置。
 *
 * @param array<int, string> $names     PATH 中的可执行文件名
 * @param array<int, string> $winGlobs  Windows 安装目录通配符
 */
function backupDetectBinary(string $envKey, array $names, array $winGlobs): ?string
{
    // 1) 环境变量优先（可以是绝对路径，也可以是命令名）
    $configured = Env::get($envKey);
    if (is_string($configured) && trim($configured) !== '') {
        $configured = trim($configured);
        if (is_file($configured)) {
            return $configured;
        }
        $found = backupFindInPath($configured);
        if ($found !== null) {
            return $found;
        }
    }

    // 2) PATH
    foreach ($names as $name) {
        $found = backupFindInPath($name);
        if ($found !== null) {
            return $found;
        }
    }

    // 3) Windows 常见安装位置
    if (DIRECTORY_SEPARATOR === '\\') {
        foreach ($winGlobs as $pattern) {
            $matches = glob($pattern) ?: [];
            sort($matches, SORT_STRING);
            if ($matches !== []) {
                return $matches[0];
            }
        }
    }

    return null;
}

/**
 * 运行外部命令，返回 [退出码, 标准输出, 标准错误]。
 *
 * 使用数组形式的 argv（proc_open），绕过 shell，避免参数被再次解析 / 注入。
 *
 * @param array<int, string> $command
 * @return array{0: int, 1: string, 2: string}
 */
function backupRun(array $command, ?string $stdinFile = null): array
{
    $descriptors = [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'rb'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动进程：' . implode(' ', $command));
    }

    if ($stdinFile === null && isset($pipes[0])) {
        fclose($pipes[0]);
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * 把数据库凭据写入临时配置文件，供 --defaults-extra-file 使用，避免密码出现在命令行。
 *
 * 返回临时文件的绝对路径；写入失败返回 null。
 */
function backupWriteCredentialsFile(string $password): ?string
{
    $path = tempnam(sys_get_temp_dir(), 'courseshop_dump_');
    if ($path === false) {
        return null;
    }

    // 转义双引号与反斜杠，符合 MySQL 选项文件语法
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
    if (file_put_contents($path, "[client]\npassword=\"" . $escaped . "\"\n") === false) {
        @unlink($path);
        return null;
    }

    @chmod($path, 0600);

    return $path;
}

/** 人类可读的字节数 */
function backupHumanSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size  = (float) $bytes;
    $index = 0;

    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return $index === 0 ? $bytes . ' B' : number_format($size, 2) . ' ' . $units[$index];
}

/** 读取 Git 短版本号（可选，失败返回 null） */
function backupGitRevision(): ?string
{
    try {
        [$code, $stdout] = backupRun(['git', 'rev-parse', '--short', 'HEAD']);
    } catch (Throwable $e) {
        return null;
    }

    $revision = trim($stdout);

    return $code === 0 && $revision !== '' ? $revision : null;
}

/** 确保备份目录存在，并写入 .gitignore 忽略备份产物 */
function backupEnsureDirectory(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建备份目录：' . $dir);
    }

    $gitignore = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.gitignore';
    if (!is_file($gitignore)) {
        @file_put_contents($gitignore, "*\n!.gitignore\n");
    }
}

/**
 * 把目录递归加入 zip，条目路径相对于项目根目录（便于恢复到项目根）。
 *
 * @param array<int, string> $skipPaths 需要跳过的绝对路径前缀（已归一化）
 * @return int 加入的文件数量
 */
function backupAddDirectory(ZipArchive $zip, string $source, string $basePrefix, array $skipPaths = []): int
{
    if (!is_dir($source)) {
        return 0;
    }

    $count    = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $absolute = str_replace('\\', '/', $item->getPathname());

        $skip = false;
        foreach ($skipPaths as $prefix) {
            if ($absolute === $prefix || str_starts_with($absolute, $prefix . '/')) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        $relative = ltrim(substr($absolute, strlen($basePrefix)), '/');

        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
            continue;
        }

        $zip->addFile($item->getPathname(), $relative);
        $count++;
    }

    return $count;
}

/**
 * 按保留份数清理旧备份（同一时间戳的 .sql.gz/.sql/.files.zip/.json 视为一组）。
 */
function backupPrune(string $dir, int $keep, callable $log): void
{
    $patterns = [rtrim($dir, '/\\') . '/*.sql.gz', rtrim($dir, '/\\') . '/*.sql'];

    /** @var array<string, int> $sets 组名 => 修改时间 */
    $sets = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            $base = (string) preg_replace('/\.sql(\.gz)?$/', '', basename($file));
            $sets[$base] = max($sets[$base] ?? 0, (int) (filemtime($file) ?: time()));
        }
    }

    arsort($sets);

    $index = 0;
    foreach ($sets as $base => $mtime) {
        $index++;
        if ($index <= $keep) {
            continue;
        }

        foreach ([$base . '.sql.gz', $base . '.sql', $base . '.files.zip', $base . '.json'] as $name) {
            $file = rtrim($dir, '/\\') . '/' . $name;
            if (is_file($file)) {
                @unlink($file);
                $log('已删除过期备份：' . $name);
            }
        }
    }
}

// ------------------------------------------------------------------
// 参数解析
// ------------------------------------------------------------------

$options = getopt('', ['dir::', 'db-only', 'no-videos', 'keep::']);

$dbOnly   = array_key_exists('db-only', $options);
$noVideos = array_key_exists('no-videos', $options);
$keep     = isset($options['keep']) && is_string($options['keep']) && $options['keep'] !== ''
    ? max(1, (int) $options['keep'])
    : 10;

$backupDir = isset($options['dir']) && is_string($options['dir']) && trim($options['dir']) !== ''
    ? backupResolvePath(trim($options['dir']))
    : backupResolvePath('storage/backups');

// ------------------------------------------------------------------
// 主流程
// ------------------------------------------------------------------

try {
    backupEnsureDirectory($backupDir);
} catch (Throwable $e) {
    $error($e->getMessage());
    exit(1);
}

$dumpBinary = backupDetectBinary(
    'DB_DUMP_BINARY',
    ['mysqldump', 'mariadb-dump'],
    [
        'C:/Program Files/MariaDB*/bin/mariadb-dump.exe',
        'C:/Program Files/MariaDB*/bin/mysqldump.exe',
        'C:/Program Files/MySQL/MySQL Server*/bin/mysqldump.exe',
        'C:/Program Files (x86)/MariaDB*/bin/mariadb-dump.exe',
        'C:/Program Files (x86)/MySQL/MySQL Server*/bin/mysqldump.exe',
    ]
);

if ($dumpBinary === null) {
    $error('未找到 mysqldump / mariadb-dump 可执行文件。');
    $error('请在 .env 中配置 DB_DUMP_BINARY 指向其绝对路径后重试。');
    exit(1);
}

$dbHost    = (string) Config::get('database.host', '127.0.0.1');
$dbPort    = (int) Config::get('database.port', 3306);
$dbName    = (string) Config::get('database.database', '');
$dbUser    = (string) Config::get('database.username', '');
$dbPass    = (string) Config::get('database.password', '');
$dbCharset = (string) Config::get('database.charset', 'utf8mb4');

$timestamp = date('Ymd-His');
$baseName  = $dbName . '-' . $timestamp;
$basePath  = $backupDir . '/' . $baseName;

$log('备份目录：' . $backupDir);
$log('数据库工具：' . $dumpBinary);

// ---------------- 1. 数据库导出 ----------------
$log('开始备份数据库：' . $dbName . '@' . $dbHost . ':' . $dbPort);

$sqlFile = $basePath . '.sql';

// 凭据写入临时文件，密码不出现在命令行参数中
$credentialsFile = backupWriteCredentialsFile($dbPass);
if ($credentialsFile === null) {
    $error('无法创建数据库凭据临时文件。');
    exit(1);
}

$dumpCommand = [
    $dumpBinary,
    // --defaults-extra-file 必须作为第一个参数传入
    '--defaults-extra-file=' . $credentialsFile,
    '--host=' . $dbHost,
    '--port=' . (string) $dbPort,
    '--user=' . $dbUser,
    '--default-character-set=' . ($dbCharset !== '' ? $dbCharset : 'utf8mb4'),
    '--single-transaction',
    '--quick',
    '--no-tablespaces',
    '--result-file=' . $sqlFile,
    $dbName,
];

try {
    [$code, , $stderr] = backupRun($dumpCommand);
} catch (Throwable $e) {
    @unlink($credentialsFile);
    $error('数据库导出失败：' . $e->getMessage());
    exit(1);
}

@unlink($credentialsFile);

if ($code !== 0 || !is_file($sqlFile)) {
    @unlink($sqlFile);
    $error('数据库导出失败（退出码 ' . $code . '）。');
    if (trim($stderr) !== '') {
        $error('dump 输出：' . trim($stderr));
    }
    exit(1);
}

$sqlRaw = (string) file_get_contents($sqlFile);

// 压缩为 .sql.gz
$dbFile = $sqlFile;
$dbSize = strlen($sqlRaw);

if (function_exists('gzencode')) {
    $compressed = gzencode($sqlRaw, 6);
    if ($compressed === false) {
        $log('警告：gzencode 压缩失败，保留未压缩的 .sql 文件。');
    } else {
        $dbFile = $basePath . '.sql.gz';
        if (file_put_contents($dbFile, $compressed) === false) {
            $error('写入压缩备份失败：' . $dbFile);
            exit(1);
        }
        @unlink($sqlFile);
        $dbSize = strlen($compressed);
    }
} else {
    $log('警告：当前 PHP 未启用 zlib，保留未压缩的 .sql 文件。');
}

$log('数据库备份完成：' . basename($dbFile) . '（' . backupHumanSize($dbSize) . '）');

// ---------------- 2. 文件备份（可选） ----------------
$filesIncluded  = false;
$videosIncluded = !$noVideos;
$zipFile        = null;
$zipSize        = 0;

if ($dbOnly) {
    $log('已指定 --db-only，跳过文件备份。');
} elseif (!class_exists('ZipArchive')) {
    $log('警告：当前 PHP 未启用 zip 扩展，跳过文件备份。');
} else {
    $zipFile = $basePath . '.files.zip';
    $zip     = new ZipArchive();
    $opened  = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        $log('警告：无法创建文件备份压缩包（错误码 ' . $opened . '），跳过文件备份。');
        $zipFile = null;
    } else {
        $basePrefix = str_replace('\\', '/', rtrim(BASE_PATH, '/\\')) . '/';
        $skipPaths  = $noVideos
            ? [str_replace('\\', '/', rtrim(STORAGE_PATH, '/\\')) . '/private/videos']
            : [];

        $log('开始打包文件：storage/uploads 与 storage/private'
            . ($noVideos ? '（已排除 storage/private/videos）' : ''));

        $fileCount = 0;
        $fileCount += backupAddDirectory($zip, STORAGE_PATH . '/uploads', $basePrefix, $skipPaths);
        $fileCount += backupAddDirectory($zip, STORAGE_PATH . '/private', $basePrefix, $skipPaths);

        $zip->close();

        if (is_file($zipFile)) {
            $filesIncluded = true;
            $zipSize       = (int) (filesize($zipFile) ?: 0);
            $log('文件备份完成：' . basename($zipFile) . '（' . $fileCount . ' 个文件，' . backupHumanSize($zipSize) . '）');
        } else {
            $zipFile = null;
            $log('警告：文件备份压缩包未生成，跳过文件备份。');
        }
    }
}

// ---------------- 3. 元数据 ----------------
$metadata = [
    'created_at'      => date('Y-m-d H:i:s'),
    'database'        => $dbName,
    'host'            => $dbHost,
    'port'            => $dbPort,
    'app_env'         => (string) Config::get('app.env', ''),
    'php_version'     => PHP_VERSION,
    'db_file'         => basename($dbFile),
    'db_size'         => $dbSize,
    'files_included'  => $filesIncluded,
    'files_file'      => $zipFile !== null ? basename($zipFile) : null,
    'files_size'      => $zipSize,
    'videos_included' => $filesIncluded && $videosIncluded,
    'git_revision'    => backupGitRevision(),
];

$jsonFile = $basePath . '.json';
if (file_put_contents(
    $jsonFile,
    (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
) === false) {
    $error('写入元数据文件失败：' . $jsonFile);
    exit(1);
}

$log('元数据已写入：' . basename($jsonFile));

// ---------------- 4. 清理过期备份 ----------------
backupPrune($backupDir, $keep, $log);

$log('备份完成，共保留最近 ' . $keep . ' 份。');
exit(0);
