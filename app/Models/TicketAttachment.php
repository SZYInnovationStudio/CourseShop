<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 工单附件
 *
 * file_path 为存储键（相对 storage/private/tickets 的相对路径），不对外公开，
 * 下载需经过鉴权的控制器路由。
 */
final class TicketAttachment
{
    /**
     * 创建附件记录，返回附件 ID
     */
    public static function create(
        int $ticketId,
        ?int $replyId,
        int $userId,
        string $fileName,
        string $filePath,
        int $fileSize,
        string $mimeType
    ): int {
        return Database::insert(
            'INSERT INTO `ticket_attachments`
                (`ticket_id`, `reply_id`, `user_id`, `file_name`, `file_path`, `file_size`, `mime_type`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$ticketId, $replyId, $userId, $fileName, $filePath, $fileSize, $mimeType]
        );
    }

    /**
     * 工单的全部附件（含正文与回复附件）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listByTicket(int $ticketId): array
    {
        return Database::select(
            'SELECT * FROM `ticket_attachments`
              WHERE `ticket_id` = ? AND `deleted_at` IS NULL
              ORDER BY `id` ASC',
            [$ticketId]
        );
    }

    /**
     * 某条回复的附件
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listByReply(int $replyId): array
    {
        return Database::select(
            'SELECT * FROM `ticket_attachments`
              WHERE `reply_id` = ? AND `deleted_at` IS NULL
              ORDER BY `id` ASC',
            [$replyId]
        );
    }

    /**
     * 按工单聚合附件，键为 reply_id（0 表示工单正文附件）
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function groupByReply(int $ticketId): array
    {
        $grouped = [];

        foreach (self::listByTicket($ticketId) as $attachment) {
            $key             = (int) ($attachment['reply_id'] ?? 0);
            $grouped[$key][] = $attachment;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return Database::first(
            'SELECT * FROM `ticket_attachments` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    public static function softDelete(int $id): bool
    {
        return Database::execute(
            'UPDATE `ticket_attachments` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        ) === 1;
    }
}
