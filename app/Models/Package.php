<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Search;

/**
 * 课程套餐
 *
 * 将多门课程打包，以套餐价整体售卖；用户购买套餐后一次性开通套餐内全部课程。
 * 金额一律使用「分」（整数）。套餐与课程为多对多关系（package_courses）。
 */
final class Package
{
    /** 套餐状态（后台） */
    public const STATUSES = [
        'draft'     => '草稿',
        'published' => '已上架',
        'offline'   => '已下架',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    // ============================================================
    // 后台管理
    // ============================================================

    /**
     * 后台套餐列表（含草稿/下架，最新在前）
     *
     * @param  array<string, mixed> $filters keyword（名称）、status
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        return Database::select(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM `package_courses` pc WHERE pc.`package_id` = p.`id`) AS course_count
               FROM `packages` p
              WHERE ' . $where . '
              ORDER BY p.`sort` DESC, p.`id` DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bindings
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function adminCount(array $filters = []): int
    {
        [$where, $bindings] = self::adminWhere($filters);

        return (int) Database::scalar('SELECT COUNT(*) FROM `packages` p WHERE ' . $where, $bindings);
    }

    /**
     * 后台套餐详情（不限状态）
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `packages` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 新建套餐，返回自增 ID
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO `packages`
                (`title`, `subtitle`, `summary`, `content`, `cover`, `price`, `original_price`,
                 `is_recommend`, `status`, `sort`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (string) $data['title'],
                (string) $data['subtitle'],
                (string) $data['summary'],
                (string) $data['content'],
                $data['cover'] ?? null,
                (int) $data['price'],
                (int) $data['original_price'],
                (int) $data['is_recommend'],
                (string) $data['status'],
                (int) $data['sort'],
            ]
        );
    }

    /**
     * 更新套餐
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE `packages` SET
                `title` = ?, `subtitle` = ?, `summary` = ?, `content` = ?, `cover` = ?,
                `price` = ?, `original_price` = ?, `is_recommend` = ?, `status` = ?, `sort` = ?
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [
                (string) $data['title'],
                (string) $data['subtitle'],
                (string) $data['summary'],
                (string) $data['content'],
                $data['cover'] ?? null,
                (int) $data['price'],
                (int) $data['original_price'],
                (int) $data['is_recommend'],
                (string) $data['status'],
                (int) $data['sort'],
                $id,
            ]
        );
    }

    /**
     * 软删除套餐（保留订单、关联等历史数据）
     */
    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE `packages` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        );
    }

    /**
     * 全部套餐（id + title），用于下拉选择
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        return Database::select(
            'SELECT `id`, `title` FROM `packages` WHERE `deleted_at` IS NULL ORDER BY `sort` DESC, `id` DESC'
        );
    }

    // ============================================================
    // 套餐 - 课程关联
    // ============================================================

    /**
     * 套餐已绑定课程 ID
     *
     * @return array<int, int>
     */
    public static function courseIds(int $packageId): array
    {
        $rows = Database::select(
            'SELECT `course_id` FROM `package_courses` WHERE `package_id` = ? ORDER BY `sort` ASC, `id` ASC',
            [$packageId]
        );

        return array_map(static fn (array $row): int => (int) $row['course_id'], $rows);
    }

    /**
     * 覆盖式同步套餐课程
     *
     * @param array<int, int> $courseIds
     */
    public static function syncCourses(int $packageId, array $courseIds): void
    {
        $courseIds = array_values(array_unique(array_filter(
            array_map('intval', $courseIds),
            static fn (int $id): bool => $id > 0
        )));

        // 先删后插必须在同一事务内，避免中途失败导致套餐课程被清空
        Database::transaction(static function () use ($packageId, $courseIds): void {
            Database::execute('DELETE FROM `package_courses` WHERE `package_id` = ?', [$packageId]);

            foreach ($courseIds as $index => $courseId) {
                Database::execute(
                    'INSERT INTO `package_courses` (`package_id`, `course_id`, `sort`) VALUES (?, ?, ?)',
                    [$packageId, $courseId, $index + 1]
                );
            }
        });
    }

    /**
     * 套餐内课程数（仅统计未删除课程）
     */
    public static function courseCount(int $packageId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
               FROM `package_courses` pc
               INNER JOIN `courses` c ON c.`id` = pc.`course_id` AND c.`deleted_at` IS NULL
              WHERE pc.`package_id` = ?',
            [$packageId]
        );
    }

    /**
     * 套餐内课程列表（仅未删除课程，含价格）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function courseList(int $packageId): array
    {
        return Database::select(
            'SELECT c.`id`, c.`title`, c.`subtitle`, c.`cover`, c.`price`, c.`original_price`, c.`status`, pc.`sort`
               FROM `package_courses` pc
               INNER JOIN `courses` c ON c.`id` = pc.`course_id` AND c.`deleted_at` IS NULL
              WHERE pc.`package_id` = ?
              ORDER BY pc.`sort` ASC, pc.`id` ASC',
            [$packageId]
        );
    }

    // ============================================================
    // 前台
    // ============================================================

    /**
     * 前台套餐列表（仅已上架）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publishedList(int $limit = 12, int $offset = 0): array
    {
        return Database::select(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM `package_courses` pc
                       INNER JOIN `courses` c ON c.`id` = pc.`course_id` AND c.`deleted_at` IS NULL
                      WHERE pc.`package_id` = p.`id`) AS course_count,
                    (SELECT COALESCE(SUM(c.`price`), 0) FROM `package_courses` pc
                       INNER JOIN `courses` c ON c.`id` = pc.`course_id` AND c.`deleted_at` IS NULL
                      WHERE pc.`package_id` = p.`id`) AS courses_price
               FROM `packages` p
              WHERE p.`status` = ' . "'published'" . ' AND p.`deleted_at` IS NULL
              ORDER BY p.`sort` DESC, p.`id` DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
    }

    public static function publishedCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM `packages` WHERE `status` = 'published' AND `deleted_at` IS NULL"
        );
    }

    /**
     * 前台套餐详情（仅已上架）
     *
     * @return array<string, mixed>|null
     */
    public static function findPublished(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return Database::first(
            "SELECT * FROM `packages` WHERE `id` = ? AND `status` = 'published' AND `deleted_at` IS NULL LIMIT 1",
            [$id]
        );
    }

    /**
     * 首页推荐套餐
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recommend(int $limit = 3): array
    {
        return Database::select(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM `package_courses` pc
                       INNER JOIN `courses` c ON c.`id` = pc.`course_id` AND c.`deleted_at` IS NULL
                      WHERE pc.`package_id` = p.`id`) AS course_count
               FROM `packages` p
              WHERE p.`status` = ' . "'published'" . ' AND p.`is_recommend` = 1 AND p.`deleted_at` IS NULL
              ORDER BY p.`sort` DESC, p.`id` DESC
              LIMIT ' . max(1, $limit)
        );
    }

    /**
     * 累计销量（首次开通套餐时调用）
     */
    public static function incrementSales(int $packageId): void
    {
        Database::execute('UPDATE `packages` SET `sales_count` = `sales_count` + 1 WHERE `id` = ?', [$packageId]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function adminWhere(array $filters): array
    {
        $conditions = ['p.`deleted_at` IS NULL'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = 'p.`title` LIKE ?';
            $bindings[]   = Search::likePattern($keyword);
        }

        $status = (string) ($filters['status'] ?? '');
        if (array_key_exists($status, self::STATUSES)) {
            $conditions[] = 'p.`status` = ?';
            $bindings[]   = $status;
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
