<?php

declare(strict_types=1);

/**
 * 后台工单管理 - 详情
 *
 * @var array<string, mixed> $ticket 工单记录
 * @var array<int, array<string, mixed>> $replies 工单回复列表
 * @var array<int, array<int, array<string, mixed>>> $attachments 按回复 ID 归类的附件
 * @var array<string, string> $statuses 工单状态选项（状态 => 名称）
 * @var array<int, string> $priorities 工单优先级选项（级别 => 名称）
 * @var bool $isAppeal 是否为申诉类工单
 * @var bool $userBanned 工单所属用户是否被封禁
 * @var int $userId 工单所属用户 ID
 */

use App\Models\Ticket;

$ticketId = (int) $ticket['id'];
$status   = (string) $ticket['status'];
$type     = (string) $ticket['type'];
$priority = (int) $ticket['priority'];
$isClosed = $status === Ticket::STATUS_CLOSED;

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

$bodyAttachments = $attachments[0] ?? [];
$userName        = (string) ($ticket['user_name'] ?? '');
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">工单详情</h2>
        <p class="admin-page-head__desc">
            工单号 <strong><?= e((string) $ticket['ticket_no']) ?></strong>
            · <?= e(Ticket::typeLabel($type)) ?>
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/tickets') ?>">返回列表</a>
    </div>
</div>

<div class="order-layout">
    <div>
        <div class="card">
            <div class="card__header flex-between">
                <span>工单信息</span>
                <span class="flex-center gap-2">
                    <span class="badge <?= $priorityBadge[$priority] ?? 'badge' ?>">
                        <?= e($priorities[$priority] ?? (string) $priority) ?>
                    </span>
                    <span class="badge <?= $statusBadge[$status] ?? 'badge' ?>">
                        <?= e($statuses[$status] ?? $status) ?>
                    </span>
                </span>
            </div>
            <div class="card__body">
                <ul class="list-plain">
                    <li class="flex-between">
                        <span class="text-muted">标题</span>
                        <span><?= e((string) $ticket['title']) ?></span>
                    </li>
                    <li class="flex-between">
                        <span class="text-muted">分类</span>
                        <span><?= e(Ticket::categoryLabel((string) $ticket['category'])) ?></span>
                    </li>
                    <li class="flex-between">
                        <span class="text-muted">提交用户</span>
                        <span>
                            <?php if ($userId > 0): ?>
                                <a href="<?= url('/admin/users/' . $userId . '/edit') ?>">
                                    <?= e($userName !== '' ? $userName : '#' . $userId) ?>
                                </a>
                                <?php if ($userBanned): ?>
                                    <span class="badge badge--danger">已封禁</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-faint">—</span>
                            <?php endif; ?>
                        </span>
                    </li>
                    <?php if (!empty($ticket['user_email'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">用户邮箱</span>
                            <span class="text-faint"><?= e((string) $ticket['user_email']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if ($userBanned && !empty($ticket['user_ban_reason'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">封禁原因</span>
                            <span class="text-faint"><?= e((string) $ticket['user_ban_reason']) ?></span>
                        </li>
                    <?php endif; ?>
                    <li class="flex-between">
                        <span class="text-muted">回复数</span>
                        <span><?= (int) $ticket['reply_count'] ?></span>
                    </li>
                    <li class="flex-between">
                        <span class="text-muted">提交时间</span>
                        <span class="text-faint"><?= e((string) $ticket['created_at']) ?></span>
                    </li>
                    <?php if (!empty($ticket['last_reply_at'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">最近回复</span>
                            <span class="text-faint"><?= e((string) $ticket['last_reply_at']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($ticket['closed_at'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">关闭时间</span>
                            <span class="text-faint"><?= e((string) $ticket['closed_at']) ?></span>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="ticket-content"><?= nl2br(e((string) $ticket['content'])) ?></div>

                <?php if ($bodyAttachments !== []): ?>
                    <div class="ticket-attachments">
                        <p class="text-muted mb-0">附件</p>
                        <ul class="ticket-attachments__list">
                            <?php foreach ($bodyAttachments as $attachment): ?>
                                <li>
                                    <a href="<?= url('/ticket/attachment/' . (int) $attachment['id']) ?>">
                                        <?= e((string) $attachment['file_name']) ?>
                                    </a>
                                    <span class="text-faint">（<?= e(format_bytes((int) $attachment['file_size'])) ?>）</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__header">会话记录</div>
            <div class="card__body">
                <?php if ($replies === []): ?>
                    <p class="text-muted mb-0">暂无回复，可在右侧回复用户。</p>
                <?php else: ?>
                    <div class="ticket-thread">
                        <?php foreach ($replies as $reply): ?>
                            <?php
                            $isAdminReply = (int) $reply['is_admin'] === 1;
                            $replyFiles   = $attachments[(int) $reply['id']] ?? [];
                            $author       = $isAdminReply
                                ? '客服'
                                : (string) (($reply['user_nickname'] ?? '') !== '' ? $reply['user_nickname'] : ($reply['user_name'] ?? '用户'));
                            ?>
                            <div class="ticket-message<?= $isAdminReply ? ' ticket-message--admin' : '' ?>">
                                <div class="ticket-message__head">
                                    <span class="ticket-message__author"><?= e($author) ?></span>
                                    <?php if ($isAdminReply): ?>
                                        <span class="badge badge--primary">客服</span>
                                    <?php endif; ?>
                                    <span class="text-faint"><?= e((string) $reply['created_at']) ?></span>
                                </div>
                                <div class="ticket-message__body"><?= nl2br(e((string) $reply['content'])) ?></div>

                                <?php if ($replyFiles !== []): ?>
                                    <ul class="ticket-attachments__list">
                                        <?php foreach ($replyFiles as $attachment): ?>
                                            <li>
                                                <a href="<?= url('/ticket/attachment/' . (int) $attachment['id']) ?>">
                                                    <?= e((string) $attachment['file_name']) ?>
                                                </a>
                                                <span class="text-faint">（<?= e(format_bytes((int) $attachment['file_size'])) ?>）</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside>
        <div class="card">
            <div class="card__header">回复工单</div>
            <div class="card__body">
                <form method="post" action="<?= url('/admin/tickets/' . $ticketId . '/reply') ?>">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <textarea class="textarea" name="content" rows="5" maxlength="5000"
                                  placeholder="输入给用户的回复内容" required></textarea>
                    </div>

                    <button class="btn btn--block" type="submit">发送回复</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card__header">状态与优先级</div>
            <div class="card__body">
                <form method="post" action="<?= url('/admin/tickets/' . $ticketId . '/status') ?>">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="ticket-status">工单状态</label>
                        <select class="select" id="ticket-status" name="status">
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="btn btn--outline btn--sm btn--block" type="submit">更新状态</button>
                </form>

                <form class="mt-4" method="post" action="<?= url('/admin/tickets/' . $ticketId . '/priority') ?>">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="ticket-priority">优先级</label>
                        <select class="select" id="ticket-priority" name="priority">
                            <?php foreach ($priorities as $value => $label): ?>
                                <option value="<?= (int) $value ?>"<?= $priority === (int) $value ? ' selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="btn btn--outline btn--sm btn--block" type="submit">更新优先级</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card__header">操作</div>
            <div class="card__body">
                <div class="admin-order-actions">
                    <?php if ($isAppeal && $userBanned): ?>
                        <form method="post" action="<?= url('/admin/tickets/' . $ticketId . '/unban') ?>"
                              data-confirm="确定解除该用户的账号封禁吗？此操作会同时记录一条回复。">
                            <?= csrf_field() ?>
                            <button class="btn btn--sm btn--block" type="submit">核实并解封</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$isClosed): ?>
                        <form method="post" action="<?= url('/admin/tickets/' . $ticketId . '/status') ?>"
                              data-confirm="确定关闭该工单吗？关闭后用户将无法继续回复。">
                            <?= csrf_field() ?>
                            <input type="hidden" name="status" value="<?= e(Ticket::STATUS_CLOSED) ?>">
                            <button class="btn btn--ghost btn--sm btn--block" type="submit">关闭工单</button>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?= url('/admin/tickets/' . $ticketId . '/delete') ?>"
                          data-confirm="确定删除该工单吗？工单将从列表隐藏，历史数据保留。">
                        <?= csrf_field() ?>
                        <button class="btn btn--danger btn--sm btn--block" type="submit">删除工单</button>
                    </form>
                </div>
            </div>
        </div>
    </aside>
</div>
