<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Models\Report;
use App\Support\Csv;
use App\Support\Request;

/**
 * 后台报表
 *
 * 提供营收 / 订单 / 退款概览，销售与消费排行，支付方式与优惠券统计，
 * 并支持将按日趋势导出为 CSV。
 */
final class ReportController extends AdminController
{
    public function index(): void
    {
        [$range, $from, $to] = Report::resolveRange(
            Request::string('range', Report::RANGE_TODAY),
            Request::string('date_from'),
            Request::string('date_to')
        );

        [$trend, $refundTrend] = $this->trends($from, $to);

        $this->view('admin.reports.index', [
            'pageTitle'      => '经营报表',
            'range'          => $range,
            'ranges'         => Report::RANGES,
            'dateFrom'       => $from,
            'dateTo'         => $to,
            'summary'        => Report::summary($from, $to),
            'trend'          => $trend,
            'refundTrend'    => $refundTrend,
            'topCourses'     => Report::topCourses($from, $to, 10),
            'topUsers'       => Report::topUsers($from, $to, 10),
            'payTypes'       => Report::payTypeBreakdown($from, $to),
            'couponStats'    => Report::couponStats($from, $to, 10),
        ]);
    }

    /**
     * 导出按日趋势 CSV（营收 / 订单 / 退款）
     */
    public function export(): void
    {
        [$range, $from, $to] = Report::resolveRange(
            Request::string('range', Report::RANGE_TODAY),
            Request::string('date_from'),
            Request::string('date_to')
        );

        [$trend, $refundTrend] = $this->trends($from, $to);

        // 以退款日期为键，便于与营收趋势逐日对齐
        $refundMap = [];
        foreach ($refundTrend as $row) {
            $refundMap[$row['date']] = $row;
        }

        $rows = [];
        foreach ($trend as $row) {
            $rows[] = [
                $row['date'],
                $row['orders'],
                number_format($row['revenue'] / 100, 2, '.', ''),
                $refundMap[$row['date']]['orders'] ?? 0,
                number_format(($refundMap[$row['date']]['amount'] ?? 0) / 100, 2, '.', ''),
            ];
        }

        Log::recordOperation('report.export', 'report', null, [
            'range' => $range,
            'from'  => $from,
            'to'    => $to,
        ]);

        Csv::download(
            'report_' . $from . '_' . $to . '.csv',
            ['日期', '支付订单数', '营收(元)', '退款订单数', '退款金额(元)'],
            $rows
        );
    }

    /**
     * 营收与退款按日趋势
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function trends(string $from, string $to): array
    {
        return [
            Report::dailyTrend($from, $to),
            Report::refundTrend($from, $to),
        ];
    }
}
