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
    <h1 class="error-page__title">服务暂时不可用</h1>
    <p class="error-page__message"><?= e($message ?? '服务器出现了一点问题，请稍后再试。') ?></p>

    <div class="btn-group mt-6">
        <a class="btn" href="<?= url('/') ?>">返回首页</a>
        <?php // 用当前请求地址实现「重新加载」：CSP 禁止 javascript: 协议的内联脚本 ?>
        <a class="btn btn--outline" href="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/')) ?>">重新加载</a>
    </div>

    <?php if ($detailText !== ''): ?>
        <pre class="error-detail"><?= e($detailText) ?></pre>
    <?php endif; ?>
</div>
