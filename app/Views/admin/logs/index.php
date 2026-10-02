<?php

declare(strict_types=1);

/**
 * 后台日志查看
 *
 * @var string $type 当前日志类型
 * @var array<string, string> $types 日志类型选项（标识 => 名称）
 * @var array<string, int> $totals 各日志类型的记录数统计
 * @var array<string, mixed> $filters 当前筛选条件
 * @var array<int, array<string, mixed>> $rows 当前页日志记录
 * @var int $total 记录总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var array<string, array<string, string>> $options 状态的筛选项（字段 => 值到标签的映射）
 */

use App\Models\Log;

/** 保留当前筛选条件与类型的分页链接 */
$pageUrl = static function (int $target): string {
    $query         = $_GET;
    $query['page'] = $target;

    return url('/admin/logs') . '?' . http_build_query($query);
};

/** 切换日志类型（丢弃原类型的筛选条件，避免串场） */
$tabUrl = static function (string $target): string {
    return url('/admin/logs') . '?' . http_build_query(['type' => $target]);
};

/** 状态 => 徽标样式 */
$badge = static function (string $type, string $status): string {
    return match ($type) {
        'login', 'mail' => $status === 'success' ? 'badge--success' : 'badge--danger',
        'payment'       => $status === 'success' ? 'badge--success' : ($status === 'recv' ? 'badge--info' : 'badge--danger'),
        'captcha'       => $status === 'passed' ? 'badge--success' : ($status === 'failed' ? 'badge--danger' : 'badge--info'),
        default         => 'badge',
    };
};

/** 状态展示名 */
$statusLabel = static function (string $type, string $status): string {
    $map = match ($type) {
        'login'   => Log::LOGIN_STATUS,
        'payment' => Log::PAYMENT_STATUS,
        'mail'    => Log::MAIL_STATUS,
        'captcha' => Log::CAPTCHA_STATUS,
        default   => [],
    };

    return $map[$status] ?? ($status !== '' ? $status : '—');
};

/** 操作日志详情解析为可读 JSON */
$detailText = static function (?string $raw): string {
    if ($raw === null || $raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return $raw;
};

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">日志查看</h2>
        <p class="admin-page-head__desc">共 <?= (int) $total ?> 条记录，可按关键词、状态与时间范围筛选。</p>
    </div>
</div>

<nav class="admin-tabs" aria-label="日志类型">
    <?php foreach ($types as $key => $label): ?>
        <a class="admin-tabs__item<?= $key === $type ? ' is-active' : '' ?>"
           href="<?= e($tabUrl($key)) ?>">
            <span><?= e($label) ?></span>
            <span class="admin-tabs__count"><?= (int) ($totals[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<form class="admin-toolbar" method="get" action="<?= url('/admin/logs') ?>">
    <input type="hidden" name="type" value="<?= e($type) ?>">

    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="log-keyword">
            <?php
            $keywordLabel = match ($type) {
                'login'     => '账号 / IP',
                'operation' => '操作人 / 动作 / 目标',
                'payment'   => '订单号',
                'mail'      => '收件人 / 主题',
                'captcha'   => 'IP',
                default     => '关键词',
            };
            echo e($keywordLabel);
            ?>
        </label>
        <input class="input" id="log-keyword" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="输入关键词搜索">
    </div>

    <?php if (isset($options['status']) && $options['status'] !== []): ?>
        <div class="admin-toolbar__field">
            <label class="form-label" for="log-status">状态</label>
            <select class="select" id="log-status" name="status">
                <option value="">全部状态</option>
                <?php foreach ($options['status'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if (isset($options['action']) && $options['action'] !== []): ?>
        <div class="admin-toolbar__field">
            <label class="form-label" for="log-action">动作</label>
            <select class="select" id="log-action" name="action">
                <option value="">全部动作</option>
                <?php foreach ($options['action'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filters['action'] === $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if (isset($options['scene']) && $options['scene'] !== []): ?>
        <div class="admin-toolbar__field">
            <label class="form-label" for="log-scene">场景</label>
            <select class="select" id="log-scene" name="scene">
                <option value="">全部场景</option>
                <?php foreach ($options['scene'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filters['scene'] === $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="admin-toolbar__field">
        <label class="form-label" for="log-date-from">起始日期</label>
        <input class="input" id="log-date-from" type="date" name="date_from"
               value="<?= e((string) $filters['date_from']) ?>">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="log-date-to">截止日期</label>
        <input class="input" id="log-date-to" type="date" name="date_to"
               value="<?= e((string) $filters['date_to']) ?>">
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= e($tabUrl($type)) ?>">重置</a>
    </div>
</form>

<?php if ($rows === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的日志记录。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <?php if ($type === 'login'): ?>
                        <th>账号</th>
                        <th>用户ID</th>
                        <th>IP</th>
                        <th>状态</th>
                        <th>说明</th>
                        <th>时间</th>
                    <?php elseif ($type === 'operation'): ?>
                        <th>操作人</th>
                        <th>动作</th>
                        <th>目标</th>
                        <th>详情</th>
                        <th>IP</th>
                        <th>时间</th>
                    <?php elseif ($type === 'payment'): ?>
                        <th>订单号</th>
                        <th>动作</th>
                        <th>状态</th>
                        <th>原始数据</th>
                        <th>IP</th>
                        <th>时间</th>
                    <?php elseif ($type === 'mail'): ?>
                        <th>收件人</th>
                        <th>主题</th>
                        <th>状态</th>
                        <th>错误</th>
                        <th>时间</th>
                    <?php else: ?>
                        <th>场景</th>
                        <th>IP</th>
                        <th>状态</th>
                        <th>时间</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-faint"><?= (int) $row['id'] ?></td>

                        <?php if ($type === 'login'): ?>
                            <td><?= e((string) ($row['username'] !== '' ? $row['username'] : '—')) ?></td>
                            <td class="text-faint"><?= (int) ($row['user_id'] ?? 0) > 0 ? (int) $row['user_id'] : '—' ?></td>
                            <td class="text-faint"><?= e((string) ($row['ip'] ?? '—')) ?></td>
                            <td>
                                <span class="badge <?= $badge($type, (string) $row['status']) ?>">
                                    <?= e($statusLabel($type, (string) $row['status'])) ?>
                                </span>
                            </td>
                            <td class="text-faint"><?= e((string) ($row['message'] ?? '')) ?></td>
                            <td class="text-faint"><?= e((string) $row['created_at']) ?></td>

                        <?php elseif ($type === 'operation'): ?>
                            <td><?= e((string) ($row['username'] !== '' ? $row['username'] : '—')) ?></td>
                            <td>
                                <span class="badge badge--primary"><?= e(Log::actionLabel((string) $row['action'])) ?></span>
                                <span class="text-faint log-action-code"><?= e((string) $row['action']) ?></span>
                            </td>
                            <td class="text-faint">
                                <?php if ((string) $row['target_type'] !== ''): ?>
                                    <?= e((string) $row['target_type']) ?><?= (int) ($row['target_id'] ?? 0) > 0 ? '#' . (int) $row['target_id'] : '' ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $text = $detailText($row['detail'] ?? null); ?>
                                <?php if ($text !== ''): ?>
                                    <details class="log-detail">
                                        <summary>查看</summary>
                                        <pre class="log-raw"><?= e($text) ?></pre>
                                    </details>
                                <?php else: ?>
                                    <span class="text-faint">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-faint"><?= e((string) ($row['ip'] ?? '—')) ?></td>
                            <td class="text-faint"><?= e((string) $row['created_at']) ?></td>

                        <?php elseif ($type === 'payment'): ?>
                            <td class="text-faint"><?= e((string) ($row['order_no'] !== '' ? $row['order_no'] : '—')) ?></td>
                            <td class="text-faint"><?= e(Log::PAYMENT_ACTIONS[(string) $row['action']] ?? (string) $row['action']) ?></td>
                            <td>
                                <span class="badge <?= $badge($type, (string) $row['status']) ?>">
                                    <?= e($statusLabel($type, (string) $row['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php $raw = (string) ($row['raw'] ?? ''); ?>
                                <?php if ($raw !== ''): ?>
                                    <details class="log-detail">
                                        <summary>查看</summary>
                                        <pre class="log-raw"><?= e($raw) ?></pre>
                                    </details>
                                <?php else: ?>
                                    <span class="text-faint">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-faint"><?= e((string) ($row['ip'] ?? '—')) ?></td>
                            <td class="text-faint"><?= e((string) $row['created_at']) ?></td>

                        <?php elseif ($type === 'mail'): ?>
                            <td><?= e((string) $row['to_email']) ?></td>
                            <td><?= e((string) $row['subject']) ?></td>
                            <td>
                                <span class="badge <?= $badge($type, (string) $row['status']) ?>">
                                    <?= e($statusLabel($type, (string) $row['status'])) ?>
                                </span>
                            </td>
                            <td class="text-faint"><?= e((string) ($row['error'] ?? '')) ?></td>
                            <td class="text-faint"><?= e((string) $row['created_at']) ?></td>

                        <?php else: ?>
                            <td>
                                <span class="badge badge--primary">
                                    <?= e(Log::CAPTCHA_SCENES[(string) $row['scene']] ?? (string) $row['scene']) ?>
                                </span>
                            </td>
                            <td class="text-faint"><?= e((string) ($row['ip'] ?? '—')) ?></td>
                            <td>
                                <span class="badge <?= $badge($type, (string) $row['status']) ?>">
                                    <?= e($statusLabel($type, (string) $row['status'])) ?>
                                </span>
                            </td>
                            <td class="text-faint"><?= e((string) $row['created_at']) ?></td>
                        <?php endif; ?>
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
