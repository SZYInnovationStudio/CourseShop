<?php

declare(strict_types=1);

/**
 * 跳转到支付网关的中间页（不使用布局）
 *
 * @var string $action 网关提交地址
 * @var array<string, string> $params 表单字段
 */

use App\Support\Auth;
use App\Support\I18n;
use App\Support\Setting;
use App\Support\Theme;

$siteName = Setting::localized('site_name', 'CourseShop');

// 与主布局一致：登录用户以账号偏好为准，其次本地记忆，最后站点默认
$defaultMode = Setting::string('theme_default_mode', 'system');
$authUser    = Auth::user();
$userMode    = ($authUser !== null && in_array((string) ($authUser['dark_mode'] ?? ''), ['light', 'dark', 'system'], true))
    ? (string) $authUser['dark_mode']
    : null;

$modeJson     = json_encode($defaultMode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$userModeJson = json_encode($userMode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::htmlLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(t('正在跳转到支付页面')) ?> - <?= e($siteName) ?></title>
    <meta name="theme-color" content="<?= e(Theme::primary()) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <style><?= Theme::css() ?></style>
    <script nonce="<?= e(csp_nonce()) ?>">
        // 尽早应用主题，避免支付跳转页与站内其它页面出现配色跳变
        (function () {
            try {
                var userMode = <?= $userModeJson ?>;
                var mode = userMode || localStorage.getItem('courseshop-theme') || <?= $modeJson ?>;
                document.documentElement.setAttribute('data-theme-mode', mode);
                var dark = mode === 'dark'
                    || (mode !== 'light' && window.matchMedia
                        && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
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
