<?php

declare(strict_types=1);

/**
 * 套餐详情
 *
 * @var array<string, mixed> $package 套餐信息
 * @var array<int, array<string, mixed>> $courses 套餐内课程列表
 * @var array<int, bool> $ownedIds 已拥有课程的 ID 集合
 * @var bool $hasAll 是否已拥有套餐内全部课程
 * @var int $coursesTotal 套餐内课程原价合计
 * @var array<string, string> $paymentMethods 可用支付方式（键为通道标识，值为展示名）
 * @var bool $paymentEnabled 支付网关是否可用
 */

use App\Support\Auth;

$packageId     = (int) $package['id'];
$price         = (int) $package['price'];
$originalPrice = (int) ($package['original_price'] ?? 0);
$courseCount   = count($courses);
// 节省金额：优先用划线价，否则用套餐内课程原价合计
$strikeTotal   = $originalPrice > 0 ? $originalPrice : $coursesTotal;
$saved         = max(0, $strikeTotal - $price);

$packageContent = trim((string) ($package['content'] ?? ''));
?>
<div class="container">
    <nav class="breadcrumb" aria-label="面包屑导航">
        <a href="<?= url('/') ?>">首页</a>
        <span class="breadcrumb__sep">/</span>
        <a href="<?= url('/packages') ?>">优惠套餐</a>
        <span class="breadcrumb__sep">/</span>
        <span><?= e($package['title']) ?></span>
    </nav>

    <div class="course-hero">
        <div>
            <div class="course-hero__cover">
                <?php if (!empty($package['cover'])): ?>
                    <img src="<?= e($package['cover']) ?>" alt="<?= e($package['title']) ?>">
                <?php else: ?>
                    <span class="course-card__placeholder"><?= e(mb_substr((string) $package['title'], 0, 2)) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="course-hero__title"><?= e($package['title']) ?></h1>

            <?php if (!empty($package['subtitle'])): ?>
                <p class="text-muted"><?= e($package['subtitle']) ?></p>
            <?php endif; ?>

            <div class="course-meta">
                <span class="course-meta__item">套餐包含 <?= $courseCount ?> 门课程</span>
                <?php if ($saved > 0): ?>
                    <span class="course-meta__item">比单独购买省 &yen;<?= format_money($saved) ?></span>
                <?php endif; ?>
                <span class="course-meta__item"><?= (int) $package['sales_count'] ?> 人已购买</span>
            </div>

            <section class="section--tight" style="padding-top: 0;">
                <div class="section-head">
                    <h2 class="section-head__title">套餐包含课程</h2>
                </div>

                <div class="card">
                    <ul class="chapter-list">
                        <?php foreach ($courses as $index => $course): ?>
                            <?php
                            $courseId  = (int) $course['id'];
                            $isOwned   = isset($ownedIds[$courseId]);
                            $courseUrl = url('/course/' . $courseId);
                            ?>
                            <li class="chapter-item">
                                <span class="chapter-item__index"><?= $index + 1 ?></span>
                                <a class="chapter-item__title" href="<?= $courseUrl ?>"><?= e($course['title']) ?></a>

                                <?php if ($isOwned): ?>
                                    <span class="badge badge--success">已拥有</span>
                                <?php endif; ?>

                                <span class="chapter-item__duration">
                                    <span class="price price--accent"><?= price_html((int) $course['price']) ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <?php if ($packageContent !== ''): ?>
                <section class="section--tight">
                    <div class="section-head">
                        <h2 class="section-head__title">套餐介绍</h2>
                    </div>
                    <div class="prose">
                        <?= markdown($packageContent) ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside>
            <div class="buy-card">
                <div class="buy-card__price">
                    <span class="price price--lg price--accent"><?= price_html($price) ?></span>
                    <?php if ($strikeTotal > $price && $price > 0): ?>
                        <span class="price-original">&yen;<?= format_money($strikeTotal) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($hasAll): ?>
                    <a class="btn btn--lg btn--block" href="<?= url('/my/courses') ?>">开始学习</a>
                    <p class="form-hint text-center">你已拥有该套餐内全部课程</p>
                <?php elseif (Auth::check()): ?>
                    <form method="post" action="<?= url('/order/create') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="package_id" value="<?= $packageId ?>">
                        <button class="btn btn--lg btn--block" type="submit">
                            <?= $price > 0 ? '立即购买套餐' : '免费开通套餐' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <a class="btn btn--lg btn--block" href="<?= url('/login') ?>">登录后购买</a>
                    <p class="form-hint text-center">还没有账号？<a href="<?= url('/register') ?>">立即注册</a></p>
                <?php endif; ?>

                <ul class="buy-card__list">
                    <li><span class="list-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span> 一次开通套餐内 <?= $courseCount ?> 门课程</li>
                    <li><span class="list-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span> 购买后永久有效，随时回看</li>
                    <?php if ($paymentEnabled && $paymentMethods !== []): ?>
                        <li><span class="list-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span> 支持<?= e(implode(' / ', array_values($paymentMethods))) ?></li>
                    <?php endif; ?>
                    <?php if ($saved > 0): ?>
                        <li><span class="list-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span> 相比单独购买立省 &yen;<?= format_money($saved) ?></li>
                    <?php endif; ?>
                </ul>

                <?php if ($price > 0 && (!$paymentEnabled || $paymentMethods === [])): ?>
                    <p class="form-hint">支付通道维护中，暂不可在线购买，请稍后再试。</p>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<?php // 移动端底部固定购买栏：与右侧购买卡片操作一致，仅在窄屏显示 ?>
<div class="buy-bar" role="region" aria-label="购买操作">
    <div class="buy-bar__price">
        <span class="price price--lg price--accent"><?= price_html($price) ?></span>
        <?php if ($strikeTotal > $price && $price > 0): ?>
            <span class="price-original">&yen;<?= format_money($strikeTotal) ?></span>
        <?php endif; ?>
    </div>

    <div class="buy-bar__action">
        <?php if ($hasAll): ?>
            <a class="btn btn--lg btn--block" href="<?= url('/my/courses') ?>">开始学习</a>
        <?php elseif (Auth::check()): ?>
            <form method="post" action="<?= url('/order/create') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" value="<?= $packageId ?>">
                <button class="btn btn--lg btn--block" type="submit">
                    <?= $price > 0 ? '立即购买套餐' : '免费开通套餐' ?>
                </button>
            </form>
        <?php else: ?>
            <a class="btn btn--lg btn--block" href="<?= url('/login') ?>">登录后购买</a>
        <?php endif; ?>
    </div>
</div>
