<?php

declare(strict_types=1);

/**
 * 后台优惠券管理 - 列表
 *
 * @var array<int, array<string, mixed>> $coupons 优惠券列表
 * @var array<string, mixed> $filters 筛选条件
 */

use App\Models\Coupon;

$now = time();
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">优惠券管理</h2>
        <p class="admin-page-head__desc">
            共 <?= count($coupons) ?> 张优惠券。用户在订单详情页输入优惠码即可抵扣，支付成功后自动核销。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--sm" href="<?= url('/admin/coupons/create') ?>">新增优惠券</a>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/coupons') ?>">
    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="filter-keyword">关键词</label>
        <input class="input" id="filter-keyword" type="search" name="q"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="优惠码 / 名称">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-type">类型</label>
        <select class="select" id="filter-type" name="type">
            <option value="">全部类型</option>
            <?php foreach (Coupon::TYPE_LABELS as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['type'] === $value ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-active">状态</label>
        <select class="select" id="filter-active" name="is_active">
            <option value="">全部状态</option>
            <option value="1"<?= $filters['is_active'] === '1' ? ' selected' : '' ?>>已启用</option>
            <option value="0"<?= $filters['is_active'] === '0' ? ' selected' : '' ?>>已停用</option>
        </select>
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/coupons') ?>">重置</a>
    </div>
</form>

<?php if ($coupons === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的优惠券，点击右上角「新增优惠券」创建。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>优惠码</th>
                    <th>名称</th>
                    <th>规则</th>
                    <th>适用范围</th>
                    <th>已用 / 发行</th>
                    <th>有效期</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($coupons as $item): ?>
                    <?php
                    $itemId        = (int) $item['id'];
                    $totalQuantity = (int) $item['total_quantity'];
                    $isActive      = (int) $item['is_active'] === 1;
                    $startAt       = (string) ($item['start_at'] ?? '');
                    $endAt         = (string) ($item['end_at'] ?? '');
                    $expired       = $endAt !== '' && strtotime($endAt) < $now;
                    $notStarted    = $startAt !== '' && strtotime($startAt) > $now;
                    ?>
                    <tr>
                        <td><code><?= e((string) $item['code']) ?></code></td>
                        <td><?= e((string) $item['name']) ?></td>
                        <td><?= e(Coupon::ruleText($item)) ?></td>
                        <td>
                            <?php if (!empty($item['course_id'])): ?>
                                <?= e((string) ($item['course_title'] ?? ('#' . (int) $item['course_id']))) ?>
                            <?php else: ?>
                                <span class="badge">全场通用</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-faint">
                            <?= (int) $item['used_quantity'] ?> / <?= $totalQuantity > 0 ? $totalQuantity : '不限' ?>
                        </td>
                        <td class="text-faint">
                            <?= $startAt !== '' ? e($startAt) : '不限' ?>
                            <br>至 <?= $endAt !== '' ? e($endAt) : '不限' ?>
                        </td>
                        <td>
                            <?php if (!$isActive): ?>
                                <span class="badge">已停用</span>
                            <?php elseif ($expired): ?>
                                <span class="badge badge--danger">已过期</span>
                            <?php elseif ($notStarted): ?>
                                <span class="badge badge--info">未开始</span>
                            <?php else: ?>
                                <span class="badge badge--success">进行中</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm"
                                   href="<?= url('/admin/coupons/' . $itemId . '/edit') ?>">编辑</a>
                                <form method="post" action="<?= url('/admin/coupons/' . $itemId . '/delete') ?>"
                                      data-confirm="确定删除该优惠券吗？已核销的记录会保留。">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--danger btn--sm" type="submit">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
