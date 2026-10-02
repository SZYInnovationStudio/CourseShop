<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 轻量 Markdown 渲染器
 *
 * 面向站点公告、用户协议等「信任来源」的富文本展示，无第三方依赖。
 * 安全策略：正文先按块级语法解析，行内内容统一经 htmlspecialchars 转义后再拼装 HTML，
 * 因此原始 HTML 标签不会被执行（等价于转义输出），可放心用 <?= markdown($text) ?> 直接输出。
 *
 * 支持语法：标题、段落、无序/有序列表、引用、围栏代码块、行内代码、
 * 粗体、斜体、链接、水平线。
 */
final class Markdown
{
    /**
     * 渲染 Markdown 为 HTML 片段
     */
    public static function render(?string $text): string
    {
        $text = (string) $text;

        if (trim($text) === '') {
            return '';
        }

        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $count = count($lines);

        $html = [];
        $i    = 0;

        while ($i < $count) {
            $trimmed = trim($lines[$i]);

            // 空行：跳过
            if ($trimmed === '') {
                $i++;
                continue;
            }

            // 围栏代码块 ```lang ... ```
            if (str_starts_with($trimmed, '```')) {
                $lang = trim(substr($trimmed, 3));
                $code = [];
                $i++;

                while ($i < $count && !str_starts_with(trim($lines[$i]), '```')) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++; // 跳过结束围栏

                $class = $lang !== '' ? ' class="language-' . self::escape($lang) . '"' : '';
                $html[] = '<pre><code' . $class . '>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            // 标题 # ~ ######
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $matched) === 1) {
                $level  = strlen($matched[1]);
                $html[] = '<h' . $level . '>' . self::inline(trim($matched[2])) . '</h' . $level . '>';
                $i++;
                continue;
            }

            // 水平线 --- / *** / ___
            if (preg_match('/^([-*_])\s*(\1\s*){2,}$/', $trimmed) === 1) {
                $html[] = '<hr>';
                $i++;
                continue;
            }

            // 引用块 > ...
            if (str_starts_with($trimmed, '>')) {
                $quote = [];

                while ($i < $count && str_starts_with(trim($lines[$i]), '>')) {
                    $quote[] = preg_replace('/^>\s?/', '', trim($lines[$i]));
                    $i++;
                }

                $html[] = '<blockquote>' . self::inline(implode(' ', $quote)) . '</blockquote>';
                continue;
            }

            // 无序列表 - / * / +
            if (preg_match('/^[-*+]\s+/', $trimmed) === 1) {
                $items = [];

                while ($i < $count && preg_match('/^[-*+]\s+(.*)$/', trim($lines[$i]), $matched) === 1) {
                    $items[] = '<li>' . self::inline($matched[1]) . '</li>';
                    $i++;
                }

                $html[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            // 有序列表 1. 2. ...
            if (preg_match('/^\d+\.\s+/', $trimmed) === 1) {
                $items = [];

                while ($i < $count && preg_match('/^\d+\.\s+(.*)$/', trim($lines[$i]), $matched) === 1) {
                    $items[] = '<li>' . self::inline($matched[1]) . '</li>';
                    $i++;
                }

                $html[] = '<ol>' . implode('', $items) . '</ol>';
                continue;
            }

            // 段落：合并连续的非块级起始行，单换行渲染为 <br>
            $paragraph = [];

            while ($i < $count && trim($lines[$i]) !== '' && !self::isBlockStart(trim($lines[$i]))) {
                $paragraph[] = trim($lines[$i]);
                $i++;
            }

            // 兜底：避免因未识别的块级起始行导致死循环
            if ($paragraph === []) {
                $paragraph[] = $trimmed;
                $i++;
            }

            $html[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $paragraph)) . '</p>';
        }

        return implode("\n", $html);
    }

    /**
     * 当前行是否为块级语法起始行（用于段落边界判定）
     */
    private static function isBlockStart(string $line): bool
    {
        return str_starts_with($line, '```')
            || preg_match('/^(#{1,6})\s+/', $line) === 1
            || preg_match('/^([-*_])\s*(\1\s*){2,}$/', $line) === 1
            || str_starts_with($line, '>')
            || preg_match('/^[-*+]\s+/', $line) === 1
            || preg_match('/^\d+\.\s+/', $line) === 1;
    }

    /**
     * 行内格式化
     *
     * 顺序：先抽出行内代码 -> 转义全文 -> 处理链接/强调 -> 还原行内代码（转义后包裹）。
     */
    private static function inline(string $text): string
    {
        // 1) 抽出行内代码，避免其中的符号被后续规则误处理
        $codes = [];
        $text  = preg_replace_callback('/`([^`]+)`/', static function (array $matched) use (&$codes): string {
            $codes[] = $matched[1];

            return "\x00C" . (count($codes) - 1) . "\x00";
        }, $text) ?? $text;

        // 2) 转义，杜绝原始 HTML 注入
        $text = self::escape($text);

        // 3) 链接 [文字](http(s)://...)
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/i',
            static fn (array $matched): string =>
                '<a href="' . $matched[2] . '" target="_blank" rel="noopener nofollow">' . $matched[1] . '</a>',
            $text
        ) ?? $text;

        // 4) 粗体 **文字**
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;

        // 5) 斜体 *文字* 与 _文字_
        $text = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9_])_([^_]+)_(?![A-Za-z0-9_])/', '<em>$1</em>', $text) ?? $text;

        // 6) 还原行内代码（内容需再次转义，因为抽取发生在转义之前）
        $text = preg_replace_callback('/\x00C(\d+)\x00/', static function (array $matched) use ($codes): string {
            $index = (int) $matched[1];

            return '<code>' . self::escape($codes[$index] ?? '') . '</code>';
        }, $text) ?? $text;

        return $text;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
