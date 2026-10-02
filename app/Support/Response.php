<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 响应工具
 *
 * 注意：这里的 redirect / json 会直接结束请求（exit），
 * 因此控制器里调用后无需再 return。
 */
final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url, int $status = 302): never
    {
        header('Location: ' . $url, true, $status);
        exit;
    }

    public static function text(string $content, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');

        echo $content;
        exit;
    }

    /**
     * 追加提示消息后重定向（PRG 模式）
     *
     * @param array<string, mixed> $input 需要回填的表单数据
     */
    public static function back(string $url, ?string $type = null, ?string $message = null, array $input = []): never
    {
        if ($message !== null) {
            Session::flash($type ?: 'success', $message);
        }
        if ($input !== []) {
            Session::set('_old', $input);
        }

        self::redirect($url);
    }
}
