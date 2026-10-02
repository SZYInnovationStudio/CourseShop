<?php

declare(strict_types=1);

/**
 * 两步验证（2FA）动态口令校验页
 *
 * 登录流程第二步：账号密码校验通过后跳转到此页，输入验证器中的 6 位动态口令完成登录。
 *
 * @var string $pageTitle 页面标题
 */
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-card__title">两步验证</h1>
        <p class="auth-card__subtitle">请输入身份验证器中显示的 6 位动态口令以完成登录。</p>

        <form method="post" action="<?= url('/login/2fa') ?>" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="code">动态口令<span class="required">*</span></label>
                <input class="input" type="text" id="code" name="code"
                       inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                       maxlength="6" placeholder="000000" required autofocus>
                <p class="form-hint">口令每 30 秒更新一次，请留意当前剩余时间。</p>
            </div>

            <button class="btn btn--block" type="submit">验证并登录</button>
        </form>

        <div class="auth-card__footer">
            <a href="<?= url('/login') ?>">返回重新登录</a>
        </div>
    </div>
</div>
