<?php

declare(strict_types=1);

namespace App\Support\Cache;

/**
 * 缓存驱动接口
 *
 * 业务代码统一通过 App\Support\Cache 门面访问缓存，不直接依赖具体驱动。
 * 新增驱动（如 redis / memcached）只需实现本接口，并在 Cache::resolve() 中注册，
 * 上层调用无需改动。
 *
 * 约定：缓存值只能是标量或数组（文件驱动使用 serialize 往返，且反序列化时
 * 禁用对象还原），不要存放对象、闭包或资源。
 */
interface CacheStore
{
    /**
     * 读取缓存，未命中返回 $default
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 写入缓存
     *
     * @param int $ttl 有效期（秒），<= 0 表示永不过期
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * 删除单个缓存
     */
    public function forget(string $key): bool;

    /**
     * 清空全部缓存
     */
    public function flush(): bool;
}
