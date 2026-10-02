<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 课程章节
 */
final class Chapter
{
    /**
     * 章节详情（含视频存储字段，仅供服务端鉴权使用，不可直接对外输出）
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $chapterId, int $courseId): ?array
    {
        if ($chapterId <= 0 || $courseId <= 0) {
            return null;
        }

        return Database::first(
            'SELECT `id`, `course_id`, `title`, `video_path`, `video_disk`,
                    `duration`, `file_size`, `is_preview`, `sort`
               FROM `chapters`
              WHERE `id` = ? AND `course_id` = ? AND `status` = 1 AND `deleted_at` IS NULL
              LIMIT 1',
            [$chapterId, $courseId]
        );
    }

    /**
     * 章节详情（不限制状态，供后台任务使用，例如转码消费）
     *
     * @return array<string, mixed>|null
     */
    public static function findAny(int $chapterId): ?array
    {
        if ($chapterId <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `chapters` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$chapterId]
        );
    }

    // ============================================================
    // 后台章节管理
    // ============================================================

    /**
     * 后台章节列表（含隐藏章节与视频信息）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(int $courseId): array
    {
        if ($courseId <= 0) {
            return [];
        }

        return Database::select(
            'SELECT `id`, `course_id`, `title`, `video_disk`, `duration`, `file_size`,
                    `is_preview`, `sort`, `status`, `created_at`,
                    (`video_path` IS NOT NULL AND `video_path` <> \'\') AS has_video
               FROM `chapters`
              WHERE `course_id` = ? AND `deleted_at` IS NULL
              ORDER BY `sort` ASC, `id` ASC',
            [$courseId]
        );
    }

    /**
     * 后台章节详情（含 video_path，用于替换/删除视频）
     *
     * @return array<string, mixed>|null
     */
    public static function adminFind(int $chapterId, int $courseId): ?array
    {
        if ($chapterId <= 0 || $courseId <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `chapters` WHERE `id` = ? AND `course_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$chapterId, $courseId]
        );
    }

    /**
     * 某课程下的章节数量（不含已删除）
     */
    public static function count(int $courseId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `chapters` WHERE `course_id` = ? AND `deleted_at` IS NULL',
            [$courseId]
        );
    }

    /**
     * 新增章节，返回自增 ID
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO `chapters`
                (`course_id`, `title`, `video_path`, `video_disk`, `duration`, `file_size`, `is_preview`, `sort`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['course_id'], $data['title'], $data['video_path'], $data['video_disk'],
                $data['duration'], $data['file_size'], $data['is_preview'], $data['sort'], $data['status'],
            ]
        );
    }

    /**
     * 更新章节信息（不改动视频字段，视频通过 setVideo 单独维护）
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, int $courseId, array $data): void
    {
        Database::execute(
            'UPDATE `chapters` SET
                `title` = ?, `duration` = ?, `is_preview` = ?, `sort` = ?, `status` = ?
              WHERE `id` = ? AND `course_id` = ? AND `deleted_at` IS NULL',
            [
                $data['title'], $data['duration'], $data['is_preview'], $data['sort'], $data['status'],
                $id, $courseId,
            ]
        );
    }

    /**
     * 写入 / 清除章节视频
     */
    public static function setVideo(int $id, int $courseId, ?string $path, string $disk, int $size): void
    {
        Database::execute(
            'UPDATE `chapters` SET `video_path` = ?, `video_disk` = ?, `file_size` = ?
              WHERE `id` = ? AND `course_id` = ? AND `deleted_at` IS NULL',
            [$path, $disk, $size, $id, $courseId]
        );
    }

    /**
     * 软删除章节
     */
    public static function softDelete(int $id, int $courseId): void
    {
        Database::execute(
            'UPDATE `chapters` SET `deleted_at` = NOW()
              WHERE `id` = ? AND `course_id` = ? AND `deleted_at` IS NULL',
            [$id, $courseId]
        );
    }

    /**
     * 上移 / 下移章节：交换相邻顺序并归一化排序值
     *
     * @param string $direction up | down
     * @return bool 是否发生了移动（已在首/末位时返回 false）
     */
    public static function move(int $courseId, int $chapterId, string $direction): bool
    {
        $rows = Database::select(
            'SELECT `id` FROM `chapters` WHERE `course_id` = ? AND `deleted_at` IS NULL ORDER BY `sort` ASC, `id` ASC',
            [$courseId]
        );

        $ids   = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $index = array_search($chapterId, $ids, true);

        if ($index === false) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= count($ids)) {
            return false;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        // 归一化为 10 的倍数，便于后续手动插入排序值
        foreach ($ids as $position => $id) {
            Database::execute('UPDATE `chapters` SET `sort` = ? WHERE `id` = ?', [$position * 10, $id]);
        }

        return true;
    }
}
