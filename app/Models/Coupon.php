<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Search;

/**
 * 优惠券
 *
 * 金额一律使用「分」（整数）。type：
 *  - fixed   满减券：value 为减免金额（分）
 *  - percent 折扣券：value 为减免百分比（1-99，例如 20 表示立减 20%）
 *
 * 使用时机：下单时「选择」优惠券（写入订单 coupon_id / discount_amount），
 * 支付成功后再核销（写 coupon_usages 并累加 used_quantity）。
 *
 * 配额占用采用「隐式预留」：订单在 pending / paying 期间持有 coupon_id，即视为占用了
 * 一张券的名额（见 reservedCount）。应用优惠券时以 Coupon::reserve 原子校验总量与
 * 每人限用，避免多笔未支付订单重复占用最后一张券；订单关闭后状态离开 pending / paying，
 * 预留自动释放，无需额外维护计数。
 */
final class Coupon
{
    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENT = 'percent';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_FIXED   => '满减券',
        self::TYPE_PERCENT => '折扣券',
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    // ============================================================
    // 后台管理
    // ============================================================

    /**
     * 后台优惠券列表（含停用，最新在前）
     *
     * @param  array<string, mixed> $filters keyword（码 / 名称）、type、is_active
     * @return array<int, array<string, mixed>>
     */
    public static function adminAll(array $filters = []): array
    {
        $conditions = ['c.`deleted_at` IS NULL'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = '(c.`code` LIKE ? OR c.`name` LIKE ?)';
            $like         = Search::likePattern($keyword);
            $bindings[]   = $like;
            $bindings[]   = $like;
        }

        $type = (string) ($filters['type'] ?? '');
        if ($type !== '' && isset(self::TYPE_LABELS[$type])) {
            $conditions[] = 'c.`type` = ?';
            $bindings[]   = $type;
        }

        $isActive = $filters['is_active'] ?? null;
        if ($isActive === '0' || $isActive === '1' || $isActive === 0 || $isActive === 1) {
            $conditions[] = 'c.`is_active` = ?';
            $bindings[]   = (int) $isActive;
        }

        return Database::select(
            'SELECT c.*, co.`title` AS course_title
               FROM `coupons` c
               LEFT JOIN `courses` co ON co.`id` = c.`course_id` AND co.`deleted_at` IS NULL
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY c.`id` DESC',
            $bindings
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
            'SELECT * FROM `coupons` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO `coupons`
                (`code`, `name`, `type`, `value`, `min_amount`, `course_id`,
                 `total_quantity`, `per_user_limit`, `start_at`, `end_at`, `is_active`,
                 `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (string) $data['code'],
                (string) $data['name'],
                (string) $data['type'],
                (int) $data['value'],
                (int) $data['min_amount'],
                $data['course_id'] ?? null,
                (int) $data['total_quantity'],
                (int) $data['per_user_limit'],
                $data['start_at'] ?? null,
                $data['end_at'] ?? null,
                (int) $data['is_active'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE `coupons`
                SET `code` = ?, `name` = ?, `type` = ?, `value` = ?, `min_amount` = ?,
                    `course_id` = ?, `total_quantity` = ?, `per_user_limit` = ?,
                    `start_at` = ?, `end_at` = ?, `is_active` = ?
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [
                (string) $data['code'],
                (string) $data['name'],
                (string) $data['type'],
                (int) $data['value'],
                (int) $data['min_amount'],
                $data['course_id'] ?? null,
                (int) $data['total_quantity'],
                (int) $data['per_user_limit'],
                $data['start_at'] ?? null,
                $data['end_at'] ?? null,
                (int) $data['is_active'],
                $id,
            ]
        );
    }

    /**
     * 软删除优惠券
     */
    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE `coupons` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        );
    }

    /**
     * 优惠码是否已被占用（用于新增 / 编辑时校验唯一性）
     */
    public static function codeExists(string $code, int $exceptId = 0): bool
    {
        return Database::first(
            'SELECT `id` FROM `coupons` WHERE `code` = ? AND `deleted_at` IS NULL AND `id` <> ? LIMIT 1',
            [$code, $exceptId]
        ) !== null;
    }

    /**
     * 优惠规则的可读描述，例如「满 100.00 元减 20.00 元」「满 100.00 元享 8 折」
     *
     * @param array<string, mixed> $coupon
     */
    public static function ruleText(array $coupon): string
    {
        $minAmount = (int) $coupon['min_amount'];
        $prefix    = $minAmount > 0 ? '满 ' . format_money($minAmount) . ' 元' : '不限金额';

        if ((string) $coupon['type'] === self::TYPE_PERCENT) {
            $payRate = 100 - min(99, max(0, (int) $coupon['value']));
            $rate    = rtrim(rtrim(number_format($payRate / 10, 1, '.', ''), '0'), '.');

            return $prefix . '享 ' . $rate . ' 折';
        }

        return $prefix . '减 ' . format_money((int) $coupon['value']) . ' 元';
    }

    // ============================================================
    // 下单使用
    // ============================================================

    /**
     * 按优惠码查询「未删除」的优惠券
     *
     * @return array<string, mixed>|null
     */
    public static function findByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return Database::first(
            'SELECT * FROM `coupons` WHERE `code` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$code]
        );
    }

    /**
     * 计算优惠券对指定金额的抵扣额（分），不校验使用条件
     *
     * @param array<string, mixed> $coupon
     */
    public static function discountFor(array $coupon, int $baseAmount): int
    {
        $baseAmount = max(0, $baseAmount);
        if ($baseAmount === 0) {
            return 0;
        }

        $type  = (string) $coupon['type'];
        $value = max(0, (int) $coupon['value']);

        if ($type === self::TYPE_PERCENT) {
            $value    = min(99, $value);
            $discount = (int) floor($baseAmount * $value / 100);
        } else {
            $discount = $value;
        }

        // 抵扣额不得超过订单金额，保证实付不为负
        return min($discount, $baseAmount);
    }

    /**
     * 校验优惠券是否可用于该用户 / 该课程 / 该金额
     *
     * @param  array<string, mixed> $coupon
     * @return string|null 可用返回 null，否则返回错误提示
     */
    public static function usableError(array $coupon, int $userId, int $courseId, int $baseAmount): ?string
    {
        if ((int) $coupon['is_active'] !== 1) {
            return t('该优惠券已停用。');
        }

        $now = time();

        if (!empty($coupon['start_at']) && strtotime((string) $coupon['start_at']) > $now) {
            return t('该优惠券尚未开始使用。');
        }

        if (!empty($coupon['end_at']) && strtotime((string) $coupon['end_at']) < $now) {
            return t('该优惠券已过期。');
        }

        if ($baseAmount < (int) $coupon['min_amount']) {
            return t('订单金额未满 %s 元，无法使用该优惠券。', [format_money((int) $coupon['min_amount'])]);
        }

        $limitCourseId = (int) ($coupon['course_id'] ?? 0);
        if ($limitCourseId > 0 && $limitCourseId !== $courseId) {
            return t('该优惠券仅限指定课程使用。');
        }

        // 总量校验须计入「未支付订单已占用」的名额，否则多用户可在支付前抢占最后一张券（CS-07）
        $totalQuantity = (int) $coupon['total_quantity'];
        if ($totalQuantity > 0
            && (int) $coupon['used_quantity'] + self::reservedCount((int) $coupon['id']) >= $totalQuantity
        ) {
            return t('该优惠券已被领完。');
        }

        $perUserLimit = (int) $coupon['per_user_limit'];
        if ($perUserLimit > 0 && self::usageCount((int) $coupon['id'], $userId) >= $perUserLimit) {
            return t('你已使用过该优惠券。');
        }

        return null;
    }

    /**
     * 某用户对某优惠券的已核销次数
     */
    public static function usageCount(int $couponId, int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `coupon_usages` WHERE `coupon_id` = ? AND `user_id` = ?',
            [$couponId, $userId]
        );
    }

    /**
     * 该优惠券当前被「未支付 / 支付中」订单占用的名额数（隐式预留）
     *
     * @param int $excludeOrderId 排除的订单 ID（应用优惠券时排除当前订单）
     */
    public static function reservedCount(int $couponId, int $excludeOrderId = 0): int
    {
        if ($couponId <= 0) {
            return 0;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `orders`
              WHERE `coupon_id` = ? AND `status` IN (?, ?) AND `id` <> ? AND `deleted_at` IS NULL',
            [$couponId, Order::STATUS_PENDING, Order::STATUS_PAYING, $excludeOrderId]
        );
    }

    /**
     * 某用户以「未支付 / 支付中」订单占用该优惠券的名额数
     */
    public static function reservedCountForUser(int $couponId, int $userId, int $excludeOrderId = 0): int
    {
        if ($couponId <= 0 || $userId <= 0) {
            return 0;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `orders`
              WHERE `coupon_id` = ? AND `user_id` = ? AND `status` IN (?, ?)
                AND `id` <> ? AND `deleted_at` IS NULL',
            [$couponId, $userId, Order::STATUS_PENDING, Order::STATUS_PAYING, $excludeOrderId]
        );
    }

    /**
     * 应用优惠券时原子占用配额（隐式预留）
     *
     * 必须在 Order::applyCoupon 的事务内调用：事务内对优惠券行加锁
     * （SELECT ... FOR UPDATE），串行化同一优惠券的并发应用，并复核发放总量与每人限用次数
     * （计入其它未支付订单已占用的名额）。预留本身不写库——订单在 pending / paying 期间
     * 持有 coupon_id 即视为占用，订单关闭后自动释放。
     *
     * @return bool 是否成功占用（false 表示名额已满 / 超出每人限用 / 券不存在）
     */
    public static function reserve(int $couponId, int $userId, int $orderId): bool
    {
        if ($couponId <= 0) {
            return false;
        }

        return (bool) Database::transaction(static function () use ($couponId, $userId, $orderId): bool {
            $coupon = Database::first(
                'SELECT * FROM `coupons` WHERE `id` = ? AND `deleted_at` IS NULL FOR UPDATE',
                [$couponId]
            );

            if ($coupon === null) {
                return false;
            }

            // 复核发放总量：已核销 + 其它订单已占用 + 本单，不得超过总量
            $totalQuantity = (int) $coupon['total_quantity'];
            if ($totalQuantity > 0
                && (int) $coupon['used_quantity'] + self::reservedCount($couponId, $orderId) >= $totalQuantity
            ) {
                return false;
            }

            // 复核每人限用次数：已核销 + 该用户其它订单已占用 + 本单
            $perUserLimit = (int) $coupon['per_user_limit'];
            if ($perUserLimit > 0) {
                $usedByUser = (int) Database::scalar(
                    'SELECT COUNT(*) FROM `coupon_usages` WHERE `coupon_id` = ? AND `user_id` = ?',
                    [$couponId, $userId]
                );
                if ($usedByUser + self::reservedCountForUser($couponId, $userId, $orderId) >= $perUserLimit) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * 核销优惠券：写入使用记录并累加已用数量
     *
     * 应在订单支付成功后调用。订单状态流转本身已保证幂等；同一订单重复核销时直接视为成功。
     * 并发安全：事务内对优惠券行加锁（SELECT ... FOR UPDATE）并复核发放上限。
     *
     * @return bool 是否核销成功（false 表示券已发完等异常，调用方应告警但不得阻断订单开通）
     */
    public static function redeem(int $couponId, int $userId, int $orderId, int $discount): bool
    {
        if ($couponId <= 0) {
            return true;
        }

        return (bool) Database::transaction(static function () use ($couponId, $userId, $orderId, $discount): bool {
            // 锁定优惠券行，串行化同一优惠券的核销
            $coupon = Database::first(
                'SELECT * FROM `coupons` WHERE `id` = ? FOR UPDATE',
                [$couponId]
            );

            if ($coupon === null) {
                return false;
            }

            // 幂等保护：同一订单对同一优惠券只核销一次
            $alreadyUsed = (int) Database::scalar(
                'SELECT COUNT(*) FROM `coupon_usages` WHERE `coupon_id` = ? AND `order_id` = ?',
                [$couponId, $orderId]
            );
            if ($alreadyUsed > 0) {
                return true;
            }

            // 复核发放总量，已发完则核销失败（应用阶段已预留，正常不会触发）
            $totalQuantity = (int) $coupon['total_quantity'];
            if ($totalQuantity > 0 && (int) $coupon['used_quantity'] >= $totalQuantity) {
                return false;
            }

            Database::execute(
                'INSERT INTO `coupon_usages` (`coupon_id`, `user_id`, `order_id`, `amount`, `created_at`)
                 VALUES (?, ?, ?, ?, NOW())',
                [$couponId, $userId, $orderId, max(0, $discount)]
            );

            Database::execute(
                'UPDATE `coupons` SET `used_quantity` = `used_quantity` + 1 WHERE `id` = ?',
                [$couponId]
            );

            return true;
        });
    }

    /**
     * 统计某优惠券的核销总额（分），用于后台展示
     */
    public static function redeemedAmount(int $couponId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(SUM(`amount`), 0) FROM `coupon_usages` WHERE `coupon_id` = ?',
            [$couponId]
        );
    }
}
