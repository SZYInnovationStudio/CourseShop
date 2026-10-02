<?php

declare(strict_types=1);

/**
 * 课程卡片
 *
 * @var array<string, mixed> $cardCourse 课程数据（含 id/title/cover/summary/price/original_price/category_name）
 */

$cardUrl      = url('/course/' . (int) $cardCourse['id']);
$cardCover    = (string) ($cardCourse['cover'] ?? '');
$cardPrice    = (int) ($cardCourse['price'] ?? 0);
$cardOriginal = (int) ($cardCourse['original_price'] ?? 0);
$cardCategory = (string) ($cardCourse['category_name'] ?? '');
?>
<article class="course-card">
    <a class="course-card__cover" href="<?= $cardUrl ?>" tabindex="-1" aria-hidden="true">
        <?php if ($cardCover !== ''): ?>
            <img src="<?= e($cardCover) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="course-card__placeholder"><?= e(mb_substr((string) $cardCourse['title'], 0, 2)) ?></span>
        <?php endif; ?>
    </a>

    <div class="course-card__body">
        <h3 class="course-card__title">
            <a href="<?= $cardUrl ?>"><?= e($cardCourse['title']) ?></a>
        </h3>

        <?php if (!empty($cardCourse['summary'])): ?>
            <p class="course-card__summary"><?= e($cardCourse['summary']) ?></p>
        <?php endif; ?>

        <div class="course-card__meta">
            <span class="course-card__price">
                <span class="price price--accent"><?= price_html($cardPrice) ?></span>
                <?php if ($cardOriginal > $cardPrice && $cardOriginal > 0): ?>
                    <span class="price-original">&yen;<?= format_money($cardOriginal) ?></span>
                <?php endif; ?>
            </span>

            <?php if ($cardCategory !== ''): ?>
                <span class="badge"><?= e($cardCategory) ?></span>
            <?php endif; ?>
        </div>
    </div>
</article>
