<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TicketAttachment;
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
     * 无论后台如何配置都禁止的扩展名（可被当作脚本/页面执行）
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps',
        'shtml', 'html', 'htm', 'xhtml', 'svg', 'js', 'mjs', 'cgi', 'pl', 'py',
        'asp', 'aspx', 'jsp', 'jspx', 'sh', 'bat', 'cmd', 'exe', 'com', 'htaccess',
    ];

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

        // 始终剔除危险扩展名，避免后台误配置导致可执行文件被上传
        $list = array_values(array_diff($list, self::FORBIDDEN_EXTENSIONS));

        return $list === [] ? ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip', 'rar', '7z', 'txt'] : $list;
    }

    /**
     * 单个附件大小上限（字节）
     *
     * 取「后台配置值」与「PHP 实际允许值」的较小者：
     * 若界面宣称的上限高于 php.ini 的 upload_max_filesize / post_max_size，
     * 超限文件会被 PHP 在到达业务代码前直接丢弃（UPLOAD_ERR_INI_SIZE），
     * 表现为「工单提交成功但附件凭空消失」。
     */
    public static function maxUploadBytes(): int
    {
        $mb = (int) Setting::int('ticket_attachment_max_mb', (int) Config::get('upload.max_attachment_mb', 10));
        $bytes = max(1, $mb) * 1024 * 1024;

        foreach ([ini_get('upload_max_filesize'), ini_get('post_max_size')] as $ini) {
            $limit = self::parseIniBytes((string) $ini);
            if ($limit > 0) {
                $bytes = min($bytes, $limit);
            }
        }

        return max(1, $bytes);
    }

    public static function maxUploadMb(): int
    {
        return (int) (self::maxUploadBytes() / 1024 / 1024);
    }

    /**
     * 解析 php.ini 的字节缩写（2M / 2048M / 1G / 512K / 纯数字）
     *
     * @return int 字节数；-1 表示不限（如 -1 或空值）
     */
    private static function parseIniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
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
     * 保存一次提交中的全部附件（前台提交/回复、后台回复共用）
     *
     * @param  array<string, mixed>|null $field $_FILES 中的多文件字段（name="attachments[]"）
     * @return array<int, string> 失败提示列表（空数组表示全部成功）
     */
    public static function saveUploaded(int $ticketId, ?int $replyId, int $userId, ?array $field): array
    {
        $files = self::normalizeFiles($field);
        if ($files === []) {
            return [];
        }

        $errors = [];
        $count  = 0;

        foreach ($files as $file) {
            if ($count >= self::maxFiles()) {
                $errors[] = t('附件数量超出上限（最多 %d 个）。', [self::maxFiles()]);
                break;
            }

            try {
                $stored = self::store($file, $ticketId);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            TicketAttachment::create($ticketId, $replyId, $userId, $stored['name'], $stored['path'], $stored['size'], $stored['mime']);
            $count++;
        }

        return $errors;
    }

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
            throw new RuntimeException(t('上传的附件为空。'));
        }

        if ($size > self::maxUploadBytes()) {
            throw new RuntimeException(t('附件大小超过限制（最大 %d MB）。', [self::maxUploadMb()]));
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension    = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed      = self::allowedExtensions();

        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new RuntimeException(t('仅支持以下附件格式：%s。', [implode(t('、'), $allowed)]));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException(t('附件上传失败，请重试。'));
        }

        $directory = self::localRoot() . '/ticket-' . $ticketId;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException(t('无法创建附件存储目录，请检查写入权限。'));
        }

        $storedName = bin2hex(random_bytes(8)) . '.' . $extension;
        $target     = $directory . '/' . $storedName;

        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException(t('附件保存失败，请检查目录写入权限。'));
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
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t('附件大小超过服务器限制，请调整 php.ini 的 upload_max_filesize / post_max_size。'),
            UPLOAD_ERR_PARTIAL                        => t('附件仅上传了一部分，请重试。'),
            UPLOAD_ERR_NO_TMP_DIR                     => t('服务器缺少临时目录。'),
            UPLOAD_ERR_CANT_WRITE                     => t('服务器无法写入临时文件。'),
            UPLOAD_ERR_EXTENSION                      => t('上传被 PHP 扩展中断。'),
            default                                   => t('附件上传失败。'),
        };
    }
}
