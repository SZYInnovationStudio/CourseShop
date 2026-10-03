<?php

declare(strict_types=1);

/**
 * 我的订单
 *
 * @var array<int, array<string, mixed>> $orders 订单列表
 * @var int $total 订单总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var array<string, string> $payTypeLabel 支付方式文案映射
 */

use App\Models\Order;

$statusBadges = [
    Order::STATUS_PENDING   => 'badge--warning',
    Order::STATUS_PAYING    => 'badge--warning',
    Order::STATUS_PAID      => 'badge--success',
    Order::STATUS_COMPLETED => 'badge--success',
    Order::STATUS_CLOSED    => 'badge',
    Order::STATUS_REFUNDED  => 'badge--info',
];

$pageUrl = static function (int $target): string {
    $query = $_GET;
    $query['page'] = $target;

    return url('/orders') . '?' . http_build_query($query);
};

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e(t('我的订单')) ?></h1>
    <p class="page-head__desc"><?= e(t('共 %d 笔订单，可在此查看支付状态并继续未完成的支付。', [(int) $total])) ?></p>
</div>

<div class="container">
    <div class="btn-group mb-6">
        <a class="btn btn--outline btn--sm" href="<?= url('/my/courses') ?>"><?= e(t('我的课程')) ?></a>
        <a class="btn btn--outline btn--sm" href="<?= url('/courses') ?>"><?= e(t('去逛逛课程')) ?></a>
    </div>

    <?php if ($orders === []): ?>
        <div class="card card--flat">
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path></svg>
                </div>
                <p><?= e(t('你还没有任何订单。')) ?></p>
                <p class="mb-0"><a class="btn btn--sm" href="<?= url('/courses') ?>"><?= e(t('去挑选课程')) ?></a></p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="table table--cards">
                    <thead>
                    <tr>
                        <th><?= e(t('课程')) ?></th>
                        <th><?= e(t('订单号')) ?></th>
                        <th><?= e(t('金额')) ?></th>
                        <th><?= e(t('支付方式')) ?></th>
                        <th><?= e(t('状态')) ?></th>
                        <th><?= e(t('下单时间')) ?></th>
                        <th><?= e(t('操作')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $orderNo    = (string) $order['order_no'];
                        $status     = (string) $order['status'];
                        $payType    = (string) ($order['pay_type'] ?? '');
                        $payLabel   = $payTypeLabel[$payType] ?? ($payType !== '' ? $payType : '—');
                        $canPay     = in_array($status, [Order::STATUS_PENDING, Order::STATUS_PAYING], true);
                        ?>
                        <tr>
                            <td data-label="<?= e(t('课程')) ?>">
                                <a href="<?= url('/order/' . $orderNo) ?>"><?= e($order['course_title']) ?></a>
                            </td>
                            <td data-label="<?= e(t('订单号')) ?>" class="text-faint"><?= e($orderNo) ?></td>
                            <td data-label="<?= e(t('金额')) ?>" class="price"><?= price_html((int) $order['amount']) ?></td>
                            <td data-label="<?= e(t('支付方式')) ?>"><?= e($payLabel) ?></td>
                            <td data-label="<?= e(t('状态')) ?>">
                                <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>">
                                    <?= e(t(Order::label($status))) ?>
                                </span>
                            </td>
                            <td data-label="<?= e(t('下单时间')) ?>" class="text-faint"><?= e($order['created_at']) ?></td>
                            <td data-label="<?= e(t('操作')) ?>" class="table__cell--actions">
                                <div class="btn-group">
                                    <a class="btn btn--outline btn--sm" href="<?= url('/order/' . $orderNo) ?>"><?= e(t('详情')) ?></a>
                                    <?php if ($canPay): ?>
                                        <a class="btn btn--sm" href="<?= url('/order/' . $orderNo) ?>"><?= e(t('去支付')) ?></a>
                                    <?php elseif (in_array($status, [Order::STATUS_PAID, Order::STATUS_COMPLETED], true)): ?>
                                        <?php // 套餐订单无独立学习页，跳转到我的课程；课程订单直达课程详情 ?>
                                        <?php if ((int) ($order['package_id'] ?? 0) > 0): ?>
                                            <a class="btn btn--sm" href="<?= url('/my/courses') ?>"><?= e(t('去学习')) ?></a>
                                        <?php else: ?>
                                            <a class="btn btn--sm" href="<?= url('/course/' . (int) $order['course_id']) ?>"><?= e(t('去学习')) ?></a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="<?= e(t('分页')) ?>">
                <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : $pageUrl($page - 1) ?>" rel="prev"><?= e(t('上一页')) ?></a>

                <?php if ($windowStart > 1): ?>
                    <a class="pagination__item" href="<?= $pageUrl(1) ?>">1</a>
                    <?php if ($windowStart > 2): ?>
                        <span class="pagination__item is-disabled">…</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                    <a class="pagination__item<?= $p === $page ? ' is-active' : '' ?>" href="<?= $pageUrl($p) ?>"><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($windowEnd < $totalPages): ?>
                    <?php if ($windowEnd < $totalPages - 1): ?>
                        <span class="pagination__item is-disabled">…</span>
                    <?php endif; ?>
                    <a class="pagination__item" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <a class="pagination__item<?= $page >= $totalPages ? ' is-disabled' : '' ?>"
                   href="<?= $page >= $totalPages ? '#' : $pageUrl($page + 1) ?>" rel="next"><?= e(t('下一页')) ?></a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
