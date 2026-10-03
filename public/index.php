<?php

declare(strict_types=1);

/**
 * 站点唯一入口（前端控制器）
 */

use App\Support\Auth;
use App\Support\I18n;
use App\Support\Installer;
use App\Support\Middleware;
use App\Support\QueueRunner;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\SecurityHeaders;

// PHP 内置服务器：请求的是真实存在的静态文件时，直接交给内置服务器返回，不进入路由
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;

    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

// 未安装时（既无安装锁、库中也没有管理员）所有页面统一跳转到安装向导，避免在缺失
// 数据表的情况下直接访问业务页面报错。安装锁缺失但库中确有管理员（部署已完成而锁
// 写入失败，例如 storage/ 目录权限问题）时视为已安装并正常提供服务：写不了锁只应
// 降级为「无法写缓存/日志/上传」，不应让整站不可访问。
// 安装向导为独立入口 public/install.php，不经过本文件，因此不会造成跳转循环。
if (!Installer::isInstalled() && !Installer::hasExistingAdmin()) {
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    Response::redirect($base . '/install.php');
}

// 尽力补写安装锁；storage/ 不可写时静默失败，不影响站点访问
Installer::ensureLock();

// 多语言：解析当前语言并同步 Cookie（须在任何输出之前）
I18n::boot();

// 后台管理界面固定简体中文：必须在此处固定，而不能只靠 AdminController 构造函数——
// 中间件（admin / permission）在控制器实例化之前就会抛 403，那些错误页同样是后台界面。
if (str_starts_with(Request::path(), '/admin')) {
    I18n::force(I18n::DEFAULT_LOCALE);

    // 队列自动运行：仅管理员登录后的后台请求触发，避免匿名请求被用来反复拉起进程
    if (Auth::isAdmin()) {
        QueueRunner::trigger();
    }
}

// 全局安全响应头：在任何输出之前下发
SecurityHeaders::apply();

$router = new Router();

/** @var callable(Router): void $routes */
$routes = require CONFIG_PATH . '/routes.php';
$routes($router);

// 强制绑定邮箱拦截（后台开关关闭时不产生任何影响）
Middleware::enforceEmailBinding();

$router->dispatch();
