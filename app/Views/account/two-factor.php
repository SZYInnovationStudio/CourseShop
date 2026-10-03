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

use App\Support\QrCode;
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e(t('启用两步验证')) ?></h1>
    <p class="page-head__desc"><?= e(t('使用身份验证器添加账号，并输入动态口令完成绑定。')) ?></p>
</div>

<div class="container">
    <div class="card">
        <div class="card__header"><?= e(t('第 1 步：在验证器中添加账号')) ?></div>
        <div class="card__body">
            <?php
            // 二维码容量有限：内容超长时降级为「手动密钥」绑定，避免整页报错
            $qrSvg = '';

            try {
                $qrSvg = QrCode::svg($otpauthUri, 220);
            } catch (Throwable $e) {
                $qrSvg = '';
            }
            ?>

            <?php if ($qrSvg !== ''): ?>
                <p class="form-hint settings-panel__desc mb-0">
                    <?= e(t('用验证器扫描下方二维码，即可自动添加账号。')) ?>
                </p>

                <div class="qr-box" role="img" aria-label="<?= e(t('绑定二维码')) ?>">
                    <?= $qrSvg ?>
                </div>
            <?php else: ?>
                <p class="form-hint settings-panel__desc mb-0">
                    <?= e(t('当前账号信息过长，无法生成二维码，请使用下方的手动密钥完成绑定。')) ?>
                </p>
            <?php endif; ?>

            <details class="qr-fallback"<?= $qrSvg === '' ? ' open' : '' ?>>
                <summary><?= e(t('无法扫码？展开手动输入')) ?></summary>
                <div class="qr-fallback__body">
                    <p class="form-hint settings-panel__desc">
                        <?= e(t('打开 Google Authenticator、Microsoft Authenticator 或 1Password 等应用，选择「手动输入密钥」，填入以下信息。')) ?>
                    </p>

                    <div class="form-group">
                        <label class="form-label" for="totp_secret"><?= e(t('密钥（Base32）')) ?></label>
                        <div class="input-group">
                            <input class="input" type="text" id="totp_secret" value="<?= e($secretText) ?>" readonly>
                            <button type="button" class="input-group__suffix" data-copy-target="totp_secret"
                                    data-copied-label="<?= e(t('已复制')) ?>"><?= e(t('复制')) ?></button>
                        </div>
                        <p class="form-hint"><?= e(t('类型选择「基于时间（TOTP）」，位数 6 位，周期 30 秒。')) ?></p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="otpauth_uri"><?= e(t('导入链接（otpauth）')) ?></label>
                        <div class="input-group">
                            <input class="input" type="text" id="otpauth_uri" value="<?= e($otpauthUri) ?>" readonly>
                            <button type="button" class="input-group__suffix" data-copy-target="otpauth_uri"
                                    data-copied-label="<?= e(t('已复制')) ?>"><?= e(t('复制')) ?></button>
                        </div>
                        <p class="form-hint"><?= e(t('如果验证器支持链接导入，可直接复制上面的地址。')) ?></p>
                    </div>
                </div>
            </details>

            <p class="form-hint">
                <?= e(t('验证器无法扫描？可')) ?>
                <a href="<?= url('/account/2fa/setup?regenerate=1') ?>"><?= e(t('重新生成密钥')) ?></a><?= e(t('。')) ?>
            </p>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card__header"><?= e(t('第 2 步：输入动态口令完成绑定')) ?></div>
        <div class="card__body">
            <form method="post" action="<?= url('/account/2fa/enable') ?>" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label" for="code"><?= e(t('6 位动态口令')) ?><span class="required">*</span></label>
                    <input class="input" type="text" id="code" name="code"
                           inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           autocomplete="one-time-code" placeholder="000000" required autofocus>
                    <p class="form-hint"><?= e(t('输入验证器中当前显示的 6 位数字，以确认绑定成功。')) ?></p>
                </div>
                <div class="btn-group">
                    <button class="btn" type="submit"><?= e(t('确认启用')) ?></button>
                    <a class="btn btn--outline" href="<?= url('/account?tab=security') ?>"><?= e(t('取消')) ?></a>
                </div>
            </form>
        </div>
    </div>
</div>