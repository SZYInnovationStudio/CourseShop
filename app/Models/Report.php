<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 经营报表
 *
 * 统计口径（以「曾收款」为准，避免订单被软删除或状态流转后营收凭空消失）：
 * - 营收：凡 paid_at 落在区间的订单（不论当前状态与是否软删除）按支付时间归集
 * - 退款：凡 refunded_at 落在区间的订单，按退款时间归集
 * - 订单数：按下单时间归集
 * 金额一律为「分」（int）。
 */
final class Report
{
    public const RANGE_TODAY = 'today';

    /** @var array<string, string> 时间范围预设 */
    public const RANGES = [
        'today'  => '今日',
        '7d'     => '近 7 天',
        '30d'    => '近 30 天',
        '90d'    => '近 90 天',
        'custom' => '自定义',
    ];

    /** 趋势表最大天数，防止超大区间生成过多行 */
    public const MAX_TREND_DAYS = 366;

    /**
     * 概览指标
     *
     * @return array<string, int>
     */
    public static function summary(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);

        $paidOrders = (int) Database::scalar(
            'SELECT COUNT(*) FROM `orders`
              WHERE `paid_at` >= ? AND `paid_at` <= ?',
            [$start, $end]
        );

        $revenue = (int) Database::scalar(
            'SELECT COALESCE(SUM(`amount`), 0) FROM `orders`
              WHERE `paid_at` >= ? AND `paid_at` <= ?',
            [$start, $end]
        );

        $createdOrders = (int) Database::scalar(
            'SELECT COUNT(*) FROM `orders`
              WHERE `deleted_at` IS NULL AND `created_at` >= ? AND `created_at` <= ?',
            [$start, $end]
        );

        $refundOrders = (int) Database::scalar(
            'SELECT COUNT(*) FROM `orders`
              WHERE `refunded_at` >= ? AND `refunded_at` <= ?',
            [$start, $end]
        );

        $refundAmount = (int) Database::scalar(
            'SELECT COALESCE(SUM(`refund_amount`), 0) FROM `orders`
              WHERE `refunded_at` >= ? AND `refunded_at` <= ?',
            [$start, $end]
        );

        $newUsers = (int) Database::scalar(
            'SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL AND `created_at` >= ? AND `created_at` <= ?',
            [$start, $end]
        );

        return [
            'revenue'        => $revenue,
            // 允许为负：退款可能多于区间营收（例如退款集中在某一天），截负会掩盖真实经营状况
            'net_revenue'    => $revenue - $refundAmount,
            'paid_orders'    => $paidOrders,
            'created_orders' => $createdOrders,
            'refund_orders'  => $refundOrders,
            'refund_amount'  => $refundAmount,
            'new_users'      => $newUsers,
            // 客单价：仅统计已支付订单
            'avg_order'      => $paidOrders > 0 ? intdiv($revenue, $paidOrders) : 0,
        ];
    }

    /**
     * 按日趋势（营收 + 已支付订单数），区间内无数据的日期补零
     *
     * @return array<int, array{date: string, orders: int, revenue: int}>
     */
    public static function dailyTrend(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);

        $rows = Database::select(
            'SELECT DATE(`paid_at`) AS `day`, COUNT(*) AS `orders`, COALESCE(SUM(`amount`), 0) AS `revenue`
               FROM `orders`
              WHERE `paid_at` >= ? AND `paid_at` <= ?
              GROUP BY DATE(`paid_at`)',
            [$start, $end]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['day']] = [
                'orders'  => (int) $row['orders'],
                'revenue' => (int) $row['revenue'],
            ];
        }

        $trend = [];
        $cursor = strtotime($from);
        $last   = strtotime($to);
        $guard  = 0;

        while ($cursor !== false && $last !== false && $cursor <= $last && $guard < self::MAX_TREND_DAYS) {
            $day = date('Y-m-d', $cursor);
            $trend[] = [
                'date'    => $day,
                'orders'  => $map[$day]['orders'] ?? 0,
                'revenue' => $map[$day]['revenue'] ?? 0,
            ];

            $cursor = strtotime('+1 day', $cursor);
            $guard++;
        }

        return $trend;
    }

    /**
     * 按日退款统计，区间内无数据的日期补零
     *
     * @return array<int, array{date: string, orders: int, amount: int}>
     */
    public static function refundTrend(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);

        $rows = Database::select(
            'SELECT DATE(`refunded_at`) AS `day`, COUNT(*) AS `orders`, COALESCE(SUM(`refund_amount`), 0) AS `amount`
               FROM `orders`
              WHERE `refunded_at` >= ? AND `refunded_at` <= ?
              GROUP BY DATE(`refunded_at`)',
            [$start, $end]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['day']] = [
                'orders' => (int) $row['orders'],
                'amount' => (int) $row['amount'],
            ];
        }

        $trend  = [];
        $cursor = strtotime($from);
        $last   = strtotime($to);
        $guard  = 0;

        while ($cursor !== false && $last !== false && $cursor <= $last && $guard < self::MAX_TREND_DAYS) {
            $day = date('Y-m-d', $cursor);
            $trend[] = [
                'date'   => $day,
                'orders' => $map[$day]['orders'] ?? 0,
                'amount' => $map[$day]['amount'] ?? 0,
            ];

            $cursor = strtotime('+1 day', $cursor);
            $guard++;
        }

        return $trend;
    }

    /**
     * 课程销售排行（按已支付金额降序）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function topCourses(string $from, string $to, int $limit = 10): array
    {
        [$start, $end] = self::bounds($from, $to);

        return Database::select(
            'SELECT o.`course_id`, o.`package_id`, MAX(o.`course_title`) AS `course_title`,
                    COUNT(*) AS `orders`, COALESCE(SUM(o.`amount`), 0) AS `revenue`
               FROM `orders` o
              WHERE o.`paid_at` >= ? AND o.`paid_at` <= ?
              GROUP BY o.`course_id`, o.`package_id`
              ORDER BY `revenue` DESC, `orders` DESC
              LIMIT ' . max(1, $limit),
            [$start, $end]
        );
    }

    /**
     * 用户消费排行（按已支付金额降序）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function topUsers(string $from, string $to, int $limit = 10): array
    {
        [$start, $end] = self::bounds($from, $to);

        return Database::select(
            'SELECT o.`user_id`, u.`username`, u.`nickname`,
                    COUNT(*) AS `orders`, COALESCE(SUM(o.`amount`), 0) AS `revenue`
               FROM `orders` o
               LEFT JOIN `users` u ON u.`id` = o.`user_id`
              WHERE o.`paid_at` >= ? AND o.`paid_at` <= ?
              GROUP BY o.`user_id`, u.`username`, u.`nickname`
              ORDER BY `revenue` DESC, `orders` DESC
              LIMIT ' . max(1, $limit),
            [$start, $end]
        );
    }

    /**
     * 支付方式分布
     *
     * @return array<int, array<string, mixed>>
     */
    public static function payTypeBreakdown(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);

        return Database::select(
            'SELECT `pay_type`, COUNT(*) AS `orders`, COALESCE(SUM(`amount`), 0) AS `revenue`
               FROM `orders`
              WHERE `paid_at` >= ? AND `paid_at` <= ?
              GROUP BY `pay_type`
              ORDER BY `revenue` DESC',
            [$start, $end]
        );
    }

    /**
     * 优惠券使用情况（总量 + 优惠码排行）
     *
     * @return array{count: int, discount: int, top: array<int, array<string, mixed>>}
     */
    public static function couponStats(string $from, string $to, int $limit = 10): array
    {
        [$start, $end] = self::bounds($from, $to);

        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM `coupon_usages` WHERE `created_at` >= ? AND `created_at` <= ?',
            [$start, $end]
        );

        $discount = (int) Database::scalar(
            'SELECT COALESCE(SUM(`amount`), 0) FROM `coupon_usages` WHERE `created_at` >= ? AND `created_at` <= ?',
            [$start, $end]
        );

        $top = Database::select(
            'SELECT cu.`coupon_id`, MAX(c.`code`) AS `code`, MAX(c.`name`) AS `name`,
                    COUNT(*) AS `used`, COALESCE(SUM(cu.`amount`), 0) AS `discount`
               FROM `coupon_usages` cu
               LEFT JOIN `coupons` c ON c.`id` = cu.`coupon_id`
              WHERE cu.`created_at` >= ? AND cu.`created_at` <= ?
              GROUP BY cu.`coupon_id`
              ORDER BY `used` DESC, `discount` DESC
              LIMIT ' . max(1, $limit),
            [$start, $end]
        );

        return [
            'count'    => $count,
            'discount' => $discount,
            'top'      => $top,
        ];
    }

    /**
     * 解析时间范围参数，返回 [区间标识, 起始日期, 结束日期]
     *
     * 非法输入或起止倒置时回退为「今日」，避免出现空区间。
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function resolveRange(string $range, string $dateFrom = '', string $dateTo = ''): array
    {
        if (!array_key_exists($range, self::RANGES)) {
            $range = self::RANGE_TODAY;
        }

        $today = date('Y-m-d');

        switch ($range) {
            case '7d':
                return ['7d', date('Y-m-d', strtotime('-6 days')), $today];

            case '30d':
                return ['30d', date('Y-m-d', strtotime('-29 days')), $today];

            case '90d':
                return ['90d', date('Y-m-d', strtotime('-89 days')), $today];

            case 'custom':
                $from = self::normalizeDate($dateFrom);
                $to   = self::normalizeDate($dateTo);

                if ($from === null || $to === null || $from > $to) {
                    return [self::RANGE_TODAY, $today, $today];
                }

                return ['custom', $from, $to];

            case self::RANGE_TODAY:
            default:
                return [self::RANGE_TODAY, $today, $today];
        }
    }

    /**
     * 校验 Y-m-d 日期字符串，非法返回 null
     */
    private static function normalizeDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false || date('Y-m-d', $timestamp) !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * 将 Y-m-d 区间转换为 [起始时间, 结束时间]
     *
     * @return array{0: string, 1: string}
     */
    private static function bounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }
}
