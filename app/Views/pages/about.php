<?php

declare(strict_types=1);

/**
 * 关于我们
 *
 * @var string|null $pageTitle 页面标题
 */

use App\Support\Setting;

$heroKicker   = trim(Setting::localized('about_hero_kicker', '关于我们'));
$heroTitle    = trim(Setting::localized('about_hero_title', '让学习回到真实的问题'));
$heroSubtitle = trim(Setting::localized('about_hero_subtitle', '我们做的不只是课程，而是一条从好奇到掌握、从理解到实践的路径。'));
$heroImage    = trim(Setting::string('about_hero_image', ''));
$heroImageUrl = $heroImage !== '' ? url($heroImage) : '';

$showContent     = Setting::bool('about_show_content', true);
$content         = (string) Setting::localized('about_content', "## 我们是谁\n\nCourseShop 是一个专注实用技能的在线课程平台。我们与一线实践者一起，把真实项目中的经验整理成可以按步骤完成的课程。\n\n## 我们为什么做这件事\n\n信息从来不缺，缺的是一条走得通的路。我们希望每门课程都能回答三个问题：要学什么、为什么这样学、学完能做什么。\n\n## 我们如何打磨课程\n\n从选题、结构、示例到练习，每门课都经过多轮试学与修订。宁可慢一点，也不把半成品交到你手里。");
$contentImage    = trim(Setting::string('about_content_image', ''));
$contentImageUrl = $contentImage !== '' ? url($contentImage) : '';

$showValues  = Setting::bool('about_show_values', true);
$valuesTitle = trim(Setting::localized('about_values_title', '我们坚持的三件事'));

$parseItems = static function (string $raw, int $limit): array {
    $items = [];

    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $line  = str_replace('｜', '|', $line);
        $parts = array_map('trim', explode('|', $line, 2));
        $items[] = [
            'title' => $parts[0],
            'desc'  => $parts[1] ?? '',
        ];

        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
};

$values = $parseItems(trim(Setting::localized('about_values', "真诚|只讲真正有用的内容，不制造焦虑，也不贩卖速成。\n专业|每门课程都经过反复打磨，从结构到表达都经得起推敲。\n长期|学习不是一次交易，我们更在意你学完之后能做什么。")), 4);

$showCta      = Setting::bool('about_show_cta', true);
$ctaTitle     = trim(Setting::localized('about_cta_title', '和我们一起，把想学的变成会用的'));
$ctaText      = trim(Setting::localized('about_cta_text', '挑一门你最想解决的问题，从第一节课开始。'));
$ctaButton    = trim(Setting::localized('about_cta_button_text', '浏览全部课程'));
$ctaButtonUrl = trim(Setting::string('about_cta_button_url', '/courses'));

$arrow = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
    . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    . '<path d="M5 12h14M13 6l6 6-6 6"></path></svg>';
?>
<section class="about-hero<?= $heroImageUrl !== '' ? ' about-hero--image' : '' ?>">
    <?php if ($heroImageUrl !== ''): ?>
        <img class="about-hero__bg" src="<?= e($heroImageUrl) ?>" alt="" aria-hidden="true" loading="eager" decoding="async">
        <div class="about-hero__shade" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="container about-hero__inner">
        <?php if ($heroKicker !== ''): ?>
            <p class="landing-kicker"><?= e($heroKicker) ?></p>
        <?php endif; ?>
        <h1 class="about-hero__title"><?= e($heroTitle) ?></h1>
        <?php if ($heroSubtitle !== ''): ?>
            <p class="about-hero__subtitle"><?= nl2br(e($heroSubtitle)) ?></p>
        <?php endif; ?>
    </div>
</section>

<?php if ($showContent && ($content !== '' || $contentImageUrl !== '')): ?>
    <section class="about-body" data-reveal>
        <?php if ($contentImageUrl !== ''): ?>
            <div class="landing-media">
                <img src="<?= e($contentImageUrl) ?>" alt="" loading="lazy" decoding="async">
            </div>
        <?php endif; ?>

        <div class="container landing-narrow">
            <?php if ($content !== ''): ?>
                <div class="prose about-prose"><?= markdown($content) ?></div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($showValues && $values !== []): ?>
    <section class="landing-principles about-values" data-reveal>
        <div class="container">
            <?php if ($valuesTitle !== ''): ?>
                <div class="landing-head">
                    <h2 class="landing-head__title"><?= e($valuesTitle) ?></h2>
                </div>
            <?php endif; ?>

            <div class="landing-principle-grid">
                <?php foreach ($values as $index => $value): ?>
                    <article class="landing-principle" data-reveal data-reveal-delay="<?= (int) $index * 70 ?>">
                        <span class="landing-principle__index"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <h3 class="landing-principle__title"><?= e($value['title']) ?></h3>
                        <?php if ($value['desc'] !== ''): ?>
                            <p class="landing-principle__desc"><?= e($value['desc']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($showCta && ($ctaTitle !== '' || $ctaText !== '' || $ctaButton !== '')): ?>
    <section class="about-invite" data-reveal>
        <div class="container landing-narrow">
            <?php if ($ctaTitle !== ''): ?>
                <h2 class="about-invite__title"><?= e($ctaTitle) ?></h2>
            <?php endif; ?>
            <?php if ($ctaText !== ''): ?>
                <div class="landing-copy"><?= nl2br(e($ctaText)) ?></div>
            <?php endif; ?>
            <?php if ($ctaButton !== ''): ?>
                <div class="landing-course__actions">
                    <a class="landing-link landing-link--lg" href="<?= e(url($ctaButtonUrl)) ?>">
                        <?= e($ctaButton) ?><span class="landing-link__arrow"><?= $arrow ?></span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
