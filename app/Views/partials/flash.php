<?php

declare(strict_types=1);

/**
 * 一次性提示
 *
 * $flashStyle 为 'toast' 时输出顶部浮层（不占版面、滚动时位置不变），
 * 否则输出页面内的经典提示条。后台布局不传该变量，始终使用经典样式。
 *
 * @var string|null $flashStyle
 */

use App\Support\Session;

$flashStyle = ($flashStyle ?? 'classic') === 'toast' ? 'toast' : 'classic';
$flashKeys  = ['success', 'error', 'warning', 'info'];
$flashItems = [];

foreach ($flashKeys as $flashKey) {
    $flashMessage = Session::getFlash($flashKey);

    if ($flashMessage === null || $flashMessage === '') {
        continue;
    }

    $flashItems[] = ['key' => $flashKey, 'message' => $flashMessage];
}

if ($flashItems === []) {
    return;
}
?>
<?php if ($flashStyle === 'toast'): ?>
    <div class="toast-stack" data-toast-stack>
        <?php foreach ($flashItems as $item): ?>
            <div class="toast toast--<?= e($item['key']) ?>" role="alert" data-flash>
                <span class="grow"><?= e($item['message']) ?></span>
                <button type="button" class="toast__close" data-flash-close aria-label="<?= e(t('关闭提示')) ?>">&times;</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?php foreach ($flashItems as $item): ?>
        <div class="alert alert--<?= e($item['key']) ?>" role="alert" data-flash>
            <span class="grow"><?= e($item['message']) ?></span>
            <button type="button" class="alert__close" data-flash-close aria-label="<?= e(t('关闭提示')) ?>">&times;</button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
