<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Category;
use App\Models\Log;
use App\Support\Csrf;
use App\Support\Request;

/**
 * 后台分类管理
 *
 * 支持两级分类树（顶级分类 + 子分类）。删除前会校验分类下是否仍有课程或子分类，
 * 上级分类不允许选择自身或其子分类，以保证分类结构始终有效。
 */
final class CategoryController extends AdminController
{
    /**
     * 分类列表（含新增表单）
     */
    public function index(): void
    {
        $this->view('admin.categories.index', [
            'pageTitle'     => '分类管理',
            'categories'    => Category::adminAll(),
            'parentOptions' => Category::parentOptions(),
        ]);
    }

    /**
     * 新增分类
     */
    public function store(): void
    {
        Csrf::check();

        $data = $this->validated(null);

        $categoryId = Category::create($data['name'], $data['sort'], $data['parent_id']);

        Log::recordOperation('category.create', 'category', $categoryId, [
            'name'      => $data['name'],
            'parent_id' => $data['parent_id'],
        ]);

        $this->success(url('/admin/categories'), '分类已创建。');
    }

    /**
     * 编辑分类页
     */
    public function edit(string $id): void
    {
        $categoryId = (int) $id;
        $category   = Category::find($categoryId);

        if ($category === null) {
            $this->fail(url('/admin/categories'), '分类不存在或已被删除。');
        }

        $this->view('admin.categories.edit', [
            'pageTitle'     => '编辑分类',
            'category'      => $category,
            'courseCount'   => Category::courseCount($categoryId),
            'parentOptions' => Category::parentOptions($categoryId),
        ]);
    }

    /**
     * 保存分类修改
     */
    public function update(string $id): void
    {
        Csrf::check();

        $categoryId = (int) $id;

        if (Category::find($categoryId) === null) {
            $this->fail(url('/admin/categories'), '分类不存在或已被删除。');
        }

        $data = $this->validated($categoryId);

        Category::update($categoryId, $data['name'], $data['sort'], $data['parent_id']);

        Log::recordOperation('category.update', 'category', $categoryId, [
            'name'      => $data['name'],
            'parent_id' => $data['parent_id'],
        ]);

        $this->success(url('/admin/categories'), '分类已保存。');
    }

    /**
     * 删除分类（存在子分类或仍有课程时拒绝）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $categoryId = (int) $id;
        $category   = Category::find($categoryId);

        if ($category === null) {
            $this->fail(url('/admin/categories'), '分类不存在或已被删除。');
        }

        if (Category::hasChildren($categoryId)) {
            $this->fail(
                url('/admin/categories'),
                '该分类下还有子分类，请先删除或调整子分类后再删除。'
            );
        }

        $courseCount = Category::courseCount($categoryId);
        if ($courseCount > 0) {
            $this->fail(
                url('/admin/categories'),
                '该分类下仍有 ' . $courseCount . ' 门课程，请先调整这些课程的分类后再删除。'
            );
        }

        Category::softDelete($categoryId);

        Log::recordOperation('category.delete', 'category', $categoryId, ['name' => (string) $category['name']]);

        $this->success(url('/admin/categories'), '分类已删除。');
    }

    /**
     * 校验分类表单，返回整理后的数据
     *
     * @return array{name: string, sort: int, parent_id: int}
     */
    private function validated(?int $categoryId): array
    {
        $backUrl = $categoryId === null
            ? url('/admin/categories')
            : url('/admin/categories/' . $categoryId . '/edit');

        $name     = Request::string('name');
        $sort     = $this->sortValue();
        $parentId = $this->parentValue();

        // 空 sort 已归一化为 0，避免整型校验对空字符串直接报错
        $validatorInput = array_merge(Request::all(), ['sort' => (string) $sort]);

        $validator = $this->validator($validatorInput)
            ->required('name', '分类名称')
            ->max('name', 50, '分类名称')
            ->integer('sort', '排序权重', -100000, 100000);

        if ($name !== '' && Category::nameExists($name, $categoryId)) {
            $validator->addError('name', '该分类名称已存在。');
        }

        if ($parentId > 0) {
            $parent = Category::find($parentId);

            if ($parent === null) {
                $validator->addError('parent_id', '所选上级分类不存在。');
            } elseif ($categoryId !== null && $parentId === $categoryId) {
                $validator->addError('parent_id', '上级分类不能是自身。');
            } elseif ($categoryId !== null && in_array($parentId, Category::descendantIds($categoryId), true)) {
                $validator->addError('parent_id', '上级分类不能是自身的子分类。');
            } elseif ((int) $parent['parent_id'] > 0) {
                $validator->addError('parent_id', '系统最多支持两级分类，请选择顶级分类作为上级。');
            }
        }

        if ($validator->fails()) {
            // 用归一化后的 sort 回填，避免空字符串触发整型校验报错后丢失输入
            $this->fail($backUrl, (string) $validator->firstError(), [
                'name'      => $name,
                'sort'      => (string) $sort,
                'parent_id' => (string) $parentId,
            ]);
        }

        return [
            'name'      => $name,
            'sort'      => $sort,
            'parent_id' => $parentId,
        ];
    }

    /**
     * 上级分类 ID：留空或 0 表示顶级分类
     */
    private function parentValue(): int
    {
        $raw = trim(Request::string('parent_id'));

        return $raw === '' ? 0 : max(0, (int) $raw);
    }

    /**
     * 排序值：允许留空，统一按 0 处理
     */
    private function sortValue(): int
    {
        $raw = trim(Request::string('sort'));

        return $raw === '' ? 0 : (int) $raw;
    }
}
