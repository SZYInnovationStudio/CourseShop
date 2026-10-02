<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * 视频存储与播放地址签名
 *
 * P0 只实现本地磁盘：chapters.video_path 保存的是「相对存储根目录」的存储键，
 * 例如 `course-3/chapter-12-9f3c1a2b4d5e.mp4`，绝不对外暴露真实路径。
 * 对象存储（oss/cos/s3）在 P2 接入，届时 redirect 到云端签名地址即可。
 */
final class VideoStorage
{
    /**
     * 本地视频根目录（仅 local 磁盘使用）
     */
    public static function localRoot(): string
    {
        $root = trim((string) Config::get('video.local_root', ''));

        return $root !== '' ? rtrim($root, '/\\') : STORAGE_PATH . '/private/videos';
    }

    /**
     * 将存储键解析为服务器上的绝对路径
     *
     * 拒绝绝对路径与目录穿越；文件必须真实存在于根目录之内，否则返回 null。
     */
    public static function resolve(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $key = str_replace('\\', '/', $path);

        // 拒绝绝对路径、盘符与任何形式的目录穿越
        if (str_starts_with($key, '/') || preg_match('#^[a-zA-Z]:#', $key) === 1
            || preg_match('#(^|/)\.\.(/|$)#', $key) === 1) {
            return null;
        }

        $root = realpath(self::localRoot());
        $real = realpath(self::localRoot() . '/' . $key);

        if ($root === false || $real === false || !is_file($real)) {
            return null;
        }

        // 二次确认：解析后的真实路径必须位于根目录之内
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    public static function exists(?string $path): bool
    {
        return self::resolve($path) !== null;
    }

    public static function size(?string $path): int
    {
        $real = self::resolve($path);

        return $real === null ? 0 : (int) filesize($real);
    }

    // ============================================================
    // 上传与删除（后台章节管理使用）
    // ============================================================

    /** 允许的视频扩展名白名单 */
    private const ALLOWED_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov'];

    /** 允许的视频 MIME 白名单 */
    private const ALLOWED_MIMES = [
        'video/mp4',
        'video/x-m4v',
        'video/webm',
        'video/quicktime',
        'video/x-matroska',
    ];

    /**
     * 单个视频文件大小上限（字节）
     */
    public static function maxUploadBytes(): int
    {
        return max(1, (int) Config::get('upload.max_video_mb', 2048)) * 1024 * 1024;
    }

    /**
     * 保存上传的章节视频
     *
     * 文件名带随机后缀（chapter-{id}-{rand}.{ext}），替换视频时写入新文件而非
     * 原位覆盖：旧文件由调用方在数据库写入成功后再删除，避免元数据保存失败时
     * 旧视频已不可恢复（CS-20）。存储键始终可控，绝不对外暴露真实路径。
     *
     * @param  array<string, mixed>|null $file $_FILES 中的单个文件项
     * @return array{path: string, disk: string, size: int}|null 未选择文件时返回 null
     *
     * @throws RuntimeException 校验或落盘失败
     */
    public static function storeUpload(?array $file, int $courseId, int $chapterId): ?array
    {
        if ($file === null || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $error = (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('上传的视频文件为空。');
        }

        if ($size > self::maxUploadBytes()) {
            throw new RuntimeException(
                '视频大小超过限制（最大 ' . (int) Config::get('upload.max_video_mb', 2048) . ' MB）。'
            );
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('仅支持以下视频格式：' . implode('、', self::ALLOWED_EXTENSIONS) . '。');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('视频上传失败，请重试。');
        }

        $mime = self::detectMime($tmp);
        // finfo 在部分环境会把合法视频识别为 application/octet-stream，
        // 此时退化为按容器魔数（magic bytes）判定，避免仅凭 MIME 放行非视频内容
        if ($mime !== '' && !in_array($mime, self::ALLOWED_MIMES, true) && !self::hasVideoSignature($tmp)) {
            throw new RuntimeException('视频内容与扩展名不符，已拒绝保存。');
        }

        $directory = self::localRoot() . '/course-' . $courseId;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建视频存储目录，请检查写入权限。');
        }

        $storedName = 'chapter-' . $chapterId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $target     = $directory . '/' . $storedName;

        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('视频保存失败，请检查目录写入权限。');
        }

        return [
            'path' => 'course-' . $courseId . '/' . $storedName,
            'disk' => 'local',
            'size' => (int) filesize($target),
        ];
    }

    /**
     * 删除存储键对应的视频文件（文件不存在时静默忽略）
     */
    public static function delete(?string $path): void
    {
        $real = self::resolve($path);

        if ($real !== null) {
            @unlink($real);
        }
    }

    private static function detectMime(string $file): string
    {
        if (!function_exists('finfo_open')) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        $mime = (string) finfo_file($finfo, $file);
        finfo_close($finfo);

        return $mime;
    }

    /**
     * 校验文件头是否为已知视频容器签名（ISO BMFF / EBML）
     *
     * 用于 finfo 将合法视频误判为 application/octet-stream 时的兜底判定。
     */
    private static function hasVideoSignature(string $file): bool
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = (string) fread($handle, 16);
        fclose($handle);

        if (strlen($header) < 8) {
            return false;
        }

        // ISO BMFF（mp4 / m4v / mov）：第 4-7 字节为 ftyp
        if (substr($header, 4, 4) === 'ftyp') {
            return true;
        }

        // Matroska / WebM：EBML 魔数 1A 45 DF A3
        return str_starts_with($header, "\x1A\x45\xDF\xA3");
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '视频大小超过服务器限制，请调整 php.ini 的 upload_max_filesize / post_max_size。',
            UPLOAD_ERR_PARTIAL                        => '视频仅上传了一部分，请重试。',
            UPLOAD_ERR_NO_TMP_DIR                     => '服务器缺少临时目录。',
            UPLOAD_ERR_CANT_WRITE                     => '服务器无法写入临时文件。',
            UPLOAD_ERR_EXTENSION                      => '上传被 PHP 扩展中断。',
            default                                   => '视频上传失败。',
        };
    }

    /**
     * 播放地址签名有效期（秒）
     */
    public static function urlTtl(): int
    {
        return max(60, (int) Setting::int('video_signed_ttl', (int) Config::get('video.url_ttl', 7200)));
    }

    /**
     * 生成带签名的播放地址
     *
     * 签名与「用户 + 课程 + 章节 + 过期时间」绑定，把地址复制给别人也无法播放。
     */
    public static function signedUrl(int $courseId, int $chapterId, int $userId): string
    {
        $expires   = time() + self::urlTtl();
        $signature = self::signature($courseId, $chapterId, $userId, $expires);

        return url('/course/' . $courseId . '/learn/' . $chapterId . '/video')
            . '?e=' . $expires . '&s=' . $signature;
    }

    public static function signature(int $courseId, int $chapterId, int $userId, int $expires): string
    {
        return hash_hmac(
            'sha256',
            $userId . '|' . $courseId . '|' . $chapterId . '|' . $expires,
            self::signingKey()
        );
    }

    /**
     * 校验签名与有效期
     *
     * 密钥缺失时视为校验失败（拒绝播放），不抛出异常。
     */
    public static function verifySignature(int $courseId, int $chapterId, int $userId, int $expires, string $signature): bool
    {
        if ($expires < time() || $signature === '') {
            return false;
        }

        try {
            $expected = self::signature($courseId, $chapterId, $userId, $expires);
        } catch (RuntimeException $e) {
            Logger::error('视频签名校验失败：' . $e->getMessage());

            return false;
        }

        return hash_equals($expected, $signature);
    }

    /**
     * 签名密钥：取 app.key
     *
     * 未配置时直接抛异常，拒绝生成播放地址，避免使用可预测的兜底密钥。
     *
     * @throws RuntimeException
     */
    private static function signingKey(): string
    {
        $key = (string) Config::get('app.key', '');

        if ($key === '') {
            throw new RuntimeException('视频签名密钥未配置（app.key），已拒绝生成播放地址。');
        }

        return $key;
    }

    // ============================================================
    // HLS（P2 转码产物）
    // ============================================================

    /**
     * HLS 产物文件名白名单：index.m3u8 与 seg-000.ts 等
     *
     * 文件名不参与签名，仅凭此白名单 + basename 防止目录穿越。
     */
    private const HLS_FILE_PATTERN = '#^[A-Za-z0-9_-]+\.(?:m3u8|ts)$#';

    /**
     * HLS 存储目录（绝对路径）
     */
    public static function hlsDirectory(int $chapterId): string
    {
        return self::localRoot() . '/hls/chapter-' . $chapterId;
    }

    /**
     * 把 HLS 文件名转换为存储键，非法文件名返回 null
     */
    public static function hlsKey(int $chapterId, string $file): ?string
    {
        // 只接受纯文件名：显式拒绝路径分隔符与目录穿越，再叠加扩展名白名单
        if ($chapterId <= 0 || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..')) {
            return null;
        }

        if (preg_match(self::HLS_FILE_PATTERN, $file) !== 1) {
            return null;
        }

        return 'hls/chapter-' . $chapterId . '/' . $file;
    }

    /**
     * 生成 HLS 播放地址（含签名，用于 m3u8 入口）
     */
    public static function signedHlsUrl(int $courseId, int $chapterId, int $userId, string $file): string
    {
        $expires = time() + self::urlTtl();

        return self::hlsUrl($courseId, $chapterId, $file, $expires, self::signature($courseId, $chapterId, $userId, $expires));
    }

    /**
     * 用既定的过期时间与签名拼装 HLS 地址
     *
     * 转码输出 m3u8 时会用它把分片行重写为带签名的绝对地址，
     * 从而复用同一个签名，避免逐个分片单独签名。
     */
    public static function hlsUrl(int $courseId, int $chapterId, string $file, int $expires, string $signature): string
    {
        return url('/course/' . $courseId . '/learn/' . $chapterId . '/hls/' . rawurlencode($file))
            . '?e=' . $expires . '&s=' . $signature;
    }

    /**
     * 删除某章节的全部 HLS 产物（播放列表与分片）
     */
    public static function deleteHls(int $chapterId): void
    {
        if ($chapterId <= 0) {
            return;
        }

        $root = realpath(self::localRoot());
        $real = realpath(self::hlsDirectory($chapterId));

        // 目录必须真实存在且位于存储根目录之内，杜绝误删
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return;
        }

        foreach (glob($real . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($real);
    }
}

