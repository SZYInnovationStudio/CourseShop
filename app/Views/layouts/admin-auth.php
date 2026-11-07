<?php

declare(strict_types=1);

/**
 * 后台登录页布局（独立居中卡片，不含侧边栏）
 *
 * @var string $content 视图正文（已是 HTML）
 * @var string|null $pageTitle 页面标题（可选）
 */

use App\Support\Csrf;
use App\Support\Setting;
use App\Support\Theme;

$siteName  = Setting::string('site_name', 'CourseShop');
$pageTitle = $pageTitle ?? null;

$htmlTitle   = ($pageTitle !== null && $pageTitle !== '') ? $pageTitle . ' - ' . $siteName : '后台管理 - ' . $siteName;

$defaultMode = Setting::string('theme_default_mode', 'system');
$modeJson    = json_encode($defaultMode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
        (function () {
            try {
                var mode = localStorage.getItem('courseshop-theme') || <?= $modeJson ?>;
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

<main class="admin-auth">
    <div class="admin-auth__card">
        <div class="admin-auth__brand">
            <span class="admin-sidebar__mark"><?= e(mb_substr($siteName, 0, 1)) ?></span>
            <span><?= e($siteName) ?></span>
        </div>

        <?php require VIEW_PATH . '/partials/flash.php'; ?>

        <?= $content ?>
    </div>
</main>

<script src="<?= asset('assets/js/app.js') ?>" defer></script>
</body>
</html>
