<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Ids;

/**
 * RBAC 角色模型
 *
 * 数据表：roles / role_permissions / user_roles
 */
final class Role
{
    /** 角色权限优先级（用于把多角色归并到 users.role 字段） */
    public const PRIORITY = ['super_admin', 'admin', 'support', 'operator', 'user'];

    /**
     * 全部角色（附带权限数量与用户数量）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::select(
            'SELECT r.`id`, r.`code`, r.`name`, r.`description`, r.`is_system`, r.`created_at`,
                    (SELECT COUNT(*) FROM `role_permissions` rp
                      WHERE rp.`role_id` = r.`id`) AS permissions_count,
                    (SELECT COUNT(*) FROM `user_roles` ur
                      WHERE ur.`role_id` = r.`id`) AS users_count,
                    (SELECT COUNT(*) FROM `permissions` p
                      WHERE p.`deleted_at` IS NULL) AS permissions_total
               FROM `roles` r
              WHERE r.`deleted_at` IS NULL
              ORDER BY r.`id` ASC'
        );
    }

    /**
     * 角色下拉选项（id => 名称）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        return Database::select(
            'SELECT `id`, `code`, `name` FROM `roles`
              WHERE `deleted_at` IS NULL ORDER BY `id` ASC'
        );
    }

    /**
     * 按主键查询
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `roles` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 过滤出真实存在的角色 ID（用于表单取值）
     *
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    public static function existingIds(array $ids): array
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = Database::select(
            'SELECT `id` FROM `roles`
              WHERE `deleted_at` IS NULL AND `id` IN (' . $placeholders . ')',
            $ids
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * 角色 code 是否已存在
     */
    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT COUNT(*) FROM `roles` WHERE `code` = ?';
        $bindings = [$code];

        if ($exceptId !== null && $exceptId > 0) {
            $sql       .= ' AND `id` <> ?';
            $bindings[] = $exceptId;
        }

        return (int) Database::scalar($sql, $bindings) > 0;
    }

    /**
     * 创建角色，返回角色 ID
     */
    public static function create(string $code, string $name, ?string $description): int
    {
        return Database::insert(
            'INSERT INTO `roles` (`code`, `name`, `description`, `is_system`)
             VALUES (?, ?, ?, 0)',
            [$code, $name, $description]
        );
    }

    /**
     * 更新角色基本信息
     */
    public static function update(int $id, string $name, ?string $description): void
    {
        Database::execute(
            'UPDATE `roles` SET `name` = ?, `description` = ? WHERE `id` = ? AND `deleted_at` IS NULL',
            [$name, $description, $id]
        );
    }

    /**
     * 软删除角色
     */
    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE `roles` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        );
    }

    /**
     * 已分配该角色的账号数量
     */
    public static function usersCount(int $roleId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `user_roles` WHERE `role_id` = ?',
            [$roleId]
        );
    }

    /**
     * 角色已分配的权限 ID 列表
     *
     * @return array<int, int>
     */
    public static function permissionIds(int $roleId): array
    {
        $rows = Database::select(
            'SELECT `permission_id` FROM `role_permissions` WHERE `role_id` = ?',
            [$roleId]
        );

        return array_map(static fn (array $row): int => (int) $row['permission_id'], $rows);
    }

    /**
     * 覆盖式同步角色权限
     *
     * @param array<int, int> $permissionIds
     */
    public static function syncPermissions(int $roleId, array $permissionIds): void
    {
        Database::transaction(static function () use ($roleId, $permissionIds): void {
            Database::execute('DELETE FROM `role_permissions` WHERE `role_id` = ?', [$roleId]);

            if ($permissionIds === []) {
                return;
            }

            $values = [];
            $params = [];

            foreach ($permissionIds as $permissionId) {
                $values[] = '(?, ?)';
                $params[] = $roleId;
                $params[] = $permissionId;
            }

            Database::execute(
                'INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES ' . implode(', ', $values),
                $params
            );
        });
    }

    /**
     * 按角色 ID 列表查询对应的角色 code
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    public static function codesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = Database::select(
            'SELECT `code` FROM `roles`
              WHERE `deleted_at` IS NULL AND `id` IN (' . $placeholders . ')',
            $ids
        );

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    /**
     * 用户已分配的角色 ID 列表
     *
     * @return array<int, int>
     */
    public static function userIdsRoleIds(int $userId): array
    {
        $rows = Database::select(
            'SELECT ur.`role_id`
               FROM `user_roles` ur
               JOIN `roles` r ON r.`id` = ur.`role_id`
              WHERE ur.`user_id` = ? AND r.`deleted_at` IS NULL',
            [$userId]
        );

        return array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
    }

    /**
     * 用户已分配角色的 code 列表
     *
     * @return array<int, string>
     */
    public static function userRoleCodes(int $userId): array
    {
        $rows = Database::select(
            'SELECT r.`code`
               FROM `user_roles` ur
               JOIN `roles` r ON r.`id` = ur.`role_id`
              WHERE ur.`user_id` = ? AND r.`deleted_at` IS NULL',
            [$userId]
        );

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    /**
     * 覆盖式同步用户角色
     *
     * @param array<int, int> $roleIds
     */
    public static function syncUserRoles(int $userId, array $roleIds): void
    {
        Database::transaction(static function () use ($userId, $roleIds): void {
            Database::execute('DELETE FROM `user_roles` WHERE `user_id` = ?', [$userId]);

            if ($roleIds === []) {
                return;
            }

            $values = [];
            $params = [];

            foreach ($roleIds as $roleId) {
                $values[] = '(?, ?)';
                $params[] = $userId;
                $params[] = $roleId;
            }

            Database::execute(
                'INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES ' . implode(', ', $values),
                $params
            );
        });
    }

    /**
     * 按优先级从角色 code 列表中挑选主角色（用于写入 users.role）
     *
     * @param array<int, string> $codes
     */
    public static function primaryCode(array $codes): string
    {
        foreach (self::PRIORITY as $code) {
            if (in_array($code, $codes, true)) {
                return $code;
            }
        }

        return 'user';
    }
}
