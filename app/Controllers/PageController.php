<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Setting;

/**
 * 单页（关于我们等）
 */
final class PageController extends Controller
{
    public function about(): void
    {
        if (!Setting::bool('about_enabled', true)) {
            abort(404, t('页面不存在。'));
        }

        $this->view('pages.about', [
            'pageTitle' => Setting::localized('about_hero_title', '让学习回到真实的问题'),
        ]);
    }
}
