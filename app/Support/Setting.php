<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 系统设置（数据库 settings 表，键值对）
 *
 * 首次读取时一次性载入并缓存在内存中；后台修改后调用 flush() 失效。
 * 注意：settings 表的 key / value 是 MySQL 保留字，SQL 中必须加反引号。
 */
final class Setting
{
    /** 全量设置的文件缓存键 */
    private const CACHE_KEY = 'settings:all';

    /** 设置缓存有效期（秒）：后台保存时主动失效，此处仅作兜底 */
    private const CACHE_TTL = 3600;

    /** @var array<string, string|null> */
    private static array $cache = [];

    private static bool $loaded = false;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        if (!array_key_exists($key, self::$cache)) {
            return $default;
        }

        $value = self::$cache[$key];

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 写入或更新单个设置
     */
    public static function set(string $key, mixed $value): void
    {
        $scalar = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        Database::execute(
            'INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()',
            [$key, $scalar]
        );

        self::$cache[$key] = $scalar;

        // 主动失效全量设置缓存，保证其他请求立刻读到最新值
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * 批量写入
     *
     * @param array<string, mixed> $values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set((string) $key, $value);
        }
    }

    /**
     * 取某分组下的全部设置
     *
     * @return array<string, string|null>
     */
    public static function group(string $group): array
    {
        $rows = Database::select('SELECT `key`, `value` FROM `settings` WHERE `group_name` = ?', [$group]);

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['key']] = $row['value'];
        }

        return $result;
    }

    public static function flush(): void
    {
        self::$cache  = [];
        self::$loaded = false;

        Cache::forget(self::CACHE_KEY);
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        try {
            // 全量设置走文件缓存，避免每个请求都查一次 settings 表；
            // 后台保存时（set / flush）会主动失效该缓存。
            $rows = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static function (): array {
                return Database::select('SELECT `key`, `value` FROM `settings`');
            });

            foreach ($rows as $row) {
                self::$cache[(string) $row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            // 数据库不可用时（例如安装向导阶段）静默降级为默认值，保证错误页仍可渲染
            Logger::warning('读取系统设置失败：' . $e->getMessage());
            self::$cache = [];
        }
    }
}
