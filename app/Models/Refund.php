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

    /** 已抢占处理权、正在调用支付网关（防止并发重复退款） */
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /** 处于「待处理」语义、会阻止同一订单重复发起退款的状态 */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING    => '待审核',
        self::STATUS_PROCESSING => '处理中',
        self::STATUS_SUCCESS    => '退款成功',
        self::STATUS_FAILED     => '已驳回',
    ];

    /** @var array<string, string> 状态 => 徽标样式 */
    public const STATUS_BADGES = [
        self::STATUS_PENDING    => 'badge--warning',
        self::STATUS_PROCESSING => 'badge--warning',
        self::STATUS_SUCCESS    => 'badge--success',
        self::STATUS_FAILED     => 'badge',
    ];

    /** 处理中状态超过该秒数视为超时，允许重新抢占（处理进程可能已崩溃） */
    private const CLAIM_STALE_SECONDS = 600;

    /**
     * 生成唯一退款单号：R + 年月日时分秒 + 6 位随机数
     */
    public static function generateNo(): string
    {
        return 'R' . date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 创建退款单（初始状态为待审核），保证「同一订单同一时刻仅一个待处理退款单」
     *
     * 通过锁定订单行 + 事务串行化同一订单的并发发起，避免无锁预检导致的重复退款单（CS-10）。
     *
     * @return int|null 新退款单 ID；已存在待处理退款单时返回 null
     */
    public static function createIfIdle(
        int $orderId,
        string $orderNo,
        int $userId,
        int $amount,
        string $reason,
        ?int $operatorId
    ): ?int {
        return Database::transaction(static function () use (
            $orderId,
            $orderNo,
            $userId,
            $amount,
            $reason,
            $operatorId
        ): ?int {
            // 锁定订单行，串行化同一订单的并发发起
            Database::scalar('SELECT `id` FROM `orders` WHERE `id` = ? FOR UPDATE', [$orderId]);

            $exists = (int) Database::scalar(
                'SELECT COUNT(*) FROM `refunds`
                  WHERE `order_id` = ? AND `status` IN (?, ?) AND `deleted_at` IS NULL',
                [$orderId, self::STATUS_PENDING, self::STATUS_PROCESSING]
            );

            if ($exists > 0) {
                return null;
            }

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
        });
    }

    /**
     * 原子抢占退款处理权（CS-10）
     *
     * 仅当退款单仍为「待审核」，或「处理中」但已超过超时时间（处理进程可能已崩溃）时，
     * 才能抢占成功。抢占后状态为 processing，后续必须调用 markSuccess / markFailed / release 收尾。
     *
     * @return bool 是否成功获得处理权
     */
    public static function claim(int $id, ?int $operatorId): bool
    {
        return Database::execute(
            'UPDATE `refunds`
                SET `status` = ?, `operator_id` = ?, `updated_at` = NOW()
              WHERE `id` = ? AND `deleted_at` IS NULL
                AND (`status` = ?
                     OR (`status` = ? AND `updated_at` < DATE_SUB(NOW(), INTERVAL ? SECOND)))',
            [
                self::STATUS_PROCESSING,
                $operatorId,
                $id,
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
                self::CLAIM_STALE_SECONDS,
            ]
        ) === 1;
    }

    /**
     * 释放处理权，回到待审核（网关调用异常时使用，允许核实后重试）
     */
    public static function release(int $id): bool
    {
        return Database::execute(
            'UPDATE `refunds` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ? AND `status` = ?',
            [self::STATUS_PENDING, $id, self::STATUS_PROCESSING]
        ) === 1;
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
     * 标记退款成功（待审核 / 处理中的退款单均可流转）
     */
    public static function markSuccess(int $id, string $raw, ?int $operatorId): bool
    {
        return Database::execute(
            'UPDATE `refunds` SET `status` = ?, `api_result` = ?, `operator_id` = ?, `updated_at` = NOW()
              WHERE `id` = ? AND `status` IN (?, ?)',
            [self::STATUS_SUCCESS, $raw, $operatorId, $id, self::STATUS_PENDING, self::STATUS_PROCESSING]
        ) === 1;
    }

    /**
     * 标记退款失败 / 驳回（待审核 / 处理中的退款单均可流转）
     */
    public static function markFailed(int $id, string $raw, ?int $operatorId): bool
    {
        return Database::execute(
            'UPDATE `refunds` SET `status` = ?, `api_result` = ?, `operator_id` = ?, `updated_at` = NOW()
              WHERE `id` = ? AND `status` IN (?, ?)',
            [self::STATUS_FAILED, $raw, $operatorId, $id, self::STATUS_PENDING, self::STATUS_PROCESSING]
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
