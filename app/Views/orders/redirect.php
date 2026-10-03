<?php

declare(strict_types=1);

/**
 * 跳转到支付网关的中间页（不使用布局）
 *
 * @var string $action 网关提交地址
 * @var array<string, string> $params 表单字段
 */

use App\Support\I18n;
use App\Support\Setting;

$siteName = Setting::string('site_name', 'CourseShop');
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::htmlLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(t('正在跳转到支付页面')) ?> - <?= e($siteName) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>
<div class="container">
    <div class="empty-state">
        <div class="empty-state__icon" aria-hidden="true">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path></svg>
        </div>
        <p><?= e(t('正在跳转到支付页面，请稍候…')) ?></p>
        <p class="mb-0 text-muted"><?= e(t('如果浏览器没有自动跳转，请点击下面的按钮。')) ?></p>
    </div>

    <form id="payForm" method="post" action="<?= e($action) ?>" class="text-center">
        <?php foreach ($params as $name => $value): ?>
            <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
        <?php endforeach; ?>
        <button class="btn btn--lg" type="submit"><?= e(t('继续支付')) ?></button>
    </form>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
    document.getElementById('payForm').submit();
</script>
</body>
</html>
