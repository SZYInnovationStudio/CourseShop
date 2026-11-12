<?php

declare(strict_types=1);

/**
 * 品牌落地页首页
 *
 * 与课程列表完全独立：只读取「主页」设置，不查询任何课程数据。
 */

use App\Support\Setting;

// 默认文案以中文原文同时作为 t() 词典键：后台未配置译文时，各语言也能显示像样的内置文案
$heroKicker   = trim(Setting::localized('landing_hero_kicker', t('在线课程 · 系统学习')));
$heroTitle    = trim(Setting::localized('landing_hero_title', t('系统学习，从入门到实战')));
$heroSubtitle = trim(Setting::localized('landing_hero_subtitle', t('把复杂的知识拆成清晰的路径，用真实的项目带你一步步走到会用、敢用。')));
$heroImage    = trim(Setting::string('landing_hero_image', '/assets/images/landing/hero.svg'));
$heroImageUrl = $heroImage !== '' ? url($heroImage) : '';

$heroCtaText  = trim(Setting::localized('landing_hero_cta_text', t('浏览全部课程')));
$heroCtaUrl   = trim(Setting::string('landing_hero_cta_url', '/courses'));
$heroCta2Text = trim(Setting::localized('landing_hero_cta2_text', t('了解我们')));
$heroCta2Url  = trim(Setting::string('landing_hero_cta2_url', '/about'));

$aboutEnabled = Setting::bool('about_enabled', true);
// 关于页关闭时，次链接凡指向 /about（含完整 URL、带参、尾斜杠等写法）一律隐藏
$cta2Path      = rtrim(strtolower((string) parse_url($heroCta2Url, PHP_URL_PATH)), '/');
$heroCta2Visible = $heroCta2Text !== ''
    && !(!$aboutEnabled && in_array($cta2Path, ['/about', 'about'], true));

$galleryTitle  = trim(Setting::localized('landing_gallery_title', t('在课程里，看见成长')));
$galleryDefaults = [
    1 => '/assets/images/landing/gallery-1.svg',
    2 => '/assets/images/landing/gallery-2.svg',
    3 => '/assets/images/landing/gallery-3.svg',
    4 => '/assets/images/landing/gallery-4.svg',
    5 => '/assets/images/landing/gallery-5.svg',
    6 => '/assets/images/landing/gallery-6.svg',
];

$galleryImages = [];

for ($i = 1; $i <= 6; $i++) {
    $path = trim(Setting::string('landing_gallery_image_' . $i, $galleryDefaults[$i]));

    if ($path !== '') {
        $galleryImages[] = $path;
    }
}

$showIntro     = Setting::bool('landing_show_intro', true);
$introKicker   = trim(Setting::localized('landing_intro_kicker', t('我们的方式')));
$introTitle    = trim(Setting::localized('landing_intro_title', t('把好内容，讲到真正学会为止')));
$introText     = trim(Setting::localized('landing_intro_text', t("我们相信，真正有效的学习不是把知识点堆在眼前，而是把一条条路径铺到脚下。\n每一门课程都从真实问题出发，拆解成可以一步步完成的章节，再用项目把零散的知识连成完整的能力。\n你不需要先成为专家，才配开始。你只需要愿意迈出第一步。")));
$introImage    = trim(Setting::string('landing_intro_image', '/assets/images/landing/intro.svg'));
$introImageUrl = $introImage !== '' ? url($introImage) : '';

$showFeatures     = Setting::bool('landing_show_features', true);
$featuresTitle    = trim(Setting::localized('landing_features_title', t('我们如何做课')));
$featuresSubtitle = trim(Setting::localized('landing_features_subtitle', t('不追求热闹，只把该做的事做扎实。')));

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

$features = $parseItems(trim(Setting::localized('landing_features', t("体系化路径|从入门到实战，每个阶段都有清晰的目标和练习。\n项目驱动|不只讲概念，用真实项目把知识变成可交付的成果。\n随时回看|课程内容长期有效，进度自动保存，按自己的节奏学习。\n持续更新|课程与资料随技术变化迭代，只保留真正有用的内容。"))), 4);

$showCourse       = Setting::bool('landing_show_course', true);
$courseKicker     = trim(Setting::localized('landing_course_kicker', t('开始学习')));
$courseTitle      = trim(Setting::localized('landing_course_title', t('选一条路径，从第一节课开始')));
$courseText       = trim(Setting::localized('landing_course_text', t("课程目录里有完整的体系、清晰的进度和可以反复回看的章节。\n不需要一次学完，按自己的节奏往前走就好。")));
$courseButton     = trim(Setting::localized('landing_course_button_text', t('浏览全部课程')));
$courseButtonUrl  = trim(Setting::string('landing_course_button_url', '/courses'));
$courseImage      = trim(Setting::string('landing_course_image', '/assets/images/landing/course.svg'));
$courseImageUrl   = $courseImage !== '' ? url($courseImage) : '';

$renderGalleryGroup = static function () use ($galleryImages): void {
    foreach ($galleryImages as $image) {
        ?>
        <span class="landing-gallery__item">
            <img src="<?= e(url($image)) ?>" alt="" loading="lazy" decoding="async">
        </span>
        <?php
    }
};

$arrow = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
    . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    . '<path d="M5 12h14M13 6l6 6-6 6"></path></svg>';

// 极简首屏顶栏（默认）：顶栏只留品牌与登录/注册，菜单入口以居中链接行的形式放在首屏上
$minimalHeader = Setting::string('landing_header_mode', 'minimal') === 'minimal';

$heroNavLinks = [['text' => t('全部课程'), 'url' => '/courses']];

if (Setting::bool('packages_enabled', true)) {
    $heroNavLinks[] = ['text' => t('优惠套餐'), 'url' => '/packages'];
}

if (Setting::bool('about_enabled', true)) {
    $heroNavLinks[] = ['text' => t('关于我们'), 'url' => '/about'];
}

// 首屏下箭头：指向第一个实际渲染的区块，全部关闭时不渲染
$cueTarget = '';

if ($galleryTitle !== '' && $galleryImages !== []) {
    $cueTarget = '#gallery';
} elseif ($showIntro) {
    $cueTarget = '#story';
} elseif ($showCourse) {
    $cueTarget = '#start';
} elseif ($showFeatures && $features !== []) {
    $cueTarget = '#philosophy';
}
?>
<section class="landing-hero<?= $heroImageUrl !== '' ? ' landing-hero--image' : '' ?>">
    <?php if ($heroImageUrl !== ''): ?>
        <img class="landing-hero__bg" src="<?= e($heroImageUrl) ?>" alt="" aria-hidden="true" loading="eager" decoding="async">
        <div class="landing-hero__shade" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="container landing-hero__inner">
        <?php if ($heroKicker !== ''): ?>
            <p class="landing-kicker"><?= e($heroKicker) ?></p>
        <?php endif; ?>

        <h1 class="landing-hero__title"><?= e($heroTitle) ?></h1>

        <?php if ($heroSubtitle !== ''): ?>
            <p class="landing-hero__subtitle"><?= nl2br(e($heroSubtitle)) ?></p>
        <?php endif; ?>

        <?php if ($minimalHeader): ?>
            <nav class="landing-hero__nav" aria-label="<?= e(t('主导航')) ?>">
                <?php foreach ($heroNavLinks as $link): ?>
                    <a class="landing-hero__navlink" href="<?= e(url($link['url'])) ?>">
                        <?= e($link['text']) ?><span class="landing-link__arrow"><?= $arrow ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php elseif ($heroCtaText !== '' || $heroCta2Visible): ?>
            <div class="landing-hero__actions">
                <?php if ($heroCtaText !== ''): ?>
                    <a class="landing-link landing-link--lg" href="<?= e(url($heroCtaUrl)) ?>">
                        <?= e($heroCtaText) ?><span class="landing-link__arrow"><?= $arrow ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($heroCta2Visible): ?>
                    <a class="landing-link landing-link--muted" href="<?= e(url($heroCta2Url)) ?>"><?= e($heroCta2Text) ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($cueTarget !== ''): ?>
        <a class="landing-hero__cue" href="<?= e($cueTarget) ?>" aria-label="<?= e(t('向下浏览')) ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 4v15M5.5 12.5 12 19l6.5-6.5"></path>
            </svg>
        </a>
    <?php endif; ?>
</section>

<?php if ($galleryTitle !== '' && $galleryImages !== []): ?>
    <?php
    // 单组至少 12 项（约 6100px），保证 4K 等超宽视口下循环平移到 -50% 时不露空白
    $repeat = max(2, (int) ceil(12 / count($galleryImages)));
    ?>
    <section class="landing-gallery" id="gallery">
        <div class="container" data-reveal>
            <h2 class="landing-head__title"><?= e($galleryTitle) ?></h2>
        </div>

        <div class="landing-gallery__viewport">
            <div class="landing-gallery__track">
                <div class="landing-gallery__group">
                    <?php for ($i = 0; $i < $repeat; $i++): ?>
                        <?php $renderGalleryGroup(); ?>
                    <?php endfor; ?>
                </div>
                <div class="landing-gallery__group" aria-hidden="true">
                    <?php for ($i = 0; $i < $repeat; $i++): ?>
                        <?php $renderGalleryGroup(); ?>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($showIntro && ($introTitle !== '' || $introText !== '' || $introImageUrl !== '')): ?>
    <section class="landing-statement" id="story">
        <div class="container landing-split<?= $introImageUrl === '' ? ' landing-split--text-only' : '' ?>">
            <div class="landing-split__text" data-reveal>
                <?php if ($introKicker !== ''): ?>
                    <p class="landing-kicker"><?= e($introKicker) ?></p>
                <?php endif; ?>
                <?php if ($introTitle !== ''): ?>
                    <h2 class="landing-statement__title"><?= e($introTitle) ?></h2>
                <?php endif; ?>
                <?php if ($introText !== ''): ?>
                    <div class="landing-copy"><?= nl2br(e($introText)) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($introImageUrl !== ''): ?>
                <figure class="landing-split__media" data-reveal data-reveal-delay="90">
                    <img src="<?= e($introImageUrl) ?>" alt="" loading="lazy" decoding="async">
                </figure>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($showFeatures && $features !== []): ?>
    <section class="landing-principles" id="philosophy">
        <div class="container">
            <div class="landing-head" data-reveal>
                <?php if ($featuresTitle !== ''): ?>
                    <h2 class="landing-head__title"><?= e($featuresTitle) ?></h2>
                <?php endif; ?>
                <?php if ($featuresSubtitle !== ''): ?>
                    <p class="landing-head__desc"><?= e($featuresSubtitle) ?></p>
                <?php endif; ?>
            </div>

            <div class="landing-principle-grid">
                <?php foreach ($features as $index => $feature): ?>
                    <article class="landing-principle" data-reveal data-reveal-delay="<?= (int) $index * 70 ?>">
                        <span class="landing-principle__index"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <h3 class="landing-principle__title"><?= e($feature['title']) ?></h3>
                        <?php if ($feature['desc'] !== ''): ?>
                            <p class="landing-principle__desc"><?= e($feature['desc']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($showCourse && ($courseTitle !== '' || $courseText !== '' || $courseButton !== '')): ?>
    <section class="landing-course" id="start">
        <div class="container landing-split<?= $courseImageUrl === '' ? ' landing-split--text-only' : '' ?>">
            <?php if ($courseImageUrl !== ''): ?>
                <figure class="landing-split__media" data-reveal>
                    <img src="<?= e($courseImageUrl) ?>" alt="" loading="lazy" decoding="async">
                </figure>
            <?php endif; ?>

            <div class="landing-split__text landing-course__inner" data-reveal data-reveal-delay="90">
                <?php if ($courseKicker !== ''): ?>
                    <p class="landing-kicker"><?= e($courseKicker) ?></p>
                <?php endif; ?>
                <?php if ($courseTitle !== ''): ?>
                    <h2 class="landing-course__title"><?= e($courseTitle) ?></h2>
                <?php endif; ?>
                <?php if ($courseText !== ''): ?>
                    <div class="landing-copy"><?= nl2br(e($courseText)) ?></div>
                <?php endif; ?>
                <?php if ($courseButton !== ''): ?>
                    <div class="landing-course__actions">
                        <a class="landing-link landing-link--lg" href="<?= e(url($courseButtonUrl)) ?>">
                            <?= e($courseButton) ?><span class="landing-link__arrow"><?= $arrow ?></span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
