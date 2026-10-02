<?php

declare(strict_types=1);

/**
 * 后台公告管理 - 新增 / 编辑表单
 *
 * @var bool $isEdit 是否为编辑模式
 * @var array<string, mixed>|null $announcement 编辑时的公告记录，新增时为 null
 */

$announcementId = $isEdit ? (int) ($announcement['id'] ?? 0) : 0;
$action         = $isEdit
    ? url('/admin/announcements/' . $announcementId)
    : url('/admin/announcements');

$title   = (string) old('title', $isEdit ? (string) ($announcement['title'] ?? '') : '');
$content = (string) old('content', $isEdit ? (string) ($announcement['content'] ?? '') : '');

$publishedRaw = $isEdit ? (string) ($announcement['published_at'] ?? '') : '';
$publishedTmp = $publishedRaw !== '' ? strtotime($publishedRaw) : false;
$published    = (string) old('published_at', $publishedTmp !== false ? date('Y-m-d\TH:i', $publishedTmp) : '');

$activeOld = old('is_active', $isEdit ? (string) (int) ($announcement['is_active'] ?? 0) : '1');
$isActive  = in_array((string) $activeOld, ['1', 'on', 'true', 'yes'], true);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title"><?= $isEdit ? '编辑公告' : '新增公告' ?></h2>
        <p class="admin-page-head__desc">
            正文支持 Markdown 语法（标题、列表、加粗、链接、引用、代码块等），保存后前台按排版渲染。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/announcements') ?>">返回列表</a>
    </div>
</div>

<form method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">公告内容</div>
        <div class="card__body">
            <div class="form-group">
                <label class="form-label" for="title">公告标题<span class="required">*</span></label>
                <input class="input" id="title" type="text" name="title" maxlength="150"
                       value="<?= e($title) ?>" placeholder="例如：平台维护通知" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="content">公告正文（Markdown）</label>
                <textarea class="textarea textarea--tall" id="content" name="content" rows="10"
                          placeholder="支持 Markdown 语法，例如：&#10;## 小标题&#10;- 列表项&#10;**加粗**"><?= e($content) ?></textarea>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="published_at">发布时间</label>
                    <input class="input" id="published_at" type="datetime-local" name="published_at"
                           value="<?= e($published) ?>">
                    <p class="form-hint">留空表示立即发布（取当前时间）。</p>
                </div>

                <div class="form-group">
                    <span class="form-label">状态</span>
                    <label class="checkbox" for="is_active">
                        <input type="checkbox" id="is_active" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
                        <span>启用并在前台展示</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit"><?= $isEdit ? '保存修改' : '发布公告' ?></button>
        <a class="btn btn--ghost" href="<?= url('/admin/announcements') ?>">取消</a>
    </div>
</form>
