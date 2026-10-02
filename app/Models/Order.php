<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Ids;
use App\Support\Search;
use App\Support\Setting;

/**
 * 订单
 *
 * 金额一律使用「分」（整数）；订单号全局唯一。
 * 状态流转：pending → paying → paid → completed / closed / refunded
 */
final class Order
{
    /** 后台导出单次最大条数，防止超大结果集耗尽内存 */
    public const MAX_EXPORT_ROWS = 50000;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAYING = 'paying';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REFUNDED = 'refunded';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING   => '待支付',
        self::STATUS_PAYING    => '支付中',
        self::STATUS_PAID      => '已支付',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CLOSED    => '已关闭',
        self::STATUS_REFUNDED  => '已退款',
    ];

    /**
     * 生成唯一订单号：年月日时分秒 + 6 位随机数
     */
    public static function generateNo(): string
    {
        return date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 创建订单，返回订单 ID
     *
     * 课程订单传 course_id，套餐订单传 package_id（此时 course_id 为 null）。
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $sql = 'INSERT INTO `orders`
                    (`order_no`, `user_id`, `course_id`, `package_id`, `course_title`, `amount`, `original_amount`,
                     `discount_amount`, `coupon_id`, `pay_type`, `status`, `client_ip`, `device`,
                     `expire_at`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        $courseId  = (int) ($data['course_id'] ?? 0);
        $packageId = (int) ($data['package_id'] ?? 0);

        $params = [
            (string) $data['order_no'],
            (int) $data['user_id'],
            $courseId > 0 ? $courseId : null,
            $packageId > 0 ? $packageId : null,
            (string) $data['course_title'],
            (int) $data['amount'],
            (int) ($data['original_amount'] ?? 0),
            (int) ($data['discount_amount'] ?? 0),
            $data['coupon_id'] ?? null,
            (string) ($data['pay_type'] ?? ''),
            self::STATUS_PENDING,
            (string) ($data['client_ip'] ?? ''),
            (string) ($data['device'] ?? ''),
            (string) ($data['expire_at'] ?? self::defaultExpireAt()),
        ];

        return Database::insert($sql, $params);
    }

    /**
     * 订单超时时间（默认 15 分钟，后台可配）
     */
    public static function defaultExpireAt(): string
    {
        $minutes = max(1, Setting::int('order_expire_minutes', 15));

        return date('Y-m-d H:i:s', time() + $minutes * 60);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByNo(string $orderNo): ?array
    {
        return Database::first(
            'SELECT * FROM `orders` WHERE `order_no` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$orderNo]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByNoForUser(string $orderNo, int $userId): ?array
    {
        return Database::first(
            'SELECT * FROM `orders` WHERE `order_no` = ? AND `user_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$orderNo, $userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return Database::first('SELECT * FROM `orders` WHERE `id` = ? LIMIT 1', [$id]);
    }

    /**
     * 后台按 ID 查询订单（含下单用户信息，不含已删除订单）
     *
     * @return array<string, mixed>|null
     */
    public static function adminFind(int $id): ?array
    {
        return Database::first(
            'SELECT o.*, u.`username` AS user_name, u.`nickname` AS user_nickname, u.`email` AS user_email
               FROM `orders` o
               LEFT JOIN `users` u ON u.`id` = o.`user_id`
              WHERE o.`id` = ? AND o.`deleted_at` IS NULL
              LIMIT 1',
            [$id]
        );
    }

    /**
     * 过滤出「未删除」的订单 ID，用于批量操作前剔除无效 ID
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public static function existingIds(array $ids): array
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = Database::select(
            'SELECT `id` FROM `orders`
              WHERE `deleted_at` IS NULL AND `id` IN (' . $placeholders . ')',
            $ids
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * 订单状态流转日志（按时间正序）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function logs(int $orderId): array
    {
        return Database::select(
            'SELECT * FROM `order_logs` WHERE `order_id` = ? ORDER BY `id` ASC',
            [$orderId]
        );
    }

    /**
     * 查找该用户对某课程尚未过期、待支付的订单（用于避免重复下单）
     *
     * @return array<string, mixed>|null
     */
    public static function pendingForUserCourse(int $userId, int $courseId): ?array
    {
        return Database::first(
            'SELECT * FROM `orders`
              WHERE `user_id` = ? AND `course_id` = ?
                AND `status` IN (?, ?)
                AND (`expire_at` IS NULL OR `expire_at` > NOW())
                AND `deleted_at` IS NULL
              ORDER BY `id` DESC
              LIMIT 1',
            [$userId, $courseId, self::STATUS_PENDING, self::STATUS_PAYING]
        );
    }

    /**
     * 查找该用户对某套餐尚未过期、待支付的订单（用于避免重复下单）
     *
     * @return array<string, mixed>|null
     */
    public static function pendingForUserPackage(int $userId, int $packageId): ?array
    {
        return Database::first(
            'SELECT * FROM `orders`
              WHERE `user_id` = ? AND `package_id` = ?
                AND `status` IN (?, ?)
                AND (`expire_at` IS NULL OR `expire_at` > NOW())
                AND `deleted_at` IS NULL
              ORDER BY `id` DESC
              LIMIT 1',
            [$userId, $packageId, self::STATUS_PENDING, self::STATUS_PAYING]
        );
    }

    /**
     * 标记订单已支付并开通课程（幂等）
     *
     * 仅当订单当前处于 pending / paying 时才会写入，
     * 重复的支付回调不会重复开通课程、重复累计销量。
     *
     * @return bool 本次调用是否真正完成了状态流转
     */
    public static function markPaid(int $orderId, ?string $tradeNo, ?string $apiTradeNo, string $raw = ''): bool
    {
        $order = self::findById($orderId);

        if ($order === null) {
            return false;
        }

        return (bool) Database::transaction(static function () use ($order, $tradeNo, $apiTradeNo, $raw): bool {
            // 条件更新保证幂等：只有仍处于待支付/支付中的订单才会被更新
            $affected = Database::execute(
                'UPDATE `orders`
                    SET `status` = ?, `trade_no` = ?, `api_trade_no` = ?, `paid_at` = NOW(), `notify_raw` = ?
                  WHERE `id` = ? AND `status` IN (?, ?)',
                [
                    self::STATUS_PAID,
                    $tradeNo,
                    $apiTradeNo,
                    $raw,
                    (int) $order['id'],
                    self::STATUS_PENDING,
                    self::STATUS_PAYING,
                ]
            );

            if ($affected !== 1) {
                return false;
            }

            $userId    = (int) $order['user_id'];
            $packageId = (int) ($order['package_id'] ?? 0);

            if ($packageId > 0) {
                // 套餐订单：一次性开通套餐内全部课程，新增课程各自累计销量，套餐累计一次
                foreach (Package::courseIds($packageId) as $courseId) {
                    if (!Enrollment::exists($userId, $courseId)) {
                        Database::execute('UPDATE `courses` SET `sales_count` = `sales_count` + 1 WHERE `id` = ?', [$courseId]);
                    }

                    Enrollment::grant($userId, $courseId, (int) $order['id']);
                }

                Package::incrementSales($packageId);
                $remark = '支付成功，已开通套餐内课程';
            } else {
                $courseId = (int) $order['course_id'];

                // 首次开通才累计销量（enrollments 上有 (user_id, course_id) 唯一键）
                $firstGrant = !Enrollment::exists($userId, $courseId);

                Enrollment::grant($userId, $courseId, (int) $order['id']);

                if ($firstGrant) {
                    Database::execute('UPDATE `courses` SET `sales_count` = `sales_count` + 1 WHERE `id` = ?', [$courseId]);
                }

                $remark = '支付成功，已开通课程';
            }

            // 优惠券核销：状态流转已保证幂等，故此处对同一订单只会执行一次
            $couponId = (int) ($order['coupon_id'] ?? 0);
            if ($couponId > 0) {
                Coupon::redeem($couponId, $userId, (int) $order['id'], (int) $order['discount_amount']);
            }

            self::log(
                (int) $order['id'],
                (string) $order['order_no'],
                (string) $order['status'],
                self::STATUS_PAID,
                'system',
                $remark
            );

            return true;
        });
    }

    /**
     * 应用优惠券到订单（仅当订单仍待支付且尚未使用优惠券时生效）
     *
     * 禁止对「支付中」（paying）订单改价：此时网关可能已按原金额发起扣款，
     * 改价会导致回调金额校验失败、订单无法开通。
     *
     * 使用优惠券后，金额语义调整为：original_amount = 基准价（券前应付）、
     * discount_amount = 券抵扣额、amount = 基准价 - 抵扣额，保证「原价 - 优惠 = 应付」内部一致。
     *
     * @return bool 是否成功应用
     */
    public static function applyCoupon(int $orderId, int $couponId, int $baseAmount, int $amount, int $discount): bool
    {
        $affected = Database::execute(
            'UPDATE `orders`
                SET `coupon_id` = ?, `original_amount` = ?, `amount` = ?, `discount_amount` = ?
              WHERE `id` = ? AND `coupon_id` IS NULL AND `status` = ?',
            [$couponId, $baseAmount, $amount, $discount, $orderId, self::STATUS_PENDING]
        );

        return $affected === 1;
    }

    /**
     * 判断该用户是否已存在使用同一优惠券的其他未关闭订单
     *
     * 用于「应用阶段占用配额」：避免同一张券被同时应用到多笔待支付订单，
     * 从而在支付前重复享受折扣（核销阶段的限额校验此时尚未生效）。
     */
    public static function hasOpenCouponOrder(int $userId, int $couponId, int $excludeOrderId = 0): bool
    {
        $count = Database::scalar(
            'SELECT COUNT(*) FROM `orders`
              WHERE `user_id` = ? AND `coupon_id` = ? AND `status` IN (?, ?) AND `id` <> ?',
            [$userId, $couponId, self::STATUS_PENDING, self::STATUS_PAYING, $excludeOrderId]
        );

        return (int) $count > 0;
    }

    /**
     * 关闭订单
     */
    public static function close(int $orderId, string $remark = '订单超时未支付，已自动关闭'): void
    {
        $order = self::findById($orderId);

        if ($order === null) {
            return;
        }

        $affected = Database::execute(
            'UPDATE `orders` SET `status` = ?, `closed_at` = NOW()
              WHERE `id` = ? AND `status` IN (?, ?)',
            [self::STATUS_CLOSED, $orderId, self::STATUS_PENDING, self::STATUS_PAYING]
        );

        if ($affected === 1) {
            self::log($orderId, (string) $order['order_no'], (string) $order['status'], self::STATUS_CLOSED, 'system', $remark);
        }
    }

    /**
     * 后台：关闭订单（仅待支付 / 支付中）
     *
     * @return bool 本次调用是否真正完成了状态流转
     */
    public static function adminClose(int $orderId, string $operator, string $remark = '管理员手动关闭订单'): bool
    {
        $order = self::findById($orderId);

        if ($order === null) {
            return false;
        }

        $affected = Database::execute(
            'UPDATE `orders` SET `status` = ?, `closed_at` = NOW()
              WHERE `id` = ? AND `status` IN (?, ?)',
            [self::STATUS_CLOSED, $orderId, self::STATUS_PENDING, self::STATUS_PAYING]
        );

        if ($affected !== 1) {
            return false;
        }

        self::log($orderId, (string) $order['order_no'], (string) $order['status'], self::STATUS_CLOSED, $operator, $remark);

        return true;
    }

    /**
     * 后台：标记订单已完成（仅已支付订单）
     *
     * @return bool 本次调用是否真正完成了状态流转
     */
    public static function complete(int $orderId, string $operator, string $remark = '管理员确认订单完成'): bool
    {
        $order = self::findById($orderId);

        if ($order === null) {
            return false;
        }

        $affected = Database::execute(
            'UPDATE `orders` SET `status` = ? WHERE `id` = ? AND `status` = ?',
            [self::STATUS_COMPLETED, $orderId, self::STATUS_PAID]
        );

        if ($affected !== 1) {
            return false;
        }

        self::log($orderId, (string) $order['order_no'], (string) $order['status'], self::STATUS_COMPLETED, $operator, $remark);

        return true;
    }

    /**
     * 标记订单已退款并撤销课程授权（仅「已支付 / 已完成」订单可退款）
     *
     * 状态流转与授权撤销在同一事务内完成；授权撤销是幂等的
     * （enrollments 为软删除，重复调用不会报错）。
     *
     * @return bool 本次调用是否真正完成了退款流转
     */
    public static function markRefunded(int $orderId, int $amount, string $operator, string $remark = '管理员确认退款'): bool
    {
        $order = self::findById($orderId);

        if ($order === null) {
            return false;
        }

        return (bool) Database::transaction(static function () use ($order, $amount, $operator, $remark): bool {
            $affected = Database::execute(
                'UPDATE `orders`
                    SET `status` = ?, `refund_amount` = ?, `refunded_at` = NOW()
                  WHERE `id` = ? AND `status` IN (?, ?)',
                [
                    self::STATUS_REFUNDED,
                    max(0, $amount),
                    (int) $order['id'],
                    self::STATUS_PAID,
                    self::STATUS_COMPLETED,
                ]
            );

            if ($affected !== 1) {
                return false;
            }

            // 套餐订单：撤销套餐内全部课程授权；普通订单：撤销该课程授权
            $packageId = (int) ($order['package_id'] ?? 0);
            if ($packageId > 0) {
                foreach (Package::courseIds($packageId) as $courseId) {
                    Enrollment::revoke((int) $order['user_id'], $courseId);
                }
            } else {
                Enrollment::revoke((int) $order['user_id'], (int) $order['course_id']);
            }

            self::log(
                (int) $order['id'],
                (string) $order['order_no'],
                (string) $order['status'],
                self::STATUS_REFUNDED,
                $operator,
                $remark
            );

            return true;
        });
    }

    /**
     * 订单当前状态是否允许发起退款
     */
    public static function canRefund(string $status): bool
    {
        return in_array($status, [self::STATUS_PAID, self::STATUS_COMPLETED], true);
    }

    /**
     * 后台：软删除订单（保留数据，仅从列表隐藏）
     *
     * @return bool 本次调用是否真正完成了删除
     */
    public static function softDelete(int $orderId, string $operator, string $remark = '管理员删除订单'): bool
    {
        $order = self::findById($orderId);

        if ($order === null || $order['deleted_at'] !== null) {
            return false;
        }

        $affected = Database::execute(
            'UPDATE `orders` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$orderId]
        );

        if ($affected !== 1) {
            return false;
        }

        self::log($orderId, (string) $order['order_no'], (string) $order['status'], 'deleted', $operator, $remark);

        return true;
    }

    /**
     * 惰性关闭所有已超时的订单（无 cron，在各入口调用）
     *
     * @return int 被关闭的订单数
     */
    public static function closeExpired(): int
    {
        return Database::execute(
            'UPDATE `orders` SET `status` = ?, `closed_at` = NOW()
              WHERE `status` IN (?, ?) AND `expire_at` IS NOT NULL AND `expire_at` < NOW()',
            [self::STATUS_CLOSED, self::STATUS_PENDING, self::STATUS_PAYING]
        );
    }

    /**
     * 更新支付方式与状态为「支付中」
     */
    public static function markPaying(int $orderId, string $payType): void
    {
        Database::execute(
            'UPDATE `orders` SET `pay_type` = ?, `status` = ?
              WHERE `id` = ? AND `status` = ?',
            [$payType, self::STATUS_PAYING, $orderId, self::STATUS_PENDING]
        );
    }

    /**
     * 用户的订单列表
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(int $userId, int $limit, int $offset): array
    {
        return Database::select(
            'SELECT * FROM `orders`
              WHERE `user_id` = ? AND `deleted_at` IS NULL
              ORDER BY `id` DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$userId]
        );
    }

    public static function countForUser(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `orders` WHERE `user_id` = ? AND `deleted_at` IS NULL',
            [$userId]
        );
    }

    /**
     * 后台订单列表（含下单用户名）
     *
     * 支持的筛选：keyword（订单号/课程名/用户名）、status、pay_type、user_id、date_from、date_to
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        return Database::select(
            'SELECT o.*, u.`username` AS user_name
               FROM `orders` o
               LEFT JOIN `users` u ON u.`id` = o.`user_id`
              WHERE ' . $where . '
              ORDER BY o.`id` DESC
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
            'SELECT COUNT(*) FROM `orders` o
               LEFT JOIN `users` u ON u.`id` = o.`user_id`
              WHERE ' . $where,
            $bindings
        );
    }

    /**
     * 后台订单导出（含下单用户名，不参与分页）
     *
     * 与 adminList 共用筛选条件，最多导出 MAX_EXPORT_ROWS 条，防止超大结果集耗尽内存。
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function adminExportRows(array $filters = []): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        return Database::select(
            'SELECT o.*, u.`username` AS user_name
               FROM `orders` o
               LEFT JOIN `users` u ON u.`id` = o.`user_id`
              WHERE ' . $where . '
              ORDER BY o.`id` DESC
              LIMIT ' . self::MAX_EXPORT_ROWS,
            $bindings
        );
    }

    /**
     * 已支付（含已完成）订单金额合计（分）
     */
    public static function paidAmountTotal(?string $from = null, ?string $to = null): int
    {
        $conditions = ['`deleted_at` IS NULL', '`status` IN (?, ?)'];
        $bindings   = [self::STATUS_PAID, self::STATUS_COMPLETED];

        if ($from !== null && $from !== '') {
            $conditions[] = '`paid_at` >= ?';
            $bindings[]   = $from . ' 00:00:00';
        }

        if ($to !== null && $to !== '') {
            $conditions[] = '`paid_at` <= ?';
            $bindings[]   = $to . ' 23:59:59';
        }

        return (int) Database::scalar(
            'SELECT COALESCE(SUM(`amount`), 0) FROM `orders` WHERE ' . implode(' AND ', $conditions),
            $bindings
        );
    }

    /**
     * 某用户已支付（含已完成）订单金额合计（分）
     */
    public static function paidAmountForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) Database::scalar(
            'SELECT COALESCE(SUM(`amount`), 0) FROM `orders`
              WHERE `user_id` = ? AND `deleted_at` IS NULL AND `status` IN (?, ?)',
            [$userId, self::STATUS_PAID, self::STATUS_COMPLETED]
        );
    }

    /**
     * 按状态统计订单数
     */
    public static function countByStatus(string $status): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `orders` WHERE `status` = ? AND `deleted_at` IS NULL',
            [$status]
        );
    }

    /**
     * 组装后台列表的 WHERE 条件
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function adminWhere(array $filters): array
    {
        $conditions = ['o.`deleted_at` IS NULL'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = '(o.`order_no` LIKE ? OR o.`course_title` LIKE ? OR u.`username` LIKE ?)';
            $like         = Search::likePattern($keyword);
            $bindings[]   = $like;
            $bindings[]   = $like;
            $bindings[]   = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $conditions[] = 'o.`status` = ?';
            $bindings[]   = $status;
        }

        $payType = (string) ($filters['pay_type'] ?? '');
        if ($payType !== '') {
            $conditions[] = 'o.`pay_type` = ?';
            $bindings[]   = $payType;
        }

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $conditions[] = 'o.`user_id` = ?';
            $bindings[]   = $userId;
        }

        $from = (string) ($filters['date_from'] ?? '');
        if ($from !== '') {
            $conditions[] = 'o.`created_at` >= ?';
            $bindings[]   = $from . ' 00:00:00';
        }

        $to = (string) ($filters['date_to'] ?? '');
        if ($to !== '') {
            $conditions[] = 'o.`created_at` <= ?';
            $bindings[]   = $to . ' 23:59:59';
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    public static function label(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /**
     * 写入订单状态流转日志
     */
    public static function log(
        int $orderId,
        string $orderNo,
        string $fromStatus,
        string $toStatus,
        string $operator = 'system',
        string $remark = ''
    ): void {
        Database::execute(
            'INSERT INTO `order_logs` (`order_id`, `order_no`, `from_status`, `to_status`, `operator`, `remark`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$orderId, $orderNo, $fromStatus, $toStatus, $operator, $remark]
        );
    }
}
