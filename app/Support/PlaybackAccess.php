<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\VideoTranscode;

/**
 * 播放权限判定
 *
 * 学习页、进度上报、视频输出三处都要做同样的校验，统一收敛到这里：
 * 1. 课程存在（未购买时还必须处于已上架状态）
 * 2. 章节存在且属于该课程
 * 3. 用户已购买该课程，或该章节在试看范围内
 */
final class PlaybackAccess
{
    /**
     * 解析学习上下文，无权限时抛 404 / 403
     *
     * @return array{
     *     course: array<string, mixed>,
     *     chapters: array<int, array<string, mixed>>,
     *     chapter: array<string, mixed>,
     *     hasAccess: bool,
     *     previewIds: array<int, int>
     * }
     */
    public static function context(int $courseId, int $chapterId, int $userId): array
    {
        $course = Course::find($courseId);

        if ($course === null) {
            abort(404, '课程不存在或已下架。');
        }

        $hasAccess = Enrollment::hasAccess($userId > 0 ? $userId : null, $courseId);

        // 未购买的用户不能试看已下架课程（已购用户不受下架影响，保障其已购权益）
        if (!$hasAccess && (string) $course['status'] !== 'published') {
            abort(404, '课程不存在或已下架。');
        }

        $chapters = Course::chapters($courseId);
        if ($chapters === []) {
            abort(404, '该课程还没有可学习的章节。');
        }

        $chapter = Chapter::find($chapterId, $courseId);
        if ($chapter === null) {
            abort(404, '章节不存在。');
        }

        if (!Course::canPlayChapter($course, $chapters, $chapterId, $hasAccess)) {
            abort(403, '请先购买该课程后再学习。');
        }

        return [
            'course'     => $course,
            'chapters'   => $chapters,
            'chapter'    => $chapter,
            'hasAccess'  => $hasAccess,
            'previewIds' => Course::previewIdsFor($course, $chapters, $hasAccess),
        ];
    }

    /**
     * 章节视频在服务器上的绝对路径，文件缺失时抛 404
     *
     * @param array<string, mixed> $chapter
     */
    public static function videoFile(array $chapter): string
    {
        $file = VideoStorage::resolve($chapter['video_path'] ?? null);

        if ($file === null) {
            abort(404, '视频文件不存在或尚未上传。');
        }

        return $file;
    }

    /**
     * 章节 HLS 播放列表在服务器上的绝对路径
     *
     * 返回 null 表示该章节没有可用的 HLS 产物（未转码 / 转码失败 / 文件缺失），
     * 调用方应回退到 mp4 播放。
     */
    public static function hlsFile(int $chapterId): ?string
    {
        $output = VideoTranscode::readyOutput($chapterId);

        if ($output === null) {
            return null;
        }

        return VideoStorage::resolve($output);
    }
}
