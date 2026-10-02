<?php

declare(strict_types=1);

/**
 * 后台订单管理 - 列表
 *
 * @var array<string, mixed> $filters 当前筛选条件
 * @var array<int, array<string, mixed>> $orders 当前页订单记录
 * @var int $total 订单总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var array<string, string> $statuses 订单状态选项（状态 => 名称）
 * @var array<string, string> $payTypes 支付方式选项（方式 => 名称）
 */

use App\Models\Order;

$pageUrl = static function (int $target): string {
    $query         = $_GET;
    $query['page'] = $target;

    return url('/admin/orders') . '?' . http_build_query($query);
};

/** 状态 => 徽标样式 */
$statusBadge = [
    Order::STATUS_PENDING   => 'badge--warning',
    Order::STATUS_PAYING    => 'badge--warning',
    Order::STATUS_PAID      => 'badge--success',
    Order::STATUS_COMPLETED => 'badge--success',
    Order::STATUS_CLOSED    => 'badge',
    Order::STATUS_REFUNDED  => 'badge--info',
];

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);

// 导出链接携带当前筛选条件（去掉空值）
$exportQuery = array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null);
$exportUrl   = url('/admin/orders/export') . ($exportQuery !== [] ? '?' . http_build_query($exportQuery) : '');
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">订单管理</h2>
        <p class="admin-page-head__desc">共 <?= (int) $total ?> 笔订单，可查看详情、手动关闭或确认完成。</p>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/orders') ?>">
    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="filter-keyword">关键词</label>
        <input class="input" id="filter-keyword" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="订单号 / 课程名 / 用户名">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-status">状态</label>
        <select class="select" id="filter-status" name="status">
            <option value="">全部状态</option>
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-pay-type">支付方式</label>
        <select class="select" id="filter-pay-type" name="pay_type">
            <option value="">全部方式</option>
            <?php foreach ($payTypes as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['pay_type'] === $value ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-date-from">下单起</label>
        <input class="input" id="filter-date-from" type="date" name="date_from"
               value="<?= e((string) $filters['date_from']) ?>">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-date-to">下单止</label>
        <input class="input" id="filter-date-to" type="date" name="date_to"
               value="<?= e((string) $filters['date_to']) ?>">
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/orders') ?>">重置</a>
        <a class="btn btn--outline btn--sm" href="<?= e($exportUrl) ?>">导出 CSV</a>
    </div>
</form>

<?php if ($orders === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的订单。</p>
        </div>
    </div>
<?php else: ?>
    <form class="admin-batch-bar" id="batch-form" method="post" action="<?= url('/admin/orders/batch') ?>"
          data-batch-form data-confirm="确定对已选中的订单执行该批量操作吗？">
        <?= csrf_field() ?>
        <span class="admin-batch-bar__count">已选 <strong data-batch-count>0</strong> 项</span>
        <div class="admin-batch-bar__actions">
            <select class="select" name="action" required aria-label="批量操作">
                <option value="">批量操作…</option>
                <option value="close">关闭订单</option>
                <option value="complete">确认完成</option>
                <option value="delete">删除订单</option>
            </select>
            <button class="btn btn--sm" type="submit">执行</button>
        </div>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" data-batch-select-all aria-label="全选本页"></th>
                    <th>ID</th>
                    <th>订单号</th>
                    <th>课程</th>
                    <th>用户</th>
                    <th>金额</th>
                    <th>支付方式</th>
                    <th>状态</th>
                    <th>下单时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $item): ?>
                    <?php
                    $orderId = (int) $item['id'];
                    $status  = (string) $item['status'];
                    $payType = (string) ($item['pay_type'] ?? '');
                    ?>
                    <tr>
                        <td class="col-check">
                            <input type="checkbox" form="batch-form" name="ids[]" value="<?= $orderId ?>"
                                   data-batch-checkbox aria-label="选择订单 <?= e((string) $item['order_no']) ?>">
                        </td>
                        <td class="text-faint"><?= $orderId ?></td>
                        <td class="text-faint"><?= e((string) $item['order_no']) ?></td>
                        <td><?= e((string) $item['course_title']) ?></td>
                        <td><?= e((string) ($item['user_name'] ?? '—')) ?></td>
                        <td><span class="price"><?= price_html((int) $item['amount']) ?></span></td>
                        <td class="text-faint"><?= e($payTypes[$payType] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= $statusBadge[$status] ?? 'badge' ?>">
                                <?= e($statuses[$status] ?? $status) ?>
                            </span>
                        </td>
                        <td class="text-faint"><?= e((string) $item['created_at']) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm" href="<?= url('/admin/orders/' . $orderId) ?>">详情</a>
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
