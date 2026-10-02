<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Order;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Request;

/**
 * 后台仪表盘
 *
 * 展示站点概览指标、区间统计与最近的订单。
 */
final class DashboardController extends AdminController
{
    /** @var array<string, string> 时间范围预设 */
    private const RANGES = [
        'today'  => '今日',
        '7d'     => '近 7 天',
        '30d'    => '近 30 天',
        'custom' => '自定义',
    ];

    public function index(): void
    {
        // 惰性关闭超时订单（项目无 cron，在各入口调用）
        Order::closeExpired();

        $today = date('Y-m-d');

        [$range, $from, $to] = self::resolveRange();

        $stats = [
            'users_total'       => (int) Database::scalar(
                'SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL'
            ),
            'users_today'       => (int) Database::scalar(
                'SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL AND `created_at` >= CURDATE()'
            ),
            'courses_total'     => (int) Database::scalar(
                'SELECT COUNT(*) FROM `courses` WHERE `deleted_at` IS NULL'
            ),
            'courses_published' => (int) Database::scalar(
                "SELECT COUNT(*) FROM `courses` WHERE `deleted_at` IS NULL AND `status` = 'published'"
            ),
            'orders_total'      => (int) Database::scalar(
                'SELECT COUNT(*) FROM `orders` WHERE `deleted_at` IS NULL'
            ),
            'orders_today'      => (int) Database::scalar(
                'SELECT COUNT(*) FROM `orders` WHERE `deleted_at` IS NULL AND `created_at` >= CURDATE()'
            ),
            'orders_pending'    => (int) Database::scalar(
                'SELECT COUNT(*) FROM `orders` WHERE `deleted_at` IS NULL AND `status` IN (?, ?)',
                [Order::STATUS_PENDING, Order::STATUS_PAYING]
            ),
            'sales_total'       => Order::paidAmountTotal(),
            'sales_today'       => Order::paidAmountTotal($today, $today),
        ];

        // 区间统计：用户按注册时间、订单按下单时间、销售额按支付时间
        $period = [
            'users'  => (int) Database::scalar(
                'SELECT COUNT(*) FROM `users`
                  WHERE `deleted_at` IS NULL AND `created_at` >= ? AND `created_at` <= ?',
                [$from . ' 00:00:00', $to . ' 23:59:59']
            ),
            'orders' => (int) Database::scalar(
                'SELECT COUNT(*) FROM `orders`
                  WHERE `deleted_at` IS NULL AND `created_at` >= ? AND `created_at` <= ?',
                [$from . ' 00:00:00', $to . ' 23:59:59']
            ),
            'sales'  => Order::paidAmountTotal($from, $to),
        ];

        $this->view('admin.dashboard.index', [
            'pageTitle'    => '仪表盘',
            'admin'        => Auth::user(),
            'stats'        => $stats,
            'period'       => $period,
            'range'        => $range,
            'ranges'       => self::RANGES,
            'dateFrom'     => $from,
            'dateTo'       => $to,
            'recentOrders' => Order::adminList([], 8, 0),
        ]);
    }

    /**
     * 解析时间范围参数
     *
     * @return array{0: string, 1: string, 2: string} [range, from(Y-m-d), to(Y-m-d)]
     */
    private static function resolveRange(): array
    {
        $range = Request::string('range', 'today');

        if (!array_key_exists($range, self::RANGES)) {
            $range = 'today';
        }

        $today = date('Y-m-d');

        switch ($range) {
            case '7d':
                return ['7d', date('Y-m-d', strtotime('-6 days')), $today];

            case '30d':
                return ['30d', date('Y-m-d', strtotime('-29 days')), $today];

            case 'custom':
                $from = self::normalizeDate(Request::string('date_from'));
                $to   = self::normalizeDate(Request::string('date_to'));

                // 起止缺失或倒置时回退为今日，避免出现空区间
                if ($from === null || $to === null || $from > $to) {
                    return ['today', $today, $today];
                }

                return ['custom', $from, $to];

            case 'today':
            default:
                return ['today', $today, $today];
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
}
