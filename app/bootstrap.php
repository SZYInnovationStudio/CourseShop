<?php

declare(strict_types=1);

/**
 * 应用引导文件
 *
 * 职责：定义路径常量、注册自动加载、加载配置、启动会话、注册错误处理。
 * 由 public/index.php 与 CLI 脚本引入。
 */

use App\Support\Config;
use App\Support\Env;
use App\Support\ErrorHandler;
use App\Support\Session;

// ---------------- 路径常量 ----------------
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('VIEW_PATH', APP_PATH . '/Views');

// ---------------- 自动加载（App\ -> app/） ----------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/Support/helpers.php';

// ---------------- 环境与配置 ----------------
Env::load(BASE_PATH . '/.env');
Config::load(CONFIG_PATH);

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));
mb_internal_encoding('UTF-8');

ErrorHandler::register();

// ---------------- 会话 ----------------
if (PHP_SAPI !== 'cli') {
    Session::start();
}
