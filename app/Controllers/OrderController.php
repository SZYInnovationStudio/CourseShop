<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Course;
use App\Models\Coupon;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Support\Csrf;
use App\Support\OrderNotifier;
use App\Support\Payment\PaymentManager;
use App\Support\Request;
use App\Support\Response;
use App\Support\Setting;
use Throwable;

/**
 * 订单：下单、订单详情、发起支付、支付状态查询
 */
final class OrderController extends Controller
{
    /** 我的订单每页条数 */
    private const PER_PAGE = 10;

    /**
     * 支付方式展示名（网关未启用时也能正确展示历史订单）
     *
     * @return array<string, string>
     */
    private static function payTypeLabels(): array
    {
        return [
            'wxpay'  => t('微信支付'),
            'alipay' => t('支付宝'),
            'free'   => t('免费开通'),
        ];
    }

    /**
     * 我的订单列表
     */
    public function index(): void
    {
        $user = $this->user();
        if ($user === null) {
            Response::redirect(url('/login'));
        }

        $userId = (int) $user['id'];

        Order::closeExpired();

        $page       = max(1, Request::int('page', 1));
        $total      = Order::countForUser($userId);
        $perPage    = self::PER_PAGE;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $orders     = Order::listForUser($userId, $perPage, ($page - 1) * $perPage);

        $this->view('orders.index', [
            'pageTitle'    => t('我的订单'),
            'orders'       => $orders,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'payTypeLabel' => self::payTypeLabels(),
        ]);
    }

    /**
     * 创建订单
     *
     * 免费课程直接开通；付费课程复用未过期的待支付订单，避免重复下单。
     */
    public function create(): void
    {
        Csrf::check();

        $user = $this->user();
        if ($user === null) {
            Response::redirect(url('/login'));
        }

        // ---------- 套餐下单 ----------
        $packageId = Request::int('package_id');
        if ($packageId > 0) {
            $this->createPackageOrder($user, $packageId);

            return;
        }

        $userId   = (int) $user['id'];
        $courseId = Request::int('course_id');
        $course   = $courseId > 0 ? Course::findPublished($courseId) : null;

        if ($course === null) {
            abort(404, t('课程不存在或已下架。'));
        }

        if (Enrollment::hasAccess($userId, $courseId)) {
            $this->success(url('/my/courses'), t('你已拥有该课程，可直接开始学习。'));
        }

        Order::closeExpired();

        $amount = (int) $course['price'];

        // ---------- 免费课程：直接开通 ----------
        if ($amount <= 0) {
            $orderId = $this->insertOrder($user, $course, 0, 'free');
            $freeOrder = Order::findById($orderId);

            Order::markPaid($orderId, null, null, '免费课程直接开通');
            PaymentLog::record($orderId, (string) ($freeOrder['order_no'] ?? ''), 'create', 'success', '免费课程直接开通', Request::ip());

            if ($freeOrder !== null) {
                OrderNotifier::notifyPaid($freeOrder);
            }

            $this->success(url('/my/courses'), t('课程已开通，开始学习吧！'));
        }

        // ---------- 付费课程：复用未过期待支付订单 ----------
        $pending = Order::pendingForUserCourse($userId, $courseId);
        if ($pending !== null) {
            $this->success(url('/order/' . $pending['order_no']), t('你有一笔待支付订单，请继续完成支付。'));
        }

        $orderId = $this->insertOrder($user, $course, $amount, '');

        $order   = Order::findById($orderId);
        $orderNo = (string) ($order['order_no'] ?? '');

        PaymentLog::record($orderId, $orderNo, 'create', 'success', (string) json_encode([
            'course_id' => $courseId,
            'amount'    => $amount,
        ], JSON_UNESCAPED_UNICODE), Request::ip());

        Response::redirect(url('/order/' . $orderNo));
    }

    /**
     * 订单详情
     */
    public function show(string $orderNo): void
    {
        $order = $this->resolveOrder($orderNo);

        if ($order === null) {
            abort(404, t('订单不存在。'));
        }

        $order = $this->closeIfExpired($order);

        $gateway  = PaymentManager::gateway();
        $payable  = in_array((string) $order['status'], [Order::STATUS_PENDING, Order::STATUS_PAYING], true)
            && !$this->isExpired($order);

        $this->view('orders.show', [
            'pageTitle'      => t('订单详情'),
            // 返回固定回订单列表
            'backUrl'        => url('/orders'),
            'order'          => $order,
            'statusLabel'    => t(Order::label((string) $order['status'])),
            'payTypeLabel'   => self::payTypeLabels(),
            'methods'        => $gateway->methods(),
            'gatewayEnabled' => $gateway->enabled() && (int) $order['amount'] > 0,
            'payable'        => $payable,
            'expired'        => $this->isExpired($order),
        ]);
    }

    /**
     * 发起支付：生成跳转到易支付收银台的自动提交表单
     */
    public function pay(string $orderNo): void
    {
        Csrf::check();

        $order = $this->resolveOrder($orderNo);

        if ($order === null) {
            abort(404, t('订单不存在。'));
        }

        if (in_array((string) $order['status'], [Order::STATUS_PAID, Order::STATUS_COMPLETED], true)) {
            $this->success(url('/my/courses'), t('该订单已完成支付。'));
        }

        if (!in_array((string) $order['status'], [Order::STATUS_PENDING, Order::STATUS_PAYING], true) || $this->isExpired($order)) {
            Order::close((int) $order['id']);
            $this->fail(url('/order/' . $orderNo), t('订单已关闭，请重新下单。'));
        }

        $payType = Request::string('pay_type');
        $gateway = PaymentManager::gateway();

        if (!$gateway->enabled()) {
            $this->fail(url('/order/' . $orderNo), t('支付通道尚未配置，请联系客服。'));
        }

        $methods = $gateway->methods();
        if (!isset($methods[$payType])) {
            $this->fail(url('/order/' . $orderNo), t('请选择有效的支付方式。'));
        }

        Order::markPaying((int) $order['id'], $payType);

        try {
            $payment = $gateway->createPayment(
                $order,
                $payType,
                url('/payment/notify/epay'),
                url('/order/' . $orderNo . '/return')
            );
        } catch (Throwable $e) {
            \App\Support\Logger::error('创建支付失败：' . $e->getMessage());
            PaymentLog::record((int) $order['id'], $orderNo, 'create', 'fail', $e->getMessage(), Request::ip());
            $this->fail(url('/order/' . $orderNo), t('发起支付失败，请稍后重试或联系客服。'));
        }

        PaymentLog::record((int) $order['id'], $orderNo, 'create', 'success', (string) json_encode($payment, JSON_UNESCAPED_UNICODE), Request::ip());

        // 该视图不使用布局，仅输出一个自动提交的表单
        \App\Support\View::render('orders.redirect', [
            'action' => $payment['url'],
            'params' => $payment['params'],
        ], null);
    }

    /**
     * 应用优惠券
     *
     * 仅当订单处于待支付且尚未使用优惠券时生效；每张订单只允许使用一张优惠券，不支持更换。
     * 券在此处仅写入订单，支付成功后由 Order::markPaid 调用 Coupon::redeem 核销。
     * 「支付中」订单禁止改价，避免回调金额与订单金额不一致导致无法开通。
     */
    public function applyCoupon(string $orderNo): void
    {
        Csrf::check();

        $order = $this->resolveOrder($orderNo);

        if ($order === null) {
            abort(404, t('订单不存在。'));
        }

        $backUrl = url('/order/' . $orderNo);

        if ((string) $order['status'] !== Order::STATUS_PENDING || $this->isExpired($order)) {
            $this->fail($backUrl, t('当前订单不可使用优惠券。'));
        }

        if (!empty($order['coupon_id'])) {
            $this->fail($backUrl, t('该订单已使用优惠券，无法重复使用。'));
        }

        $code = strtoupper(trim(Request::string('code')));
        if ($code === '') {
            $this->fail($backUrl, t('请输入优惠码。'));
        }

        $coupon = Coupon::findByCode($code);
        if ($coupon === null) {
            $this->fail($backUrl, t('优惠码无效。'));
        }

        $user     = $this->user();
        $userId   = (int) $user['id'];
        $courseId = (int) $order['course_id'];
        $base     = (int) $order['amount'];

        $error = Coupon::usableError($coupon, $userId, $courseId, $base);
        if ($error !== null) {
            $this->fail($backUrl, $error);
        }

        // 应用阶段占用校验：同一张券不允许同时挂在多笔未关闭订单上，
        // 否则可在核销（限额统计）生效前对多笔订单重复打折。
        if (Order::hasOpenCouponOrder($userId, (int) $coupon['id'], (int) $order['id'])) {
            $this->fail($backUrl, t('该优惠券已用于其他未完成订单，请先完成或关闭该订单。'));
        }

        $discount = Coupon::discountFor($coupon, $base);
        if ($discount <= 0) {
            $this->fail($backUrl, t('该优惠券对当前订单无抵扣。'));
        }

        $newAmount = max(0, $base - $discount);

        if (!Order::applyCoupon((int) $order['id'], (int) $coupon['id'], $userId, $base, $newAmount, $discount)) {
            $this->fail($backUrl, t('优惠券应用失败，请刷新后重试。'));
        }

        PaymentLog::record((int) $order['id'], $orderNo, 'coupon', 'success', (string) json_encode([
            'coupon_id' => (int) $coupon['id'],
            'code'      => (string) $coupon['code'],
            'discount'  => $discount,
            'amount'    => $newAmount,
        ], JSON_UNESCAPED_UNICODE), Request::ip());

        // 全额抵扣：应付为 0，无需再走支付网关，直接核销优惠券并开通订单（CS-06）
        if ($newAmount === 0) {
            $opened = Order::markPaid((int) $order['id'], null, null, '优惠券全额抵扣，直接开通');

            if ($opened) {
                $paidOrder = Order::findById((int) $order['id']);
                if ($paidOrder !== null) {
                    OrderNotifier::notifyPaid($paidOrder);
                }

                $this->success(url('/my/courses'), t('优惠券已全额抵扣，订单已开通，开始学习吧！'));
            }

            $this->success($backUrl, t('优惠券已应用，请查看订单状态。'));
        }

        $this->success($backUrl, t('优惠券已应用，应付金额已更新。'));
    }

    /**
     * 支付状态轮询接口
     */
    public function status(string $orderNo): void
    {
        $order = $this->resolveOrder($orderNo);

        if ($order === null) {
            Response::json(['code' => 1, 'message' => t('订单不存在')], 404);
        }

        $order = $this->closeIfExpired($order);
        $status = (string) $order['status'];

        Response::json([
            'code'   => 0,
            'status' => $status,
            'label'  => Order::label($status),
            'paid'   => in_array($status, [Order::STATUS_PAID, Order::STATUS_COMPLETED], true),
        ]);
    }

    /**
     * 查询当前用户的订单，找不到返回 null
     *
     * @return array<string, mixed>|null
     */
    private function resolveOrder(string $orderNo): ?array
    {
        $user = $this->user();
        if ($user === null) {
            Response::redirect(url('/login'));
        }

        return Order::findByNoForUser($orderNo, (int) $user['id']);
    }

    /**
     * 订单是否已超时未支付
     *
     * @param array<string, mixed> $order
     */
    private function isExpired(array $order): bool
    {
        if (!in_array((string) $order['status'], [Order::STATUS_PENDING, Order::STATUS_PAYING], true)) {
            return false;
        }

        $expireAt = $order['expire_at'] ?? null;

        return $expireAt !== null && strtotime((string) $expireAt) <= time();
    }

    /**
     * 若订单已超时则关闭，并返回最新订单数据
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function closeIfExpired(array $order): array
    {
        if ($this->isExpired($order)) {
            Order::close((int) $order['id'], '订单超时未支付，访问时自动关闭');

            return Order::findById((int) $order['id']) ?? $order;
        }

        return $order;
    }

    /**
     * 套餐下单
     *
     * 免费套餐直接开通；付费套餐复用未过期的待支付订单，避免重复下单。
     *
     * @param array<string, mixed> $user
     */
    private function createPackageOrder(array $user, int $packageId): void
    {
        if (!Setting::bool('packages_enabled', true)) {
            $this->fail(url('/courses'), t('套餐功能已关闭，暂不可购买。'));
        }

        $package = Package::findPublished($packageId);

        if ($package === null) {
            abort(404, t('套餐不存在或已下架。'));
        }

        $userId    = (int) $user['id'];
        $courseIds = Package::courseIds($packageId);

        if ($courseIds === []) {
            abort(404, t('该套餐暂无可售课程。'));
        }

        // 已拥有套餐内全部课程时无需重复购买
        $ownedAll = true;
        foreach ($courseIds as $courseId) {
            if (!Enrollment::hasAccess($userId, $courseId)) {
                $ownedAll = false;
                break;
            }
        }

        if ($ownedAll) {
            $this->success(url('/my/courses'), t('你已拥有该套餐内全部课程，可直接开始学习。'));
        }

        Order::closeExpired();

        $amount = (int) $package['price'];

        // ---------- 免费套餐：直接开通 ----------
        if ($amount <= 0) {
            $orderId   = $this->insertOrder($user, $package, 0, 'free', 'package');
            $freeOrder = Order::findById($orderId);

            Order::markPaid($orderId, null, null, '免费套餐直接开通');
            PaymentLog::record($orderId, (string) ($freeOrder['order_no'] ?? ''), 'create', 'success', '免费套餐直接开通', Request::ip());

            if ($freeOrder !== null) {
                OrderNotifier::notifyPaid($freeOrder);
            }

            $this->success(url('/my/courses'), t('套餐已开通，开始学习吧！'));
        }

        // ---------- 付费套餐：复用未过期待支付订单 ----------
        $pending = Order::pendingForUserPackage($userId, $packageId);
        if ($pending !== null) {
            $this->success(url('/order/' . $pending['order_no']), t('你有一笔待支付订单，请继续完成支付。'));
        }

        $orderId = $this->insertOrder($user, $package, $amount, '', 'package');

        $order   = Order::findById($orderId);
        $orderNo = (string) ($order['order_no'] ?? '');

        PaymentLog::record($orderId, $orderNo, 'create', 'success', (string) json_encode([
            'package_id' => $packageId,
            'amount'     => $amount,
        ], JSON_UNESCAPED_UNICODE), Request::ip());

        Response::redirect(url('/order/' . $orderNo));
    }

    /**
     * 写入订单，必要时重试以避免订单号冲突
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $item 课程或套餐行（需含 id/title/original_price）
     * @param string               $kind course 课程订单 / package 套餐订单
     */
    private function insertOrder(array $user, array $item, int $amount, string $payType, string $kind = 'course'): int
    {
        $originalAmount = max(0, (int) $item['original_price']);

        $data = [
            'user_id'         => (int) $user['id'],
            'course_id'       => $kind === 'course' ? (int) $item['id'] : null,
            'package_id'      => $kind === 'package' ? (int) $item['id'] : null,
            'course_title'    => (string) $item['title'],
            'amount'          => $amount,
            'original_amount' => $originalAmount,
            'discount_amount' => max(0, $originalAmount - $amount),
            'pay_type'        => $payType,
            'client_ip'       => Request::ip(),
            'device'          => Request::deviceType(),
            'expire_at'       => Order::defaultExpireAt(),
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $data['order_no'] = Order::generateNo();

            if (Order::findByNo((string) $data['order_no']) !== null) {
                continue;
            }

            return Order::create($data);
        }

        abort(500, t('生成订单号失败，请稍后重试。'));
    }
}
