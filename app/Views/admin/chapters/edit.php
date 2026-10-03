<?php

declare(strict_types=1);

/**
 * 后台章节管理 - 编辑
 *
 * @var array<string, mixed> $course 所属课程记录
 * @var array<string, mixed> $chapter 章节记录
 * @var bool $hasVideo 是否已上传视频
 * @var int $videoSize 视频文件大小（字节）
 * @var array<string, mixed>|null $transcode 转码任务信息
 * @var bool $ffmpegAvailable 是否可用 ffmpeg
 * @var bool $hlsEnabled 是否启用 HLS
 */

$courseId  = (int) $course['id'];
$chapterId = (int) $chapter['id'];
$listUrl   = url('/admin/courses/' . $courseId . '/chapters');
$action    = url('/admin/courses/' . $courseId . '/chapters/' . $chapterId);

$transcode       = $transcode ?? null;
$ffmpegAvailable = $ffmpegAvailable ?? false;
$hlsEnabled      = $hlsEnabled ?? false;

$transcodeStatus = is_array($transcode) ? (string) $transcode['status'] : '';
$transcodeBusy   = in_array($transcodeStatus, ['pending', 'running'], true);

$title     = (string) old('title', (string) $chapter['title']);
$duration  = (string) old('duration', (string) (int) $chapter['duration']);
$sort      = (string) old('sort', (string) (int) $chapter['sort']);
$isPreview = old('is_preview', (string) (int) $chapter['is_preview']);
$status    = (string) old('status', (string) (int) $chapter['status']);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">编辑章节</h2>
        <p class="admin-page-head__desc">
            所属课程：<?= e((string) $course['title']) ?>（ID：<?= $courseId ?>），章节 ID：<?= $chapterId ?>。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= $listUrl ?>">返回章节列表</a>
    </div>
</div>

<form method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">章节信息</div>
        <div class="card__body">
            <div class="form-group">
                <label class="form-label" for="title">章节名称<span class="required">*</span></label>
                <input class="input" id="title" type="text" name="title" maxlength="150"
                       value="<?= e($title) ?>" required>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="duration">视频时长（秒）</label>
                    <input class="input" id="duration" type="number" name="duration" min="0" step="1"
                           value="<?= e($duration) ?>">
                    <p class="form-hint">留空表示未知，页面会显示 00:00。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sort">排序权重</label>
                    <input class="input" id="sort" type="number" name="sort" step="1" value="<?= e($sort) ?>">
                    <p class="form-hint">数值越小越靠前。</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="is_preview">试看</label>
                    <select class="select" id="is_preview" name="is_preview">
                        <option value="0"<?= (string) $isPreview === '1' ? '' : ' selected' ?>>否</option>
                        <option value="1"<?= (string) $isPreview === '1' ? ' selected' : '' ?>>是（未购买也可观看）</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="status">状态</label>
                    <select class="select" id="status" name="status">
                        <option value="1"<?= $status === '0' ? '' : ' selected' ?>>显示</option>
                        <option value="0"<?= $status === '0' ? ' selected' : '' ?>>隐藏</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">章节视频</div>
        <div class="card__body">
            <div class="form-group">
                <label class="form-label">当前视频</label>
                <?php if ($hasVideo): ?>
                    <p class="form-hint mb-0">
                        已上传，文件大小 <?= e(format_bytes((int) $videoSize)) ?>。
                        如需删除请使用下方「删除视频」按钮。
                    </p>
                <?php else: ?>
                    <p class="form-hint mb-0">尚未上传视频。</p>
                <?php endif; ?>
            </div>

            <div class="form-group mb-0">
                <label class="form-label" for="video"><?= $hasVideo ? '替换视频' : '上传视频' ?></label>
                <input class="input" id="video" type="file" name="video"
                       accept="video/mp4,video/x-m4v,video/webm,video/quicktime">
                <p class="form-hint">
                    选择新文件即替换原视频；支持 mp4 / m4v / webm / mov，单个文件最大
                    <?= (int) config('upload.max_video_mb', 2048) ?> MB。
                </p>
            </div>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit">保存修改</button>
        <a class="btn btn--ghost" href="<?= $listUrl ?>">取消</a>
    </div>
</form>

<div class="card">
    <div class="card__header">视频转码（HLS）</div>
    <div class="card__body">
        <?php if (!$hlsEnabled): ?>
            <p class="form-hint mb-0">HLS 转码已在「系统设置 - 视频设置」中关闭，播放将使用 mp4 兜底。</p>
        <?php elseif (!$ffmpegAvailable): ?>
            <p class="form-hint mb-0">服务器未检测到 ffmpeg，无法执行 HLS 转码，播放将使用 mp4 兜底。</p>
        <?php elseif (!$hasVideo): ?>
            <p class="form-hint mb-0">请先上传章节视频后再提交转码。</p>
        <?php else: ?>
            <div class="form-group">
                <label class="form-label">当前状态</label>
                <?php if ($transcode === null): ?>
                    <p class="form-hint mb-0">尚未提交转码，播放使用 mp4。</p>
                <?php elseif ($transcodeStatus === 'success'): ?>
                    <p class="mb-0"><span class="badge badge--success">已完成</span>
                        <span class="text-faint">HLS 已就绪，播放器将自动切换为流式播放。</span>
                    </p>
                <?php elseif ($transcodeStatus === 'failed'): ?>
                    <p class="mb-0"><span class="badge badge--danger">失败</span></p>
                    <?php if (trim((string) $transcode['error']) !== ''): ?>
                        <p class="form-hint"><?= e((string) $transcode['error']) ?></p>
                    <?php endif; ?>
                <?php elseif ($transcodeStatus === 'running'): ?>
                    <p class="mb-0"><span class="badge badge--primary">转码中 <?= (int) $transcode['progress'] ?>%</span></p>
                <?php else: ?>
                    <p class="mb-0"><span class="badge badge--info">等待中</span>
                        <span class="text-faint">
                            队列消费者将尽快处理<?= $queueAutoRun ? '（已启用自动运行）' : '（自动运行不可用，请按 README 配置消费者或计划任务）' ?>。
                        </span>
                    </p>
                <?php endif; ?>
            </div>

            <?php if (!$transcodeBusy): ?>
                <div class="btn-group mb-0">
                    <form method="post" action="<?= url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/transcode') ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn--outline btn--sm" type="submit">
                            <?= $transcodeStatus === 'success' ? '重新转码' : '提交转码' ?>
                        </button>
                    </form>
                </div>
                <p class="form-hint mb-0">
                    提交后由队列异步执行<?= $queueAutoRun ? '（已启用自动运行，无需额外配置）' : '，请确保已运行 <code>php bin/queue-worker.php</code>' ?>。
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card--flat">
    <div class="card__body">
        <div class="admin-actions">
            <?php if ($hasVideo): ?>
                <form method="post" action="<?= url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/video/delete') ?>"
                      data-confirm="确定删除该章节的视频吗？章节信息会保留。">
                    <?= csrf_field() ?>
                    <button class="btn btn--danger btn--sm" type="submit">删除视频</button>
                </form>
            <?php endif; ?>

            <form method="post" action="<?= url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/delete') ?>"
                  data-confirm="确定删除该章节吗？章节视频文件也会一并删除。">
                <?= csrf_field() ?>
                <button class="btn btn--danger btn--sm" type="submit">删除章节</button>
            </form>
        </div>
    </div>
</div>
