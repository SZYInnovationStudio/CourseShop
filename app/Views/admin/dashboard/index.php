<?php

declare(strict_types=1);

/**
 * 后台仪表盘
 *
 * @var array<string, mixed> $admin 当前登录管理员
 * @var array<string, int> $stats 概览指标
 * @var array<string, int> $period 区间统计
 * @var string $range 当前区间标识
 * @var array<string, string> $ranges 区间选项
 * @var string $dateFrom 统计起始日期
 * @var string $dateTo 统计结束日期
 * @var array<int, array<string, mixed>> $recentOrders 最近订单
 */

use App\Models\Order;

$adminName = isset($admin['username']) ? (string) $admin['username'] : '管理员';

$payTypeLabels = [
    'wxpay'  => '微信支付',
    'alipay' => '支付宝',
];

$statusBadges = [
    Order::STATUS_PENDING   => 'badge--warning',
    Order::STATUS_PAYING    => 'badge--warning',
    Order::STATUS_PAID      => 'badge--success',
    Order::STATUS_COMPLETED => 'badge--success',
    Order::STATUS_CLOSED    => 'badge',
    Order::STATUS_REFUNDED  => 'badge--info',
];
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">仪表盘</h2>
        <p class="admin-page-head__desc">欢迎回来，<?= e($adminName) ?>。以下是站点当前的运营概览。</p>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin') ?>">
    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-range">统计区间</label>
        <select class="select" id="filter-range" name="range">
            <?php foreach ($ranges as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $range === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-date-from">起始日期</label>
        <input class="input" id="filter-date-from" type="date" name="date_from" value="<?= e((string) $dateFrom) ?>">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-date-to">结束日期</label>
        <input class="input" id="filter-date-to" type="date" name="date_to" value="<?= e((string) $dateTo) ?>">
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">应用</button>
    </div>
</form>

<div class="admin-stats">
    <div class="admin-stat">
        <span class="admin-stat__label">用户总数</span>
        <span class="admin-stat__value"><?= (int) $stats['users_total'] ?></span>
        <span class="admin-stat__hint">今日新增 <?= (int) $stats['users_today'] ?> 人</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">课程总数</span>
        <span class="admin-stat__value"><?= (int) $stats['courses_total'] ?></span>
        <span class="admin-stat__hint">已上架 <?= (int) $stats['courses_published'] ?> 门</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">订单总数</span>
        <span class="admin-stat__value"><?= (int) $stats['orders_total'] ?></span>
        <span class="admin-stat__hint">今日新增 <?= (int) $stats['orders_today'] ?> 笔</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">待支付订单</span>
        <span class="admin-stat__value"><?= (int) $stats['orders_pending'] ?></span>
        <span class="admin-stat__hint">待支付与支付中</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">累计销售额</span>
        <span class="admin-stat__value price"><?= price_html((int) $stats['sales_total']) ?></span>
        <span class="admin-stat__hint">今日 <?= price_html((int) $stats['sales_today']) ?></span>
    </div>
</div>

<div class="admin-page-head mt-6">
    <div>
        <h3 class="admin-page-head__title">区间统计</h3>
        <p class="admin-page-head__desc">统计区间 <?= e((string) $dateFrom) ?> ~ <?= e((string) $dateTo) ?></p>
    </div>
</div>

<div class="admin-stats">
    <div class="admin-stat">
        <span class="admin-stat__label">区间新增用户</span>
        <span class="admin-stat__value"><?= (int) $period['users'] ?></span>
        <span class="admin-stat__hint">按注册时间统计</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">区间订单数</span>
        <span class="admin-stat__value"><?= (int) $period['orders'] ?></span>
        <span class="admin-stat__hint">按下单时间统计</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">区间销售额</span>
        <span class="admin-stat__value price"><?= price_html((int) $period['sales']) ?></span>
        <span class="admin-stat__hint">按支付时间统计</span>
    </div>
</div>

<div class="card mt-6">
    <div class="card__header">最近订单</div>
    <?php if ($recentOrders === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">暂无订单记录。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>订单号</th>
                    <th>用户</th>
                    <th>课程</th>
                    <th>金额</th>
                    <th>支付方式</th>
                    <th>状态</th>
                    <th>下单时间</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentOrders as $order): ?>
                    <?php
                    $status  = (string) $order['status'];
                    $payType = (string) ($order['pay_type'] ?? '');
                    ?>
                    <tr>
                        <td class="text-faint"><?= e($order['order_no']) ?></td>
                        <td><?= e($order['user_name'] ?? '—') ?></td>
                        <td><?= e($order['course_title']) ?></td>
                        <td class="price"><?= price_html((int) $order['amount']) ?></td>
                        <td><?= e($payTypeLabels[$payType] ?? ($payType !== '' ? $payType : '—')) ?></td>
                        <td>
                            <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>">
                                <?= e(Order::label($status)) ?>
                            </span>
                        </td>
                        <td class="text-faint"><?= e($order['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
