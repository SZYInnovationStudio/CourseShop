<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\Refund;
use App\Support\Auth;
use App\Support\Csrf;
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

        $refundId = Refund::create(
            $id,
            (string) $order['order_no'],
            (int) $order['user_id'],
            $amount,
            $reason,
            (int) (Auth::id() ?? 0)
        );

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
        $refund   = $this->requirePendingRefund($refundId);
        $backUrl  = url('/admin/refunds');

        $order = Order::findById((int) $refund['order_id']);

        if ($order === null) {
            $this->fail($backUrl, '关联订单不存在。');
        }

        $gateway = PaymentManager::gateway();

        if (!$gateway->enabled()) {
            $this->fail($backUrl, '支付通道未启用或未配置，无法执行退款。');
        }

        $orderNo = (string) $order['order_no'];
        $amount  = (int) $refund['amount'];

        try {
            $result = $gateway->refund($orderNo, $amount, (string) ($refund['reason'] ?? ''));
        } catch (Throwable $e) {
            PaymentLog::record((int) $order['id'], $orderNo, 'refund', 'fail', $e->getMessage(), Request::ip());

            Log::recordOperation('refund.approve', 'refund', $refundId, [
                'order_no' => $orderNo,
                'result'   => 'exception',
                'message'  => $e->getMessage(),
            ]);

            $this->fail($backUrl, '调用支付网关退款失败：' . $e->getMessage());
        }

        $ok      = (bool) ($result['ok'] ?? false);
        $message = (string) ($result['message'] ?? ($ok ? '退款成功' : '退款失败'));
        $rawJson = (string) json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE);

        PaymentLog::record((int) $order['id'], $orderNo, 'refund', $ok ? 'success' : 'fail', $rawJson, Request::ip());

        if (!$ok) {
            // 网关拒绝：退款单置为失败，保留 api_result 供排查
            Refund::markFailed($refundId, $rawJson, (int) (Auth::id() ?? 0));

            Log::recordOperation('refund.approve', 'refund', $refundId, [
                'order_no' => $orderNo,
                'result'   => 'fail',
                'message'  => $message,
            ]);

            $this->fail($backUrl, '支付网关退款失败：' . $message);
        }

        // 退款成功：退款单置成功 + 订单置已退款（撤销课程授权）+ 邮件通知
        Refund::markSuccess($refundId, $rawJson, (int) (Auth::id() ?? 0));

        Order::markRefunded(
            (int) $order['id'],
            $amount,
            'admin:' . (int) (Auth::id() ?? 0),
            '管理员确认退款（退款单 ' . (string) $refund['refund_no'] . '）'
        );

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
