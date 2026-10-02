<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Search;

/**
 * 退款单（P2）
 *
 * 流程：管理员在订单详情页「发起退款」生成待审核退款单（pending），
 * 再到退款管理页「确认退款」（调用支付网关）或「驳回」。
 * 金额一律使用「分」（整数）。
 */
final class Refund
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待审核',
        self::STATUS_SUCCESS => '退款成功',
        self::STATUS_FAILED  => '已驳回',
    ];

    /** @var array<string, string> 状态 => 徽标样式 */
    public const STATUS_BADGES = [
        self::STATUS_PENDING => 'badge--warning',
        self::STATUS_SUCCESS => 'badge--success',
        self::STATUS_FAILED  => 'badge',
    ];

    /**
     * 生成唯一退款单号：R + 年月日时分秒 + 6 位随机数
     */
    public static function generateNo(): string
    {
        return 'R' . date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 创建退款单（初始状态为待审核）
     */
    public static function create(
        int $orderId,
        string $orderNo,
        int $userId,
        int $amount,
        string $reason,
        ?int $operatorId
    ): int {
        return Database::insert(
            'INSERT INTO `refunds`
                (`refund_no`, `order_id`, `order_no`, `user_id`, `amount`, `reason`, `status`, `operator_id`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                self::generateNo(),
                $orderId,
                $orderNo,
                $userId,
                $amount,
                mb_substr(trim($reason), 0, 255),
                self::STATUS_PENDING,
                $operatorId,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT * FROM `refunds` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 该订单是否存在待审核的退款单（用于阻止重复发起）
     *
     * @return array<string, mixed>|null
     */
    public static function pendingByOrder(int $orderId): ?array
    {
        return Database::first(
            'SELECT * FROM `refunds`
              WHERE `order_id` = ? AND `status` = ? AND `deleted_at` IS NULL
              ORDER BY `id` DESC LIMIT 1',
            [$orderId, self::STATUS_PENDING]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByOrder(int $orderId): ?array
    {
        return Database::first(
            'SELECT * FROM `refunds` WHERE `order_id` = ? AND `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 1',
            [$orderId]
        );
    }

    /**
     * 后台退款单列表（含订单与用户信息）
     *
     * 支持的筛选：keyword（退款单号/订单号/用户名）、status、date_from、date_to
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        return Database::select(
            'SELECT r.*, u.`username` AS user_name
               FROM `refunds` r
               LEFT JOIN `users` u ON u.`id` = r.`user_id`
              WHERE ' . $where . '
              ORDER BY r.`id` DESC
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
            'SELECT COUNT(*) FROM `refunds` r
               LEFT JOIN `users` u ON u.`id` = r.`user_id`
              WHERE ' . $where,
            $bindings
        );
    }

    /**
     * 标记退款成功（仅待审核的退款单可流转）
     */
    public static function markSuccess(int $id, string $raw, ?int $operatorId): bool
    {
        return Database::execute(
            'UPDATE `refunds` SET `status` = ?, `api_result` = ?, `operator_id` = ?, `updated_at` = NOW()
              WHERE `id` = ? AND `status` = ?',
            [self::STATUS_SUCCESS, $raw, $operatorId, $id, self::STATUS_PENDING]
        ) === 1;
    }

    /**
     * 标记退款失败 / 驳回（仅待审核的退款单可流转）
     */
    public static function markFailed(int $id, string $raw, ?int $operatorId): bool
    {
        return Database::execute(
            'UPDATE `refunds` SET `status` = ?, `api_result` = ?, `operator_id` = ?, `updated_at` = NOW()
              WHERE `id` = ? AND `status` = ?',
            [self::STATUS_FAILED, $raw, $operatorId, $id, self::STATUS_PENDING]
        ) === 1;
    }

    public static function countByStatus(string $status): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `refunds` WHERE `status` = ? AND `deleted_at` IS NULL',
            [$status]
        );
    }

    public static function label(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function adminWhere(array $filters): array
    {
        $conditions = ['r.`deleted_at` IS NULL'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = '(r.`refund_no` LIKE ? OR r.`order_no` LIKE ? OR u.`username` LIKE ?)';
            $like         = Search::likePattern($keyword);
            $bindings[]   = $like;
            $bindings[]   = $like;
            $bindings[]   = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $conditions[] = 'r.`status` = ?';
            $bindings[]   = $status;
        }

        $from = (string) ($filters['date_from'] ?? '');
        if ($from !== '') {
            $conditions[] = 'r.`created_at` >= ?';
            $bindings[]   = $from . ' 00:00:00';
        }

        $to = (string) ($filters['date_to'] ?? '');
        if ($to !== '') {
            $conditions[] = 'r.`created_at` <= ?';
            $bindings[]   = $to . ' 23:59:59';
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
