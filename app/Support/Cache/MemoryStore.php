<?php

declare(strict_types=1);

namespace App\Support\Cache;

/**
 * 进程内数组缓存驱动
 *
 * 仅在当前请求 / 当前 CLI 进程生命周期内有效，不跨请求持久化。
 * 适合：单元自测、CLI 任务、临时关闭持久化缓存（CACHE_DRIVER=array）。
 */
final class MemoryStore implements CacheStore
{
    /** @var array<string, array{0: mixed, 1: int}> 键 => [值, 过期时间戳（0 表示永不过期）] */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->items)) {
            return $default;
        }

        [$value, $expiresAt] = $this->items[$key];

        if ($expiresAt !== 0 && $expiresAt <= time()) {
            unset($this->items[$key]);

            return $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->items[$key] = [$value, $ttl > 0 ? time() + $ttl : 0];

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }
}
