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

// 自动播放下一节所需的地址与标题（无可播放的下一节时为空）
$nextUrl   = '';
$nextTitle = '';

if ($nextId > 0) {
    foreach ($chapters as $item) {
        if ((int) $item['id'] === $nextId) {
            $nextTitle = (string) $item['title'];
            break;
        }
    }

    $nextUrl = url('/course/' . $courseId . '/learn/' . $nextId);
}

// 由「播完自动下一节」跳转而来时尝试自动播放
$autoPlayNext  = (string) ($_GET['autoplay'] ?? '') === '1';
$nextUrlAuto   = $nextUrl === '' ? '' : $nextUrl . '?autoplay=1';
?>
<div class="container">
    <nav class="breadcrumb" aria-label="<?= e(t('面包屑导航')) ?>">
        <a href="<?= url('/') ?>"><?= e(t('首页')) ?></a>
        <span class="breadcrumb__sep">/</span>
        <a href="<?= url('/my/courses') ?>"><?= e(t('我的课程')) ?></a>
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
                 data-mp4="<?= e($videoUrl) ?>"
                 data-next-url="<?= e($nextUrl) ?>"
                 data-autoplay="<?= $autoPlayNext ? '1' : '0' ?>">
                <?php if ($hasVideo): ?>
                    <?php /* controls 为无 JS 兜底：脚本初始化成功后会移除原生控件，改为自绘控件条 */ ?>
                    <video class="player__video"
                           controls
                           preload="metadata"
                           playsinline
                           webkit-playsinline
                           x5-playsinline="true"
                           x5-video-player-type="h5"
                           tabindex="0"
                           controlslist="nodownload"
                           <?php if ($cover !== ''): ?>poster="<?= e($cover) ?>"<?php endif; ?>
                           <?php if ($initialSrc !== ''): ?>src="<?= e($initialSrc) ?>"<?php endif; ?>></video>

                    <div class="player__spinner" data-player-spinner hidden aria-hidden="true"></div>

                    <button type="button" class="player__bigplay" data-player-bigplay
                            aria-hidden="true" tabindex="-1">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 5.2v13.6L19.4 12z" fill="currentColor"></path></svg>
                    </button>

                    <div class="player__controls" data-player-controls>
                        <button type="button" class="player__ctrl" data-player-play
                                aria-label="<?= e(t('播放')) ?>"
                                data-label-play="<?= e(t('播放')) ?>"
                                data-label-pause="<?= e(t('暂停')) ?>">
                            <svg class="player__icon player__icon--play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 5.2v13.6L19.4 12z" fill="currentColor"></path></svg>
                            <svg class="player__icon player__icon--pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 5h3.4v14H7zM13.6 5H17v14h-3.4z" fill="currentColor"></path></svg>
                        </button>

                        <span class="player__time" data-player-current>0:00</span>

                        <input class="player__range" type="range" min="0" max="1000" step="1" value="0"
                               data-player-seek aria-label="<?= e(t('播放进度')) ?>">

                        <span class="player__time" data-player-duration>0:00</span>

                        <div class="player__speed" data-player-speed-wrap>
                            <button type="button" class="player__ctrl player__ctrl--speed" data-player-speed
                                    aria-label="<?= e(t('倍速')) ?>" aria-haspopup="true" aria-expanded="false">
                                <span class="player__speed-value" data-player-speed-label>1x</span>
                            </button>
                            <div class="player__speed-menu" data-player-speed-menu hidden>
                                <button type="button" class="player__speed-item" data-rate="0.5">0.5x</button>
                                <button type="button" class="player__speed-item" data-rate="0.75">0.75x</button>
                                <button type="button" class="player__speed-item is-active" data-rate="1">1x</button>
                                <button type="button" class="player__speed-item" data-rate="1.25">1.25x</button>
                                <button type="button" class="player__speed-item" data-rate="1.5">1.5x</button>
                                <button type="button" class="player__speed-item" data-rate="2">2x</button>
                            </div>
                        </div>

                        <button type="button" class="player__ctrl player__ctrl--volume" data-player-mute
                                aria-label="<?= e(t('静音')) ?>"
                                data-label-mute="<?= e(t('静音')) ?>"
                                data-label-unmute="<?= e(t('取消静音')) ?>">
                            <svg class="player__icon player__icon--volume" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9.5v5h3.6L12 18V6L7.6 9.5z" fill="currentColor"></path><path d="M15.5 9a4.2 4.2 0 0 1 0 6M18 6.6a7.6 7.6 0 0 1 0 10.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path></svg>
                            <svg class="player__icon player__icon--muted" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9.5v5h3.6L12 18V6L7.6 9.5z" fill="currentColor"></path><path d="m15.5 9.6 5 4.8m0-4.8-5 4.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path></svg>
                        </button>

                        <button type="button" class="player__ctrl" data-player-fullscreen
                                aria-label="<?= e(t('全屏')) ?>"
                                data-label-enter="<?= e(t('全屏')) ?>"
                                data-label-exit="<?= e(t('退出全屏')) ?>">
                            <svg class="player__icon player__icon--expand" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                            <svg class="player__icon player__icon--collapse" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4v5H4M20 9h-5V4M15 20v-5h5M4 15h5v5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                        </button>
                    </div>

                    <p class="player__status" data-video-status hidden
                       data-label-finished="<?= e(t('本章已学完')) ?>"><?= e(t('进度已记录')) ?></p>

                    <div class="player__playhint" data-playhint hidden>
                        <button type="button" class="btn" data-playhint-button><?= e(t('点击继续播放')) ?></button>
                    </div>

                    <?php if ($nextUrl !== ''): ?>
                        <div class="player__autonext" data-autonext hidden
                             data-countdown-template="<?= e(t('%d 秒后自动播放')) ?>">
                            <div class="player__autonext-inner">
                                <p class="player__autonext-title"><?= e(t('接下来：%s', [$nextTitle])) ?></p>
                                <p class="player__autonext-countdown" data-autonext-countdown></p>
                                <div class="player__autonext-actions">
                                    <a class="btn btn--sm" href="<?= e($nextUrlAuto) ?>" data-autonext-play><?= e(t('立即播放')) ?></a>
                                    <button type="button" class="btn btn--outline btn--sm" data-autonext-cancel><?= e(t('取消')) ?></button>
                                </div>
                                <p class="player__autonext-hint"><?= e(t('按 Esc 可取消自动播放')) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="player__empty">
                        <p><?= e(t('本章节暂时没有可播放的视频。')) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="learn__head">
                <h1 class="learn__title"><?= e($chapterTitle) ?></h1>
                <div class="learn__actions">
                    <?php if ($prevId > 0): ?>
                        <a class="btn btn--outline btn--sm" href="<?= url('/course/' . $courseId . '/learn/' . $prevId) ?>">
                            &larr; <?= e(t('上一节')) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($nextId > 0): ?>
                        <a class="btn btn--outline btn--sm" href="<?= url('/course/' . $courseId . '/learn/' . $nextId) ?>">
                            <?= e(t('下一节')) ?> &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$hasAccess): ?>
                <div class="alert alert--info">
                    <?= e(t('当前为试看章节，购买课程后可解锁全部内容。')) ?>
                    <a href="<?= url('/course/' . $courseId) ?>"><?= e(t('去看看课程')) ?></a>
                </div>
            <?php elseif ($resume > 0): ?>
                <p class="text-faint learn__resume"><?= e(t('上次播放到 %s，已为你自动续播。', [format_duration((int) $resume)])) ?></p>
            <?php endif; ?>
        </div>

        <aside class="learn__aside">
            <div class="card">
                <div class="card__header flex-between">
                    <span><?= e(t('课程目录')) ?></span>
                    <span class="text-faint"><?= e(t('%d 章', [count($chapters)])) ?></span>
                </div>

                <?php if ($chapters !== []): ?>
                    <ul class="chapter-list chapter-list--learn">
                        <?php foreach ($chapters as $index => $item): ?>
                            <?php
                            $itemId       = (int) $item['id'];
                            $isPreview    = in_array($itemId, $previewIds, true);
                            $canPlay      = $hasAccess || $isPreview;
                            $isCurrent    = $itemId === (int) $currentId;
                            $itemProgress = PlayProgress::toArray($progress[$itemId] ?? null);
                            $itemUrl      = url('/course/' . $courseId . '/learn/' . $itemId);
                            ?>
                            <li class="chapter-item<?= $isCurrent ? ' is-active' : '' ?>">
                                <span class="chapter-item__index">
                                    <?php if ((int) $itemProgress['finished'] === 1): ?>
                                        <span class="chapter-item__done" title="<?= e(t('已学完')) ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span>
                                    <?php else: ?>
                                        <?= $index + 1 ?>
                                    <?php endif; ?>
                                </span>

                                <?php if ($canPlay): ?>
                                    <a class="chapter-item__title" href="<?= $itemUrl ?>"
                                       <?= $isCurrent ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
                                <?php else: ?>
                                    <span class="chapter-item__title text-muted"><?= e($item['title']) ?></span>
                                <?php endif; ?>

                                <?php if ($isPreview && !$hasAccess): ?>
                                    <a class="badge badge--primary" href="<?= $itemUrl ?>"><?= e(t('试看')) ?></a>
                                <?php elseif (!$canPlay): ?>
                                    <span class="badge"><?= e(t('未解锁')) ?></span>
                                <?php endif; ?>

                                <span class="chapter-item__duration"><?= format_duration((int) $item['duration']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <p class="mb-0"><?= e(t('课程目录正在整理中。')) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
