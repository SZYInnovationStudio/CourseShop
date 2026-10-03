<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Agreement;
use App\Models\User;
use App\Support\Auth;
use App\Support\Captcha;
use App\Support\Csrf;
use App\Support\LoginThrottle;
use App\Support\Request;
use App\Support\Session;
use App\Support\Setting;
use App\Support\Totp;

/**
 * 注册 / 登录 / 退出
 */
final class AuthController extends Controller
{
    /** 两步验证：待校验用户 ID 的会话键 */
    private const TWO_FACTOR_USER_KEY = '2fa_user_id';

    /** 两步验证：挑战发起时间的会话键 */
    private const TWO_FACTOR_TIME_KEY = '2fa_started_at';

    /** 两步验证：错误次数计数的会话键 */
    private const TWO_FACTOR_ATTEMPTS_KEY = '2fa_attempts';

    /** 两步验证挑战有效期（秒），超时需重新输入密码 */
    private const TWO_FACTOR_TTL = 300;

    /** 两步验证动态口令最大尝试次数 */
    private const TWO_FACTOR_MAX_ATTEMPTS = 5;

    /**
     * 登录页
     */
    public function showLogin(): void
    {
        $this->view('auth.login', [
            'pageTitle'       => t('登录'),
            'captchaRequired' => LoginThrottle::needsCaptcha(),
        ]);
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
            $this->fail(url('/login'), (string) $validator->firstError(), $old);
        }

        // 1. 失败过多则临时锁定
        $remaining = LoginThrottle::lockRemaining($username);
        if ($remaining > 0) {
            $this->fail(url('/login'), sprintf(t('失败次数过多，请 %d 分钟后再试。'), (int) ceil($remaining / 60)), $old);
        }

        // 2. 触发阈值后必须通过图形验证码
        if (LoginThrottle::needsCaptcha($username) && !Captcha::verify('login', Request::string('captcha'))) {
            User::logLogin(null, $username, 'fail', '图形验证码错误');
            $this->fail(url('/login'), t('图形验证码不正确或已过期。'), $old);
        }

        // 3. 校验账号密码
        $user = User::findByUsername($username);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            User::logLogin($user === null ? null : (int) $user['id'], $username, 'fail', '账号或密码错误');
            $this->fail(url('/login'), t('账号或密码不正确。'), $old);
        }

        if ((int) $user['status'] !== User::STATUS_ACTIVE) {
            User::logLogin((int) $user['id'], $username, 'fail', '账号已被禁用');
            $this->fail(url('/login'), t('该账号已被禁用，如有疑问请联系管理员。'), $old);
        }

        if (Auth::isBanned($user)) {
            User::logLogin((int) $user['id'], $username, 'fail', '账号已被封禁');
            $this->fail(url('/login'), t('该账号已被封禁，如有疑问请联系管理员。'), $old);
        }

        // 已启用两步验证：先进入动态口令校验，通过后才真正登录
        if ((int) ($user['two_factor_enabled'] ?? 0) === 1 && (string) ($user['two_factor_secret'] ?? '') !== '') {
            self::startTwoFactorChallenge((int) $user['id']);
            User::logLogin((int) $user['id'], $username, 'success', '密码校验通过，等待两步验证');

            $this->success(url('/login/2fa'), t('请输入两步验证动态口令。'));
        }

        Auth::login($user);
        User::touchLogin((int) $user['id']);
        User::logLogin((int) $user['id'], $username, 'success');

        $this->success($this->intended('/'), t('登录成功，欢迎回来！'));
    }

    /**
     * 两步验证（2FA）校验页
     */
    public function showTwoFactor(): void
    {
        if (self::pendingUserId() === null) {
            $this->fail(url('/login'), t('请先登录。'));
        }

        $this->view('auth.two-factor', [
            'pageTitle' => t('两步验证'),
        ]);
    }

    /**
     * 提交两步验证动态口令
     */
    public function verifyTwoFactor(): void
    {
        Csrf::check();

        $userId = self::pendingUserId();

        if ($userId === null) {
            $this->fail(url('/login'), t('两步验证已超时，请重新登录。'));
        }

        $attempts = (int) Session::get(self::TWO_FACTOR_ATTEMPTS_KEY, 0);

        if ($attempts >= self::TWO_FACTOR_MAX_ATTEMPTS) {
            self::clearTwoFactorChallenge();
            $this->fail(url('/login'), t('动态口令错误次数过多，请重新登录。'));
        }

        $code  = Request::string('code');
        $user  = User::findById($userId);
        $secret = $user === null ? '' : (string) ($user['two_factor_secret'] ?? '');

        if ($user === null || $secret === '') {
            self::clearTwoFactorChallenge();
            $this->fail(url('/login'), t('账号状态异常，请重新登录。'));
        }

        $step = Totp::verifyStep($secret, $code);

        // 一次性防重放：校验通过后原子占用该时间步，同一时间步不可重复使用
        if ($step === null || !User::consumeTwoFactorStep($userId, $step)) {
            Session::set(self::TWO_FACTOR_ATTEMPTS_KEY, $attempts + 1);
            User::logLogin($userId, (string) $user['username'], 'fail', '两步验证动态口令错误');

            $this->fail(url('/login/2fa'), t('动态口令不正确，请重试。'));
        }

        self::clearTwoFactorChallenge();

        Auth::login($user);
        User::touchLogin($userId);
        User::logLogin($userId, (string) $user['username'], 'success', '两步验证通过');

        $this->success($this->intended('/'), t('登录成功，欢迎回来！'));
    }

    /**
     * 注册页
     */
    public function showRegister(): void
    {
        if (!Setting::bool('register_enabled', true)) {
            abort(404, t('当前未开放注册。'));
        }

        $this->view('auth.register', [
            'pageTitle'       => t('注册'),
            'captchaRequired' => Captcha::enabled(),
            'forceEmailBind'  => Setting::bool('force_email_bind', false),
            'terms'           => Agreement::current('terms'),
            'privacy'         => Agreement::current('privacy'),
        ]);
    }

    /**
     * 提交注册
     */
    public function register(): void
    {
        Csrf::check();

        if (!Setting::bool('register_enabled', true)) {
            $this->fail(url('/register'), t('当前未开放注册。'));
        }

        $username = Request::string('username');
        $password = Request::raw('password');
        $old      = ['username' => $username];

        $validator = $this->validator(Request::all())
            ->required('username', '用户名')
            ->username('username', '用户名')
            ->min('username', 3, '用户名')
            ->max('username', 20, '用户名')
            ->required('password', '密码')
            ->min('password', 8, '密码')
            ->max('password', 64, '密码')
            ->required('password_confirm', '确认密码')
            ->same('password_confirm', 'password', '确认密码')
            ->accepted('agree', '用户协议与隐私政策');

        if ($validator->fails()) {
            $this->fail(url('/register'), (string) $validator->firstError(), $old);
        }

        if (Captcha::enabled() && !Captcha::verify('register', Request::string('captcha'))) {
            $this->fail(url('/register'), t('图形验证码不正确或已过期。'), $old);
        }

        if (User::usernameExists($username)) {
            $this->fail(url('/register'), t('该用户名已被注册，请更换一个。'), $old);
        }

        $userId = User::create($username, password_hash($password, PASSWORD_DEFAULT));

        // 记录注册时同意的协议版本快照
        foreach (['terms', 'privacy'] as $type) {
            $agreement = Agreement::current($type);
            if ($agreement !== null) {
                Agreement::record($userId, $agreement);
            }
        }

        $user = User::findById($userId);

        if ($user === null) {
            $this->fail(url('/register'), t('注册失败，请稍后重试。'), $old);
        }

        Auth::login($user);
        User::touchLogin($userId);
        User::logLogin($userId, $username, 'success', '注册后自动登录');

        $message = Setting::bool('force_email_bind', false)
            ? t('注册成功！请先绑定邮箱以启用完整功能。')
            : t('注册成功，欢迎加入！');

        $this->success($this->intended('/'), $message);
    }

    /**
     * 退出登录
     */
    public function logout(): void
    {
        Csrf::check();

        Auth::logout();

        $this->success(url('/'), t('你已安全退出。'));
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
}
