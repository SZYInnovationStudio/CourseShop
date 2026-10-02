<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 课程分类
 *
 * 支持两级分类树：`parent_id = 0` 为顶级分类，其余为某个顶级分类的子分类。
 */
final class Category
{
    /** 分类树最大层级（1 个顶级 + 1 个子级） */
    public const MAX_LEVELS = 2;

    /**
     * 全部分类（含已上架课程数量），按树形顺序返回，附带 depth 缩进层级
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::tree(Database::select(
            "SELECT cat.id, cat.parent_id, cat.name, cat.sort,
                    (SELECT COUNT(*) FROM `courses` c
                      WHERE c.category_id = cat.id AND c.status = 'published' AND c.deleted_at IS NULL) AS course_count
               FROM `course_categories` cat
              WHERE cat.deleted_at IS NULL
              ORDER BY cat.sort ASC, cat.id ASC"
        ));
    }

    // ============================================================
    // 后台分类管理
    // ============================================================

    /**
     * 后台分类列表（课程数量统计含草稿与下架），按树形顺序返回，附带 depth 缩进层级
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminAll(): array
    {
        return self::tree(Database::select(
            "SELECT cat.id, cat.parent_id, cat.name, cat.sort, cat.created_at,
                    (SELECT COUNT(*) FROM `courses` c
                      WHERE c.category_id = cat.id AND c.deleted_at IS NULL) AS course_count
               FROM `course_categories` cat
              WHERE cat.deleted_at IS NULL
              ORDER BY cat.sort ASC, cat.id ASC"
        ));
    }

    /**
     * 将扁平分类列表整理为树形顺序，并为每行补上 depth 缩进层级
     *
     * 若数据异常（父分类被删除、层级超出上限），这些行会平铺到末尾作为顶级展示，
     * 避免分类在界面上「凭空消失」。
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function tree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) $row['parent_id']][] = $row;
        }

        $ordered = [];
        $placed  = [];

        $walk = static function (int $parentId, int $depth) use (&$walk, &$ordered, &$placed, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $row) {
                $row['depth'] = $depth;
                $ordered[]    = $row;
                $placed[(int) $row['id']] = true;

                if ($depth + 1 < self::MAX_LEVELS) {
                    $walk((int) $row['id'], $depth + 1);
                }
            }
        };

        $walk(0, 0);

        foreach ($rows as $row) {
            if (!isset($placed[(int) $row['id']])) {
                $row['depth'] = 0;
                $ordered[]    = $row;
            }
        }

        return $ordered;
    }

    /**
     * 可作为上级分类的候选列表（仅顶级分类，排除指定分类及其子分类）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parentOptions(?int $excludeId = null): array
    {
        $exclude = [];
        if ($excludeId !== null && $excludeId > 0) {
            $exclude = array_merge([$excludeId], self::descendantIds($excludeId));
        }

        return array_values(array_filter(
            self::adminAll(),
            static fn (array $row): bool => (int) $row['depth'] === 0
                && !in_array((int) $row['id'], $exclude, true)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return Database::first(
            'SELECT `id`, `parent_id`, `name`, `sort` FROM `course_categories` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    public static function create(string $name, int $sort, int $parentId = 0): int
    {
        return Database::insert(
            'INSERT INTO `course_categories` (`name`, `sort`, `parent_id`) VALUES (?, ?, ?)',
            [$name, $sort, $parentId > 0 ? $parentId : 0]
        );
    }

    public static function update(int $id, string $name, int $sort, int $parentId = 0): void
    {
        Database::execute(
            'UPDATE `course_categories` SET `name` = ?, `sort` = ?, `parent_id` = ?
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [$name, $sort, $parentId > 0 ? $parentId : 0, $id]
        );
    }

    /**
     * 软删除分类
     */
    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE `course_categories` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        );
    }

    /**
     * 分类名称是否已被占用（用于唯一性校验）
     */
    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT COUNT(*) FROM `course_categories` WHERE `name` = ? AND `deleted_at` IS NULL';
        $bindings = [$name];

        if ($exceptId !== null && $exceptId > 0) {
            $sql       .= ' AND `id` <> ?';
            $bindings[] = $exceptId;
        }

        return (int) Database::scalar($sql, $bindings) > 0;
    }

    /**
     * 分类下的课程数量（含草稿/下架，不含已删除）
     */
    public static function courseCount(int $id): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `courses` WHERE `category_id` = ? AND `deleted_at` IS NULL',
            [$id]
        );
    }

    /**
     * 是否存在子分类
     */
    public static function hasChildren(int $id): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `course_categories` WHERE `parent_id` = ? AND `deleted_at` IS NULL',
            [$id]
        ) > 0;
    }

    /**
     * 子分类 ID 列表（含所有层级，不含自身）
     *
     * 用于「按分类筛选课程时同时包含其子分类」，以及校验上级分类不能选择自身的子分类。
     *
     * @return array<int, int>
     */
    public static function descendantIds(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $rows = Database::select(
            'SELECT `id`, `parent_id` FROM `course_categories` WHERE `deleted_at` IS NULL'
        );

        $childrenOf = [];
        foreach ($rows as $row) {
            $childrenOf[(int) $row['parent_id']][] = (int) $row['id'];
        }

        $result = [];
        $stack  = [$id];

        while ($stack !== []) {
            $current = (int) array_pop($stack);
            foreach ($childrenOf[$current] ?? [] as $childId) {
                if (!in_array($childId, $result, true)) {
                    $result[] = $childId;
                    $stack[]  = $childId;
                }
            }
        }

        return $result;
    }

    /**
     * 分类自身 + 全部子分类的 ID 列表
     *
     * @return array<int, int>
     */
    public static function idWithDescendants(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        return array_merge([$id], self::descendantIds($id));
    }
}
