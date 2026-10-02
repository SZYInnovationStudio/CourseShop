<?php

declare(strict_types=1);

/**
 * 页头：品牌、主导航、用户操作、主题切换、移动端抽屉
 */

use App\Support\Auth;
use App\Support\Request;
use App\Support\Setting;

$siteName  = Setting::string('site_name', 'CourseShop');
$siteLogo  = Setting::string('site_logo', '');
$current   = Request::path();
$user      = Auth::user();

$canSwitchTheme = Setting::bool('theme_allow_user_switch', true);
$canRegister    = Setting::bool('register_enabled', true);

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
        <button type="button" class="nav-back" data-history-back aria-label="返回上一页" title="返回">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 18l-6-6 6-6"></path>
            </svg>
        </button>

        <a class="brand" href="<?= url('/') ?>">
            <?php if ($siteLogo !== ''): ?>
                <img class="brand__logo" src="<?= e(url($siteLogo)) ?>" alt="<?= e($siteName) ?>">
            <?php else: ?>
                <img class="brand__logo" src="<?= e(favicon_url()) ?>" alt="<?= e($siteName) ?>">
                <span><?= e($siteName) ?></span>
            <?php endif; ?>
        </a>

        <nav class="site-nav" aria-label="主导航">
            <a class="site-nav__link<?= $activeClass('/') ?>" href="<?= url('/') ?>">首页</a>
            <a class="site-nav__link<?= $activeClass('/courses') ?>" href="<?= url('/courses') ?>">全部课程</a>
            <a class="site-nav__link<?= $activeClass('/packages') ?>" href="<?= url('/packages') ?>">优惠套餐</a>
        </nav>

        <div class="header-actions">
            <div class="flex-center gap-2 hide-sm">
                <?php if ($user === null): ?>
                    <a class="btn btn--ghost btn--sm" href="<?= url('/login') ?>">登录</a>
                    <?php if ($canRegister): ?>
                        <a class="btn btn--sm" href="<?= url('/register') ?>">注册</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="btn btn--ghost btn--sm" href="<?= url('/my/courses') ?>">我的课程</a>
                    <?php if (Auth::isAdmin()): ?>
                        <a class="btn btn--ghost btn--sm" href="<?= url('/admin') ?>">管理后台</a>
                    <?php endif; ?>
                    <form class="header-form" method="post" action="<?= url('/logout') ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn--outline btn--sm" type="submit">退出</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($canSwitchTheme): ?>
                <button type="button" class="theme-toggle" data-theme-toggle aria-label="切换深浅色主题" title="切换主题"
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

            <button type="button" class="nav-toggle" data-nav-toggle aria-label="打开菜单" aria-expanded="false" aria-controls="site-drawer">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M3 6h18M3 12h18M3 18h18"></path>
                </svg>
            </button>
        </div>
    </div>

    <div class="site-drawer" id="site-drawer" data-drawer>
        <div class="container">
            <a class="site-drawer__link<?= $activeClass('/') ?>" href="<?= url('/') ?>">首页</a>
            <a class="site-drawer__link<?= $activeClass('/courses') ?>" href="<?= url('/courses') ?>">全部课程</a>
            <a class="site-drawer__link<?= $activeClass('/packages') ?>" href="<?= url('/packages') ?>">优惠套餐</a>

            <?php if ($user === null): ?>
                <a class="site-drawer__link" href="<?= url('/login') ?>">登录</a>
                <?php if ($canRegister): ?>
                    <a class="site-drawer__link" href="<?= url('/register') ?>">注册</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="site-drawer__link" href="<?= url('/my/courses') ?>">我的课程</a>
                <a class="site-drawer__link" href="<?= url('/account') ?>">账户设置</a>
                <?php if (Auth::isAdmin()): ?>
                    <a class="site-drawer__link" href="<?= url('/admin') ?>">管理后台</a>
                <?php endif; ?>
                <form method="post" action="<?= url('/logout') ?>">
                    <?= csrf_field() ?>
                    <button class="site-drawer__link" type="submit">退出登录</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>
