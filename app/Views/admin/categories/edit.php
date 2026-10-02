<?php

declare(strict_types=1);

/**
 * 后台分类管理 - 编辑
 *
 * @var array<string, mixed> $category 分类记录
 * @var int $courseCount 该分类关联的课程数
 * @var array<int, array<string, mixed>> $parentOptions 可作为上级的顶级分类
 */

$categoryId = (int) $category['id'];
$name       = (string) old('name', (string) $category['name']);
$sort       = (string) old('sort', (string) (int) $category['sort']);
$parentId   = (int) old('parent_id', (string) (int) $category['parent_id']);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">编辑分类</h2>
        <p class="admin-page-head__desc">
            分类 ID：<?= $categoryId ?>，关联课程 <?= (int) $courseCount ?> 门。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/categories') ?>">返回列表</a>
    </div>
</div>

<form method="post" action="<?= url('/admin/categories/' . $categoryId) ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">分类信息</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="name">分类名称<span class="required">*</span></label>
                    <input class="input" id="name" type="text" name="name" maxlength="50"
                           value="<?= e($name) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="parent_id">上级分类</label>
                    <select class="select" id="parent_id" name="parent_id">
                        <option value="0"<?= $parentId === 0 ? ' selected' : '' ?>>顶级分类</option>
                        <?php foreach ($parentOptions as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"
                                <?= $parentId === (int) $option['id'] ? ' selected' : '' ?>>
                                <?= e((string) $option['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">系统最多支持两级分类，上级只能选择顶级分类。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sort">排序</label>
                    <input class="input" id="sort" type="number" name="sort" step="1" value="<?= e($sort) ?>">
                    <p class="form-hint">数值越小越靠前。</p>
                </div>
            </div>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit">保存修改</button>
        <a class="btn btn--ghost" href="<?= url('/admin/categories') ?>">取消</a>
    </div>
</form>
