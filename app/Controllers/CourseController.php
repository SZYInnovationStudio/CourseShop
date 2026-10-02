<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Support\Auth;
use App\Support\Request;

/**
 * 前台课程
 */
final class CourseController extends Controller
{
    private const PER_PAGE = 9;

    /**
     * 课程列表：支持分类筛选、标签筛选、关键词搜索、排序、分页
     */
    public function index(): void
    {
        $keyword    = Request::string('q');
        $categoryId = Request::int('category');
        $tagId      = Request::int('tag');
        $sort       = Request::string('sort', 'recommended');

        if (!array_key_exists($sort, Course::SORTS)) {
            $sort = 'recommended';
        }

        $page = max(1, Request::int('page', 1));

        $filters = [
            'keyword'     => $keyword,
            'category_id' => $categoryId,
            'tag_id'      => $tagId,
        ];

        $total   = Course::count($filters);
        $courses = Course::search($filters, $sort, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->view('courses.index', [
            'pageTitle'     => '全部课程',
            'categories'    => Category::all(),
            'tags'          => Course::allTags(),
            'courses'       => $courses,
            'total'         => $total,
            'page'          => $page,
            'perPage'       => self::PER_PAGE,
            'totalPages'    => max(1, (int) ceil($total / self::PER_PAGE)),
            'keyword'       => $keyword,
            'categoryId'    => $categoryId,
            'tagId'         => $tagId,
            'sort'          => $sort,
            'sortOptions'   => Course::SORTS,
        ]);
    }

    /**
     * 课程详情
     */
    public function show(string $id): void
    {
        $courseId = (int) $id;
        $course   = $courseId > 0 ? Course::findPublished($courseId) : null;

        if ($course === null) {
            abort(404, '课程不存在或已下架。');
        }

        Course::incrementViews($courseId);

        $chapters = Course::chapters($courseId);
        $tags     = Course::tags($courseId);
        $related  = Course::related($courseId, isset($course['category_id']) ? (int) $course['category_id'] : null, 3);

        $userId    = Auth::id();
        $hasAccess = Enrollment::hasAccess($userId, $courseId);
        $previewIds = Course::previewIdsFor($course, $chapters, $hasAccess);

        $this->view('courses.show', [
            'pageTitle'  => (string) $course['title'],
            'bodyClass'  => 'page-course',
            'course'     => $course,
            'chapters'   => $chapters,
            'tags'       => $tags,
            'related'    => $related,
            'hasAccess'  => $hasAccess,
            'previewIds' => $previewIds,
        ]);
    }
}
