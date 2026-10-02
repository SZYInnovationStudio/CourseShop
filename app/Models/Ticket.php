<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Search;

/**
 * 工单
 *
 * 类型：normal 普通工单 / appeal 申诉工单
 * 状态流转：pending 待处理 → processing 处理中 → replied 已回复 → closed 已关闭
 */
final class Ticket
{
    public const TYPE_NORMAL = 'normal';

    public const TYPE_APPEAL = 'appeal';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_CLOSED = 'closed';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_NORMAL => '普通工单',
        self::TYPE_APPEAL => '账号申诉',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING    => '待处理',
        self::STATUS_PROCESSING => '处理中',
        self::STATUS_REPLIED    => '已回复',
        self::STATUS_CLOSED     => '已关闭',
    ];

    /** @var array<int, string> */
    public const PRIORITY_LABELS = [
        0 => '低',
        1 => '普通',
        2 => '高',
        3 => '紧急',
    ];

    /** 问题分类（前台下拉可选） */
    public const CATEGORIES = [
        'account'   => '账号相关',
        'order'     => '订单与支付',
        'course'    => '课程与学习',
        'technical' => '技术故障',
        'other'     => '其他问题',
    ];

    /**
     * 生成唯一工单号：TK + 年月日时分秒 + 6 位随机数
     */
    public static function generateNo(): string
    {
        return 'TK' . date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 创建工单，返回工单 ID
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $sql = 'INSERT INTO `tickets`
                    (`ticket_no`, `user_id`, `type`, `category`, `title`, `content`,
                     `status`, `priority`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        $params = [
            (string) $data['ticket_no'],
            (int) $data['user_id'],
            (string) ($data['type'] ?? self::TYPE_NORMAL),
            (string) ($data['category'] ?? ''),
            (string) $data['title'],
            (string) $data['content'],
            self::STATUS_PENDING,
            (int) ($data['priority'] ?? 1),
        ];

        return Database::insert($sql, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return Database::first(
            'SELECT * FROM `tickets` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 前台：按 ID 查询属于该用户的工单
     *
     * @return array<string, mixed>|null
     */
    public static function findByIdForUser(int $id, int $userId): ?array
    {
        return Database::first(
            'SELECT * FROM `tickets` WHERE `id` = ? AND `user_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id, $userId]
        );
    }

    /**
     * 后台按 ID 查询工单（含提交用户信息）
     *
     * @return array<string, mixed>|null
     */
    public static function adminFind(int $id): ?array
    {
        return Database::first(
            'SELECT t.*, u.`username` AS user_name, u.`nickname` AS user_nickname,
                    u.`email` AS user_email, u.`status` AS user_status,
                    u.`ban_type` AS user_ban_type, u.`banned_until` AS user_banned_until,
                    u.`ban_reason` AS user_ban_reason
               FROM `tickets` t
               LEFT JOIN `users` u ON u.`id` = t.`user_id`
              WHERE t.`id` = ? AND t.`deleted_at` IS NULL
              LIMIT 1',
            [$id]
        );
    }

    /**
     * 用户的工单列表
     *
     * @param array<string, mixed> $filters 支持 keyword、status
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(int $userId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::userWhere($userId, $filters);

        return Database::select(
            'SELECT * FROM `tickets`
              WHERE ' . $where . '
              ORDER BY `id` DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bindings
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function countForUser(int $userId, array $filters = []): int
    {
        [$where, $bindings] = self::userWhere($userId, $filters);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `tickets` WHERE ' . $where,
            $bindings
        );
    }

    /**
     * 后台工单列表（含提交用户名）
     *
     * 支持的筛选：keyword（工单号/标题/用户名）、status、type、priority、category、user_id、date_from、date_to
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        return Database::select(
            'SELECT t.*, u.`username` AS user_name
               FROM `tickets` t
               LEFT JOIN `users` u ON u.`id` = t.`user_id`
              WHERE ' . $where . '
              ORDER BY t.`status` = \'closed\' ASC, t.`priority` DESC, t.`id` DESC
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

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `tickets` t
               LEFT JOIN `users` u ON u.`id` = t.`user_id`
              WHERE ' . $where,
            $bindings
        );
    }

    /**
     * 按状态统计工单数（后台仪表盘用）
     */
    public static function countByStatus(string $status): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `tickets` WHERE `status` = ? AND `deleted_at` IS NULL',
            [$status]
        );
    }

    /**
     * 是否存在未关闭的申述工单（避免同一用户重复申诉）
     */
    public static function hasOpenAppeal(int $userId): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `tickets`
              WHERE `user_id` = ? AND `type` = ? AND `status` <> ? AND `deleted_at` IS NULL',
            [$userId, self::TYPE_APPEAL, self::STATUS_CLOSED]
        ) > 0;
    }

    /**
     * 回复数 +1，并刷新最后回复时间
     */
    public static function incrementReply(int $ticketId): void
    {
        Database::execute(
            'UPDATE `tickets`
                SET `reply_count` = `reply_count` + 1, `last_reply_at` = NOW()
              WHERE `id` = ?',
            [$ticketId]
        );
    }

    /**
     * 更新工单状态
     */
    public static function setStatus(int $ticketId, string $status, ?int $operatorId = null): void
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            return;
        }

        if ($status === self::STATUS_CLOSED) {
            Database::execute(
                'UPDATE `tickets` SET `status` = ?, `closed_at` = NOW(), `closed_by` = ? WHERE `id` = ?',
                [$status, $operatorId, $ticketId]
            );

            return;
        }

        Database::execute(
            'UPDATE `tickets` SET `status` = ? WHERE `id` = ?',
            [$status, $ticketId]
        );
    }

    /**
     * 前台：用户关闭自己的工单
     */
    public static function closeByUser(int $ticketId, int $userId): bool
    {
        $affected = Database::execute(
            'UPDATE `tickets`
                SET `status` = ?, `closed_at` = NOW(), `closed_by` = ?
              WHERE `id` = ? AND `user_id` = ? AND `status` <> ?',
            [self::STATUS_CLOSED, $userId, $ticketId, $userId, self::STATUS_CLOSED]
        );

        return $affected === 1;
    }

    /**
     * 后台：设置优先级
     */
    public static function setPriority(int $ticketId, int $priority): bool
    {
        if (!isset(self::PRIORITY_LABELS[$priority])) {
            return false;
        }

        return Database::execute(
            'UPDATE `tickets` SET `priority` = ? WHERE `id` = ?',
            [$priority, $ticketId]
        ) === 1;
    }

    /**
     * 前台：用户软删除自己的工单
     */
    public static function softDeleteByUser(int $ticketId, int $userId): bool
    {
        return Database::execute(
            'UPDATE `tickets` SET `deleted_at` = NOW()
              WHERE `id` = ? AND `user_id` = ? AND `deleted_at` IS NULL',
            [$ticketId, $userId]
        ) === 1;
    }

    /**
     * 后台：软删除工单
     */
    public static function softDelete(int $ticketId): bool
    {
        return Database::execute(
            'UPDATE `tickets` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$ticketId]
        ) === 1;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function userWhere(int $userId, array $filters): array
    {
        $conditions = ['`user_id` = ?', '`deleted_at` IS NULL'];
        $bindings   = [$userId];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = '(`ticket_no` LIKE ? OR `title` LIKE ?)';
            $like         = Search::likePattern($keyword);
            $bindings[]   = $like;
            $bindings[]   = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $conditions[] = '`status` = ?';
            $bindings[]   = $status;
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function adminWhere(array $filters): array
    {
        $conditions = ['t.`deleted_at` IS NULL'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = '(t.`ticket_no` LIKE ? OR t.`title` LIKE ? OR u.`username` LIKE ?)';
            $like         = Search::likePattern($keyword);
            $bindings[]   = $like;
            $bindings[]   = $like;
            $bindings[]   = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $conditions[] = 't.`status` = ?';
            $bindings[]   = $status;
        }

        $type = (string) ($filters['type'] ?? '');
        if ($type !== '' && isset(self::TYPE_LABELS[$type])) {
            $conditions[] = 't.`type` = ?';
            $bindings[]   = $type;
        }

        $priority = $filters['priority'] ?? '';
        if ($priority !== '' && array_key_exists((int) $priority, self::PRIORITY_LABELS)) {
            $conditions[] = 't.`priority` = ?';
            $bindings[]   = (int) $priority;
        }

        $category = (string) ($filters['category'] ?? '');
        if ($category !== '' && isset(self::CATEGORIES[$category])) {
            $conditions[] = 't.`category` = ?';
            $bindings[]   = $category;
        }

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $conditions[] = 't.`user_id` = ?';
            $bindings[]   = $userId;
        }

        $from = (string) ($filters['date_from'] ?? '');
        if ($from !== '') {
            $conditions[] = 't.`created_at` >= ?';
            $bindings[]   = $from . ' 00:00:00';
        }

        $to = (string) ($filters['date_to'] ?? '');
        if ($to !== '') {
            $conditions[] = 't.`created_at` <= ?';
            $bindings[]   = $to . ' 23:59:59';
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    public static function priorityLabel(int $priority): string
    {
        return self::PRIORITY_LABELS[$priority] ?? (string) $priority;
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? ($category !== '' ? $category : '未分类');
    }
}
