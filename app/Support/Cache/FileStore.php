<?php

declare(strict_types=1);

namespace App\Support\Cache;

/**
 * 文件缓存驱动（默认，零依赖）
 *
 * 每个缓存键对应 storage/cache 下的一个 .cache 文件，文件名取键名的 SHA-256，
 * 避免非法字符与目录穿越。文件内容为 serialize 后的 {expires_at, value} 结构。
 *
 * 反序列化时禁用对象还原（allowed_classes = false），因此只支持标量与数组，
 * 这也杜绝了不可信内容触发的对象注入风险。
 */
final class FileStore implements CacheStore
{
    /** 缓存文件扩展名，用于 flush 时精确匹配，避免误删目录内的其他文件 */
    private const SUFFIX = '.cache';

    private string $directory;

    /**
     * @param string|null $directory 缓存目录，留空则使用 storage/cache
     */
    public function __construct(?string $directory = null)
    {
        $dir = $directory !== null && $directory !== ''
            ? $directory
            : STORAGE_PATH . '/cache';

        $this->directory = rtrim($dir, '/\\');

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->path($key);

        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            @unlink($file);

            return $default;
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($expiresAt !== 0 && $expiresAt <= time()) {
            @unlink($file);

            return $default;
        }

        return $payload['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $payload = [
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            'value'      => $value,
        ];

        return @file_put_contents($this->path($key), serialize($payload), LOCK_EX) !== false;
    }

    public function forget(string $key): bool
    {
        $file = $this->path($key);

        return !is_file($file) || @unlink($file);
    }

    public function flush(): bool
    {
        $ok = true;

        foreach (glob($this->directory . '/*' . self::SUFFIX) ?: [] as $file) {
            $ok = @unlink($file) && $ok;
        }

        return $ok;
    }

    /**
     * 缓存文件绝对路径（键名哈希后落盘，避免路径穿越与非法字符）
     */
    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . self::SUFFIX;
    }
}
