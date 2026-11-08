<?php

declare(strict_types=1);

/**
 * 关于我们 —— 单卡片 Markdown 内容
 *
 * 整页只有一张卡片：标题、正文、结尾全部由「关于页设置」的
 * about_content（Markdown，支持多语言）驱动。
 *
 * @var string|null $pageTitle 页面标题
 */

use App\Support\Setting;

$content = (string) Setting::localized('about_content', "## 关于我们\n\nCourseShop 是一个专注实用技能的在线课程平台。我们与一线实践者一起，把真实项目中的经验整理成可以按步骤完成的课程。\n\n## 我们相信\n\n信息从来不缺，缺的是一条走得通的路。好的课程应该回答三个问题：要学什么、为什么这样学、学完能做什么。\n\n## 我们如何做\n\n从选题、结构、示例到练习，每门课都经过多轮试学与修订，宁可慢一点，也不把半成品交到你手里。\n\n学习不需要一口气走完，挑一条你感兴趣的路径，从第一节课开始就好。");
?>
<section class="about-page">
    <div class="container about-page__inner">
        <article class="about-card" data-reveal>
            <div class="prose about-prose"><?= markdown($content) ?></div>
        </article>
    </div>
</section>
