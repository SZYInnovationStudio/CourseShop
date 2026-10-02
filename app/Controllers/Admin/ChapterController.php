<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Jobs\TranscodeVideoJob;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Log;
use App\Models\VideoTranscode;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Setting;
use App\Support\Video\Transcoder;
use App\Support\VideoStorage;
use RuntimeException;
use Throwable;

/**
 * 后台章节管理
 *
 * 章节归属于课程，提供章节的增删改、上移/下移排序、试看标记，
 * 以及章节视频的上传、替换与删除（P0 存储到本地磁盘）。
 * P2 增加 HLS 转码：上传 / 替换视频后自动入队，由队列消费者异步转码。
 */
final class ChapterController extends AdminController
{
    /**
     * 章节列表（含新增表单）
     */
    public function index(string $id): void
    {
        $course   = $this->requireCourse((int) $id);
        $chapters = Chapter::adminList((int) $course['id']);

        $this->view('admin.chapters.index', [
            'pageTitle'       => '章节管理',
            'course'          => $course,
            'chapters'        => $chapters,
            'transcodes'      => VideoTranscode::mapLatest(array_column($chapters, 'id')),
            'ffmpegAvailable' => Transcoder::available(),
            'hlsEnabled'      => Setting::bool('video_hls_enabled', true),
        ]);
    }

    /**
     * 新增章节（可同时上传视频）
     */
    public function store(string $id): void
    {
        Csrf::check();

        $course   = $this->requireCourse((int) $id);
        $courseId = (int) $course['id'];
        $listUrl  = url('/admin/courses/' . $courseId . '/chapters');

        $data      = $this->validatedData($listUrl);
        $chapterId = Chapter::create([
            'course_id'  => $courseId,
            'title'      => $data['title'],
            'video_path' => null,
            'video_disk' => 'local',
            'duration'   => $data['duration'],
            'file_size'  => 0,
            'is_preview' => $data['is_preview'],
            'sort'       => $data['sort'],
            'status'     => $data['status'],
        ]);

        try {
            $stored = VideoStorage::storeUpload(Request::file('video'), $courseId, $chapterId);
        } catch (RuntimeException $e) {
            // 视频保存失败时回滚刚创建的章节，避免产生无视频的空章节
            Chapter::softDelete($chapterId, $courseId);
            $this->fail($listUrl, $e->getMessage());
        }

        if ($stored !== null) {
            Chapter::setVideo($chapterId, $courseId, $stored['path'], $stored['disk'], $stored['size']);
            $this->queueTranscode($chapterId);
        }

        Log::recordOperation('chapter.create', 'chapter', $chapterId, [
            'course_id' => $courseId,
            'title'     => $data['title'],
        ]);

        $this->success($listUrl, '章节已创建。');
    }

    /**
     * 编辑章节页
     */
    public function edit(string $id, string $chapterId): void
    {
        $course  = $this->requireCourse((int) $id);
        $chapter = $this->requireChapter((int) $chapterId, (int) $course['id']);

        $this->view('admin.chapters.edit', [
            'pageTitle'       => '编辑章节',
            'course'          => $course,
            'chapter'         => $chapter,
            'hasVideo'        => !empty($chapter['video_path']),
            'videoSize'       => (int) ($chapter['file_size'] ?? 0),
            'transcode'       => VideoTranscode::latest((int) $chapter['id']),
            'ffmpegAvailable' => Transcoder::available(),
            'hlsEnabled'      => Setting::bool('video_hls_enabled', true),
        ]);
    }

    /**
     * 保存章节修改（含替换视频）
     */
    public function update(string $id, string $chapterId): void
    {
        Csrf::check();

        $course    = $this->requireCourse((int) $id);
        $courseId  = (int) $course['id'];
        $chapter   = $this->requireChapter((int) $chapterId, $courseId);
        $chapterId = (int) $chapter['id'];
        $editUrl   = url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/edit');

        $data = $this->validatedData($editUrl);

        Chapter::update($chapterId, $courseId, $data);

        // 选择了新视频则替换；未选择则保留原视频
        try {
            $stored = VideoStorage::storeUpload(Request::file('video'), $courseId, $chapterId);
        } catch (RuntimeException $e) {
            $this->fail($editUrl, $e->getMessage());
        }

        if ($stored !== null) {
            $oldPath = (string) ($chapter['video_path'] ?? '');
            // 扩展名变化时旧文件不会被覆盖，需要显式删除
            if ($oldPath !== '' && $oldPath !== $stored['path']) {
                VideoStorage::delete($oldPath);
            }
            Chapter::setVideo($chapterId, $courseId, $stored['path'], $stored['disk'], $stored['size']);

            // 源视频已变化，旧的 HLS 产物与进行中的任务全部作废，并重新入队
            VideoStorage::deleteHls($chapterId);
            VideoTranscode::supersedeActive($chapterId, '源视频已被替换，任务作废。');
            $this->queueTranscode($chapterId);
        }

        Log::recordOperation('chapter.update', 'chapter', $chapterId, [
            'course_id' => $courseId,
            'title'     => $data['title'],
        ]);

        $this->success($editUrl, '章节已保存。');
    }

    /**
     * 上移 / 下移章节
     */
    public function move(string $id, string $chapterId): void
    {
        Csrf::check();

        $course   = $this->requireCourse((int) $id);
        $courseId = (int) $course['id'];
        $listUrl  = url('/admin/courses/' . $courseId . '/chapters');

        $this->requireChapter((int) $chapterId, $courseId);

        $direction = Request::string('direction') === 'up' ? 'up' : 'down';

        if (!Chapter::move($courseId, (int) $chapterId, $direction)) {
            $this->fail($listUrl, '章节已在' . ($direction === 'up' ? '首' : '末') . '位，无需移动。');
        }

        Log::recordOperation('chapter.update', 'chapter', (int) $chapterId, [
            'course_id' => $courseId,
            'direction' => $direction,
        ]);

        $this->success($listUrl, '章节顺序已更新。');
    }

    /**
     * 删除章节视频（保留章节）
     */
    public function deleteVideo(string $id, string $chapterId): void
    {
        Csrf::check();

        $course    = $this->requireCourse((int) $id);
        $courseId  = (int) $course['id'];
        $chapter   = $this->requireChapter((int) $chapterId, $courseId);
        $chapterId = (int) $chapter['id'];
        $editUrl   = url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/edit');

        $oldPath = (string) ($chapter['video_path'] ?? '');
        if ($oldPath === '') {
            $this->fail($editUrl, '该章节尚未上传视频。');
        }

        VideoStorage::delete($oldPath);
        Chapter::setVideo($chapterId, $courseId, null, 'local', 0);

        // 视频已删除，HLS 产物与进行中的转码任务一并清理
        VideoStorage::deleteHls($chapterId);
        VideoTranscode::supersedeActive($chapterId, '章节视频已删除，任务作废。');

        Log::recordOperation('chapter.update', 'chapter', $chapterId, [
            'course_id' => $courseId,
            'video'     => 'deleted',
        ]);

        $this->success($editUrl, '章节视频已删除。');
    }

    /**
     * 软删除章节（同时删除视频文件）
     */
    public function destroy(string $id, string $chapterId): void
    {
        Csrf::check();

        $course   = $this->requireCourse((int) $id);
        $courseId = (int) $course['id'];
        $listUrl  = url('/admin/courses/' . $courseId . '/chapters');

        $chapter = $this->requireChapter((int) $chapterId, $courseId);

        $oldPath = (string) ($chapter['video_path'] ?? '');
        if ($oldPath !== '') {
            VideoStorage::delete($oldPath);
        }

        // 清理 HLS 产物与转码记录，避免遗留孤儿文件
        VideoStorage::deleteHls((int) $chapter['id']);
        VideoTranscode::supersedeActive((int) $chapter['id'], '章节已删除，任务作废。');
        VideoTranscode::softDeleteByChapter((int) $chapter['id']);

        Chapter::softDelete((int) $chapter['id'], $courseId);

        Log::recordOperation('chapter.delete', 'chapter', (int) $chapter['id'], [
            'course_id' => $courseId,
            'title'     => (string) $chapter['title'],
        ]);

        $this->success($listUrl, '章节已删除。');
    }

    /**
     * 手动提交章节视频的 HLS 转码任务
     */
    public function transcode(string $id, string $chapterId): void
    {
        Csrf::check();

        $course    = $this->requireCourse((int) $id);
        $courseId  = (int) $course['id'];
        $chapter   = $this->requireChapter((int) $chapterId, $courseId);
        $chapterId = (int) $chapter['id'];
        $editUrl   = url('/admin/courses/' . $courseId . '/chapters/' . $chapterId . '/edit');

        $videoPath = (string) ($chapter['video_path'] ?? '');
        if ($videoPath === '' || !VideoStorage::exists($videoPath)) {
            $this->fail($editUrl, '请先上传章节视频后再提交转码。');
        }

        if (!Setting::bool('video_hls_enabled', true)) {
            $this->fail($editUrl, 'HLS 转码已在「系统设置 - 视频设置」中关闭。');
        }

        if (!Transcoder::available()) {
            $this->fail($editUrl, '服务器未安装 ffmpeg，无法执行转码。');
        }

        if (VideoTranscode::active($chapterId) !== null) {
            $this->fail($editUrl, '该章节已有转码任务进行中，请稍后再试。');
        }

        $transcodeId = $this->queueTranscode($chapterId);

        if ($transcodeId <= 0) {
            $this->fail($editUrl, '转码任务入队失败，请检查队列运行状态。');
        }

        Log::recordOperation('chapter.transcode', 'chapter', $chapterId, [
            'course_id'    => $courseId,
            'transcode_id' => $transcodeId,
        ]);

        $this->success($editUrl, '转码任务已提交，完成后自动切换为 HLS 播放。');
    }

    /**
     * 提交一个 HLS 转码任务（未启用或 ffmpeg 不可用时静默跳过）
     *
     * @return int 转码任务 ID，未入队时返回 0
     */
    private function queueTranscode(int $chapterId): int
    {
        if ($chapterId <= 0 || !Transcoder::enabled()) {
            return 0;
        }

        $transcodeId = VideoTranscode::create($chapterId);

        try {
            TranscodeVideoJob::dispatch($chapterId, $transcodeId);
        } catch (Throwable $e) {
            VideoTranscode::markFailed($transcodeId, '任务入队失败：' . $e->getMessage());

            return 0;
        }

        return $transcodeId;
    }

    /**
     * 校验并整理章节表单数据
     *
     * 视频字段不在此处理，由调用方通过 VideoStorage 单独维护。
     *
     * @return array<string, mixed>
     */
    private function validatedData(string $backUrl): array
    {
        $title         = Request::string('title');
        $durationRaw   = trim(Request::string('duration'));
        $isPreview     = Request::string('is_preview', '0') === '1' ? 1 : 0;
        $status        = Request::string('status', '1') === '0' ? 0 : 1;
        $sortRaw       = trim(Request::string('sort'));

        if ($durationRaw === '') {
            $durationRaw = '0';
        }
        if ($sortRaw === '') {
            $sortRaw = '0';
        }

        // 用归一化后的值参与整型校验，避免空字符串直接判为非法
        $validatorInput = array_merge(Request::all(), [
            'duration' => $durationRaw,
            'sort'     => $sortRaw,
        ]);

        $validator = $this->validator($validatorInput)
            ->required('title', '章节名称')
            ->max('title', 150, '章节名称')
            ->integer('duration', '视频时长', 0, 864000)
            ->in('is_preview', ['0', '1'], '试看标记')
            ->integer('sort', '排序权重', -100000, 100000);

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), [
                'title'      => $title,
                'duration'   => $durationRaw,
                'is_preview' => (string) $isPreview,
                'status'     => (string) $status,
                'sort'       => $sortRaw,
            ]);
        }

        return [
            'title'      => $title,
            'duration'   => max(0, (int) $durationRaw),
            'is_preview' => $isPreview,
            'sort'       => (int) $sortRaw,
            'status'     => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireCourse(int $courseId): array
    {
        $course = Course::adminFind($courseId);

        if ($course === null) {
            $this->fail(url('/admin/courses'), '课程不存在或已被删除。');
        }

        return $course;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireChapter(int $chapterId, int $courseId): array
    {
        $chapter = Chapter::adminFind($chapterId, $courseId);

        if ($chapter === null) {
            $this->fail(
                url('/admin/courses/' . $courseId . '/chapters'),
                '章节不存在或已被删除。'
            );
        }

        return $chapter;
    }
}
