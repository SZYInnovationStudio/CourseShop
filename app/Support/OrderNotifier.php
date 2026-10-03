<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 订单邮件通知
 *
 * 所有通知均为「尽力而为」：邮件系统未启用、收件人未绑定/未验证邮箱
 * 或发送失败都不影响订单主流程（下单、开通、完成等）。
 */
final class OrderNotifier
{
    /**
     * 支付成功 / 课程开通通知
     *
     * @param array<string, mixed> $order orders 表记录
     */
    public static function notifyPaid(array $order): void
    {
        if (!Mailer::enabled()) {
            return;
        }

        try {
            $user = self::recipient((int) ($order['user_id'] ?? 0));
            if ($user === null) {
                return;
            }

            $siteName = Setting::string('site_name', 'CourseShop');

            [$subject, $html] = I18n::withLocale($user['locale'], static function () use ($order, $user, $siteName): array {
                $html = '<p>' . t('你好，%s：', [e($user['name'])]) . '</p>'
                    . '<p>' . t('你购买的课程已开通成功。') . '</p>'
                    . '<ul>'
                    . '<li>' . t('课程：') . e((string) ($order['course_title'] ?? '')) . '</li>'
                    . '<li>' . t('订单号：') . e((string) ($order['order_no'] ?? '')) . '</li>'
                    . '<li>' . t('金额：') . '&yen;' . format_money((int) ($order['amount'] ?? 0)) . '</li>'
                    . '</ul>'
                    . '<p>' . t('现在就可以访问「我的课程」开始学习了。') . '</p>'
                    . '<p>—— ' . e($siteName) . '</p>';

                return [$siteName . ' - ' . t('课程开通通知'), $html];
            });

            Mailer::send($user['email'], $subject, $html);
        } catch (Throwable $e) {
            Logger::error('发送课程开通通知邮件失败：' . $e->getMessage());
        }
    }

    /**
     * 订单完成通知（管理员确认订单完成后发送）
     *
     * @param array<string, mixed> $order orders 表记录
     */
    public static function notifyCompleted(array $order): void
    {
        if (!Mailer::enabled()) {
            return;
        }

        try {
            $user = self::recipient((int) ($order['user_id'] ?? 0));
            if ($user === null) {
                return;
            }

            $siteName = Setting::string('site_name', 'CourseShop');
            $url      = url('/order/' . (string) ($order['order_no'] ?? ''));

            [$subject, $html] = I18n::withLocale($user['locale'], static function () use ($order, $user, $siteName, $url): array {
                $html = '<p>' . t('你好，%s：', [e($user['name'])]) . '</p>'
                    . '<p>' . t('你的订单已处理完成。') . '</p>'
                    . '<ul>'
                    . '<li>' . t('课程：') . e((string) ($order['course_title'] ?? '')) . '</li>'
                    . '<li>' . t('订单号：') . e((string) ($order['order_no'] ?? '')) . '</li>'
                    . '<li>' . t('金额：') . '&yen;' . format_money((int) ($order['amount'] ?? 0)) . '</li>'
                    . '</ul>'
                    . '<p>' . t('查看订单详情：') . '<a href="' . e($url) . '">' . e($url) . '</a></p>'
                    . '<p>—— ' . e($siteName) . '</p>';

                return [$siteName . ' - ' . t('订单完成通知'), $html];
            });

            Mailer::send($user['email'], $subject, $html);
        } catch (Throwable $e) {
            Logger::error('发送订单完成通知邮件失败：' . $e->getMessage());
        }
    }

    /**
     * 退款成功通知（管理员确认退款后发送）
     *
     * @param array<string, mixed> $order orders 表记录
     */
    public static function notifyRefunded(array $order): void
    {
        if (!Mailer::enabled()) {
            return;
        }

        try {
            $user = self::recipient((int) ($order['user_id'] ?? 0));
            if ($user === null) {
                return;
            }

            $siteName = Setting::string('site_name', 'CourseShop');
            // 退款金额优先取 refund_amount，缺失时回退订单金额
            $amount   = (int) ($order['refund_amount'] ?? $order['amount'] ?? 0);

            [$subject, $html] = I18n::withLocale($user['locale'], static function () use ($order, $user, $siteName, $amount): array {
                $html = '<p>' . t('你好，%s：', [e($user['name'])]) . '</p>'
                    . '<p>' . t('你的订单已完成退款，课程访问权限已同步关闭。') . '</p>'
                    . '<ul>'
                    . '<li>' . t('课程：') . e((string) ($order['course_title'] ?? '')) . '</li>'
                    . '<li>' . t('订单号：') . e((string) ($order['order_no'] ?? '')) . '</li>'
                    . '<li>' . t('退款金额：') . '&yen;' . format_money($amount) . '</li>'
                    . '</ul>'
                    . '<p>' . t('如有疑问请联系客服。') . '</p>'
                    . '<p>—— ' . e($siteName) . '</p>';

                return [$siteName . ' - ' . t('订单退款通知'), $html];
            });

            Mailer::send($user['email'], $subject, $html);
        } catch (Throwable $e) {
            Logger::error('发送订单退款通知邮件失败：' . $e->getMessage());
        }
    }

    /**
     * 解析收件人（仅当用户存在、已绑定并验证邮箱、邮箱格式合法时返回）
     *
     * @return array{email: string, name: string, locale: string}|null
     */
    private static function recipient(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = Database::first(
            'SELECT `email`, `email_verified_at`, `nickname`, `username`, `locale`
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
        $locale   = (string) ($row['locale'] ?? '');

        return [
            'email'  => $email,
            'name'   => $name,
            'locale' => $locale !== '' ? $locale : I18n::DEFAULT_LOCALE,
        ];
    }
}
