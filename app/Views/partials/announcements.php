<?php

declare(strict_types=1);

/**
 * 站点公告区块
 *
 * 与原首页保持一致：标题 + 日期 + Markdown 正文，无启用公告时不渲染。
 * 使用处需传入 $announcements。
 *
 * @var array<int, array<string, mixed>> $announcements 站点公告列表
 */

if (($announcements ?? []) === []) {
    return;
}
?>
<section class="section section--tight">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 class="section-head__title"><?= e(t('站点公告')) ?></h2>
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
                                    <?php $announcementTs = strtotime((string) $announcement['published_at']); ?>
                                    <?= $announcementTs !== false ? e(date('Y-m-d', $announcementTs)) : '' ?>
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
