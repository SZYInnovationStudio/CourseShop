<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Enrollment;
use App\Models\Package;
use App\Support\Auth;
use App\Support\Payment\PaymentManager;
use App\Support\Request;

/**
 * 前台课程套餐
 */
final class PackageController extends Controller
{
    private const PER_PAGE = 12;

    /**
     * 套餐列表
     */
    public function index(): void
    {
        $page       = max(1, Request::int('page', 1));
        $total      = Package::publishedCount();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min($page, $totalPages);

        $packages = Package::publishedList(self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->view('packages.index', [
            'pageTitle'  => '优惠套餐',
            'packages'   => $packages,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * 套餐详情
     */
    public function show(string $id): void
    {
        $packageId = (int) $id;
        $package   = $packageId > 0 ? Package::findPublished($packageId) : null;

        if ($package === null) {
            abort(404, '套餐不存在或已下架。');
        }

        $courses = Package::courseList($packageId);

        if ($courses === []) {
            abort(404, '该套餐暂无可售课程。');
        }

        $userId = Auth::id();

        // 逐一检查是否已拥有，用于提示「已购买」与判断是否已拥有全部课程
        $ownedIds = [];
        $coursesTotal = 0;
        foreach ($courses as $course) {
            $courseId = (int) $course['id'];
            $coursesTotal += (int) $course['price'];

            if ($userId !== null && Enrollment::hasAccess($userId, $courseId)) {
                $ownedIds[$courseId] = true;
            }
        }

        $hasAll = count($ownedIds) === count($courses);

        $gateway = PaymentManager::gateway();

        $this->view('packages.show', [
            'pageTitle'      => (string) $package['title'],
            'bodyClass'      => 'page-package',
            'package'        => $package,
            'courses'        => $courses,
            'ownedIds'       => $ownedIds,
            'hasAll'         => $hasAll,
            'coursesTotal'   => $coursesTotal,
            // 按当前实际可用的支付通道展示，避免与订单页提示不一致（CS-26）
            'paymentMethods' => $gateway->methods(),
            'paymentEnabled' => $gateway->enabled(),
        ]);
    }
}
