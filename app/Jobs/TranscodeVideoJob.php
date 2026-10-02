<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chapter;
use App\Models\VideoTranscode;
use App\Support\Queue;
use App\Support\Video\Transcoder;
use App\Support\VideoStorage;
use RuntimeException;
use Throwable;

/**
 * 章节视频转码任务（P2）
 *
 * 由 bin/queue-worker.php 消费：把已上传的 mp4 转成 HLS（m3u8 + ts 分片），
 * 成功后就绪状态写入 video_transcodes，前台播放自动切换为 HLS。
 *
 * 设计要点：
 * - 转码失败属于「业务失败」，写入 video_transcodes.error 后不再重抛，
 *   避免队列无意义地反复重试一个注定失败的源文件；播放会自动降级为 mp4。
 * - 其余异常（数据库 / IO 等）向上抛出，交给 Queue::release() 做退避重试。
 * - 重新提交转码时旧任务会被 VideoTranscode::supersedeActive() 置为失败，
 *   此处以「状态仍为 pending」作为执行许可，天然跳过过期任务。
 */
final class TranscodeVideoJob
{
    /** 队列名 */
    public const QUEUE = 'transcode';

    /** 任务类型标识（payload.type） */
    public const TYPE = 'transcode_video';

    /**
     * 入队一个转码任务
     */
    public static function dispatch(int $chapterId, int $transcodeId): int
    {
        return Queue::push(self::QUEUE, [
            'type'         => self::TYPE,
            'chapter_id'   => $chapterId,
            'transcode_id' => $transcodeId,
        ]);
    }

    /**
     * 执行任务
     *
     * @param array<string, mixed> $payload
     * @param int                  $jobId 队列任务 ID，用于长任务续租（0 表示不可续租）
     */
    public static function handle(array $payload, int $jobId = 0): void
    {
        $chapterId   = (int) ($payload['chapter_id'] ?? 0);
        $transcodeId = (int) ($payload['transcode_id'] ?? 0);

        if ($chapterId <= 0 || $transcodeId <= 0) {
            return;
        }

        // 章节已被删除：静默结束
        $chapter = Chapter::findAny($chapterId);
        if ($chapter === null) {
            return;
        }

        $transcode = VideoTranscode::find($transcodeId);
        // 记录被删除 / 已成功 / 已被 supersedeActive() 作废：静默结束
        if ($transcode === null) {
            return;
        }

        $status = (string) $transcode['status'];

        if ($status === VideoTranscode::STATUS_SUCCESS || $status === VideoTranscode::STATUS_FAILED) {
            return;
        }

        // 状态为 running：上一次执行已中断（预留超时被回收后重新领取），
        // 若不处理，记录会永久停留在「转码中」。这里标记失败，允许用户重新提交。
        if ($status === VideoTranscode::STATUS_RUNNING) {
            VideoTranscode::markFailed($transcodeId, '上一次转码执行中断，请重新提交转码。');
            return;
        }

        if (!Transcoder::available()) {
            VideoTranscode::markFailed($transcodeId, '服务器未安装 ffmpeg，无法转码。');
            return;
        }

        $source = VideoStorage::resolve($chapter['video_path'] ?? null);
        if ($source === null) {
            VideoTranscode::markFailed($transcodeId, '源视频文件不存在，请重新上传后再转码。');
            return;
        }

        VideoTranscode::markRunning($transcodeId);

        // 转码是长任务：进入后先续租一次，避免刚开始就接近预留超时
        if ($jobId > 0) {
            Queue::renew($jobId);
        }

        try {
            $output = Transcoder::transcodeToHls(
                $chapterId,
                $transcodeId,
                $source,
                (int) ($chapter['duration'] ?? 0),
                static function (int $progress) use ($transcodeId): void {
                    VideoTranscode::updateProgress($transcodeId, $progress);
                },
                // 心跳：源视频时长未知（无进度输出）时同样能周期性续租
                static function () use ($jobId): void {
                    if ($jobId > 0) {
                        Queue::renew($jobId);
                    }
                }
            );

            VideoTranscode::markSuccess($transcodeId, $output);
        } catch (RuntimeException $e) {
            // 业务失败：记录原因，不再重试
            VideoTranscode::markFailed($transcodeId, $e->getMessage());
        } catch (Throwable $e) {
            // 未知异常：交回队列做退避重试
            throw $e;
        }
    }
}
