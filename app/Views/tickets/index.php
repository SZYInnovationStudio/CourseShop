<?php

declare(strict_types=1);

/**
 * 我的工单列表
 *
 * @var array<int, array<string, mixed>> $tickets 当前页工单列表
 * @var array<string, string> $filters 当前筛选条件（keyword / status）
 * @var int $total 工单总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 */

use App\Models\Ticket;

$statusBadges = [
    Ticket::STATUS_PENDING    => 'badge--warning',
    Ticket::STATUS_PROCESSING => 'badge--info',
    Ticket::STATUS_REPLIED    => 'badge--success',
    Ticket::STATUS_CLOSED     => 'badge',
];

$pageUrl = static function (int $target): string {
    $query = $_GET;
    $query['page'] = $target;

    return url('/tickets') . '?' . http_build_query($query);
};

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);
?>
<div class="page-head container">
    <div class="flex-between flex-wrap gap-3">
        <div>
            <h1 class="page-head__title">我的工单</h1>
            <p class="page-head__desc mb-0">共 <?= (int) $total ?> 条工单，提交问题后可在此跟踪处理进度。</p>
        </div>
        <a class="btn" href="<?= url('/ticket/create') ?>">提交工单</a>
    </div>
</div>

<div class="container">
    <form class="admin-toolbar" method="get" action="<?= url('/tickets') ?>">
        <div class="admin-toolbar__field admin-toolbar__field--grow">
            <input class="input" type="text" name="keyword" value="<?= e($filters['keyword']) ?>"
                   placeholder="搜索工单号或标题">
        </div>
        <div class="admin-toolbar__field">
            <select class="select" name="status">
                <option value="">全部状态</option>
                <?php foreach (Ticket::STATUS_LABELS as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-toolbar__actions">
            <button class="btn btn--sm" type="submit">筛选</button>
            <?php if ($filters['keyword'] !== '' || $filters['status'] !== ''): ?>
                <a class="btn btn--sm btn--ghost" href="<?= url('/tickets') ?>">重置</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($tickets === []): ?>
        <div class="card card--flat">
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"></path><path d="M13 5v2"></path><path d="M13 17v2"></path><path d="M13 11v2"></path></svg>
                </div>
                <p>还没有工单记录。</p>
                <p class="mb-0"><a class="btn btn--sm" href="<?= url('/ticket/create') ?>">提交第一条工单</a></p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>工单号</th>
                        <th>标题</th>
                        <th>类型</th>
                        <th>状态</th>
                        <th>回复</th>
                        <th>最近更新</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                        <?php
                        $status = (string) $ticket['status'];
                        $type   = (string) $ticket['type'];
                        ?>
                        <tr>
                            <td class="text-faint"><?= e($ticket['ticket_no']) ?></td>
                            <td>
                                <a href="<?= url('/ticket/' . (int) $ticket['id']) ?>"><?= e($ticket['title']) ?></a>
                            </td>
                            <td>
                                <span class="badge <?= $type === Ticket::TYPE_APPEAL ? 'badge--warning' : 'badge--info' ?>">
                                    <?= e(Ticket::typeLabel($type)) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>">
                                    <?= e(Ticket::statusLabel($status)) ?>
                                </span>
                            </td>
                            <td><?= (int) $ticket['reply_count'] ?></td>
                            <td class="text-faint"><?= e($ticket['last_reply_at'] ?? $ticket['updated_at']) ?></td>
                            <td>
                                <a class="btn btn--outline btn--sm" href="<?= url('/ticket/' . (int) $ticket['id']) ?>">查看</a>
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
