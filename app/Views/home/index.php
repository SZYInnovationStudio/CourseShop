<?php

declare(strict_types=1);

/**
 * 首页
 *
 * @var array<int, array<string, mixed>> $categories 课程分类列表
 * @var array<int, array<string, mixed>> $courses 精选课程列表
 * @var array<int, array<string, mixed>> $announcements 站点公告列表
 * @var array<int, array<string, mixed>> $packages 推荐套餐列表
 * @var int $totalCourses 在售课程总数
 */

use App\Support\Setting;

$siteName    = Setting::string('site_name', 'CourseShop');
$siteDesc    = Setting::string('site_description', '精选优质课程，助你高效掌握实用技能。');
$hasCourses  = $courses !== [];
?>
<section class="hero">
    <div class="container hero__inner">
        <div class="hero__content">
            <span class="hero__eyebrow"><?= e($siteName) ?> · 在线课程平台</span>
            <h1 class="hero__title">系统学习，从入门到实战</h1>
            <p class="hero__desc"><?= e($siteDesc) ?></p>

            <div class="hero__actions">
                <a class="btn btn--lg" href="<?= url('/courses') ?>">浏览全部课程</a>
                <a class="btn btn--outline btn--lg" href="<?= url('/courses?sort=latest') ?>">查看最新上架</a>
            </div>
        </div>

        <div class="hero__stats">
            <div class="hero__stat">
                <div class="hero__stat-value"><?= (int) $totalCourses ?></div>
                <div class="hero__stat-label">在售课程</div>
            </div>
            <div class="hero__stat">
                <div class="hero__stat-value"><?= count($categories) ?></div>
                <div class="hero__stat-label">课程分类</div>
            </div>
        </div>
    </div>
</section>

<?php if ($categories !== []): ?>
    <section class="section section--tight">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2 class="section-head__title">课程分类</h2>
                    <p class="section-head__desc">按方向挑选你需要的课程</p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <?php foreach ($categories as $category): ?>
                    <a class="chip" href="<?= url('/courses?category=' . (int) $category['id']) ?>">
                        <?= e($category['name']) ?>
                        <span class="text-faint">&nbsp;<?= (int) $category['course_count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="section">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 class="section-head__title">精选课程</h2>
                <p class="section-head__desc">编辑推荐，值得优先学习</p>
            </div>
            <a class="btn btn--outline btn--sm" href="<?= url('/courses') ?>">查看全部</a>
        </div>

        <?php if ($hasCourses): ?>
            <div class="course-grid">
                <?php foreach ($courses as $cardCourse): ?>
                    <?php require VIEW_PATH . '/partials/course-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card card--flat">
                <div class="empty-state">
                    <div class="empty-state__icon" aria-hidden="true">&#128218;</div>
                    <p class="mb-0">暂无上架课程，敬请期待。</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($packages !== []): ?>
    <section class="section section--tight">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2 class="section-head__title">推荐套餐</h2>
                    <p class="section-head__desc">组合购买更划算，一次开通多门课程</p>
                </div>
                <a class="btn btn--outline btn--sm" href="<?= url('/packages') ?>">查看全部</a>
            </div>

            <div class="course-grid">
                <?php foreach ($packages as $cardPackage): ?>
                    <?php
                    $pkgId       = (int) $cardPackage['id'];
                    $pkgUrl      = url('/package/' . $pkgId);
                    $pkgCover    = (string) ($cardPackage['cover'] ?? '');
                    $pkgPrice    = (int) ($cardPackage['price'] ?? 0);
                    $pkgOriginal = (int) ($cardPackage['original_price'] ?? 0);
                    $pkgCourses  = (int) ($cardPackage['course_count'] ?? 0);
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
                                    <?php if ($pkgOriginal > $pkgPrice && $pkgPrice > 0): ?>
                                        <span class="price-original">&yen;<?= format_money($pkgOriginal) ?></span>
                                    <?php endif; ?>
                                </span>

                                <span class="badge badge--primary">含 <?= $pkgCourses ?> 门课程</span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($announcements !== []): ?>
    <section class="section section--tight">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2 class="section-head__title">站点公告</h2>
                </div>
            </div>

            <div class="card">
                <div class="card__body">
                    <ul class="list-plain">
                        <?php foreach ($announcements as $announcement): ?>
                            <li>
                                <div class="flex-between">
                                    <strong><?= e($announcement['title']) ?></strong>
                                    <span class="announcement__date">
                                        <?= e(date('Y-m-d', strtotime((string) $announcement['published_at']))) ?>
                                    </span>
                                </div>
                                <div class="prose prose--muted mt-4"><?= markdown((string) $announcement['content']) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>
