<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 已购课程授权
 */
final class Enrollment
{
    /**
     * 判断用户是否已获得某课程的学习权限
     */
    public static function hasAccess(?int $userId, int $courseId): bool
    {
        if ($userId === null || $userId <= 0 || $courseId <= 0) {
            return false;
        }

        $row = Database::first(
            'SELECT `id`, `expire_at`
               FROM `enrollments`
              WHERE `user_id` = ? AND `course_id` = ? AND `status` = 1 AND `deleted_at` IS NULL
              LIMIT 1',
            [$userId, $courseId]
        );

        if ($row === null) {
            return false;
        }

        $expireAt = $row['expire_at'];

        return $expireAt === null || strtotime((string) $expireAt) > time();
    }

    /**
     * 是否已购买（存在任意有效授权记录）
     */
    public static function exists(int $userId, int $courseId): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `enrollments` WHERE `user_id` = ? AND `course_id` = ? AND `deleted_at` IS NULL',
            [$userId, $courseId]
        ) > 0;
    }

    /**
     * 已购课程数量（有效授权）
     */
    public static function countForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*)
               FROM `enrollments` e
               INNER JOIN `courses` c ON c.id = e.course_id AND c.deleted_at IS NULL
              WHERE e.user_id = ? AND e.status = 1 AND e.deleted_at IS NULL
                AND (e.expire_at IS NULL OR e.expire_at > NOW())',
            [$userId]
        );
    }

    /**
     * 已购课程列表（含学习进度聚合）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function coursesForUser(int $userId, int $limit = 9, int $offset = 0): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit  = max(1, $limit);
        $offset = max(0, $offset);

        return Database::select(
            "SELECT c.id, c.title, c.subtitle, c.cover, c.summary, c.price, c.category_id,
                    cat.name AS category_name,
                    e.granted_at, e.expire_at,
                    (SELECT COUNT(*) FROM `chapters` ch
                      WHERE ch.course_id = c.id AND ch.status = 1 AND ch.deleted_at IS NULL) AS chapter_count,
                    (SELECT COUNT(*) FROM `play_progress` pp
                      WHERE pp.user_id = e.user_id AND pp.course_id = c.id AND pp.finished = 1) AS finished_count,
                    (SELECT ch2.id FROM `chapters` ch2
                      WHERE ch2.course_id = c.id AND ch2.status = 1 AND ch2.deleted_at IS NULL
                        AND ch2.video_path IS NOT NULL AND ch2.video_path <> ''
                      ORDER BY ch2.sort ASC, ch2.id ASC LIMIT 1) AS first_chapter_id,
                    (SELECT pp2.chapter_id FROM `play_progress` pp2
                      WHERE pp2.user_id = e.user_id AND pp2.course_id = c.id
                      ORDER BY pp2.updated_at DESC, pp2.id DESC LIMIT 1) AS last_chapter_id
               FROM `enrollments` e
               INNER JOIN `courses` c ON c.id = e.course_id AND c.deleted_at IS NULL
               LEFT JOIN `course_categories` cat ON cat.id = c.category_id AND cat.deleted_at IS NULL
              WHERE e.user_id = ? AND e.status = 1 AND e.deleted_at IS NULL
                AND (e.expire_at IS NULL OR e.expire_at > NOW())
              ORDER BY e.granted_at DESC, e.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            [$userId]
        );
    }

    /**
     * 后台查看某用户的全部课程授权（含已失效 / 已过期 / 已退款）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminCoursesForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return Database::select(
            'SELECT e.id, e.course_id, e.order_id, e.status, e.granted_at, e.expire_at, e.deleted_at,
                    c.title AS course_title, c.price AS course_price, c.deleted_at AS course_deleted_at
               FROM `enrollments` e
               LEFT JOIN `courses` c ON c.id = e.course_id
              WHERE e.user_id = ?
              ORDER BY e.granted_at DESC, e.id DESC',
            [$userId]
        );
    }

    /**
     * 开通课程（幂等）
     *
     * enrollments 表上有 (user_id, course_id) 唯一键，重复开通只更新时间与订单号，
     * 因此支付回调重复触发也不会产生重复记录。
     */
    public static function grant(int $userId, int $courseId, ?int $orderId = null): void
    {
        Database::execute(
            'INSERT INTO `enrollments` (`user_id`, `course_id`, `order_id`, `granted_at`, `status`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, NOW(), 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                `status` = 1,
                `order_id` = VALUES(`order_id`),
                `granted_at` = NOW(),
                `deleted_at` = NULL,
                `updated_at` = NOW()',
            [$userId, $courseId, $orderId]
        );
    }

    /**
     * 撤销课程授权（退款成功后调用，软删除并置为失效）
     *
     * @return bool 本次调用是否真正撤销了授权
     */
    public static function revoke(int $userId, int $courseId): bool
    {
        return Database::execute(
            'UPDATE `enrollments` SET `status` = 0, `deleted_at` = NOW(), `updated_at` = NOW()
              WHERE `user_id` = ? AND `course_id` = ? AND `deleted_at` IS NULL',
            [$userId, $courseId]
        ) > 0;
    }
}
