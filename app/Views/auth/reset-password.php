<?php

declare(strict_types=1);

/**
 * 找回密码 - 第二步：输入验证码并设置新密码
 *
 * @var string $email 待重置密码的邮箱（用于预填与隐藏域提交）
 */

use App\Support\Setting;

$ttlMinutes = max(1, (int) ceil(max(60, Setting::int('mail_code_ttl', 600)) / 60));
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-card__title"><?= e(t('重置密码')) ?></h1>
        <p class="auth-card__subtitle"><?= e(t('请输入邮件中收到的验证码，并设置新密码。')) ?></p>

        <form method="post" action="<?= url('/password/reset') ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= e($email) ?>">

            <div class="form-group">
                <label class="form-label" for="emailDisplay"><?= e(t('邮箱地址')) ?></label>
                <input class="input" type="email" id="emailDisplay"
                       value="<?= e($email) ?>" disabled>
                <p class="form-hint"><?= e(t('如需更换邮箱，请')) ?><a href="<?= url('/password/forgot') ?>"><?= e(t('重新获取验证码')) ?></a><?= e(t('。')) ?></p>
            </div>

            <div class="form-group">
                <label class="form-label" for="code"><?= e(t('邮箱验证码')) ?><span class="required">*</span></label>
                <input class="input" type="text" id="code" name="code"
                       autocomplete="one-time-code" inputmode="numeric"
                       maxlength="6" placeholder="<?= e(t('6 位数字验证码')) ?>" required autofocus>
                <p class="form-hint"><?= e(t('验证码 %d 分钟内有效。', [$ttlMinutes])) ?></p>
            </div>

            <div class="form-group">
                <label class="form-label" for="password"><?= e(t('新密码')) ?><span class="required">*</span></label>
                <div class="input-group">
                    <input class="input" type="password" id="password" name="password"
                           autocomplete="new-password" minlength="8" maxlength="64" required>
                    <button type="button" class="input-group__suffix" data-password-toggle
                            aria-label="<?= e(t('显示密码')) ?>"
                            data-label-show="<?= e(t('显示密码')) ?>" data-label-hide="<?= e(t('隐藏密码')) ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
                <p class="form-hint"><?= e(t('至少 8 位字符。')) ?></p>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm"><?= e(t('确认新密码')) ?><span class="required">*</span></label>
                <input class="input" type="password" id="password_confirm" name="password_confirm"
                       autocomplete="new-password" minlength="8" maxlength="64" required>
            </div>

            <button class="btn btn--block" type="submit"><?= e(t('重置密码')) ?></button>
        </form>

        <div class="auth-card__footer">
            <a href="<?= url('/login') ?>"><?= e(t('返回登录')) ?></a>
        </div>
    </div>
</div>
