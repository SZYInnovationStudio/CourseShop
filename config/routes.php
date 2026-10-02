<?php

declare(strict_types=1);

/**
 * 路由表
 *
 * 处理器写法：[控制器类名, 方法名]，或闭包。
 * 中间件：'auth'（需登录）、'guest'（仅未登录）、'admin'（需管理员）、'email_verified'（需已绑定邮箱）。
 */

use App\Controllers\AccountController;
use App\Controllers\Admin\AgreementController as AdminAgreementController;
use App\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Controllers\Admin\ChapterController as AdminChapterController;
use App\Controllers\Admin\CourseController as AdminCourseController;
use App\Controllers\Admin\CouponController as AdminCouponController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\LogController as AdminLogController;
use App\Controllers\Admin\OrderController as AdminOrderController;
use App\Controllers\Admin\PackageController as AdminPackageController;
use App\Controllers\Admin\RefundController as AdminRefundController;
use App\Controllers\Admin\ReportController as AdminReportController;
use App\Controllers\Admin\RoleController as AdminRoleController;
use App\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Controllers\Admin\TicketController as AdminTicketController;
use App\Controllers\Admin\UserController as AdminUserController;
use App\Controllers\AgreementController;
use App\Controllers\AuthController;
use App\Controllers\CaptchaController;
use App\Controllers\CourseController;
use App\Controllers\HomeController;
use App\Controllers\MyCourseController;
use App\Controllers\OrderController;
use App\Controllers\PackageController;
use App\Controllers\PasswordController;
use App\Controllers\PaymentController;
use App\Controllers\TicketController;
use App\Controllers\VideoController;
use App\Support\Router;
use App\Support\Setting;

return static function (Router $router): void {
    // ---------------- 前台：首页 ----------------
    $router->get('/', [HomeController::class, 'index']);

    // ---------------- PWA ----------------
    // 动态输出 Web App Manifest，使应用名称、主题色与后台配置保持一致
    $router->get('/manifest.webmanifest', static function (): void {
        $name    = Setting::string('site_name', 'CourseShop');
        $primary = Setting::string('theme_primary_color', '#4F6F52');

        $manifest = [
            'name'             => $name,
            'short_name'       => mb_substr($name, 0, 12),
            'description'      => Setting::string('site_description', ''),
            'lang'             => 'zh-CN',
            'start_url'        => './',
            'scope'            => './',
            'display'          => 'standalone',
            'background_color' => '#ffffff',
            'theme_color'      => $primary,
            'icons'            => [
                ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => 'assets/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('Cache-Control: no-cache');

        echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });

    // ---------------- 前台：课程 ----------------
    $router->get('/courses', [CourseController::class, 'index']);
    $router->get('/course/{id}', [CourseController::class, 'show']);

    // ---------------- 前台：套餐 ----------------
    $router->get('/packages', [PackageController::class, 'index']);
    $router->get('/package/{id}', [PackageController::class, 'show']);

    // ---------------- 认证 ----------------
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest']);
    $router->get('/register', [AuthController::class, 'showRegister'], ['guest']);
    $router->post('/register', [AuthController::class, 'register'], ['guest']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth']);

    // 两步验证（2FA）：登录流程的第二步，此时尚未写入登录态，故用 guest 中间件
    $router->get('/login/2fa', [AuthController::class, 'showTwoFactor'], ['guest']);
    $router->post('/login/2fa', [AuthController::class, 'verifyTwoFactor'], ['guest']);

    // 找回密码（未登录可用）：先发送邮箱验证码，再校验并设置新密码
    $router->get('/password/forgot', [PasswordController::class, 'showForgot'], ['guest']);
    $router->post('/password/forgot', [PasswordController::class, 'sendResetCode'], ['guest']);
    $router->get('/password/reset', [PasswordController::class, 'showReset'], ['guest']);
    $router->post('/password/reset', [PasswordController::class, 'reset'], ['guest']);

    // 图形验证码图片
    $router->get('/captcha', [CaptchaController::class, 'image']);

    // ---------------- 账户中心 ----------------
    $router->get('/account', [AccountController::class, 'index'], ['auth']);
    $router->post('/account', [AccountController::class, 'updateProfile'], ['auth']);
    $router->post('/account/password', [AccountController::class, 'updatePassword'], ['auth']);
    $router->post('/account/theme', [AccountController::class, 'saveTheme'], ['auth']);
    $router->post('/account/delete', [AccountController::class, 'destroy'], ['auth']);
    $router->get('/account/email', [AccountController::class, 'showEmail'], ['auth']);
    $router->post('/account/email/send', [AccountController::class, 'sendEmailCode'], ['auth']);
    $router->post('/account/email/verify', [AccountController::class, 'verifyEmailCode'], ['auth']);

    // 登录安全：两步验证（2FA）与登录设备管理
    $router->get('/account/2fa/setup', [AccountController::class, 'showTwoFactorSetup'], ['auth']);
    $router->post('/account/2fa/enable', [AccountController::class, 'enableTwoFactor'], ['auth']);
    $router->post('/account/2fa/disable', [AccountController::class, 'disableTwoFactor'], ['auth']);
    $router->post('/account/devices/revoke-others', [AccountController::class, 'revokeOtherDevices'], ['auth']);
    $router->post('/account/devices/{id}/revoke', [AccountController::class, 'revokeDevice'], ['auth']);

    // ---------------- 协议 ----------------
    $router->get('/agreements/{type}', [AgreementController::class, 'show']);

    // ---------------- 订单与支付 ----------------
    $router->post('/order/create', [OrderController::class, 'create'], ['auth']);
    $router->get('/orders', [OrderController::class, 'index'], ['auth']);
    $router->get('/order/{orderNo}', [OrderController::class, 'show'], ['auth']);
    $router->post('/order/{orderNo}/pay', [OrderController::class, 'pay'], ['auth']);
    $router->post('/order/{orderNo}/coupon', [OrderController::class, 'applyCoupon'], ['auth']);
    $router->get('/order/{orderNo}/status', [OrderController::class, 'status'], ['auth']);

    // ---------------- 我的课程与学习 ----------------
    $router->get('/my/courses', [MyCourseController::class, 'index'], ['auth']);
    // 未带章节号时跳转到第一个可播放章节，避免直接 404
    $router->get('/course/{courseId}/learn', [MyCourseController::class, 'learnEntry'], ['auth']);
    $router->get('/course/{courseId}/learn/{chapterId}', [MyCourseController::class, 'learn'], ['auth']);
    $router->post('/course/{courseId}/learn/{chapterId}/progress', [MyCourseController::class, 'saveProgress'], ['auth']);
    // 视频流：登录 + 签名双重校验，签名校验在控制器内完成（防盗链）
    $router->get('/course/{courseId}/learn/{chapterId}/video', [VideoController::class, 'stream'], ['auth']);
    // HLS 播放列表与分片：{file} 允许 index.m3u8 / seg-000.ts 等带点号文件名
    $router->get('/course/{courseId}/learn/{chapterId}/hls/{file}', [VideoController::class, 'hls'], ['auth']);

    // ---------------- 工单与申诉 ----------------
    // 注意：静态段（/tickets、/ticket/create、/ticket/appeal、/ticket/attachment/{id}）
    // 必须先于通配段 /ticket/{id} 声明，否则会被后者抢先匹配。
    // 账号申诉为公开入口（封禁用户无法登录），不挂 auth 中间件。
    $router->get('/ticket/appeal', [TicketController::class, 'showAppeal']);
    $router->post('/ticket/appeal', [TicketController::class, 'submitAppeal']);
    $router->get('/tickets', [TicketController::class, 'index'], ['auth']);
    $router->get('/ticket/create', [TicketController::class, 'create'], ['auth']);
    $router->post('/ticket', [TicketController::class, 'store'], ['auth']);
    $router->get('/ticket/attachment/{id}', [TicketController::class, 'downloadAttachment'], ['auth']);
    $router->post('/ticket/{id}/reply', [TicketController::class, 'reply'], ['auth']);
    $router->post('/ticket/{id}/close', [TicketController::class, 'close'], ['auth']);
    $router->post('/ticket/{id}/delete', [TicketController::class, 'destroy'], ['auth']);
    $router->get('/ticket/{id}', [TicketController::class, 'show'], ['auth']);

    // 支付网关回调：不带会话，不做登录校验与 CSRF 校验，安全性由签名与金额校验保证
    $router->get('/order/{orderNo}/return', [PaymentController::class, 'epayReturn']);
    $router->get('/payment/notify/epay', [PaymentController::class, 'epayNotify']);
    $router->post('/payment/notify/epay', [PaymentController::class, 'epayNotify']);

    // ---------------- 后台管理 ----------------
    // 后台使用独立登录入口，避免与前台登录态混淆
    $router->get('/admin/login', [AdminAuthController::class, 'showLogin']);
    $router->post('/admin/login', [AdminAuthController::class, 'login']);
    $router->get('/admin/login/2fa', [AdminAuthController::class, 'showTwoFactor']);
    $router->post('/admin/login/2fa', [AdminAuthController::class, 'verifyTwoFactor']);
    $router->post('/admin/logout', [AdminAuthController::class, 'logout'], ['admin']);
    $router->get('/admin', [DashboardController::class, 'index'], ['admin', 'permission:dashboard.view']);

    // 经营报表（静态段 /admin/reports/export 需先于 /admin/reports 声明）
    $router->get('/admin/reports/export', [AdminReportController::class, 'export'], ['admin', 'permission:report.view']);
    $router->get('/admin/reports', [AdminReportController::class, 'index'], ['admin', 'permission:report.view']);

    // 用户管理（静态段 /admin/users/export、/admin/users/batch、/admin/users/create 需先于 /admin/users/{id} 声明）
    $router->get('/admin/users', [AdminUserController::class, 'index'], ['admin', 'permission:user.view']);
    $router->get('/admin/users/export', [AdminUserController::class, 'export'], ['admin', 'permission:user.manage']);
    $router->get('/admin/users/create', [AdminUserController::class, 'create'], ['admin', 'permission:user.manage']);
    $router->post('/admin/users/batch', [AdminUserController::class, 'batch'], ['admin', 'permission:user.manage']);
    $router->post('/admin/users', [AdminUserController::class, 'store'], ['admin', 'permission:user.manage']);
    $router->get('/admin/users/{id}/edit', [AdminUserController::class, 'edit'], ['admin', 'permission:user.manage']);
    $router->post('/admin/users/{id}', [AdminUserController::class, 'update'], ['admin', 'permission:user.manage']);
    $router->post('/admin/users/{id}/status', [AdminUserController::class, 'toggleStatus'], ['admin', 'permission:user.manage']);
    $router->post('/admin/users/{id}/admin', [AdminUserController::class, 'toggleAdmin'], ['admin', 'permission:user.manage']);
    $router->post('/admin/users/{id}/reset-password', [AdminUserController::class, 'resetPassword'], ['admin', 'permission:user.manage']);
    $router->post('/admin/users/{id}/delete', [AdminUserController::class, 'destroy'], ['admin', 'permission:user.manage']);
    $router->get('/admin/users/{id}', [AdminUserController::class, 'show'], ['admin', 'permission:user.view']);

    // 课程管理（注意：静态段 /admin/courses/create 需先于 /admin/courses/{id} 声明）
    $router->get('/admin/courses', [AdminCourseController::class, 'index'], ['admin', 'permission:course.view']);
    $router->get('/admin/courses/create', [AdminCourseController::class, 'create'], ['admin', 'permission:course.manage']);
    $router->post('/admin/courses', [AdminCourseController::class, 'store'], ['admin', 'permission:course.manage']);
    $router->post('/admin/courses/batch', [AdminCourseController::class, 'batch'], ['admin', 'permission:course.manage']);
    $router->get('/admin/courses/{id}/edit', [AdminCourseController::class, 'edit'], ['admin', 'permission:course.manage']);
    $router->post('/admin/courses/{id}/status', [AdminCourseController::class, 'setStatus'], ['admin', 'permission:course.manage']);
    $router->post('/admin/courses/{id}/delete', [AdminCourseController::class, 'destroy'], ['admin', 'permission:course.manage']);
    $router->post('/admin/courses/{id}', [AdminCourseController::class, 'update'], ['admin', 'permission:course.manage']);

    // 分类管理（静态段 /admin/categories 需先于 /admin/categories/{id} 声明）
    $router->get('/admin/categories', [AdminCategoryController::class, 'index'], ['admin', 'permission:course.manage']);
    $router->post('/admin/categories', [AdminCategoryController::class, 'store'], ['admin', 'permission:course.manage']);
    $router->get('/admin/categories/{id}/edit', [AdminCategoryController::class, 'edit'], ['admin', 'permission:course.manage']);
    $router->post('/admin/categories/{id}/delete', [AdminCategoryController::class, 'destroy'], ['admin', 'permission:course.manage']);
    $router->post('/admin/categories/{id}', [AdminCategoryController::class, 'update'], ['admin', 'permission:course.manage']);

    // 公告管理（静态段 /admin/announcements/create、{id}/delete 需先于 /admin/announcements/{id} 声明）
    $router->get('/admin/announcements', [AdminAnnouncementController::class, 'index'], ['admin', 'permission:setting.manage']);
    $router->get('/admin/announcements/create', [AdminAnnouncementController::class, 'create'], ['admin', 'permission:setting.manage']);
    $router->post('/admin/announcements', [AdminAnnouncementController::class, 'store'], ['admin', 'permission:setting.manage']);
    $router->get('/admin/announcements/{id}/edit', [AdminAnnouncementController::class, 'edit'], ['admin', 'permission:setting.manage']);
    $router->post('/admin/announcements/{id}/delete', [AdminAnnouncementController::class, 'destroy'], ['admin', 'permission:setting.manage']);
    $router->post('/admin/announcements/{id}', [AdminAnnouncementController::class, 'update'], ['admin', 'permission:setting.manage']);

    // 协议管理（用户协议 / 隐私政策 / 退款政策）
    $router->get('/admin/agreements', [AdminAgreementController::class, 'index'], ['admin', 'permission:setting.manage']);
    $router->get('/admin/agreements/{type}/edit', [AdminAgreementController::class, 'edit'], ['admin', 'permission:setting.manage']);
    $router->post('/admin/agreements/{type}', [AdminAgreementController::class, 'update'], ['admin', 'permission:setting.manage']);

    // 订单管理（静态段 /admin/orders/export 需先于 /admin/orders/{id} 声明）
    $router->get('/admin/orders', [AdminOrderController::class, 'index'], ['admin', 'permission:order.view']);
    $router->get('/admin/orders/export', [AdminOrderController::class, 'export'], ['admin', 'permission:order.manage']);
    $router->post('/admin/orders/batch', [AdminOrderController::class, 'batch'], ['admin', 'permission:order.manage']);
    $router->get('/admin/orders/{id}', [AdminOrderController::class, 'show'], ['admin', 'permission:order.view']);
    $router->post('/admin/orders/{id}/close', [AdminOrderController::class, 'close'], ['admin', 'permission:order.manage']);
    $router->post('/admin/orders/{id}/complete', [AdminOrderController::class, 'complete'], ['admin', 'permission:order.manage']);
    $router->post('/admin/orders/{id}/reconcile', [AdminOrderController::class, 'reconcile'], ['admin', 'permission:order.manage']);
    $router->post('/admin/orders/{id}/refund', [AdminRefundController::class, 'store'], ['admin', 'permission:order.refund']);
    $router->post('/admin/orders/{id}/delete', [AdminOrderController::class, 'destroy'], ['admin', 'permission:order.manage']);

    // 优惠券管理（静态段 /admin/coupons/create 需先于 /admin/coupons/{id} 声明）
    $router->get('/admin/coupons', [AdminCouponController::class, 'index'], ['admin', 'permission:coupon.view']);
    $router->get('/admin/coupons/create', [AdminCouponController::class, 'create'], ['admin', 'permission:coupon.manage']);
    $router->post('/admin/coupons', [AdminCouponController::class, 'store'], ['admin', 'permission:coupon.manage']);
    $router->get('/admin/coupons/{id}/edit', [AdminCouponController::class, 'edit'], ['admin', 'permission:coupon.manage']);
    $router->post('/admin/coupons/{id}/delete', [AdminCouponController::class, 'destroy'], ['admin', 'permission:coupon.manage']);
    $router->post('/admin/coupons/{id}', [AdminCouponController::class, 'update'], ['admin', 'permission:coupon.manage']);

    // 套餐管理（静态段 /admin/packages/create 需先于 /admin/packages/{id} 声明）
    $router->get('/admin/packages', [AdminPackageController::class, 'index'], ['admin', 'permission:package.view']);
    $router->get('/admin/packages/create', [AdminPackageController::class, 'create'], ['admin', 'permission:package.manage']);
    $router->post('/admin/packages', [AdminPackageController::class, 'store'], ['admin', 'permission:package.manage']);
    $router->get('/admin/packages/{id}/edit', [AdminPackageController::class, 'edit'], ['admin', 'permission:package.manage']);
    $router->post('/admin/packages/{id}/delete', [AdminPackageController::class, 'destroy'], ['admin', 'permission:package.manage']);
    $router->post('/admin/packages/{id}', [AdminPackageController::class, 'update'], ['admin', 'permission:package.manage']);

    // 退款管理（静态段 /admin/refunds 需先于 /admin/refunds/{id} 声明）
    $router->get('/admin/refunds', [AdminRefundController::class, 'index'], ['admin', 'permission:order.refund']);
    $router->post('/admin/refunds/{id}/approve', [AdminRefundController::class, 'approve'], ['admin', 'permission:order.refund']);
    $router->post('/admin/refunds/{id}/reject', [AdminRefundController::class, 'reject'], ['admin', 'permission:order.refund']);

    // 工单管理（静态段 /admin/tickets 需先于 /admin/tickets/{id} 声明）
    $router->get('/admin/tickets', [AdminTicketController::class, 'index'], ['admin', 'permission:ticket.view']);
    $router->post('/admin/tickets/{id}/reply', [AdminTicketController::class, 'reply'], ['admin', 'permission:ticket.reply']);
    $router->post('/admin/tickets/{id}/status', [AdminTicketController::class, 'setStatus'], ['admin', 'permission:ticket.manage']);
    $router->post('/admin/tickets/{id}/priority', [AdminTicketController::class, 'setPriority'], ['admin', 'permission:ticket.manage']);
    $router->post('/admin/tickets/{id}/unban', [AdminTicketController::class, 'unban'], ['admin', 'permission:ticket.manage']);
    $router->post('/admin/tickets/{id}/delete', [AdminTicketController::class, 'destroy'], ['admin', 'permission:ticket.manage']);
    $router->get('/admin/tickets/{id}', [AdminTicketController::class, 'show'], ['admin', 'permission:ticket.view']);

    // 角色权限（RBAC，静态段 /admin/roles/create 需先于 /admin/roles/{id} 声明）
    $router->get('/admin/roles', [AdminRoleController::class, 'index'], ['admin', 'permission:rbac.manage']);
    $router->get('/admin/roles/create', [AdminRoleController::class, 'create'], ['admin', 'permission:rbac.manage']);
    $router->post('/admin/roles', [AdminRoleController::class, 'store'], ['admin', 'permission:rbac.manage']);
    $router->get('/admin/roles/{id}/edit', [AdminRoleController::class, 'edit'], ['admin', 'permission:rbac.manage']);
    $router->post('/admin/roles/{id}/delete', [AdminRoleController::class, 'destroy'], ['admin', 'permission:rbac.manage']);
    $router->post('/admin/roles/{id}', [AdminRoleController::class, 'update'], ['admin', 'permission:rbac.manage']);

    // 系统设置（GET 静态页 + POST /admin/settings/{group} 按分组保存）
    $router->get('/admin/settings', [AdminSettingsController::class, 'index'], ['admin', 'permission:setting.view']);
    $router->post('/admin/settings/cache/clear', [AdminSettingsController::class, 'clearCache'], ['admin', 'permission:setting.manage']);
    $router->post('/admin/settings/{group}', [AdminSettingsController::class, 'update'], ['admin', 'permission:setting.manage']);

    // 日志查看（登录 / 操作 / 支付 / 邮件 / 验证码）
    $router->get('/admin/logs', [AdminLogController::class, 'index'], ['admin', 'permission:log.view']);

    // 章节管理（静态段 /chapters 与 /chapters/{chapterId}/xxx 需先于 POST /chapters/{chapterId} 声明）
    $router->get('/admin/courses/{id}/chapters', [AdminChapterController::class, 'index'], ['admin', 'permission:chapter.manage']);
    $router->post('/admin/courses/{id}/chapters', [AdminChapterController::class, 'store'], ['admin', 'permission:chapter.manage']);
    $router->get('/admin/courses/{id}/chapters/{chapterId}/edit', [AdminChapterController::class, 'edit'], ['admin', 'permission:chapter.manage']);
    $router->post('/admin/courses/{id}/chapters/{chapterId}/move', [AdminChapterController::class, 'move'], ['admin', 'permission:chapter.manage']);
    $router->post('/admin/courses/{id}/chapters/{chapterId}/video/delete', [AdminChapterController::class, 'deleteVideo'], ['admin', 'permission:chapter.manage']);
    $router->post('/admin/courses/{id}/chapters/{chapterId}/transcode', [AdminChapterController::class, 'transcode'], ['admin', 'permission:chapter.manage']);
    $router->post('/admin/courses/{id}/chapters/{chapterId}/delete', [AdminChapterController::class, 'destroy'], ['admin', 'permission:chapter.manage']);
    $router->post('/admin/courses/{id}/chapters/{chapterId}', [AdminChapterController::class, 'update'], ['admin', 'permission:chapter.manage']);
};
