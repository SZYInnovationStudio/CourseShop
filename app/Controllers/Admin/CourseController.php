<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Category;
use App\Models\Course;
use App\Models\Log;
use App\Support\Csrf;
use App\Support\ImageStorage;
use App\Support\Request;
use RuntimeException;

/**
 * 后台课程管理
 *
 * 提供课程列表与筛选、新增/编辑（分类、标签、价格、试看、状态）、
 * 上架与下架快捷操作、软删除。章节与视频管理在「章节管理」中完成。
 */
final class CourseController extends AdminController
{
    /** 每页条数 */
    private const PER_PAGE = 20;

    /**
     * 课程列表
     */
    public function index(): void
    {
        $filters = [
            'keyword'     => Request::string('keyword'),
            'status'      => Request::string('status'),
            'category_id' => Request::int('category_id'),
            'tag_id'      => Request::int('tag_id'),
        ];

        // 归一化状态，避免非法值进入 SQL 条件拼装
        if (!array_key_exists($filters['status'], Course::STATUSES)) {
            $filters['status'] = '';
        }

        $total      = Course::adminCount($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min(max(1, Request::int('page', 1)), $totalPages);

        $this->view('admin.courses.index', [
            'pageTitle'  => '课程管理',
            'filters'    => $filters,
            'courses'    => Course::adminList($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'categories' => Category::all(),
            'tags'       => Course::allTags(),
            'statuses'   => Course::STATUSES,
        ]);
    }

    /**
     * 新增课程页
     */
    public function create(): void
    {
        $this->renderForm(null);
    }

    /**
     * 编辑课程页
     */
    public function edit(string $id): void
    {
        $course = Course::adminFind((int) $id);

        if ($course === null) {
            $this->fail(url('/admin/courses'), '课程不存在或已被删除。');
        }

        $this->renderForm($course);
    }

    /**
     * 保存新课程
     */
    public function store(): void
    {
        Csrf::check();

        $data   = $this->validated(null);
        $course = Course::create($data);

        Course::syncTags($course, $this->tagIdsFromRequest());

        Log::recordOperation('course.create', 'course', $course, ['title' => $data['title']]);

        $this->success(url('/admin/courses'), '课程已创建，可继续添加章节。');
    }

    /**
     * 保存课程修改
     */
    public function update(string $id): void
    {
        Csrf::check();

        $courseId = (int) $id;

        if (Course::adminFind($courseId) === null) {
            $this->fail(url('/admin/courses'), '课程不存在或已被删除。');
        }

        $data = $this->validated($courseId);
        Course::update($courseId, $data);

        Course::syncTags($courseId, $this->tagIdsFromRequest());

        Log::recordOperation('course.update', 'course', $courseId, ['title' => $data['title']]);

        $this->success(url('/admin/courses'), '课程已保存。');
    }

    /**
     * 修改课程状态（草稿 / 上架 / 下架）
     */
    public function setStatus(string $id): void
    {
        Csrf::check();

        $courseId = (int) $id;

        if (Course::adminFind($courseId) === null) {
            $this->fail(url('/admin/courses'), '课程不存在或已被删除。');
        }

        $status = Request::string('status');

        if (!array_key_exists($status, Course::STATUSES)) {
            $this->fail(url('/admin/courses'), '课程状态不合法。');
        }

        Course::setStatus($courseId, $status);

        Log::recordOperation('course.status', 'course', $courseId, ['status' => $status]);

        $this->success(url('/admin/courses'), '课程状态已更新为「' . Course::STATUSES[$status] . '」。');
    }

    /**
     * 软删除课程
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $courseId = (int) $id;

        if (Course::adminFind($courseId) === null) {
            $this->fail(url('/admin/courses'), '课程不存在或已被删除。');
        }

        Course::softDelete($courseId);

        Log::recordOperation('course.delete', 'course', $courseId);

        $this->success(url('/admin/courses'), '课程已删除（订单、章节等历史数据保留）。');
    }

    /**
     * 批量操作：上架 / 下架 / 转为草稿 / 删除
     */
    public function batch(): void
    {
        Csrf::check();

        $backUrl = url('/admin/courses');
        $action  = Request::string('action');
        $ids     = $this->batchIds();

        // 每个动作映射到对应的课程状态；delete 单独处理
        $statusMap = [
            'publish' => 'published',
            'offline' => 'offline',
            'draft'   => 'draft',
        ];

        $labels = [
            'publish' => '上架',
            'offline' => '下架',
            'draft'   => '转为草稿',
            'delete'  => '删除',
        ];

        if (!isset($labels[$action])) {
            $this->fail($backUrl, '未知的批量操作类型。');
        }

        if ($ids === []) {
            $this->fail($backUrl, '请至少选择一门课程。');
        }

        $affected = $action === 'delete'
            ? Course::softDeleteMany($ids)
            : Course::setStatusMany($ids, $statusMap[$action]);

        Log::recordOperation(
            $action === 'delete' ? 'course.delete' : 'course.status',
            'course',
            null,
            ['action' => $action, 'ids' => $ids, 'affected' => $affected]
        );

        $this->success($backUrl, sprintf('已批量%s %d 门课程。', $labels[$action], $affected));
    }

    /**
     * 渲染新增 / 编辑表单
     *
     * @param array<string, mixed>|null $course
     */
    private function renderForm(?array $course): void
    {
        $this->view('admin.courses.form', [
            'pageTitle'      => $course === null ? '新增课程' : '编辑课程',
            'course'         => $course,
            'categories'     => Category::all(),
            'tags'           => Course::allTags(),
            'selectedTagIds' => $course === null ? [] : Course::tagIds((int) $course['id']),
            'statuses'       => Course::STATUSES,
        ]);
    }

    /**
     * 校验并整理课程表单数据
     *
     * 金额表单以「元」提交，落库前统一转换为「分」。
     *
     * @return array<string, mixed>
     */
    private function validated(?int $courseId): array
    {
        $backUrl = $courseId === null
            ? url('/admin/courses/create')
            : url('/admin/courses/' . $courseId . '/edit');

        $title             = Request::string('title');
        $subtitle          = Request::string('subtitle');
        $summary           = Request::string('summary');
        $content           = Request::string('content');
        $cover             = Request::string('cover');
        $categoryId        = Request::int('category_id');
        // 数值字段允许留空，统一按 0 处理，避免整数校验直接失败
        $priceRaw          = trim(Request::string('price'));
        $originalPriceRaw  = trim(Request::string('original_price'));
        $previewEnabled    = Request::string('preview_enabled', '0');
        $previewCount      = trim(Request::string('preview_chapter_count'));
        $status            = Request::string('status', 'draft');
        $sort              = trim(Request::string('sort'));

        if ($priceRaw === '') {
            $priceRaw = '0';
        }
        if ($originalPriceRaw === '') {
            $originalPriceRaw = '0';
        }
        if ($previewCount === '') {
            $previewCount = '0';
        }
        if ($sort === '') {
            $sort = '0';
        }

        $old = [
            'title'                 => $title,
            'subtitle'              => $subtitle,
            'summary'               => $summary,
            'content'               => $content,
            'cover'                 => $cover,
            'category_id'           => (string) $categoryId,
            'price'                 => $priceRaw,
            'original_price'        => $originalPriceRaw,
            'preview_enabled'       => $previewEnabled,
            'preview_chapter_count' => (string) $previewCount,
            'status'                => $status,
            'sort'                  => (string) $sort,
            // 复选标签与新增标签一并回填，校验失败时不丢失选择
            'tag_ids'               => array_map('intval', (array) Request::input('tag_ids', [])),
            'new_tags'              => Request::string('new_tags'),
        ];

        // 空值已在上方归一化为 0，这里用归一化后的值参与数字校验
        $validatorInput = array_merge(Request::all(), [
            'preview_chapter_count' => $previewCount,
            'sort'                  => $sort,
        ]);

        $validator = $this->validator($validatorInput)
            ->required('title', '课程名称')
            ->max('title', 150, '课程名称')
            ->max('subtitle', 255, '副标题')
            ->max('summary', 500, '简介')
            ->max('cover', 255, '预览图地址')
            ->in('status', array_keys(Course::STATUSES), '课程状态')
            ->in('preview_enabled', ['0', '1'], '试看开关')
            ->integer('preview_chapter_count', '试看章节数', 0, 999)
            ->integer('sort', '排序权重', -100000, 100000);

        if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
            $validator->addError('price', '售价必须为不小于 0 的数字。');
        }
        if (!is_numeric($originalPriceRaw) || (float) $originalPriceRaw < 0) {
            $validator->addError('original_price', '划线价必须为不小于 0 的数字。');
        }

        // 分类必须存在（0 表示未分类）
        if ($categoryId > 0) {
            $categoryIds = array_map(static fn (array $c): int => (int) $c['id'], Category::all());
            if (!in_array($categoryId, $categoryIds, true)) {
                $validator->addError('category_id', '所选分类不存在。');
            }
        }

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), $old);
        }

        // 封面上传优先于地址；上传成功后替换旧的受管图片
        $oldCover = null;
        if ($courseId !== null) {
            $existingCourse = Course::adminFind($courseId);
            $oldCover = $existingCourse === null ? null : (string) ($existingCourse['cover'] ?? '');
        }

        try {
            $uploadedCover = ImageStorage::saveUploaded('cover_file', 'covers', $oldCover);
        } catch (RuntimeException $e) {
            $this->fail($backUrl, $e->getMessage(), $old);
        }

        if ($uploadedCover !== null) {
            $cover = $uploadedCover;
        }

        return [
            'title'                 => $title,
            'subtitle'              => $subtitle,
            'category_id'           => $categoryId > 0 ? $categoryId : null,
            'summary'               => $summary,
            'content'               => $content,
            'cover'                 => $cover !== '' ? $cover : null,
            'price'                 => (int) round((float) $priceRaw * 100),
            'original_price'        => (int) round((float) $originalPriceRaw * 100),
            'preview_enabled'       => $previewEnabled === '1' ? 1 : 0,
            'preview_chapter_count' => max(0, (int) $previewCount),
            'status'                => $status,
            'sort'                  => (int) $sort,
        ];
    }

    /**
     * 收集本次提交要绑定的标签 ID
     *
     * 已存在标签来自复选（tag_ids），新标签来自逗号分隔的文本框（new_tags）。
     *
     * @return array<int, int>
     */
    private function tagIdsFromRequest(): array
    {
        $ids = array_values(array_filter(
            array_map('intval', (array) Request::input('tag_ids', [])),
            static fn (int $id): bool => $id > 0
        ));

        $raw = Request::string('new_tags');
        if ($raw !== '') {
            $names = preg_split('/[,\x{ff0c}]/u', $raw) ?: [];
            foreach (Course::ensureTags($names) as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
