<?php

declare(strict_types=1);

/**
 * 后台章节管理 - 列表 + 新增
 *
 * @var array<string, mixed> $course 所属课程记录
 * @var array<int, array<string, mixed>> $chapters 章节列表
 * @var array<int, array<string, mixed>> $transcodes 各章节的转码任务信息
 * @var bool $ffmpegAvailable 是否可用 ffmpeg
 * @var bool $hlsEnabled 是否启用 HLS
 */

$courseId        = (int) $course['id'];
$listUrl         = url('/admin/courses/' . $courseId . '/chapters');
$lastIndex       = count($chapters) - 1;
$transcodes      = $transcodes ?? [];
$ffmpegAvailable = $ffmpegAvailable ?? false;
$hlsEnabled      = $hlsEnabled ?? false;
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">章节管理</h2>
        <p class="admin-page-head__desc">
            课程：<?= e((string) $course['title']) ?>（ID：<?= $courseId ?>），共 <?= count($chapters) ?> 个章节。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/courses/' . $courseId . '/edit') ?>">编辑课程</a>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/courses') ?>">返回课程列表</a>
    </div>
</div>

<div class="card">
    <div class="card__header">新增章节</div>
    <div class="card__body">
        <form method="post" action="<?= $listUrl ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label" for="chapter-title">章节名称<span class="required">*</span></label>
                <input class="input" id="chapter-title" type="text" name="title" maxlength="150"
                       value="<?= e((string) old('title')) ?>" placeholder="例如：01 环境搭建与项目初始化" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="chapter-video">章节视频</label>
                <input class="input" id="chapter-video" type="file" name="video"
                       accept="video/mp4,video/x-m4v,video/webm,video/quicktime">
                <p class="form-hint">
                    支持 mp4 / m4v / webm / mov，单个文件最大
                    <?= (int) config('upload.max_video_mb', 2048) ?> MB，可稍后在编辑页替换。
                </p>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="chapter-duration">视频时长（秒）</label>
                    <input class="input" id="chapter-duration" type="number" name="duration" min="0" step="1"
                           value="<?= e((string) old('duration', '0')) ?>">
                    <p class="form-hint">留空表示未知，页面会显示 00:00。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="chapter-sort">排序权重</label>
                    <input class="input" id="chapter-sort" type="number" name="sort" step="1"
                           value="<?= e((string) old('sort', '0')) ?>">
                    <p class="form-hint">数值越小越靠前，也可在列表中上移 / 下移。</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="chapter-preview">试看</label>
                    <select class="select" id="chapter-preview" name="is_preview">
                        <option value="0"<?= old('is_preview', '0') === '1' ? '' : ' selected' ?>>否</option>
                        <option value="1"<?= old('is_preview', '0') === '1' ? ' selected' : '' ?>>是（未购买也可观看）</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="chapter-status">状态</label>
                    <select class="select" id="chapter-status" name="status">
                        <option value="1"<?= old('status', '1') === '0' ? '' : ' selected' ?>>显示</option>
                        <option value="0"<?= old('status', '1') === '0' ? ' selected' : '' ?>>隐藏</option>
                    </select>
                </div>
            </div>

            <div class="btn-group mb-0">
                <button class="btn" type="submit">添加章节</button>
            </div>
        </form>
    </div>
</div>

<?php if ($chapters === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">该课程还没有章节，请在上方添加。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>章节名称</th>
                    <th>试看</th>
                    <th>视频</th>
                    <th>HLS</th>
                    <th>时长</th>
                    <th>大小</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($chapters as $index => $chapter): ?>
                    <?php
                    $chapterId = (int) $chapter['id'];
                    $hasVideo  = (int) $chapter['has_video'] === 1;
                    $editUrl   = url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/edit');
                    $transcode = $transcodes[$chapterId] ?? null;
                    ?>
                    <tr>
                        <td class="text-faint"><?= $index + 1 ?></td>
                        <td><?= e((string) $chapter['title']) ?></td>
                        <td>
                            <?php if ((int) $chapter['is_preview'] === 1): ?>
                                <span class="badge badge--success">可试看</span>
                            <?php else: ?>
                                <span class="text-faint">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasVideo): ?>
                                <span class="badge badge--success">已上传</span>
                            <?php else: ?>
                                <span class="badge badge--warning">未上传</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($transcode === null): ?>
                                <span class="text-faint">—</span>
                            <?php elseif ($transcode['status'] === 'success'): ?>
                                <span class="badge badge--success">已完成</span>
                            <?php elseif ($transcode['status'] === 'failed'): ?>
                                <span class="badge badge--danger">失败</span>
                            <?php elseif ($transcode['status'] === 'running'): ?>
                                <span class="badge badge--primary">转码中 <?= (int) $transcode['progress'] ?>%</span>
                            <?php else: ?>
                                <span class="badge badge--info">等待中</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-faint"><?= e(format_duration((int) $chapter['duration'])) ?></td>
                        <td class="text-faint"><?= (int) $chapter['file_size'] > 0 ? e(format_bytes((int) $chapter['file_size'])) : '—' ?></td>
                        <td>
                            <?php if ((int) $chapter['status'] === 1): ?>
                                <span class="badge">显示</span>
                            <?php else: ?>
                                <span class="badge badge--warning">隐藏</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-actions">
                                <form method="post" action="<?= url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/move') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="direction" value="up">
                                    <button class="btn btn--ghost btn--sm" type="submit" aria-label="上移"
                                        <?= $index === 0 ? ' disabled' : '' ?>>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M12 19V5"></path><path d="M5 12l7-7 7 7"></path>
                                        </svg>
                                    </button>
                                </form>
                                <form method="post" action="<?= url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/move') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="direction" value="down">
                                    <button class="btn btn--ghost btn--sm" type="submit" aria-label="下移"
                                        <?= $index === $lastIndex ? ' disabled' : '' ?>>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M12 5v14"></path><path d="M19 12l-7 7-7-7"></path>
                                        </svg>
                                    </button>
                                </form>

                                <a class="btn btn--outline btn--sm" href="<?= $editUrl ?>">编辑</a>

                                <form method="post" action="<?= url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/delete') ?>"
                                      data-confirm="确定删除该章节吗？章节视频文件也会一并删除。">
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
