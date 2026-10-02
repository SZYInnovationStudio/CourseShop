<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Ticket;

/**
 * 工单邮件通知
 *
 * 所有通知均为「尽力而为」：邮件未启用、收件人未验证邮箱或发送失败都不影响工单主流程。
 */
final class TicketNotifier
{
    /**
     * 通知工单所有者：管理员已回复
     */
    public static function notifyOwnerReplied(array $ticket, string $content): void
    {
        $to = self::ownerEmail($ticket);
        if ($to === null) {
            return;
        }

        $siteName = Setting::string('site_name', 'CourseShop');
        $url      = url('/ticket/' . (int) $ticket['id']);

        $html = '<p>你好，</p>'
            . '<p>你的工单 <strong>' . e((string) $ticket['ticket_no']) . '</strong>（'
            . e((string) $ticket['title']) . '）有了新的回复：</p>'
            . '<blockquote style="border-left:3px solid #4F6F52;padding-left:12px;color:#555;">'
            . nl2br(e($content)) . '</blockquote>'
            . '<p>查看并继续沟通：<a href="' . e($url) . '">' . e($url) . '</a></p>'
            . '<p>—— ' . e($siteName) . '</p>';

        Mailer::send($to, $siteName . ' - 工单有新回复', $html);
    }

    /**
     * 通知工单所有者：工单状态变更（含关闭）
     */
    public static function notifyOwnerStatusChanged(array $ticket): void
    {
        $to = self::ownerEmail($ticket);
        if ($to === null) {
            return;
        }

        $siteName = Setting::string('site_name', 'CourseShop');
        $url      = url('/ticket/' . (int) $ticket['id']);
        $status   = Ticket::statusLabel((string) $ticket['status']);

        $html = '<p>你好，</p>'
            . '<p>你的工单 <strong>' . e((string) $ticket['ticket_no']) . '</strong>（'
            . e((string) $ticket['title']) . '）状态已更新为：<strong>' . e($status) . '</strong>。</p>'
            . '<p>查看详情：<a href="' . e($url) . '">' . e($url) . '</a></p>'
            . '<p>—— ' . e($siteName) . '</p>';

        Mailer::send($to, $siteName . ' - 工单状态更新', $html);
    }

    /**
     * 通知全体管理员：有新工单 / 用户新回复
     */
    public static function notifyAdmins(array $ticket, string $subjectSuffix = '有新工单'): void
    {
        if (!Mailer::enabled()) {
            return;
        }

        $emails = self::adminEmails();
        if ($emails === []) {
            return;
        }

        $siteName = Setting::string('site_name', 'CourseShop');
        $url      = url('/admin/tickets/' . (int) $ticket['id']);
        $type     = Ticket::typeLabel((string) $ticket['type']);

        $html = '<p>后台待处理工单提醒：</p>'
            . '<ul>'
            . '<li>工单号：' . e((string) $ticket['ticket_no']) . '</li>'
            . '<li>类型：' . e($type) . '</li>'
            . '<li>标题：' . e((string) $ticket['title']) . '</li>'
            . '</ul>'
            . '<p>前往处理：<a href="' . e($url) . '">' . e($url) . '</a></p>'
            . '<p>—— ' . e($siteName) . '</p>';

        foreach ($emails as $email) {
            Mailer::send($email, $siteName . ' - 工单' . $subjectSuffix, $html);
        }
    }

    /**
     * 工单所有者的邮箱（仅当已绑定并验证邮箱、邮件系统可用时返回）
     */
    private static function ownerEmail(array $ticket): ?string
    {
        if (!Mailer::enabled()) {
            return null;
        }

        $userId = (int) ($ticket['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $row = Database::first(
            'SELECT `email`, `email_verified_at` FROM `users` WHERE `id` = ? LIMIT 1',
            [$userId]
        );

        if ($row === null || ($row['email_verified_at'] ?? null) === null) {
            return null;
        }

        $email = (string) ($row['email'] ?? '');

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * 全体管理员的已验证邮箱
     *
     * @return array<int, string>
     */
    private static function adminEmails(): array
    {
        $rows = Database::select(
            'SELECT `email` FROM `users`
              WHERE `is_admin` = 1 AND `email_verified_at` IS NOT NULL AND `deleted_at` IS NULL',
            []
        );

        $emails = [];

        foreach ($rows as $row) {
            $email = (string) ($row['email'] ?? '');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $email;
            }
        }

        return $emails;
    }
}
