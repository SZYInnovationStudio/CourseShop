<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Announcement;
use App\Models\Category;
use App\Models\Course;
use App\Models\Package;
use App\Support\Cache;
use App\Support\Config;
use App\Support\Logger;
use Throwable;

/**
 * 前台首页
 */
final class HomeController extends Controller
{
    public function index(): void
    {
        $categories    = [];
        $courses       = [];
        $announcements = [];
        $packages      = [];
        $totalCourses  = 0;

        // 首页「热门/推荐课程」与课程总数读取频繁、变化不频繁，走缓存；
        // 后台改动课程时 Course 模型会主动失效这两个键。
        $ttl = (int) Config::get('cache.ttl', 300);

        try {
            $categories    = Category::all();
            $courses       = Cache::remember(
                Course::CACHE_KEY_RECOMMENDED,
                $ttl,
                static fn (): array => Course::search([], 'recommended', 8, 0)
            );
            $announcements = Announcement::latest(3);
            $packages      = Package::recommend(3);
            $totalCourses  = Cache::remember(
                Course::CACHE_KEY_TOTAL,
                $ttl,
                static fn (): int => Course::count()
            );
        } catch (Throwable $e) {
            // 数据库尚未初始化时不阻断首页渲染
            Logger::error('首页数据加载失败：' . $e->getMessage());
        }

        $this->view('home.index', [
            'pageTitle'     => null,
            'categories'    => $categories,
            'courses'       => $courses,
            'announcements' => $announcements,
            'packages'      => $packages,
            'totalCourses'  => $totalCourses,
        ]);
    }
}
