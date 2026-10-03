<?php

declare(strict_types=1);

/**
 * 一次性提示条
 */

use App\Support\Session;

$flashLabels = [
    'success' => 'alert--success',
    'error'   => 'alert--error',
    'warning' => 'alert--warning',
    'info'    => 'alert--info',
];

foreach ($flashLabels as $flashKey => $flashClass):
    $flashMessage = Session::getFlash($flashKey);
    if ($flashMessage === null || $flashMessage === '') {
        continue;
    }
    ?>
    <div class="alert <?= e($flashClass) ?>" role="alert" data-flash>
        <span class="grow"><?= e($flashMessage) ?></span>
        <button type="button" class="alert__close" data-flash-close aria-label="<?= e(t('关闭提示')) ?>">&times;</button>
    </div>
    <?php
endforeach;
