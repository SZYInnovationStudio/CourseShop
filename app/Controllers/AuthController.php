<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Agreement;
use App\Models\EmailVerification;
use App\Models\User;
use App\Support\Auth;
use App\Support\Captcha;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Logger;
use App\Support\LoginThrottle;
use App\Support\Mailer;
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

    /** 注册验证码重发间隔（秒） */
    private const REGISTER_RESEND_INTERVAL = 60;

    /** 同一 IP 每小时最多下发的注册验证码数量 */
    private const REGISTER_IP_HOURLY_LIMIT = 5;

    /**
     * 注册是否必须完成邮箱验证
     *
     * 开启后注册页要求填写邮箱并校验验证码，验证通过才会创建账号，
     * 可有效抑制批量注册；关闭则回到「注册无需邮箱」的流程。
     */
    private function registerEmailRequired(): bool
    {
        return Setting::bool('register_email_verify', true);
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
            'pageTitle'           => t('注册'),
            'captchaRequired'     => Captcha::enabled(),
            'emailVerifyRequired' => $this->registerEmailRequired(),
            'codeTtlMinutes'      => max(1, (int) ceil(max(60, Setting::int('mail_code_ttl', 600)) / 60)),
            'forceEmailBind'      => Setting::bool('force_email_bind', false),
            'terms'               => Agreement::current('terms'),
            'privacy'             => Agreement::current('privacy'),
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

        $emailVerifyRequired = $this->registerEmailRequired();
        $username = Request::string('username');
        $password = Request::raw('password');
        $email    = strtolower(Request::string('email'));
        $old      = ['username' => $username, 'email' => $email];

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

        if ($emailVerifyRequired) {
            $validator->required('email', '邮箱')->email('email', '邮箱')->required('email_code', '验证码');
        }

        if ($validator->fails()) {
            $this->fail(url('/register'), (string) $validator->firstError(), $old);
        }

        if (Captcha::enabled() && !Captcha::verify('register', Request::string('captcha'))) {
            $this->fail(url('/register'), t('图形验证码不正确或已过期。'), $old);
        }

        if (User::usernameExists($username)) {
            $this->fail(url('/register'), t('该用户名已被注册，请更换一个。'), $old);
        }

        // 注册即验证邮箱：邮箱占用与验证码都在建号前校验，避免用任意邮箱批量注册
        if ($emailVerifyRequired) {
            if (User::emailExists($email)) {
                $this->fail(url('/register'), t('该邮箱已被其他账号绑定。'), $old);
            }

            $codeError = $this->consumeRegisterEmailCode($email, Request::string('email_code'));

            if ($codeError !== '') {
                $this->fail(url('/register'), $codeError, $old);
            }
        }

        $userId = User::create($username, password_hash($password, PASSWORD_DEFAULT));

        $emailBound = false;

        if ($emailVerifyRequired) {
            User::bindEmail($userId, $email);
            $emailBound = true;
        }

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

        $message = (!$emailBound && Setting::bool('force_email_bind', false))
            ? t('注册成功！请先绑定邮箱以启用完整功能。')
            : t('注册成功，欢迎加入！');

        $this->success($this->intended('/'), $message);
    }

    /**
     * 注册页：发送邮箱验证码
     *
     * 该入口未登录即可访问，因此同时依赖图形验证码、邮箱冷却与 IP 频次限制，
     * 防止被用来向任意邮箱批量发送邮件。
     */
    public function sendRegisterEmailCode(): void
    {
        Csrf::check();

        if (!Setting::bool('register_enabled', true)) {
            $this->fail(url('/register'), t('当前未开放注册。'));
        }

        $email = strtolower(Request::string('email'));
        $old   = ['username' => Request::string('username'), 'email' => $email];

        $validator = $this->validator(Request::all())
            ->required('email', '邮箱')
            ->email('email', '邮箱');

        if ($validator->fails()) {
            $this->fail(url('/register'), (string) $validator->firstError(), $old);
        }

        if (Captcha::enabled() && !Captcha::verify('register', Request::string('captcha'))) {
            $this->fail(url('/register'), t('图形验证码不正确或已过期。'), $old);
        }

        if (User::emailExists($email)) {
            $this->fail(url('/register'), t('该邮箱已被其他账号绑定。'), $old);
        }

        if (EmailVerification::recentCount($email, EmailVerification::PURPOSE_REGISTER, self::REGISTER_RESEND_INTERVAL) > 0) {
            $this->fail(url('/register'), t('验证码发送过于频繁，请稍后再试。'), $old);
        }

        if (EmailVerification::recentCountByIp(Request::ip(), EmailVerification::PURPOSE_REGISTER, 3600) >= self::REGISTER_IP_HOURLY_LIMIT) {
            $this->fail(url('/register'), t('当前网络获取验证码过于频繁，请稍后再试。'), $old);
        }

        $issued = EmailVerification::issue(null, $email, EmailVerification::PURPOSE_REGISTER);
        $ttl    = max(1, (int) ceil($issued['ttl'] / 60));

        if (Mailer::sendCode($email, $issued['code'], 'register')) {
            $this->success(url('/register'), t('验证码已发送至 %s，%d 分钟内有效。', [mask_email($email), $ttl]), $old);
        }

        // 发送失败：调试模式下仅写入受控日志，不在页面回显验证码
        if ((bool) Config::get('app.debug', false)) {
            Logger::warning('注册验证码发送失败（调试模式已写入日志）', [
                'email' => $email,
                'code'  => (string) $issued['code'],
            ]);
        }

        $this->fail(url('/register'), t('验证码发送失败，请稍后重试或联系管理员。'), $old);
    }

    /**
     * 校验并消费注册邮箱验证码
     *
     * @return string 成功返回空串，失败返回错误提示（已翻译）
     */
    private function consumeRegisterEmailCode(string $email, string $code): string
    {
        $record = EmailVerification::latest($email, EmailVerification::PURPOSE_REGISTER);

        if ($record === null) {
            return t('验证码不正确。');
        }

        if ((int) $record['attempts'] >= EmailVerification::MAX_ATTEMPTS) {
            return t('验证码错误次数过多，请重新获取。');
        }

        if (strtotime((string) $record['expires_at']) < time()) {
            return t('验证码已过期，请重新获取。');
        }

        if (!hash_equals((string) $record['code'], trim($code))) {
            EmailVerification::incrementAttempts((int) $record['id']);

            return t('验证码不正确。');
        }

        EmailVerification::markUsed((int) $record['id']);

        return '';
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
