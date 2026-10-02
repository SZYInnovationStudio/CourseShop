<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 极简路由器
 *
 * 支持：
 * - GET / POST / PUT / PATCH / DELETE（HEAD 按 GET 处理，OPTIONS 自动响应允许的方法）
 * - {param} 路径参数（按位置作为实参传给处理器）
 * - 中间件名称（auth / guest / admin / email_verified）
 *
 * 处理器支持三种写法：
 * - 数组：[HomeController::class, 'index']
 * - 闭包：function (...) { ... }
 * - 字符串：'HomeController@index'（全局命名空间下解析）
 */
final class Router
{
    /** 中间件名称 => 可调用 */
    private const MIDDLEWARE = [
        'auth'           => [Middleware::class, 'auth'],
        'guest'          => [Middleware::class, 'guest'],
        'admin'          => [Middleware::class, 'admin'],
        'email_verified' => [Middleware::class, 'emailVerified'],
        'permission'     => [Middleware::class, 'permission'],
    ];

    /** @var array<int, array{method: string, regex: string, params: array<int, string>, handler: mixed, middleware: array<int, string>}> */
    private array $routes = [];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param array<int, string> $middleware
     */
    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        [$regex, $params] = self::compile($path);

        $this->routes[] = [
            'method'     => $method,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(?string $method = null, ?string $uri = null): void
    {
        $method = strtoupper($method ?? Request::method());
        $path   = self::normalize($uri ?? Request::path());

        // OPTIONS：不执行处理器，仅返回该路径允许的方法
        if ($method === 'OPTIONS') {
            $allowed = [];
            foreach ($this->routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $route['method'];
                }
            }

            if ($allowed === []) {
                throw new HttpException(404);
            }

            $allowed[] = 'OPTIONS';
            header('Allow: ' . implode(', ', array_unique($allowed)));
            http_response_code(204);

            return;
        }

        // HEAD 按 GET 匹配（响应体由 SAPI / 前端服务器负责丢弃）
        $matchMethod = $method === 'HEAD' ? 'GET' : $method;

        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $matchMethod) {
                $allowed[] = $route['method'];
                continue;
            }

            $arguments = [];
            foreach ($route['params'] as $name) {
                $arguments[] = $matches[$name] ?? null;
            }

            foreach ($route['middleware'] as $name) {
                $argument = null;

                // 支持「名:参数」形式，例如 permission:course.manage
                if (str_contains($name, ':')) {
                    [$name, $argument] = explode(':', $name, 2);
                }

                $callable = self::MIDDLEWARE[$name] ?? null;
                if ($callable === null) {
                    throw new HttpException(500, '未定义的中间件：' . $name);
                }
                $callable($argument);
            }

            $this->invoke($route['handler'], $arguments);

            return;
        }

        if ($allowed !== []) {
            $allowed = array_unique($allowed);
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $allowed[] = 'OPTIONS';
            header('Allow: ' . implode(', ', array_unique($allowed)));
            throw new HttpException(405);
        }

        throw new HttpException(404);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invoke(mixed $handler, array $arguments): void
    {
        if (is_callable($handler)) {
            $handler(...$arguments);

            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;
            if (is_string($class) && class_exists($class)) {
                $instance = new $class();
                $instance->{$action}(...$arguments);

                return;
            }
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $action] = explode('@', $handler, 2);
            if (class_exists($class)) {
                $instance = new $class();
                $instance->{$action}(...$arguments);

                return;
            }
        }

        throw new HttpException(500, '路由处理器无法解析。');
    }

    /**
     * 把 /course/{id} 编译成正则并收集参数名
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private static function compile(string $path): array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));

        if ($segments === []) {
            return ['#^/$#', []];
        }

        $params = [];
        $parts  = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $match) === 1) {
                $params[] = $match[1];
                $parts[]  = '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }

        return ['#^/' . implode('/', $parts) . '/?$#', $params];
    }

    private static function normalize(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        return $path;
    }
}
