<?php

declare(strict_types=1);

/**
 * 我的课程
 *
 * @var array<int, array<string, mixed>> $courses 已购课程列表
 * @var int $total 课程总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 */

$pageUrl = static function (int $target): string {
    $query = $_GET;
    $query['page'] = $target;

    return url('/my/courses') . '?' . http_build_query($query);
};

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e(t('我的课程')) ?></h1>
    <p class="page-head__desc"><?= e(t('共 %d 门课程，随时回来继续学习。', [(int) $total])) ?></p>
</div>

<div class="container">
    <div class="btn-group mb-6">
        <a class="btn btn--outline btn--sm" href="<?= url('/orders') ?>"><?= e(t('我的订单')) ?></a>
        <a class="btn btn--outline btn--sm" href="<?= url('/courses') ?>"><?= e(t('去逛逛课程')) ?></a>
    </div>

    <?php if ($courses === []): ?>
        <div class="card card--flat">
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14"></path><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"></path></svg>
                </div>
                <p><?= e(t('你还没有已购买的课程。')) ?></p>
                <p class="mb-0"><a class="btn btn--sm" href="<?= url('/courses') ?>"><?= e(t('去挑选课程')) ?></a></p>
            </div>
        </div>
    <?php else: ?>
        <div class="course-grid">
            <?php foreach ($courses as $course): ?>
                <?php
                $courseId      = (int) $course['id'];
                $chapterCount  = (int) $course['chapter_count'];
                $finishedCount = (int) $course['finished_count'];
                $percent       = $chapterCount > 0 ? (int) floor($finishedCount / $chapterCount * 100) : 0;
                $percent       = max(0, min(100, $percent));
                // 优先跳转到最近学习的章节，其次跳到第一个有视频的章节
                $targetChapter = (int) ($course['last_chapter_id'] ?? 0) ?: (int) ($course['first_chapter_id'] ?? 0);
                $learnUrl      = $targetChapter > 0
                    ? url('/course/' . $courseId . '/learn/' . $targetChapter)
                    : url('/course/' . $courseId);
                ?>
                <article class="course-card">
                    <a class="course-card__cover" href="<?= $learnUrl ?>" tabindex="-1" aria-hidden="true">
                        <?php if (!empty($course['cover'])): ?>
                            <img src="<?= e($course['cover']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="course-card__placeholder"><?= e(mb_substr((string) $course['title'], 0, 2)) ?></span>
                        <?php endif; ?>
                    </a>

                    <div class="course-card__body">
                        <h3 class="course-card__title">
                            <a href="<?= $learnUrl ?>"><?= e($course['title']) ?></a>
                        </h3>

                        <?php if (!empty($course['summary'])): ?>
                            <p class="course-card__summary"><?= e($course['summary']) ?></p>
                        <?php endif; ?>

                        <div class="progress" role="progressbar" aria-valuenow="<?= $percent ?>"
                             aria-valuemin="0" aria-valuemax="100"
                             aria-label="<?= e(t('学习进度 %d%%', [$percent])) ?>">
                            <span class="progress__bar" style="width: <?= $percent ?>%"></span>
                        </div>

                        <div class="course-card__meta">
                            <span class="text-muted">
                                <?= e(t('已学 %d/%d 章', [$finishedCount, $chapterCount])) ?><?= $chapterCount > 0 ? e(t('（%d%%）', [$percent])) : '' ?>
                            </span>
                            <a class="btn btn--sm" href="<?= $learnUrl ?>">
                                <?= $finishedCount > 0 && $finishedCount < $chapterCount ? e(t('继续学习')) : ($percent >= 100 && $chapterCount > 0 ? e(t('重新学习')) : e(t('开始学习'))) ?>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="<?= e(t('分页')) ?>">
                <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : $pageUrl($page - 1) ?>" rel="prev"><?= e(t('上一页')) ?></a>

                <?php if ($windowStart > 1): ?>
                    <a class="pagination__item" href="<?= $pageUrl(1) ?>">1</a>
                    <?php if ($windowStart > 2): ?>
                        <span class="pagination__item is-disabled">…</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                    <a class="pagination__item<?= $p === $page ? ' is-active' : '' ?>" href="<?= $pageUrl($p) ?>"><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($windowEnd < $totalPages): ?>
                    <?php if ($windowEnd < $totalPages - 1): ?>
                        <span class="pagination__item is-disabled">…</span>
                    <?php endif; ?>
                    <a class="pagination__item" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <a class="pagination__item<?= $page >= $totalPages ? ' is-disabled' : '' ?>"
                   href="<?= $page >= $totalPages ? '#' : $pageUrl($page + 1) ?>" rel="next"><?= e(t('下一页')) ?></a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
