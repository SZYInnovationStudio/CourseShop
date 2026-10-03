<?php

declare(strict_types=1);

/**
 * 找回密码 - 第一步：验证邮箱
 *
 * @var bool $captchaRequired 是否要求图形验证码
 * @var bool $mailEnabled SMTP 是否可用
 */
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-card__title"><?= e(t('找回密码')) ?></h1>
        <p class="auth-card__subtitle"><?= e(t('输入注册时绑定的邮箱，我们会发送一封包含验证码的邮件。')) ?></p>

        <?php if (!$mailEnabled): ?>
            <div class="alert alert--info" role="alert">
                <span class="grow"><?= e(t('站点尚未启用邮件发送，请联系管理员完成 SMTP 配置后再试。')) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= url('/password/forgot') ?>" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="email"><?= e(t('邮箱地址')) ?><span class="required">*</span></label>
                <input class="input" type="email" id="email" name="email"
                       value="<?= e(old('email')) ?>" autocomplete="email"
                       maxlength="120" placeholder="you@example.com" required autofocus>
            </div>

            <?php if ($captchaRequired): ?>
                <div class="form-group">
                    <label class="form-label" for="captcha"><?= e(t('图形验证码')) ?><span class="required">*</span></label>
                    <div class="captcha">
                        <input class="input" type="text" id="captcha" name="captcha"
                               autocomplete="off" maxlength="6" placeholder="<?= e(t('请输入右侧字符')) ?>" required>
                        <img class="captcha__image" src="<?= url('/captcha?scene=reset') ?>"
                             alt="<?= e(t('图形验证码')) ?>" title="<?= e(t('点击刷新验证码')) ?>" data-captcha-image>
                    </div>
                </div>
            <?php endif; ?>

            <button class="btn btn--block" type="submit"><?= e(t('发送重置验证码')) ?></button>
        </form>

        <div class="auth-card__footer">
            <?= e(t('想起密码了？')) ?><a href="<?= url('/login') ?>"><?= e(t('返回登录')) ?></a>
        </div>
    </div>
</div>
