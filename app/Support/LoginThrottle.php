<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * 登录风控
 *
 * 依据 login_logs 中的失败记录判断：
 * - 失败达到阈值 -> 登录页强制要求图形验证码
 * - 失败达到上限 -> 临时锁定（按账号或 IP 维度）
 */
final class LoginThrottle
{
    /**
     * 统计与锁定共用的时间窗口（秒）
     */
    public static function windowSeconds(): int
    {
        return max(60, Setting::int('login_lock_minutes', 10) * 60);
    }

    /**
     * 时间窗口内的失败次数（账号或 IP 命中即计数，用于决定是否要求验证码）
     */
    public static function failureCount(string $username = ''): int
    {
        return User::countLoginFailures($username, Request::ip(), self::windowSeconds());
    }

    /**
     * 时间窗口内该 IP 的失败次数（用于锁定判断）
     */
    public static function ipFailureCount(): int
    {
        return User::countLoginFailures('', Request::ip(), self::windowSeconds(), 'ip');
    }

    /**
     * 是否需要输入图形验证码
     */
    public static function needsCaptcha(string $username = ''): bool
    {
        if (!Captcha::enabled()) {
            return false;
        }

        $threshold = max(1, Setting::int('login_fail_captcha_threshold', 3));

        return self::failureCount($username) >= $threshold;
    }

    /**
     * 剩余锁定秒数；0 表示未被锁定
     *
     * 只按 IP 维度锁定：否则任意匿名访客只要反复用受害者的用户名输错密码，
     * 就能把该账号（包括管理员）锁死，且每失败一次还会续期。
     * 账号维度的异常仍会通过「必须输入图形验证码」限制爆破速度。
     */
    public static function lockRemaining(string $username = ''): int
    {
        $maxFail = Setting::int('login_max_fail', 10);
        if ($maxFail <= 0 || self::ipFailureCount() < $maxFail) {
            return 0;
        }

        $lastFailure = User::lastFailureAt('', Request::ip(), 'ip');
        if ($lastFailure === null) {
            return 0;
        }

        return max(0, $lastFailure + self::windowSeconds() - time());
    }
}
