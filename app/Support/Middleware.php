<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 路由中间件
 *
 * 校验不通过时直接重定向并结束请求（Response::redirect 内部 exit）。
 */
final class Middleware
{
    /** 需要登录 */
    public static function auth(): void
    {
        if (Auth::check()) {
            return;
        }

        if (Request::method() === 'GET') {
            Session::set('_intended', Request::path());
        }
        Session::flash('error', t('请先登录后再继续操作。'));
        Response::redirect(url('/login'));
    }

    /** 仅未登录可访问（登录 / 注册页） */
    public static function guest(): void
    {
        if (!Auth::check()) {
            return;
        }

        Response::redirect(url('/'));
    }

    /** 需要管理员权限（后台使用独立登录入口） */
    public static function admin(): void
    {
        if (!Auth::check()) {
            if (Request::method() === 'GET') {
                Session::set('_intended', Request::path());
            }
            Session::flash('error', '请先登录管理员账号。');
            Response::redirect(url('/admin/login'));
        }

        if (Auth::isAdmin()) {
            return;
        }

        throw new HttpException(403);
    }

    /**
     * 需要指定权限（参数为权限标识，例如 permission:course.manage）
     *
     * 未传参数时仅校验后台登录；权限不足抛出 403。
     */
    public static function permission(?string $code = null): void
    {
        self::admin();

        if ($code === null || $code === '' || Auth::can($code)) {
            return;
        }

        throw new HttpException(403);
    }

    /** 需要已完成邮箱绑定 */
    public static function emailVerified(): void
    {
        self::auth();

        if (!Auth::mustBindEmail()) {
            return;
        }

        Session::flash('error', t('请先完成邮箱绑定后再继续操作。'));
        Response::redirect(url('/account/email'));
    }

    /**
     * 全局强制绑定邮箱拦截
     *
     * 当后台开启「强制绑定邮箱」时，除管理员外所有账号只能访问白名单路径。
     */
    public static function enforceEmailBinding(): void
    {
        if (!Auth::mustBindEmail()) {
            return;
        }

        $path = Request::path();

        // 精确匹配的放行页面
        if (in_array($path, self::emailBindingAllowlist(), true)) {
            return;
        }

        // 仅对确需整段放行的目录使用前缀匹配，避免误放行同前缀的其它路径
        foreach (self::emailBindingAllowlistPrefixes() as $prefix) {
            if (str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return;
            }
        }

        if (Request::isAjax()) {
            Response::json(['code' => 403, 'message' => t('请先完成邮箱绑定。')], 403);
        }

        Session::flash('error', t('请先完成邮箱绑定后再继续操作。'));
        Response::redirect(url('/account/email'));
    }

    /**
     * 允许未绑定邮箱访问的页面（精确匹配）
     *
     * @return array<int, string>
     */
    private static function emailBindingAllowlist(): array
    {
        return [
            '/account',
            '/account/email',
            '/account/email/send',
            '/account/email/verify',
            '/logout',
            '/captcha',
            // 账号申诉入口不要求绑定邮箱
            '/ticket/appeal',
        ];
    }

    /**
     * 允许未绑定邮箱访问的路径前缀（仅用于必须整段放行的目录）
     *
     * @return array<int, string>
     */
    private static function emailBindingAllowlistPrefixes(): array
    {
        return [
            // 协议页按类型分路径：/agreements/{type}
            '/agreements',
        ];
    }
}
