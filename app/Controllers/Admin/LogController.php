<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Support\Request;

/**
 * 后台日志查看（P2）
 *
 * 单页面通过 ?type= 切换五类日志（登录 / 操作 / 支付 / 邮件 / 验证码），
 * 各自支持关键词、状态、时间范围等筛选与分页。
 */
final class LogController extends AdminController
{
    /** 每页条数 */
    private const PER_PAGE = 30;

    /**
     * 日志列表
     */
    public function index(): void
    {
        $type    = Log::normalizeType(Request::string('type', 'login'));
        $filters = Log::normalizeFilters($type, Request::all());

        $total      = Log::count($type, $filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min(max(1, Request::int('page', 1)), $totalPages);

        $this->view('admin.logs.index', [
            'pageTitle'  => '日志查看',
            'type'       => $type,
            'types'      => Log::TYPES,
            'totals'     => Log::totals(),
            'filters'    => $filters,
            'rows'       => Log::page($type, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'options'    => self::options($type),
        ]);
    }

    /**
     * 当前日志类型的筛选下拉选项
     *
     * @return array<string, array<string, string>>
     */
    private static function options(string $type): array
    {
        return match ($type) {
            'login'   => ['status' => Log::LOGIN_STATUS],
            'payment' => ['status' => Log::PAYMENT_STATUS, 'action' => Log::PAYMENT_ACTIONS],
            'mail'    => ['status' => Log::MAIL_STATUS],
            'captcha' => ['status' => Log::CAPTCHA_STATUS, 'scene' => Log::CAPTCHA_SCENES],
            default   => [],
        };
    }
}
