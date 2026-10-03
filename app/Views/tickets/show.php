<?php

declare(strict_types=1);

/**
 * 工单详情（前台）
 *
 * @var array<string, mixed> $ticket 工单详情
 * @var array<int, array<string, mixed>> $replies 工单回复列表
 * @var array<int, array<int, array<string, mixed>>> $attachments 附件（键 0 为主帖附件，其余按回复 ID 分组）
 * @var int $maxFiles 附件数量上限
 * @var int $maxMb 单个附件大小上限（MB）
 * @var array<int, string> $allowedTypes 允许上传的附件扩展名
 */

use App\Models\Ticket;

$ticketId    = (int) $ticket['id'];
$status      = (string) $ticket['status'];
$isClosed    = $status === Ticket::STATUS_CLOSED;

$statusBadges = [
    Ticket::STATUS_PENDING    => 'badge--warning',
    Ticket::STATUS_PROCESSING => 'badge--info',
    Ticket::STATUS_REPLIED    => 'badge--success',
    Ticket::STATUS_CLOSED     => 'badge',
];

$bodyAttachments = $attachments[0] ?? [];
?>
<div class="page-head container">
    <div class="flex-between flex-wrap gap-3">
        <div>
            <h1 class="page-head__title"><?= e($ticket['title']) ?></h1>
            <p class="page-head__desc mb-0">
                <?= e(t('工单号 %s', [$ticket['ticket_no']])) ?> · <?= e(t(Ticket::typeLabel((string) $ticket['type']))) ?>
                · <?= e(t(Ticket::categoryLabel((string) $ticket['category']))) ?>
            </p>
        </div>
        <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>"><?= e(t(Ticket::statusLabel($status))) ?></span>
    </div>
</div>

<div class="container">
    <div class="order-layout">
        <div>
            <div class="card">
                <div class="card__header flex-between">
                    <span><?= e(t('问题描述')) ?></span>
                    <span class="text-faint"><?= e($ticket['created_at']) ?></span>
                </div>
                <div class="card__body">
                    <div class="ticket-content"><?= nl2br(e($ticket['content'])) ?></div>

                    <?php if ($bodyAttachments !== []): ?>
                        <div class="ticket-attachments">
                            <p class="text-muted mb-0"><?= e(t('附件')) ?></p>
                            <ul class="ticket-attachments__list">
                                <?php foreach ($bodyAttachments as $attachment): ?>
                                    <li>
                                        <a href="<?= url('/ticket/attachment/' . (int) $attachment['id']) ?>">
                                            <?= e($attachment['file_name']) ?>
                                        </a>
                                        <span class="text-faint"><?= e(t('（%s）', [format_bytes((int) $attachment['file_size'])])) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($replies !== []): ?>
                <div class="ticket-thread ticket-thread--conversation">
                    <?php foreach ($replies as $reply): ?>
                        <?php
                        $isAdminReply = (int) $reply['is_admin'] === 1;
                        $replyFiles   = $attachments[(int) $reply['id']] ?? [];
                        $author       = $isAdminReply
                            ? t('客服')
                            : (string) ($reply['user_nickname'] ?: $reply['user_name']);
                        ?>
                        <div class="ticket-message<?= $isAdminReply ? ' ticket-message--admin' : '' ?>">
                            <div class="ticket-message__head">
                                <span class="ticket-message__author"><?= e($author) ?></span>
                                <?php if ($isAdminReply): ?>
                                    <span class="badge badge--primary"><?= e(t('客服')) ?></span>
                                <?php endif; ?>
                                <span class="text-faint"><?= e($reply['created_at']) ?></span>
                            </div>
                            <div class="ticket-message__body"><?= nl2br(e($reply['content'])) ?></div>

                            <?php if ($replyFiles !== []): ?>
                                <ul class="ticket-attachments__list">
                                    <?php foreach ($replyFiles as $attachment): ?>
                                        <li>
                                            <a href="<?= url('/ticket/attachment/' . (int) $attachment['id']) ?>">
                                                <?= e($attachment['file_name']) ?>
                                            </a>
                                            <span class="text-faint"><?= e(t('（%s）', [format_bytes((int) $attachment['file_size'])])) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$isClosed): ?>
                <div class="card">
                    <div class="card__header"><?= e(t('补充回复')) ?></div>
                    <div class="card__body">
                        <form method="post" action="<?= url('/ticket/' . $ticketId . '/reply') ?>" enctype="multipart/form-data">
                            <?= csrf_field() ?>

                            <div class="form-group">
                                <textarea class="textarea" name="content" rows="5" maxlength="5000"
                                          placeholder="<?= e(t('继续补充你的问题或回复客服')) ?>" required></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="attachments"><?= e(t('附件（选填）')) ?></label>
                                <input class="input" type="file" id="attachments" name="attachments[]" multiple>
                                <p class="form-hint">
                                    <?= e(t('最多 %d 个，单个不超过 %d MB。', [(int) $maxFiles, (int) $maxMb])) ?>
                                </p>
                            </div>

                            <button class="btn" type="submit"><?= e(t('提交回复')) ?></button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert--info" role="alert">
                    <span class="grow"><?= e(t('该工单已关闭。如问题仍未解决，可重新提交一条新工单。')) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <aside>
            <div class="card">
                <div class="card__header"><?= e(t('工单信息')) ?></div>
                <div class="card__body">
                    <ul class="list-plain">
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('工单号')) ?></span>
                            <span class="text-faint"><?= e($ticket['ticket_no']) ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('类型')) ?></span>
                            <span><?= e(t(Ticket::typeLabel((string) $ticket['type']))) ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('分类')) ?></span>
                            <span><?= e(t(Ticket::categoryLabel((string) $ticket['category']))) ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('回复数')) ?></span>
                            <span><?= (int) $ticket['reply_count'] ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('提交时间')) ?></span>
                            <span class="text-faint"><?= e($ticket['created_at']) ?></span>
                        </li>
                        <?php if (!empty($ticket['closed_at'])): ?>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('关闭时间')) ?></span>
                                <span class="text-faint"><?= e($ticket['closed_at']) ?></span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card__footer">
                    <div class="admin-actions">
                        <?php if (!$isClosed): ?>
                            <form method="post" action="<?= url('/ticket/' . $ticketId . '/close') ?>"
                                  data-confirm="<?= e(t('确认关闭该工单？关闭后将无法继续回复。')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn--sm btn--outline" type="submit"><?= e(t('关闭工单')) ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= url('/ticket/' . $ticketId . '/delete') ?>"
                              data-confirm="<?= e(t('确认删除该工单？删除后不可恢复。')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--sm btn--danger" type="submit"><?= e(t('删除工单')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>
