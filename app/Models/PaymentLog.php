<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use Throwable;

/**
 * 支付日志（记录每一次与支付网关的交互原始数据，便于对账与排查）
 */
final class PaymentLog
{
    /**
     * @param string $action create / return / notify / query / refund
     * @param string $status success / fail / recv
     */
    public static function record(
        ?int $orderId,
        string $orderNo,
        string $action,
        string $status,
        string $raw,
        ?string $ip = null
    ): void {
        try {
            Database::execute(
                'INSERT INTO `payment_logs` (`order_id`, `order_no`, `action`, `status`, `raw`, `ip`, `created_at`)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$orderId, $orderNo, $action, $status, self::trim($raw), $ip]
            );
        } catch (Throwable $e) {
            // 日志写入失败不应影响支付主流程
            \App\Support\Logger::warning('写入支付日志失败：' . $e->getMessage());
        }
    }

    /**
     * TEXT 列上限 65535 字节，按字节安全截断
     */
    private static function trim(string $raw): string
    {
        if (strlen($raw) <= 60000) {
            return $raw;
        }

        return mb_strcut($raw, 0, 60000, 'UTF-8');
    }
}
