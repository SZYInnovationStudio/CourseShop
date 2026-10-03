<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\MailJob;
use RuntimeException;
use Throwable;

/**
 * 原生 SMTP 邮件发送
 *
 * 不依赖任何第三方库（不使用 PHPMailer / Composer），
 * 支持 ssl（SMTPS，通常 465 端口）、tls（STARTTLS，通常 587 端口）与 none 三种加密方式。
 * 所有参数均来自后台系统设置，发送结果写入 mail_logs。
 *
 * P2：支持「队列异步发送」与「同步发送」两种模式。
 * - 开启 mail_queue_enabled 时，普通邮件（订单通知、工单通知等）入队，
 *   由 bin/queue-worker.php 异步投递，避免支付回调等入口被 SMTP 超时拖慢。
 * - 验证码类邮件始终同步发送（用户在等待，时效性要求高）。
 */
final class Mailer
{
    /** SMTP 超时（秒） */
    private const TIMEOUT = 15;

    /** 是否已正确配置并启用邮件发送 */
    public static function enabled(): bool
    {
        return Setting::bool('mail_enabled', false) && Setting::string('mail_host') !== '';
    }

    /** 是否使用队列异步发送（需运行 bin/queue-worker.php） */
    public static function queueEnabled(): bool
    {
        return Setting::bool('mail_queue_enabled', true);
    }

    /**
     * 发送一封 HTML 邮件，失败返回 false（不抛异常，避免影响主流程）
     *
     * 开启队列时仅入队即返回 true（真正的投递结果见 mail_logs）；
     * 入队异常时自动回退为同步发送，保证邮件不丢。
     */
    public static function send(string $to, string $subject, string $html): bool
    {
        if (!self::validate($to, $subject)) {
            return false;
        }

        if (self::queueEnabled()) {
            try {
                MailJob::dispatch($to, $subject, $html);

                // 入队后按节流策略拉起一次「处理完即退出」的消费者，避免邮件长时间滞留
                QueueRunner::trigger();

                return true;
            } catch (Throwable $e) {
                // 入队失败（如数据库异常）：回退同步发送
                Logger::warning('邮件入队失败，改为同步发送：' . $e->getMessage());
            }
        }

        try {
            self::deliver($to, $subject, $html);

            return true;
        } catch (Throwable $e) {
            // deliver() 内部已写入失败日志
            return false;
        }
    }

    /**
     * 发送邮箱验证码邮件
     *
     * 验证码邮件始终同步发送：用户在页面上等待结果，走队列会引入不确定延迟。
     */
    public static function sendCode(string $to, string $code, string $scene = 'bind'): bool
    {
        $siteName = Setting::string('site_name', 'CourseShop');
        $locale   = self::recipientLocale($to);

        [$subject, $html] = I18n::withLocale($locale, static function () use ($code, $scene, $siteName): array {
            $ttlMinutes = (int) ceil(max(60, Setting::int('mail_code_ttl', 600)) / 60);
            $action     = $scene === 'reset'
                ? t('找回密码')
                : ($scene === 'register' ? t('注册') : t('绑定邮箱'));

            $subject = $siteName . ' - ' . t('邮箱验证码');

            $html = '<p>' . t('你好，') . '</p>'
                . '<p>' . t('你正在执行「%s」操作，本次验证码为：', [e($action)]) . '</p>'
                . '<p style="font-size:24px;font-weight:700;letter-spacing:6px;color:#4F6F52;">' . e($code) . '</p>'
                . '<p>' . t('验证码 %d 分钟内有效，请勿泄露给他人。', [$ttlMinutes]) . '</p>'
                . '<p>' . t('如果这不是你本人的操作，请忽略本邮件。') . '</p>'
                . '<p>—— ' . e($siteName) . '</p>';

            return [$subject, $html];
        });

        if (!self::validate($to, $subject)) {
            return false;
        }

        try {
            self::deliver($to, $subject, $html);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 立即投递一封 HTML 邮件（同步，失败抛异常）
     *
     * 由 send() 的同步路径与队列任务 MailJob::handle() 调用，
     * 失败时写入 mail_logs 并向上抛出，供队列退避重试。
     */
    public static function deliver(string $to, string $subject, string $html): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || !self::enabled()) {
            throw new RuntimeException('收件人邮箱无效或站点未启用邮件发送。');
        }

        $from     = self::sanitizeHeader((string) (Setting::string('mail_from_address') ?: Setting::string('mail_username')));
        $fromName = self::sanitizeHeader((string) (Setting::string('mail_from_name') ?: Setting::string('site_name', 'CourseShop')));

        // 发件地址来自后台设置，必须校验，避免邮件头 / SMTP 命令注入（CRLF）
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('发件人邮箱配置无效。');
        }

        try {
            self::smtpDeliver($to, $subject, $html, $from, $fromName);
            self::log($to, $subject, 'success');
        } catch (Throwable $e) {
            self::log($to, $subject, 'fail', $e->getMessage());
            Logger::error('发送邮件失败：' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * 校验收件人与站点配置，不通过时写入失败日志并返回 false
     */
    private static function validate(string $to, string $subject): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            self::log($to, $subject, 'fail', '收件人邮箱格式不正确');

            return false;
        }

        if (!self::enabled()) {
            self::log($to, $subject, 'fail', '站点未启用邮件发送或未配置 SMTP 主机');

            return false;
        }

        return true;
    }

    /**
     * 完成一次完整的 SMTP 投递（失败抛异常）
     */
    private static function smtpDeliver(string $to, string $subject, string $html, string $from, string $fromName): void
    {
        $host       = Setting::string('mail_host');
        $port       = max(1, Setting::int('mail_port', 465));
        $encryption = strtolower(Setting::string('mail_encryption', 'ssl'));
        $username   = Setting::string('mail_username');
        $password   = Setting::string('mail_password');

        $remote    = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno     = 0;
        $errstr    = '';

        $socket = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT);

        if ($socket === false) {
            throw new RuntimeException(sprintf('无法连接 SMTP 服务器 %s:%d（%s）', $host, $port, $errstr !== '' ? $errstr : (string) $errno));
        }

        stream_set_timeout($socket, self::TIMEOUT);

        try {
            $hostname = self::clientHostname();

            self::expect($socket, [220]);
            self::command($socket, 'EHLO ' . $hostname, [250]);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);

                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('STARTTLS 加密协商失败');
                }

                self::command($socket, 'EHLO ' . $hostname, [250]);
            }

            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode($password), [235]);
            }

            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);

            $body = self::buildMessage($to, $subject, $html, $from, $fromName);
            // SMTP 点透明：行首的「.」需要写成「..」
            $body = (string) preg_replace('/^\./m', '..', $body);

            fwrite($socket, $body . "\r\n.\r\n");
            self::expect($socket, [250]);

            self::command($socket, 'QUIT', [221]);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * @param resource             $socket
     * @param array<int, int>      $okCodes
     * @return array{0: int, 1: string}
     */
    private static function command($socket, string $command, array $okCodes): array
    {
        fwrite($socket, $command . "\r\n");

        return self::expect($socket, $okCodes);
    }

    /**
     * @param resource        $socket
     * @param array<int, int> $okCodes
     * @return array{0: int, 1: string}
     */
    private static function expect($socket, array $okCodes): array
    {
        [$code, $message] = self::readResponse($socket);

        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException(sprintf('SMTP 响应异常（%d）：%s', $code, $message));
        }

        return [$code, $message];
    }

    /**
     * 读取一条（可能多行的）SMTP 响应
     *
     * @param resource $socket
     * @return array{0: int, 1: string}
     */
    private static function readResponse($socket): array
    {
        $code    = 0;
        $message = '';

        while (($line = fgets($socket, 1024)) !== false) {
            $message .= $line;

            if (preg_match('/^(\d{3})([ -])/', $line, $matched) === 1) {
                $code = (int) $matched[1];
                if ($matched[2] === ' ') {
                    break;
                }
            }
        }

        if ($code === 0) {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('SMTP 服务器响应超时');
            }

            throw new RuntimeException('SMTP 服务器无响应或连接被中断');
        }

        return [$code, trim($message)];
    }

    /**
     * 组装符合 MIME 规范的邮件正文
     */
    private static function buildMessage(string $to, string $subject, string $html, string $from, string $fromName): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::clientHostname() . '>',
        ];

        return implode("\r\n", $headers)
            . "\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n");
    }

    private static function encodeName(string $name): string
    {
        $name = self::sanitizeHeader($name);

        return $name === '' ? '' : '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    /**
     * 移除值中的 CR / LF，防止邮件头或 SMTP 命令注入。
     */
    private static function sanitizeHeader(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    /**
     * EHLO 阶段使用的本机标识
     */
    private static function clientHostname(): string
    {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            $host = (string) gethostname();
        }

        $host = (string) preg_replace('/[^A-Za-z0-9.\-]/', '', $host);

        return $host === '' ? 'localhost' : $host;
    }

    /**
     * 依据收件邮箱解析语言偏好（未注册或未设置时回退当前语言）
     */
    private static function recipientLocale(string $email): string
    {
        try {
            $row = Database::first(
                'SELECT `locale` FROM `users` WHERE `email` = ? AND `deleted_at` IS NULL LIMIT 1',
                [$email]
            );

            $code = (string) ($row['locale'] ?? '');
            if ($code !== '' && I18n::isEnabled($code)) {
                return $code;
            }
        } catch (Throwable $e) {
            // 查询失败不影响发信，回退当前语言
        }

        return I18n::current();
    }

    private static function log(string $to, string $subject, string $status, string $error = ''): void
    {
        try {
            Database::execute(
                'INSERT INTO `mail_logs` (`to_email`, `subject`, `status`, `error`, `created_at`)
                 VALUES (?, ?, ?, ?, NOW())',
                [$to, mb_substr($subject, 0, 255), $status, $error]
            );
        } catch (Throwable $e) {
            Logger::warning('写入邮件日志失败：' . $e->getMessage());
        }
    }
}
