<?php

declare(strict_types=1);

/**
 * 后台两步验证（2FA）动态口令校验页
 *
 * 后台登录流程第二步：账号密码校验通过后跳转到此页，
 * 输入身份验证器中的 6 位动态口令完成登录。
 */
?>
<h1 class="admin-auth__title">两步验证</h1>
<p class="admin-auth__desc">请输入身份验证器中显示的 6 位动态口令以进入后台。</p>

<form method="post" action="<?= url('/admin/login/2fa') ?>" novalidate>
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

<div class="admin-auth__foot">
    <a href="<?= url('/admin/login') ?>">返回重新登录</a>
</div>
