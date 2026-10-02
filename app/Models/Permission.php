<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * RBAC 权限模型
 *
 * 权限为系统预置数据，不提供增删改，仅提供读取（按分组展示）。
 */
final class Permission
{
    /**
     * 全部权限，按分组与 ID 排序
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::select(
            'SELECT `id`, `code`, `name`, `group_name`
               FROM `permissions`
              WHERE `deleted_at` IS NULL
              ORDER BY `group_name` ASC, `id` ASC'
        );
    }

    /**
     * 按分组归集权限：group_name => [ {id, code, name}, ... ]
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $permission) {
            $groups[(string) $permission['group_name']][] = $permission;
        }

        return $groups;
    }

    /**
     * 权限分组的中文名（用于表单分组标题）
     *
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'dashboard' => '仪表盘',
            'user'      => '用户管理',
            'course'    => '课程内容',
            'order'     => '订单交易',
            'ticket'    => '工单服务',
            'setting'   => '系统设置',
            'system'    => '系统与权限',
            'general'   => '其他',
        ];
    }

    /**
     * 全部权限 ID
     *
     * @return array<int, int>
     */
    public static function allIds(): array
    {
        $rows = Database::select('SELECT `id` FROM `permissions` WHERE `deleted_at` IS NULL');

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
