<?php

declare(strict_types=1);

/**
 * 页头：品牌、主导航、用户操作、主题切换、移动端抽屉
 */

use App\Support\Auth;
use App\Support\I18n;
use App\Support\Request;
use App\Support\Setting;

$siteName  = Setting::string('site_name', 'CourseShop');
$siteLogo  = Setting::string('site_logo', '');
$current   = Request::path();
$user      = Auth::user();

$canSwitchTheme = Setting::bool('theme_allow_user_switch', true);
$canRegister    = Setting::bool('register_enabled', true);
$showPackages   = Setting::bool('packages_enabled', true);

// 语言切换：仅在启用两种及以上语言时展示
$locales        = I18n::enabled();
$showLangSwitch = count($locales) > 1;
$currentLocale  = I18n::current();

// 主导航已覆盖的顶级页面：底部导航 / 顶部导航已提供入口，
// 这些页面不再渲染返回箭头，避免出现「无处可返」的冗余按钮
$rootPaths   = ['/', '/courses', '/packages', '/my/courses', '/tickets', '/account'];
$currentPath = rtrim($current, '/');
$currentPath = $currentPath === '' ? '/' : $currentPath;
$isRootPage  = in_array($currentPath, $rootPaths, true);

// 固定返回目标（如学习页固定返回课程目录）
// 设置后返回按钮不再逐条回退历史，避免在同类页面之间反复返回
$backUrl   = trim((string) ($backUrl ?? ''));
$backLabel = $backUrl !== '' ? t('返回') : t('返回上一页');

/** 导航高亮 */
$activeClass = static function (string $path) use ($current): string {
    if ($path === '/') {
        return $current === '/' ? ' is-active' : '';
    }

    return str_starts_with($current, $path) ? ' is-active' : '';
};
?>
<header class="site-header">
    <div class="container site-header__inner">
        <?php if (!$isRootPage): ?>
            <button type="button" class="nav-back" data-history-back<?= $backUrl !== '' ? ' data-back-url="' . e($backUrl) . '"' : '' ?>
                    aria-label="<?= e($backLabel) ?>" title="<?= e($backLabel) ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6"></path>
                </svg>
            </button>
        <?php endif; ?>

        <a class="brand" href="<?= url('/') ?>">
            <?php if ($siteLogo !== ''): ?>
                <img class="brand__logo" src="<?= e(url($siteLogo)) ?>" alt="<?= e($siteName) ?>">
            <?php else: ?>
                <img class="brand__logo" src="<?= e(favicon_url()) ?>" alt="<?= e($siteName) ?>">
                <span><?= e($siteName) ?></span>
            <?php endif; ?>
        </a>

        <nav class="site-nav" aria-label="<?= e(t('主导航')) ?>">
            <a class="site-nav__link<?= $activeClass('/') ?>" href="<?= url('/') ?>"><?= e(t('首页')) ?></a>
            <a class="site-nav__link<?= $activeClass('/courses') ?>" href="<?= url('/courses') ?>"><?= e(t('全部课程')) ?></a>
            <?php if ($showPackages): ?>
                <a class="site-nav__link<?= $activeClass('/packages') ?>" href="<?= url('/packages') ?>"><?= e(t('优惠套餐')) ?></a>
            <?php endif; ?>
        </nav>

        <div class="header-actions">
            <div class="flex-center gap-2 hide-sm">
                <?php if ($user === null): ?>
                    <a class="btn btn--ghost btn--sm" href="<?= url('/login') ?>"><?= e(t('登录')) ?></a>
                    <?php if ($canRegister): ?>
                        <a class="btn btn--sm" href="<?= url('/register') ?>"><?= e(t('注册')) ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php
                    $displayName = (string) ($user['nickname'] ?? '') !== ''
                        ? (string) $user['nickname']
                        : (string) ($user['username'] ?? '');
                    $avatar  = (string) ($user['avatar'] ?? '');
                    $initial = mb_substr($displayName !== '' ? $displayName : '?', 0, 1);
                    ?>
                    <div class="user-menu" data-user-menu>
                        <button type="button" class="user-menu__trigger" data-user-menu-toggle
                                aria-haspopup="true" aria-expanded="false" aria-controls="user-menu-panel">
                            <span class="avatar avatar--sm">
                                <?php if ($avatar !== ''): ?>
                                    <img src="<?= e(asset($avatar)) ?>" alt="">
                                <?php else: ?>
                                    <span class="avatar__fallback"><?= e($initial) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="user-menu__name"><?= e($displayName) ?></span>
                            <svg class="user-menu__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true">
                                <path d="M6 9l6 6 6-6"></path>
                            </svg>
                        </button>

                        <div class="user-menu__panel" id="user-menu-panel" data-user-menu-panel>
                            <a class="user-menu__item" href="<?= url('/my/courses') ?>"><?= e(t('我的课程')) ?></a>
                            <a class="user-menu__item" href="<?= url('/tickets') ?>"><?= e(t('我的工单')) ?></a>
                            <a class="user-menu__item" href="<?= url('/account') ?>"><?= e(t('账户设置')) ?></a>
                            <?php if (Auth::isAdmin()): ?>
                                <a class="user-menu__item" href="<?= url('/admin') ?>"><?= e(t('管理后台')) ?></a>
                            <?php endif; ?>
                            <form class="user-menu__form" method="post" action="<?= url('/logout') ?>">
                                <?= csrf_field() ?>
                                <button class="user-menu__item user-menu__item--action" type="submit"><?= e(t('退出登录')) ?></button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($showLangSwitch): ?>
                <details class="lang-menu" data-lang-menu>
                    <summary class="lang-menu__trigger" aria-label="<?= e(t('切换语言')) ?>" title="<?= e(t('切换语言')) ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M3 12h18M12 3c2.5 2.5 3.8 5.6 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.6-3.8-9S9.5 5.5 12 3z"></path>
                        </svg>
                        <span class="lang-menu__label"><?= e(I18n::label($currentLocale)) ?></span>
                    </summary>
                    <div class="lang-menu__panel">
                        <?php foreach ($locales as $locale): ?>
                            <a class="lang-menu__item<?= $locale === $currentLocale ? ' is-active' : '' ?>"
                               href="<?= e(I18n::switchUrl($locale)) ?>" hreflang="<?= e(I18n::htmlLang($locale)) ?>"
                               lang="<?= e(I18n::htmlLang($locale)) ?>"><?= e(I18n::label($locale)) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if ($canSwitchTheme): ?>
                <button type="button" class="theme-toggle" data-theme-toggle aria-label="<?= e(t('切换深浅色主题')) ?>" title="<?= e(t('切换主题')) ?>"
                        <?= $user !== null ? 'data-theme-save="' . e(url('/account/theme')) . '"' : '' ?>>
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

            <button type="button" class="nav-toggle" data-nav-toggle aria-label="<?= e(t('打开菜单')) ?>" aria-expanded="false" aria-controls="site-drawer"
                    data-label-open="<?= e(t('打开菜单')) ?>" data-label-close="<?= e(t('关闭菜单')) ?>">
                <span class="nav-toggle__icons" aria-hidden="true">
                    <svg class="nav-toggle__icon nav-toggle__icon--menu" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M3 6h18M3 12h18M3 18h18"></path>
                    </svg>
                    <svg class="nav-toggle__icon nav-toggle__icon--close" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M6 6l12 12M18 6 6 18"></path>
                    </svg>
                </span>
            </button>
        </div>
    </div>

    <div class="site-drawer" id="site-drawer" data-drawer>
        <div class="site-drawer__panel">
            <div class="container">
                <a class="site-drawer__link<?= $activeClass('/') ?>" href="<?= url('/') ?>"><?= e(t('首页')) ?></a>
                <a class="site-drawer__link<?= $activeClass('/courses') ?>" href="<?= url('/courses') ?>"><?= e(t('全部课程')) ?></a>
                <?php if ($showPackages): ?>
                    <a class="site-drawer__link<?= $activeClass('/packages') ?>" href="<?= url('/packages') ?>"><?= e(t('优惠套餐')) ?></a>
                <?php endif; ?>

                <?php if ($user === null): ?>
                    <a class="site-drawer__link" href="<?= url('/login') ?>"><?= e(t('登录')) ?></a>
                    <?php if ($canRegister): ?>
                        <a class="site-drawer__link" href="<?= url('/register') ?>"><?= e(t('注册')) ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="site-drawer__link" href="<?= url('/my/courses') ?>"><?= e(t('我的课程')) ?></a>
                    <a class="site-drawer__link" href="<?= url('/tickets') ?>"><?= e(t('我的工单')) ?></a>
                    <a class="site-drawer__link" href="<?= url('/account') ?>"><?= e(t('账户设置')) ?></a>
                    <?php if (Auth::isAdmin()): ?>
                        <a class="site-drawer__link" href="<?= url('/admin') ?>"><?= e(t('管理后台')) ?></a>
                    <?php endif; ?>
                    <form method="post" action="<?= url('/logout') ?>">
                        <?= csrf_field() ?>
                        <button class="site-drawer__link" type="submit"><?= e(t('退出登录')) ?></button>
                    </form>
                <?php endif; ?>

                <?php if ($showLangSwitch): ?>
                    <div class="site-drawer__section">
                        <span class="site-drawer__section-label"><?= e(t('语言')) ?></span>
                        <div class="site-drawer__langs">
                            <?php foreach ($locales as $locale): ?>
                                <a class="site-drawer__lang<?= $locale === $currentLocale ? ' is-active' : '' ?>"
                                   href="<?= e(I18n::switchUrl($locale)) ?>" hreflang="<?= e(I18n::htmlLang($locale)) ?>"
                                   lang="<?= e(I18n::htmlLang($locale)) ?>"><?= e(I18n::label($locale)) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
