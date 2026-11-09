<?php

declare(strict_types=1);

/**
 * 500 页面
 *
 * @var int $status HTTP 状态码
 * @var string $message 错误提示信息
 * @var bool $debug 是否处于调试模式
 * @var \Throwable|null $detail 异常详情（调试模式下为 Throwable）
 */

$detailText = '';
if (!empty($debug) && isset($detail) && $detail instanceof \Throwable) {
    $detailText = $detail->getMessage() . "\n"
        . $detail->getFile() . ':' . $detail->getLine() . "\n\n"
        . $detail->getTraceAsString();
}
?>
<div class="error-page">
    <div class="error-page__code" aria-hidden="true"><?= (int) ($status ?? 500) ?></div>
    <h1 class="error-page__title"><?= e(t('服务暂时不可用')) ?></h1>
    <p class="error-page__message"><?= e($message ?? t('服务器出现了一点问题，请稍后再试。')) ?></p>

    <div class="btn-group mt-6">
        <a class="btn" href="<?= url('/') ?>"><?= e(t('返回首页')) ?></a>
        <?php
        // 只取路径并强制以单个 / 开头：REQUEST_URI 为 //evil.com/... 时不能生成协议相对外链
        $retryPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $retryPath = '/' . ltrim($retryPath, '/');
        ?>
        <a class="btn btn--outline" href="<?= e($retryPath) ?>"><?= e(t('重新加载')) ?></a>
    </div>

    <?php if ($detailText !== ''): ?>
        <pre class="error-detail"><?= e($detailText) ?></pre>
    <?php endif; ?>
</div>
