<?php

declare(strict_types=1);

/**
 * 后台侧边栏
 *
 * 采用「已实现模块才加入导航」的渐进策略，避免出现指向 404 的入口；
 * 同时通过 Auth::can() 按当前账号的权限动态门控导航项，
 * 权限不足的分组与入口不渲染，避免越权点击后直接 403。
 */

use App\Support\Auth;
use App\Support\Request;
use App\Support\Setting;

$siteName = Setting::string('site_name', 'CourseShop');
$current  = Request::path();

/** 当前路径是否命中导航项 */
$isActive = static function (string $path) use ($current): bool {
    if ($path === '/admin') {
        return $current === '/admin';
    }

    return str_starts_with($current, $path);
};

// 各导航项显隐开关（集中计算，便于分组判空）
$can = static fn (string $code): bool => Auth::can($code);

$nav = [
    'overview' => [
        'label' => '总览',
        'items' => [
            [
                'path'  => '/admin',
                'label' => '仪表盘',
                'show'  => $can('dashboard.view'),
                'icon'  => '<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>',
            ],
            [
                'path'  => '/admin/reports',
                'label' => '经营报表',
                'show'  => $can('report.view'),
                'icon'  => '<path d="M3 3v18h18"></path><path d="M7 15l4-5 3 3 5-7"></path>',
            ],
        ],
    ],
    'user' => [
        'label' => '用户',
        'items' => [
            [
                'path'  => '/admin/users',
                'label' => '用户管理',
                'show'  => $can('user.view'),
                'icon'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
            ],
        ],
    ],
    'content' => [
        'label' => '内容',
        'items' => [
            [
                'path'  => '/admin/courses',
                'label' => '课程管理',
                'show'  => $can('course.view'),
                'icon'  => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
            ],
            [
                'path'  => '/admin/categories',
                'label' => '分类管理',
                'show'  => $can('course.manage'),
                'icon'  => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><circle cx="7" cy="7" r="1.5"></circle>',
            ],
            [
                'path'  => '/admin/announcements',
                'label' => '公告管理',
                'show'  => $can('setting.manage'),
                'icon'  => '<path d="M3 11v3a1 1 0 0 0 1 1h2l4 4V6L6 10H4a1 1 0 0 0-1 1z"></path><path d="M15 8.5a5 5 0 0 1 0 7"></path><path d="M18.5 5a9 9 0 0 1 0 14"></path>',
            ],
            [
                'path'  => '/admin/agreements',
                'label' => '协议管理',
                'show'  => $can('setting.manage'),
                'icon'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8M8 17h6"></path>',
            ],
        ],
    ],
    'trade' => [
        'label' => '交易',
        'items' => [
            [
                'path'  => '/admin/orders',
                'label' => '订单管理',
                'show'  => $can('order.view'),
                'icon'  => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path>',
            ],
            [
                'path'  => '/admin/refunds',
                'label' => '退款管理',
                'show'  => $can('order.refund'),
                'icon'  => '<path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 3v6h6"></path><path d="M12 7v5l3 2"></path>',
            ],
            [
                'path'  => '/admin/coupons',
                'label' => '优惠券',
                'show'  => $can('coupon.view'),
                'icon'  => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><circle cx="7" cy="7" r="1.5"></circle>',
            ],
            [
                'path'  => '/admin/packages',
                'label' => '套餐管理',
                'show'  => $can('package.view'),
                'icon'  => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path>',
            ],
        ],
    ],
    'service' => [
        'label' => '服务',
        'items' => [
            [
                'path'  => '/admin/tickets',
                'label' => '工单管理',
                'show'  => $can('ticket.view'),
                'icon'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
            ],
        ],
    ],
    'system' => [
        'label' => '系统',
        'items' => [
            [
                'path'  => '/admin/settings',
                'label' => '系统设置',
                'show'  => $can('setting.view'),
                'icon'  => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
            ],
            [
                'path'  => '/admin/roles',
                'label' => '角色权限',
                'show'  => $can('rbac.manage'),
                'icon'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path>',
            ],
            [
                'path'  => '/admin/logs',
                'label' => '日志查看',
                'show'  => $can('log.view'),
                'icon'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8M8 17h6"></path>',
            ],
        ],
    ],
];
?>
<aside class="admin-sidebar" id="admin-sidebar" data-admin-sidebar>
    <div class="admin-sidebar__head">
        <a class="admin-sidebar__brand" href="<?= url('/admin') ?>">
            <span class="admin-sidebar__mark"><?= e(mb_substr($siteName, 0, 1)) ?></span>
            <span class="admin-sidebar__name"><?= e($siteName) ?></span>
        </a>
    </div>

    <nav class="admin-nav" aria-label="后台导航">
        <?php foreach ($nav as $section): ?>
            <?php
            // 过滤掉当前账号无权限访问的入口
            $items = array_values(array_filter(
                $section['items'],
                static fn (array $item): bool => (bool) $item['show']
            ));
            ?>
            <?php if ($items !== []): ?>
                <div class="admin-nav__section"><?= e($section['label']) ?></div>
                <?php foreach ($items as $item): ?>
                    <a class="admin-nav__link<?= $isActive($item['path']) ? ' is-active' : '' ?>" href="<?= url($item['path']) ?>">
                        <span class="admin-nav__icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <?= $item['icon'] ?>
                            </svg>
                        </span>
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar__foot">
        <a class="admin-nav__link" href="<?= url('/') ?>" target="_blank" rel="noopener">
            <span class="admin-nav__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 10.5 12 3l9 7.5"></path>
                    <path d="M5 9.5V21h14V9.5"></path>
                </svg>
            </span>
            <span>返回前台</span>
        </a>

        <form method="post" action="<?= url('/admin/logout') ?>">
            <?= csrf_field() ?>
            <button class="admin-nav__link" type="submit">
                <span class="admin-nav__icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <path d="M16 17l5-5-5-5M21 12H9"></path>
                    </svg>
                </span>
                <span>退出登录</span>
            </button>
        </form>
    </div>
</aside>
