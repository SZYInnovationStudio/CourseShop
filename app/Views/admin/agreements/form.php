<?php

declare(strict_types=1);

/**
 * 后台协议管理 - 编辑
 *
 * @var string $type 协议类型标识
 * @var string $label 协议名称
 * @var array<string, mixed>|null $current 当前生效版本，可能为 null
 * @var array<int, array<string, mixed>> $history 历史版本列表
 */

$current = $current ?? null;

$version = (string) old('version', $current !== null ? (string) $current['version'] : '1.0.0');
$title   = (string) old('title', $current !== null ? (string) $current['title'] : $label);
$content = (string) old('content', $current !== null ? (string) ($current['content'] ?? '') : '');

$effectiveRaw  = $current !== null ? (string) ($current['effective_at'] ?? '') : '';
$effectiveTmp  = $effectiveRaw !== '' ? strtotime($effectiveRaw) : false;
$effectiveDate = (string) old(
    'effective_date',
    $effectiveTmp !== false ? date('Y-m-d', $effectiveTmp) : date('Y-m-d')
);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">编辑<?= e($label) ?></h2>
        <p class="admin-page-head__desc">
            正文支持 Markdown 语法。修改版本号将生成新版本并自动存档旧版本；若版本号不变则直接更新当前版本。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/agreements') ?>">返回列表</a>
        <a class="btn btn--ghost btn--sm" href="<?= url('/agreements/' . $type) ?>" target="_blank" rel="noopener">前台查看</a>
    </div>
</div>

<form method="post" action="<?= url('/admin/agreements/' . $type) ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">协议内容</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="title">协议标题<span class="required">*</span></label>
                    <input class="input" id="title" type="text" name="title" maxlength="150"
                           value="<?= e($title) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="version">版本号<span class="required">*</span></label>
                    <input class="input" id="version" type="text" name="version" maxlength="20"
                           value="<?= e($version) ?>" placeholder="例如 1.0.0" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="effective_date">生效日期</label>
                    <input class="input" id="effective_date" type="date" name="effective_date"
                           value="<?= e($effectiveDate) ?>">
                    <p class="form-hint">留空表示不指定生效日期。</p>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="content">协议正文（Markdown）</label>
                <textarea class="textarea textarea--tall" id="content" name="content" rows="18"
                          placeholder="## 一、总则&#10;&#10;正文内容……"><?= e($content) ?></textarea>
            </div>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit">保存并生效</button>
        <a class="btn btn--ghost" href="<?= url('/admin/agreements') ?>">取消</a>
    </div>
</form>

<?php if ($history !== []): ?>
    <div class="card">
        <div class="card__header">版本历史</div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>版本</th>
                    <th>标题</th>
                    <th>生效日期</th>
                    <th>状态</th>
                    <th>创建时间</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $record): ?>
                    <tr>
                        <td>v<?= e((string) $record['version']) ?></td>
                        <td><?= e((string) $record['title']) ?></td>
                        <td class="text-faint"><?= e((string) ($record['effective_at'] ?? '—')) ?></td>
                        <td>
                            <?php if ((int) $record['is_current'] === 1): ?>
                                <span class="badge badge--success">当前生效</span>
                            <?php else: ?>
                                <span class="badge">历史版本</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-faint"><?= e((string) $record['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
