<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Mailer;
use App\Support\Queue;

/**
 * 邮件发送任务（P2）
 *
 * 由 bin/queue-worker.php 消费。后台开启「队列发送」后，Mailer::send() 不再在
 * 请求内同步阻塞 SMTP（最长 15 秒），而是入队由队列进程异步投递，
 * 避免支付回调、工单提交等入口被 SMTP 超时拖慢。
 *
 * 设计要点：
 * - 收件人缺失 / 格式非法 / 站点未启用 SMTP 属于「永久失败」，直接丢弃以避免无意义重试。
 * - SMTP 投递异常（网络抖动、限流等）向上抛出，交给 Queue::release() 做退避重试。
 */
final class MailJob
{
    /** 队列名 */
    public const QUEUE = 'mail';

    /** 任务类型标识（payload.type） */
    public const TYPE = 'send_mail';

    /**
     * 入队一封邮件
     */
    public static function dispatch(string $to, string $subject, string $html, int $delaySeconds = 0): int
    {
        return Queue::push(self::QUEUE, [
            'type'    => self::TYPE,
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
        ], $delaySeconds);
    }

    /**
     * 执行任务
     *
     * @param array<string, mixed> $payload
     */
    public static function handle(array $payload): void
    {
        $to      = (string) ($payload['to'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $html    = (string) ($payload['html'] ?? '');

        // 永久性无效：收件人为空或格式错误，直接丢弃
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // 邮件功能被关闭 / 未配置 SMTP：投递也不会成功，直接丢弃
        if (!Mailer::enabled()) {
            return;
        }

        // 失败会抛出异常，交由队列退避重试
        Mailer::deliver($to, $subject, $html);
    }
}
