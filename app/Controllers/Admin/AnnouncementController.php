<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Announcement;
use App\Models\Log;
use App\Support\Csrf;
use App\Support\Request;

/**
 * 后台公告管理
 *
 * 公告按「标题 + Markdown 正文」维护，可控制是否启用与发布时间；
 * 前台仅展示 is_active = 1 的公告，无启用公告时首页不显示公告区块。
 */
final class AnnouncementController extends AdminController
{
    /**
     * 公告列表
     */
    public function index(): void
    {
        $this->view('admin.announcements.index', [
            'pageTitle'     => '公告管理',
            'announcements' => Announcement::adminAll(),
        ]);
    }

    /**
     * 新增公告页
     */
    public function create(): void
    {
        $this->view('admin.announcements.form', [
            'pageTitle'    => '新增公告',
            'isEdit'       => false,
            'announcement' => null,
        ]);
    }

    /**
     * 保存新公告
     */
    public function store(): void
    {
        Csrf::check();

        $data = $this->validated(null);

        $announcementId = Announcement::create($data['title'], $data['content'], $data['is_active'], $data['published_at']);

        Log::recordOperation('announcement.create', 'announcement', $announcementId, ['title' => $data['title']]);

        $this->success(url('/admin/announcements'), '公告已发布。');
    }

    /**
     * 编辑公告页
     */
    public function edit(string $id): void
    {
        $announcement = Announcement::find((int) $id);

        if ($announcement === null) {
            $this->fail(url('/admin/announcements'), '公告不存在或已被删除。');
        }

        $this->view('admin.announcements.form', [
            'pageTitle'    => '编辑公告',
            'isEdit'       => true,
            'announcement' => $announcement,
        ]);
    }

    /**
     * 保存公告修改
     */
    public function update(string $id): void
    {
        Csrf::check();

        $announcementId = (int) $id;

        if (Announcement::find($announcementId) === null) {
            $this->fail(url('/admin/announcements'), '公告不存在或已被删除。');
        }

        $data = $this->validated($announcementId);

        Announcement::update(
            $announcementId,
            $data['title'],
            $data['content'],
            $data['is_active'],
            $data['published_at']
        );

        Log::recordOperation('announcement.update', 'announcement', $announcementId, ['title' => $data['title']]);

        $this->success(url('/admin/announcements'), '公告已保存。');
    }

    /**
     * 删除公告（软删除）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $announcementId = (int) $id;
        $announcement   = Announcement::find($announcementId);

        if ($announcement === null) {
            $this->fail(url('/admin/announcements'), '公告不存在或已被删除。');
        }

        Announcement::softDelete($announcementId);

        Log::recordOperation('announcement.delete', 'announcement', $announcementId, [
            'title' => (string) $announcement['title'],
        ]);

        $this->success(url('/admin/announcements'), '公告已删除。');
    }

    /**
     * 校验表单并返回整理后的数据
     *
     * @return array{title: string, content: string, is_active: bool, published_at: ?string}
     */
    private function validated(?int $announcementId): array
    {
        $backUrl = $announcementId === null
            ? url('/admin/announcements/create')
            : url('/admin/announcements/' . $announcementId . '/edit');

        $title       = Request::string('title');
        $content     = Request::string('content');
        $isActive    = Request::bool('is_active', false);
        $publishedAt = $this->normalizePublishedAt(Request::string('published_at'));

        $validator = $this->validator(Request::all())
            ->required('title', '公告标题')
            ->max('title', 150, '公告标题')
            ->max('content', 20000, '公告正文');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), [
                'title'        => $title,
                'content'      => $content,
                'is_active'    => $isActive ? '1' : '0',
                'published_at' => Request::string('published_at'),
            ]);
        }

        return [
            'title'        => $title,
            'content'      => $content,
            'is_active'    => $isActive,
            'published_at' => $publishedAt,
        ];
    }

    /**
     * 归一化发布时间：支持 datetime-local（Y-m-dTH:i），留空则取当前时间
     */
    private function normalizePublishedAt(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return date('Y-m-d H:i:s');
        }

        $timestamp = strtotime(str_replace('T', ' ', $raw));

        return $timestamp === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $timestamp);
    }
}
