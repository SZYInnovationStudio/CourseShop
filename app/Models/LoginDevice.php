<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use Throwable;

/**
 * 登录设备（P2）
 *
 * 每次登录生成一个随机的设备令牌并写入会话，用于：
 * - 在「账户设置 → 登录安全」中查看当前账号的活跃登录设备；
 * - 支持将指定设备踢下线（撤销），被撤销的设备在下次请求时会被强制退出登录。
 */
final class LoginDevice
{
    /** 会话中保存设备令牌的键名 */
    public const SESSION_KEY = 'device_token';

    /**
     * 登记一台登录设备
     *
     * 同一令牌重复登记时更新活跃时间（避免唯一键冲突）。
     */
    public static function register(int $userId, string $token, string $ip, string $ua): void
    {
        $ua = mb_substr($ua, 0, 255);

        try {
            Database::execute(
                'INSERT INTO `login_devices` (`user_id`, `session_token`, `ip`, `ua`, `last_active_at`)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `ip` = VALUES(`ip`),
                                         `ua` = VALUES(`ua`), `last_active_at` = NOW(),
                                         `revoked_at` = NULL',
                [$userId, $token, $ip !== '' ? $ip : null, $ua !== '' ? $ua : null]
            );
        } catch (Throwable $e) {
            // 设备管理属于增强能力，失败不应阻断登录
            \App\Support\Logger::error('登记登录设备失败：' . $e->getMessage());
        }
    }

    /**
     * 将指定设备标记为已撤销（用于「退出该设备」）
     */
    public static function revoke(int $id, int $userId): int
    {
        return Database::execute(
            'UPDATE `login_devices` SET `revoked_at` = NOW()
             WHERE `id` = ? AND `user_id` = ? AND `revoked_at` IS NULL',
            [$id, $userId]
        );
    }

    /**
     * 撤销当前用户除指定令牌外的全部设备（用于「退出其他所有设备」）
     */
    public static function revokeOthers(int $userId, string $keepToken): int
    {
        return Database::execute(
            'UPDATE `login_devices` SET `revoked_at` = NOW()
             WHERE `user_id` = ? AND `session_token` <> ? AND `revoked_at` IS NULL',
            [$userId, $keepToken]
        );
    }

    /**
     * 撤销当前用户全部设备（退出登录时清理，或安全事件时使用）
     */
    public static function revokeAll(int $userId): int
    {
        return Database::execute(
            'UPDATE `login_devices` SET `revoked_at` = NOW()
             WHERE `user_id` = ? AND `revoked_at` IS NULL',
            [$userId]
        );
    }

    /**
     * 删除某台设备记录（退出登录时清理当前设备）
     */
    public static function forget(string $token): void
    {
        try {
            Database::execute('DELETE FROM `login_devices` WHERE `session_token` = ?', [$token]);
        } catch (Throwable $e) {
            \App\Support\Logger::error('清理登录设备失败：' . $e->getMessage());
        }
    }

    /**
     * 某个设备令牌是否已被撤销
     *
     * 令牌不存在（如历史会话未登记）时返回 false，避免误伤老会话。
     */
    public static function isRevoked(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        try {
            $revokedAt = Database::scalar(
                'SELECT `revoked_at` FROM `login_devices` WHERE `session_token` = ? LIMIT 1',
                [$token]
            );
        } catch (Throwable $e) {
            return false;
        }

        return $revokedAt !== null && $revokedAt !== '';
    }

    /**
     * 读取指定设备的会话令牌（不存在或不属于该用户时返回 null）
     */
    public static function tokenOf(int $id, int $userId): ?string
    {
        $token = Database::scalar(
            'SELECT `session_token` FROM `login_devices` WHERE `id` = ? AND `user_id` = ? LIMIT 1',
            [$id, $userId]
        );

        return $token === null ? null : (string) $token;
    }

    /**
     * 当前用户的活跃设备列表（含最后活跃时间倒序）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForUser(int $userId): array
    {
        return Database::select(
            'SELECT * FROM `login_devices`
             WHERE `user_id` = ? AND `revoked_at` IS NULL
             ORDER BY `last_active_at` DESC, `id` DESC',
            [$userId]
        );
    }

    /**
     * 刷新当前设备活跃时间
     */
    public static function touch(string $token): void
    {
        try {
            Database::execute(
                'UPDATE `login_devices` SET `last_active_at` = NOW() WHERE `session_token` = ?',
                [$token]
            );
        } catch (Throwable $e) {
            // 忽略：活跃时间仅用于展示
        }
    }
}
