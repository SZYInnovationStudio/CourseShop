<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * 控制器基类
 */
abstract class Controller
{
    /**
     * 渲染视图
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/app'): void
    {
        View::render($view, $data, $layout);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function validator(array $data): Validator
    {
        return new Validator($data);
    }

    /**
     * 校验失败：回填表单并跳回来源页
     *
     * @param array<string, mixed> $input
     */
    protected function fail(string $url, string $message, array $input = []): never
    {
        Response::back($url, 'error', $message, $input);
    }

    /**
     * 操作成功：跳转并回填表单数据
     *
     * @param array<string, mixed> $input
     */
    protected function success(string $url, string $message, array $input = []): never
    {
        Response::back($url, 'success', $message, $input);
    }

    /**
     * 校验失败：AJAX 请求返回 JSON 提示，普通请求回填并跳回来源页
     *
     * 用于「发送验证码」这类不应打断输入的操作，避免整页刷新导致密码等已填内容丢失。
     *
     * @param array<string, mixed> $input
     */
    protected function failUnlessAjax(string $url, string $message, array $input = []): never
    {
        if (Request::isAjax()) {
            Response::json(['code' => 1, 'message' => $message]);
        }

        $this->fail($url, $message, $input);
    }

    /**
     * 操作成功：AJAX 请求返回 JSON，普通请求跳转并提示
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $extra 额外的 JSON 字段
     */
    protected function successUnlessAjax(string $url, string $message, array $input = [], array $extra = []): never
    {
        if (Request::isAjax()) {
            Response::json(array_merge(['code' => 0, 'message' => $message], $extra));
        }

        $this->success($url, $message, $input);
    }

    /**
     * 当前登录用户（未登录返回 null）
     *
     * @return array<string, mixed>|null
     */
    protected function user(): ?array
    {
        return Auth::user();
    }

    /**
     * 登录后跳转到此前的目标地址
     */
    protected function intended(string $default = '/'): string
    {
        $intended = (string) Session::get('_intended', '');
        Session::forget('_intended');

        if ($intended === '' || !str_starts_with($intended, '/')) {
            return url($default);
        }

        return url($intended);
    }

    protected function isPost(): bool
    {
        return Request::isPost();
    }
}
