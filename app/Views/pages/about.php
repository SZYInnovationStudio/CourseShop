<?php

declare(strict_types=1);

/**
 * 关于我们 —— 通栏头图 + 图文交替的编辑式叙事
 *
 * 顶部为通栏大图与居中标题；正文由「关于页设置」的 about_content
 * （Markdown，支持多语言）驱动：独占一行的图片会与紧随其后的文字
 * 组成左右交替的图文栏目，首段则作为居中导语。
 *
 * @var string|null $pageTitle 页面标题
 */

use App\Support\Setting;

$defaultContent = "CourseShop 是一个专注实用技能的在线课程平台。我们与一线实践者一起，把真实项目中的经验整理成可以按步骤完成的课程。\n\n![课程现场](/assets/images/about/about-1.svg)\n\n## 我们相信\n\n信息从来不缺，缺的是一条走得通的路。好的课程应该回答三个问题：要学什么、为什么这样学、学完能做什么。\n\n![一起打磨课程](/assets/images/about/about-2.svg)\n\n## 我们如何做\n\n从选题、结构、示例到练习，每门课都经过多轮试学与修订，宁可慢一点，也不把半成品交到你手里。\n\n![学习者的日常](/assets/images/about/about-3.svg)\n\n学习不需要一口气走完，挑一条你感兴趣的路径，从第一节课开始就好。";

$content      = (string) Setting::localized('about_content', $defaultContent);
$heroImage    = trim(Setting::string('about_hero_image', '/assets/images/landing/about.svg'));
$heroImageUrl = $heroImage !== '' ? url($heroImage) : '';

// 以「独占一行的图片」为分界切分正文：导语 +（图片, 文字）组成的图文行
// 注意：需要捕获组，配合 PREG_SPLIT_DELIM_CAPTURE 才能把图片行保留在结果中
$parts = preg_split('/^(!\[[^\]]*\]\([^)\s]+\))$/m', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
$lead  = '';
$rows  = [];

if (is_array($parts)) {
    $lead = trim((string) array_shift($parts));

    while (count($parts) >= 2) {
        $image  = trim((string) array_shift($parts));
        $text   = trim((string) array_shift($parts));
        $rows[] = ['image' => $image, 'text' => $text];
    }

    // 正文以图片结尾时，最后一张图单独成行
    $tail = trim((string) array_shift($parts));

    if ($tail !== '' && preg_match('/^!\[[^\]]*\]\([^)\s]+\)$/', $tail) === 1) {
        $rows[] = ['image' => $tail, 'text' => ''];
    }
}
?>
<section class="about-page">
    <div class="about-hero">
        <?php if ($heroImageUrl !== ''): ?>
            <img class="about-hero__bg" src="<?= e($heroImageUrl) ?>" alt="" aria-hidden="true" loading="eager" decoding="async">
            <div class="about-hero__shade" aria-hidden="true"></div>
        <?php endif; ?>

        <div class="about-hero__inner">
            <h1 class="about-hero__title"><?= e($pageTitle ?? t('关于我们')) ?></h1>
        </div>
    </div>

    <div class="about-body">
        <?php if ($rows === []): ?>
            <div class="prose about-prose"><?= markdown($content) ?></div>
        <?php else: ?>
            <?php if ($lead !== ''): ?>
                <div class="prose about-prose about-lead" data-reveal><?= markdown($lead) ?></div>
            <?php endif; ?>

            <?php foreach ($rows as $index => $row): ?>
                <div class="about-row<?= $index % 2 === 1 ? ' about-row--reverse' : '' ?>" data-reveal>
                    <div class="about-row__media"><?= markdown($row['image']) ?></div>
                    <?php if ($row['text'] !== ''): ?>
                        <div class="prose about-prose about-row__text"><?= markdown($row['text']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
