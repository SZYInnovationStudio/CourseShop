<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 队列自动运行
 *
 * 目标：即装即用——不要求用户手工配置 systemd / crontab。在有任务产生（提交转码、发信）
 * 或管理员访问后台时，按节流策略拉起一个「处理完即退出」的消费者进程，尽快消化待办任务。
 *
 * - 触发是尽力而为：非 Unix 环境、进程函数被禁用、找不到可用的 PHP CLI 时静默跳过，
 *   站点其余功能不受影响（此时可改用 README 中的常驻消费者或计划任务）
 * - 多个消费者并存是安全的：任务领取由 Queue 的数据库事务加锁保证互斥
 * - 生命周期：自动拉起的进程用 nohup 脱离请求，跑完待办即退出，不会常驻残留
 */
final class QueueRunner
{
    /** 两次自动触发的最小间隔（秒），避免每个请求都拉起进程 */
    private const THROTTLE_SECONDS = 10;

    /** 单次自动运行最多处理的任务数，避免长时间占用 */
    private const MAX_JOBS = 20;

    /**
     * 触发一次后台消费
     *
     * @param bool $force 忽略节流限制，用于用户刚刚提交任务的场景（尽快开始处理）
     */
    public static function trigger(bool $force = false): void
    {
        if (PHP_SAPI === 'cli' || !self::canSpawn()) {
            return;
        }

        $stamp = self::stampFile();
        $last  = is_file($stamp) ? (int) @filemtime($stamp) : 0;

        if (!$force && $last > 0 && time() - $last < self::THROTTLE_SECONDS) {
            return;
        }

        $binary = self::cliBinary();

        if ($binary === '') {
            return;
        }

        @file_put_contents($stamp, (string) time(), LOCK_EX);

        $command = 'nohup ' . self::quote($binary) . ' ' . self::quote(self::workerScript())
            . ' --stop-when-empty --max-jobs=' . self::MAX_JOBS
            . ' >> ' . self::quote(STORAGE_PATH . '/logs/queue-auto.log') . ' 2>&1 < /dev/null &';

        // 三路描述符都指向 /dev/null：避免子进程继承管道导致 proc_close 阻塞
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            @unlink($stamp);

            Logger::warning('队列自动运行启动失败：无法创建进程');

            return;
        }

        $exitCode = @proc_close($process);

        if ($exitCode !== 0) {
            // 启动命令本身失败（例如 nohup 不存在）：撤销节流标记，下次请求可立即重试
            @unlink($stamp);

            Logger::warning('队列自动运行启动失败：退出码 ' . (int) $exitCode);
        }
    }

    /**
     * 自动触发是否可用（用于后台提示与问题排查）
     */
    public static function available(): bool
    {
        return self::canSpawn() && self::cliBinary() !== '';
    }

    private static function workerScript(): string
    {
        return BASE_PATH . '/bin/queue-worker.php';
    }

    private static function stampFile(): string
    {
        return STORAGE_PATH . '/cache/queue-auto.stamp';
    }

    private static function cliCacheFile(): string
    {
        return STORAGE_PATH . '/cache/queue-auto-php.txt';
    }

    /**
     * 是否具备拉起外部进程的条件
     */
    private static function canSpawn(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        if (!function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /**
     * 定位可用的 PHP CLI 可执行文件（结果缓存，避免每次请求都探测）
     */
    private static function cliBinary(): string
    {
        $cache = self::cliCacheFile();

        if (is_file($cache)) {
            $saved = trim((string) @file_get_contents($cache));

            if ($saved !== '' && self::usable($saved)) {
                return $saved;
            }
        }

        foreach (self::cliCandidates() as $candidate) {
            if (!self::usable($candidate)) {
                continue;
            }

            @file_put_contents($cache, $candidate, LOCK_EX);

            return $candidate;
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private static function cliCandidates(): array
    {
        $candidates = [
            PHP_BINDIR . '/php',
            dirname(PHP_BINARY) . '/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];

        return array_values(array_unique(array_filter($candidates, static fn (string $path): bool => $path !== '')));
    }

    /**
     * 候选 CLI 是否可用：可执行且版本满足 8.1+
     */
    private static function usable(string $binary): bool
    {
        if (!is_file($binary) || !is_executable($binary)) {
            return false;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = @proc_open(self::quote($binary) . ' -r ' . self::quote('echo PHP_VERSION_ID;'), $descriptors, $pipes);

        if (!is_resource($process)) {
            return false;
        }

        $output = trim((string) stream_get_contents($pipes[1]));

        @fclose($pipes[1]);
        @proc_close($process);

        return is_numeric($output) && (int) $output >= 80100;
    }

    /**
     * 命令行参数转义（宿主可能禁用 escapeshellarg，此处做最小兜底）
     */
    private static function quote(string $value): string
    {
        if (function_exists('escapeshellarg')) {
            return escapeshellarg($value);
        }

        return '"' . str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $value) . '"';
    }
}