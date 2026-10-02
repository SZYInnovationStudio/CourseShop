<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * 公开图片上传存储
 *
 * 统一处理站点 Logo、课程 / 套餐封面等公开图片：校验类型与大小、
 * 落盘到 public/uploads/{subdir}，返回可直接用于 <img src> 的根相对路径。
 *
 * 仅允许 jpg/jpeg/png/gif/webp，不接受 SVG，避免存储型 XSS。
 */
final class ImageStorage
{
    /** 单张图片大小上限（字节） */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** 允许的扩展名 */
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** 允许的存储子目录白名单 */
    private const SUBDIRS = ['logo', 'covers'];

    /**
     * 保存上传图片，返回根相对路径（如 /uploads/covers/xxx.png）
     *
     * @param  array<string, mixed> $file $_FILES 中的单个文件项
     * @throws RuntimeException 校验或落盘失败
     */
    public static function store(array $file, string $subdir): string
    {
        $subdir = self::normalizeSubdir($subdir);

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('上传的图片文件为空。');
        }

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('图片大小不能超过 ' . (self::MAX_BYTES / 1024 / 1024) . ' MB。');
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('图片仅支持 jpg、jpeg、png、gif、webp 格式。');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('图片上传失败，请重试。');
        }

        // 二次确认确为图片，防止伪造扩展名
        if (@getimagesize($tmp) === false) {
            throw new RuntimeException('上传的文件不是有效的图片。');
        }

        $relativeDir = 'uploads/' . $subdir;
        $directory   = PUBLIC_PATH . '/' . $relativeDir;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建图片存储目录，请检查写入权限。');
        }

        $storedName = bin2hex(random_bytes(8)) . '.' . $extension;
        $target     = $directory . '/' . $storedName;

        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('图片保存失败，请检查目录写入权限。');
        }

        return '/' . $relativeDir . '/' . $storedName;
    }

    /**
     * 处理某个图片字段的文件上传
     *
     * 未选择文件时返回 null（由调用方沿用文本框地址）；选择文件时保存新图，
     * 并在旧值为本系统管理的图片时将其删除。
     *
     * @param  string      $fileKey  $_FILES 中的字段名（如 cover_file）
     * @param  string      $subdir   存储子目录（logo / covers）
     * @param  string|null $oldValue 旧的图片值（根相对路径或外链）
     * @return string|null 新图片的根相对路径；未上传时为 null
     * @throws RuntimeException 校验或落盘失败
     */
    public static function saveUploaded(string $fileKey, string $subdir, ?string $oldValue = null): ?string
    {
        $file = Request::file($fileKey);

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $path = self::store($file, $subdir);

        if ($oldValue !== null && $oldValue !== '') {
            self::delete($oldValue);
        }

        return $path;
    }

    /**
     * 删除由本系统管理的图片；非受管路径（外链、静态资源等）静默忽略
     */
    public static function delete(?string $path): void
    {
        $path = ltrim((string) $path, '/');

        if (!str_starts_with($path, 'uploads/')) {
            return;
        }

        // 仅允许删除白名单子目录下的文件
        $segments = explode('/', $path);
        if (count($segments) < 3 || !in_array($segments[1], self::SUBDIRS, true)) {
            return;
        }

        // realpath 规范化后确认目标位于 uploads 目录内，防止符号链接 / .. 穿越
        $base = realpath(PUBLIC_PATH . '/uploads');
        $real = realpath(PUBLIC_PATH . '/' . $path);

        if ($base === false || $real === false) {
            return;
        }

        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_file($real)) {
            @unlink($real);
        }
    }

    /**
     * 校验并归一化存储子目录
     */
    private static function normalizeSubdir(string $subdir): string
    {
        $subdir = strtolower(trim($subdir, '/'));

        if (!in_array($subdir, self::SUBDIRS, true)) {
            throw new RuntimeException('不支持的图片存储目录。');
        }

        return $subdir;
    }

    /**
     * 上传错误码 -> 中文提示
     */
    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '图片大小超过服务器限制。',
            UPLOAD_ERR_PARTIAL                        => '图片上传不完整，请重试。',
            UPLOAD_ERR_NO_FILE                        => '请选择要上传的图片文件。',
            UPLOAD_ERR_NO_TMP_DIR                     => '服务器缺少临时目录，请联系管理员。',
            UPLOAD_ERR_CANT_WRITE                     => '服务器写入文件失败，请联系管理员。',
            default                                   => '图片上传失败，请重试。',
        };
    }
}
