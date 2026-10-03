<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\EmailVerification;
use App\Models\LoginDevice;
use App\Models\User;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Setting;
use App\Support\Totp;
use RuntimeException;

/**
 * 账户中心
 *
 * 提供个人资料与头像、密码修改、主题偏好、邮箱绑定与账号注销。
 * 页面按 ?tab= 分面板展示，写操作统一走 POST + PRG。
 */
final class AccountController extends Controller
{
    /** 同一邮箱重发验证码的最小间隔（秒） */
    private const RESEND_INTERVAL = 60;

    /** 头像大小上限（字节） */
    private const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    /** 头像允许的扩展名 */
    private const AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** 头像存放目录（相对 public） */
    private const AVATAR_DIR = 'uploads/avatars';

    /** 待确认的两步验证密钥在会话中的键名 */
    private const TWO_FACTOR_SETUP_KEY = '2fa_setup_secret';

    /**
     * 账户总览（按 tab 展示对应面板）
     */
    public function index(): void
    {
        $allowedTabs = ['profile', 'password', 'security', 'theme', 'danger'];
        $tab         = Request::string('tab', 'profile');

        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'profile';
        }

        $user = $this->user();

        $data = [
            'pageTitle' => t('账户设置'),
            'profile'   => $user,
            'tab'       => $tab,
        ];

        // 仅在「登录安全」面板加载设备列表，避免其他面板产生多余查询
        if ($tab === 'security' && $user !== null) {
            $data['devices']          = LoginDevice::activeForUser((int) $user['id']);
            $data['currentToken']     = Auth::deviceToken() ?? '';
            $data['twoFactorEnabled'] = (int) ($user['two_factor_enabled'] ?? 0) === 1
                && (string) ($user['two_factor_secret'] ?? '') !== '';
        }

        $this->view('account.index', $data);
    }

    /**
     * 邮箱绑定页
     */
    public function showEmail(): void
    {
        $this->view('account.email', [
            'pageTitle'      => t('绑定邮箱'),
            'forceEmailBind' => Auth::mustBindEmail(),
            'mailEnabled'    => Mailer::enabled(),
        ]);
    }

    /**
     * 保存个人资料（昵称 + 头像）
     */
    public function updateProfile(): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $userId   = (int) $user['id'];
        $backUrl  = url('/account?tab=profile');
        $nickname = trim(Request::string('nickname'));
        $old      = ['nickname' => $nickname];

        $validator = $this->validator(Request::all())
            ->required('nickname', '昵称')
            ->max('nickname', 50, '昵称');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), $old);
        }

        $avatar  = (string) ($user['avatar'] ?? '');
        $remove  = Request::bool('remove_avatar', false);
        $file    = Request::file('avatar');
        $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            try {
                $newAvatar = self::storeAvatar($file);
            } catch (RuntimeException $e) {
                $this->fail($backUrl, $e->getMessage(), $old);
            }

            if ($avatar !== '') {
                self::deleteAvatar($avatar);
            }
            $avatar = $newAvatar;
        } elseif ($remove && $avatar !== '') {
            self::deleteAvatar($avatar);
            $avatar = '';
        }

        User::updateNickname($userId, $nickname);
        User::updateAvatar($userId, $avatar !== '' ? $avatar : null);
        Auth::refresh();

        $this->success($backUrl, t('个人资料已更新。'));
    }

    /**
     * 修改密码
     */
    public function updatePassword(): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $backUrl         = url('/account?tab=password');
        $currentPassword = Request::raw('current_password');
        $newPassword     = Request::raw('password');
        $confirmPassword = Request::raw('password_confirmation');

        $validator = $this->validator(Request::all())
            ->required('current_password', '当前密码')
            ->required('password', '新密码')
            ->min('password', 8, '新密码')
            ->max('password', 72, '新密码')
            ->required('password_confirmation', '确认新密码')
            ->same('password_confirmation', 'password', '确认新密码');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError());
        }

        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            $this->fail($backUrl, t('当前密码不正确。'));
        }

        if (hash_equals($currentPassword, $newPassword)) {
            $this->fail($backUrl, t('新密码不能与当前密码相同。'));
        }

        User::updatePassword((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));

        // 修改密码后撤销其他设备的登录态，仅保留当前会话
        LoginDevice::revokeOthers((int) $user['id'], (string) Auth::deviceToken());

        Auth::refresh();

        $this->success($backUrl, t('密码已修改，其他设备已退出登录，请妥善保管新密码。'));
    }

    /**
     * 保存主题偏好（light / dark / system）
     *
     * 支持 AJAX（返回 JSON）与普通表单提交（302 回跳）。
     */
    public function saveTheme(): void
    {
        Csrf::check();

        $user = $this->user();
        $mode = Request::string('mode', 'system');

        if (!in_array($mode, ['light', 'dark', 'system'], true)) {
            $mode = 'system';
        }

        if ($user !== null) {
            User::updateDarkMode((int) $user['id'], $mode);
            Auth::refresh();
        }

        if (Request::isAjax()) {
            Response::json(['code' => 0, 'message' => 'ok', 'mode' => $mode]);
        }

        $this->success(url('/account?tab=theme'), t('主题偏好已保存。'));
    }

    /**
     * 注销账号（软删除并匿名化，保留订单等历史数据）
     */
    public function destroy(): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $backUrl  = url('/account?tab=danger');
        $password = Request::raw('password');

        $validator = $this->validator(Request::all())
            ->required('password', '密码');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError());
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->fail($backUrl, t('密码不正确，无法注销账号。'));
        }

        if (Auth::isAdmin() && User::countAdmins() <= 1) {
            $this->fail($backUrl, t('系统需保留至少一名管理员，无法注销当前账号。'));
        }

        $avatar = (string) ($user['avatar'] ?? '');

        User::softDelete((int) $user['id']);

        if ($avatar !== '') {
            self::deleteAvatar($avatar);
        }

        Auth::logout();

        $this->success(url('/'), t('账号已注销，感谢你的使用。'));
    }

    /**
     * 发送邮箱验证码
     */
    public function sendEmailCode(): void
    {
        Csrf::check();

        $user  = $this->user();
        $email = strtolower(Request::string('email'));
        $old   = ['email' => $email];

        $validator = $this->validator(Request::all())
            ->required('email', '邮箱')
            ->email('email', '邮箱');

        if ($validator->fails()) {
            $this->fail(url('/account/email'), (string) $validator->firstError(), $old);
        }

        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        if (User::emailExists($email, (int) $user['id'])) {
            $this->fail(url('/account/email'), t('该邮箱已被其他账号绑定。'), $old);
        }

        if (EmailVerification::recentCount($email, EmailVerification::PURPOSE_BIND, self::RESEND_INTERVAL) > 0) {
            $this->fail(url('/account/email'), t('验证码发送过于频繁，请稍后再试。'), $old);
        }

        $issued = EmailVerification::issue((int) $user['id'], $email, EmailVerification::PURPOSE_BIND);
        $ttl    = max(1, (int) ceil($issued['ttl'] / 60));

        if (Mailer::sendCode($email, $issued['code'], 'bind')) {
            $this->success(url('/account/email'), t('验证码已发送至 %s，%d 分钟内有效。', [mask_email($email), $ttl]), $old);
        }

        // 邮件发送失败：调试模式下仅将验证码写入受控日志，不在接口回显，
        // 避免任何人借此获取他人邮箱的验证码
        if ((bool) Config::get('app.debug', false)) {
            Logger::warning('邮箱绑定验证码发送失败（调试模式已写入日志）', [
                'email' => $email,
                'code'  => (string) $issued['code'],
            ]);
        }

        $this->fail(url('/account/email'), t('验证码发送失败，请稍后重试或联系管理员。'), $old);
    }

    /**
     * 校验邮箱验证码并完成绑定
     */
    public function verifyEmailCode(): void
    {
        Csrf::check();

        $user  = $this->user();
        $email = strtolower(Request::string('email'));
        $code  = trim(Request::string('code'));
        $old   = ['email' => $email];

        $validator = $this->validator(Request::all())
            ->required('email', '邮箱')
            ->email('email', '邮箱')
            ->required('code', '验证码');

        if ($validator->fails()) {
            $this->fail(url('/account/email'), (string) $validator->firstError(), $old);
        }

        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $record = EmailVerification::latest($email, EmailVerification::PURPOSE_BIND);

        if ($record === null) {
            $this->fail(url('/account/email'), t('请先获取邮箱验证码。'), $old);
        }

        if ((int) $record['attempts'] >= EmailVerification::MAX_ATTEMPTS) {
            $this->fail(url('/account/email'), t('验证码错误次数过多，请重新获取。'), $old);
        }

        if (strtotime((string) $record['expires_at']) <= time()) {
            $this->fail(url('/account/email'), t('验证码已过期，请重新获取。'), $old);
        }

        if (!hash_equals((string) $record['code'], $code)) {
            EmailVerification::incrementAttempts((int) $record['id']);
            $this->fail(url('/account/email'), t('验证码不正确。'), $old);
        }

        EmailVerification::markUsed((int) $record['id']);
        User::bindEmail((int) $user['id'], $email);
        Auth::refresh();

        $this->success($this->intended('/'), t('邮箱绑定成功。'));
    }

    // ============================================================
    // 登录安全：两步验证（2FA）
    // ============================================================

    /**
     * 两步验证设置页：生成待确认密钥并展示给用户
     */
    public function showTwoFactorSetup(): void
    {
        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        if ((int) ($user['two_factor_enabled'] ?? 0) === 1) {
            $this->success(url('/account?tab=security'), t('两步验证已处于开启状态。'));
        }

        // 复用会话中已有的待确认密钥，避免校验失败重定向回本页时刷新二维码、
        // 使用户已扫码的密钥失效；仅在用户主动要求重新生成时更换。
        $secret = (string) Session::get(self::TWO_FACTOR_SETUP_KEY, '');

        if ($secret === '' || Request::bool('regenerate')) {
            $secret = Totp::generateSecret();
            Session::set(self::TWO_FACTOR_SETUP_KEY, $secret);
        }

        $account = (string) ($user['email'] ?? '') !== ''
            ? (string) $user['email']
            : (string) $user['username'];

        $this->view('account.two-factor', [
            'pageTitle'  => t('启用两步验证'),
            'secret'     => $secret,
            'secretText' => Totp::formatSecret($secret),
            'otpauthUri' => Totp::provisioningUri($secret, $account, Setting::string('site_name', 'CourseShop')),
        ]);
    }

    /**
     * 校验动态口令并正式启用两步验证
     */
    public function enableTwoFactor(): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $secret = (string) Session::get(self::TWO_FACTOR_SETUP_KEY, '');

        if ($secret === '') {
            $this->fail(url('/account/2fa/setup'), t('设置已过期，请重新生成密钥。'));
        }

        $step = Totp::verifyStep($secret, Request::string('code'));

        if ($step === null) {
            $this->fail(url('/account/2fa/setup'), t('动态口令不正确，请确认验证器时间同步后重试。'));
        }

        // 记录本次通过的时间步，避免同一验证码在有效窗口内被重复使用
        User::enableTwoFactor((int) $user['id'], $secret, $step);
        Session::forget(self::TWO_FACTOR_SETUP_KEY);
        Auth::refresh();

        $this->success(url('/account?tab=security'), t('两步验证已启用，下次登录需输入动态口令。'));
    }

    /**
     * 关闭两步验证（需验证当前密码）
     */
    public function disableTwoFactor(): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $backUrl  = url('/account?tab=security');
        $password = Request::raw('password');

        if ($password === '') {
            $this->fail($backUrl, t('请输入当前密码以关闭两步验证。'));
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->fail($backUrl, t('当前密码不正确。'));
        }

        User::disableTwoFactor((int) $user['id']);
        Session::forget(self::TWO_FACTOR_SETUP_KEY);
        Auth::refresh();

        $this->success($backUrl, t('两步验证已关闭。'));
    }

    // ============================================================
    // 登录安全：登录设备管理
    // ============================================================

    /**
     * 将指定设备踢下线（撤销其会话）
     */
    public function revokeDevice(string $id): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $backUrl  = url('/account?tab=security');
        $deviceId = (int) $id;
        $userId   = (int) $user['id'];

        if ($deviceId <= 0) {
            $this->fail($backUrl, t('指定设备不存在。'));
        }

        // 当前设备应使用「退出登录」，避免误操作将自己踢下线
        $token = LoginDevice::tokenOf($deviceId, $userId);
        if ($token !== null && $token === Auth::deviceToken()) {
            $this->fail($backUrl, t('这是当前正在使用的设备，请直接退出登录。'));
        }

        LoginDevice::revoke($deviceId, $userId);

        $this->success($backUrl, t('该设备已退出登录。'));
    }

    /**
     * 退出除当前设备外的所有设备
     */
    public function revokeOtherDevices(): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            $this->fail(url('/login'), t('登录状态已失效，请重新登录。'));
        }

        $backUrl = url('/account?tab=security');
        $token   = Auth::deviceToken() ?? '';

        $count = $token === '' ? 0 : LoginDevice::revokeOthers((int) $user['id'], $token);

        $this->success(
            $backUrl,
            $count > 0 ? t('已退出其他 %d 台设备。', [$count]) : t('当前没有其他已登录的设备。')
        );
    }

    // ============================================================
    // 头像存储
    // ============================================================

    /**
     * 保存上传的头像，返回相对 public 的路径
     *
     * @param  array<string, mixed> $file $_FILES 中的单个文件项
     * @throws RuntimeException 校验或落盘失败
     */
    private static function storeAvatar(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException(t('上传的头像文件为空。'));
        }

        if ($size > self::AVATAR_MAX_BYTES) {
            throw new RuntimeException(t('头像大小不能超过 %d MB。', [self::AVATAR_MAX_BYTES / 1024 / 1024]));
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::AVATAR_EXTENSIONS, true)) {
            throw new RuntimeException(t('头像仅支持 jpg、jpeg、png、gif、webp 格式。'));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException(t('头像上传失败，请重试。'));
        }

        // 二次确认确为图片，防止伪造扩展名
        if (@getimagesize($tmp) === false) {
            throw new RuntimeException(t('上传的文件不是有效的图片。'));
        }

        $directory = PUBLIC_PATH . '/' . self::AVATAR_DIR;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(t('无法创建头像存储目录，请检查写入权限。'));
        }

        $storedName = bin2hex(random_bytes(8)) . '.' . $extension;
        $target     = $directory . '/' . $storedName;

        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException(t('头像保存失败，请检查目录写入权限。'));
        }

        return self::AVATAR_DIR . '/' . $storedName;
    }

    /**
     * 删除头像文件（仅允许删除本模块管理的目录，文件不存在时静默忽略）
     */
    private static function deleteAvatar(string $relativePath): void
    {
        $relativePath = ltrim($relativePath, '/');

        if (!str_starts_with($relativePath, self::AVATAR_DIR . '/')) {
            return;
        }

        // 用 realpath 规范化后，再确认目标确实位于头像目录内，防止 symlink / .. 穿越
        $base = realpath(PUBLIC_PATH . '/' . self::AVATAR_DIR);
        $real = realpath(PUBLIC_PATH . '/' . $relativePath);

        if ($base === false || $real === false) {
            return;
        }

        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_file($real)) {
            @unlink($real);
        }
    }

    /**
     * 上传错误码 -> 中文提示
     */
    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t('头像大小超过服务器限制。'),
            UPLOAD_ERR_PARTIAL                        => t('头像上传不完整，请重试。'),
            UPLOAD_ERR_NO_FILE                        => t('请选择要上传的头像文件。'),
            UPLOAD_ERR_NO_TMP_DIR                     => t('服务器缺少临时目录，请联系管理员。'),
            UPLOAD_ERR_CANT_WRITE                     => t('服务器写入文件失败，请联系管理员。'),
            default                                   => t('头像上传失败，请重试。'),
        };
    }
}
