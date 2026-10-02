<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\Refund;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Logger;
use App\Support\OrderNotifier;
use App\Support\Payment\PaymentManager;
use App\Support\Request;
use Throwable;

/**
 * 后台退款管理
 *
 * 采用两步审核流：在订单详情页「发起退款」生成待审核退款单（pending），
 * 再到本模块「确认退款」（调用支付网关实际退款）或「驳回」。
 * 退款金额一律使用「分」（整数）。
 */
final class RefundController extends AdminController
{
    /** 每页条数 */
    private const PER_PAGE = 20;

    /** 发起退款时用户可填写的原因最大长度 */
    private const MAX_REASON_LENGTH = 255;

    /**
     * 退款单列表
     */
    public function index(): void
    {
        $filters = [
            'keyword'   => Request::string('keyword'),
            'status'    => Request::string('status'),
            'date_from' => Request::string('date_from'),
            'date_to'   => Request::string('date_to'),
        ];

        // 归一化，避免非法值进入 SQL 条件拼装
        if (!isset(Refund::STATUS_LABELS[$filters['status']])) {
            $filters['status'] = '';
        }

        $total      = Refund::adminCount($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min(max(1, Request::int('page', 1)), $totalPages);

        $this->view('admin.refunds.index', [
            'pageTitle'   => '退款管理',
            'filters'     => $filters,
            'refunds'     => Refund::adminList($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'       => $total,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'statuses'    => Refund::STATUS_LABELS,
            'statusBadges' => Refund::STATUS_BADGES,
        ]);
    }

    /**
     * 发起退款：为订单创建一条待审核的退款单（不调用支付网关）
     */
    public function store(string $orderId): void
    {
        Csrf::check();

        $id    = (int) $orderId;
        $order = Order::adminFind($id);

        if ($order === null) {
            $this->fail(url('/admin/orders'), '订单不存在或已被删除。');
        }

        $backUrl = url('/admin/orders/' . $id);

        if (!Order::canRefund((string) $order['status'])) {
            $this->fail($backUrl, '仅「已支付 / 已完成」的订单可以发起退款。');
        }

        if (Refund::pendingByOrder($id) !== null) {
            $this->fail($backUrl, '该订单已存在待审核的退款单，请勿重复发起。');
        }

        $reason = trim((string) Request::input('reason', ''));
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $this->fail($backUrl, '退款原因不能超过 ' . self::MAX_REASON_LENGTH . ' 个字符。');
        }
        if ($reason === '') {
            $reason = '管理员发起退款';
        }

        // 仅支持全额退款：退款金额取订单实付金额（本系统不提供部分退款）
        $amount = max(0, (int) $order['amount']);

        // 原子创建：同一订单同一时刻只允许一个待处理退款单，防止并发重复发起（CS-10）
        $refundId = Refund::createIfIdle(
            $id,
            (string) $order['order_no'],
            (int) $order['user_id'],
            $amount,
            $reason,
            (int) (Auth::id() ?? 0)
        );

        if ($refundId === null) {
            $this->fail($backUrl, '该订单已存在待处理的退款单，请勿重复发起。');
        }

        Log::recordOperation('refund.create', 'refund', $refundId, [
            'order_no' => (string) $order['order_no'],
            'amount'   => $amount,
        ]);

        $this->success(url('/admin/refunds'), '退款单已创建，请在退款管理中确认或驳回。');
    }

    /**
     * 确认退款：调用支付网关实际退款，成功则同步撤销课程授权
     */
    public function approve(string $id): void
    {
        Csrf::check();

        $refundId = (int) $id;
        $backUrl  = url('/admin/refunds');

        $refund = Refund::find($refundId);

        if ($refund === null) {
            $this->fail($backUrl, '退款单不存在或已被删除。');
        }

        if (!in_array((string) $refund['status'], Refund::ACTIVE_STATUSES, true)) {
            $this->fail($backUrl, '该退款单已处理，无法重复操作。');
        }

        $order = Order::findById((int) $refund['order_id']);

        if ($order === null) {
            $this->fail($backUrl, '关联订单不存在。');
        }

        $gateway = PaymentManager::gateway();

        if (!$gateway->enabled()) {
            $this->fail($backUrl, '支付通道未启用或未配置，无法执行退款。');
        }

        $operator = (int) (Auth::id() ?? 0);

        // 原子抢占处理权：并发审批时只有一个请求能进入网关调用，避免重复退款（CS-10）
        if (!Refund::claim($refundId, $operator)) {
            $this->fail($backUrl, '该退款单正在处理中或已被其他管理员处理，请刷新后重试。');
        }

        $orderNo = (string) $order['order_no'];
        $amount  = (int) $refund['amount'];

        try {
            $result = $gateway->refund($orderNo, $amount, (string) ($refund['reason'] ?? ''));
        } catch (Throwable $e) {
            // 释放处理权回到待审核，允许核实网关状态后重试；同时告警对账（CS-10）
            Refund::release($refundId);

            PaymentLog::record((int) $order['id'], $orderNo, 'refund', 'fail', $e->getMessage(), Request::ip());

            Logger::error(
                '退款网关调用异常，已释放退款单待重试：退款单 ' . (string) $refund['refund_no']
                . '，订单号 ' . $orderNo . '，错误：' . $e->getMessage()
            );

            Log::recordOperation('refund.approve', 'refund', $refundId, [
                'order_no' => $orderNo,
                'result'   => 'exception',
                'message'  => $e->getMessage(),
            ]);

            $this->fail($backUrl, '调用支付网关退款失败：' . $e->getMessage() . '（已记录告警，可在退款管理中重试）');
        }

        $ok      = (bool) ($result['ok'] ?? false);
        $message = (string) ($result['message'] ?? ($ok ? '退款成功' : '退款失败'));
        $rawJson = (string) json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE);

        PaymentLog::record((int) $order['id'], $orderNo, 'refund', $ok ? 'success' : 'fail', $rawJson, Request::ip());

        if (!$ok) {
            // 网关拒绝：退款单置为失败，保留 api_result 供排查
            Refund::markFailed($refundId, $rawJson, $operator);

            Log::recordOperation('refund.approve', 'refund', $refundId, [
                'order_no' => $orderNo,
                'result'   => 'fail',
                'message'  => $message,
            ]);

            $this->fail($backUrl, '支付网关退款失败：' . $message);
        }

        // 退款成功：先记录退款单成功，再更新订单并撤销授权
        $refundMarked = Refund::markSuccess($refundId, $rawJson, $operator);

        $orderMarked = Order::markRefunded(
            (int) $order['id'],
            $amount,
            'admin:' . $operator,
            '管理员确认退款（退款单 ' . (string) $refund['refund_no'] . '）'
        );

        // 网关已实际退款，本地任一步骤失败都需告警人工对账（CS-10）
        if (!$refundMarked || !$orderMarked) {
            Logger::error(
                '退款网关已成功但本地状态更新不完整，需人工核查：退款单 ' . (string) $refund['refund_no']
                . '，订单号 ' . $orderNo
                . '，退款单更新=' . ($refundMarked ? '成功' : '失败')
                . '，订单更新=' . ($orderMarked ? '成功' : '失败')
            );
        }

        $updated = Order::findById((int) $order['id']);
        if ($updated !== null) {
            OrderNotifier::notifyRefunded($updated);
        }

        Log::recordOperation('refund.approve', 'refund', $refundId, [
            'order_no' => $orderNo,
            'result'   => 'success',
            'amount'   => $amount,
        ]);

        $this->success($backUrl, '退款成功，订单已置为「已退款」并撤销课程授权。');
    }

    /**
     * 驳回退款：仅将退款单置为失败，不影响订单与授权
     */
    public function reject(string $id): void
    {
        Csrf::check();

        $refundId = (int) $id;
        $refund   = $this->requirePendingRefund($refundId);

        $reason = trim((string) Request::input('reason', ''));
        if ($reason === '') {
            $reason = '管理员驳回退款申请';
        }

        Refund::markFailed($refundId, '驳回：' . mb_substr($reason, 0, 200), (int) (Auth::id() ?? 0));

        Log::recordOperation('refund.reject', 'refund', $refundId, [
            'order_no' => (string) $refund['order_no'],
            'message'  => $reason,
        ]);

        $this->success(url('/admin/refunds'), '退款单已驳回。');
    }

    /**
     * 读取待审核的退款单，不存在或状态不符则中断
     *
     * @return array<string, mixed>
     */
    private function requirePendingRefund(int $refundId): array
    {
        $refund = Refund::find($refundId);

        if ($refund === null) {
            $this->fail(url('/admin/refunds'), '退款单不存在或已被删除。');
        }

        if ((string) $refund['status'] !== Refund::STATUS_PENDING) {
            $this->fail(url('/admin/refunds'), '该退款单已处理，无法重复操作。');
        }

        return $refund;
    }
}
