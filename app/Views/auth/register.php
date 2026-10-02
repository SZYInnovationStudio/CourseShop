<?php

declare(strict_types=1);

/**
 * 注册页
 *
 * @var bool $captchaRequired 是否要求图形验证码
 * @var bool $forceEmailBind 是否强制绑定邮箱
 * @var array<string, mixed>|null $terms 当前用户协议记录，可能为 null
 * @var array<string, mixed>|null $privacy 当前隐私政策记录，可能为 null
 */
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-card__title">创建账号</h1>
        <p class="auth-card__subtitle">注册即可开始选购课程，全程无需邮箱。</p>

        <?php if ($forceEmailBind): ?>
            <div class="alert alert--warning" role="alert">
                <span class="grow">本站已开启强制邮箱绑定：注册后需先绑定邮箱，才能使用购买、学习等功能。</span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= url('/register') ?>" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="username">用户名<span class="required">*</span></label>
                <input class="input" type="text" id="username" name="username"
                       value="<?= e(old('username')) ?>" autocomplete="username"
                       minlength="3" maxlength="20" required autofocus>
                <p class="form-hint">3-20 位字母、数字或下划线。</p>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">密码<span class="required">*</span></label>
                <div class="input-group">
                    <input class="input" type="password" id="password" name="password"
                           autocomplete="new-password" minlength="8" maxlength="64" required>
                    <button type="button" class="input-group__suffix" data-password-toggle aria-label="显示密码">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
                <p class="form-hint">至少 8 位字符。</p>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">确认密码<span class="required">*</span></label>
                <input class="input" type="password" id="password_confirm" name="password_confirm"
                       autocomplete="new-password" minlength="8" maxlength="64" required>
            </div>

            <?php if ($captchaRequired): ?>
                <div class="form-group">
                    <label class="form-label" for="captcha">图形验证码<span class="required">*</span></label>
                    <div class="captcha">
                        <input class="input" type="text" id="captcha" name="captcha"
                               autocomplete="off" maxlength="6" placeholder="请输入右侧字符" required>
                        <img class="captcha__image" src="<?= url('/captcha?scene=register') ?>"
                             alt="图形验证码" title="点击刷新验证码" data-captcha-image>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="checkbox">
                    <input type="checkbox" name="agree" value="1" required>
                    <span>
                        我已阅读并同意
                        <a href="<?= url('/agreements/terms') ?>" target="_blank" rel="noopener">《用户协议》</a>
                        与
                        <a href="<?= url('/agreements/privacy') ?>" target="_blank" rel="noopener">《隐私政策》</a>
                    </span>
                </label>
            </div>

            <button class="btn btn--block" type="submit">注册</button>
        </form>

        <div class="auth-card__footer">
            已有账号？<a href="<?= url('/login') ?>">直接登录</a>
        </div>
    </div>
</div>
