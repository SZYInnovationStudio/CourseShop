<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Log;

/**
 * 图形验证码（服务端会话存储）
 *
 * 验证码按「场景」存放在会话里，校验后立即销毁，做到一次性使用。
 * 字符集剔除了 I/O/0/1 等易混淆字符，默认不区分大小写。
 */
final class Captcha
{
    private const SESSION_KEY = '_captcha';

    /** 允许的场景 */
    private const SCENES = ['login', 'register', 'email', 'reset', 'appeal'];

    /** 不易混淆的字符集 */
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function enabled(): bool
    {
        return Setting::bool('captcha_enabled', true);
    }

    public static function normalizeScene(string $scene): string
    {
        return in_array($scene, self::SCENES, true) ? $scene : 'login';
    }

    /**
     * 生成并保存新验证码，返回明文（仅用于绘制图片）
     */
    public static function issue(string $scene): string
    {
        $scene  = self::normalizeScene($scene);
        $length = min(6, max(4, Setting::int('captcha_length', 4)));
        $max    = strlen(self::CHARSET) - 1;

        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::CHARSET[random_int(0, $max)];
        }

        $_SESSION[self::SESSION_KEY][$scene] = [
            'code'    => $code,
            'expires' => time() + max(60, Setting::int('captcha_expire_seconds', 300)),
        ];

        // 记录验证码下发（P2 验证码日志）
        Log::recordCaptcha($scene, 'issued');

        return $code;
    }

    /**
     * 该校验码是否仍在有效期内（仅用于视图判断，不消费）
     */
    public static function pending(string $scene): bool
    {
        $item = $_SESSION[self::SESSION_KEY][self::normalizeScene($scene)] ?? null;

        return is_array($item) && (int) ($item['expires'] ?? 0) > time();
    }

    /**
     * 校验并消费验证码
     */
    public static function verify(string $scene, string $input): bool
    {
        $scene = self::normalizeScene($scene);
        $item  = $_SESSION[self::SESSION_KEY][$scene] ?? null;

        unset($_SESSION[self::SESSION_KEY][$scene]);

        $ok = false;

        if (is_array($item) && (int) ($item['expires'] ?? 0) > time()) {
            $input = trim($input);

            if ($input !== '') {
                $expected = (string) $item['code'];

                $ok = Setting::bool('captcha_case_sensitive', false)
                    ? hash_equals($expected, $input)
                    : hash_equals(strtoupper($expected), strtoupper($input));
            }
        }

        // 记录验证码校验结果（P2 验证码日志）
        Log::recordCaptcha($scene, $ok ? 'passed' : 'failed');

        return $ok;
    }
}
