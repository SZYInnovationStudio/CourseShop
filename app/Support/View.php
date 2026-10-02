<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 原生 PHP 模板渲染
 *
 * 视图文件位于 app/Views，文件名使用点号映射目录，例如 home.index -> app/Views/home/index.php
 * 布局文件通过 $content 变量接收视图正文（已是 HTML 字符串，输出时不要再转义）。
 */
final class View
{
    /**
     * 渲染并输出
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): void
    {
        echo self::make($view, $data, $layout);
        Session::clearOldInput();
    }

    /**
     * 渲染为字符串
     *
     * @param array<string, mixed> $data
     */
    public static function make(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($view, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    public static function exists(string $view): bool
    {
        return is_file(self::path($view));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderFile(string $view, array $data): string
    {
        $file = self::path($view);

        if (!is_file($file)) {
            throw new HttpException(500, '视图文件不存在：' . $view);
        }

        // 视图内可直接使用 $data 中的键名变量
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    private static function path(string $view): string
    {
        return VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';
    }
}
