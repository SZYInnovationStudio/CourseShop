<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\EmailVerification;
use App\Models\LoginDevice;
use App\Models\User;
use App\Support\Captcha;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\Request;
use App\Support\Setting;

/**
 * 找回密码
 *
 * 两步流程（与「绑定邮箱」复用同一套邮箱验证码设施，用途为 reset）：
 * 1. 输入注册邮箱 + 图形验证码 -> 下发邮件验证码；
 * 2. 输入邮箱 + 验证码 + 新密码 -> 校验通过后重置密码，并强制退出该账号的全部登录设备。
 *
 * 出于防止邮箱枚举的考虑，无论邮箱是否存在都返回一致的成功提示，仅在邮箱确实存在时真正发信。
 */
final class PasswordController extends Controller
{
    /** 同一邮箱重发验证码的最小间隔（秒） */
    private const RESEND_INTERVAL = 60;

    /**
     * 忘记密码页
     */
    public function showForgot(): void
    {
        $this->view('auth.forgot-password', [
            'pageTitle' => '找回密码',
            'captchaRequired' => Captcha::enabled(),
            'mailEnabled'     => Mailer::enabled(),
        ]);
    }

    /**
     * 发送重置验证码
     */
    public function sendResetCode(): void
    {
        Csrf::check();

        $email = strtolower(Request::string('email'));
        $back  = url('/password/forgot');
        $old   = ['email' => $email];

        $validator = $this->validator(Request::all())
            ->required('email', '邮箱')
            ->email('email', '邮箱');

        if ($validator->fails()) {
            $this->fail($back, (string) $validator->firstError(), $old);
        }

        // 图形验证码：防止脚本批量探测邮箱是否存在
        if (Captcha::enabled() && !Captcha::verify('reset', Request::string('captcha'))) {
            $this->fail($back, '图形验证码不正确或已过期。', $old);
        }

        // 节流：同一邮箱在间隔内重复请求时返回与成功一致的中性提示，
        // 且不因邮箱是否存在而变化，避免被用来枚举账号
        if (EmailVerification::recentCount($email, EmailVerification::PURPOSE_RESET, self::RESEND_INTERVAL) > 0) {
            $this->success($back, $this->sentMessage($email));
        }

        $user = User::findByEmail($email);

        // 邮箱不存在时不发信，但返回一致的提示，避免泄露账号是否存在
        if ($user === null) {
            $this->success($back, $this->sentMessage($email));
        }

        $issued = EmailVerification::issue((int) $user['id'], $email, EmailVerification::PURPOSE_RESET);
        $ttl    = max(1, (int) ceil($issued['ttl'] / 60));

        if (Mailer::sendCode($email, $issued['code'], 'reset')) {
            $this->success($this->resetUrl($email), $this->sentMessage($email, $ttl));
        }

        // 邮件发送失败：调试模式下仅把验证码写入受控日志（不得在公开接口回显，
        // 否则任何人可借此重置他人密码），开发联调请从 storage/logs/ 取码。
        if ((bool) Config::get('app.debug', false)) {
            Logger::warning('找回密码验证码邮件发送失败（调试模式已写入日志）', [
                'email' => $email,
                'code'  => (string) $issued['code'],
            ]);
        }

        $this->fail($back, '验证码发送失败，请稍后重试或联系管理员。', $old);
    }

    /**
     * 重置密码页
     */
    public function showReset(): void
    {
        $email = strtolower(Request::string('email'));

        $this->view('auth.reset-password', [
            'pageTitle' => '重置密码',
            'email'     => $email,
        ]);
    }

    /**
     * 校验验证码并重置密码
     */
    public function reset(): void
    {
        Csrf::check();

        $email           = strtolower(Request::string('email'));
        $code            = trim(Request::string('code'));
        $password        = Request::raw('password');
        $confirmPassword = Request::raw('password_confirm');
        $back            = url('/password/reset?email=' . rawurlencode($email));
        $old             = ['email' => $email];

        $validator = $this->validator(Request::all())
            ->required('email', '邮箱')
            ->email('email', '邮箱')
            ->required('code', '验证码')
            ->required('password', '新密码')
            ->min('password', 8, '新密码')
            ->max('password', 64, '新密码')
            ->required('password_confirm', '确认新密码')
            ->same('password_confirm', 'password', '确认新密码');

        if ($validator->fails()) {
            $this->fail($back, (string) $validator->firstError(), $old);
        }

        $record = EmailVerification::latest($email, EmailVerification::PURPOSE_RESET);

        if ($record === null) {
            $this->fail(url('/password/forgot'), '请先获取邮箱验证码。', $old);
        }

        if ((int) $record['attempts'] >= EmailVerification::MAX_ATTEMPTS) {
            $this->fail($back, '验证码错误次数过多，请重新获取。', $old);
        }

        if (strtotime((string) $record['expires_at']) <= time()) {
            $this->fail($back, '验证码已过期，请重新获取。', $old);
        }

        if (!hash_equals((string) $record['code'], $code)) {
            EmailVerification::incrementAttempts((int) $record['id']);
            $this->fail($back, '验证码不正确。', $old);
        }

        $user = User::findByEmail($email);
        if ($user === null) {
            $this->fail(url('/password/forgot'), '该邮箱未注册或账号不可用。', $old);
        }

        EmailVerification::markUsed((int) $record['id']);
        User::updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));

        // 密码已变更：撤销该账号全部登录设备，强制各处重新登录
        LoginDevice::revokeAll((int) $user['id']);

        User::logLogin((int) $user['id'], (string) $user['username'], 'success', '通过邮箱验证码重置密码');

        $this->success(url('/login'), '密码已重置，请使用新密码登录。');
    }

    /**
     * 统一的「已发送」提示文案
     */
    private function sentMessage(string $email, int $ttl = 0): string
    {
        $suffix = $ttl > 0 ? sprintf('，%d 分钟内有效', $ttl) : '';

        return sprintf('如果该邮箱已注册，重置验证码已发送至 %s%s。', mask_email($email), $suffix);
    }

    /**
     * 重置密码页地址（带上邮箱便于预填）
     */
    private function resetUrl(string $email): string
    {
        return url('/password/reset?email=' . rawurlencode($email));
    }
}
