<?php

declare(strict_types=1);

/**
 * 套餐列表
 *
 * @var array<int, array<string, mixed>> $packages 套餐列表
 * @var int $total 套餐总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 */

/** 生成分页地址 */
$pageUrl = static function (int $target): string {
    return url('/packages') . ($target > 1 ? '?page=' . $target : '');
};

// 分页窗口：最多显示 5 个页码
$windowStart = max(1, min($page - 2, $totalPages - 4));
$windowEnd   = min($totalPages, $windowStart + 4);
?>
<div class="page-head container">
    <h1 class="page-head__title">优惠套餐</h1>
    <p class="page-head__desc">组合购买更划算，一次开通套餐内全部课程</p>
</div>

<div class="container">
    <?php if ($packages !== []): ?>
        <div class="course-grid">
            <?php foreach ($packages as $cardPackage): ?>
                <?php
                $pkgId         = (int) $cardPackage['id'];
                $pkgUrl        = url('/package/' . $pkgId);
                $pkgCover      = (string) ($cardPackage['cover'] ?? '');
                $pkgPrice      = (int) ($cardPackage['price'] ?? 0);
                $pkgOriginal   = (int) ($cardPackage['original_price'] ?? 0);
                $pkgCourses    = (int) ($cardPackage['course_count'] ?? 0);
                $pkgCoursesSum = (int) ($cardPackage['courses_price'] ?? 0);
                // 划线价：优先用后台填写的划线价，否则退回课程原价合计
                $pkgStrike     = $pkgOriginal > 0 ? $pkgOriginal : $pkgCoursesSum;
                ?>
                <article class="course-card">
                    <a class="course-card__cover" href="<?= $pkgUrl ?>" tabindex="-1" aria-hidden="true">
                        <?php if ($pkgCover !== ''): ?>
                            <img src="<?= e($pkgCover) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="course-card__placeholder"><?= e(mb_substr((string) $cardPackage['title'], 0, 2)) ?></span>
                        <?php endif; ?>
                    </a>

                    <div class="course-card__body">
                        <h3 class="course-card__title">
                            <a href="<?= $pkgUrl ?>"><?= e($cardPackage['title']) ?></a>
                        </h3>

                        <?php if (!empty($cardPackage['summary'])): ?>
                            <p class="course-card__summary"><?= e($cardPackage['summary']) ?></p>
                        <?php endif; ?>

                        <div class="course-card__meta">
                            <span class="course-card__price">
                                <span class="price price--accent"><?= price_html($pkgPrice) ?></span>
                                <?php if ($pkgStrike > $pkgPrice && $pkgPrice > 0): ?>
                                    <span class="price-original">&yen;<?= format_money($pkgStrike) ?></span>
                                <?php endif; ?>
                            </span>

                            <span class="badge badge--primary">含 <?= $pkgCourses ?> 门课程</span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="分页">
                <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : $pageUrl($page - 1) ?>" rel="prev">上一页</a>

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
                   href="<?= $page >= $totalPages ? '#' : $pageUrl($page + 1) ?>" rel="next">下一页</a>
            </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="card card--flat">
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"></rect><path d="M12 8v13"></path><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path></svg>
                </div>
                <p class="mb-0">暂无上架套餐，敬请期待。</p>
            </div>
        </div>
    <?php endif; ?>
</div>
