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
    <h1 class="page-head__title">我的订单</h1>
    <p class="page-head__desc">共 <?= (int) $total ?> 笔订单，可在此查看支付状态并继续未完成的支付。</p>
</div>

<div class="container">
    <div class="btn-group mb-6">
        <a class="btn btn--outline btn--sm" href="<?= url('/my/courses') ?>">我的课程</a>
        <a class="btn btn--outline btn--sm" href="<?= url('/courses') ?>">去逛逛课程</a>
    </div>

    <?php if ($orders === []): ?>
        <div class="card card--flat">
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true">&#128179;</div>
                <p>你还没有任何订单。</p>
                <p class="mb-0"><a class="btn btn--sm" href="<?= url('/courses') ?>">去挑选课程</a></p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>课程</th>
                        <th>订单号</th>
                        <th>金额</th>
                        <th>支付方式</th>
                        <th>状态</th>
                        <th>下单时间</th>
                        <th>操作</th>
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
                            <td>
                                <a href="<?= url('/order/' . $orderNo) ?>"><?= e($order['course_title']) ?></a>
                            </td>
                            <td class="text-faint"><?= e($orderNo) ?></td>
                            <td class="price"><?= price_html((int) $order['amount']) ?></td>
                            <td><?= e($payLabel) ?></td>
                            <td>
                                <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>">
                                    <?= e(Order::label($status)) ?>
                                </span>
                            </td>
                            <td class="text-faint"><?= e($order['created_at']) ?></td>
                            <td>
                                <div class="btn-group">
                                    <a class="btn btn--outline btn--sm" href="<?= url('/order/' . $orderNo) ?>">详情</a>
                                    <?php if ($canPay): ?>
                                        <a class="btn btn--sm" href="<?= url('/order/' . $orderNo) ?>">去支付</a>
                                    <?php elseif (in_array($status, [Order::STATUS_PAID, Order::STATUS_COMPLETED], true)): ?>
                                        <?php // 套餐订单无独立学习页，跳转到我的课程；课程订单直达课程详情 ?>
                                        <?php if ((int) ($order['package_id'] ?? 0) > 0): ?>
                                            <a class="btn btn--sm" href="<?= url('/my/courses') ?>">去学习</a>
                                        <?php else: ?>
                                            <a class="btn btn--sm" href="<?= url('/course/' . (int) $order['course_id']) ?>">去学习</a>
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
            <nav class="pagination" aria-label="分页">
                <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : $pageUrl($page - 1) ?>" rel="prev">上一页</a>

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
                   href="<?= $page >= $totalPages ? '#' : $pageUrl($page + 1) ?>" rel="next">下一页</a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
