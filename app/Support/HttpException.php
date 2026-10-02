<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * HTTP 异常：用于在任意位置抛出 4xx / 5xx，由 ErrorHandler 统一渲染
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private int $statusCode,
        string $message = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($statusCode), 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400     => '请求参数有误。',
            401     => '请先登录后再继续操作。',
            403     => '你没有权限执行该操作。',
            404     => '你访问的页面不存在。',
            405     => '请求方式不被允许。',
            419     => '页面已过期，请刷新后重试。',
            429     => '操作过于频繁，请稍后再试。',
            default => $status >= 500 ? '服务器开小差了，请稍后再试。' : '请求处理失败。',
        };
    }
}
