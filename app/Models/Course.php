<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Cache;
use App\Support\Database;
use App\Support\Ids;
use App\Support\Search;

/**
 * 课程
 */
final class Course
{
    /** 首页推荐课程缓存键 */
    public const CACHE_KEY_RECOMMENDED = 'home:recommended';

    /** 课程总数缓存键 */
    public const CACHE_KEY_TOTAL = 'home:total';

    public const SORTS = [
        'recommended' => '推荐排序',
        'latest'      => '最新上架',
        'hot'         => '销量优先',
        'price_asc'   => '价格从低到高',
        'price_desc'  => '价格从高到低',
    ];

    /** 课程状态（后台） */
    public const STATUSES = [
        'draft'     => '草稿',
        'published' => '已上架',
        'offline'   => '已下架',
    ];

    /**
     * 课程列表（仅已上架）
     *
     * @param  array<string, mixed> $filters category_id / keyword / tag_id
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filters = [], string $sort = 'recommended', int $limit = 12, int $offset = 0): array
    {
        [$where, $bindings] = self::buildWhere($filters);

        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        $order  = self::orderBy($sort);

        return Database::select(
            "SELECT c.id, c.title, c.subtitle, c.cover, c.summary, c.price, c.original_price,
                    c.preview_enabled, c.preview_chapter_count, c.view_count, c.sales_count,
                    c.category_id, cat.name AS category_name
               FROM `courses` c
               LEFT JOIN `course_categories` cat ON cat.id = c.category_id AND cat.deleted_at IS NULL
              WHERE {$where}
              ORDER BY {$order}
              LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function count(array $filters = []): int
    {
        [$where, $bindings] = self::buildWhere($filters);

        return (int) Database::scalar("SELECT COUNT(*) FROM `courses` c WHERE {$where}", $bindings);
    }

    /**
     * 课程详情（仅已上架）
     *
     * @return array<string, mixed>|null
     */
    public static function findPublished(int $id): ?array
    {
        return self::fetch($id, true);
    }

    /**
     * 课程详情（不限状态，供「我的课程」学习页使用）
     *
     * 课程下架后已购用户仍应能继续学习，因此这里不限制 status。
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return self::fetch($id, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetch(int $id, bool $publishedOnly): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $status = $publishedOnly ? " AND c.status = 'published'" : '';

        return Database::first(
            "SELECT c.*, cat.name AS category_name
               FROM `courses` c
               LEFT JOIN `course_categories` cat ON cat.id = c.category_id AND cat.deleted_at IS NULL
              WHERE c.id = ?{$status} AND c.deleted_at IS NULL
              LIMIT 1",
            [$id]
        );
    }

    /**
     * 章节列表（正常状态）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function chapters(int $courseId): array
    {
        return Database::select(
            "SELECT `id`, `title`, `duration`, `is_preview`, `sort`,
                    (`video_path` IS NOT NULL AND `video_path` <> '') AS has_video
               FROM `chapters`
              WHERE `course_id` = ? AND `status` = 1 AND `deleted_at` IS NULL
              ORDER BY `sort` ASC, `id` ASC",
            [$courseId]
        );
    }

    /**
     * 课程标签
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tags(int $courseId): array
    {
        return Database::select(
            'SELECT t.`id`, t.`name`
               FROM `course_tags` t
               INNER JOIN `course_tag_relations` r ON r.tag_id = t.id
              WHERE r.course_id = ? AND t.deleted_at IS NULL
              ORDER BY t.sort ASC, t.id ASC',
            [$courseId]
        );
    }

    /**
     * 同分类推荐课程
     *
     * @return array<int, array<string, mixed>>
     */
    public static function related(int $courseId, ?int $categoryId, int $limit = 4): array
    {
        if ($categoryId === null) {
            return [];
        }

        $limit = max(1, $limit);

        return Database::select(
            "SELECT c.id, c.title, c.cover, c.price, c.original_price
               FROM `courses` c
              WHERE c.category_id = ? AND c.id <> ? AND c.status = 'published' AND c.deleted_at IS NULL
              ORDER BY c.sort DESC, c.id DESC
              LIMIT {$limit}",
            [$categoryId, $courseId]
        );
    }

    public static function incrementViews(int $courseId): void
    {
        Database::execute('UPDATE `courses` SET `view_count` = `view_count` + 1 WHERE `id` = ?', [$courseId]);
    }

    /**
     * 试看章节 ID 列表
     *
     * @param  array<int, array<string, mixed>> $chapters
     * @return array<int, int>
     */
    public static function previewChapterIds(array $chapters, int $previewCount): array
    {
        $ids = [];

        foreach ($chapters as $index => $chapter) {
            if ((int) ($chapter['is_preview'] ?? 0) === 1 || $index < $previewCount) {
                $ids[] = (int) $chapter['id'];
            }
        }

        return $ids;
    }

    /**
     * 当前用户对该课程可试看的章节 ID 列表
     *
     * 已购用户无需试看；课程未开启试看时返回空数组。
     *
     * @param  array<string, mixed>            $course
     * @param  array<int, array<string, mixed>> $chapters
     * @return array<int, int>
     */
    public static function previewIdsFor(array $course, array $chapters, bool $hasAccess): array
    {
        if ($hasAccess || (int) ($course['preview_enabled'] ?? 0) !== 1) {
            return [];
        }

        return self::previewChapterIds($chapters, (int) ($course['preview_chapter_count'] ?? 0));
    }

    /**
     * 章节是否允许播放：已购，或处于试看范围
     *
     * @param  array<string, mixed>             $course
     * @param  array<int, array<string, mixed>> $chapters
     */
    public static function canPlayChapter(array $course, array $chapters, int $chapterId, bool $hasAccess): bool
    {
        if ($hasAccess) {
            return true;
        }

        return in_array($chapterId, self::previewIdsFor($course, $chapters, false), true);
    }

    // ============================================================
    // 后台课程管理
    // ============================================================

    /**
     * 后台课程列表（不限状态，含草稿与下架）
     *
     * @param  array<string, mixed> $filters keyword / category_id / tag_id / status
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        $limit  = max(1, $limit);
        $offset = max(0, $offset);

        return Database::select(
            "SELECT c.id, c.title, c.subtitle, c.cover, c.price, c.original_price, c.status, c.sort,
                    c.preview_enabled, c.preview_chapter_count, c.view_count, c.sales_count,
                    c.category_id, c.created_at, c.updated_at, cat.name AS category_name
               FROM `courses` c
               LEFT JOIN `course_categories` cat ON cat.id = c.category_id AND cat.deleted_at IS NULL
              WHERE {$where}
              ORDER BY c.sort DESC, c.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function adminCount(array $filters = []): int
    {
        [$where, $bindings] = self::adminWhere($filters);

        return (int) Database::scalar("SELECT COUNT(*) FROM `courses` c WHERE {$where}", $bindings);
    }

    /**
     * 后台课程详情（含草稿/下架，含 content 等全部字段）
     *
     * @return array<string, mixed>|null
     */
    public static function adminFind(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `courses` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 新建课程，返回自增 ID
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $id = Database::insert(
            'INSERT INTO `courses`
                (`title`, `subtitle`, `category_id`, `summary`, `content`, `cover`,
                 `price`, `original_price`, `preview_enabled`, `preview_chapter_count`, `status`, `sort`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['title'], $data['subtitle'], $data['category_id'], $data['summary'], $data['content'], $data['cover'],
                $data['price'], $data['original_price'], $data['preview_enabled'], $data['preview_chapter_count'],
                $data['status'], $data['sort'],
            ]
        );

        self::forgetHomeCache();

        return $id;
    }

    /**
     * 更新课程
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE `courses` SET
                `title` = ?, `subtitle` = ?, `category_id` = ?, `summary` = ?, `content` = ?, `cover` = ?,
                `price` = ?, `original_price` = ?, `preview_enabled` = ?, `preview_chapter_count` = ?,
                `status` = ?, `sort` = ?
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [
                $data['title'], $data['subtitle'], $data['category_id'], $data['summary'], $data['content'], $data['cover'],
                $data['price'], $data['original_price'], $data['preview_enabled'], $data['preview_chapter_count'],
                $data['status'], $data['sort'], $id,
            ]
        );

        self::forgetHomeCache();
    }

    /**
     * 修改课程状态（草稿 / 上架 / 下架）
     */
    public static function setStatus(int $id, string $status): void
    {
        Database::execute(
            'UPDATE `courses` SET `status` = ? WHERE `id` = ? AND `deleted_at` IS NULL',
            [$status, $id]
        );

        self::forgetHomeCache();
    }

    /**
     * 软删除课程（保留订单、章节等历史数据）
     */
    public static function softDelete(int $id): void
    {
        Database::execute(
            'UPDATE `courses` SET `deleted_at` = NOW() WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        );

        self::forgetHomeCache();
    }

    /**
     * 批量修改课程状态，返回受影响行数
     *
     * @param array<int, int> $ids
     */
    public static function setStatusMany(array $ids, string $status): int
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $affected = Database::execute(
            'UPDATE `courses` SET `status` = ?
              WHERE `deleted_at` IS NULL AND `id` IN (' . $placeholders . ')',
            array_merge([$status], $ids)
        );

        if ($affected > 0) {
            self::forgetHomeCache();
        }

        return $affected;
    }

    /**
     * 批量软删除课程，返回受影响行数
     *
     * @param array<int, int> $ids
     */
    public static function softDeleteMany(array $ids): int
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $affected = Database::execute(
            'UPDATE `courses` SET `deleted_at` = NOW()
              WHERE `deleted_at` IS NULL AND `id` IN (' . $placeholders . ')',
            $ids
        );

        if ($affected > 0) {
            self::forgetHomeCache();
        }

        return $affected;
    }

    /**
     * 失效首页课程相关缓存（课程任何写操作后调用）
     *
     * 首页数据由文件缓存承载，后台改动课程后必须主动失效，避免前台读到旧数据。
     */
    public static function forgetHomeCache(): void
    {
        Cache::forget(self::CACHE_KEY_RECOMMENDED);
        Cache::forget(self::CACHE_KEY_TOTAL);
    }

    /**
     * 全部课程（id + title），用于后台「限定课程」下拉选择
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        return Database::select(
            'SELECT `id`, `title` FROM `courses` WHERE `deleted_at` IS NULL ORDER BY `id` DESC'
        );
    }

    // ---------------- 标签 ----------------

    /**
     * 全部标签
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allTags(): array
    {
        return Database::select(
            'SELECT `id`, `name`, `sort` FROM `course_tags` WHERE `deleted_at` IS NULL ORDER BY `sort` ASC, `id` ASC'
        );
    }

    /**
     * 课程已绑定标签 ID
     *
     * @return array<int, int>
     */
    public static function tagIds(int $courseId): array
    {
        $rows = Database::select('SELECT `tag_id` FROM `course_tag_relations` WHERE `course_id` = ?', [$courseId]);

        return array_map(static fn (array $row): int => (int) $row['tag_id'], $rows);
    }

    /**
     * 覆盖式同步课程标签
     *
     * @param array<int, int> $tagIds
     */
    public static function syncTags(int $courseId, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(
            array_map('intval', $tagIds),
            static fn (int $id): bool => $id > 0
        )));

        Database::execute('DELETE FROM `course_tag_relations` WHERE `course_id` = ?', [$courseId]);

        foreach ($tagIds as $tagId) {
            Database::execute(
                'INSERT IGNORE INTO `course_tag_relations` (`course_id`, `tag_id`) VALUES (?, ?)',
                [$courseId, $tagId]
            );
        }
    }

    /**
     * 按名称批量获取或创建标签，返回标签 ID 列表
     *
     * 若同名标签已被软删除则复活它，避免触发 name 唯一索引冲突。
     *
     * @param  array<int, string> $names
     * @return array<int, int>
     */
    public static function ensureTags(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 50) {
                continue;
            }

            $row = Database::first('SELECT `id`, `deleted_at` FROM `course_tags` WHERE `name` = ? LIMIT 1', [$name]);

            if ($row !== null) {
                if ($row['deleted_at'] !== null) {
                    Database::execute('UPDATE `course_tags` SET `deleted_at` = NULL WHERE `id` = ?', [(int) $row['id']]);
                }
                $ids[] = (int) $row['id'];
                continue;
            }

            $ids[] = Database::insert('INSERT INTO `course_tags` (`name`) VALUES (?)', [$name]);
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function adminWhere(array $filters): array
    {
        $conditions = ['c.deleted_at IS NULL'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = Search::adminCourseKeyword($keyword, $bindings);
        }

        // 父分类需同时包含其子分类下的课程
        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $conditions[] = self::categoryCondition(Category::idWithDescendants($categoryId), $bindings);
        }

        $tagId = (int) ($filters['tag_id'] ?? 0);
        if ($tagId > 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM `course_tag_relations` ctr WHERE ctr.course_id = c.id AND ctr.tag_id = ?)';
            $bindings[]   = $tagId;
        }

        $status = (string) ($filters['status'] ?? '');
        if (array_key_exists($status, self::STATUSES)) {
            $conditions[] = 'c.status = ?';
            $bindings[]   = $status;
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = ["c.status = 'published'", 'c.deleted_at IS NULL'];
        $bindings   = [];

        // 父分类需同时包含其子分类下的课程
        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $conditions[] = self::categoryCondition(Category::idWithDescendants($categoryId), $bindings);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = Search::courseKeyword($keyword, $bindings);
        }

        $tagId = (int) ($filters['tag_id'] ?? 0);
        if ($tagId > 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM `course_tag_relations` ctr WHERE ctr.course_id = c.id AND ctr.tag_id = ?)';
            $bindings[]   = $tagId;
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * 生成分类筛选条件（支持一次匹配多个分类 ID）
     *
     * @param array<int, int>   $categoryIds
     * @param array<int, mixed> $bindings
     */
    private static function categoryCondition(array $categoryIds, array &$bindings): string
    {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

        foreach ($categoryIds as $categoryId) {
            $bindings[] = $categoryId;
        }

        return 'c.category_id IN (' . $placeholders . ')';
    }

    private static function orderBy(string $sort): string
    {
        return match ($sort) {
            'latest'     => 'c.created_at DESC, c.id DESC',
            'hot'        => 'c.sales_count DESC, c.view_count DESC, c.id DESC',
            'price_asc'  => 'c.price ASC, c.id DESC',
            'price_desc' => 'c.price DESC, c.id DESC',
            default      => 'c.sort DESC, c.id DESC',
        };
    }
}
