<?php

declare(strict_types=1);

/**
 * 订单详情与支付
 *
 * @var array<string, mixed> $order 订单信息
 * @var string $statusLabel 订单状态文案
 * @var array<string, string> $payTypeLabel 支付方式文案映射
 * @var array<string, string> $methods 可用支付方式
 * @var bool $gatewayEnabled 支付通道是否可用
 * @var bool $payable 订单是否可支付
 * @var bool $expired 订单是否已超时
 */

use App\Models\Order;
use App\Support\Setting;

$orderNo       = (string) $order['order_no'];
$status        = (string) $order['status'];
$amount        = (int) $order['amount'];
$courseId      = (int) $order['course_id'];
$packageId     = (int) ($order['package_id'] ?? 0);
$isPackage     = $packageId > 0;
// 套餐订单 course_id 为空，商品链接需指向套餐详情页，否则会生成 /course/0 死链
$itemUrl       = $isPackage ? url('/package/' . $packageId) : url('/course/' . $courseId);
// 套餐入口关闭时不再跳转套餐详情，避免死链
$itemLink      = $isPackage && !Setting::bool('packages_enabled', true) ? '' : $itemUrl;
$itemLabel     = $isPackage ? t('套餐') : t('课程');
$isPaid        = in_array($status, [Order::STATUS_PAID, Order::STATUS_COMPLETED], true);
$isClosed      = in_array($status, [Order::STATUS_CLOSED, Order::STATUS_REFUNDED], true);
$payType       = (string) ($order['pay_type'] ?? '');
$payLabel      = $payTypeLabel[$payType] ?? '—';
$statusBadges  = [
    Order::STATUS_PENDING   => 'badge--warning',
    Order::STATUS_PAYING    => 'badge--warning',
    Order::STATUS_PAID      => 'badge--success',
    Order::STATUS_COMPLETED => 'badge--success',
    Order::STATUS_REFUNDED  => 'badge--info',
];
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e(t('订单详情')) ?></h1>
    <p class="page-head__desc"><?= e(t('订单号 %s', [$orderNo])) ?></p>
</div>

<div class="container">
    <div class="order-layout">
        <div>
            <div class="card">
                <div class="card__header flex-between">
                    <span><?= e(t('订单信息')) ?></span>
                    <span class="badge <?= e($statusBadges[$status] ?? 'badge') ?>"><?= e($statusLabel) ?></span>
                </div>
                <div class="card__body">
                    <ul class="list-plain">
                        <li class="flex-between">
                            <span class="text-muted"><?= e($itemLabel) ?></span>
                            <span><?php if ($itemLink !== ''): ?><a href="<?= e($itemLink) ?>"><?= e($order['course_title']) ?></a><?php else: ?><?= e($order['course_title']) ?><?php endif; ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('订单号')) ?></span>
                            <span class="text-faint"><?= e($orderNo) ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('应付金额')) ?></span>
                            <span class="price price--accent"><?= price_html($amount) ?></span>
                        </li>
                        <?php if ((int) $order['original_amount'] > $amount): ?>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('原价')) ?></span>
                                <span class="price-original">&yen;<?= format_money((int) $order['original_amount']) ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if ((int) $order['discount_amount'] > 0): ?>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('优惠')) ?></span>
                                <span>-&yen;<?= format_money((int) $order['discount_amount']) ?></span>
                            </li>
                        <?php endif; ?>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('支付方式')) ?></span>
                            <span><?= e($payLabel) ?></span>
                        </li>
                        <li class="flex-between">
                            <span class="text-muted"><?= e(t('下单时间')) ?></span>
                            <span class="text-faint"><?= e($order['created_at']) ?></span>
                        </li>
                        <?php if (!empty($order['paid_at'])): ?>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('支付时间')) ?></span>
                                <span class="text-faint"><?= e($order['paid_at']) ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if ($payable && !empty($order['expire_at'])): ?>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('支付截止')) ?></span>
                                <span class="text-faint"><?= e($order['expire_at']) ?></span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <aside>
            <div class="buy-card">
                <?php if ($isPaid): ?>
                    <p class="text-center mb-4"><span class="badge badge--success"><?= e(t('支付成功')) ?></span></p>
                    <p class="text-center text-muted"><?= e(t('课程已开通，随时可以开始学习。')) ?></p>
                    <a class="btn btn--lg btn--block" href="<?= url('/my/courses') ?>"><?= e(t('开始学习')) ?></a>
                <?php elseif ($payable && $gatewayEnabled): ?>
                    <div class="buy-card__price">
                        <span class="price price--lg price--accent"><?= price_html($amount) ?></span>
                    </div>

                    <?php if (empty($order['coupon_id'])): ?>
                        <form method="post" action="<?= url('/order/' . $orderNo . '/coupon') ?>" class="mb-4">
                            <?= csrf_field() ?>
                            <label class="form-label" for="coupon-code"><?= e(t('优惠码')) ?></label>
                            <div class="flex-center">
                                <input class="input grow" id="coupon-code" type="text" name="code" maxlength="32"
                                       placeholder="<?= e(t('输入优惠码')) ?>" autocomplete="off">
                                <button class="btn btn--outline" type="submit"><?= e(t('使用')) ?></button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="mb-4"><span class="badge badge--success"><?= e(t('已使用优惠券')) ?></span></p>
                    <?php endif; ?>

                    <form method="post" action="<?= url('/order/' . $orderNo . '/pay') ?>" class="pay-form">
                        <?= csrf_field() ?>

                        <p class="form-label"><?= e(t('选择支付方式')) ?></p>
                        <?php foreach ($methods as $value => $label): ?>
                            <label class="checkbox pay-option">
                                <input type="radio" name="pay_type" value="<?= e($value) ?>"
                                    <?= (string) $value === $payType || count($methods) === 1 ? 'checked' : '' ?>>
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>

                        <button class="btn btn--lg btn--block" type="submit"><?= e(t('去支付')) ?></button>
                    </form>

                    <p class="form-hint text-center"><?= e(t('支付完成后请回到本页，状态会自动更新。')) ?></p>
                <?php elseif ($payable): ?>
                    <div class="alert alert--warning" role="alert">
                        <span class="grow"><?= e(t('支付通道尚未配置，请联系客服。')) ?></span>
                    </div>
                <?php elseif ($isClosed): ?>
                    <div class="alert alert--warning" role="alert">
                        <span class="grow"><?= $expired ? e(t('订单已超时关闭。')) : e(t('该订单已关闭。')) ?></span>
                    </div>
                    <form method="post" action="<?= url('/order/create') ?>">
                        <?= csrf_field() ?>
                        <?php if ($isPackage): ?>
                            <input type="hidden" name="package_id" value="<?= $packageId ?>">
                        <?php else: ?>
                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                        <?php endif; ?>
                        <button class="btn btn--lg btn--block" type="submit"><?= e(t('重新下单')) ?></button>
                    </form>
                <?php endif; ?>

                <ul class="buy-card__list">
                    <li><span class="list-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span> <?= e(t('支持微信支付 / 支付宝')) ?></li>
                    <li><span class="list-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span> <?= e(t('支付成功后立即开通课程')) ?></li>
                    <li><span class="list-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span> <?= e(t('如需帮助可提交工单联系客服')) ?></li>
                </ul>
            </div>
        </aside>
    </div>
</div>

<?php if ($payable && $gatewayEnabled): ?>
    <?php // 支付中：前端定时轮询订单状态，支付成功或订单关闭后刷新页面 ?>
    <div data-order-watch
         data-status-url="<?= e(url('/order/' . $orderNo . '/status')) ?>"
         hidden></div>
<?php endif; ?>
