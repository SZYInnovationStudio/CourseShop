<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PlayProgress;
use App\Models\VideoTranscode;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\PlaybackAccess;
use App\Support\Request;
use App\Support\Response;
use App\Support\VideoStorage;

/**
 * 我的课程与课程学习页
 */
final class MyCourseController extends Controller
{
    private const PER_PAGE = 9;

    /**
     * 我的课程：已购课程列表（含学习进度）
     */
    public function index(): void
    {
        $userId  = (int) Auth::id();
        $page    = max(1, Request::int('page', 1));
        $total   = Enrollment::countForUser($userId);
        $courses = Enrollment::coursesForUser($userId, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->view('my.index', [
            'pageTitle'  => '我的课程',
            'courses'    => $courses,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /**
     * 课程学习页入口：未带章节号时跳转到第一个可播放章节
     *
     * 直接访问 /course/{id}/learn 时不再 404：优先跳转到有视频的可播放章节，
     * 其次跳转到任一可播放章节；都没有则回退到课程详情页。
     */
    public function learnEntry(string $courseId): void
    {
        $courseId  = (int) $courseId;
        $userId    = (int) Auth::id();
        $course    = Course::find($courseId);

        if ($course === null) {
            abort(404, '课程不存在或已下架。');
        }

        $hasAccess = Enrollment::hasAccess($userId > 0 ? $userId : null, $courseId);

        // 未购买的用户不能试看已下架课程（与 PlaybackAccess 保持一致）
        if (!$hasAccess && (string) $course['status'] !== 'published') {
            abort(404, '课程不存在或已下架。');
        }

        $chapters = Course::chapters($courseId);
        $target   = 0;

        foreach ($chapters as $chapter) {
            $id = (int) $chapter['id'];

            if (!Course::canPlayChapter($course, $chapters, $id, $hasAccess)) {
                continue;
            }

            // 优先有视频的章节；否则先记下第一个可播放章节作为兜底
            if ((int) ($chapter['has_video'] ?? 0) === 1) {
                $target = $id;
                break;
            }

            if ($target === 0) {
                $target = $id;
            }
        }

        if ($target === 0) {
            Response::redirect(url('/course/' . $courseId));
        }

        Response::redirect(url('/course/' . $courseId . '/learn/' . $target));
    }

    /**
     * 课程学习页：视频播放 + 章节列表 + 续播
     */
    public function learn(string $courseId, string $chapterId): void
    {
        $courseId  = (int) $courseId;
        $chapterId = (int) $chapterId;
        $userId    = (int) Auth::id();

        $context = PlaybackAccess::context($courseId, $chapterId, $userId);

        $progress = PlayProgress::mapForCourse($userId, $courseId);
        $resume   = (int) ($progress[$chapterId]['position'] ?? 0);

        // 只有存储键存在且文件真实落地时才渲染播放器，避免出现无法播放的空壳
        $hasMp4 = ($context['chapter']['video_path'] ?? '') !== ''
            && VideoStorage::exists((string) $context['chapter']['video_path']);

        // 已转码完成且 m3u8 落地的章节优先走 HLS（mp4 始终保留作为兜底）
        // 播放列表文件名内嵌转码任务 ID，必须取实际文件名签发，不能硬编码 index.m3u8
        $hlsPlaylist = VideoTranscode::readyPlaylistFile($chapterId);
        $hasHls      = $hlsPlaylist !== null && PlaybackAccess::hlsFile($chapterId) !== null;

        $hasVideo = $hasMp4 || $hasHls;

        $videoUrl = $hasMp4 ? VideoStorage::signedUrl($courseId, $chapterId, $userId) : null;
        $hlsUrl   = $hasHls ? VideoStorage::signedHlsUrl($courseId, $chapterId, $userId, (string) $hlsPlaylist) : null;

        $this->view('learn.show', [
            'pageTitle'  => (string) $context['chapter']['title'] . ' - ' . (string) $context['course']['title'],
            'course'     => $context['course'],
            'chapters'   => $context['chapters'],
            'chapter'    => $context['chapter'],
            'currentId'  => $chapterId,
            'hasAccess'  => $context['hasAccess'],
            'previewIds' => $context['previewIds'],
            'progress'   => $progress,
            'resume'     => $resume,
            'hasVideo'   => $hasVideo,
            'videoUrl'   => $videoUrl,
            'hlsUrl'     => $hlsUrl,
        ]);
    }

    /**
     * 上报播放进度（AJAX）
     *
     * 播放中每 10 秒、暂停 / 播完 / 关闭页面时各上报一次。
     */
    public function saveProgress(string $courseId, string $chapterId): void
    {
        Csrf::check();

        $courseId  = (int) $courseId;
        $chapterId = (int) $chapterId;
        $userId    = (int) Auth::id();

        PlaybackAccess::context($courseId, $chapterId, $userId);

        $position = max(0, Request::int('position'));
        $duration = max(0, Request::int('duration'));

        PlayProgress::save($userId, $courseId, $chapterId, $position, $duration);

        Response::json([
            'code'     => 0,
            'message'  => 'ok',
            'position' => $position,
            'duration' => $duration,
        ]);
    }
}
