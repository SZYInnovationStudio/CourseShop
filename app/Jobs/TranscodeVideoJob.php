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
     */
    public static function handle(array $payload): void
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
        // 任务已被取消 / 已被更新的任务取代 / 记录被删除
        if ($transcode === null || (string) $transcode['status'] !== VideoTranscode::STATUS_PENDING) {
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

        try {
            $output = Transcoder::transcodeToHls(
                $chapterId,
                $source,
                (int) ($chapter['duration'] ?? 0),
                static function (int $progress) use ($transcodeId): void {
                    VideoTranscode::updateProgress($transcodeId, $progress);
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
