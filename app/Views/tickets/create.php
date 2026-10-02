<?php

declare(strict_types=1);

/**
 * 提交工单
 *
 * @var array<string, string> $categories 问题分类选项（标识 => 名称）
 * @var int $maxFiles 附件数量上限
 * @var int $maxMb 单个附件大小上限（MB）
 * @var array<int, string> $allowedTypes 允许上传的附件扩展名
 */
?>
<div class="page-head container">
    <h1 class="page-head__title">提交工单</h1>
    <p class="page-head__desc">请尽量描述清楚你的问题，附上相关截图或文件有助于更快解决。</p>
</div>

<div class="container">
    <div class="card">
        <div class="card__body">
            <form method="post" action="<?= url('/ticket') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="category">问题分类<span class="required">*</span></label>
                    <select class="select" id="category" name="category">
                        <?php foreach ($categories as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= old('category', 'other') === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="title">标题<span class="required">*</span></label>
                    <input class="input" type="text" id="title" name="title" maxlength="150"
                           value="<?= e(old('title', '')) ?>" placeholder="一句话概括你的问题">
                </div>

                <div class="form-group">
                    <label class="form-label" for="content">问题描述<span class="required">*</span></label>
                    <textarea class="textarea" id="content" name="content" rows="8" maxlength="5000"
                              placeholder="请描述问题出现的场景、操作步骤与期望结果"><?= e(old('content', '')) ?></textarea>
                    <p class="form-hint">最多 5000 个字符。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="attachments">附件（选填）</label>
                    <input class="input" type="file" id="attachments" name="attachments[]" multiple>
                    <p class="form-hint">
                        最多 <?= (int) $maxFiles ?> 个文件，单个不超过 <?= (int) $maxMb ?> MB，
                        支持：<?= e(implode('、', $allowedTypes)) ?>。
                    </p>
                </div>

                <div class="btn-group">
                    <button class="btn" type="submit">提交工单</button>
                    <a class="btn btn--ghost" href="<?= url('/tickets') ?>">返回列表</a>
                </div>
            </form>
        </div>
    </div>
</div>
