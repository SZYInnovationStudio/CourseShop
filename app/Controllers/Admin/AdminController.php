<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Support\I18n;
use App\Support\Ids;
use App\Support\Request;

/**
 * 后台控制器基类
 *
 * 与前台控制器唯一的区别是默认布局为后台布局（侧边栏 + 内容区）。
 * 同时提供批量操作的公共能力（ID 列表解析与数量上限）。
 */
abstract class AdminController extends Controller
{
    /** 单次批量操作允许处理的最大记录数 */
    protected const BATCH_MAX = 500;

    public function __construct()
    {
        // 后台管理界面固定使用简体中文：共享类（校验器、HTTP 异常、模型消息）的多语言
        // 消息是全局翻译的，这里强制语言可避免其影响后台。此操作不写 Cookie。
        I18n::force(I18n::DEFAULT_LOCALE);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/admin'): void
    {
        parent::view($view, $data, $layout);
    }

    /**
     * 解析批量操作提交的 ids[]，返回去重后的正整数 ID 列表
     *
     * @return array<int, int>
     */
    protected function batchIds(): array
    {
        return Ids::normalize((array) Request::input('ids', []), self::BATCH_MAX);
    }
}
