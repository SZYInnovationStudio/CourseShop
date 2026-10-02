<?php

declare(strict_types=1);

/**
 * 课程详情
 *
 * @var array<string, mixed> $course 课程信息
 * @var array<int, array<string, mixed>> $chapters 章节列表
 * @var array<int, array<string, mixed>> $tags 标签列表
 * @var array<int, array<string, mixed>> $related 相关课程列表
 * @var bool $hasAccess 是否已购买该课程
 * @var array<int, int> $previewIds 可试看章节 ID 列表
 * @var array<string, string> $paymentMethods 可用支付方式（键为通道标识，值为展示名）
 * @var bool $paymentEnabled 支付网关是否可用
 */

use App\Support\Auth;

$courseId      = (int) $course['id'];
$price         = (int) $course['price'];
$originalPrice = (int) ($course['original_price'] ?? 0);
$categoryName  = (string) ($course['category_name'] ?? '');
$chapterCount  = count($chapters);
$totalDuration = 0;

foreach ($chapters as $chapter) {
    $totalDuration += (int) $chapter['duration'];
}

$courseContent = trim((string) ($course['content'] ?? ''));
?>
<div class="container">
    <nav class="breadcrumb" aria-label="面包屑导航">
        <a href="<?= url('/') ?>">首页</a>
        <span class="breadcrumb__sep">/</span>
        <a href="<?= url('/courses') ?>">全部课程</a>
        <?php if ($categoryName !== ''): ?>
            <span class="breadcrumb__sep">/</span>
            <a href="<?= url('/courses?category=' . (int) $course['category_id']) ?>"><?= e($categoryName) ?></a>
        <?php endif; ?>
        <span class="breadcrumb__sep">/</span>
        <span><?= e($course['title']) ?></span>
    </nav>

    <div class="course-hero">
        <div>
            <div class="course-hero__cover">
                <?php if (!empty($course['cover'])): ?>
                    <img src="<?= e($course['cover']) ?>" alt="<?= e($course['title']) ?>">
                <?php else: ?>
                    <span class="course-card__placeholder"><?= e(mb_substr((string) $course['title'], 0, 2)) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="course-hero__title"><?= e($course['title']) ?></h1>

            <?php if (!empty($course['subtitle'])): ?>
                <p class="text-muted"><?= e($course['subtitle']) ?></p>
            <?php endif; ?>

            <div class="course-meta">
                <?php if ($categoryName !== ''): ?>
                    <span class="course-meta__item">分类：<?= e($categoryName) ?></span>
                <?php endif; ?>
                <span class="course-meta__item">共 <?= $chapterCount ?> 个章节</span>
                <?php if ($totalDuration > 0): ?>
                    <span class="course-meta__item">总时长 <?= format_duration($totalDuration) ?></span>
                <?php endif; ?>
                <span class="course-meta__item"><?= (int) $course['view_count'] ?> 次浏览</span>
                <span class="course-meta__item"><?= (int) $course['sales_count'] ?> 人已购买</span>
            </div>

            <?php if ($tags !== []): ?>
                <div class="tag-list mb-6">
                    <?php foreach ($tags as $tag): ?>
                        <span class="badge"><?= e($tag['name']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="section--tight" style="padding-top: 0;">
                <div class="section-head">
                    <h2 class="section-head__title">课程目录</h2>
                </div>

                <?php if ($chapters !== []): ?>
                    <div class="card">
                        <ul class="chapter-list">
                            <?php foreach ($chapters as $index => $chapter): ?>
                                <?php
                                $chapterId    = (int) $chapter['id'];
                                $isPreview    = in_array($chapterId, $previewIds, true);
                                $canPlay      = $hasAccess || $isPreview;
                                $chapterUrl   = url('/course/' . $courseId . '/learn/' . $chapterId);
                                ?>
                                <li class="chapter-item">
                                    <span class="chapter-item__index"><?= $index + 1 ?></span>

                                    <?php if ($canPlay): ?>
                                        <a class="chapter-item__title" href="<?= $chapterUrl ?>"><?= e($chapter['title']) ?></a>
                                    <?php else: ?>
                                        <span class="chapter-item__title text-muted"><?= e($chapter['title']) ?></span>
                                    <?php endif; ?>

                                    <?php if ($isPreview && !$hasAccess): ?>
                                        <a class="badge badge--primary" href="<?= $chapterUrl ?>" title="点击试看本章">试看</a>
                                    <?php elseif (!$hasAccess): ?>
                                        <span class="badge">未解锁</span>
                                    <?php endif; ?>

                                    <span class="chapter-item__duration"><?= format_duration((int) $chapter['duration']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="card card--flat">
                        <div class="empty-state">
                            <p class="mb-0">课程目录正在整理中。</p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($courseContent !== ''): ?>
                <section class="section--tight">
                    <div class="section-head">
                        <h2 class="section-head__title">课程介绍</h2>
                    </div>
                    <div class="prose">
                        <?= markdown($courseContent) ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside>
            <div class="buy-card">
                <div class="buy-card__price">
                    <span class="price price--lg price--accent"><?= price_html($price) ?></span>
                    <?php if ($originalPrice > $price && $originalPrice > 0): ?>
                        <span class="price-original">&yen;<?= format_money($originalPrice) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($hasAccess): ?>
                    <a class="btn btn--lg btn--block" href="<?= url('/my/courses') ?>">开始学习</a>
                    <p class="form-hint text-center">你已拥有这门课程</p>
                <?php elseif (Auth::check()): ?>
                    <form method="post" action="<?= url('/order/create') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="course_id" value="<?= $courseId ?>">
                        <button class="btn btn--lg btn--block" type="submit">
                            <?= $price > 0 ? '立即购买' : '免费学习' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <a class="btn btn--lg btn--block" href="<?= url('/login') ?>">登录后购买</a>
                    <p class="form-hint text-center">还没有账号？<a href="<?= url('/register') ?>">立即注册</a></p>
                <?php endif; ?>

                <ul class="buy-card__list">
                    <li><span aria-hidden="true">&#10003;</span> 购买后永久有效，随时回看</li>
                    <?php if ($paymentEnabled && $paymentMethods !== []): ?>
                        <li><span aria-hidden="true">&#10003;</span> 支持<?= e(implode(' / ', array_values($paymentMethods))) ?></li>
                    <?php endif; ?>
                    <li><span aria-hidden="true">&#10003;</span> 在线视频流畅播放，支持进度记录</li>
                    <li><span aria-hidden="true">&#10003;</span> 共 <?= $chapterCount ?> 个章节</li>
                </ul>

                <?php if ($price > 0 && (!$paymentEnabled || $paymentMethods === [])): ?>
                    <p class="form-hint">支付通道维护中，暂不可在线购买，请稍后再试。</p>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<?php if ($related !== []): ?>
    <section class="section">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2 class="section-head__title">相关课程</h2>
                    <p class="section-head__desc">同一分类下的其他课程</p>
                </div>
            </div>

            <div class="course-grid">
                <?php foreach ($related as $cardCourse): ?>
                    <?php require VIEW_PATH . '/partials/course-card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php // 移动端底部固定购买栏：与右侧购买卡片操作一致，仅在窄屏显示 ?>
<div class="buy-bar" role="region" aria-label="购买操作">
    <div class="buy-bar__price">
        <span class="price price--lg price--accent"><?= price_html($price) ?></span>
        <?php if ($originalPrice > $price && $originalPrice > 0): ?>
            <span class="price-original">&yen;<?= format_money($originalPrice) ?></span>
        <?php endif; ?>
    </div>

    <div class="buy-bar__action">
        <?php if ($hasAccess): ?>
            <a class="btn btn--lg btn--block" href="<?= url('/my/courses') ?>">开始学习</a>
        <?php elseif (Auth::check()): ?>
            <form method="post" action="<?= url('/order/create') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <button class="btn btn--lg btn--block" type="submit">
                    <?= $price > 0 ? '立即购买' : '免费学习' ?>
                </button>
            </form>
        <?php else: ?>
            <a class="btn btn--lg btn--block" href="<?= url('/login') ?>">登录后购买</a>
        <?php endif; ?>
    </div>
</div>
