<?php

declare(strict_types=1);

/**
 * 前台移动端底部固定导航
 *
 * 仅在窄屏（<768px）展示，包含：首页、课程、我的、工单、设置。
 * 未登录时「我的 / 工单 / 设置」统一引导至登录页。
 * 底部通过 env(safe-area-inset-bottom) 适配全面屏手势条。
 */

use App\Support\Auth;
use App\Support\Request;

$current = Request::path();
$user    = Auth::user();

/** 判断某个路径前缀是否命中当前页面 */
$isActive = static function (array $prefixes) use ($current): bool {
    foreach ($prefixes as $prefix) {
        if ($prefix === '/') {
            if ($current === '/') {
                return true;
            }
            continue;
        }

        if (str_starts_with($current, $prefix)) {
            return true;
        }
    }

    return false;
};

$items = [
    [
        'label'    => '首页',
        'href'     => url('/'),
        'active'   => $isActive(['/']),
        'icon'     => '<path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path>',
    ],
    [
        'label'    => '课程',
        'href'     => url('/courses'),
        'active'   => $isActive(['/courses', '/course/']),
        'icon'     => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"></path><path d="M4 5.5v15"></path>',
    ],
    [
        'label'    => '我的',
        'href'     => url($user !== null ? '/my/courses' : '/login'),
        'active'   => $user !== null && $isActive(['/my/courses', '/orders', '/order/']),
        'icon'     => '<circle cx="12" cy="8" r="3.5"></circle><path d="M5 20a7 7 0 0 1 14 0"></path>',
    ],
    [
        'label'    => '工单',
        'href'     => url($user !== null ? '/tickets' : '/login'),
        'active'   => $user !== null && $isActive(['/tickets', '/ticket/']),
        'icon'     => '<path d="M21 12a8 8 0 0 1-8 8H7l-4 3v-6.5A8 8 0 0 1 11 4h2a8 8 0 0 1 8 8z"></path>',
    ],
    [
        'label'    => '设置',
        'href'     => url($user !== null ? '/account' : '/login'),
        'active'   => $user !== null && $isActive(['/account']),
        'icon'     => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2 2 2 0 1 1-4 0 1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15a2 2 0 1 1 0-4 1.7 1.7 0 0 0 1.5-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 4.6a2 2 0 1 1 4 0 1.7 1.7 0 0 0 2.7 1.5l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 21 11a2 2 0 1 1 0 4z"></path>',
    ],
];
?>
<nav class="bottom-nav" aria-label="移动端主导航">
    <?php foreach ($items as $item): ?>
        <a class="bottom-nav__item<?= $item['active'] ? ' is-active' : '' ?>"
           href="<?= e($item['href']) ?>"
           <?= $item['active'] ? 'aria-current="page"' : '' ?>>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <?= $item['icon'] ?>
            </svg>
            <span><?= e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
