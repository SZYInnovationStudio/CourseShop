<?php

declare(strict_types=1);

/**
 * 后台分类管理 - 列表 + 新增
 *
 * @var array<int, array<string, mixed>> $categories 分类列表（按树形顺序，含 depth 层级）
 * @var array<int, array<string, mixed>> $parentOptions 可作为上级的顶级分类
 */

// 分类 ID => 名称，用于展示上级分类名称
$nameById = [];
foreach ($categories as $row) {
    $nameById[(int) $row['id']] = (string) $row['name'];
}

$selectedParent = (int) old('parent_id', '0');
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">分类管理</h2>
        <p class="admin-page-head__desc">
            共 <?= count($categories) ?> 个分类，支持两级分类树。分类下存在课程或子分类时无法删除，请先调整。
        </p>
    </div>
</div>

<div class="card">
    <div class="card__header">新增分类</div>
    <div class="card__body">
        <form class="admin-inline-form" method="post" action="<?= url('/admin/categories') ?>">
            <?= csrf_field() ?>
            <div class="admin-inline-form__field admin-inline-form__field--grow">
                <label class="form-label" for="new-name">分类名称<span class="required">*</span></label>
                <input class="input" id="new-name" type="text" name="name" maxlength="50"
                       value="<?= e((string) old('name')) ?>" placeholder="例如：前端开发" required>
            </div>
            <div class="admin-inline-form__field">
                <label class="form-label" for="new-parent">上级分类</label>
                <select class="select" id="new-parent" name="parent_id">
                    <option value="0">顶级分类</option>
                    <?php foreach ($parentOptions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"
                            <?= $selectedParent === (int) $option['id'] ? ' selected' : '' ?>>
                            <?= e((string) $option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-inline-form__field">
                <label class="form-label" for="new-sort">排序</label>
                <input class="input" id="new-sort" type="number" name="sort" step="1"
                       value="<?= e((string) old('sort', '0')) ?>">
            </div>
            <div class="admin-inline-form__actions">
                <button class="btn" type="submit">添加分类</button>
            </div>
        </form>
    </div>
</div>

<?php if ($categories === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">暂无分类，请在上方添加。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>序号</th>
                    <th>分类名称</th>
                    <th>上级分类</th>
                    <th>课程数</th>
                    <th>排序</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $index => $category): ?>
                    <?php
                    $categoryId = (int) $category['id'];
                    $depth      = (int) ($category['depth'] ?? 0);
                    $parentId   = (int) ($category['parent_id'] ?? 0);
                    ?>
                    <tr>
                        <td class="text-faint"><?= $index + 1 ?></td>
                        <td>
                            <?php if ($depth > 0): ?>
                                <span class="text-faint">└&nbsp;</span>
                            <?php endif; ?>
                            <?= e((string) $category['name']) ?>
                        </td>
                        <td class="text-faint">
                            <?= $parentId > 0 ? e($nameById[$parentId] ?? '—') : '顶级分类' ?>
                        </td>
                        <td class="text-faint"><?= (int) $category['course_count'] ?></td>
                        <td class="text-faint"><?= (int) $category['sort'] ?></td>
                        <td class="text-faint"><?= e((string) $category['created_at']) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm"
                                   href="<?= url('/admin/categories/' . $categoryId . '/edit') ?>">编辑</a>
                                <form method="post" action="<?= url('/admin/categories/' . $categoryId . '/delete') ?>"
                                      data-confirm="确定删除该分类吗？">
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
