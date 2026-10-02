<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 配置读取（支持点号路径）
 *
 * 启动时把 config/ 下的每个 php 文件加载为一级命名空间，
 * 例如 config/config.php 中的 app.url 通过 Config::get('app.url') 读取。
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    public static function load(string $directory): void
    {
        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            // 路由文件由入口单独加载，不属于配置
            if ($name === 'routes') {
                continue;
            }

            $data = require $file;
            if (!is_array($data)) {
                continue;
            }

            // 主配置文件 config.php 的顶层键（app / database / session / upload）
            // 直接作为一级命名空间，其余文件则按文件名作为命名空间。
            if ($name === 'config') {
                self::$items = array_replace_recursive(self::$items, $data);
                continue;
            }

            self::$items[$name] = $data;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target   = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
    }
}
