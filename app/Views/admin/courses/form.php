<?php

declare(strict_types=1);

/**
 * 后台课程管理 - 新增 / 编辑表单
 *
 * @var array<string, mixed>|null $course 课程记录（新增时为 null）
 * @var array<int, array<string, mixed>> $categories 课程分类列表
 * @var array<int, array<string, mixed>> $tags 标签列表
 * @var array<int, int> $selectedTagIds 已选标签 ID 列表
 * @var array<string, string> $statuses 课程状态选项
 */

$isEdit   = $course !== null;
$courseId = $isEdit ? (int) $course['id'] : 0;
$action   = $isEdit ? url('/admin/courses/' . $courseId) : url('/admin/courses');

$title    = (string) old('title', $isEdit ? (string) $course['title'] : '');
$subtitle = (string) old('subtitle', $isEdit ? (string) $course['subtitle'] : '');
$summary  = (string) old('summary', $isEdit ? (string) $course['summary'] : '');
$content  = (string) old('content', $isEdit ? (string) ($course['content'] ?? '') : '');
$cover    = (string) old('cover', $isEdit ? (string) ($course['cover'] ?? '') : '');

$categoryId = (int) old('category_id', $isEdit ? (int) ($course['category_id'] ?? 0) : 0);

$price    = (string) old('price', $isEdit ? format_money((int) $course['price']) : '0');
$original = (string) old('original_price', $isEdit ? format_money((int) $course['original_price']) : '0');

$previewEnabled = (string) old('preview_enabled', $isEdit ? (string) (int) $course['preview_enabled'] : '0');
$previewCount   = (string) old('preview_chapter_count', $isEdit ? (string) (int) $course['preview_chapter_count'] : '0');
$status         = (string) old('status', $isEdit ? (string) $course['status'] : 'draft');
$sort           = (string) old('sort', $isEdit ? (string) (int) $course['sort'] : '0');
$newTags        = (string) old('new_tags', '');

$selectedTags = old('tag_ids', $selectedTagIds);
$selectedTags = is_array($selectedTags) ? array_map('intval', $selectedTags) : [];
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title"><?= $isEdit ? '编辑课程' : '新增课程' ?></h2>
        <p class="admin-page-head__desc">
            <?php if ($isEdit): ?>
                课程 ID：<?= $courseId ?>，创建于 <?= e((string) $course['created_at']) ?>。
            <?php else: ?>
                填写课程基本信息后保存，再前往章节管理上传视频。
            <?php endif; ?>
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/courses') ?>">返回列表</a>
    </div>
</div>

<form method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">基本信息</div>
        <div class="card__body">
            <div class="form-group">
                <label class="form-label" for="title">课程名称<span class="required">*</span></label>
                <input class="input" id="title" type="text" name="title" maxlength="150"
                       value="<?= e($title) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="subtitle">副标题</label>
                <input class="input" id="subtitle" type="text" name="subtitle" maxlength="255"
                       value="<?= e($subtitle) ?>" placeholder="一句话概括课程亮点">
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="category_id">课程分类</label>
                    <select class="select" id="category_id" name="category_id">
                        <option value="0">未分类</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"
                                <?= $categoryId === (int) $category['id'] ? ' selected' : '' ?>>
                                <?= (int) ($category['depth'] ?? 0) > 0 ? '— ' : '' ?><?= e((string) $category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">分类可在「分类管理」中维护。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cover">预览图</label>
                    <div class="image-field">
                        <input class="input" id="cover" type="text" name="cover" maxlength="255"
                               value="<?= e($cover) ?>" placeholder="填写图片地址，或选择本地图片上传">
                        <input class="input image-field__file" type="file" name="cover_file"
                               accept="image/jpeg,image/png,image/gif,image/webp">
                        <?php if ($cover !== ''): ?>
                            <div class="image-field__preview">
                                <img src="<?= e(url($cover)) ?>" alt="预览图">
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="form-hint">留空则使用第一章节视频首帧。</p>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">标签</label>
                <?php if ($tags === []): ?>
                    <p class="form-hint mb-0">暂无标签，可在下方「新增标签」中直接输入创建。</p>
                <?php else: ?>
                    <div class="tag-checkboxes">
                        <?php foreach ($tags as $tag): ?>
                            <label class="tag-checkbox">
                                <input type="checkbox" name="tag_ids[]" value="<?= (int) $tag['id'] ?>"
                                    <?= in_array((int) $tag['id'], $selectedTags, true) ? ' checked' : '' ?>>
                                <span><?= e((string) $tag['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="new_tags">新增标签</label>
                <input class="input" id="new_tags" type="text" name="new_tags"
                       value="<?= e($newTags) ?>" placeholder="多个标签用逗号分隔，例如：前端, 入门">
            </div>

            <div class="form-group mb-0">
                <label class="form-label" for="summary">课程简介</label>
                <textarea class="textarea" id="summary" name="summary" maxlength="500"
                          placeholder="用于课程列表与详情页展示，建议 100 字以内"><?= e($summary) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">价格与试看</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="price">售价（元）<span class="required">*</span></label>
                    <input class="input" id="price" type="number" name="price" min="0" step="0.01"
                           value="<?= e($price) ?>" required>
                    <p class="form-hint">填写 0 表示免费课程。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="original_price">划线价（元）</label>
                    <input class="input" id="original_price" type="number" name="original_price" min="0" step="0.01"
                           value="<?= e($original) ?>">
                    <p class="form-hint">填写 0 表示不展示划线价。</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="preview_enabled">允许试看</label>
                    <select class="select" id="preview_enabled" name="preview_enabled">
                        <option value="0"<?= $previewEnabled === '0' ? ' selected' : '' ?>>关闭</option>
                        <option value="1"<?= $previewEnabled === '1' ? ' selected' : '' ?>>开启</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="preview_chapter_count">试看章节数</label>
                    <input class="input" id="preview_chapter_count" type="number" name="preview_chapter_count"
                           min="0" step="1" value="<?= e($previewCount) ?>">
                    <p class="form-hint">开启试看时，前 N 节可免费观看；勾选「试看章节」的章节始终可试看。</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="status">课程状态</label>
                    <select class="select" id="status" name="status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sort">排序权重</label>
                    <input class="input" id="sort" type="number" name="sort" step="1" value="<?= e($sort) ?>">
                    <p class="form-hint">数值越大越靠前。</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">课程详情</div>
        <div class="card__body">
            <div class="form-group mb-0">
                <label class="form-label" for="content">详细介绍（Markdown）</label>
                <textarea class="textarea textarea--tall" id="content" name="content"
                          placeholder="支持 Markdown 语法，用于详情页展示"><?= e($content) ?></textarea>
            </div>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit"><?= $isEdit ? '保存修改' : '创建课程' ?></button>
        <a class="btn btn--ghost" href="<?= url('/admin/courses') ?>">取消</a>
    </div>
</form>
