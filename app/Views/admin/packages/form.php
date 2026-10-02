<?php

declare(strict_types=1);

/**
 * 后台套餐管理 - 新增 / 编辑表单
 *
 * @var bool $isEdit 是否为编辑模式
 * @var array<string, mixed>|null $package 套餐记录（新增时为 null）
 * @var array<int, array<string, mixed>> $courses 可选择的课程列表
 * @var array<int, int> $selectedIds 已绑定课程 ID 列表
 */

use App\Models\Package;

$packageId = $isEdit ? (int) ($package['id'] ?? 0) : 0;
$action    = $isEdit
    ? url('/admin/packages/' . $packageId)
    : url('/admin/packages');

$title    = (string) old('title', $isEdit ? (string) ($package['title'] ?? '') : '');
$subtitle = (string) old('subtitle', $isEdit ? (string) ($package['subtitle'] ?? '') : '');
$summary  = (string) old('summary', $isEdit ? (string) ($package['summary'] ?? '') : '');
$content  = (string) old('content', $isEdit ? (string) ($package['content'] ?? '') : '');
$cover    = (string) old('cover', $isEdit ? (string) ($package['cover'] ?? '') : '');

$priceDefault = $isEdit ? format_money((int) $package['price']) : '0';
$price        = (string) old('price', $priceDefault);

$originalPriceDefault = $isEdit ? format_money((int) $package['original_price']) : '0';
$originalPrice        = (string) old('original_price', $originalPriceDefault);

$status  = (string) old('status', $isEdit ? (string) ($package['status'] ?? 'draft') : 'draft');
$sort    = (string) old('sort', $isEdit ? (string) (int) $package['sort'] : '0');

$recommendOld = old('is_recommend', $isEdit ? (string) (int) $package['is_recommend'] : '0');
$isRecommend  = in_array((string) $recommendOld, ['1', 'on', 'true', 'yes'], true);

// 课程多选：编辑时优先使用上次提交（回填），否则使用当前已绑定课程
$checkedRaw = old('courses');
if ($checkedRaw !== null) {
    $checkedIds = array_map('intval', (array) $checkedRaw);
} else {
    $checkedIds = array_map('intval', (array) $selectedIds);
}
$checkedIds = array_flip($checkedIds);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title"><?= $isEdit ? '编辑套餐' : '新增套餐' ?></h2>
        <p class="admin-page-head__desc">
            选择套餐包含的课程并设置套餐价。用户购买套餐后，系统会一次性开通套餐内全部课程。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/packages') ?>">返回列表</a>
    </div>
</div>

<form method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">基本信息</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="title">套餐名称<span class="required">*</span></label>
                    <input class="input" id="title" type="text" name="title" maxlength="150"
                           value="<?= e($title) ?>" placeholder="例如：前端工程师成长套餐" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="subtitle">副标题</label>
                    <input class="input" id="subtitle" type="text" name="subtitle" maxlength="255"
                           value="<?= e($subtitle) ?>" placeholder="一句话卖点">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="summary">简介</label>
                <textarea class="textarea" id="summary" name="summary" rows="2" maxlength="500"
                          placeholder="套餐简介，展示在列表卡片中"><?= e($summary) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="cover">封面图</label>
                <div class="image-field">
                    <input class="input" id="cover" type="text" name="cover" maxlength="255"
                           value="<?= e($cover) ?>" placeholder="填写图片地址，或选择本地图片上传">
                    <input class="input image-field__file" type="file" name="cover_file"
                           accept="image/jpeg,image/png,image/gif,image/webp">
                    <?php if ($cover !== ''): ?>
                        <div class="image-field__preview">
                            <img src="<?= e(url($cover)) ?>" alt="封面图">
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="content">套餐详情</label>
                <textarea class="textarea" id="content" name="content" rows="8"
                          placeholder="支持 Markdown 语法"><?= e($content) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">售卖设置</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="price">套餐售价（元）<span class="required">*</span></label>
                    <input class="input" id="price" type="text" name="price" inputmode="decimal"
                           value="<?= e($price) ?>" placeholder="0 表示免费" required>
                    <p class="form-hint">填 0 表示免费套餐，用户下单后直接开通。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="original_price">划线价（元）</label>
                    <input class="input" id="original_price" type="text" name="original_price" inputmode="decimal"
                           value="<?= e($originalPrice) ?>" placeholder="0 表示不展示">
                    <p class="form-hint">用于展示「原价」，需不低于套餐售价，0 表示不展示。</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="status">状态</label>
                    <select class="select" id="status" name="status">
                        <?php foreach (Package::STATUSES as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">仅「已上架」的套餐在前台可见并可购买。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sort">排序权重</label>
                    <input class="input" id="sort" type="number" name="sort" value="<?= e($sort) ?>">
                    <p class="form-hint">数值越大越靠前。</p>
                </div>
            </div>

            <div class="form-group">
                <label class="checkbox" for="is_recommend">
                    <input type="checkbox" id="is_recommend" name="is_recommend" value="1"<?= $isRecommend ? ' checked' : '' ?>>
                    <span>首页推荐（勾选后展示在首页推荐套餐区）</span>
                </label>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">包含课程</div>
        <div class="card__body">
            <?php if ($courses === []): ?>
                <p class="text-faint mb-0">暂无可用课程，请先在「课程管理」中创建课程。</p>
            <?php else: ?>
                <div style="max-height:320px;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px">
                    <?php foreach ($courses as $course): ?>
                        <?php $courseId = (int) $course['id']; ?>
                        <label class="checkbox">
                            <input type="checkbox" name="courses[]" value="<?= $courseId ?>"
                                <?= isset($checkedIds[$courseId]) ? 'checked' : '' ?>>
                            <span><?= e((string) $course['title']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint">至少选择 1 门课程，套餐才会在前台可购买。</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit"><?= $isEdit ? '保存修改' : '创建套餐' ?></button>
        <a class="btn btn--ghost" href="<?= url('/admin/packages') ?>">取消</a>
    </div>
</form>
