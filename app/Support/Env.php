<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 极简 .env 解析器
 *
 * 项目不引入 Composer 依赖，这里手写一个够用的解析器：
 * - 支持 KEY=VALUE 形式
 * - 忽略空行与以 # 开头的注释行
 * - 自动去除 UTF-8 BOM（避免第一个键名带上不可见字符）
 * - 自动去除包裹的成对引号
 */
final class Env
{
    /** @var array<string, string> */
    private static array $items = [];

    /**
     * 读取并解析 .env 文件
     */
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $content = (string) file_get_contents($path);
        // 去掉 UTF-8 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        foreach (preg_split("/\r\n|\r|\n/", $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $position = strpos($line, '=');
            if ($position === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));
            if ($key === '') {
                continue;
            }

            // 去除包裹的成对引号
            $length = strlen($value);
            if ($length >= 2) {
                $first = $value[0];
                $last  = $value[$length - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$items[$key] = $value;
        }
    }

    /**
     * 取值：优先 .env 文件，其次系统环境变量
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$items)) {
            return self::$items[$key];
        }

        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$items;
    }
}
