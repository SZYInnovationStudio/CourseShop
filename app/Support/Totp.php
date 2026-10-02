<?php

declare(strict_types=1);

namespace App\Support;

/**
 * TOTP（基于时间的一次性密码，RFC 6238）
 *
 * 纯 PHP 实现，不依赖任何扩展与第三方库，用于两步验证（2FA）。
 * 默认参数与 Google Authenticator / Microsoft Authenticator / 1Password 等
 * 主流验证器一致：SHA-1、6 位数字、30 秒步长。
 */
final class Totp
{
    /** 验证码位数 */
    private const DIGITS = 6;

    /** 时间步长（秒） */
    private const PERIOD = 30;

    /** 校验时允许前后偏移的时间窗口（步数），用于容忍客户端时钟误差 */
    public const WINDOW = 1;

    /** Base32 字母表（RFC 4648，去掉易混淆的 1/0 也可，这里保留标准字母表） */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 生成一个新的随机密钥（Base32 编码，长度 32 字符 = 160 位）
     */
    public static function generateSecret(int $length = 32): string
    {
        $bytes = random_bytes((int) ceil($length * 5 / 8));
        $secret = self::base32Encode($bytes);

        return substr($secret, 0, $length);
    }

    /**
     * 计算指定时间点的一次性密码
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter   = (int) floor($timestamp / self::PERIOD);

        // 计数器为大端 64 位整数
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $key           = self::base32Decode($secret);

        $hash   = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 校验一次性密码，并返回匹配到的时间步（允许前后 WINDOW 个时间步的误差）
     *
     * 使用 hash_equals 进行恒时比较，避免时序侧信道。
     * 返回值用于「一次性防重放」：调用方记录该时间步，拒绝同一用户重复使用同一时间步。
     *
     * @return int|null 匹配到的时间步（自 Unix 纪元起按 PERIOD 划分的计数），未匹配返回 null
     */
    public static function verifyStep(string $secret, string $code, int $window = self::WINDOW): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }

        if ($secret === '') {
            return null;
        }

        $now = time();

        for ($i = -$window; $i <= $window; $i++) {
            $timestamp = $now + $i * self::PERIOD;
            $expected  = self::code($secret, $timestamp);

            if (hash_equals($expected, $code)) {
                return (int) floor($timestamp / self::PERIOD);
            }
        }

        return null;
    }

    /**
     * 生成供验证器扫码 / 手动录入的 otpauth 链接
     *
     * @param string $secret  用户密钥（Base32）
     * @param string $account 账号标识（一般为用户名或邮箱）
     * @param string $issuer  发行方名称（站点名）
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);

        return 'otpauth://totp/' . $label . '?'
            . http_build_query([
                'secret'    => $secret,
                'issuer'    => $issuer,
                'algorithm' => 'SHA1',
                'digits'    => self::DIGITS,
                'period'    => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 将密钥按 4 位分组，便于用户手动录入
     */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split(strtoupper($secret), 4, ' '));
    }

    // ============================================================
    // Base32 编解码（RFC 4648）
    // ============================================================

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            // 不足 5 位时右侧补 0
            $chunk   = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $result;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        if ($secret === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);

            if ($index === false) {
                continue;
            }

            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($binary, 8) as $chunk) {
            // 丢弃不足 8 位的尾部填充
            if (strlen($chunk) < 8) {
                break;
            }

            $result .= chr(bindec($chunk));
        }

        return $result;
    }
}
