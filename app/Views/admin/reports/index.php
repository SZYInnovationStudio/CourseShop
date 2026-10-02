<?php

declare(strict_types=1);

/**
 * 后台经营报表
 *
 * @var string $range 当前统计区间标识
 * @var array<string, string> $ranges 统计区间选项
 * @var string $dateFrom 起始日期
 * @var string $dateTo 结束日期
 * @var array<string, mixed> $summary 概览指标
 * @var array<int, array<string, mixed>> $trend 营收趋势（按日）
 * @var array<int, array<string, mixed>> $refundTrend 退款趋势（按日）
 * @var array<int, array<string, mixed>> $topCourses 课程销售排行
 * @var array<int, array<string, mixed>> $topUsers 用户消费排行
 * @var array<int, array<string, mixed>> $payTypes 支付方式分布
 * @var array<string, mixed> $couponStats 优惠券使用统计
 */

$payTypeLabels = [
    'wxpay'  => '微信支付',
    'alipay' => '支付宝',
];

// 退款趋势按日期索引，便于与营收趋势逐日对齐展示
$refundMap = [];
foreach ($refundTrend as $row) {
    $refundMap[$row['date']] = $row;
}

$exportQuery = http_build_query([
    'range'     => $range,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
]);

$payTypeTotal = 0;
foreach ($payTypes as $row) {
    $payTypeTotal += (int) $row['orders'];
}
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">经营报表</h2>
        <p class="admin-page-head__desc">统计区间 <?= e((string) $dateFrom) ?> ~ <?= e((string) $dateTo) ?>，营收按下单支付时间归集。</p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/reports/export') . '?' . e($exportQuery) ?>">导出 CSV</a>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/reports') ?>">
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
        <span class="admin-stat__label">营收（已支付）</span>
        <span class="admin-stat__value price price--accent"><?= price_html((int) $summary['revenue']) ?></span>
        <span class="admin-stat__hint">区间内支付成功的订单</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">净营收</span>
        <span class="admin-stat__value price"><?= price_html((int) $summary['net_revenue']) ?></span>
        <span class="admin-stat__hint">营收 - 退款 <?= price_html((int) $summary['refund_amount']) ?></span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">支付订单</span>
        <span class="admin-stat__value"><?= (int) $summary['paid_orders'] ?></span>
        <span class="admin-stat__hint">区间下单 <?= (int) $summary['created_orders'] ?> 笔</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">客单价</span>
        <span class="admin-stat__value price"><?= price_html((int) $summary['avg_order']) ?></span>
        <span class="admin-stat__hint">营收 / 支付订单数</span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">退款</span>
        <span class="admin-stat__value"><?= (int) $summary['refund_orders'] ?></span>
        <span class="admin-stat__hint">金额 <?= price_html((int) $summary['refund_amount']) ?></span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat__label">新增用户</span>
        <span class="admin-stat__value"><?= (int) $summary['new_users'] ?></span>
        <span class="admin-stat__hint">按注册时间统计</span>
    </div>
</div>

<div class="card mt-6">
    <div class="card__header">按日趋势</div>
    <?php if ($trend === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">统计区间内暂无数据。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>日期</th>
                    <th>支付订单</th>
                    <th>营收</th>
                    <th>退款订单</th>
                    <th>退款金额</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trend as $row): ?>
                    <?php $refund = $refundMap[$row['date']] ?? ['orders' => 0, 'amount' => 0]; ?>
                    <tr>
                        <td><?= e($row['date']) ?></td>
                        <td><?= (int) $row['orders'] ?></td>
                        <td class="price"><?= price_html((int) $row['revenue']) ?></td>
                        <td><?= (int) $refund['orders'] ?></td>
                        <td class="text-faint"><?= price_html((int) $refund['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card mt-6">
    <div class="card__header">课程 / 套餐销售排行（Top 10）</div>
    <?php if ($topCourses === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">统计区间内暂无销售记录。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>排名</th>
                    <th>课程</th>
                    <th>支付订单</th>
                    <th>营收</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($topCourses as $index => $row): ?>
                    <tr>
                        <td class="text-faint"><?= $index + 1 ?></td>
                        <td><?= e((string) $row['course_title']) ?></td>
                        <td><?= (int) $row['orders'] ?></td>
                        <td class="price"><?= price_html((int) $row['revenue']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card mt-6">
    <div class="card__header">用户消费排行（Top 10）</div>
    <?php if ($topUsers === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">统计区间内暂无消费记录。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>排名</th>
                    <th>用户</th>
                    <th>支付订单</th>
                    <th>消费金额</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($topUsers as $index => $row): ?>
                    <tr>
                        <td class="text-faint"><?= $index + 1 ?></td>
                        <td>
                            <?= e((string) ($row['nickname'] ?? '') !== '' ? (string) $row['nickname'] : (string) ($row['username'] ?? '—')) ?>
                            <span class="text-faint">#<?= (int) $row['user_id'] ?></span>
                        </td>
                        <td><?= (int) $row['orders'] ?></td>
                        <td class="price"><?= price_html((int) $row['revenue']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card mt-6">
    <div class="card__header">支付方式分布</div>
    <?php if ($payTypes === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">统计区间内暂无支付记录。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>支付方式</th>
                    <th>支付订单</th>
                    <th>占比</th>
                    <th>营收</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($payTypes as $row): ?>
                    <?php
                    $code   = (string) $row['pay_type'];
                    $orders = (int) $row['orders'];
                    $share  = $payTypeTotal > 0 ? number_format($orders * 100 / $payTypeTotal, 1) : '0.0';
                    ?>
                    <tr>
                        <td><?= e($payTypeLabels[$code] ?? ($code !== '' ? $code : '未知')) ?></td>
                        <td><?= $orders ?></td>
                        <td class="text-faint"><?= e($share) ?>%</td>
                        <td class="price"><?= price_html((int) $row['revenue']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card mt-6">
    <div class="card__header">优惠券使用</div>
    <div class="card__body">
        <p class="mb-4">
            区间内共使用 <strong><?= (int) $couponStats['count'] ?></strong> 次，累计抵扣
            <strong class="price"><?= price_html((int) $couponStats['discount']) ?></strong>。
        </p>

        <?php if ($couponStats['top'] === []): ?>
            <p class="text-muted mb-0">统计区间内暂无优惠券使用记录。</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>优惠码</th>
                        <th>名称</th>
                        <th>使用次数</th>
                        <th>抵扣金额</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($couponStats['top'] as $row): ?>
                        <tr>
                            <td class="text-faint"><?= e((string) ($row['code'] ?? '—')) ?></td>
                            <td><?= e((string) ($row['name'] ?? '—')) ?></td>
                            <td><?= (int) $row['used'] ?></td>
                            <td class="price"><?= price_html((int) $row['discount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
