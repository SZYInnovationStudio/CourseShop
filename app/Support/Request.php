<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 请求对象（只读封装 $_SERVER / $_GET / $_POST / $_FILES）
 */
final class Request
{
    public static function method(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // 表单可通过 _method 伪造 PUT/PATCH/DELETE
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /**
     * 去掉查询串后的路径，形如 /course/12
     */
    public static function path(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return ($path === false || $path === null || $path === '') ? '/' : $path;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::input($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    public static function has(string $key): bool
    {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }

    /**
     * 客户端真实 IP
     *
     * 仅当直连方（REMOTE_ADDR）位于可信代理列表（app.trusted_proxies）时，
     * 才采信 X-Forwarded-For / X-Real-IP 等转发头，避免客户端伪造 IP 绕过风控。
     */
    public static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $remote = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';

        if ($remote !== '' && self::isTrustedProxy($remote)) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
                $value = (string) ($_SERVER[$key] ?? '');
                if ($value === '') {
                    continue;
                }
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $remote !== '' ? $remote : '0.0.0.0';
    }

    /**
     * 判断给定 IP 是否属于可信反向代理
     */
    private static function isTrustedProxy(string $ip): bool
    {
        $trusted = (array) Config::get('app.trusted_proxies', []);

        foreach ($trusted as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }

            if (str_contains($rule, '/')) {
                if (self::ipInCidr($ip, $rule)) {
                    return true;
                }

                continue;
            }

            if ($rule === $ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 IP 是否落在 CIDR（IPv4/IPv6）网段内
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        if ($bits === null || !ctype_digit($bits)) {
            return false;
        }

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits     = (int) $bits;
        $maxBits  = strlen($ipBin) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder  = $bits % 8;

        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /**
     * 设备类型（pc / mobile / tablet），用于订单等表的短字段存储
     */
    public static function deviceType(): string
    {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        if ($ua === '') {
            return 'pc';
        }

        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')
            || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone')
            || str_contains($ua, 'ipod') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        return 'pc';
    }

    public static function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }
}
