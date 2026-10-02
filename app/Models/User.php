<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Ids;
use App\Support\Request;
use App\Support\Search;

/**
 * 用户模型
 *
 * 只封装与「账号」直接相关的读写；登录态由 App\Support\Auth 负责。
 */
final class User
{
    /** 允许的账户状态 */
    public const STATUS_ACTIVE   = 1;
    public const STATUS_DISABLED = 0;

    /** 后台导出单次最大条数，防止超大结果集耗尽内存 */
    public const MAX_EXPORT_ROWS = 50000;

    /**
     * 按主键查询（不含软删除）
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return Database::first(
            'SELECT * FROM `users` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$id]
        );
    }

    /**
     * 按登录名查询
     *
     * @return array<string, mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        return Database::first(
            'SELECT * FROM `users` WHERE `username` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$username]
        );
    }

    /**
     * 按邮箱查询
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return Database::first(
            'SELECT * FROM `users` WHERE `email` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$email]
        );
    }

    public static function usernameExists(string $username): bool
    {
        return Database::scalar(
            'SELECT COUNT(*) FROM `users` WHERE `username` = ? AND `deleted_at` IS NULL',
            [$username]
        ) > 0;
    }

    /**
     * 邮箱是否已被其他账号占用（$exceptUserId 用于换绑时排除自己）
     */
    public static function emailExists(string $email, ?int $exceptUserId = null): bool
    {
        $sql       = 'SELECT COUNT(*) FROM `users` WHERE `email` = ? AND `deleted_at` IS NULL';
        $bindings  = [$email];

        if ($exceptUserId !== null) {
            $sql       .= ' AND `id` <> ?';
            $bindings[] = $exceptUserId;
        }

        return Database::scalar($sql, $bindings) > 0;
    }

    /**
     * 创建账号，返回新用户 ID
     */
    public static function create(string $username, string $passwordHash, ?string $email = null): int
    {
        return Database::insert(
            'INSERT INTO `users`
                (`username`, `nickname`, `email`, `password_hash`, `role`, `is_admin`, `status`,
                 `register_ip`, `register_ua`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?, NOW(), NOW())',
            [
                $username,
                $username,
                $email,
                $passwordHash,
                'user',
                Request::ip(),
                Request::userAgent(),
            ]
        );
    }

    /**
     * 记录一次成功登录
     */
    public static function touchLogin(int $id): void
    {
        Database::execute(
            'UPDATE `users` SET `last_login_at` = NOW(), `last_login_ip` = ? WHERE `id` = ?',
            [Request::ip(), $id]
        );
    }

    /**
     * 绑定并标记邮箱已验证
     */
    public static function bindEmail(int $id, string $email): void
    {
        Database::execute(
            'UPDATE `users` SET `email` = ?, `email_verified_at` = NOW() WHERE `id` = ?',
            [$email, $id]
        );
    }

    public static function updatePassword(int $id, string $passwordHash): void
    {
        Database::execute(
            'UPDATE `users` SET `password_hash` = ? WHERE `id` = ?',
            [$passwordHash, $id]
        );
    }

    /**
     * 更新昵称
     */
    public static function updateNickname(int $id, string $nickname): void
    {
        Database::execute('UPDATE `users` SET `nickname` = ? WHERE `id` = ?', [$nickname, $id]);
    }

    /**
     * 更新头像路径（传 null 表示清除头像）
     */
    public static function updateAvatar(int $id, ?string $avatar): void
    {
        Database::execute('UPDATE `users` SET `avatar` = ? WHERE `id` = ?', [$avatar, $id]);
    }

    /**
     * 更新主题偏好（light / dark / system）
     */
    public static function updateDarkMode(int $id, string $mode): void
    {
        Database::execute('UPDATE `users` SET `dark_mode` = ? WHERE `id` = ?', [$mode, $id]);
    }

    /**
     * 启用两步验证（写入密钥并标记启用）
     */
    public static function enableTwoFactor(int $id, string $secret, ?int $step = null): void
    {
        Database::execute(
            'UPDATE `users`
                SET `two_factor_secret` = ?, `two_factor_enabled` = 1, `two_factor_last_step` = ?
              WHERE `id` = ?',
            [$secret, $step, $id]
        );
    }

    /**
     * 关闭两步验证并清除密钥
     */
    public static function disableTwoFactor(int $id): void
    {
        Database::execute(
            'UPDATE `users`
                SET `two_factor_secret` = NULL, `two_factor_enabled` = 0, `two_factor_last_step` = NULL
              WHERE `id` = ?',
            [$id]
        );
    }

    /**
     * 原子占用一个 TOTP 时间步（用于两步验证的一次性防重放）
     *
     * 仅当该时间步大于已记录的时间步时才更新，保证同一用户同一时间步只通过一次；
     * 并发重复提交时只有一个请求能成功。
     *
     * @return bool 是否成功占用（false 表示该时间步已被使用过）
     */
    public static function consumeTwoFactorStep(int $id, int $step): bool
    {
        $affected = Database::execute(
            'UPDATE `users`
                SET `two_factor_last_step` = ?
              WHERE `id` = ? AND (`two_factor_last_step` IS NULL OR `two_factor_last_step` < ?)',
            [$step, $id, $step]
        );

        return $affected === 1;
    }

    /**
     * 写入登录日志（成功与失败都记录，用于风控与审计）
     */
    public static function logLogin(?int $userId, string $username, string $status, string $message = ''): void
    {
        Database::execute(
            'INSERT INTO `login_logs` (`user_id`, `username`, `ip`, `ua`, `status`, `message`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$userId, $username, Request::ip(), Request::userAgent(), $status, $message]
        );
    }

    /**
     * 统计指定时间窗口内的登录失败次数
     */
    public static function countLoginFailures(string $username, string $ip, int $seconds): int
    {
        $since = date('Y-m-d H:i:s', time() - $seconds);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `login_logs`
              WHERE `status` = \'fail\'
                AND `created_at` >= ?
                AND (`username` = ? OR `ip` = ?)',
            [$since, $username, $ip]
        );
    }

    /**
     * 该账号/IP 最近一次失败登录时间戳（用于锁定判断）
     */
    public static function lastFailureAt(string $username, string $ip): ?int
    {
        $value = Database::scalar(
            'SELECT `created_at` FROM `login_logs`
              WHERE `status` = \'fail\'
                AND (`username` = ? OR `ip` = ?)
              ORDER BY `id` DESC LIMIT 1',
            [$username, $ip]
        );

        return $value === null ? null : (int) strtotime((string) $value);
    }

    // ---------------- 后台用户管理 ----------------

    /**
     * 后台用户列表（附带订单数）
     *
     * 支持的筛选：keyword（用户名/昵称/邮箱）、status、role、banned
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function adminList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        return Database::select(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM `orders` o
                      WHERE o.`user_id` = u.`id` AND o.`deleted_at` IS NULL) AS orders_count
               FROM `users` u
              WHERE ' . $where . '
              ORDER BY u.`id` DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bindings
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function adminCount(array $filters = []): int
    {
        [$where, $bindings] = self::adminWhere($filters);

        return (int) Database::scalar('SELECT COUNT(*) FROM `users` u WHERE ' . $where, $bindings);
    }

    /**
     * 后台用户导出（附带订单数，不参与分页）
     *
     * 与 adminList 共用筛选条件，最多导出 MAX_EXPORT_ROWS 条，防止超大结果集耗尽内存。
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function adminExportRows(array $filters = []): array
    {
        [$where, $bindings] = self::adminWhere($filters);

        return Database::select(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM `orders` o
                      WHERE o.`user_id` = u.`id` AND o.`deleted_at` IS NULL) AS orders_count
               FROM `users` u
              WHERE ' . $where . '
              ORDER BY u.`id` DESC
              LIMIT ' . self::MAX_EXPORT_ROWS,
            $bindings
        );
    }

    /**
     * 管理员账号数量（用于「保护最后一个管理员」）
     */
    public static function countAdmins(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM `users` u
              WHERE u.`deleted_at` IS NULL
                AND (
                    u.`is_admin` = 1 OR u.`role` IN ('admin', 'super_admin')
                    OR EXISTS (
                        SELECT 1 FROM `user_roles` ur
                        JOIN `roles` r ON r.`id` = ur.`role_id`
                        WHERE ur.`user_id` = u.`id`
                          AND r.`deleted_at` IS NULL
                          AND r.`code` IN ('super_admin', 'admin', 'support', 'operator')
                    )
                )"
        );
    }

    /**
     * 后台更新资料（昵称、邮箱、状态、管理员标记、主角色）
     *
     * role 与 is_admin 需保持一致：
     * - 显式传入 role（RBAC 多角色归并后的主角色）时以其为准；
     * - 未传 role 时按管理员标记推导：是管理员 -> admin，否则 -> user。
     *
     * @param array<string, mixed> $data
     */
    public static function adminUpdate(int $id, array $data): void
    {
        $isAdmin = (int) ($data['is_admin'] ?? 0) === 1;
        $email   = trim((string) ($data['email'] ?? ''));

        $role = trim((string) ($data['role'] ?? ''));
        if ($role === '') {
            $role = $isAdmin ? 'admin' : 'user';
        }

        $fields   = ['`nickname` = ?', '`email` = ?', '`status` = ?', '`is_admin` = ?', '`role` = ?'];
        $bindings = [
            (string) ($data['nickname'] ?? ''),
            $email !== '' ? $email : null,
            (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            $isAdmin ? 1 : 0,
            $role,
        ];

        // 邮箱被管理员手动修改/清空时同步邮箱验证时间：
        // 传入 email_verified=true 标记为已验证（NOW()），false 则清空为 NULL。
        if (array_key_exists('email_verified', $data)) {
            $fields[] = '`email_verified_at` = ' . ((bool) $data['email_verified'] ? 'NOW()' : 'NULL');
        }

        $bindings[] = $id;

        Database::execute(
            'UPDATE `users` SET ' . implode(', ', $fields) . ' WHERE `id` = ?',
            $bindings
        );
    }

    /**
     * 后台创建账号，返回新用户 ID
     *
     * 与前台注册不同：可由管理员直接指定昵称、邮箱、管理员标记与状态；
     * 邮箱非空时视为已验证（管理员手动录入视为可信）。
     *
     * @param array<string, mixed> $data
     */
    public static function adminCreate(array $data): int
    {
        $isAdmin  = (int) ($data['is_admin'] ?? 0) === 1;
        $email    = trim((string) ($data['email'] ?? ''));
        $username = (string) ($data['username'] ?? '');

        $role = trim((string) ($data['role'] ?? ''));
        if ($role === '') {
            $role = $isAdmin ? 'admin' : 'user';
        }

        $nickname = trim((string) ($data['nickname'] ?? ''));

        return Database::insert(
            'INSERT INTO `users`
                (`username`, `nickname`, `email`, `password_hash`, `role`, `is_admin`, `status`,
                 `email_verified_at`, `register_ip`, `register_ua`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $username,
                $nickname !== '' ? $nickname : $username,
                $email !== '' ? $email : null,
                (string) ($data['password_hash'] ?? ''),
                $role,
                $isAdmin ? 1 : 0,
                (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
                $email !== '' ? date('Y-m-d H:i:s') : null,
                Request::ip(),
                Request::userAgent(),
            ]
        );
    }

    /**
     * 启用 / 禁用账号
     */
    public static function setStatus(int $id, int $status): void
    {
        Database::execute('UPDATE `users` SET `status` = ? WHERE `id` = ?', [$status, $id]);
    }

    /**
     * 设置 / 取消管理员权限
     */
    public static function setAdmin(int $id, bool $isAdmin): void
    {
        Database::execute(
            'UPDATE `users` SET `is_admin` = ?, `role` = ? WHERE `id` = ?',
            [$isAdmin ? 1 : 0, $isAdmin ? 'admin' : 'user', $id]
        );
    }

    /**
     * 封禁账号（temp 需给出到期时间，permanent 传 null）
     */
    public static function ban(int $id, string $type, ?string $until, ?string $reason): void
    {
        Database::execute(
            'UPDATE `users` SET `ban_type` = ?, `banned_until` = ?, `ban_reason` = ? WHERE `id` = ?',
            [$type, $until, $reason, $id]
        );
    }

    /**
     * 解除封禁
     */
    public static function unban(int $id): void
    {
        Database::execute(
            "UPDATE `users`
                SET `ban_type` = 'none', `banned_until` = NULL, `ban_reason` = NULL
              WHERE `id` = ?",
            [$id]
        );
    }

    /**
     * 软删除并匿名化账号（保留订单等历史数据，释放用户名与邮箱占用）
     */
    public static function softDelete(int $id): void
    {
        Database::execute(
            "UPDATE `users`
                SET `username` = ?, `nickname` = ?, `email` = NULL,
                    `status` = 0, `is_admin` = 0, `role` = 'user', `deleted_at` = NOW()
              WHERE `id` = ?",
            ['deleted_' . $id, '已注销用户', $id]
        );
    }

    /**
     * 批量按主键查询（不含软删除），用于批量操作前的管理员保护预检
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public static function findManyByIds(array $ids): array
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Database::select(
            'SELECT * FROM `users`
              WHERE `deleted_at` IS NULL AND `id` IN (' . $placeholders . ')',
            $ids
        );
    }

    /**
     * 批量启用 / 禁用账号，返回受影响行数
     *
     * @param array<int, int> $ids
     */
    public static function setStatusMany(array $ids, int $status): int
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Database::execute(
            'UPDATE `users` SET `status` = ?
              WHERE `deleted_at` IS NULL AND `id` IN (' . $placeholders . ')',
            array_merge([$status], $ids)
        );
    }

    /**
     * 批量解除封禁，返回受影响行数
     *
     * @param array<int, int> $ids
     */
    public static function unbanMany(array $ids): int
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Database::execute(
            "UPDATE `users`
                SET `ban_type` = 'none', `banned_until` = NULL, `ban_reason` = NULL
              WHERE `deleted_at` IS NULL AND `id` IN (" . $placeholders . ')',
            $ids
        );
    }

    /**
     * 批量软删除并匿名化账号，返回受影响行数
     *
     * @param array<int, int> $ids
     */
    public static function softDeleteMany(array $ids): int
    {
        $ids = Ids::normalize($ids);
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Database::execute(
            "UPDATE `users`
                SET `username` = CONCAT('deleted_', `id`), `nickname` = '已注销用户', `email` = NULL,
                    `status` = 0, `is_admin` = 0, `role` = 'user', `deleted_at` = NOW()
              WHERE `deleted_at` IS NULL AND `id` IN (" . $placeholders . ')',
            $ids
        );
    }

    /**
     * 组装后台列表的 WHERE 条件
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function adminWhere(array $filters): array
    {
        $conditions = ['u.`deleted_at` IS NULL'];
        $bindings   = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = '(u.`username` LIKE ? OR u.`nickname` LIKE ? OR u.`email` LIKE ?)';
            $like         = Search::likePattern($keyword);
            $bindings[]   = $like;
            $bindings[]   = $like;
            $bindings[]   = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === '0' || $status === '1') {
            $conditions[] = 'u.`status` = ?';
            $bindings[]   = (int) $status;
        }

        $role = (string) ($filters['role'] ?? '');
        if ($role === 'admin') {
            $conditions[] = "(u.`is_admin` = 1 OR u.`role` IN ('admin', 'super_admin'))";
        } elseif ($role === 'user') {
            $conditions[] = "u.`is_admin` = 0 AND u.`role` NOT IN ('admin', 'super_admin')";
        }

        if (!empty($filters['banned'])) {
            $conditions[] = "u.`ban_type` <> 'none'
                             AND (u.`ban_type` = 'permanent' OR u.`banned_until` IS NULL OR u.`banned_until` > NOW())";
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
