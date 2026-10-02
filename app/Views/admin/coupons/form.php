<?php

declare(strict_types=1);

/**
 * 后台优惠券管理 - 新增 / 编辑表单
 *
 * @var bool $isEdit 是否为编辑模式
 * @var array<string, mixed>|null $coupon 优惠券记录（新增时为 null）
 * @var array<int, array<string, mixed>> $courses 可选择的课程列表
 */

use App\Models\Coupon;

$couponId = $isEdit ? (int) ($coupon['id'] ?? 0) : 0;
$action   = $isEdit
    ? url('/admin/coupons/' . $couponId)
    : url('/admin/coupons');

$code = (string) old('code', $isEdit ? (string) ($coupon['code'] ?? '') : '');
$name = (string) old('name', $isEdit ? (string) ($coupon['name'] ?? '') : '');
$type = (string) old('type', $isEdit ? (string) ($coupon['type'] ?? Coupon::TYPE_FIXED) : Coupon::TYPE_FIXED);

$valueDefault = '';
if ($isEdit) {
    $valueDefault = (string) $coupon['type'] === Coupon::TYPE_PERCENT
        ? (string) (int) $coupon['value']
        : format_money((int) $coupon['value']);
}
$value = (string) old('value', $valueDefault);

$minAmountDefault = $isEdit ? format_money((int) $coupon['min_amount']) : '0';
$minAmount        = (string) old('min_amount', $minAmountDefault);

$courseId = (int) old('course_id', $isEdit ? (int) ($coupon['course_id'] ?? 0) : 0);

$totalQuantity = (string) old('total_quantity', $isEdit ? (string) (int) $coupon['total_quantity'] : '0');
$perUserLimit  = (string) old('per_user_limit', $isEdit ? (string) (int) $coupon['per_user_limit'] : '1');

$startRaw = $isEdit ? (string) ($coupon['start_at'] ?? '') : '';
$startTmp = $startRaw !== '' ? strtotime($startRaw) : false;
$startAt  = (string) old('start_at', $startTmp !== false ? date('Y-m-d\TH:i', $startTmp) : '');

$endRaw = $isEdit ? (string) ($coupon['end_at'] ?? '') : '';
$endTmp = $endRaw !== '' ? strtotime($endRaw) : false;
$endAt  = (string) old('end_at', $endTmp !== false ? date('Y-m-d\TH:i', $endTmp) : '');

$activeOld = old('is_active', $isEdit ? (string) (int) $coupon['is_active'] : '1');
$isActive  = in_array((string) $activeOld, ['1', 'on', 'true', 'yes'], true);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title"><?= $isEdit ? '编辑优惠券' : '新增优惠券' ?></h2>
        <p class="admin-page-head__desc">
            满减券填写减免金额（元），折扣券填写减免百分比（1-99，例如 20 表示立减 20%）。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/coupons') ?>">返回列表</a>
    </div>
</div>

<form method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">基本信息</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="code">优惠码<span class="required">*</span></label>
                    <input class="input" id="code" type="text" name="code" maxlength="32"
                           value="<?= e($code) ?>" placeholder="例如：WELCOME20" required>
                    <p class="form-hint">仅支持字母、数字、下划线与短横线，保存时自动转为大写。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="name">名称<span class="required">*</span></label>
                    <input class="input" id="name" type="text" name="name" maxlength="100"
                           value="<?= e($name) ?>" placeholder="例如：新用户立减 20 元" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="type">类型<span class="required">*</span></label>
                    <select class="select" id="type" name="type" required>
                        <?php foreach (Coupon::TYPE_LABELS as $valueKey => $label): ?>
                            <option value="<?= e($valueKey) ?>"<?= $type === $valueKey ? ' selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="value">优惠力度<span class="required">*</span></label>
                    <input class="input" id="value" type="text" name="value" inputmode="decimal"
                           value="<?= e($value) ?>" placeholder="满减填 20（元），折扣填 20（%）" required>
                    <p class="form-hint">满减券填减免金额（元，可含小数）；折扣券填减免百分比（1-99）。</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="min_amount">最低可用金额（元）</label>
                    <input class="input" id="min_amount" type="text" name="min_amount" inputmode="decimal"
                           value="<?= e($minAmount) ?>" placeholder="0 表示不限">
                    <p class="form-hint">订单金额达到该值才可使用，0 表示不限。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="course_id">限定课程</label>
                    <select class="select" id="course_id" name="course_id">
                        <option value="0"<?= $courseId === 0 ? ' selected' : '' ?>>全场通用</option>
                        <?php foreach ($courses as $course): ?>
                            <?php $optionId = (int) $course['id']; ?>
                            <option value="<?= $optionId ?>"<?= $courseId === $optionId ? ' selected' : '' ?>>
                                <?= e((string) $course['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">发放与生效</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="total_quantity">发行总量</label>
                    <input class="input" id="total_quantity" type="number" name="total_quantity" min="0"
                           value="<?= e($totalQuantity) ?>" placeholder="0 表示不限">
                </div>

                <div class="form-group">
                    <label class="form-label" for="per_user_limit">每人限用次数</label>
                    <input class="input" id="per_user_limit" type="number" name="per_user_limit" min="0"
                           value="<?= e($perUserLimit) ?>" placeholder="0 表示不限">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="start_at">生效时间</label>
                    <input class="input" id="start_at" type="datetime-local" name="start_at"
                           value="<?= e($startAt) ?>">
                    <p class="form-hint">留空表示立即生效。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="end_at">失效时间</label>
                    <input class="input" id="end_at" type="datetime-local" name="end_at"
                           value="<?= e($endAt) ?>">
                    <p class="form-hint">留空表示长期有效。</p>
                </div>
            </div>

            <div class="form-group">
                <span class="form-label">状态</span>
                <label class="checkbox" for="is_active">
                    <input type="checkbox" id="is_active" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
                    <span>启用（启用后用户可凭优惠码使用）</span>
                </label>
            </div>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit"><?= $isEdit ? '保存修改' : '创建优惠券' ?></button>
        <a class="btn btn--ghost" href="<?= url('/admin/coupons') ?>">取消</a>
    </div>
</form>
