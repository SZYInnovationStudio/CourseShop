<?php

declare(strict_types=1);

/**
 * 备份恢复脚本（P2 部署交付物）
 *
 * 用法示例：
 *   php bin/restore.php --file=storage/backups/courseshop-20261002-120000.sql.gz
 *   php bin/restore.php --file=xxx.sql.gz --files=xxx.files.zip --force
 *   php bin/restore.php --file=xxx.sql.gz --yes
 *
 * 说明：
 * - 未加 --force / --yes 时只做校验并打印将要执行的操作，不会真正导入或覆盖。
 * - 数据库通过 mysql / mariadb 客户端导入，可用 .env 的 DB_CLIENT_BINARY 指定路径。
 * - --files 指定的 .files.zip 会解压到项目根目录（覆盖同名文件）。
 *
 * 安全提示：数据库密码通过临时 --defaults-extra-file 传给客户端进程，进程结束后立即删除，
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
// 通用工具（与 bin/backup.php 保持一致）
// ------------------------------------------------------------------

/** 把相对路径按项目根目录解析为绝对路径（跨平台） */
function restoreResolvePath(string $path): string
{
    if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) === 1) {
        return rtrim($path, '/\\');
    }

    return rtrim(BASE_PATH . '/' . ltrim($path, '/\\'), '/\\');
}

/** 在 PATH 中查找可执行文件（Windows 自动尝试 .exe/.bat/.cmd 后缀） */
function restoreFindInPath(string $name): ?string
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
 * 探测数据库客户端二进制（逻辑与 backup.php 一致）。
 */
function restoreDetectBinary(string $envKey, array $names, array $winGlobs): ?string
{
    $configured = Env::get($envKey);
    if (is_string($configured) && trim($configured) !== '') {
        $configured = trim($configured);
        if (is_file($configured)) {
            return $configured;
        }
        $found = restoreFindInPath($configured);
        if ($found !== null) {
            return $found;
        }
    }

    foreach ($names as $name) {
        $found = restoreFindInPath($name);
        if ($found !== null) {
            return $found;
        }
    }

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
 * @param array<int, string> $command
 * @return array{0: int, 1: string, 2: string}
 */
function restoreRun(array $command, ?string $stdinFile = null): array
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
function restoreWriteCredentialsFile(string $password): ?string
{
    $path = tempnam(sys_get_temp_dir(), 'courseshop_restore_');
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
function restoreHumanSize(int $bytes): string
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

/**
 * 校验 zip 内条目是否安全（防止 zip-slip：绝对路径或上跳目录）。
 *
 * @return array{0: bool, 1: string}
 */
function restoreZipIsSafe(string $zipFile): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return [false, '无法打开压缩包'];
    }

    $count = $zip->numFiles;
    for ($i = 0; $i < $count; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

        if ($name === ''
            || str_starts_with($name, '/')
            || preg_match('#^[A-Za-z]:#', $name) === 1
            || in_array('..', explode('/', $name), true)
        ) {
            $zip->close();
            return [false, '压缩包内存在不安全的条目：' . $name];
        }
    }

    $zip->close();

    return [true, ''];
}

// ------------------------------------------------------------------
// 参数解析与校验
// ------------------------------------------------------------------

$options = getopt('', ['file:', 'files:', 'force', 'yes']);

$force = array_key_exists('force', $options) || array_key_exists('yes', $options);

if (!isset($options['file']) || !is_string($options['file']) || trim($options['file']) === '') {
    $error('缺少必填参数 --file=<备份文件>（.sql 或 .sql.gz）。');
    $error('用法：php bin/restore.php --file=storage/backups/xxx.sql.gz [--files=xxx.files.zip] --force');
    exit(1);
}

$filePath = restoreResolvePath(trim($options['file']));

if (!is_file($filePath)) {
    $error('备份文件不存在：' . $filePath);
    exit(1);
}

$lower     = strtolower($filePath);
$isGzip    = str_ends_with($lower, '.sql.gz');
$isPlainSql = str_ends_with($lower, '.sql');

if (!$isGzip && !$isPlainSql) {
    $error('备份文件仅支持 .sql 或 .sql.gz：' . basename($filePath));
    exit(1);
}

$filesZip = null;
if (isset($options['files']) && is_string($options['files']) && trim($options['files']) !== '') {
    $filesZip = restoreResolvePath(trim($options['files']));
    if (!is_file($filesZip)) {
        $error('文件备份不存在：' . $filesZip);
        exit(1);
    }
    if (!str_ends_with(strtolower($filesZip), '.zip')) {
        $error('文件备份必须为 .zip：' . basename($filesZip));
        exit(1);
    }
    [$safe, $reason] = restoreZipIsSafe($filesZip);
    if (!$safe) {
        $error('文件备份校验失败：' . $reason);
        exit(1);
    }
}

$clientBinary = restoreDetectBinary(
    'DB_CLIENT_BINARY',
    ['mysql', 'mariadb'],
    [
        'C:/Program Files/MariaDB*/bin/mariadb.exe',
        'C:/Program Files/MariaDB*/bin/mysql.exe',
        'C:/Program Files/MySQL/MySQL Server*/bin/mysql.exe',
        'C:/Program Files (x86)/MariaDB*/bin/mariadb.exe',
        'C:/Program Files (x86)/MySQL/MySQL Server*/bin/mysql.exe',
    ]
);

if ($clientBinary === null) {
    $error('未找到 mysql / mariadb 客户端可执行文件。');
    $error('请在 .env 中配置 DB_CLIENT_BINARY 指向其绝对路径后重试。');
    exit(1);
}

$dbHost    = (string) Config::get('database.host', '127.0.0.1');
$dbPort    = (int) Config::get('database.port', 3306);
$dbName    = (string) Config::get('database.database', '');
$dbUser    = (string) Config::get('database.username', '');
$dbPass    = (string) Config::get('database.password', '');
$dbCharset = (string) Config::get('database.charset', 'utf8mb4');

$zipEntries = 0;
if ($filesZip !== null) {
    $probe = new ZipArchive();
    if ($probe->open($filesZip) === true) {
        $zipEntries = $probe->numFiles;
        $probe->close();
    }
}

// ------------------------------------------------------------------
// 打印计划
// ------------------------------------------------------------------

$log('恢复计划：');
$log('  数据库备份文件：' . $filePath . '（' . restoreHumanSize((int) (filesize($filePath) ?: 0)) . '）');
$log('  目标数据库：' . $dbName . '@' . $dbHost . ':' . $dbPort . '（现有数据将被覆盖！）');
$log('  导入客户端：' . $clientBinary);
if ($filesZip !== null) {
    $log('  文件备份：' . $filesZip . ' -> 项目根目录（将覆盖约 ' . $zipEntries . ' 个文件）');
} else {
    $log('  文件备份：未指定（仅恢复数据库）。');
}

if (!$force) {
    $log('当前为校验模式（未加 --force / --yes），不会执行任何导入或覆盖操作。');
    $log('确认无误后请加上 --force 或 --yes 重新运行。');
    exit(0);
}

// ------------------------------------------------------------------
// 执行恢复
// ------------------------------------------------------------------

$log('开始恢复数据库：' . $dbName);

$importFile = $filePath;
$tempFile   = null;

if ($isGzip) {
    $compressed = (string) file_get_contents($filePath);
    $sql        = gzdecode($compressed);

    if ($sql === false) {
        $error('解压 .sql.gz 失败，备份文件可能已损坏。');
        exit(1);
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'courseshop_restore_');
    if ($tempFile === false || file_put_contents($tempFile, $sql) === false) {
        $error('无法写入临时 SQL 文件。');
        exit(1);
    }
    $importFile = $tempFile;
}

// 凭据写入临时文件，密码不出现在命令行参数中
$credentialsFile = restoreWriteCredentialsFile($dbPass);
if ($credentialsFile === null) {
    if ($tempFile !== null && is_file($tempFile)) {
        @unlink($tempFile);
    }
    $error('无法创建数据库凭据临时文件。');
    exit(1);
}

$importCommand = [
    $clientBinary,
    // --defaults-extra-file 必须作为第一个参数传入
    '--defaults-extra-file=' . $credentialsFile,
    '--host=' . $dbHost,
    '--port=' . (string) $dbPort,
    '--user=' . $dbUser,
    '--default-character-set=' . ($dbCharset !== '' ? $dbCharset : 'utf8mb4'),
    $dbName,
];

try {
    [$code, , $stderr] = restoreRun($importCommand, $importFile);
} catch (Throwable $e) {
    @unlink($credentialsFile);
    if ($tempFile !== null && is_file($tempFile)) {
        @unlink($tempFile);
    }
    $error('数据库导入失败：' . $e->getMessage());
    exit(1);
}

@unlink($credentialsFile);

if ($tempFile !== null && is_file($tempFile)) {
    @unlink($tempFile);
}

if ($code !== 0) {
    $error('数据库导入失败（退出码 ' . $code . '）。');
    if (trim($stderr) !== '') {
        $error('客户端输出：' . trim($stderr));
    }
    $error('请确认连接信息、账号权限，以及备份文件是否为该数据库导出。');
    exit(1);
}

$log('数据库恢复完成。');

// ---------------- 恢复文件 ----------------
if ($filesZip === null) {
    $log('未指定文件备份，跳过文件恢复。');
    $log('恢复完成。');
    exit(0);
}

$log('开始恢复文件：将覆盖项目根目录下约 ' . $zipEntries . ' 个文件。');

$zip = new ZipArchive();
if ($zip->open($filesZip) !== true) {
    $error('无法打开文件备份压缩包：' . $filesZip);
    exit(1);
}

if (!$zip->extractTo(BASE_PATH)) {
    $zip->close();
    $error('解压文件备份失败：' . $filesZip);
    exit(1);
}

$zip->close();

$log('文件恢复完成：' . basename($filesZip));
$log('恢复完成。');
exit(0);
