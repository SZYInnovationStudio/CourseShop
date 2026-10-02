<?php

declare(strict_types=1);

/**
 * 后台订单管理 - 详情
 *
 * @var array<string, mixed> $order 订单记录
 * @var string $statusLabel 订单状态名称
 * @var array<string, string> $statusBadges 订单状态对应的徽标样式
 * @var array<string, string> $payTypeLabel 支付方式名称映射
 * @var array<int, array<string, mixed>> $logs 订单流转日志
 * @var bool $canClose 是否可关闭
 * @var bool $canComplete 是否可完成
 * @var bool $canRefund 是否可退款
 * @var bool $canReconcile 是否可对账
 * @var array<string, mixed>|null $pendingRefund 待处理的退款单
 * @var array<string, mixed>|null $latestRefund 最近一条退款单
 */

$orderId    = (int) $order['id'];
$orderNo    = (string) $order['order_no'];
$status     = (string) $order['status'];
$amount     = (int) $order['amount'];
$original   = (int) $order['original_amount'];
$discount   = (int) $order['discount_amount'];
$courseId   = (int) $order['course_id'];
$payType    = (string) ($order['pay_type'] ?? '');
$userId     = (int) $order['user_id'];
$userName   = (string) ($order['user_name'] ?? '');

/** 订单流转日志里的状态展示名（含软删除标记 deleted） */
$logStatusLabels = [
    'pending'   => '待支付',
    'paying'    => '支付中',
    'paid'      => '已支付',
    'completed' => '已完成',
    'closed'    => '已关闭',
    'refunded'  => '已退款',
    'deleted'   => '已删除',
];
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">订单详情</h2>
        <p class="admin-page-head__desc">订单号 <strong><?= e($orderNo) ?></strong></p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/orders') ?>">返回列表</a>
    </div>
</div>

<div class="order-layout">
    <div>
        <div class="card">
            <div class="card__header flex-between">
                <span>订单信息</span>
                <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>"><?= e($statusLabel) ?></span>
            </div>
            <div class="card__body">
                <ul class="list-plain">
                    <li class="flex-between">
                        <span class="text-muted">课程</span>
                        <span><?= e((string) $order['course_title']) ?></span>
                    </li>
                    <li class="flex-between">
                        <span class="text-muted">订单号</span>
                        <span class="text-faint"><?= e($orderNo) ?></span>
                    </li>
                    <li class="flex-between">
                        <span class="text-muted">下单用户</span>
                        <span>
                            <?php if ($userId > 0): ?>
                                <a href="<?= url('/admin/users/' . $userId . '/edit') ?>">
                                    <?= e($userName !== '' ? $userName : '#' . $userId) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-faint">—</span>
                            <?php endif; ?>
                        </span>
                    </li>
                    <?php if (!empty($order['user_email'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">用户邮箱</span>
                            <span class="text-faint"><?= e((string) $order['user_email']) ?></span>
                        </li>
                    <?php endif; ?>
                    <li class="flex-between">
                        <span class="text-muted">应付金额</span>
                        <span class="price price--accent"><?= price_html($amount) ?></span>
                    </li>
                    <?php if ($original > $amount): ?>
                        <li class="flex-between">
                            <span class="text-muted">原价</span>
                            <span class="price-original">&yen;<?= format_money($original) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if ($discount > 0): ?>
                        <li class="flex-between">
                            <span class="text-muted">优惠金额</span>
                            <span>&minus;&yen;<?= format_money($discount) ?></span>
                        </li>
                    <?php endif; ?>
                    <li class="flex-between">
                        <span class="text-muted">支付方式</span>
                        <span><?= e($payTypeLabel[$payType] ?? '—') ?></span>
                    </li>
                    <li class="flex-between">
                        <span class="text-muted">下单时间</span>
                        <span class="text-faint"><?= e((string) $order['created_at']) ?></span>
                    </li>
                    <?php if (!empty($order['expire_at'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">支付截止</span>
                            <span class="text-faint"><?= e((string) $order['expire_at']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($order['paid_at'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">支付时间</span>
                            <span class="text-faint"><?= e((string) $order['paid_at']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($order['closed_at'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">关闭时间</span>
                            <span class="text-faint"><?= e((string) $order['closed_at']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($order['refunded_at'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">退款金额</span>
                            <span class="price price--accent"><?= price_html((int) ($order['refund_amount'] ?? 0)) ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted">退款时间</span>
                            <span class="text-faint"><?= e((string) $order['refunded_at']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($order['trade_no'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">商户单号</span>
                            <span class="text-faint"><?= e((string) $order['trade_no']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($order['api_trade_no'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">平台交易号</span>
                            <span class="text-faint"><?= e((string) $order['api_trade_no']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($order['client_ip'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">下单 IP</span>
                            <span class="text-faint"><?= e((string) $order['client_ip']) ?></span>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($order['device'])): ?>
                        <li class="flex-between">
                            <span class="text-muted">设备</span>
                            <span class="text-faint"><?= e((string) $order['device']) ?></span>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card__header">状态流转日志</div>
            <div class="card__body">
                <?php if ($logs === []): ?>
                    <p class="text-muted mb-0">暂无状态流转记录。</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>时间</th>
                                <th>变更</th>
                                <th>操作者</th>
                                <th>备注</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $from = (string) ($log['from_status'] ?? '');
                                $to   = (string) ($log['to_status'] ?? '');
                                $operatorRaw = (string) ($log['operator'] ?? '');
                                if (str_starts_with($operatorRaw, 'admin:')) {
                                    $operatorLabel = '管理员 #' . substr($operatorRaw, 6);
                                } elseif ($operatorRaw === 'system') {
                                    $operatorLabel = '系统';
                                } else {
                                    $operatorLabel = $operatorRaw !== '' ? $operatorRaw : '—';
                                }
                                ?>
                                <tr>
                                    <td class="text-faint"><?= e((string) $log['created_at']) ?></td>
                                    <td>
                                        <span class="text-faint"><?= e($logStatusLabels[$from] ?? ($from !== '' ? $from : '—')) ?></span>
                                        <span aria-hidden="true">&rarr;</span>
                                        <strong><?= e($logStatusLabels[$to] ?? $to) ?></strong>
                                    </td>
                                    <td class="text-faint"><?= e($operatorLabel) ?></td>
                                    <td><?= e((string) ($log['remark'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside>
        <div class="card">
            <div class="card__header">操作</div>
            <div class="card__body">
                <div class="admin-order-actions">
                    <a class="btn btn--outline btn--sm btn--block"
                       href="<?= url('/course/' . $courseId) ?>" target="_blank" rel="noopener">查看课程</a>

                    <?php if ($canReconcile): ?>
                        <form method="post" action="<?= url('/admin/orders/' . $orderId . '/reconcile') ?>"
                              data-confirm="将向支付网关主动查单，若网关侧已收款则自动补单开通。确定继续吗？">
                            <?= csrf_field() ?>
                            <button class="btn btn--outline btn--sm btn--block" type="submit">对账查单</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canClose): ?>
                        <form method="post" action="<?= url('/admin/orders/' . $orderId . '/close') ?>"
                              data-confirm="确定关闭该订单吗？关闭后用户将无法继续支付。">
                            <?= csrf_field() ?>
                            <button class="btn btn--ghost btn--sm btn--block" type="submit">关闭订单</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canComplete): ?>
                        <form method="post" action="<?= url('/admin/orders/' . $orderId . '/complete') ?>"
                              data-confirm="确定将该订单标记为已完成吗？">
                            <?= csrf_field() ?>
                            <button class="btn btn--sm btn--block" type="submit">标记完成</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canRefund): ?>
                        <form method="post" class="admin-order-refund"
                              action="<?= url('/admin/orders/' . $orderId . '/refund') ?>"
                              data-confirm="确定发起退款吗？将生成一张待审核的退款单，需到退款管理中确认执行。">
                            <?= csrf_field() ?>
                            <input class="input" type="text" name="reason" maxlength="255"
                                   placeholder="退款原因（可选）">
                            <button class="btn btn--danger btn--sm btn--block" type="submit">发起退款</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($pendingRefund !== null): ?>
                        <p class="form-hint">
                            存在待审核退款单
                            <strong><?= e((string) $pendingRefund['refund_no']) ?></strong>，
                            请到 <a href="<?= url('/admin/refunds') ?>">退款管理</a> 确认或驳回。
                        </p>
                    <?php endif; ?>

                    <form method="post" action="<?= url('/admin/orders/' . $orderId . '/delete') ?>"
                          data-confirm="确定删除该订单吗？订单将从列表隐藏，历史数据保留。">
                        <?= csrf_field() ?>
                        <button class="btn btn--danger btn--sm btn--block" type="submit">删除订单</button>
                    </form>
                </div>
            </div>
        </div>
    </aside>
</div>
