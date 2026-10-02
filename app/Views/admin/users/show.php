<?php

declare(strict_types=1);

/**
 * 后台用户管理 - 详情
 *
 * 汇总展示单个账号的购买课程、订单金额、登录日志与操作日志。
 *
 * @var array<string, mixed> $user 用户记录
 * @var bool $isAdmin 是否为管理员
 * @var array<int, array<string, mixed>> $enrollments 该用户的全部课程授权
 * @var int $orderCount 订单总数
 * @var int $paidAmount 已支付金额合计（分）
 * @var array<int, array<string, mixed>> $loginLogs 最近登录日志
 * @var array<int, array<string, mixed>> $operationLogs 最近操作日志
 * @var array<string, string> $loginStatus 登录状态文案
 */

$userId   = (int) $user['id'];
$username = (string) $user['username'];
$email    = (string) ($user['email'] ?? '');

$active = (int) $user['status'] === 1;

$banType = (string) ($user['ban_type'] ?? 'none');
$banned  = false;
if ($banType === 'permanent') {
    $banned = true;
} elseif ($banType === 'temp') {
    $until  = $user['banned_until'] ?? null;
    $banned = $until === null || strtotime((string) $until) > time();
}

$emailVerified = ($user['email_verified_at'] ?? null) !== null;
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">用户详情</h2>
        <p class="admin-page-head__desc">
            账号 <strong><?= e($username) ?></strong>（ID：<?= $userId ?>），注册于 <?= e((string) $user['created_at']) ?>。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/users') ?>">返回列表</a>
        <a class="btn btn--sm" href="<?= url('/admin/users/' . $userId . '/edit') ?>">编辑资料</a>
    </div>
</div>

<div class="card">
    <div class="card__header">账号信息</div>
    <div class="card__body">
        <ul class="list-plain">
            <li class="flex-between">
                <span class="text-muted">昵称</span>
                <span><?= e((string) ($user['nickname'] ?? '')) ?></span>
            </li>
            <li class="flex-between">
                <span class="text-muted">邮箱</span>
                <span>
                    <?php if ($email === ''): ?>
                        <span class="text-faint">未绑定</span>
                    <?php else: ?>
                        <?= e($email) ?>
                        <?php if ($emailVerified): ?>
                            <span class="badge badge--success">已验证</span>
                        <?php else: ?>
                            <span class="badge">未验证</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </li>
            <li class="flex-between">
                <span class="text-muted">角色</span>
                <span>
                    <?php if ($isAdmin): ?>
                        <span class="badge badge--primary">管理员</span>
                    <?php else: ?>
                        <span class="badge">普通用户</span>
                    <?php endif; ?>
                </span>
            </li>
            <li class="flex-between">
                <span class="text-muted">账号状态</span>
                <span>
                    <?php if ($banned): ?>
                        <span class="badge badge--danger">已封禁</span>
                    <?php elseif ($active): ?>
                        <span class="badge badge--success">正常</span>
                    <?php else: ?>
                        <span class="badge">已禁用</span>
                    <?php endif; ?>
                </span>
            </li>
            <?php if ($banned && $banType !== 'none'): ?>
                <li class="flex-between">
                    <span class="text-muted">封禁原因</span>
                    <span><?= e((string) ($user['ban_reason'] ?? '—')) ?></span>
                </li>
            <?php endif; ?>
            <li class="flex-between">
                <span class="text-muted">最后登录</span>
                <span class="text-faint">
                    <?= e((string) ($user['last_login_at'] ?? '—')) ?>
                    <?php if (($user['last_login_ip'] ?? null) !== null): ?>
                        · <?= e((string) $user['last_login_ip']) ?>
                    <?php endif; ?>
                </span>
            </li>
        </ul>
    </div>
</div>

<div class="admin-stats">
    <div class="admin-stat">
        <div class="admin-stat__label">订单总数</div>
        <div class="admin-stat__value"><?= $orderCount ?></div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__label">已支付金额</div>
        <div class="admin-stat__value"><?= e(format_money($paidAmount)) ?></div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__label">课程授权</div>
        <div class="admin-stat__value"><?= count($enrollments) ?></div>
    </div>
</div>

<div class="card">
    <div class="card__header">购买课程</div>
    <?php if ($enrollments === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">该用户暂无课程授权记录。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>课程</th>
                    <th>订单号</th>
                    <th>开通时间</th>
                    <th>到期时间</th>
                    <th>状态</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($enrollments as $item): ?>
                    <?php
                    $expired = ($item['expire_at'] ?? null) !== null && strtotime((string) $item['expire_at']) <= time();
                    $revoked = (int) $item['status'] !== 1 || ($item['deleted_at'] ?? null) !== null;
                    ?>
                    <tr>
                        <td><?= e((string) ($item['course_title'] ?? ('课程 #' . (int) $item['course_id']))) ?></td>
                        <td class="text-faint"><?= e((string) ($item['order_id'] ?? '—')) ?></td>
                        <td class="text-faint"><?= e((string) ($item['granted_at'] ?? '—')) ?></td>
                        <td class="text-faint"><?= e((string) ($item['expire_at'] ?? '长期有效')) ?></td>
                        <td>
                            <?php if ($revoked): ?>
                                <span class="badge">已失效</span>
                            <?php elseif ($expired): ?>
                                <span class="badge">已过期</span>
                            <?php else: ?>
                                <span class="badge badge--success">有效</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__header">登录日志（最近 20 条）</div>
    <?php if ($loginLogs === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">暂无登录日志。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>时间</th>
                    <th>状态</th>
                    <th>IP</th>
                    <th>设备</th>
                    <th>说明</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($loginLogs as $log): ?>
                    <?php $ok = (string) $log['status'] === 'success'; ?>
                    <tr>
                        <td class="text-faint"><?= e((string) $log['created_at']) ?></td>
                        <td>
                            <span class="badge <?= $ok ? 'badge--success' : 'badge--danger' ?>">
                                <?= e($loginStatus[(string) $log['status']] ?? (string) $log['status']) ?>
                            </span>
                        </td>
                        <td class="text-faint"><?= e((string) ($log['ip'] ?? '—')) ?></td>
                        <td class="text-faint"><?= e(device_label($log['ua'] ?? null)) ?></td>
                        <td class="text-faint"><?= e((string) ($log['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__header">操作日志（最近 20 条）</div>
    <?php if ($operationLogs === []): ?>
        <div class="card__body">
            <p class="text-muted mb-0">暂无操作日志。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>时间</th>
                    <th>操作</th>
                    <th>对象</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($operationLogs as $log): ?>
                    <tr>
                        <td class="text-faint"><?= e((string) $log['created_at']) ?></td>
                        <td><?= e(\App\Models\Log::actionLabel((string) $log['action'])) ?></td>
                        <td class="text-faint">
                            <?php
                            $target = (string) ($log['target_type'] ?? '');
                            $targetId = (int) ($log['target_id'] ?? 0);
                            ?>
                            <?= $target !== '' ? e($target) . ($targetId > 0 ? ' #' . $targetId : '') : '—' ?>
                        </td>
                        <td class="text-faint"><?= e((string) ($log['ip'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
