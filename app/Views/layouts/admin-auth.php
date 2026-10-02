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

$siteName  = Setting::string('site_name', 'CourseShop');
$pageTitle = $pageTitle ?? null;

$htmlTitle   = ($pageTitle !== null && $pageTitle !== '') ? $pageTitle . ' - ' . $siteName : '后台管理 - ' . $siteName;
$primary     = Setting::string('theme_primary_color', '#4F6F52');

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
    <style>
        :root { --color-primary: <?= e($primary) ?>; }
        html[data-theme="dark"] { --color-primary: <?= e($primaryDark) ?>; }
    </style>
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
