<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * 数据库队列（P2）
 *
 * 用 jobs 表实现最小可用的「入队 / 预留 / 完成 / 重试 / 失败」语义，
 * 由 bin/queue-worker.php 以 CLI 常驻或定时方式消费。
 * 不引入 Redis 等外部依赖，符合「原生 PHP + 无 Composer」的约束。
 */
final class Queue
{
    /** 预留超时（秒）：超过该时长仍未完成的任务会被回收重排 */
    public const RESERVATION_TIMEOUT = 900;

    /**
     * 入队，返回任务 ID
     *
     * @param array<string, mixed> $payload
     */
    public static function push(string $queue, array $payload, int $delaySeconds = 0, int $maxAttempts = 3): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('队列任务载荷无法序列化为 JSON。');
        }

        $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));

        return Database::insert(
            'INSERT INTO `jobs`
                (`queue`, `payload`, `attempts`, `max_attempts`, `available_at`, `created_at`, `updated_at`)
             VALUES (?, ?, 0, ?, ?, NOW(), NOW())',
            [$queue, $json, max(1, $maxAttempts), $availableAt]
        );
    }

    /**
     * 预留一个可执行任务（原子操作）
     *
     * @return array<string, mixed>|null
     */
    public static function reserve(string $queue): ?array
    {
        return Database::transaction(static function () use ($queue): ?array {
            // 先回收执行超时的任务，避免消费者崩溃后任务永久卡住
            Database::execute(
                'UPDATE `jobs`
                    SET `reserved_at` = NULL, `available_at` = NOW(), `updated_at` = NOW()
                  WHERE `queue` = ? AND `reserved_at` IS NOT NULL AND `reserved_at` < ?',
                [$queue, date('Y-m-d H:i:s', time() - self::RESERVATION_TIMEOUT)]
            );

            $job = Database::first(
                'SELECT * FROM `jobs`
                  WHERE `queue` = ? AND `failed_at` IS NULL
                    AND `reserved_at` IS NULL AND `available_at` <= NOW()
                  ORDER BY `id` ASC
                  LIMIT 1
                  FOR UPDATE',
                [$queue]
            );

            if ($job === null) {
                return null;
            }

            Database::execute(
                'UPDATE `jobs` SET `reserved_at` = NOW(), `attempts` = `attempts` + 1, `updated_at` = NOW()
                  WHERE `id` = ?',
                [(int) $job['id']]
            );

            $job['attempts'] = (int) $job['attempts'] + 1;

            return $job;
        });
    }

    /**
     * 续租（心跳）：刷新任务预留时间
     *
     * 视频转码等长任务应周期性调用，否则预留超过 RESERVATION_TIMEOUT 后
     * 会被其它消费者重复领取同一任务。
     */
    public static function renew(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        Database::execute(
            'UPDATE `jobs` SET `reserved_at` = NOW(), `updated_at` = NOW()
              WHERE `id` = ? AND `reserved_at` IS NOT NULL AND `failed_at` IS NULL',
            [$id]
        );
    }

    /**
     * 任务执行成功，出队
     */
    public static function complete(int $id): void
    {
        Database::execute('DELETE FROM `jobs` WHERE `id` = ?', [$id]);
    }

    /**
     * 任务执行失败，未达上限则延迟重试，否则标记永久失败
     */
    public static function release(int $id, string $error = ''): void
    {
        $job = Database::first(
            'SELECT `attempts`, `max_attempts` FROM `jobs` WHERE `id` = ? LIMIT 1',
            [$id]
        );

        if ($job === null) {
            return;
        }

        $attempts    = (int) $job['attempts'];
        $maxAttempts = max(1, (int) $job['max_attempts']);

        if ($attempts >= $maxAttempts) {
            self::fail($id, $error);
            return;
        }

        // 退避：30s、60s、90s… 最长 5 分钟
        $delay = min(300, 30 * max(1, $attempts));

        Database::execute(
            'UPDATE `jobs`
                SET `reserved_at` = NULL, `available_at` = ?, `last_error` = ?, `updated_at` = NOW()
              WHERE `id` = ?',
            [date('Y-m-d H:i:s', time() + $delay), mb_substr($error, 0, 2000), $id]
        );
    }

    /**
     * 标记为永久失败（不再重试，等待人工处理）
     */
    public static function fail(int $id, string $error = ''): void
    {
        Database::execute(
            'UPDATE `jobs`
                SET `failed_at` = NOW(), `reserved_at` = NULL, `last_error` = ?, `updated_at` = NOW()
              WHERE `id` = ?',
            [mb_substr($error, 0, 2000), $id]
        );
    }

    /**
     * 待处理任务数量（不含永久失败）
     */
    public static function size(string $queue): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `jobs` WHERE `queue` = ? AND `failed_at` IS NULL',
            [$queue]
        );
    }
}
