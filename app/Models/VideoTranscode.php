<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * 视频转码任务（P2）
 *
 * 只记录「HLS 是否已就绪」，不改动 chapters 表结构：
 * 播放时通过 readyOutput() 取 status=success 的 output_path（形如 hls/chapter-12/index.m3u8）。
 */
final class VideoTranscode
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    /** 目标格式：目前仅 HLS */
    public const TARGET_HLS = 'hls';

    /** 状态 => 中文名 */
    public const STATUSES = [
        self::STATUS_PENDING => '等待中',
        self::STATUS_RUNNING => '转码中',
        self::STATUS_SUCCESS => '已完成',
        self::STATUS_FAILED  => '失败',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    /**
     * 新建一条待处理任务，返回自增 ID
     */
    public static function create(int $chapterId, string $target = self::TARGET_HLS): int
    {
        return Database::insert(
            'INSERT INTO `video_transcodes` (`chapter_id`, `target`, `status`, `progress`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, 0, NOW(), NOW())',
            [$chapterId, $target, self::STATUS_PENDING]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `video_transcodes` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 章节最新一条转码记录
     *
     * @return array<string, mixed>|null
     */
    public static function latest(int $chapterId): ?array
    {
        if ($chapterId <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `video_transcodes`
              WHERE `chapter_id` = ? AND `deleted_at` IS NULL
              ORDER BY `id` DESC LIMIT 1',
            [$chapterId]
        );
    }

    /**
     * 已转码完成、可直接播放的 HLS 存储键（index.m3u8）
     */
    public static function readyOutput(int $chapterId): ?string
    {
        if ($chapterId <= 0) {
            return null;
        }

        $output = Database::scalar(
            'SELECT `output_path` FROM `video_transcodes`
              WHERE `chapter_id` = ? AND `status` = ? AND `deleted_at` IS NULL
              ORDER BY `id` DESC LIMIT 1',
            [$chapterId, self::STATUS_SUCCESS]
        );

        $output = is_string($output) ? trim($output) : '';

        return $output !== '' ? $output : null;
    }

    /**
     * 进行中的任务（等待中 / 转码中）
     *
     * @return array<string, mixed>|null
     */
    public static function active(int $chapterId): ?array
    {
        if ($chapterId <= 0) {
            return null;
        }

        return Database::first(
            'SELECT * FROM `video_transcodes`
              WHERE `chapter_id` = ? AND `status` IN (?, ?) AND `deleted_at` IS NULL
              ORDER BY `id` DESC LIMIT 1',
            [$chapterId, self::STATUS_PENDING, self::STATUS_RUNNING]
        );
    }

    /**
     * 批量取各章节最新一条转码记录，键为章节 ID
     *
     * @param  array<int, int|string> $chapterIds
     * @return array<int, array<string, mixed>>
     */
    public static function mapLatest(array $chapterIds): array
    {
        $ids = [];
        foreach ($chapterIds as $chapterId) {
            $chapterId = (int) $chapterId;
            if ($chapterId > 0) {
                $ids[$chapterId] = $chapterId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids          = array_values($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = Database::select(
            'SELECT * FROM `video_transcodes`
              WHERE `chapter_id` IN (' . $placeholders . ') AND `deleted_at` IS NULL
              ORDER BY `id` ASC',
            $ids
        );

        // 按 id 升序覆盖，最终保留每个章节的最新记录
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['chapter_id']] = $row;
        }

        return $result;
    }

    public static function markRunning(int $id): void
    {
        Database::execute(
            'UPDATE `video_transcodes`
                SET `status` = ?, `progress` = 0, `error` = NULL, `updated_at` = NOW()
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [self::STATUS_RUNNING, $id]
        );
    }

    public static function updateProgress(int $id, int $progress): void
    {
        $progress = max(0, min(99, $progress));

        Database::execute(
            'UPDATE `video_transcodes` SET `progress` = ?, `updated_at` = NOW()
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [$progress, $id]
        );
    }

    public static function markSuccess(int $id, string $outputPath): void
    {
        Database::execute(
            'UPDATE `video_transcodes`
                SET `status` = ?, `progress` = 100, `output_path` = ?, `error` = NULL, `updated_at` = NOW()
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [self::STATUS_SUCCESS, $outputPath, $id]
        );
    }

    public static function markFailed(int $id, string $error): void
    {
        Database::execute(
            'UPDATE `video_transcodes` SET `status` = ?, `error` = ?, `updated_at` = NOW()
              WHERE `id` = ? AND `deleted_at` IS NULL',
            [self::STATUS_FAILED, mb_substr($error, 0, 2000), $id]
        );
    }

    /**
     * 把同章节进行中的旧任务标记为失败（重新提交转码 / 删除视频时调用）
     *
     * 旧任务在 handle() 中会因状态不再是 pending 而跳过，避免无谓的重复转码。
     */
    public static function supersedeActive(int $chapterId, string $reason): void
    {
        if ($chapterId <= 0) {
            return;
        }

        Database::execute(
            'UPDATE `video_transcodes` SET `status` = ?, `error` = ?, `updated_at` = NOW()
              WHERE `chapter_id` = ? AND `status` IN (?, ?) AND `deleted_at` IS NULL',
            [self::STATUS_FAILED, mb_substr($reason, 0, 2000), $chapterId, self::STATUS_PENDING, self::STATUS_RUNNING]
        );
    }

    /**
     * 章节被删除时级联软删除其转码记录
     */
    public static function softDeleteByChapter(int $chapterId): void
    {
        if ($chapterId <= 0) {
            return;
        }

        Database::execute(
            'UPDATE `video_transcodes` SET `deleted_at` = NOW()
              WHERE `chapter_id` = ? AND `deleted_at` IS NULL',
            [$chapterId]
        );
    }
}
