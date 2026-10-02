<?php

declare(strict_types=1);

namespace App\Support\Video;

use App\Support\Config;
use App\Support\Setting;
use App\Support\VideoStorage;
use RuntimeException;

/**
 * ffmpeg 转码器（可选能力）
 *
 * ffmpeg 属于可选依赖：未安装时 available() 返回 false，站点自动降级为 mp4 播放，
 * 不会产生任何转码任务，也不影响正常购买与学习流程。
 */
final class Transcoder
{
    private static ?string $binary = null;

    private static bool $probed = false;

    /**
     * 心跳回调的最小间隔（秒）
     *
     * 转码过程中用于周期性地向队列续租，避免长任务因预留超时被重复领取。
     */
    private const HEARTBEAT_INTERVAL = 30;

    /**
     * 定位可用的 ffmpeg 可执行文件（找不到返回 null）
     *
     * 优先级：后台设置 ffmpeg_path → .env VIDEO_FFMPEG_PATH → PATH 中的 ffmpeg
     */
    public static function binary(): ?string
    {
        if (self::$probed) {
            return self::$binary;
        }

        self::$probed = true;

        $candidates = [
            trim(Setting::string('ffmpeg_path', '')),
            trim((string) Config::get('video.ffmpeg_path', '')),
            'ffmpeg',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && self::probe($candidate)) {
                self::$binary = $candidate;
                break;
            }
        }

        return self::$binary;
    }

    public static function available(): bool
    {
        return self::binary() !== null;
    }

    /**
     * 是否开启 HLS 转码：需同时满足「后台开关开启」且「ffmpeg 可用」
     */
    public static function enabled(): bool
    {
        return Setting::bool('video_hls_enabled', true) && self::available();
    }

    /**
     * 单个 ts 分片的时长（秒）
     */
    public static function segmentSeconds(): int
    {
        $value = Setting::int('video_hls_segment_seconds', (int) Config::get('video.hls_segment_seconds', 10));

        return max(2, min(60, $value > 0 ? $value : 10));
    }

    /**
     * HLS 播放列表的存储键（文件名内嵌转码任务 ID）
     */
    public static function outputKey(int $chapterId, int $transcodeId): string
    {
        return 'hls/chapter-' . $chapterId . '/' . self::playlistName($transcodeId);
    }

    /**
     * HLS 播放列表文件名：同一章节多次转码时互不覆盖
     */
    private static function playlistName(int $transcodeId): string
    {
        return 'index-' . $transcodeId . '.m3u8';
    }

    /**
     * ts 分片文件名前缀（同样内嵌转码任务 ID）
     */
    private static function segmentPrefix(int $transcodeId): string
    {
        return 'seg-' . $transcodeId . '-';
    }

    /**
     * HLS 输出目录（绝对路径）
     */
    public static function outputDirectory(int $chapterId): string
    {
        return VideoStorage::localRoot() . '/hls/chapter-' . $chapterId;
    }

    /**
     * 转码为 HLS（VOD 播放列表 + ts 分片）
     *
     * @param int           $transcodeId  转码任务 ID，用于隔离同章节多次转码的产物
     * @param int           $totalSeconds 源视频总时长，用于估算进度（0 表示未知）
     * @param callable|null $onProgress   进度回调，参数为 0-100 的整数
     * @param callable|null $onHeartbeat  心跳回调，转码过程中周期性触发（长任务续租用）
     * @return string HLS 播放列表存储键
     *
     * @throws RuntimeException 转码失败
     */
    public static function transcodeToHls(
        int $chapterId,
        int $transcodeId,
        string $sourceFile,
        int $totalSeconds = 0,
        ?callable $onProgress = null,
        ?callable $onHeartbeat = null
    ): string {
        $binary = self::binary();

        if ($binary === null) {
            throw new RuntimeException('未检测到 ffmpeg，无法执行转码。');
        }

        if (!is_file($sourceFile)) {
            throw new RuntimeException('源视频文件不存在。');
        }

        $directory = self::outputDirectory($chapterId);
        self::prepareDirectory($directory, $transcodeId);

        $playlist       = $directory . '/' . self::playlistName($transcodeId);
        $segmentPattern = $directory . '/' . self::segmentPrefix($transcodeId) . '%03d.ts';
        $logFile        = $directory . '/ffmpeg-' . $transcodeId . '.log';

        // 使用数组形式传参，由 PHP 负责转义，避免命令注入
        $command = [
            $binary,
            '-y',
            '-i', $sourceFile,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-hls_time', (string) self::segmentSeconds(),
            '-hls_playlist_type', 'vod',
            '-hls_segment_filename', $segmentPattern,
            '-progress', 'pipe:1',
            '-nostats',
            '-loglevel', 'error',
            $playlist,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // 错误日志写入文件，避免管道写满造成死锁
            2 => ['file', $logFile, 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('无法启动 ffmpeg 进程，请检查 ffmpeg 路径与执行权限。');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $buffer   = '';
        $exitCode = null;

        // 心跳计时：即使源视频时长未知（无进度输出），也能周期性续租
        $lastHeartbeat = time();

        while (true) {
            $chunk = fread($pipes[1], 8192);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;

                while (($position = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 1);
                    self::handleProgressLine(trim($line), $totalSeconds, $onProgress);
                }
            }

            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if ($onHeartbeat !== null && time() - $lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
                $lastHeartbeat = time();
                $onHeartbeat();
            }

            // 避免空转占满 CPU
            usleep(50000);
        }

        $rest = stream_get_contents($pipes[1]);
        if (is_string($rest) && $rest !== '') {
            $buffer .= $rest;
        }

        foreach (explode("\n", $buffer) as $line) {
            self::handleProgressLine(trim($line), $totalSeconds, $onProgress);
        }

        fclose($pipes[1]);

        $closeCode = proc_close($process);

        // proc_get_status 可能因 PHP 版本差异返回 -1，此时以 proc_close 的结果为准
        if ($exitCode === null || $exitCode < 0) {
            $exitCode = $closeCode;
        }

        if ($exitCode !== 0 || !is_file($playlist) || (int) filesize($playlist) === 0) {
            $log = is_file($logFile) ? trim((string) file_get_contents($logFile)) : '';
            @unlink($logFile);

            throw new RuntimeException(
                '视频转码失败：' . ($log !== '' ? mb_substr($log, 0, 500) : 'ffmpeg 退出码 ' . $exitCode)
            );
        }

        @unlink($logFile);

        if ($onProgress !== null) {
            $onProgress(100);
        }

        return self::outputKey($chapterId, $transcodeId);
    }

    /**
     * 解析 ffmpeg -progress 输出的一行
     *
     * 注意：ffmpeg 的 out_time_ms 历史遗留单位实际是「微秒」，与 out_time_us 相同。
     */
    private static function handleProgressLine(string $line, int $totalSeconds, ?callable $onProgress): void
    {
        if ($line === '') {
            return;
        }

        if (str_starts_with($line, 'out_time_us=') || str_starts_with($line, 'out_time_ms=')) {
            if ($onProgress === null || $totalSeconds <= 0) {
                return;
            }

            $microseconds = (int) substr($line, strpos($line, '=') + 1);

            if ($microseconds <= 0) {
                return;
            }

            $percent = (int) floor($microseconds / 1000000 / $totalSeconds * 100);
            $onProgress(max(1, min(99, $percent)));

            return;
        }

        if ($line === 'progress=end' && $onProgress !== null) {
            $onProgress(100);
        }
    }

    /**
     * 准备输出目录，并仅清理「本任务」的旧产物
     *
     * 播放列表与分片文件名均内嵌转码任务 ID，因此同一章节的多次转码可安全共存：
     * 这里只删除本任务的残留文件，不会误删其它任务（含正在进行的转码）的产物。
     */
    private static function prepareDirectory(string $directory, int $transcodeId): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建转码输出目录，请检查写入权限。');
        }

        // 清理同任务的旧分片
        $prefix = self::segmentPrefix($transcodeId);

        foreach (glob($directory . '/' . $prefix . '*.ts') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // 清理同任务的旧播放列表
        $playlist = $directory . '/' . self::playlistName($transcodeId);

        if (is_file($playlist)) {
            @unlink($playlist);
        }
    }

    /**
     * 探测指定可执行文件是否为可用的 ffmpeg
     */
    private static function probe(string $binary): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([$binary, '-version'], $descriptors, $pipes);

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return $exitCode === 0 && str_contains($output, 'ffmpeg version');
    }
}
