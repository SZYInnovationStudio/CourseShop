<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Models\User;
use App\Support\Auth;
use App\Support\Captcha;
use App\Support\Csrf;
use App\Support\LoginThrottle;
use App\Support\Permission;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Totp;

/**
 * 后台登录 / 退出
 *
 * 复用与前台一致的防爆破策略（失败锁定 + 图形验证码），
 * 额外要求账号具备管理员权限，并支持两步验证（2FA）。
 */
final class AuthController extends Controller
{
    /** 两步验证：待校验用户 ID 的会话键 */
    private const TWO_FACTOR_USER_KEY = 'admin_2fa_user_id';

    /** 两步验证：挑战发起时间的会话键 */
    private const TWO_FACTOR_TIME_KEY = 'admin_2fa_started_at';

    /** 两步验证：错误次数计数的会话键 */
    private const TWO_FACTOR_ATTEMPTS_KEY = 'admin_2fa_attempts';

    /** 两步验证挑战有效期（秒），超时需重新输入密码 */
    private const TWO_FACTOR_TTL = 300;

    /** 两步验证动态口令最大尝试次数 */
    private const TWO_FACTOR_MAX_ATTEMPTS = 5;

    /**
     * 登录页
     */
    public function showLogin(): void
    {
        if (Auth::isAdmin()) {
            Response::redirect(url('/admin'));
        }

        $this->view('admin.auth.login', [
            'pageTitle'       => '管理员登录',
            'captchaRequired' => LoginThrottle::needsCaptcha(),
        ], 'layouts/admin-auth');
    }

    /**
     * 提交登录
     */
    public function login(): void
    {
        Csrf::check();

        $username = Request::string('username');
        $password = Request::raw('password');
        $old      = ['username' => $username];

        $validator = $this->validator(Request::all())
            ->required('username', '用户名')
            ->required('password', '密码');

        if ($validator->fails()) {
            $this->fail(url('/admin/login'), (string) $validator->firstError(), $old);
        }

        // 1. 失败过多则临时锁定
        $remaining = LoginThrottle::lockRemaining($username);
        if ($remaining > 0) {
            $this->fail(url('/admin/login'), sprintf('失败次数过多，请 %d 分钟后再试。', (int) ceil($remaining / 60)), $old);
        }

        // 2. 触发阈值后必须通过图形验证码
        if (LoginThrottle::needsCaptcha($username) && !Captcha::verify('login', Request::string('captcha'))) {
            User::logLogin(null, $username, 'fail', '后台登录：图形验证码错误');
            $this->fail(url('/admin/login'), '图形验证码不正确或已过期。', $old);
        }

        // 3. 校验账号密码
        $user = User::findByUsername($username);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            User::logLogin($user === null ? null : (int) $user['id'], $username, 'fail', '后台登录：账号或密码错误');
            $this->fail(url('/admin/login'), '账号或密码不正确。', $old);
        }

        if ((int) $user['status'] !== User::STATUS_ACTIVE) {
            User::logLogin((int) $user['id'], $username, 'fail', '后台登录：账号已被禁用');
            $this->fail(url('/admin/login'), '该账号已被禁用，如有疑问请联系站点负责人。', $old);
        }

        if (Auth::isBanned($user)) {
            User::logLogin((int) $user['id'], $username, 'fail', '后台登录：账号已被封禁');
            $this->fail(url('/admin/login'), '该账号已被封禁，如有疑问请联系站点负责人。', $old);
        }

        // 4. 权限预检：非管理员一律拒绝（此时尚未写入登录态，避免先登录再退出的时序问题）
        if (!Permission::isAdminUser($user)) {
            User::logLogin((int) $user['id'], $username, 'fail', '后台登录：无管理权限');
            $this->fail(url('/admin/login'), '该账号没有后台管理权限。', $old);
        }

        // 5. 已启用两步验证：先进入动态口令校验，通过后才真正登录
        if ((int) ($user['two_factor_enabled'] ?? 0) === 1 && (string) ($user['two_factor_secret'] ?? '') !== '') {
            self::startTwoFactorChallenge((int) $user['id']);
            User::logLogin((int) $user['id'], $username, 'success', '后台登录：密码校验通过，等待两步验证');

            $this->success(url('/admin/login/2fa'), '请输入两步验证动态口令。');
        }

        Auth::login($user);
        User::touchLogin((int) $user['id']);
        User::logLogin((int) $user['id'], $username, 'success', '后台登录');

        $this->success($this->intended('/admin'), '登录成功，欢迎回来！');
    }

    /**
     * 两步验证（2FA）校验页
     */
    public function showTwoFactor(): void
    {
        if (self::pendingUserId() === null) {
            $this->fail(url('/admin/login'), '请先登录。');
        }

        $this->view('admin.auth.two-factor', [
            'pageTitle' => '管理员两步验证',
        ], 'layouts/admin-auth');
    }

    /**
     * 提交两步验证动态口令
     */
    public function verifyTwoFactor(): void
    {
        Csrf::check();

        $userId = self::pendingUserId();

        if ($userId === null) {
            $this->fail(url('/admin/login'), '两步验证已超时，请重新登录。');
        }

        $attempts = (int) Session::get(self::TWO_FACTOR_ATTEMPTS_KEY, 0);

        if ($attempts >= self::TWO_FACTOR_MAX_ATTEMPTS) {
            self::clearTwoFactorChallenge();
            $this->fail(url('/admin/login'), '动态口令错误次数过多，请重新登录。');
        }

        $user   = User::findById($userId);
        $code   = Request::string('code');
        $secret = $user === null ? '' : (string) ($user['two_factor_secret'] ?? '');

        if ($user === null || $secret === '') {
            self::clearTwoFactorChallenge();
            $this->fail(url('/admin/login'), '账号状态异常，请重新登录。');
        }

        $step = Totp::verifyStep($secret, $code);

        // 一次性防重放：校验通过后原子占用该时间步，同一时间步不可重复使用
        if ($step === null || !User::consumeTwoFactorStep($userId, $step)) {
            Session::set(self::TWO_FACTOR_ATTEMPTS_KEY, $attempts + 1);
            User::logLogin($userId, (string) $user['username'], 'fail', '后台登录：两步验证动态口令错误');

            $this->fail(url('/admin/login/2fa'), '动态口令不正确，请重试。');
        }

        self::clearTwoFactorChallenge();

        Auth::login($user);
        User::touchLogin($userId);
        User::logLogin($userId, (string) $user['username'], 'success', '后台登录：两步验证通过');

        $this->success($this->intended('/admin'), '登录成功，欢迎回来！');
    }

    /**
     * 发起两步验证挑战：记录待校验用户与发起时间
     */
    private static function startTwoFactorChallenge(int $userId): void
    {
        Session::set(self::TWO_FACTOR_USER_KEY, $userId);
        Session::set(self::TWO_FACTOR_TIME_KEY, time());
        Session::forget(self::TWO_FACTOR_ATTEMPTS_KEY);
    }

    /**
     * 读取待校验用户 ID；不存在或已超时则返回 null 并清理挑战
     */
    private static function pendingUserId(): ?int
    {
        $userId    = (int) Session::get(self::TWO_FACTOR_USER_KEY, 0);
        $startedAt = (int) Session::get(self::TWO_FACTOR_TIME_KEY, 0);

        if ($userId <= 0 || $startedAt <= 0 || (time() - $startedAt) > self::TWO_FACTOR_TTL) {
            self::clearTwoFactorChallenge();
            return null;
        }

        return $userId;
    }

    /**
     * 清除两步验证挑战相关的全部会话数据
     */
    private static function clearTwoFactorChallenge(): void
    {
        Session::forget(self::TWO_FACTOR_USER_KEY);
        Session::forget(self::TWO_FACTOR_TIME_KEY);
        Session::forget(self::TWO_FACTOR_ATTEMPTS_KEY);
    }

    /**
     * 退出登录
     */
    public function logout(): void
    {
        Csrf::check();

        Auth::logout();

        $this->success(url('/admin/login'), '你已安全退出后台。');
    }
}
