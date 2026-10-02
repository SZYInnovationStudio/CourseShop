<?php

declare(strict_types=1);

/**
 * 启用两步验证（2FA）设置页
 *
 * @var string $pageTitle 页面标题
 * @var string $secret 两步验证密钥（Base32，未分组）
 * @var string $secretText 已按 4 位分组的密钥，便于手动录入
 * @var string $otpauthUri 供验证器导入的 otpauth 链接
 */
?>
<div class="page-head container">
    <h1 class="page-head__title">启用两步验证</h1>
    <p class="page-head__desc">使用身份验证器添加账号，并输入动态口令完成绑定。</p>
</div>

<div class="container">
    <div class="card">
        <div class="card__header">第 1 步：在验证器中添加账号</div>
        <div class="card__body">
            <p class="form-hint settings-panel__desc">
                打开 Google Authenticator、Microsoft Authenticator 或 1Password 等应用，
                选择「手动输入密钥」，填入以下信息。
            </p>

            <div class="form-group">
                <label class="form-label" for="otpauth_uri">导入链接（otpauth）</label>
                <div class="input-group">
                    <input class="input" type="text" id="otpauth_uri" value="<?= e($otpauthUri) ?>" readonly>
                    <button type="button" class="input-group__suffix" data-copy-target="otpauth_uri">复制</button>
                </div>
                <p class="form-hint">如果验证器支持链接导入，可直接复制上面的地址。</p>
            </div>

            <div class="form-group">
                <label class="form-label" for="totp_secret">密钥（Base32）</label>
                <div class="input-group">
                    <input class="input" type="text" id="totp_secret" value="<?= e($secretText) ?>" readonly>
                    <button type="button" class="input-group__suffix" data-copy-target="totp_secret">复制</button>
                </div>
                <p class="form-hint">类型选择「基于时间（TOTP）」，位数 6 位，周期 30 秒。</p>
            </div>

            <p class="form-hint">
                验证器无法扫描？可
                <a href="<?= url('/account/2fa/setup?regenerate=1') ?>">重新生成密钥</a>。
            </p>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card__header">第 2 步：输入动态口令完成绑定</div>
        <div class="card__body">
            <form method="post" action="<?= url('/account/2fa/enable') ?>" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label" for="code">6 位动态口令<span class="required">*</span></label>
                    <input class="input" type="text" id="code" name="code"
                           inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           autocomplete="one-time-code" placeholder="000000" required autofocus>
                    <p class="form-hint">输入验证器中当前显示的 6 位数字，以确认绑定成功。</p>
                </div>
                <div class="btn-group">
                    <button class="btn" type="submit">确认启用</button>
                    <a class="btn btn--outline" href="<?= url('/account?tab=security') ?>">取消</a>
                </div>
            </form>
        </div>
    </div>
</div>
