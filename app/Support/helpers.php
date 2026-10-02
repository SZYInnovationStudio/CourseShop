<?php

declare(strict_types=1);

/**
 * 全局辅助函数
 *
 * 保持精简：只放模板与控制器里高频使用的小工具。
 */

use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\HttpException;
use App\Support\Setting;

if (!function_exists('e')) {
    /**
     * HTML 转义输出
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * 生成站点绝对地址
     */
    function url(string $path = ''): string
    {
        $base = (string) Config::get('app.url', '');

        if ($path === '') {
            return $base === '' ? '/' : $base . '/';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * 生成静态资源地址，并附加版本号（便于刷新浏览器缓存）
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = PUBLIC_PATH . '/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return url($path) . '?v=' . $version;
    }
}

if (!function_exists('favicon_url')) {
    /**
     * 站点 favicon 地址
     *
     * 已设置站点 Logo 时使用该图片，否则回退到内置的默认 SVG 图标。
     */
    function favicon_url(): string
    {
        $logo = trim(Setting::string('site_logo', ''));

        return $logo !== '' ? url($logo) : asset('assets/icons/favicon.svg');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}
if (!function_exists('setting')) {
    /**
     * 读取后台可配置项
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csp_nonce')) {
    /**
     * 当前请求的 CSP nonce（内联 <script> 需带 nonce="..." 才能通过 CSP）
     */
    function csp_nonce(): string
    {
        return \App\Support\SecurityHeaders::nonce();
    }
}

if (!function_exists('old')) {
    /**
     * 表单回填
     */
    function old(string $key, mixed $default = ''): mixed
    {
        $old = $_SESSION['_old'] ?? [];

        return $old[$key] ?? $default;
    }
}

if (!function_exists('auth')) {
    /** @return array<string, mixed>|null */
    function auth(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('abort')) {
    /**
     * 中断请求并返回错误页
     */
    function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }
}

if (!function_exists('format_money')) {
    /**
     * 分 -> 元（用于展示，不带货币符号）
     */
    function format_money(int $cents, int $decimals = 2): string
    {
        return number_format($cents / 100, $decimals, '.', '');
    }
}

if (!function_exists('price_html')) {
    /**
     * 分 -> 带符号价格，0 显示「免费」
     */
    function price_html(int $cents): string
    {
        if ($cents <= 0) {
            return '免费';
        }

        return '&yen;' . format_money($cents);
    }
}

if (!function_exists('format_duration')) {
    /**
     * 秒 -> mm:ss 或 hh:mm:ss
     */
    function format_duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '00:00';
        }

        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs    = $seconds % 60;

        return $hours > 0
            ? sprintf('%02d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%02d:%02d', $minutes, $secs);
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }
}

if (!function_exists('markdown')) {
    /**
     * 渲染 Markdown 为安全 HTML（公告、协议等富文本）
     *
     * 返回值已完成转义与标签白名单化，可直接用 <?= markdown($text) ?> 输出。
     */
    function markdown(?string $text): string
    {
        return \App\Support\Markdown::render($text);
    }
}

if (!function_exists('mask_email')) {
    /**
     * 邮箱脱敏：ab***@example.com
     */
    function mask_email(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        [$name, $domain] = $parts;
        $visible = mb_substr($name, 0, 2);

        return $visible . str_repeat('*', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }
}

if (!function_exists('device_label')) {
    /**
     * 将 User-Agent 解析为可读的设备描述，例如「Windows · Chrome」
     */
    function device_label(?string $ua): string
    {
        $ua = (string) $ua;

        if ($ua === '') {
            return '未知设备';
        }

        $lower = strtolower($ua);

        $os = '未知系统';
        foreach ([
            'windows'   => 'Windows',
            'iphone'    => 'iPhone',
            'ipad'      => 'iPad',
            'android'   => 'Android',
            'macintosh' => 'macOS',
            'mac os x'  => 'macOS',
            'linux'     => 'Linux',
        ] as $needle => $label) {
            if (str_contains($lower, $needle)) {
                $os = $label;
                break;
            }
        }

        // 注意顺序：Edge / Opera 的 UA 同样包含 chrome，需先匹配
        $browser = '未知浏览器';
        foreach ([
            'micromessenger' => '微信',
            'edg/'           => 'Edge',
            'opr/'           => 'Opera',
            'chrome/'        => 'Chrome',
            'firefox/'       => 'Firefox',
            'safari/'        => 'Safari',
        ] as $needle => $label) {
            if (str_contains($lower, $needle)) {
                $browser = $label;
                break;
            }
        }

        return $os . ' · ' . $browser;
    }
}
