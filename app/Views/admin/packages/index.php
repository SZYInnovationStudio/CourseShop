<?php

declare(strict_types=1);

/**
 * 后台套餐管理 - 列表
 *
 * @var array<int, array<string, mixed>> $packages 套餐列表
 * @var int $total 套餐总数
 * @var array<string, mixed> $filters 筛选条件
 */

use App\Models\Package;

?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">套餐管理</h2>
        <p class="admin-page-head__desc">
            共 <?= (int) $total ?> 个套餐。套餐把多门课程打包整体售卖，用户购买后一次性开通套餐内全部课程。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--sm" href="<?= url('/admin/packages/create') ?>">新增套餐</a>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/packages') ?>">
    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="filter-keyword">关键词</label>
        <input class="input" id="filter-keyword" type="search" name="q"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="套餐名称">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-status">状态</label>
        <select class="select" id="filter-status" name="status">
            <option value="">全部状态</option>
            <?php foreach (Package::STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/packages') ?>">重置</a>
    </div>
</form>

<?php if ($packages === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的套餐，点击右上角「新增套餐」创建。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>套餐名称</th>
                    <th>课程数</th>
                    <th>套餐价</th>
                    <th>划线价</th>
                    <th>销量</th>
                    <th>排序</th>
                    <th>推荐</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $item): ?>
                    <?php
                    $itemId        = (int) $item['id'];
                    $status        = (string) $item['status'];
                    $originalPrice = (int) $item['original_price'];
                    $badgeClass    = match ($status) {
                        'published' => 'badge--success',
                        'offline'   => 'badge--danger',
                        default     => 'badge',
                    };
                    ?>
                    <tr>
                        <td>
                            <div><?= e((string) $item['title']) ?></div>
                            <?php if ((string) $item['subtitle'] !== ''): ?>
                                <div class="text-faint"><?= e((string) $item['subtitle']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $item['course_count'] ?></td>
                        <td><span class="price price--accent"><?= e(format_money((int) $item['price'])) ?></span></td>
                        <td class="text-faint">
                            <?= $originalPrice > 0 ? e(format_money($originalPrice)) : '—' ?>
                        </td>
                        <td class="text-faint"><?= (int) $item['sales_count'] ?></td>
                        <td class="text-faint"><?= (int) $item['sort'] ?></td>
                        <td>
                            <?= (int) $item['is_recommend'] === 1 ? '是' : '—' ?>
                        </td>
                        <td><span class="badge <?= $badgeClass ?>"><?= e(Package::statusLabel($status)) ?></span></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm"
                                   href="<?= url('/admin/packages/' . $itemId . '/edit') ?>">编辑</a>
                                <form method="post" action="<?= url('/admin/packages/' . $itemId . '/delete') ?>"
                                      data-confirm="确定删除该套餐吗？已产生的订单会保留。">
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
