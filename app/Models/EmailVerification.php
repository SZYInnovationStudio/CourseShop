<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Request;
use App\Support\Setting;

/**
 * 邮箱验证码
 *
 * 同一「邮箱 + 用途」同时只保留一条有效记录：下发新码时会把旧码置为已使用。
 */
final class EmailVerification
{
    /** 单条验证码最多允许校验几次 */
    public const MAX_ATTEMPTS = 5;

    /** 用途：绑定邮箱 */
    public const PURPOSE_BIND = 'bind';

    /** 用途：找回密码 */
    public const PURPOSE_RESET = 'reset';

    /**
     * 下发验证码
     *
     * @return array{code: string, expires_at: string, ttl: int}
     */
    public static function issue(?int $userId, string $email, string $purpose = self::PURPOSE_BIND): array
    {
        $ttl       = max(60, Setting::int('mail_code_ttl', 600));
        $code      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token     = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        // 作废该邮箱 + 用途下尚未使用的旧验证码
        Database::execute(
            'UPDATE `email_verifications` SET `used_at` = NOW()
              WHERE `email` = ? AND `purpose` = ? AND `used_at` IS NULL',
            [$email, $purpose]
        );

        Database::execute(
            'INSERT INTO `email_verifications`
                (`user_id`, `email`, `code`, `purpose`, `token`, `attempts`, `ip`, `expires_at`, `created_at`)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, NOW())',
            [$userId, $email, $code, $purpose, $token, Request::ip(), $expiresAt]
        );

        return ['code' => $code, 'expires_at' => $expiresAt, 'ttl' => $ttl];
    }

    /**
     * 取该邮箱 + 用途下最新一条尚未使用的验证码
     *
     * @return array<string, mixed>|null
     */
    public static function latest(string $email, string $purpose = self::PURPOSE_BIND): ?array
    {
        return Database::first(
            'SELECT * FROM `email_verifications`
              WHERE `email` = ? AND `purpose` = ? AND `used_at` IS NULL
              ORDER BY `id` DESC
              LIMIT 1',
            [$email, $purpose]
        );
    }

    public static function incrementAttempts(int $id): void
    {
        Database::execute('UPDATE `email_verifications` SET `attempts` = `attempts` + 1 WHERE `id` = ?', [$id]);
    }

    public static function markUsed(int $id): void
    {
        Database::execute('UPDATE `email_verifications` SET `used_at` = NOW() WHERE `id` = ?', [$id]);
    }

    /**
     * 统计最近 $seconds 秒内向该邮箱下发的验证码数量（用于发送频率限制）
     */
    public static function recentCount(string $email, string $purpose, int $seconds): int
    {
        $since = date('Y-m-d H:i:s', time() - $seconds);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `email_verifications`
              WHERE `email` = ? AND `purpose` = ? AND `created_at` >= ?',
            [$email, $purpose, $since]
        );
    }
}
