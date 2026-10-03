<?php

declare(strict_types=1);

/**
 * 404 页面
 *
 * @var int $status HTTP 状态码
 * @var string $message 错误提示信息
 * @var bool $debug 是否处于调试模式
 * @var \Throwable|null $detail 异常详情（调试模式下为 Throwable）
 */
?>
<div class="error-page">
    <div class="error-page__code" aria-hidden="true">404</div>
    <h1 class="error-page__title"><?= e(t('页面不存在')) ?></h1>
    <p class="error-page__message"><?= e($message ?? t('你访问的页面已被移除或从未存在。')) ?></p>

    <div class="btn-group mt-6">
        <a class="btn" href="<?= url('/') ?>"><?= e(t('返回首页')) ?></a>
        <a class="btn btn--outline" href="<?= url('/courses') ?>"><?= e(t('浏览课程')) ?></a>
    </div>
</div>
