<?php

declare(strict_types=1);

namespace App\Support\Payment;

/**
 * 统一支付网关接口
 *
 * 所有支付渠道（当前为易支付）都实现本接口，便于后续扩展。
 * 金额单位统一使用「分」（整数）。
 */
interface PaymentGateway
{
    /**
     * 网关标识，如 epay
     */
    public function name(): string;

    /**
     * 网关是否已正确配置并启用
     */
    public function enabled(): bool;

    /**
     * 可用的支付方式：['wxpay' => '微信支付', 'alipay' => '支付宝']
     *
     * @return array<string, string>
     */
    public function methods(): array;

    /**
     * 创建支付：返回跳转所需信息
     *
     * @param  array<string, mixed> $order  订单数据（order_no / amount / course_title 等）
     * @param  string               $payType 支付方式标识（wxpay / alipay）
     * @return array{mode: string, url: string, params: array<string, string>}
     */
    public function createPayment(array $order, string $payType, string $notifyUrl, string $returnUrl): array;

    /**
     * 校验回调参数签名
     *
     * @param array<string, mixed> $params
     */
    public function verifySign(array $params): bool;

    /**
     * 回调是否为「支付成功」且签名有效
     *
     * @param array<string, mixed> $params
     */
    public function isPaidNotify(array $params): bool;

    /**
     * 异步通知来源 IP 是否在白名单内（未配置白名单时一律放行）
     */
    public function notifyIpAllowed(?string $ip): bool;

    /**
     * 主动查单
     *
     * @return array{status: string, trade_no: string, raw: array<string, mixed>}
     */
    public function queryOrder(string $orderNo): array;

    /**
     * 申请退款
     *
     * @param  int $amount 退款金额（分）
     * @return array{ok: bool, message: string, raw: array<string, mixed>}
     */
    public function refund(string $orderNo, int $amount, string $reason = ''): array;
}
