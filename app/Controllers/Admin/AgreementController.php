<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Agreement;
use App\Models\Log;
use App\Support\Csrf;
use App\Support\Request;

/**
 * 后台协议管理
 *
 * 维护用户协议 / 隐私政策 / 退款政策的当前版本与版本历史。
 * 保存时若版本号已存在则就地更新，否则新增一条记录，并保证同类型仅一条当前生效版本。
 */
final class AgreementController extends AdminController
{
    /**
     * 协议总览：各类型当前版本
     */
    public function index(): void
    {
        $current = Agreement::currentMap();

        $items = [];
        foreach (Agreement::TYPES as $type) {
            $items[] = [
                'type'    => $type,
                'label'   => Agreement::LABELS[$type] ?? $type,
                'current' => $current[$type] ?? null,
            ];
        }

        $this->view('admin.agreements.index', [
            'pageTitle' => '协议管理',
            'items'     => $items,
        ]);
    }

    /**
     * 编辑某类型协议
     */
    public function edit(string $type): void
    {
        $this->assertType($type);

        $this->view('admin.agreements.form', [
            'pageTitle' => '编辑' . (Agreement::LABELS[$type] ?? $type),
            'type'      => $type,
            'label'     => Agreement::LABELS[$type] ?? $type,
            'current'   => Agreement::current($type),
            'history'   => Agreement::history($type),
        ]);
    }

    /**
     * 发布/保存协议版本
     */
    public function update(string $type): void
    {
        Csrf::check();
        $this->assertType($type);

        $backUrl = url('/admin/agreements/' . $type . '/edit');

        $version       = Request::string('version');
        $title         = Request::string('title');
        $content       = Request::string('content');
        $effectiveDate = trim(Request::string('effective_date'));

        $validator = $this->validator(Request::all())
            ->required('version', '版本号')
            ->max('version', 20, '版本号')
            ->required('title', '协议标题')
            ->max('title', 150, '协议标题')
            ->max('content', 40000, '协议正文');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), [
                'version'        => $version,
                'title'          => $title,
                'content'        => $content,
                'effective_date' => $effectiveDate,
            ]);
        }

        $effectiveAt = null;
        if ($effectiveDate !== '') {
            $timestamp = strtotime($effectiveDate);
            $effectiveAt = $timestamp === false ? null : date('Y-m-d 00:00:00', $timestamp);
        }

        Agreement::publish($type, $version, $title, $content, $effectiveAt);

        Log::recordOperation('agreement.update', 'agreement', null, [
            'type'    => $type,
            'version' => $version,
        ]);

        $this->success($backUrl, (Agreement::LABELS[$type] ?? $type) . ' 已保存并生效。');
    }

    /**
     * 校验协议类型合法性
     */
    private function assertType(string $type): void
    {
        if (!in_array($type, Agreement::TYPES, true)) {
            $this->fail(url('/admin/agreements'), '协议类型不存在。');
        }
    }
}
