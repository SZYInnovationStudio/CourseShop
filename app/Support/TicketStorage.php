<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * 工单附件存储
 *
 * 附件保存在非公开目录（默认 storage/private/tickets），
 * ticket_attachments.file_path 保存的是「相对存储根目录」的存储键，
 * 例如 `ticket-12/ab12cd34.pdf`，绝不对外暴露真实路径，
 * 下载必须经过鉴权路由。
 */
final class TicketStorage
{
    /**
     * 本地附件根目录
     */
    public static function localRoot(): string
    {
        $root = trim((string) Config::get('ticket.local_root', ''));

        return $root !== '' ? rtrim($root, '/\\') : STORAGE_PATH . '/private/tickets';
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

        if (str_starts_with($key, '/') || preg_match('#^[a-zA-Z]:#', $key) === 1
            || preg_match('#(^|/)\.\.(/|$)#', $key) === 1) {
            return null;
        }

        $root = realpath(self::localRoot());
        $real = realpath(self::localRoot() . '/' . $key);

        if ($root === false || $real === false || !is_file($real)) {
            return null;
        }

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
    // 上传限制
    // ============================================================

    /**
     * 允许的附件扩展名（后台可配，默认 ticket_attachment_types）
     *
     * @return array<int, string>
     */
    public static function allowedExtensions(): array
    {
        $raw = (string) Setting::string('ticket_attachment_types', 'jpg,jpeg,png,gif,pdf,zip,rar,7z,txt');

        $list = array_filter(array_map(static function (string $item): string {
            return strtolower(ltrim(trim($item), '.'));
        }, explode(',', $raw)), static fn (string $item): bool => $item !== '');

        return $list === [] ? ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip', 'rar', '7z', 'txt'] : array_values($list);
    }

    /**
     * 单个附件大小上限（字节）
     */
    public static function maxUploadBytes(): int
    {
        $mb = (int) Setting::int('ticket_attachment_max_mb', (int) Config::get('upload.max_attachment_mb', 10));

        return max(1, $mb) * 1024 * 1024;
    }

    public static function maxUploadMb(): int
    {
        return (int) (self::maxUploadBytes() / 1024 / 1024);
    }

    /**
     * 单次提交最多附件数量
     */
    public static function maxFiles(): int
    {
        return 5;
    }

    /**
     * 把 $_FILES 中形如 name="attachments[]" 的多文件字段规整为单个文件数组列表
     *
     * @param  array<string, mixed>|null $field
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeFiles(?array $field): array
    {
        if ($field === null || !isset($field['name'])) {
            return [];
        }

        // 单文件形式
        if (!is_array($field['name'])) {
            return (int) ($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$field];
        }

        $files = [];
        $count = count($field['name']);

        for ($i = 0; $i < $count; $i++) {
            $error = (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $files[] = [
                'name'     => (string) ($field['name'][$i] ?? ''),
                'type'     => (string) ($field['type'][$i] ?? ''),
                'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''),
                'error'    => $error,
                'size'     => (int) ($field['size'][$i] ?? 0),
            ];
        }

        return $files;
    }

    // ============================================================
    // 保存与删除
    // ============================================================

    /**
     * 保存单个上传的附件
     *
     * @param  array<string, mixed> $file $_FILES 中的单个文件项
     * @return array{name: string, path: string, size: int, mime: string}
     *
     * @throws RuntimeException 校验或落盘失败
     */
    public static function store(array $file, int $ticketId): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('上传的附件为空。');
        }

        if ($size > self::maxUploadBytes()) {
            throw new RuntimeException('附件大小超过限制（最大 ' . self::maxUploadMb() . ' MB）。');
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension    = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed      = self::allowedExtensions();

        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new RuntimeException('仅支持以下附件格式：' . implode('、', $allowed) . '。');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('附件上传失败，请重试。');
        }

        $directory = self::localRoot() . '/ticket-' . $ticketId;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建附件存储目录，请检查写入权限。');
        }

        $storedName = bin2hex(random_bytes(8)) . '.' . $extension;
        $target     = $directory . '/' . $storedName;

        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('附件保存失败，请检查目录写入权限。');
        }

        return [
            'name' => mb_substr($originalName, 0, 255),
            'path' => 'ticket-' . $ticketId . '/' . $storedName,
            'size' => (int) filesize($target),
            'mime' => self::detectMime($target),
        ];
    }

    /**
     * 删除存储键对应的附件文件（文件不存在时静默忽略）
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

        return mb_substr($mime, 0, 100);
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '附件大小超过服务器限制，请调整 php.ini 的 upload_max_filesize / post_max_size。',
            UPLOAD_ERR_PARTIAL                        => '附件仅上传了一部分，请重试。',
            UPLOAD_ERR_NO_TMP_DIR                     => '服务器缺少临时目录。',
            UPLOAD_ERR_CANT_WRITE                     => '服务器无法写入临时文件。',
            UPLOAD_ERR_EXTENSION                      => '上传被 PHP 扩展中断。',
            default                                   => '附件上传失败。',
        };
    }
}
