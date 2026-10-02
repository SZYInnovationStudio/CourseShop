<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 站点公告
 */
final class Announcement
{
    /**
     * 前台展示：已启用的公告（最新在前）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function latest(int $limit = 5): array
    {
        $limit = max(1, $limit);

        return Database::select(
            "SELECT `id`, `title`, `content`, `published_at`
               FROM `announcements`
              WHERE `is_active` = 1 AND `deleted_at` IS NULL
              ORDER BY `published_at` DESC, `id` DESC
              LIMIT {$limit}"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findActive(int $id): ?array
    {
        return Database::first(
            'SELECT * FROM `announcements` WHERE `id` = ? AND `is_active` = 1 AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    // ============================================================
    // 后台公告管理
    // ============================================================

    /**
     * 后台公告列表（含停用，最新在前）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminAll(): array
    {
        return Database::select(
            'SELECT `id`, `title`, `content`, `is_active`, `published_at`, `created_at`, `updated_at`
               FROM `announcements`
              WHERE `deleted_at` IS NULL
              ORDER BY `published_at` DESC, `id` DESC'
        );
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
            'SELECT * FROM `announcements` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 新增公告，返回自增 ID
     */
    public static function create(string $title, string $content, bool $isActive, ?string $publishedAt): int
    {
        return Database::insert(
            'INSERT INTO `announcements` (`title`, `content`, `is_active`, `published_at`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$title, $content, $isActive ? 1 : 0, $publishedAt]
        );
    }

    public static function update(int $id, string $title, string $content, bool $isActive, ?string $publishedAt): void
    {
        Database::execute(
            'UPDATE `announcements`
                SET `title` = ?, `content` = ?, `is_active` = ?, `published_at` = ?
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [$title, $content, $isActive ? 1 : 0, $publishedAt, $id]
        );
    }

    /**
     * 软删除公告
     */
    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE `announcements` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        );
    }
}
