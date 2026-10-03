<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Throwable;

/**
 * 多语言（i18n）
 *
 * 语言标识：zh-CN（简体中文，默认）/ zh-TW（繁體中文）/ en / es / ja。
 * 译文以「中文原文」为键，存放于 lang/{locale}.php，缺失时回退中文原文。
 *
 * 当前语言识别优先级：
 *   登录用户账号偏好 users.locale → 会话 → Cookie → 浏览器 Accept-Language → 站点默认语言。
 * 仅当后台「多语言」设置中启用的语言才会参与识别与外显。
 */
final class I18n
{
    /** 语言 Cookie 名 */
    public const COOKIE = 'locale';

    /** 会话键名 */
    public const SESSION_KEY = 'locale';

    /** 默认语言 */
    public const DEFAULT_LOCALE = 'zh-CN';

    /** Cookie 有效期（秒）：1 年 */
    private const COOKIE_TTL = 31536000;

    /**
     * 支持的语言：code => [母语名称, html lang, 浏览器语言别名]
     *
     * @var array<string, array{label: string, html: string, aliases: array<int, string>}>
     */
    private const SUPPORTED = [
        'zh-CN' => ['label' => '简体中文', 'html' => 'zh-CN', 'aliases' => ['zh', 'zh-cn', 'zh-hans', 'zh-sg', 'zh-my']],
        'zh-TW' => ['label' => '繁體中文', 'html' => 'zh-TW', 'aliases' => ['zh-tw', 'zh-hk', 'zh-mo', 'zh-hant', 'zh-hant-hk']],
        'en'    => ['label' => 'English', 'html' => 'en', 'aliases' => ['en', 'en-us', 'en-gb', 'en-au', 'en-ca', 'en-nz']],
        'es'    => ['label' => 'Español', 'html' => 'es', 'aliases' => ['es', 'es-es', 'es-mx', 'es-ar', 'es-co', 'es-cl', 'es-pe', 'es-419']],
        'ja'    => ['label' => '日本語', 'html' => 'ja', 'aliases' => ['ja', 'ja-jp']],
    ];

    private static ?string $current = null;

    /** @var array<string, array<string, string>> 已载入的词典缓存 */
    private static array $dictionaries = [];

    /**
     * 全部支持的语言
     *
     * @return array<string, array{label: string, html: string, aliases: array<int, string>}>
     */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    /**
     * 全部支持的语言标识
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::SUPPORTED[$code]);
    }

    /**
     * 后台已启用的语言（至少一个；配置异常时回退默认语言）
     *
     * @return array<int, string>
     */
    public static function enabled(): array
    {
        $raw  = (string) Setting::get('i18n_locales', self::DEFAULT_LOCALE);
        $list = [];

        foreach (explode(',', $raw) as $item) {
            $item = trim($item);

            if ($item !== '' && self::isSupported($item) && !in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        if ($list === []) {
            $list[] = self::defaultLocale();
        }

        return $list;
    }

    public static function isEnabled(string $code): bool
    {
        return self::isSupported($code) && in_array($code, self::enabled(), true);
    }

    /**
     * 站点默认语言（未启用时回退到第一个启用语言）
     */
    public static function defaultLocale(): string
    {
        $code = (string) Setting::get('i18n_default_locale', self::DEFAULT_LOCALE);

        if (!self::isSupported($code)) {
            return self::DEFAULT_LOCALE;
        }

        return $code;
    }

    /**
     * 语言母语名称
     */
    public static function label(string $code): string
    {
        return self::SUPPORTED[$code]['label'] ?? $code;
    }

    /**
     * <html lang="..."> 使用的语言标记
     */
    public static function htmlLang(?string $code = null): string
    {
        $code = $code ?? self::current();

        return self::SUPPORTED[$code]['html'] ?? self::SUPPORTED[self::DEFAULT_LOCALE]['html'];
    }

    /**
     * 当前语言
     */
    public static function current(): string
    {
        if (self::$current === null) {
            self::$current = self::detect();
        }

        return self::$current;
    }

    /**
     * 临时切换语言执行回调（用于邮件按收件人语言渲染，返回后恢复）
     */
    public static function withLocale(string $code, callable $callback): mixed
    {
        $previous = self::current();
        self::$current = self::isSupported($code) ? $code : $previous;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * 强制切换当前语言（不写 Cookie / 不写账号偏好）
     *
     * 用于后台管理界面等需要固定语言的场景；仅接受受支持的语言标识。
     */
    public static function force(string $code): void
    {
        if (self::isSupported($code)) {
            self::$current = $code;
        }
    }

    /**
     * 应用启动时调用：解析当前语言，并让 Cookie 与解析结果保持一致，
     * 避免每次访问都重新探测浏览器语言。
     */
    public static function boot(): void
    {
        $current = self::current();

        if ((string) ($_COOKIE[self::COOKIE] ?? '') !== $current) {
            self::writeCookie($current);
        }
    }

    /**
     * 记录用户的语言选择：会话 + Cookie +（已登录时）账号偏好
     */
    public static function remember(string $code): bool
    {
        if (!self::isEnabled($code)) {
            return false;
        }

        self::$current = $code;

        Session::set(self::SESSION_KEY, $code);
        self::writeCookie($code);

        try {
            $user = Auth::user();

            if (is_array($user) && (int) ($user['id'] ?? 0) > 0) {
                User::updateLocale((int) $user['id'], $code);
                Auth::refresh();
            }
        } catch (Throwable $e) {
            // 账号偏好写入失败不影响本次切换（Cookie / 会话已生效）
            Logger::warning('保存语言偏好失败：' . $e->getMessage());
        }

        return true;
    }

    /**
     * 翻译：以中文原文为键，未命中或当前为默认语言时原样返回。
     *
     * @param array<int, mixed> $args 用于 vsprintf 的占位参数
     */
    public static function translate(string $text, array $args = []): string
    {
        if ($text === '') {
            return $text;
        }

        $result = $text;

        if (self::current() !== self::DEFAULT_LOCALE) {
            $dictionary = self::dictionary(self::current());

            if (isset($dictionary[$text]) && $dictionary[$text] !== '') {
                $result = (string) $dictionary[$text];
            }
        }

        if ($args !== []) {
            try {
                return vsprintf($result, $args);
            } catch (Throwable $e) {
                // 占位符与参数不匹配时退回未格式化文本，避免直接报错
                return $result;
            }
        }

        return $result;
    }

    /**
     * 指定语言是否存在某条译文
     */
    public static function hasTranslation(string $text): bool
    {
        if (self::current() === self::DEFAULT_LOCALE) {
            return true;
        }

        return isset(self::dictionary(self::current())[$text]);
    }

    /**
     * 载入指定语言的词典（不存在时返回空数组）
     *
     * @return array<string, string>
     */
    public static function dictionary(string $locale): array
    {
        if (isset(self::$dictionaries[$locale])) {
            return self::$dictionaries[$locale];
        }

        $file = LANG_PATH . '/' . $locale . '.php';
        $data = [];

        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $data = $loaded;
            }
        }

        return self::$dictionaries[$locale] = $data;
    }

    /**
     * 语言切换地址（携带回跳参数）
     */
    public static function switchUrl(string $code): string
    {
        return url('/lang/' . rawurlencode($code)) . '?redirect=' . rawurlencode(self::currentUrl());
    }

    /**
     * 当前请求的站内地址（含查询串），已做开放重定向防护
     */
    public static function currentUrl(): string
    {
        return self::safeRedirect((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    /**
     * 将回跳目标限制为站内路径，杜绝开放重定向
     */
    public static function safeRedirect(string $target): string
    {
        $target = trim(str_replace(["\r", "\n", "\0"], '', $target));

        if ($target === '' || $target[0] !== '/') {
            return '/';
        }

        if (str_starts_with($target, '//') || str_contains($target, '\\')) {
            return '/';
        }

        return $target;
    }

    /**
     * 解析当前语言
     */
    private static function detect(): string
    {
        $enabled = self::enabled();

        // 1) 登录用户账号偏好
        try {
            $user = Auth::user();
        } catch (Throwable $e) {
            $user = null;
        }

        if (is_array($user)) {
            $code = (string) ($user['locale'] ?? '');
            if ($code !== '' && in_array($code, $enabled, true)) {
                return $code;
            }
        }

        // 2) 会话（本次访问内已选择）
        $session = (string) Session::get(self::SESSION_KEY, '');
        if ($session !== '' && in_array($session, $enabled, true)) {
            return $session;
        }

        // 3) Cookie
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($cookie !== '' && in_array($cookie, $enabled, true)) {
            return $cookie;
        }

        // 4) 浏览器语言
        $browser = self::fromBrowser($enabled);
        if ($browser !== null) {
            return $browser;
        }

        // 5) 站点默认语言
        $default = self::defaultLocale();

        return in_array($default, $enabled, true) ? $default : $enabled[0];
    }

    /**
     * 依据 Accept-Language 匹配已启用语言
     *
     * @param array<int, string> $enabled
     */
    private static function fromBrowser(array $enabled): ?string
    {
        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));

            if ($tag === '') {
                continue;
            }

            $code = self::mapTag($tag);

            if ($code !== null && in_array($code, $enabled, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * 浏览器语言标记 → 支持的语言标识（取最长别名前缀匹配，兼容 zh-Hant-HK 等）
     */
    private static function mapTag(string $tag): ?string
    {
        $best    = null;
        $bestLen = 0;

        foreach (self::SUPPORTED as $code => $meta) {
            foreach ($meta['aliases'] as $alias) {
                if (($tag === $alias || str_starts_with($tag, $alias . '-')) && strlen($alias) > $bestLen) {
                    $best    = $code;
                    $bestLen = strlen($alias);
                }
            }
        }

        return $best;
    }

    private static function writeCookie(string $code): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $code, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'secure'   => Request::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::COOKIE] = $code;
    }
}
