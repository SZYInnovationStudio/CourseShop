<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 全局异常 / 错误处理
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_exception_handler([self::class, 'handleException']);

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            // 提示类错误（notice / warning / deprecated）只记录日志，不升级为异常，避免整站 500
            $logOnly = [
                E_NOTICE,
                E_USER_NOTICE,
                E_WARNING,
                E_USER_WARNING,
                E_DEPRECATED,
                E_USER_DEPRECATED,
            ];

            if (in_array($severity, $logOnly, true)) {
                Logger::warning(sprintf('%s in %s:%d', $message, $file, $line));

                return true;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleException(Throwable $e): void
    {
        $status = $e instanceof HttpException ? $e->statusCode() : 500;

        if ($status >= 500) {
            Logger::error($e->getMessage(), [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);

            // 错误监控钩子（默认关闭）：仅在 5xx 严重错误时上报
            Monitor::report($e);
        } else {
            Logger::info('HTTP ' . $status . '：' . $e->getMessage(), ['path' => Request::path()]);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, sprintf("[%d] %s\n%s\n", $status, $e->getMessage(), $e->getTraceAsString()));
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
        }

        $debug   = (bool) Config::get('app.debug', false);
        $message = $status >= 500 && !$debug
            ? HttpException::defaultMessage($status)
            : $e->getMessage();

        try {
            echo View::make(
                self::viewForStatus($status),
                [
                    'status'  => $status,
                    'message' => $message,
                    'debug'   => $debug,
                    'detail'  => $debug ? $e : null,
                ]
            );
        } catch (Throwable) {
            // 视图渲染也失败时降级为纯文本
            header('Content-Type: text/plain; charset=utf-8');
            echo $status . ' ' . $message;
        }

        exit;
    }

    /**
     * 依据 HTTP 状态码选择错误视图
     *
     * 4xx 是客户端错误，不能复用 500「服务暂时不可用」页面：
     * 404 用专用页面，其余 4xx 用通用错误页，5xx 才用 500 页面。
     */
    private static function viewForStatus(int $status): string
    {
        if ($status === 404) {
            return 'errors.404';
        }

        if ($status >= 400 && $status < 500) {
            return 'errors.error';
        }

        return 'errors.500';
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        self::handleException(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }
}
