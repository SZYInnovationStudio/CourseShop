<?php

declare(strict_types=1);

/**
 * 通用错误页（除 404 之外的 4xx 客户端错误）
 *
 * @var int $status HTTP 状态码
 * @var string $message 错误提示信息
 * @var bool $debug 是否处于调试模式
 * @var \Throwable|null $detail 异常详情（调试模式下为 Throwable）
 */

$titles = [
    400 => t('请求无效'),
    401 => t('需要登录'),
    403 => t('无权访问'),
    405 => t('请求方法不被允许'),
    419 => t('页面已过期'),
    429 => t('请求过于频繁'),
];

$title = $titles[(int) ($status ?? 400)] ?? t('请求无法完成');
?>
<div class="error-page">
    <div class="error-page__code" aria-hidden="true"><?= (int) ($status ?? 400) ?></div>
    <h1 class="error-page__title"><?= e($title) ?></h1>
    <p class="error-page__message"><?= e($message ?? t('请求无法完成，请返回后重试。')) ?></p>

    <div class="btn-group mt-6">
        <a class="btn" href="<?= url('/') ?>"><?= e(t('返回首页')) ?></a>
        <a class="btn btn--outline" href="<?= url('/courses') ?>"><?= e(t('浏览课程')) ?></a>
    </div>
</div>
