<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Request;

/**
 * 协议模型（用户协议 / 隐私政策 / 退款政策）
 *
 * 协议按「类型 + 版本」存档，同一类型只有一条 is_current = 1 的当前版本。
 */
final class Agreement
{
    /** 支持的协议类型 */
    public const TYPES = ['terms', 'privacy', 'refund'];

    /** 类型 => 中文名 */
    public const LABELS = [
        'terms'   => '用户协议',
        'privacy' => '隐私政策',
        'refund'  => '退款政策',
    ];

    /**
     * 取某类型当前生效的协议
     *
     * @return array<string, mixed>|null
     */
    public static function current(string $type): ?array
    {
        return Database::first(
            'SELECT * FROM `agreements`
              WHERE `type` = ? AND `is_current` = 1 AND `deleted_at` IS NULL
              ORDER BY `effective_at` DESC, `id` DESC
              LIMIT 1',
            [$type]
        );
    }

    /**
     * 记录一次用户同意（注册、修改协议后重新确认时调用）
     *
     * @param array<string, mixed> $agreement
     */
    public static function record(int $userId, array $agreement): void
    {
        Database::execute(
            'INSERT INTO `user_agreements`
                (`user_id`, `agreement_id`, `type`, `version`, `ip`, `ua`, `agreed_at`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $userId,
                (int) $agreement['id'],
                (string) $agreement['type'],
                (string) $agreement['version'],
                Request::ip(),
                Request::userAgent(),
            ]
        );
    }

    // ============================================================
    // 后台协议管理
    // ============================================================

    /**
     * 各类型当前生效版本（类型 => 记录），用于后台总览与前台快速读取
     *
     * @return array<string, array<string, mixed>>
     */
    public static function currentMap(): array
    {
        $rows = Database::select(
            'SELECT * FROM `agreements`
              WHERE `is_current` = 1 AND `deleted_at` IS NULL
              ORDER BY `type` ASC, `effective_at` DESC, `id` DESC'
        );

        $map = [];
        foreach ($rows as $row) {
            $type = (string) $row['type'];
            // 同类型若有异常的多条当前版本，保留首条（排序已保证最新）
            $map[$type] ??= $row;
        }

        return $map;
    }

    /**
     * 某类型的历史版本（最新在前）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function history(string $type, int $limit = 20): array
    {
        $limit = max(1, $limit);

        return Database::select(
            "SELECT `id`, `type`, `version`, `title`, `effective_at`, `is_current`, `created_at`
               FROM `agreements`
              WHERE `type` = ? AND `deleted_at` IS NULL
              ORDER BY `effective_at` DESC, `id` DESC
              LIMIT {$limit}",
            [$type]
        );
    }

    /**
     * 发布/更新某类型的协议版本
     *
     * 同类型仅保留一条 is_current = 1；若版本号已存在则就地更新，否则新增一条记录。
     *
     * @param string|null $effectiveAt 生效日期（Y-m-d H:i:s），空串按当前时间处理
     */
    public static function publish(
        string $type,
        string $version,
        string $title,
        string $content,
        ?string $effectiveAt
    ): void {
        $effectiveAt = ($effectiveAt === null || trim($effectiveAt) === '') ? null : $effectiveAt;

        Database::transaction(static function () use ($type, $version, $title, $content, $effectiveAt): void {
            Database::execute(
                'UPDATE `agreements` SET `is_current` = 0 WHERE `type` = ? AND `is_current` = 1',
                [$type]
            );

            $existing = Database::first(
                'SELECT `id` FROM `agreements`
                  WHERE `type` = ? AND `version` = ? AND `deleted_at` IS NULL
                  LIMIT 1',
                [$type, $version]
            );

            if ($existing !== null) {
                Database::execute(
                    'UPDATE `agreements`
                        SET `title` = ?, `content` = ?, `effective_at` = ?, `is_current` = 1
                      WHERE `id` = ?',
                    [$title, $content, $effectiveAt, (int) $existing['id']]
                );

                return;
            }

            Database::insert(
                'INSERT INTO `agreements`
                    (`type`, `version`, `title`, `content`, `effective_at`, `is_current`, `created_at`, `updated_at`)
                 VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [$type, $version, $title, $content, $effectiveAt]
            );
        });
    }

    /**
     * 该类型下版本号是否已存在
     */
    public static function versionExists(string $type, string $version): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `agreements`
              WHERE `type` = ? AND `version` = ? AND `deleted_at` IS NULL',
            [$type, $version]
        ) > 0;
    }
}
