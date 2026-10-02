<?php

declare(strict_types=1);

/**
 * 队列消费进程（P2）
 *
 * 用法示例：
 *   php bin/queue-worker.php                      # 常驻消费全部队列（mail + transcode）
 *   php bin/queue-worker.php --once               # 处理一个任务后退出
 *   php bin/queue-worker.php --stop-when-empty    # 处理完所有待办任务后退出（适合计划任务）
 *   php bin/queue-worker.php --queue=mail         # 只消费邮件队列
 *   php bin/queue-worker.php --queue=mail,transcode --sleep=3 --max-jobs=100
 *
 * 说明：
 * - 原生 PHP + 数据库队列，不依赖 Redis / 队列扩展，适合中小站点与虚拟主机定时任务。
 * - 生产环境建议配合计划任务（每 1 分钟拉起一次 --stop-when-empty），
 *   或使用 supervisor / systemd 常驻运行本脚本。
 */

use App\Jobs\MailJob;
use App\Jobs\TranscodeVideoJob;
use App\Support\Queue;

require dirname(__DIR__) . '/app/bootstrap.php';

/** 队列名 => [任务类型 => 处理函数] */
$registries = [
    MailJob::QUEUE           => [
        MailJob::TYPE => [MailJob::class, 'handle'],
    ],
    TranscodeVideoJob::QUEUE => [
        TranscodeVideoJob::TYPE => [TranscodeVideoJob::class, 'handle'],
    ],
];

$options = getopt('', ['queue::', 'once', 'sleep::', 'stop-when-empty', 'max-jobs::']);

/** 解析 --queue：支持逗号分隔与 all，缺省即全部队列 */
$allQueues = array_keys($registries);
$queues    = $allQueues;

if (isset($options['queue']) && is_string($options['queue']) && trim($options['queue']) !== '') {
    $requested = array_filter(array_map('trim', explode(',', $options['queue'])), static fn ($q) => $q !== '');

    if ($requested !== [] && !in_array('all', $requested, true)) {
        $unknown = array_diff($requested, $allQueues);
        if ($unknown !== []) {
            fwrite(STDERR, '未知的队列名：' . implode(', ', $unknown) . PHP_EOL);
            exit(1);
        }

        $queues = array_values($requested);
    }
}

$once          = array_key_exists('once', $options);
$stopWhenEmpty = $once || array_key_exists('stop-when-empty', $options);
$sleepSeconds  = isset($options['sleep']) ? max(1, (int) $options['sleep']) : 3;
$maxJobs       = isset($options['max-jobs']) ? max(1, (int) $options['max-jobs']) : 0;

$log = static function (string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
};

/**
 * 安全执行队列状态变更（complete / fail / release）。
 *
 * 状态写回失败（如数据库短暂不可用）只记录日志，不抛出，避免整个消费进程退出。
 */
$updateQueue = static function (callable $action, string $context) use ($log): void {
    try {
        $action();
    } catch (Throwable $e) {
        $log($context . '失败：' . $e->getMessage());
    }
};

// 常驻进程兜底：捕获内存耗尽、超时等致命错误，记录后正常退出（否则错误会静默丢失）
register_shutdown_function(static function () use ($log): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $log('进程因致命错误退出：' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);
});

$log('队列消费者已启动：queue=' . implode(',', $queues) . ($stopWhenEmpty ? '（处理完即退出）' : '（常驻模式）'));

$processed = 0;
$running   = true;

while ($running) {
    $idle = true;

    try {
        // 按顺序轮询各队列，避免某个繁忙队列饿死其它队列
        foreach ($queues as $queue) {
            try {
                $job = Queue::reserve($queue);
            } catch (Throwable $e) {
                // 数据库等基础设施异常：等待后重试，不让进程直接崩溃
                $log('预留任务失败（' . $queue . '）：' . $e->getMessage());
                $idle = false;
                sleep($sleepSeconds);
                continue;
            }

            if ($job === null) {
                continue;
            }

            $idle    = false;
            $jobId   = (int) $job['id'];
            $payload = json_decode((string) ($job['payload'] ?? ''), true);

            if (!is_array($payload)) {
                $updateQueue(static fn () => Queue::fail($jobId, '任务载荷不是合法的 JSON。'), '标记任务 #' . $jobId . ' 永久');
                $log('任务 #' . $jobId . ' 载荷解析失败，已标记为永久失败。');
            } else {
                $type = (string) ($payload['type'] ?? '');
                $handler = $registries[$queue][$type] ?? null;

                if ($handler === null) {
                    $updateQueue(static fn () => Queue::fail($jobId, '未知的任务类型：' . $type), '标记任务 #' . $jobId . ' 永久');
                    $log('任务 #' . $jobId . ' 类型未知（' . $type . '），已标记为永久失败。');
                } else {
                    try {
                        // 传入任务 ID：长任务（如视频转码）可用 Queue::renew() 续租
                        $handler($payload, $jobId);
                        $updateQueue(static fn () => Queue::complete($jobId), '完成任务 #' . $jobId);
                        $processed++;

                        $log('任务 #' . $jobId . '（' . $type . '）执行成功。');
                    } catch (Throwable $e) {
                        // 交给队列决定重试或永久失败（含退避策略）
                        $updateQueue(static fn () => Queue::release($jobId, $e->getMessage()), '重排任务 #' . $jobId);
                        $log('任务 #' . $jobId . '（' . $type . '）执行失败：' . $e->getMessage());
                    }
                }
            }

            if ($once) {
                $running = false;
                break;
            }

            if ($maxJobs > 0 && $processed >= $maxJobs) {
                $log('已达到本次最大处理数量（' . $maxJobs . '），退出。');
                $running = false;
                break;
            }
        }
    } catch (Throwable $e) {
        // 兜底：任何未预期的异常都不应让常驻进程退出
        $log('消费循环发生未预期异常：' . $e->getMessage());
        $idle = false;
        sleep($sleepSeconds);
    }

    if (!$running) {
        break;
    }

    if ($idle) {
        if ($stopWhenEmpty) {
            $log('当前没有待处理任务，退出。');
            break;
        }

        sleep($sleepSeconds);
    }
}

$log('本次共处理 ' . $processed . ' 个任务。');
exit(0);
