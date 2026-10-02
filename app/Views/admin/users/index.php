<?php

declare(strict_types=1);

/**
 * 后台用户管理 - 列表
 *
 * @var array<string, mixed> $filters 当前筛选条件
 * @var array<int, array<string, mixed>> $users 当前页用户记录
 * @var int $total 用户总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var int $adminId 当前登录管理员 ID
 */

$pageUrl = static function (int $target): string {
    $query         = $_GET;
    $query['page'] = $target;

    return url('/admin/users') . '?' . http_build_query($query);
};

/** 是否处于有效封禁状态 */
$isBanned = static function (array $user): bool {
    $type = (string) ($user['ban_type'] ?? 'none');
    if ($type === 'none') {
        return false;
    }
    if ($type === 'permanent') {
        return true;
    }

    $until = $user['banned_until'] ?? null;

    return $until === null || strtotime((string) $until) > time();
};

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);

// 导出链接携带当前筛选条件（去掉空值）
$exportQuery = array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null);
$exportUrl   = url('/admin/users/export') . ($exportQuery !== [] ? '?' . http_build_query($exportQuery) : '');
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">用户管理</h2>
        <p class="admin-page-head__desc">共 <?= (int) $total ?> 个账号，可进行资料编辑、启停、权限与封禁管理。</p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--sm" href="<?= url('/admin/users/create') ?>">新增用户</a>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/users') ?>">
    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="filter-keyword">关键词</label>
        <input class="input" id="filter-keyword" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="用户名 / 昵称 / 邮箱">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-status">状态</label>
        <select class="select" id="filter-status" name="status">
            <option value="">全部</option>
            <option value="1"<?= $filters['status'] === '1' ? ' selected' : '' ?>>正常</option>
            <option value="0"<?= $filters['status'] === '0' ? ' selected' : '' ?>>已禁用</option>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-role">角色</label>
        <select class="select" id="filter-role" name="role">
            <option value="">全部</option>
            <option value="admin"<?= $filters['role'] === 'admin' ? ' selected' : '' ?>>管理员</option>
            <option value="user"<?= $filters['role'] === 'user' ? ' selected' : '' ?>>普通用户</option>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-banned">封禁</label>
        <select class="select" id="filter-banned" name="banned">
            <option value="">全部</option>
            <option value="1"<?= $filters['banned'] === '1' ? ' selected' : '' ?>>仅被封禁</option>
        </select>
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/users') ?>">重置</a>
        <a class="btn btn--outline btn--sm" href="<?= e($exportUrl) ?>">导出 CSV</a>
    </div>
</form>

<?php if ($users === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的用户。</p>
        </div>
    </div>
<?php else: ?>
    <form class="admin-batch-bar" id="batch-form" method="post" action="<?= url('/admin/users/batch') ?>"
          data-batch-form data-confirm="确定对已选中的账号执行该批量操作吗？">
        <?= csrf_field() ?>
        <span class="admin-batch-bar__count">已选 <strong data-batch-count>0</strong> 项</span>
        <div class="admin-batch-bar__actions">
            <select class="select" name="action" required aria-label="批量操作">
                <option value="">批量操作…</option>
                <option value="enable">启用账号</option>
                <option value="disable">禁用账号</option>
                <option value="unban">解除封禁</option>
                <option value="delete">删除账号</option>
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
                    <th>用户名</th>
                    <th>昵称</th>
                    <th>邮箱</th>
                    <th>角色</th>
                    <th>状态</th>
                    <th>订单数</th>
                    <th>注册时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $userId     = (int) $user['id'];
                    $isSelf     = $userId === (int) $adminId;
                    $isAdminRow = (int) ($user['is_admin'] ?? 0) === 1
                        || in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin'], true);
                    $banned     = $isBanned($user);
                    $active     = (int) $user['status'] === 1;
                    ?>
                    <tr>
                        <td class="col-check">
                            <input type="checkbox" form="batch-form" name="ids[]" value="<?= $userId ?>"
                                   data-batch-checkbox aria-label="选择用户 <?= e((string) $user['username']) ?>"
                                   <?= $isSelf ? 'disabled' : '' ?>>
                        </td>
                        <td class="text-faint"><?= $userId ?></td>
                        <td><?= e((string) $user['username']) ?></td>
                        <td><?= e((string) ($user['nickname'] ?? '')) ?></td>
                        <td class="text-faint"><?= e((string) ($user['email'] ?? '—')) ?></td>
                        <td>
                            <?php if ($isAdminRow): ?>
                                <span class="badge badge--primary">管理员</span>
                            <?php else: ?>
                                <span class="badge">普通用户</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($banned): ?>
                                <span class="badge badge--danger">已封禁</span>
                            <?php elseif ($active): ?>
                                <span class="badge badge--success">正常</span>
                            <?php else: ?>
                                <span class="badge">已禁用</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-faint"><?= (int) ($user['orders_count'] ?? 0) ?></td>
                        <td class="text-faint"><?= e((string) $user['created_at']) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm" href="<?= url('/admin/users/' . $userId) ?>">详情</a>
                                <a class="btn btn--outline btn--sm" href="<?= url('/admin/users/' . $userId . '/edit') ?>">编辑</a>

                                <form method="post" action="<?= url('/admin/users/' . $userId . '/reset-password') ?>"
                                      data-confirm="确定重置该用户的密码吗？将生成一个新的随机密码。">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--ghost btn--sm" type="submit">重置密码</button>
                                </form>

                                <?php if (!$isSelf): ?>
                                    <form method="post" action="<?= url('/admin/users/' . $userId . '/status') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--ghost btn--sm" type="submit">
                                            <?= $active ? '禁用' : '启用' ?>
                                        </button>
                                    </form>

                                    <form method="post" action="<?= url('/admin/users/' . $userId . '/admin') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--ghost btn--sm" type="submit">
                                            <?= $isAdminRow ? '取消管理员' : '设为管理员' ?>
                                        </button>
                                    </form>

                                    <form method="post" action="<?= url('/admin/users/' . $userId . '/delete') ?>"
                                          data-confirm="确定删除该用户吗？账号将被注销，订单等历史数据保留。">
                                        <?= csrf_field() ?>
                                        <button class="btn btn--danger btn--sm" type="submit">删除</button>
                                    </form>
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
