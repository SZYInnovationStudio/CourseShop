<?php

declare(strict_types=1);

/**
 * 账号申诉（公开页面）
 *
 * @var bool $captchaRequired 是否要求图形验证码
 */
?>
<div class="auth-wrap">
    <div class="auth-card auth-card--wide">
        <h1 class="auth-card__title">账号申诉</h1>
        <p class="auth-card__subtitle">
            如果你的账号被封禁无法登录，可通过此表单提交申诉。请先验证账号身份，再说明申诉理由。
        </p>

        <form method="post" action="<?= url('/ticket/appeal') ?>" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="username">用户名<span class="required">*</span></label>
                <input class="input" type="text" id="username" name="username"
                       value="<?= e(old('username')) ?>" autocomplete="username" maxlength="20" required autofocus>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">账号密码<span class="required">*</span></label>
                <input class="input" type="password" id="password" name="password"
                       autocomplete="current-password" required>
                <p class="form-hint">用于验证你是该账号的所有者，密码不会被记录。</p>
            </div>

            <div class="form-group">
                <label class="form-label" for="content">申诉说明<span class="required">*</span></label>
                <textarea class="textarea" id="content" name="content" rows="6" maxlength="5000"
                          placeholder="请说明账号被封禁的情况与申诉理由（不少于 10 个字）" required><?= e(old('content', '')) ?></textarea>
            </div>

            <?php if ($captchaRequired): ?>
                <div class="form-group">
                    <label class="form-label" for="captcha">图形验证码<span class="required">*</span></label>
                    <div class="captcha">
                        <input class="input" type="text" id="captcha" name="captcha"
                               autocomplete="off" maxlength="6" placeholder="请输入右侧字符" required>
                        <img class="captcha__image" src="<?= url('/captcha?scene=appeal') ?>"
                             alt="图形验证码" title="点击刷新验证码" data-captcha-image>
                    </div>
                </div>
            <?php endif; ?>

            <button class="btn btn--block" type="submit">提交申诉</button>
        </form>

        <div class="auth-card__footer">
            <a href="<?= url('/login') ?>">返回登录</a>
        </div>
    </div>
</div>
