<?php

declare(strict_types=1);

/**
 * 后台主布局
 *
 * @var string $content 视图正文（已是 HTML）
 * @var string|null $pageTitle 页面标题（可选）
 */

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Setting;
use App\Support\Theme;

$siteName  = Setting::string('site_name', 'CourseShop');
$pageTitle = $pageTitle ?? null;

$htmlTitle   = ($pageTitle !== null && $pageTitle !== '') ? $pageTitle . ' - 后台管理 - ' . $siteName : '后台管理 - ' . $siteName;

$defaultMode    = Setting::string('theme_default_mode', 'system');
$modeJson       = json_encode($defaultMode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$canSwitchTheme = Setting::bool('theme_allow_user_switch', true);
$adminUser      = Auth::user();

// 与前台一致：后台也优先使用账号保存的主题偏好，避免前后台深浅色不一致
$adminThemeMode = null;

if ($adminUser !== null && in_array((string) ($adminUser['dark_mode'] ?? ''), ['light', 'dark', 'system'], true)) {
    $adminThemeMode = (string) $adminUser['dark_mode'];
}

$userModeJson = json_encode($adminThemeMode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($htmlTitle) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <script nonce="<?= e(csp_nonce()) ?>">
        // 尽早应用主题，避免首屏闪烁（FOUC）
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
    <link rel="icon" href="<?= e(favicon_url()) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <style><?= Theme::css() ?></style>
</head>
<body class="admin-body">

<div class="admin-shell">
    <?php require VIEW_PATH . '/admin/partials/sidebar.php'; ?>

    <div class="admin-scrim" data-admin-toggle aria-hidden="true"></div>

    <div class="admin-main">
        <header class="admin-topbar">
            <button type="button" class="admin-icon-btn admin-topbar__toggle" data-admin-toggle
                    aria-label="打开后台菜单" aria-expanded="false" aria-controls="admin-sidebar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M3 6h18M3 12h18M3 18h18"></path>
                </svg>
            </button>

            <h1 class="admin-topbar__title"><?= e($pageTitle !== null && $pageTitle !== '' ? $pageTitle : '后台管理') ?></h1>

            <div class="admin-topbar__actions">
                <?php if ($canSwitchTheme): ?>
                    <button type="button" class="admin-icon-btn" data-theme-toggle aria-label="切换深浅色主题" title="切换主题"
                            data-theme-save="<?= e(url('/account/theme')) ?>">
                        <span class="theme-toggle__icon--sun" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <circle cx="12" cy="12" r="4"></circle>
                                <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                            </svg>
                        </span>
                        <span class="theme-toggle__icon--moon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"></path>
                            </svg>
                        </span>
                    </button>
                <?php endif; ?>

                <?php if ($adminUser !== null): ?>
                    <span class="admin-user hide-sm"><?= e((string) $adminUser['username']) ?></span>
                <?php endif; ?>
            </div>
        </header>

        <div class="admin-content">
            <?php require VIEW_PATH . '/partials/flash.php'; ?>

            <?= $content ?>
        </div>
    </div>
</div>

<script src="<?= asset('assets/js/app.js') ?>" defer></script>
</body>
</html>
