<?php

declare(strict_types=1);

/**
 * 站点唯一入口（前端控制器）
 */

use App\Support\Installer;
use App\Support\Middleware;
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

// 未安装时（无安装锁且库中无管理员），所有页面统一跳转到安装向导，
// 避免在缺失数据表的情况下直接访问业务页面报错。
// 安装向导为独立入口 public/install.php，不经过本文件，因此不会造成跳转循环。
if (!Installer::isInstalled() && !Installer::hasExistingAdmin()) {
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    Response::redirect($base . '/install.php');
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
