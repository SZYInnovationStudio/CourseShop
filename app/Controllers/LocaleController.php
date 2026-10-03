<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\I18n;
use App\Support\Request;
use App\Support\Response;

/**
 * 前台语言切换
 *
 * 通过站内链接（/lang/{locale}?redirect=...）切换语言，
 * 记录到会话 + Cookie，并在已登录时同步写入账号偏好。
 */
final class LocaleController extends Controller
{
    public function switch(string $locale): void
    {
        $target = I18n::safeRedirect(Request::string('redirect', '/'));

        if (!I18n::isEnabled($locale)) {
            Response::redirect($target);
        }

        I18n::remember($locale);

        Response::redirect($target);
    }
}
