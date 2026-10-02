<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 权限解析（RBAC）
 *
 * 以「角色 -> 权限」两级模型解析当前登录用户的权限，结果在单次请求内缓存。
 *
 * 兼容策略：
 * - 用户被显式分配了 super_admin 角色：拥有全部权限；
 * - 用户未分配任何角色、但 users.is_admin = 1（P0 时期的旧管理员）：同样拥有全部权限，
 *   避免升级到 P2 后旧账号突然失去后台访问能力；
 * - 其余情况按 user_roles -> role_permissions -> permissions 求并集。
 *
 * 数据表：roles / permissions / role_permissions / user_roles
 */
final class Permission
{
    /** @var array<int, string>|null 当前用户角色 code 列表 */
    private static ?array $roles = null;

    /** @var array<int, string>|null 当前用户权限 code 列表 */
    private static ?array $codes = null;

    private static bool $resolved = false;

    /** @var array<int, string>|null 全部权限 code（请求级缓存） */
    private static ?array $allCodes = null;

    /**
     * 当前用户是否为超级管理员
     */
    public static function isSuperAdmin(): bool
    {
        return in_array('super_admin', self::roles(), true);
    }

    /**
     * 当前用户的角色 code 列表
     *
     * @return array<int, string>
     */
    public static function roles(): array
    {
        self::resolve();

        return self::$roles ?? [];
    }

    /**
     * 指定用户是否具备后台访问权限（不依赖当前登录态）
     *
     * 用于登录前的权限预检：账号密码校验通过后，先判断该账号是否为管理员，
     * 再决定是否写入登录态，避免「先建登录态后校验」的时序问题。
     *
     * 判定口径与 Auth::isAdmin() 保持一致：
     * - users.is_admin = 1（P0 标记）；
     * - users.role 为 admin / super_admin（P0 角色字段）；
     * - 通过 RBAC 分配了 super_admin / admin / support / operator 任一派后台角色。
     *
     * @param array<string, mixed> $user
     */
    public static function isAdminUser(array $user): bool
    {
        if ((int) ($user['is_admin'] ?? 0) === 1) {
            return true;
        }

        if (in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin'], true)) {
            return true;
        }

        try {
            $roles = self::roleCodesOf((int) $user['id']);
        } catch (Throwable $e) {
            Logger::error('查询用户角色失败：' . $e->getMessage());

            return false;
        }

        return array_intersect($roles, ['super_admin', 'admin', 'support', 'operator']) !== [];
    }

    /**
     * 当前用户是否拥有指定权限
     */
    public static function can(string $code): bool
    {
        if ($code === '') {
            return false;
        }

        return in_array($code, self::codes(), true);
    }

    /**
     * 当前用户的权限 code 列表
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        self::resolve();

        return self::$codes ?? [];
    }

    /**
     * 清空请求级缓存（登录 / 登出 / 资料变更后调用）
     */
    public static function forget(): void
    {
        self::$roles    = null;
        self::$codes    = null;
        self::$resolved = false;
    }

    /**
     * 解析当前用户的角色与权限
     */
    private static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }

        self::$resolved = true;
        self::$roles    = [];
        self::$codes    = [];

        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $userId = (int) $user['id'];

        try {
            self::$roles = self::roleCodesOf($userId);

            // 超级管理员拥有全部权限
            if (in_array('super_admin', self::$roles, true)) {
                self::$codes = self::allCodes();

                return;
            }

            // 兼容旧管理员：未分配任何角色时保留全部权限
            if (self::$roles === [] && (int) ($user['is_admin'] ?? 0) === 1) {
                self::$codes = self::allCodes();

                return;
            }

            if (self::$roles === []) {
                return;
            }

            self::$codes = self::permissionCodesOf($userId);
        } catch (Throwable $e) {
            Logger::error('解析用户权限失败：' . $e->getMessage());
        }
    }

    /**
     * 查询用户拥有的角色 code
     *
     * @return array<int, string>
     */
    private static function roleCodesOf(int $userId): array
    {
        $rows = Database::select(
            'SELECT r.`code`
               FROM `user_roles` ur
               JOIN `roles` r ON r.`id` = ur.`role_id`
              WHERE ur.`user_id` = ? AND r.`deleted_at` IS NULL',
            [$userId]
        );

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['code'],
            $rows
        )));
    }

    /**
     * 查询用户通过角色获得的权限 code 并集
     *
     * @return array<int, string>
     */
    private static function permissionCodesOf(int $userId): array
    {
        $rows = Database::select(
            'SELECT DISTINCT p.`code`
               FROM `user_roles` ur
               JOIN `role_permissions` rp ON rp.`role_id` = ur.`role_id`
               JOIN `permissions` p ON p.`id` = rp.`permission_id`
              WHERE ur.`user_id` = ? AND p.`deleted_at` IS NULL',
            [$userId]
        );

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['code'],
            $rows
        )));
    }

    /**
     * 全部权限 code（请求级缓存）
     *
     * @return array<int, string>
     */
    private static function allCodes(): array
    {
        if (self::$allCodes !== null) {
            return self::$allCodes;
        }

        try {
            $rows = Database::select('SELECT `code` FROM `permissions` WHERE `deleted_at` IS NULL');
        } catch (Throwable $e) {
            Logger::error('读取权限列表失败：' . $e->getMessage());

            return self::$allCodes = [];
        }

        return self::$allCodes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['code'],
            $rows
        )));
    }
}
