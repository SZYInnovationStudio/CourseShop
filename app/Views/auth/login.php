<?php

declare(strict_types=1);

/**
 * 登录页
 *
 * @var bool $captchaRequired 是否要求图形验证码
 */

use App\Support\Setting;

$canRegister = Setting::bool('register_enabled', true);
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-card__title">欢迎回来</h1>
        <p class="auth-card__subtitle">登录后即可购买课程、继续学习。</p>

        <form method="post" action="<?= url('/login') ?>" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="username">用户名<span class="required">*</span></label>
                <input class="input" type="text" id="username" name="username"
                       value="<?= e(old('username')) ?>" autocomplete="username"
                       maxlength="20" required autofocus>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">密码<span class="required">*</span></label>
                <div class="input-group">
                    <input class="input" type="password" id="password" name="password"
                           autocomplete="current-password" required>
                    <button type="button" class="input-group__suffix" data-password-toggle aria-label="显示密码">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <?php if ($captchaRequired): ?>
                <div class="form-group">
                    <label class="form-label" for="captcha">图形验证码<span class="required">*</span></label>
                    <div class="captcha">
                        <input class="input" type="text" id="captcha" name="captcha"
                               autocomplete="off" maxlength="6" placeholder="请输入右侧字符" required>
                        <img class="captcha__image" src="<?= url('/captcha?scene=login') ?>"
                             alt="图形验证码" title="点击刷新验证码" data-captcha-image>
                    </div>
                    <p class="form-hint">登录失败次数较多，请输入验证码后继续。</p>
                </div>
            <?php endif; ?>

            <button class="btn btn--block" type="submit">登录</button>
        </form>

        <div class="auth-card__footer">
            <a href="<?= url('/password/forgot') ?>">忘记密码？</a>
        </div>

        <?php if ($canRegister): ?>
            <div class="auth-card__footer">
                还没有账号？<a href="<?= url('/register') ?>">立即注册</a>
            </div>
        <?php endif; ?>

        <div class="auth-card__footer">
            <a href="<?= url('/ticket/appeal') ?>">账号被封禁无法登录？提交申诉</a>
        </div>
    </div>
</div>
