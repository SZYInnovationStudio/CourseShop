<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LoginDevice;
use Throwable;

/**
 * 登录态
 *
 * 会话中只保存 user_id，用户数据按需从数据库加载（只读缓存于本次请求）。
 */
final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    private static bool $resolved = false;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;

        $id = (int) Session::get('user_id', 0);
        if ($id <= 0) {
            return null;
        }

        try {
            $user = Database::first(
                'SELECT * FROM `users` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
                [$id]
            );
        } catch (Throwable $e) {
            Logger::error('读取当前用户失败：' . $e->getMessage());
            return null;
        }

        if ($user === null || (int) $user['status'] !== 1 || self::isBanned($user)) {
            Session::forget('user_id');
            return null;
        }

        // 设备管理：会话被管理员或用户本人撤销后，强制退出登录
        $token = (string) Session::get(LoginDevice::SESSION_KEY, '');

        if ($token !== '' && LoginDevice::isRevoked($token)) {
            Session::forget('user_id');
            Session::forget(LoginDevice::SESSION_KEY);

            return null;
        }

        return self::$user = $user;
    }

    /**
     * 当前会话的设备令牌（未登录时为 null）
     */
    public static function deviceToken(): ?string
    {
        $token = (string) Session::get(LoginDevice::SESSION_KEY, '');

        return $token === '' ? null : $token;
    }

    /**
     * 写入登录态（调用方需先校验账号密码）
     *
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('login_at', time());

        // 登记登录设备（用于会话管理与踢下线）
        $token = bin2hex(random_bytes(32));
        Session::set(LoginDevice::SESSION_KEY, $token);
        LoginDevice::register((int) $user['id'], $token, Request::ip(), Request::userAgent());

        self::$user     = $user;
        self::$resolved = true;

        Permission::forget();

        Csrf::rotate();
    }

    public static function logout(): void
    {
        $token = (string) Session::get(LoginDevice::SESSION_KEY, '');

        if ($token !== '') {
            LoginDevice::forget($token);
        }

        Session::forget('user_id');
        Session::forget(LoginDevice::SESSION_KEY);
        Session::regenerate();

        self::$user     = null;
        self::$resolved = true;

        Permission::forget();
    }

    /**
     * 是否可进入后台
     *
     * 兼容三种情况：
     * - users.is_admin = 1（P0 标记）；
     * - users.role 为 admin / super_admin（P0 角色字段）；
     * - 通过 RBAC 分配了 super_admin / admin / support / operator 任一派后台角色。
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        return Permission::isAdminUser($user);
    }

    /**
     * 当前用户是否拥有指定权限
     */
    public static function can(string $code): bool
    {
        return Permission::can($code);
    }

    /**
     * 当前用户是否为超级管理员
     */
    public static function isSuperAdmin(): bool
    {
        return Permission::isSuperAdmin();
    }

    /**
     * 当前用户的角色 code 列表
     *
     * @return array<int, string>
     */
    public static function roles(): array
    {
        return Permission::roles();
    }

    public static function hasVerifiedEmail(): bool
    {
        $user = self::user();

        return $user !== null && !empty($user['email_verified_at']);
    }

    /**
     * 是否需要强制绑定邮箱（管理员豁免）
     */
    public static function mustBindEmail(): bool
    {
        if (!Setting::bool('force_email_bind', false)) {
            return false;
        }

        $user = self::user();
        if ($user === null || self::isAdmin()) {
            return false;
        }

        return empty($user['email_verified_at']);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isBanned(array $user): bool
    {
        $type = (string) ($user['ban_type'] ?? 'none');

        if ($type === 'permanent') {
            return true;
        }

        if ($type === 'temp') {
            $until = $user['banned_until'] ?? null;

            return $until !== null && strtotime((string) $until) > time();
        }

        return false;
    }

    /**
     * 丢弃缓存，下次访问时重新加载
     */
    public static function refresh(): void
    {
        self::$user     = null;
        self::$resolved = false;

        Permission::forget();

        self::user();
    }
}
