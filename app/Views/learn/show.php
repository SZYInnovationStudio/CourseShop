<?php

declare(strict_types=1);

/**
 * 课程学习页
 *
 * @var array<string, mixed> $course 课程信息
 * @var array<int, array<string, mixed>> $chapters 章节列表
 * @var array<string, mixed> $chapter 当前章节
 * @var int $currentId 当前章节 ID
 * @var bool $hasAccess 是否已购买该课程
 * @var array<int, int> $previewIds 可试看章节 ID 列表
 * @var array<int, array<string, mixed>> $progress 各章节播放进度（按章节 ID 索引）
 * @var int $resume 上次播放位置（秒）
 * @var bool $hasVideo 当前章节是否有视频
 * @var string $videoUrl mp4 视频地址
 * @var string|null $hlsUrl HLS 视频地址（可空）
 */

use App\Models\PlayProgress;

$courseId     = (int) $course['id'];
$courseTitle  = (string) $course['title'];
$chapterTitle = (string) $chapter['title'];
$cover        = (string) ($course['cover'] ?? '');
$hasAccess    = (bool) $hasAccess;
$hasVideo     = (bool) $hasVideo;
$progressUrl  = url('/course/' . $courseId . '/learn/' . (int) $currentId . '/progress');
$videoUrl     = (string) ($videoUrl ?? '');
$hlsUrl       = (string) ($hlsUrl ?? '');

// 未启用 JS 时的初始播放源：优先 mp4（HLS 由脚本按需接管）
$initialSrc = $videoUrl !== '' ? $videoUrl : $hlsUrl;

// 上一个 / 下一个可学习章节
$playableIds = [];
foreach ($chapters as $item) {
    if ($hasAccess || in_array((int) $item['id'], $previewIds, true)) {
        $playableIds[] = (int) $item['id'];
    }
}
$position = array_search((int) $currentId, $playableIds, true);
$prevId   = $position !== false && $position > 0 ? $playableIds[$position - 1] : 0;
$nextId   = $position !== false && $position < count($playableIds) - 1 ? $playableIds[$position + 1] : 0;
?>
<div class="container">
    <nav class="breadcrumb" aria-label="面包屑导航">
        <a href="<?= url('/') ?>">首页</a>
        <span class="breadcrumb__sep">/</span>
        <a href="<?= url('/my/courses') ?>">我的课程</a>
        <span class="breadcrumb__sep">/</span>
        <a href="<?= url('/course/' . $courseId) ?>"><?= e($courseTitle) ?></a>
        <span class="breadcrumb__sep">/</span>
        <span><?= e($chapterTitle) ?></span>
    </nav>
</div>

<div class="container">
    <div class="learn">
        <div class="learn__main">
            <div class="player"
                 data-video-player
                 data-progress-url="<?= e($progressUrl) ?>"
                 data-resume="<?= (int) $resume ?>"
                 data-hls="<?= e($hlsUrl) ?>"
                 data-mp4="<?= e($videoUrl) ?>">
                <?php if ($hasVideo): ?>
                    <video class="player__video"
                           controls
                           preload="metadata"
                           playsinline
                           controlslist="nodownload"
                           <?php if ($cover !== ''): ?>poster="<?= e($cover) ?>"<?php endif; ?>
                           <?php if ($initialSrc !== ''): ?>src="<?= e($initialSrc) ?>"<?php endif; ?>></video>

                    <p class="player__status" data-video-status hidden>进度已记录</p>
                <?php else: ?>
                    <div class="player__empty">
                        <p>本章节暂时没有可播放的视频。</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="learn__head">
                <h1 class="learn__title"><?= e($chapterTitle) ?></h1>
                <div class="learn__actions">
                    <?php if ($prevId > 0): ?>
                        <a class="btn btn--outline btn--sm" href="<?= url('/course/' . $courseId . '/learn/' . $prevId) ?>">
                            &larr; 上一节
                        </a>
                    <?php endif; ?>
                    <?php if ($nextId > 0): ?>
                        <a class="btn btn--outline btn--sm" href="<?= url('/course/' . $courseId . '/learn/' . $nextId) ?>">
                            下一节 &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$hasAccess): ?>
                <div class="alert alert--info">
                    当前为试看章节，购买课程后可解锁全部内容。
                    <a href="<?= url('/course/' . $courseId) ?>">去看看课程</a>
                </div>
            <?php elseif ($resume > 0): ?>
                <p class="text-faint learn__resume">上次播放到 <?= format_duration((int) $resume) ?>，已为你自动续播。</p>
            <?php endif; ?>
        </div>

        <aside class="learn__aside">
            <div class="card">
                <div class="card__header flex-between">
                    <span>课程目录</span>
                    <span class="text-faint"><?= count($chapters) ?> 章</span>
                </div>

                <?php if ($chapters !== []): ?>
                    <ul class="chapter-list chapter-list--learn">
                        <?php foreach ($chapters as $index => $item): ?>
                            <?php
                            $itemId       = (int) $item['id'];
                            $isPreview    = in_array($itemId, $previewIds, true);
                            $canPlay      = $hasAccess || $isPreview;
                            $itemHasVideo = (bool) $item['has_video'];
                            $isCurrent    = $itemId === (int) $currentId;
                            $itemProgress = PlayProgress::toArray($progress[$itemId] ?? null);
                            $itemUrl      = url('/course/' . $courseId . '/learn/' . $itemId);
                            ?>
                            <li class="chapter-item<?= $isCurrent ? ' is-active' : '' ?>">
                                <span class="chapter-item__index">
                                    <?php if ((int) $itemProgress['finished'] === 1): ?>
                                        <span class="chapter-item__done" title="已学完">&#10003;</span>
                                    <?php else: ?>
                                        <?= $index + 1 ?>
                                    <?php endif; ?>
                                </span>

                                <?php if ($canPlay && $itemHasVideo): ?>
                                    <a class="chapter-item__title" href="<?= $itemUrl ?>"
                                       <?= $isCurrent ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
                                <?php else: ?>
                                    <span class="chapter-item__title text-muted"><?= e($item['title']) ?></span>
                                <?php endif; ?>

                                <?php if ($isPreview && !$hasAccess): ?>
                                    <span class="badge badge--primary">试看</span>
                                <?php elseif (!$canPlay): ?>
                                    <span class="badge">未解锁</span>
                                <?php endif; ?>

                                <span class="chapter-item__duration"><?= format_duration((int) $item['duration']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <p class="mb-0">课程目录正在整理中。</p>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
