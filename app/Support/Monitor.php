<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 错误监控钩子（默认关闭，不依赖任何第三方 SDK）
 *
 * 当站点发生 5xx 级严重错误时，ErrorHandler 会调用 Monitor::report()，
 * 按配置的通道上报异常摘要，便于接入自建告警（钉钉 / 飞书 / Sentry 兼容接口等）：
 *   - log     ：写入 storage/logs/monitor-YYYY-MM-DD.log（默认，仅落盘）
 *   - webhook ：以 JSON POST 到自定义地址
 *
 * 配置优先级：后台「错误监控」设置 > .env（MONITOR_*）> 内置默认值。
 * 上报过程中的任何异常都会被吞掉，绝不影响主流程与错误页渲染。
 */
final class Monitor
{
    /**
     * 上报异常
     *
     * @param array<string, mixed> $context 附加上下文
     */
    public static function report(Throwable $e, array $context = []): void
    {
        try {
            if (!self::enabled()) {
                return;
            }

            $payload = self::payload($e, $context);

            if (self::channel() === 'webhook') {
                self::sendWebhook($payload);
                return;
            }

            self::writeLog($payload);
        } catch (Throwable) {
            // 监控上报失败时静默忽略
        }
    }

    private static function enabled(): bool
    {
        $value = self::conf('enabled', false);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function channel(): string
    {
        return strtolower((string) self::conf('channel', 'log'));
    }

    /**
     * 读取配置：后台设置优先，回退到 config('monitor.*')
     */
    private static function conf(string $key, mixed $default): mixed
    {
        $value = Setting::get('monitor_' . $key, null);

        return $value !== null ? $value : Config::get('monitor.' . $key, $default);
    }

    /**
     * 组装上报载荷（只包含可安全序列化的标量与数组）
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function payload(Throwable $e, array $context): array
    {
        $isCli = PHP_SAPI === 'cli';

        return [
            'app'         => 'CourseShop',
            'environment' => (string) Config::get('app.env', 'production'),
            'time'        => date('c'),
            'method'      => $isCli ? 'CLI' : Request::method(),
            'url'         => $isCli ? 'cli' : Request::path(),
            'ip'          => $isCli ? '' : Request::ip(),
            'exception'   => $e::class,
            'status'      => $e instanceof HttpException ? $e->statusCode() : 500,
            'message'     => $e->getMessage(),
            'file'        => $e->getFile() . ':' . $e->getLine(),
            'trace'       => $e->getTraceAsString(),
            'context'     => $context,
        ];
    }

    /**
     * 落盘到独立监控日志，便于与业务日志分离
     *
     * @param array<string, mixed> $payload
     */
    private static function writeLog(array $payload): void
    {
        $directory = STORAGE_PATH . '/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        @file_put_contents(
            $directory . '/monitor-' . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 以 JSON POST 上报到自定义 Webhook 地址
     *
     * @param array<string, mixed> $payload
     */
    private static function sendWebhook(array $payload): void
    {
        $url = trim((string) self::conf('webhook', ''));

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return;
        }

        // SSRF 防护：仅允许上报到公网地址，拒绝回环 / 内网 / 保留地址
        if (!self::isPublicWebhookUrl($url)) {
            Logger::warning('监控 Webhook 地址指向内网或保留地址，已拒绝上报', ['url' => $url]);

            return;
        }

        $timeout = max(1, (int) self::conf('timeout', 3));
        $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        // 优先使用 cURL，缺失时回退到流式请求
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_NOSIGNAL       => true,
            ]);
            curl_exec($ch);
            curl_close($ch);

            return;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $json,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $context);
    }

    /**
     * 判断 Webhook 地址是否指向公网主机
     *
     * 通过解析主机名得到 IP，拒绝回环、私有与保留地址，防止被用于 SSRF。
     */
    private static function isPublicWebhookUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $resolved = gethostbynamel($host);

            if ($resolved === false || $resolved === []) {
                return false;
            }

            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
