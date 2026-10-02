<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Coupon;
use App\Models\Course;
use App\Models\Log;
use App\Support\Csrf;
use App\Support\Request;

/**
 * 后台优惠券管理
 *
 * 优惠券按「优惠码」对外发放：满减券（fixed）输入减免金额（元），
 * 折扣券（percent）输入减免百分比（1-99，例如 20 表示立减 20%）。
 * 可限定课程、设置最低可用金额、发行总量、每人限用次数与生效时间。
 */
final class CouponController extends AdminController
{
    /**
     * 优惠券列表
     */
    public function index(): void
    {
        $filters = [
            'keyword'   => Request::string('q'),
            'type'      => Request::string('type'),
            'is_active' => Request::string('is_active'),
        ];

        $this->view('admin.coupons.index', [
            'pageTitle' => '优惠券管理',
            'coupons'   => Coupon::adminAll($filters),
            'filters'   => $filters,
        ]);
    }

    /**
     * 新增优惠券页
     */
    public function create(): void
    {
        $this->view('admin.coupons.form', [
            'pageTitle' => '新增优惠券',
            'isEdit'    => false,
            'coupon'    => null,
            'courses'   => Course::options(),
        ]);
    }

    /**
     * 保存新优惠券
     */
    public function store(): void
    {
        Csrf::check();

        $data = $this->validated(null);

        $couponId = Coupon::create($data);

        Log::recordOperation('coupon.create', 'coupon', $couponId, ['code' => $data['code']]);

        $this->success(url('/admin/coupons'), '优惠券已创建。');
    }

    /**
     * 编辑优惠券页
     */
    public function edit(string $id): void
    {
        $coupon = Coupon::find((int) $id);

        if ($coupon === null) {
            $this->fail(url('/admin/coupons'), '优惠券不存在或已被删除。');
        }

        $this->view('admin.coupons.form', [
            'pageTitle' => '编辑优惠券',
            'isEdit'    => true,
            'coupon'    => $coupon,
            'courses'   => Course::options(),
        ]);
    }

    /**
     * 保存优惠券修改
     */
    public function update(string $id): void
    {
        Csrf::check();

        $couponId = (int) $id;

        if (Coupon::find($couponId) === null) {
            $this->fail(url('/admin/coupons'), '优惠券不存在或已被删除。');
        }

        $data = $this->validated($couponId);

        Coupon::update($couponId, $data);

        Log::recordOperation('coupon.update', 'coupon', $couponId, ['code' => $data['code']]);

        $this->success(url('/admin/coupons'), '优惠券已保存。');
    }

    /**
     * 删除优惠券（软删除）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $couponId = (int) $id;
        $coupon   = Coupon::find($couponId);

        if ($coupon === null) {
            $this->fail(url('/admin/coupons'), '优惠券不存在或已被删除。');
        }

        Coupon::softDelete($couponId);

        Log::recordOperation('coupon.delete', 'coupon', $couponId, [
            'code' => (string) $coupon['code'],
        ]);

        $this->success(url('/admin/coupons'), '优惠券已删除。');
    }

    /**
     * 校验表单并返回整理后的数据（金额统一转「分」）
     *
     * @return array<string, mixed>
     */
    private function validated(?int $couponId): array
    {
        $backUrl = $couponId === null
            ? url('/admin/coupons/create')
            : url('/admin/coupons/' . $couponId . '/edit');

        $code         = strtoupper(trim(Request::string('code')));
        $name         = trim(Request::string('name'));
        $type         = Request::string('type', Coupon::TYPE_FIXED);
        $valueRaw     = Request::string('value');
        $minAmountRaw = Request::string('min_amount', '0');
        $courseId     = max(0, Request::int('course_id'));
        $totalQty     = max(0, Request::int('total_quantity'));
        $perUserLimit = max(0, Request::int('per_user_limit'));
        $startAt      = $this->normalizeDateTime(Request::string('start_at'));
        $endAt        = $this->normalizeDateTime(Request::string('end_at'));
        $isActive     = Request::bool('is_active', false);

        // 回填时保留用户原始输入
        $input = [
            'code'           => $code,
            'name'           => $name,
            'type'           => $type,
            'value'          => $valueRaw,
            'min_amount'     => $minAmountRaw,
            'course_id'      => (string) $courseId,
            'total_quantity' => (string) $totalQty,
            'per_user_limit' => (string) $perUserLimit,
            'start_at'       => Request::string('start_at'),
            'end_at'         => Request::string('end_at'),
            'is_active'      => $isActive ? '1' : '0',
        ];

        $validator = $this->validator(Request::all())
            ->required('code', '优惠码')
            ->max('code', 32, '优惠码')
            ->required('name', '优惠券名称')
            ->max('name', 100, '优惠券名称')
            ->in('type', array_keys(Coupon::TYPE_LABELS), '优惠券类型');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), $input);
        }

        if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            $this->fail($backUrl, '优惠码只能包含字母、数字、下划线与短横线，长度 3-32 位。', $input);
        }

        if (Coupon::codeExists($code, $couponId ?? 0)) {
            $this->fail($backUrl, '该优惠码已存在，请更换。', $input);
        }

        // 折扣券按百分比（1-99）解析，满减券按「元」解析并转为「分」
        if ($type === Coupon::TYPE_PERCENT) {
            if (preg_match('/^\d+$/', trim($valueRaw)) !== 1) {
                $this->fail($backUrl, '折扣百分比需为 1-99 的整数。', $input);
            }

            $value = (int) $valueRaw;
            if ($value < 1 || $value > 99) {
                $this->fail($backUrl, '折扣百分比需在 1-99 之间。', $input);
            }
        } else {
            $value = $this->yuanToCents($valueRaw);
            if ($value === null) {
                $this->fail($backUrl, '减免金额必须为有效金额，最多两位小数。', $input);
            }
            if ($value <= 0) {
                $this->fail($backUrl, '减免金额需大于 0。', $input);
            }
        }

        $minAmount = $this->yuanToCents($minAmountRaw);
        if ($minAmount === null) {
            $this->fail($backUrl, '最低可用金额必须为有效金额，最多两位小数。', $input);
        }
        if ($minAmount < 0) {
            $this->fail($backUrl, '最低可用金额不能为负数。', $input);
        }

        if ($startAt !== null && $endAt !== null && strtotime($startAt) > strtotime($endAt)) {
            $this->fail($backUrl, '生效时间不能晚于失效时间。', $input);
        }

        return [
            'code'           => $code,
            'name'           => $name,
            'type'           => $type,
            'value'          => $value,
            'min_amount'     => $minAmount,
            'course_id'      => $courseId > 0 ? $courseId : null,
            'total_quantity' => $totalQty,
            'per_user_limit' => $perUserLimit,
            'start_at'       => $startAt,
            'end_at'         => $endAt,
            'is_active'      => $isActive ? 1 : 0,
        ];
    }

    /**
     * 元 -> 分（接受最多两位小数，空值按 0 处理，格式非法返回 null）
     */
    private function yuanToCents(string $raw): ?int
    {
        return yuan_to_cents($raw);
    }

    /**
     * 归一化时间：支持 datetime-local（Y-m-dTH:i），留空返回 null
     */
    private function normalizeDateTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime(str_replace('T', ' ', $raw));

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
