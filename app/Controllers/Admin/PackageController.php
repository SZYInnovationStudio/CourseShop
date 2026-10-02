<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Course;
use App\Models\Log;
use App\Models\Package;
use App\Support\Csrf;
use App\Support\ImageStorage;
use App\Support\Request;
use RuntimeException;

/**
 * 后台套餐管理
 *
 * 套餐把多门课程打包，以套餐价整体售卖；用户购买后一次性开通套餐内全部课程。
 * 价格在表单中以「元」输入，落库统一转为「分」。
 */
final class PackageController extends AdminController
{
    /** 后台列表单次读取上限（套餐数量通常较少，暂不分页） */
    private const LIST_LIMIT = 200;

    /**
     * 套餐列表
     */
    public function index(): void
    {
        $filters = [
            'keyword' => Request::string('q'),
            'status'  => Request::string('status'),
        ];

        $this->view('admin.packages.index', [
            'pageTitle' => '套餐管理',
            'packages'  => Package::adminList($filters, self::LIST_LIMIT),
            'total'     => Package::adminCount($filters),
            'filters'   => $filters,
        ]);
    }

    /**
     * 新增套餐页
     */
    public function create(): void
    {
        $this->view('admin.packages.form', [
            'pageTitle'   => '新增套餐',
            'isEdit'      => false,
            'package'     => null,
            'courses'     => Course::options(),
            'selectedIds' => [],
        ]);
    }

    /**
     * 保存新套餐
     */
    public function store(): void
    {
        Csrf::check();

        $data = $this->validated(null);

        $packageId = Package::create($data);

        Package::syncCourses($packageId, $this->courseIdsFromRequest());

        Log::recordOperation('package.create', 'package', $packageId, ['title' => $data['title']]);

        $this->success(url('/admin/packages'), '套餐已创建。');
    }

    /**
     * 编辑套餐页
     */
    public function edit(string $id): void
    {
        $package = Package::find((int) $id);

        if ($package === null) {
            $this->fail(url('/admin/packages'), '套餐不存在或已被删除。');
        }

        $this->view('admin.packages.form', [
            'pageTitle'   => '编辑套餐',
            'isEdit'      => true,
            'package'     => $package,
            'courses'     => Course::options(),
            'selectedIds' => Package::courseIds((int) $package['id']),
        ]);
    }

    /**
     * 保存套餐修改
     */
    public function update(string $id): void
    {
        Csrf::check();

        $packageId = (int) $id;

        if (Package::find($packageId) === null) {
            $this->fail(url('/admin/packages'), '套餐不存在或已被删除。');
        }

        $data = $this->validated($packageId);

        Package::update($packageId, $data);

        Package::syncCourses($packageId, $this->courseIdsFromRequest());

        Log::recordOperation('package.update', 'package', $packageId, ['title' => $data['title']]);

        $this->success(url('/admin/packages'), '套餐已保存。');
    }

    /**
     * 删除套餐（软删除）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $packageId = (int) $id;
        $package   = Package::find($packageId);

        if ($package === null) {
            $this->fail(url('/admin/packages'), '套餐不存在或已被删除。');
        }

        Package::softDelete($packageId);

        Log::recordOperation('package.delete', 'package', $packageId, [
            'title' => (string) $package['title'],
        ]);

        $this->success(url('/admin/packages'), '套餐已删除。');
    }

    /**
     * 从请求中解析课程多选（courses[]），返回去重后的正整数 ID
     *
     * @return array<int, int>
     */
    private function courseIdsFromRequest(): array
    {
        $raw = (array) Request::input('courses', []);

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $courseId): bool => $courseId > 0
        )));
    }

    /**
     * 校验表单并返回整理后的数据（价格统一转「分」）
     *
     * @return array<string, mixed>
     */
    private function validated(?int $packageId): array
    {
        $backUrl = $packageId === null
            ? url('/admin/packages/create')
            : url('/admin/packages/' . $packageId . '/edit');

        $title         = trim(Request::string('title'));
        $subtitle      = trim(Request::string('subtitle'));
        $summary       = trim(Request::string('summary'));
        $content       = (string) Request::input('content', '');
        $cover         = trim(Request::string('cover'));
        $priceRaw      = Request::string('price', '0');
        $originalRaw   = Request::string('original_price', '0');
        $status        = Request::string('status', 'draft');
        $sort          = Request::int('sort');
        $isRecommend   = Request::bool('is_recommend', false);

        // 回填时保留用户原始输入
        $input = [
            'title'          => $title,
            'subtitle'       => $subtitle,
            'summary'        => $summary,
            'content'        => $content,
            'cover'          => $cover,
            'price'          => $priceRaw,
            'original_price' => $originalRaw,
            'status'         => $status,
            'sort'           => (string) $sort,
            'is_recommend'   => $isRecommend ? '1' : '0',
            'courses'        => $this->courseIdsFromRequest(),
        ];

        $validator = $this->validator(Request::all())
            ->required('title', '套餐名称')
            ->max('title', 150, '套餐名称')
            ->max('subtitle', 255, '副标题')
            ->max('summary', 500, '简介')
            ->max('cover', 255, '封面图地址')
            ->in('status', array_keys(Package::STATUSES), '套餐状态');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), $input);
        }

        $price = $this->yuanToCents($priceRaw);
        if ($price < 0) {
            $this->fail($backUrl, '套餐售价不能为负数。', $input);
        }

        $originalPrice = $this->yuanToCents($originalRaw);
        if ($originalPrice < 0) {
            $this->fail($backUrl, '划线价不能为负数。', $input);
        }

        if ($originalPrice > 0 && $originalPrice < $price) {
            $this->fail($backUrl, '划线价不能低于套餐售价。', $input);
        }

        // 封面上传优先于地址；上传成功后替换旧的受管图片
        $oldCover = null;
        if ($packageId !== null) {
            $existingPackage = Package::find($packageId);
            $oldCover = $existingPackage === null ? null : (string) ($existingPackage['cover'] ?? '');
        }

        try {
            $uploadedCover = ImageStorage::saveUploaded('cover_file', 'covers', $oldCover);
        } catch (RuntimeException $e) {
            $this->fail($backUrl, $e->getMessage(), $input);
        }

        if ($uploadedCover !== null) {
            $cover = $uploadedCover;
        }

        return [
            'title'          => $title,
            'subtitle'       => $subtitle,
            'summary'        => $summary,
            'content'        => $content,
            'cover'          => $cover !== '' ? $cover : null,
            'price'          => $price,
            'original_price' => $originalPrice,
            'is_recommend'   => $isRecommend ? 1 : 0,
            'status'         => $status,
            'sort'           => $sort,
        ];
    }

    /**
     * 元 -> 分（接受小数，空值按 0 处理）
     */
    private function yuanToCents(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return 0;
        }

        return (int) round((float) $raw * 100);
    }
}
