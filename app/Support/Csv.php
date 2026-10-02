<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV 导出工具
 *
 * 以附件形式直接输出 CSV 内容并结束请求。
 * 关键处理：
 *  - 输出 UTF-8 BOM，保证 Excel 正确识别中文；
 *  - 对以 = + - @ 等开头的单元格加前缀，防止 CSV 公式注入；
 *  - 文件名仅保留白名单字符，避免响应头注入。
 */
final class Csv
{
    /**
     * 以附件下载形式输出 CSV
     *
     * @param string                    $filename 文件名（建议使用 ASCII，如 orders_20261002.csv）
     * @param array<int, string>        $headers  表头
     * @param array<int, array<int, mixed>> $rows 数据行
     */
    public static function download(string $filename, array $headers, array $rows): never
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . self::safeFilename($filename) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            Response::text('导出失败：无法写入输出流。', 500);
        }

        // UTF-8 BOM，避免 Excel 打开中文乱码
        fwrite($out, "\xEF\xBB\xBF");

        // fputcsv 显式传入 $escape = '' 为推荐做法，避免依赖默认转义行为
        fputcsv($out, array_map([self::class, 'sanitize'], $headers), ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'sanitize'], $row), ',', '"', '');
        }

        fclose($out);
        exit;
    }

    /**
     * 清理单元格，防止公式注入（Excel / WPS 会把 = + - @ 开头的单元格当公式执行）
     */
    private static function sanitize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * 仅保留白名单字符，防止响应头注入
     */
    private static function safeFilename(string $filename): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        return ($safe === null || $safe === '') ? 'export.csv' : $safe;
    }
}
