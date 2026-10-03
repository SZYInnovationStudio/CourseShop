<?php

declare(strict_types=1);

/**
 * 主布局
 *
 * @var string $content 视图正文（已是 HTML）
 * @var string|null $pageTitle 页面标题（可选）
 */

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\I18n;
use App\Support\Setting;

$siteName  = Setting::string('site_name', 'CourseShop');
$pageTitle = $pageTitle ?? null;
// 页面级 body 类名（如课程详情隐藏底部导航、预留购买栏空间）
$bodyClass = trim((string) ($bodyClass ?? ''));

$htmlTitle   = ($pageTitle !== null && $pageTitle !== '') ? $pageTitle . ' - ' . $siteName : $siteName;
$description = Setting::string('site_description', '');
$keywords    = Setting::string('site_keywords', '');
$primary     = Setting::string('theme_primary_color', '#4F6F52');

// 暗色模式下的品牌色：向白色混合 32%，避免深色背景上对比度过低
$primaryDark = $primary;
if (preg_match('/^#([0-9a-f]{6})$/i', $primary, $matched) === 1) {
    $lighten = static fn (int $channel): int => (int) round($channel + (255 - $channel) * 0.32);
    $primaryDark = sprintf(
        '#%02X%02X%02X',
        $lighten((int) hexdec(substr($matched[1], 0, 2))),
        $lighten((int) hexdec(substr($matched[1], 2, 2))),
        $lighten((int) hexdec(substr($matched[1], 4, 2)))
    );
}

// 主题默认值：登录用户优先使用账号保存的偏好，其次站点默认
$defaultMode = Setting::string('theme_default_mode', 'system');
$layoutUser  = Auth::user();
$userMode    = null;

if ($layoutUser !== null && in_array((string) ($layoutUser['dark_mode'] ?? ''), ['light', 'dark', 'system'], true)) {
    $userMode = (string) $layoutUser['dark_mode'];
}

$modeJson     = json_encode($defaultMode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$userModeJson = json_encode($userMode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::htmlLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($htmlTitle) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <?php if ($keywords !== ''): ?>
        <meta name="keywords" content="<?= e($keywords) ?>">
    <?php endif; ?>
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <link rel="manifest" href="<?= url('manifest.webmanifest') ?>">
    <meta name="theme-color" content="<?= e($primary) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= e($siteName) ?>">
    <link rel="apple-touch-icon" href="<?= asset('assets/icons/icon-192.png') ?>">
    <script nonce="<?= e(csp_nonce()) ?>">
        // 尽早应用主题，避免首屏闪烁（FOUC）
        (function () {
            try {
                // 登录用户以账号偏好为准，其次本地记忆，最后站点默认
                var userMode = <?= $userModeJson ?>;
                var mode = userMode || localStorage.getItem('courseshop-theme') || <?= $modeJson ?>;
                var dark = mode === 'dark'
                    || (mode !== 'light' && window.matchMedia
                        && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="icon" href="<?= e(favicon_url()) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <style>
        :root { --color-primary: <?= e($primary) ?>; }
        html[data-theme="dark"] { --color-primary: <?= e($primaryDark) ?>; }
    </style>
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<a class="skip-link" href="#main"><?= e(t('跳到主要内容')) ?></a>

<?php require VIEW_PATH . '/partials/header.php'; ?>

<main id="main">
    <div class="container">
        <?php require VIEW_PATH . '/partials/flash.php'; ?>
    </div>

    <?= $content ?>
</main>

<?php require VIEW_PATH . '/partials/bottom-nav.php'; ?>

<?php require VIEW_PATH . '/partials/footer.php'; ?>

<script src="<?= asset('assets/js/app.js') ?>" defer></script>
<script nonce="<?= e(csp_nonce()) ?>">
    // 注册 Service Worker，提供离线访问能力（文件缺失或不支持时静默忽略）
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(<?= json_encode(url('sw.js')) ?>).catch(function () {});
        });
    }
</script>
</body>
</html>
