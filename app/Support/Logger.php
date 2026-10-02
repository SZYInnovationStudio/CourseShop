<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 文件日志（JSON 行格式，按天切分）
 *
 * 落盘位置：storage/logs/app-YYYY-MM-DD.log
 */
final class Logger
{
    public static function emergency(string $message, array $context = []): void
    {
        self::write('emergency', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $directory = STORAGE_PATH . '/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $line = json_encode([
            'time'    => date('Y-m-d H:i:s'),
            'level'   => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        @file_put_contents(
            $directory . '/app-' . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
