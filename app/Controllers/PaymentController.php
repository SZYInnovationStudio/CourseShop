<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Order;
use App\Models\PaymentLog;
use App\Support\OrderNotifier;
use App\Support\Payment\PaymentManager;
use App\Support\Request;
use App\Support\Response;

/**
 * 支付回调：异步通知（服务端）与同步跳转（浏览器）
 *
 * 回调请求来自支付网关，不带会话，因此这两个方法不能做 CSRF 校验，
 * 安全性由「签名校验 + 金额校验 + 订单号校验 + 幂等更新」共同保证。
 */
final class PaymentController extends Controller
{
    /**
     * 易支付异步通知
     *
     * 无论成功与否都必须输出纯文本：处理成功返回 success，其余返回 fail
     * （易支付在收到非 success 响应时会按策略重试通知）。
     */
    public function epayNotify(): void
    {
        $params = array_merge($_GET, $_POST);

        $orderNo = (string) ($params['out_trade_no'] ?? '');
        $raw     = (string) json_encode($params, JSON_UNESCAPED_UNICODE);

        PaymentLog::record(null, $orderNo, 'notify', 'recv', $raw, Request::ip());

        $gateway = PaymentManager::gateway();

        if (!$gateway->enabled()) {
            PaymentLog::record(null, $orderNo, 'notify', 'fail', '支付通道未启用', Request::ip());
            Response::text('fail');
        }

        if (!$gateway->notifyIpAllowed(Request::ip())) {
            PaymentLog::record(null, $orderNo, 'notify', 'fail', '通知来源 IP 不在白名单：' . Request::ip(), Request::ip());
            Response::text('fail');
        }

        if ($orderNo === '' || !$gateway->verifySign($params)) {
            PaymentLog::record(null, $orderNo, 'notify', 'fail', '签名校验失败', Request::ip());
            Response::text('fail');
        }

        $order = Order::findByNo($orderNo);

        if ($order === null) {
            PaymentLog::record(null, $orderNo, 'notify', 'fail', '订单不存在', Request::ip());
            Response::text('fail');
        }

        // 金额校验：易支付回调的 money 单位为「元」，统一换算成「分」比对
        $notifiedAmount = (int) round(((float) ($params['money'] ?? 0)) * 100);

        if ($notifiedAmount !== (int) $order['amount']) {
            PaymentLog::record(
                (int) $order['id'],
                $orderNo,
                'notify',
                'fail',
                sprintf('金额不一致：通知 %d 分，订单 %d 分', $notifiedAmount, (int) $order['amount']),
                Request::ip()
            );
            Response::text('fail');
        }

        if (!$gateway->isPaidNotify($params)) {
            PaymentLog::record(
                (int) $order['id'],
                $orderNo,
                'notify',
                'fail',
                '非支付成功状态：' . (string) ($params['trade_status'] ?? ''),
                Request::ip()
            );
            Response::text('fail');
        }

        // 幂等：重复通知只会成功开通一次
        $changed = Order::markPaid(
            (int) $order['id'],
            isset($params['trade_no']) ? (string) $params['trade_no'] : null,
            isset($params['api_trade_no']) ? (string) $params['api_trade_no'] : null,
            $raw
        );

        PaymentLog::record((int) $order['id'], $orderNo, 'notify', 'success', $changed ? '支付成功，已开通' : '重复通知，已忽略', Request::ip());

        if ($changed) {
            OrderNotifier::notifyPaid($order);
        }

        Response::text('success');
    }

    /**
     * 易支付同步回跳（用户在收银台完成支付后浏览器跳回本站）
     */
    public function epayReturn(string $orderNo): void
    {
        $params  = array_merge($_GET, $_POST);
        $gateway = PaymentManager::gateway();
        $raw     = (string) json_encode($params, JSON_UNESCAPED_UNICODE);

        $order = Order::findByNo($orderNo);

        // 回跳路径用户可直接访问：订单不存在时不写日志（避免日志噪音），仅跳回订单页
        if ($order === null) {
            Response::redirect(url('/order/' . $orderNo));
        }

        // 与异步通知保持同等强度的校验：订单号绑定 + 金额一致 + 通道启用 + 签名/支付状态
        $notifiedAmount = (int) round(((float) ($params['money'] ?? 0)) * 100);

        $valid = (string) ($params['out_trade_no'] ?? '') === $orderNo
            && $notifiedAmount === (int) $order['amount']
            && $gateway->enabled()
            && $gateway->isPaidNotify($params);

        if (!$valid) {
            PaymentLog::record((int) $order['id'], $orderNo, 'return', 'fail', '同步回跳校验未通过：' . $raw, Request::ip());
            // 网关尚未完成通知时，前端会继续轮询订单状态
            Response::redirect(url('/order/' . $orderNo));
        }

        $changed = Order::markPaid(
            (int) $order['id'],
            isset($params['trade_no']) ? (string) $params['trade_no'] : null,
            isset($params['api_trade_no']) ? (string) $params['api_trade_no'] : null,
            $raw
        );

        if ($changed) {
            OrderNotifier::notifyPaid($order);
        }

        PaymentLog::record(
            (int) $order['id'],
            $orderNo,
            'return',
            'success',
            $changed ? '同步回跳确认支付成功' : '重复回跳，已忽略',
            Request::ip()
        );

        $this->success(url('/order/' . $orderNo), '支付成功，课程已开通！');
    }
}
