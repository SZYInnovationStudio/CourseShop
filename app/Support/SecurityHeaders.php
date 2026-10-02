<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 全局安全响应头
 *
 * 在入口处统一下发，覆盖前台与后台所有动态请求：
 * - X-Content-Type-Options: 禁止浏览器 MIME 嗅探
 * - X-Frame-Options: 禁止被第三方页面嵌套（防点击劫持）
 * - Referrer-Policy: 跨站时仅发送来源域名
 * - Content-Security-Policy: 限制资源加载来源
 * - Strict-Transport-Security: 仅在 HTTPS 下下发（强制后续使用 HTTPS）
 *
 * 说明：受限于当前「原生 PHP + 内联脚本/样式」的实现方式：
 * - 内联 <script> 通过每次请求生成的 nonce 放行，script-src 不再依赖 'unsafe-inline'；
 * - 内联 style 属性（如进度条宽度）无法携带 nonce，style-src 仍需保留 'unsafe-inline'。
 * 站点管理员可在后台「安全响应头」分组中调整策略或整体关闭。
 */
final class SecurityHeaders
{
    /** 默认内容安全策略（在后台未自定义时使用） */
    public const DEFAULT_CSP = "default-src 'self'; "
        // hls.js 按需从 jsDelivr 懒加载（Safari/iOS 走原生 HLS，不加载），故放行该 CDN 脚本源
        . "script-src 'self' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: https:; "
        . "font-src 'self' data:; "
        . "media-src 'self' blob: https:; "
        . "connect-src 'self'; "
        . "worker-src 'self' blob:; "
        . "manifest-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'self'";

    /** HSTS 有效期（秒），180 天 */
    public const HSTS_MAX_AGE = 15552000;

    /** 当前请求的 CSP nonce（进程内单例） */
    private static ?string $nonce = null;

    /**
     * 当前请求的 CSP nonce（供内联 <script> 使用，同一请求内保持不变）
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        }

        return self::$nonce;
    }

    /**
     * 下发全部安全响应头（在入口处、任何输出之前调用）
     */
    public static function apply(): void
    {
        // 已有输出或并非 Web 环境时不做任何处理
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        // 基线安全头始终下发，避免被误关后站点裸奔
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // 允许后台自定义 CSP；留空则回退到默认策略
        $csp = trim(Setting::string('security_csp', self::DEFAULT_CSP));
        if ($csp !== '') {
            header('Content-Security-Policy: ' . self::withScriptNonce($csp, self::nonce()));
        }

        // 仅在 HTTPS 下下发 HSTS，避免本地 HTTP 开发被强制跳转
        if (self::isSecure()) {
            header('Strict-Transport-Security: max-age=' . self::HSTS_MAX_AGE . '; includeSubDomains');
        }
    }

    /**
     * 为 CSP 中的 script-src 指令注入本次请求的 nonce
     *
     * 处理规则：
     * - 存在 script-src / script-src-elem 时，去除其中的 'unsafe-inline' 并追加 'nonce-xxx'；
     * - 不存在 script-src 时不改动策略（由 default-src 决定，避免破坏管理员自定义配置）。
     */
    private static function withScriptNonce(string $csp, string $nonce): string
    {
        $directives = array_values(array_filter(
            array_map('trim', explode(';', $csp)),
            static fn (string $directive): bool => $directive !== ''
        ));

        $patched = false;

        foreach ($directives as $index => $directive) {
            $parts = preg_split('/\s+/', $directive) ?: [];
            $name  = strtolower((string) array_shift($parts));

            if ($name !== 'script-src' && $name !== 'script-src-elem') {
                continue;
            }

            $patched = true;
            $values  = array_values(array_filter(
                $parts,
                static fn (string $part): bool => strtolower($part) !== "'unsafe-inline'"
            ));
            $values[] = "'nonce-" . $nonce . "'";

            $directives[$index] = $name . ' ' . implode(' ', $values);
        }

        if (!$patched) {
            return $csp;
        }

        return implode('; ', $directives);
    }

    /**
     * 当前请求是否走 HTTPS（兼容可信反向代理）
     */
    private static function isSecure(): bool
    {
        return Request::isSecure();
    }
}
