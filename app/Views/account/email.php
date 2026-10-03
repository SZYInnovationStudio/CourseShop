<?php

declare(strict_types=1);

/**
 * 绑定邮箱
 *
 * @var bool $forceEmailBind 是否强制绑定邮箱
 * @var bool $mailEnabled SMTP 是否可用
 */

use App\Support\Setting;

$ttlMinutes = max(1, (int) ceil(max(60, Setting::int('mail_code_ttl', 600)) / 60));
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e(t('绑定邮箱')) ?></h1>
    <p class="page-head__desc"><?= e(t('绑定后可用于接收通知、找回账号。')) ?></p>
</div>

<div class="container">
    <div class="card card--flat">
        <div class="card__body">
            <?php if ($forceEmailBind): ?>
                <div class="alert alert--warning" role="alert">
                    <span class="grow"><?= e(t('本站已开启强制邮箱绑定：完成绑定前，账号无法进行购买、学习等操作。')) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$mailEnabled): ?>
                <div class="alert alert--info" role="alert">
                    <span class="grow"><?= e(t('站点尚未启用邮件发送，请联系管理员在后台完成 SMTP 配置。')) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= url('/account/email/verify') ?>" novalidate>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="email"><?= e(t('邮箱地址')) ?><span class="required">*</span></label>
                    <input class="input" type="email" id="email" name="email"
                           value="<?= e(old('email')) ?>" autocomplete="email"
                           maxlength="120" placeholder="you@example.com" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="code"><?= e(t('邮箱验证码')) ?><span class="required">*</span></label>
                    <div class="flex gap-2">
                        <input class="input grow" type="text" id="code" name="code"
                               autocomplete="one-time-code" inputmode="numeric"
                               maxlength="6" placeholder="<?= e(t('6 位数字验证码')) ?>" required>
                        <button class="btn btn--outline" type="submit"
                                formaction="<?= url('/account/email/send') ?>" formnovalidate><?= e(t('获取验证码')) ?></button>
                    </div>
                    <p class="form-hint"><?= e(t('先点击「获取验证码」，再填写收到的验证码完成绑定，验证码 %d 分钟内有效。', [$ttlMinutes])) ?></p>
                </div>

                <button class="btn btn--block" type="submit"><?= e(t('完成绑定')) ?></button>
            </form>
        </div>
    </div>
</div>
