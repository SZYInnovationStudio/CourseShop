<?php

declare(strict_types=1);

namespace App\Support\Payment;

/**
 * 支付网关工厂
 *
 * 当前仅内置易支付，后续扩展新渠道时在此登记即可。
 */
final class PaymentManager
{
    public static function gateway(): PaymentGateway
    {
        return new EpayGateway();
    }
}
