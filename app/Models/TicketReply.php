<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 工单回复
 */
final class TicketReply
{
    /**
     * 创建回复，返回回复 ID
     */
    public static function create(int $ticketId, int $userId, bool $isAdmin, string $content): int
    {
        return Database::insert(
            'INSERT INTO `ticket_replies` (`ticket_id`, `user_id`, `is_admin`, `content`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$ticketId, $userId, $isAdmin ? 1 : 0, $content]
        );
    }

    /**
     * 工单的回复列表（按时间正序，含回复人信息）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listByTicket(int $ticketId): array
    {
        return Database::select(
            'SELECT r.*, u.`username` AS user_name, u.`nickname` AS user_nickname
               FROM `ticket_replies` r
               LEFT JOIN `users` u ON u.`id` = r.`user_id`
              WHERE r.`ticket_id` = ? AND r.`deleted_at` IS NULL
              ORDER BY r.`id` ASC',
            [$ticketId]
        );
    }

    public static function countByTicket(int $ticketId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `ticket_replies` WHERE `ticket_id` = ? AND `deleted_at` IS NULL',
            [$ticketId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return Database::first(
            'SELECT * FROM `ticket_replies` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    public static function softDelete(int $id): bool
    {
        return Database::execute(
            'UPDATE `ticket_replies` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        ) === 1;
    }
}
