<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\Refund;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Csv;
use App\Support\OrderNotifier;
use App\Support\Payment\PaymentManager;
use App\Support\Request;

/**
 * 后台订单管理
 *
 * 提供订单列表与筛选、订单详情（含状态流转日志）、手动关闭、确认完成与软删除。
 * 订单金额一律使用「分」，展示时统一通过 price_html / format_money 格式化。
 */
final class OrderController extends AdminController
{
    /** 每页条数 */
    private const PER_PAGE = 20;

    /** @var array<string, string> 支付方式展示名 */
    private const PAY_TYPE_LABELS = [
        'wxpay'  => '微信支付',
        'alipay' => '支付宝',
        'free'   => '免费开通',
    ];

    /**
     * 订单列表
     */
    public function index(): void
    {
        $filters = self::filters();

        // 惰性关闭超时订单，保证列表状态准确
        Order::closeExpired();

        $total      = Order::adminCount($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min(max(1, Request::int('page', 1)), $totalPages);

        $this->view('admin.orders.index', [
            'pageTitle'  => '订单管理',
            'filters'    => $filters,
            'orders'     => Order::adminList($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'statuses'   => Order::STATUS_LABELS,
            'payTypes'   => self::PAY_TYPE_LABELS,
        ]);
    }

    /**
     * 导出订单 CSV（沿用列表当前筛选条件）
     */
    public function export(): void
    {
        // 惰性关闭超时订单，保证导出状态与列表一致
        Order::closeExpired();

        $filters = self::filters();
        $rows    = Order::adminExportRows($filters);

        $headers = [
            'ID', '订单号', '用户', '课程', '应付金额(元)', '原价(元)', '优惠(元)',
            '支付方式', '状态', '下单时间', '支付时间', '关闭时间',
        ];

        $data = [];

        foreach ($rows as $row) {
            $data[] = [
                (int) $row['id'],
                (string) $row['order_no'],
                (string) ($row['user_name'] ?? ''),
                (string) $row['course_title'],
                self::yuan((int) $row['amount']),
                self::yuan((int) ($row['original_amount'] ?? 0)),
                self::yuan((int) ($row['discount_amount'] ?? 0)),
                self::PAY_TYPE_LABELS[(string) ($row['pay_type'] ?? '')] ?? (string) ($row['pay_type'] ?? ''),
                Order::label((string) $row['status']),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['paid_at'] ?? ''),
                (string) ($row['closed_at'] ?? ''),
            ];
        }

        Csv::download('orders_' . date('Ymd_His') . '.csv', $headers, $data);
    }

    /**
     * 解析并归一化列表筛选参数（列表与导出共用）
     *
     * @return array<string, string>
     */
    private static function filters(): array
    {
        $filters = [
            'keyword'   => Request::string('keyword'),
            'status'    => Request::string('status'),
            'pay_type'  => Request::string('pay_type'),
            'date_from' => Request::string('date_from'),
            'date_to'   => Request::string('date_to'),
        ];

        // 归一化，避免非法值进入 SQL 条件拼装
        if (!array_key_exists($filters['status'], Order::STATUS_LABELS)) {
            $filters['status'] = '';
        }
        if (!array_key_exists($filters['pay_type'], self::PAY_TYPE_LABELS)) {
            $filters['pay_type'] = '';
        }

        return $filters;
    }

    /**
     * 分（整数）转元（保留两位小数字符串，供 CSV 导出使用）
     */
    private static function yuan(int $fen): string
    {
        return number_format($fen / 100, 2, '.', '');
    }

    /**
     * 订单详情（含状态流转日志）
     */
    public function show(string $id): void
    {
        $order = Order::adminFind((int) $id);

        if ($order === null) {
            $this->fail(url('/admin/orders'), '订单不存在或已被删除。');
        }

        $status   = (string) $order['status'];
        $pending  = Refund::pendingByOrder((int) $order['id']);

        $this->view('admin.orders.show', [
            'pageTitle'    => '订单详情',
            'order'        => $order,
            'statusLabel'  => Order::label($status),
            'statusBadges' => self::STATUS_BADGES,
            'payTypeLabel' => self::PAY_TYPE_LABELS,
            'logs'         => Order::logs((int) $order['id']),
            'canClose'     => in_array($status, [Order::STATUS_PENDING, Order::STATUS_PAYING], true),
            'canComplete'  => $status === Order::STATUS_PAID,
            // 退款：仅「已支付 / 已完成」且有权限时可发起，且不能重复发起
            'canRefund'    => Auth::can('order.refund') && Order::canRefund($status) && $pending === null,
            'canReconcile' => in_array($status, [Order::STATUS_PENDING, Order::STATUS_PAYING], true),
            'pendingRefund' => $pending,
            'latestRefund'  => Refund::findByOrder((int) $order['id']),
        ]);
    }

    /**
     * 手动关闭订单（仅待支付 / 支付中）
     */
    public function close(string $id): void
    {
        Csrf::check();

        $orderId = (int) $id;
        $order   = $this->requireActiveOrder($orderId);

        if (!Order::adminClose($orderId, self::operator(), '管理员手动关闭订单')) {
            $this->fail(url('/admin/orders/' . $orderId), '当前状态的订单无法关闭。');
        }

        Log::recordOperation('order.close', 'order', $orderId, ['order_no' => (string) $order['order_no']]);

        $this->success(url('/admin/orders/' . $orderId), '订单已关闭。');
    }

    /**
     * 确认订单完成（仅已支付订单）
     */
    public function complete(string $id): void
    {
        Csrf::check();

        $orderId = (int) $id;
        $order   = $this->requireActiveOrder($orderId);

        if (!Order::complete($orderId, self::operator(), '管理员确认订单完成')) {
            $this->fail(url('/admin/orders/' . $orderId), '仅「已支付」的订单可以标记为完成。');
        }

        OrderNotifier::notifyCompleted($order);

        Log::recordOperation('order.complete', 'order', $orderId, ['order_no' => (string) $order['order_no']]);

        $this->success(url('/admin/orders/' . $orderId), '订单已标记为完成。');
    }

    /**
     * 对账：向支付网关主动查单，纠正本地订单状态
     *
     * 适合异步通知丢失（网络抖动、回调地址不可达）导致订单长期停留在
     * 「待支付 / 支付中」，而网关侧实际已收款的场景。
     */
    public function reconcile(string $id): void
    {
        Csrf::check();

        $orderId = (int) $id;
        $order   = $this->requireActiveOrder($orderId);
        $backUrl = url('/admin/orders/' . $orderId);

        $gateway = PaymentManager::gateway();

        if (!$gateway->enabled()) {
            $this->fail($backUrl, '支付通道未启用或未配置，无法对账。');
        }

        $orderNo = (string) $order['order_no'];

        try {
            $result = $gateway->queryOrder($orderNo);
        } catch (\Throwable $e) {
            PaymentLog::record($orderId, $orderNo, 'query', 'fail', $e->getMessage(), Request::ip());
            $this->fail($backUrl, '查询支付网关失败：' . $e->getMessage());
        }

        $gatewayStatus = (string) ($result['status'] ?? 'pending');
        $rawJson       = (string) json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE);

        PaymentLog::record($orderId, $orderNo, 'query', 'success', $rawJson, Request::ip());

        $status = (string) $order['status'];

        // 网关已支付但本地未同步 → 补单开通
        if ($gatewayStatus === 'paid' && in_array($status, [Order::STATUS_PENDING, Order::STATUS_PAYING], true)) {
            $changed = Order::markPaid(
                $orderId,
                (string) ($result['trade_no'] ?? ''),
                (string) ($result['trade_no'] ?? ''),
                $rawJson
            );

            if ($changed) {
                $updated = Order::findById($orderId);
                if ($updated !== null) {
                    OrderNotifier::notifyPaid($updated);
                }
            }

            Log::recordOperation('order.reconcile', 'order', $orderId, [
                'order_no' => $orderNo,
                'result'   => 'paid',
            ]);

            $this->success($backUrl, '对账成功：支付网关已收款，订单已补单开通。');
        }

        Log::recordOperation('order.reconcile', 'order', $orderId, [
            'order_no' => $orderNo,
            'result'   => $gatewayStatus,
        ]);

        $message = $gatewayStatus === 'paid'
            ? '支付网关显示已收款，但当前订单状态（' . Order::label($status) . '）无需变更。'
            : '对账完成：支付网关暂无该订单的收款记录。';

        $this->success($backUrl, $message);
    }

    /**
     * 软删除订单（保留数据，仅从列表隐藏）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $orderId = (int) $id;
        $order   = $this->requireActiveOrder($orderId);

        if (!Order::softDelete($orderId, self::operator(), '管理员删除订单')) {
            $this->fail(url('/admin/orders'), '订单不存在或已被删除。');
        }

        Log::recordOperation('order.delete', 'order', $orderId, ['order_no' => (string) $order['order_no']]);

        $this->success(url('/admin/orders'), '订单已删除（历史数据保留）。');
    }

    /**
     * 批量操作：关闭 / 完成 / 删除
     *
     * 逐条处理以保留每个订单的状态流转日志（完成时同步发送通知）；
     * 状态不满足条件的订单会被跳过，不影响其余订单。
     */
    public function batch(): void
    {
        Csrf::check();

        $backUrl = url('/admin/orders');
        $action  = Request::string('action');
        $ids     = $this->batchIds();

        $labels = [
            'close'    => '关闭',
            'complete' => '完成',
            'delete'   => '删除',
        ];

        if (!isset($labels[$action])) {
            $this->fail($backUrl, '未知的批量操作类型。');
        }

        if ($ids === []) {
            $this->fail($backUrl, '请至少选择一个订单。');
        }

        // 剔除不存在或已删除的订单，避免逐条处理时反复查询
        $ids = Order::existingIds($ids);

        if ($ids === []) {
            $this->fail($backUrl, '所选订单不存在或已被删除。');
        }

        $operator = self::operator();
        $success  = 0;

        foreach ($ids as $orderId) {
            if ($action === 'complete') {
                $order = Order::findById($orderId);

                if ($order !== null && Order::complete($orderId, $operator, '管理员批量确认订单完成')) {
                    OrderNotifier::notifyCompleted($order);
                    $success++;
                }

                continue;
            }

            $ok = $action === 'close'
                ? Order::adminClose($orderId, $operator, '管理员批量关闭订单')
                : Order::softDelete($orderId, $operator, '管理员批量删除订单');

            if ($ok) {
                $success++;
            }
        }

        $message = sprintf('已批量%s %d 个订单。', $labels[$action], $success);

        $failed = count($ids) - $success;
        if ($failed > 0) {
            $message .= sprintf('（%d 个订单因状态不符未处理）', $failed);
        }

        Log::recordOperation('order.' . $action, 'order', null, [
            'ids'      => $ids,
            'affected' => $success,
        ]);

        $this->success($backUrl, $message);
    }

    /**
     * 读取未删除的订单，不存在则中断
     *
     * @return array<string, mixed>
     */
    private function requireActiveOrder(int $orderId): array
    {
        $order = Order::findById($orderId);

        if ($order === null || $order['deleted_at'] !== null) {
            $this->fail(url('/admin/orders'), '订单不存在或已被删除。');
        }

        return $order;
    }

    /**
     * 日志操作者标识，形如 admin:1
     */
    private static function operator(): string
    {
        return 'admin:' . (int) (Auth::id() ?? 0);
    }

    /** @var array<string, string> 状态 => 徽标样式 */
    private const STATUS_BADGES = [
        Order::STATUS_PENDING   => 'badge--warning',
        Order::STATUS_PAYING    => 'badge--warning',
        Order::STATUS_PAID      => 'badge--success',
        Order::STATUS_COMPLETED => 'badge--success',
        Order::STATUS_CLOSED    => 'badge',
        Order::STATUS_REFUNDED  => 'badge--info',
    ];
}
