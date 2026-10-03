<?php

declare(strict_types=1);

/**
 * 协议正文（用户协议 / 隐私政策 / 退款政策）
 *
 * @var array<string, mixed> $agreement 协议记录（含 title / version / effective_at / content）
 */

$effectiveAt   = (string) ($agreement['effective_at'] ?? '');
$effectiveDate = $effectiveAt !== '' ? substr($effectiveAt, 0, 10) : '';
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e($agreement['title']) ?></h1>
    <p class="page-head__desc">
        <?= e(t('版本')) ?> <?= e($agreement['version']) ?><?= $effectiveDate !== '' ? ' · ' . e(t('生效日期')) . ' ' . e($effectiveDate) : '' ?>
    </p>
</div>

<div class="container">
    <div class="card card--flat">
        <div class="card__body">
            <div class="prose"><?= markdown((string) $agreement['content']) ?></div>
        </div>
    </div>
</div>
