<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Cache\CacheStore;
use App\Support\Cache\FileStore;
use App\Support\Cache\MemoryStore;
use Throwable;

/**
 * 缓存门面
 *
 * 统一入口，屏蔽具体驱动差异；驱动由 config('cache.driver') 决定：
 *   - file ：文件缓存（默认，零依赖，跨请求持久化）→ storage/cache
 *   - array：进程内数组缓存（当次请求有效，便于自测或临时关闭持久化）
 *
 * 缓存失败一律静默降级（记 warning 日志后返回默认值 / 直接执行回调），
 * 保证缓存不可用时业务功能不受影响。
 *
 * 典型用法：
 *   $courses = Cache::remember('home:recommended', 300, fn () => Course::search([], 'recommended', 8, 0));
 *   Cache::forget('home:recommended'); // 后台改动后主动失效
 */
final class Cache
{
    private static ?CacheStore $store = null;

    /** 未命中的哨兵对象（用于 remember 区分「未命中」与「命中且值为 null」） */
    private static ?object $miss = null;

    /**
     * 获取（或注入）当前驱动实例
     */
    public static function store(?CacheStore $store = null): CacheStore
    {
        if ($store !== null) {
            self::$store = $store;
        }

        return self::$store ??= self::resolve();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return self::store()->get($key, $default);
        } catch (Throwable $e) {
            Logger::warning('缓存读取失败：' . $e->getMessage(), ['key' => $key]);

            return $default;
        }
    }

    public static function set(string $key, mixed $value, int $ttl = 0): bool
    {
        try {
            return self::store()->set($key, $value, $ttl);
        } catch (Throwable $e) {
            Logger::warning('缓存写入失败：' . $e->getMessage(), ['key' => $key]);

            return false;
        }
    }

    /**
     * 读取缓存，未命中则执行回调并写入
     *
     * @param int      $ttl      有效期（秒），<= 0 表示永不过期
     * @param callable $callback 未命中时的数据生产者
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sentinel = self::$miss ??= new \stdClass();
        $cached   = self::get($key, $sentinel);

        if ($cached !== $sentinel) {
            return $cached;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    public static function forget(string $key): bool
    {
        try {
            return self::store()->forget($key);
        } catch (Throwable $e) {
            Logger::warning('缓存删除失败：' . $e->getMessage(), ['key' => $key]);

            return false;
        }
    }

    /**
     * 清空全部缓存
     */
    public static function flush(): bool
    {
        try {
            return self::store()->flush();
        } catch (Throwable $e) {
            Logger::warning('缓存清空失败：' . $e->getMessage());

            return false;
        }
    }

    /**
     * 丢弃已解析的驱动（下次访问重新按配置解析），用于配置变更后
     */
    public static function reset(): void
    {
        self::$store = null;
    }

    /**
     * 按配置解析驱动
     *
     * 预留扩展点：新增驱动时在此增加分支并实现 CacheStore 接口即可。
     */
    private static function resolve(): CacheStore
    {
        $driver = strtolower((string) Config::get('cache.driver', 'file'));

        return match ($driver) {
            'array', 'memory' => new MemoryStore(),
            default           => new FileStore((string) Config::get('cache.path', '')),
        };
    }
}
