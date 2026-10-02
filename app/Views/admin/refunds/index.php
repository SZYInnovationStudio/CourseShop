<?php

declare(strict_types=1);

/**
 * 后台退款管理 - 列表
 *
 * @var array<string, mixed> $filters 筛选条件
 * @var array<int, array<string, mixed>> $refunds 退款单列表
 * @var int $total 退款单总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var array<string, string> $statuses 退款状态选项
 * @var array<string, string> $statusBadges 退款状态对应的徽标样式
 */

use App\Models\Refund;

$pageUrl = static function (int $target): string {
    $query         = $_GET;
    $query['page'] = $target;

    return url('/admin/refunds') . '?' . http_build_query($query);
};

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">退款管理</h2>
        <p class="admin-page-head__desc">共 <?= (int) $total ?> 条退款单。确认退款将调用支付网关并同步撤销课程授权。</p>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/refunds') ?>">
    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="filter-keyword">关键词</label>
        <input class="input" id="filter-keyword" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="退款单号 / 订单号 / 用户名">
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
        <label class="form-label" for="filter-date-from">申请起</label>
        <input class="input" id="filter-date-from" type="date" name="date_from"
               value="<?= e((string) $filters['date_from']) ?>">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-date-to">申请止</label>
        <input class="input" id="filter-date-to" type="date" name="date_to"
               value="<?= e((string) $filters['date_to']) ?>">
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/refunds') ?>">重置</a>
    </div>
</form>

<?php if ($refunds === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的退款单。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>退款单号</th>
                    <th>订单号</th>
                    <th>用户</th>
                    <th>退款金额</th>
                    <th>原因</th>
                    <th>状态</th>
                    <th>申请时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($refunds as $item): ?>
                    <?php
                    $refundId = (int) $item['id'];
                    $status   = (string) $item['status'];
                    $orderId  = (int) $item['order_id'];
                    $pending  = $status === Refund::STATUS_PENDING;
                    ?>
                    <tr>
                        <td class="text-faint"><?= $refundId ?></td>
                        <td class="text-faint"><?= e((string) $item['refund_no']) ?></td>
                        <td>
                            <a href="<?= url('/admin/orders/' . $orderId) ?>"><?= e((string) $item['order_no']) ?></a>
                        </td>
                        <td><?= e((string) ($item['user_name'] ?? '—')) ?></td>
                        <td class="price"><?= price_html((int) $item['amount']) ?></td>
                        <td><?= e((string) ($item['reason'] ?? '')) ?></td>
                        <td>
                            <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>">
                                <?= e($statuses[$status] ?? $status) ?>
                            </span>
                        </td>
                        <td class="text-faint"><?= e((string) $item['created_at']) ?></td>
                        <td>
                            <?php if ($pending): ?>
                                <div class="admin-actions">
                                    <form method="post" class="admin-inline-form"
                                          action="<?= url('/admin/refunds/' . $refundId . '/approve') ?>"
                                          data-confirm="确定执行退款吗？将调用支付网关退款并撤销用户课程授权。">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--sm" type="submit">确认退款</button>
                                    </form>
                                    <form method="post" class="admin-inline-form"
                                          action="<?= url('/admin/refunds/' . $refundId . '/reject') ?>"
                                          data-confirm="确定驳回该退款申请吗？">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--ghost btn--sm" type="submit">驳回</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-faint">—</span>
                            <?php endif; ?>
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
