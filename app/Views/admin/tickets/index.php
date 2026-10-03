<?php

declare(strict_types=1);

/**
 * 后台工单管理 - 列表
 *
 * @var array<string, mixed> $filters 当前筛选条件
 * @var array<int, array<string, mixed>> $tickets 当前页工单记录
 * @var int $total 工单总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var array<string, string> $statuses 工单状态选项（状态 => 名称）
 * @var array<string, string> $types 工单类型选项（类型 => 名称）
 * @var array<int, string> $priorities 工单优先级选项（级别 => 名称）
 */

use App\Models\Ticket;

$pageUrl = static function (int $target): string {
    $query         = $_GET;
    $query['page'] = $target;

    return url('/admin/tickets') . '?' . http_build_query($query);
};

/** 状态 => 徽标样式 */
$statusBadge = [
    Ticket::STATUS_PENDING    => 'badge--warning',
    Ticket::STATUS_PROCESSING => 'badge--info',
    Ticket::STATUS_REPLIED    => 'badge--success',
    Ticket::STATUS_CLOSED     => 'badge',
];

/** 优先级 => 徽标样式 */
$priorityBadge = [
    0 => 'badge',
    1 => 'badge--info',
    2 => 'badge--warning',
    3 => 'badge--danger',
];

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">工单管理</h2>
        <p class="admin-page-head__desc">共 <?= (int) $total ?> 条工单，可回复、调整状态与优先级，或处理账号申诉。</p>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/tickets') ?>">
    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="filter-keyword">关键词</label>
        <input class="input" id="filter-keyword" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="工单号 / 标题 / 用户名">
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
        <label class="form-label" for="filter-type">类型</label>
        <select class="select" id="filter-type" name="type">
            <option value="">全部类型</option>
            <?php foreach ($types as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['type'] === $value ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-priority">优先级</label>
        <select class="select" id="filter-priority" name="priority">
            <option value="">全部优先级</option>
            <?php foreach ($priorities as $value => $label): ?>
                <option value="<?= (int) $value ?>"<?= (string) $filters['priority'] === (string) $value ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-date-from">提交起</label>
        <input class="input" id="filter-date-from" type="date" name="date_from"
               value="<?= e((string) $filters['date_from']) ?>">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-date-to">提交止</label>
        <input class="input" id="filter-date-to" type="date" name="date_to"
               value="<?= e((string) $filters['date_to']) ?>">
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/tickets') ?>">重置</a>
    </div>
</form>

<?php if ($tickets === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的工单。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>序号</th>
                    <th>工单号</th>
                    <th>标题</th>
                    <th>用户</th>
                    <th>类型</th>
                    <th>状态</th>
                    <th>优先级</th>
                    <th>回复</th>
                    <th>提交时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $index => $item): ?>
                    <?php
                    $ticketId = (int) $item['id'];
                    $status   = (string) $item['status'];
                    $type     = (string) $item['type'];
                    $priority = (int) $item['priority'];
                    ?>
                    <tr>
                        <td class="text-faint"><?= $index + 1 ?></td>
                        <td class="text-faint"><?= e((string) $item['ticket_no']) ?></td>
                        <td>
                            <a href="<?= url('/admin/tickets/' . $ticketId) ?>"><?= e((string) $item['title']) ?></a>
                        </td>
                        <td><?= e((string) ($item['user_name'] ?? '—')) ?></td>
                        <td>
                            <span class="badge <?= $type === Ticket::TYPE_APPEAL ? 'badge--warning' : 'badge--info' ?>">
                                <?= e($types[$type] ?? $type) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $statusBadge[$status] ?? 'badge' ?>">
                                <?= e($statuses[$status] ?? $status) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $priorityBadge[$priority] ?? 'badge' ?>">
                                <?= e($priorities[$priority] ?? (string) $priority) ?>
                            </span>
                        </td>
                        <td><?= (int) $item['reply_count'] ?></td>
                        <td class="text-faint"><?= e((string) $item['created_at']) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm" href="<?= url('/admin/tickets/' . $ticketId) ?>">详情</a>
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
