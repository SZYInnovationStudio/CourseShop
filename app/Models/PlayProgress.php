<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 播放进度
 *
 * 每用户每章节只保留一条记录（uk_progress_user_chapter 唯一键），
 * 页面刷新或重复上报都只更新时间与位置，不会产生重复行。
 */
final class PlayProgress
{
    /** 播放到该比例即视为「已看完」 */
    private const FINISH_RATIO = 0.95;

    /**
     * 某用户在某课程下的全部章节进度，按 chapter_id 索引
     *
     * @return array<int, array{position: int, duration: int, finished: int}>
     */
    public static function mapForCourse(int $userId, int $courseId): array
    {
        if ($userId <= 0 || $courseId <= 0) {
            return [];
        }

        $rows = Database::select(
            'SELECT `chapter_id`, `position`, `duration`, `finished`
               FROM `play_progress`
              WHERE `user_id` = ? AND `course_id` = ?',
            [$userId, $courseId]
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['chapter_id']] = [
                'position' => (int) $row['position'],
                'duration' => (int) $row['duration'],
                'finished' => (int) $row['finished'],
            ];
        }

        return $map;
    }

    /**
     * 保存播放进度（幂等）
     *
     * @param int $position 当前播放位置（秒）
     * @param int $duration 视频总时长（秒），未知时传 0
     */
    public static function save(int $userId, int $courseId, int $chapterId, int $position, int $duration): void
    {
        if ($userId <= 0 || $courseId <= 0 || $chapterId <= 0) {
            return;
        }

        $position = max(0, $position);
        $duration = max(0, $duration);

        $finished = ($duration > 0 && $position >= (int) ceil($duration * self::FINISH_RATIO)) ? 1 : 0;

        // finished 一旦为 1 就保持，避免用户回看前面的片段后状态被抹掉
        Database::execute(
            'INSERT INTO `play_progress`
                (`user_id`, `course_id`, `chapter_id`, `position`, `duration`, `finished`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                `position`   = VALUES(`position`),
                `duration`   = VALUES(`duration`),
                `finished`   = GREATEST(`finished`, VALUES(`finished`)),
                `updated_at` = NOW()',
            [$userId, $courseId, $chapterId, $position, $duration, $finished]
        );
    }

    /**
     * 播放进度数组（供模板使用）
     *
     * @return array{position: int, duration: int, finished: int}
     */
    public static function toArray(?array $row): array
    {
        return [
            'position' => (int) ($row['position'] ?? 0),
            'duration' => (int) ($row['duration'] ?? 0),
            'finished' => (int) ($row['finished'] ?? 0),
        ];
    }
}
