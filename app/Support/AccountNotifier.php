<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 账号邮件通知
 *
 * 所有通知均为「尽力而为」：邮件系统未启用、收件人未绑定/未验证邮箱
 * 或发送失败都不影响后台账号管理流程。
 */
final class AccountNotifier
{
    /**
     * 账号封禁通知
     *
     * @param string      $banType     temp（限时封禁）或 permanent（永久封禁）
     * @param string|null $bannedUntil 限时封禁的到期时间（Y-m-d H:i:s）
     * @param string|null $reason      封禁原因
     */
    public static function notifyBanned(int $userId, string $banType, ?string $bannedUntil, ?string $reason): void
    {
        if (!Mailer::enabled()) {
            return;
        }

        try {
            $user = self::recipient($userId);
            if ($user === null) {
                return;
            }

            $siteName = Setting::string('site_name', 'CourseShop');
            $banLabel = $banType === 'permanent' ? '永久封禁' : '限时封禁';

            $html = '<p>你好，' . e($user['name']) . '：</p>'
                . '<p>你的账号已被管理员处理为：<strong>' . e($banLabel) . '</strong>。</p>'
                . '<ul>';

            if ($banType === 'temp' && $bannedUntil !== null) {
                $html .= '<li>解封时间：' . e($bannedUntil) . '</li>';
            }

            if ($reason !== null && trim($reason) !== '') {
                $html .= '<li>原因：' . e($reason) . '</li>';
            }

            $html .= '</ul>'
                . '<p>如对处理结果有疑问，可登录后提交工单进行申诉。</p>'
                . '<p>—— ' . e($siteName) . '</p>';

            Mailer::send($user['email'], $siteName . ' - 账号封禁通知', $html);
        } catch (Throwable $e) {
            Logger::error('发送账号封禁通知邮件失败：' . $e->getMessage());
        }
    }

    /**
     * 密码重置通知（后台管理员重置密码后下发新密码）
     *
     * @return bool 是否成功投递（未启用邮件 / 无有效邮箱时返回 false）
     */
    public static function notifyPasswordReset(int $userId, string $password): bool
    {
        if (!Mailer::enabled()) {
            return false;
        }

        try {
            $user = self::recipient($userId);
            if ($user === null) {
                return false;
            }

            $siteName = Setting::string('site_name', 'CourseShop');

            $html = '<p>你好，' . e($user['name']) . '：</p>'
                . '<p>管理员已为你重置登录密码，新的密码为：<strong>' . e($password) . '</strong></p>'
                . '<p>为保障账号安全，请登录后立即修改密码。</p>'
                . '<p>—— ' . e($siteName) . '</p>';

            return Mailer::send($user['email'], $siteName . ' - 密码已重置', $html);
        } catch (Throwable $e) {
            Logger::error('发送密码重置通知邮件失败：' . $e->getMessage());

            return false;
        }
    }

    /**
     * 解析收件人（仅当用户存在、已绑定并验证邮箱、邮箱格式合法时返回）
     *
     * @return array{email: string, name: string}|null
     */
    private static function recipient(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = Database::first(
            'SELECT `email`, `email_verified_at`, `nickname`, `username`
               FROM `users` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$userId]
        );

        if ($row === null || ($row['email_verified_at'] ?? null) === null) {
            return null;
        }

        $email = (string) ($row['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $nickname = trim((string) ($row['nickname'] ?? ''));
        $name     = $nickname !== '' ? $nickname : (string) ($row['username'] ?? '');

        return ['email' => $email, 'name' => $name];
    }
}
