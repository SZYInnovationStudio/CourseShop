<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSRF 防护
 *
 * Token 存于会话中，所有写操作表单必须携带 _token 字段（或 X-CSRF-Token 请求头）。
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    /**
     * 生成表单隐藏域 HTML
     */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verify(mixed $token): bool
    {
        return is_string($token)
            && $token !== ''
            && hash_equals(self::token(), $token);
    }

    /**
     * 校验通过则返回，否则抛出 419
     */
    public static function check(): void
    {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!self::verify($token)) {
            throw new HttpException(419);
        }
    }

    /**
     * 轮换 Token（登录后调用）
     */
    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
