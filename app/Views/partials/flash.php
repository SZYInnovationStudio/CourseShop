<?php

declare(strict_types=1);

/**
 * 一次性提示
 *
 * 统一输出顶部浮层 Toast（不占版面、滚动时位置不变）。
 */

use App\Support\Session;

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
<div class="toast-stack" data-toast-stack>
    <?php foreach ($flashItems as $item): ?>
        <div class="toast toast--<?= e($item['key']) ?>" role="alert" data-flash>
            <span class="grow"><?= e($item['message']) ?></span>
            <button type="button" class="toast__close" data-flash-close aria-label="<?= e(t('关闭提示')) ?>">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
