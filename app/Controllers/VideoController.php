<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Auth;
use App\Support\PlaybackAccess;
use App\Support\Request;
use App\Support\VideoStorage;

/**
 * 视频流输出
 *
 * 安全策略（对应设计文档「视频鉴权、防盗链、Range 请求、签名 URL」）：
 * 1. 必须登录（路由中间件 auth）
 * 2. 播放地址带 HMAC 签名，且签名与用户 / 课程 / 章节 / 过期时间绑定
 * 3. 再次校验已购或试看权限，文件路径经 realpath 校验，杜绝目录穿越
 * 4. 支持 Range 请求（206 / 416），可拖动进度条与断点续传
 */
final class VideoController extends Controller
{
    /** 每次读取的字节数 */
    private const CHUNK_SIZE = 8192;

    public function stream(string $courseId, string $chapterId): void
    {
        $courseId  = (int) $courseId;
        $chapterId = (int) $chapterId;
        $userId    = (int) Auth::id();

        if (!VideoStorage::verifySignature(
            $courseId,
            $chapterId,
            $userId,
            Request::int('e'),
            Request::string('s')
        )) {
            abort(403, '播放地址无效或已过期，请刷新页面后重试。');
        }

        $context = PlaybackAccess::context($courseId, $chapterId, $userId);
        $file    = PlaybackAccess::videoFile($context['chapter']);

        $this->output($file);
    }

    /**
     * HLS 播放列表与分片输出
     *
     * 与 mp4 共用「用户 / 课程 / 章节 / 过期时间」签名，
     * 输出 m3u8 时把分片行动态重写为带签名的绝对地址（复用同一签名）。
     */
    public function hls(string $courseId, string $chapterId, string $file): never
    {
        $courseId  = (int) $courseId;
        $chapterId = (int) $chapterId;
        $userId    = (int) Auth::id();
        $expires   = Request::int('e');
        $signature = Request::string('s');

        if (!VideoStorage::verifySignature($courseId, $chapterId, $userId, $expires, $signature)) {
            abort(403, '播放地址无效或已过期，请刷新页面后重试。');
        }

        // 再次校验已购或试看权限
        PlaybackAccess::context($courseId, $chapterId, $userId);

        $key = VideoStorage::hlsKey($chapterId, $file);

        if ($key === null) {
            abort(404, '播放资源不存在。');
        }

        $path = VideoStorage::resolve($key);

        if ($path === null) {
            abort(404, '播放资源不存在或尚未转码完成。');
        }

        if (str_ends_with(strtolower($path), '.m3u8')) {
            $this->outputPlaylist($path, $courseId, $chapterId, $expires, $signature);
        }

        $this->output($path, 'video/mp2t');
    }

    /**
     * 按 HTTP Range 语义输出文件内容
     */
    private function output(string $file, string $mime = 'video/mp4'): never
    {
        $size = (int) filesize($file);

        // 视频流可能持续较长时间，先释放会话文件锁，避免阻塞同一用户的进度上报请求
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // 清空已有输出缓冲，避免混入 HTML 导致视频损坏
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
        // 视频为私有内容，禁止中间代理缓存
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        if ($size === 0) {
            header('Content-Length: 0');
            http_response_code(200);
            exit;
        }

        [$start, $end, $partial] = $this->resolveRange($size);

        // 范围非法：按规范返回 416 并告知当前文件总长度
        if ($start === null || $end === null) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        if ($partial) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            http_response_code(200);
        }

        $length = $end - $start + 1;
        header('Content-Length: ' . $length);

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            abort(500, '视频文件读取失败。');
        }

        fseek($handle, $start);

        $remaining = $length;

        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, (int) min(self::CHUNK_SIZE, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $remaining -= strlen($chunk);

            flush();
        }

        fclose($handle);
        exit;
    }

    /**
     * 输出 HLS 播放列表（m3u8）
     *
     * 把相对分片路径重写为带签名的绝对地址后再下发，
     * 播放器无需接触真实存储结构，也无法绕过鉴权直接取分片。
     */
    private function outputPlaylist(string $file, int $courseId, int $chapterId, int $expires, string $signature): never
    {
        $content = @file_get_contents($file);

        if ($content === false) {
            abort(404, '播放列表读取失败。');
        }

        // 会话可能仍持有文件锁，先释放，避免分片请求被串行阻塞
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // 空行与以 # 开头的标签行原样保留
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $out[] = $line;
                continue;
            }

            // 分片行：重写为带签名的绝对地址
            $out[] = VideoStorage::hlsUrl($courseId, $chapterId, basename($trimmed), $expires, $signature);
        }

        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        echo implode("\n", $out);
        exit;
    }

    /**
     * 解析 Range 请求头，返回 [起始字节, 结束字节, 是否为分段响应]
     *
     * 无 Range 头时返回 [0, size-1, false]，即完整响应；
     * 带合法 Range 头时返回 [start, end, true]，由调用方回 206；
     * 范围非法或越界时返回 [null, null, false]，由调用方回 416。
     *
     * @return array{0: int|null, 1: int|null, 2: bool}
     */
    private function resolveRange(int $size): array
    {
        $full  = [0, $size - 1, false];
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

        if ($range === '' || preg_match('/^bytes=(\d*)-(\d*)$/i', trim($range), $matched) !== 1) {
            return $full;
        }

        $rawStart = $matched[1];
        $rawEnd   = $matched[2];

        // bytes=-500：最后 500 字节
        if ($rawStart === '' && $rawEnd !== '') {
            $length = (int) $rawEnd;
            if ($length <= 0) {
                return [null, null, false];
            }

            return [max(0, $size - $length), $size - 1, true];
        }

        if ($rawStart === '') {
            return $full;
        }

        $start = (int) $rawStart;
        $end   = $rawEnd !== '' ? min((int) $rawEnd, $size - 1) : $size - 1;

        if ($start > $end || $start >= $size) {
            return [null, null, false];
        }

        return [$start, $end, true];
    }
}
