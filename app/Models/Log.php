<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Search;
use Throwable;

/**
 * 日志中心（P2）
 *
 * 统一承载五类日志的「写入 + 查询」：
 *   - 登录日志 login_logs（登录/退出流程写入，见 User::logLogin）
 *   - 操作日志 operation_logs（后台管理写操作写入，见 recordOperation）
 *   - 支付日志 payment_logs（支付网关交互写入，见 PaymentLog）
 *   - 邮件日志 mail_logs（邮件发送写入，见 Mailer）
 *   - 验证码日志 captcha_logs（验证码下发/校验写入，见 recordCaptcha）
 *
 * 读取侧按类型动态拼装 WHERE 条件，统一分页展示，避免为每张表各写一套查询。
 */
final class Log
{
    /** 日志类型 => 中文名（后台 Tab 顺序） */
    public const TYPES = [
        'login'     => '登录日志',
        'operation' => '操作日志',
        'payment'   => '支付日志',
        'mail'      => '邮件日志',
        'captcha'   => '验证码日志',
    ];

    /** 类型 => 物理表名 */
    private const TABLES = [
        'login'     => 'login_logs',
        'operation' => 'operation_logs',
        'payment'   => 'payment_logs',
        'mail'      => 'mail_logs',
        'captcha'   => 'captcha_logs',
    ];

    /** 登录日志状态 */
    public const LOGIN_STATUS = ['success' => '成功', 'fail' => '失败'];

    /** 支付日志动作 */
    public const PAYMENT_ACTIONS = [
        'create' => '创建支付',
        'return' => '同步回调',
        'notify' => '异步通知',
        'query'  => '订单查询',
        'refund' => '退款',
    ];

    /** 支付日志状态 */
    public const PAYMENT_STATUS = ['success' => '成功', 'fail' => '失败', 'recv' => '已接收'];

    /** 邮件日志状态 */
    public const MAIL_STATUS = ['success' => '成功', 'fail' => '失败'];

    /** 验证码日志状态 */
    public const CAPTCHA_STATUS = ['issued' => '已下发', 'passed' => '通过', 'failed' => '失败'];

    /** 验证码场景 */
    public const CAPTCHA_SCENES = [
        'login'    => '登录',
        'register' => '注册',
        'email'    => '邮箱绑定',
        'reset'    => '找回密码',
        'appeal'   => '申诉',
    ];

    /** 操作日志动作 => 中文名（未收录的动作回退为原始标识） */
    public const OPERATION_ACTIONS = [
        'course.create'       => '新建课程',
        'course.update'       => '修改课程',
        'course.delete'       => '删除课程',
        'course.status'       => '调整课程状态',
        'chapter.create'      => '新建章节',
        'chapter.update'      => '修改章节',
        'chapter.delete'      => '删除章节',
        'chapter.transcode'   => '提交转码任务',
        'category.create'     => '新建分类',
        'category.update'     => '修改分类',
        'category.delete'     => '删除分类',
        'user.update'         => '修改用户',
        'user.create'         => '新建用户',
        'user.ban'            => '封禁用户',
        'user.unban'          => '解封用户',
        'user.reset_password' => '重置用户密码',
        'user.delete'         => '删除用户',
        'role.create'         => '新建角色',
        'role.update'         => '修改角色',
        'role.delete'         => '删除角色',
        'order.close'         => '关闭订单',
        'order.complete'      => '完成订单',
        'order.delete'        => '删除订单',
        'order.reconcile'     => '订单对账',
        'refund.create'       => '发起退款',
        'refund.approve'      => '确认退款',
        'refund.reject'       => '驳回退款',
        'ticket.reply'        => '回复工单',
        'ticket.status'       => '调整工单状态',
        'ticket.priority'     => '调整工单优先级',
        'ticket.unban'        => '工单解封',
        'ticket.delete'       => '删除工单',
        'announcement.create' => '新建公告',
        'announcement.update' => '修改公告',
        'announcement.delete' => '删除公告',
        'agreement.update'    => '修改协议',
        'settings.update'     => '修改系统设置',
        'settings.cache_clear' => '清空缓存',
    ];

    public static function normalizeType(string $type): string
    {
        return array_key_exists($type, self::TYPES) ? $type : 'login';
    }

    /** 动作标识 => 中文展示名 */
    public static function actionLabel(string $action): string
    {
        return self::OPERATION_ACTIONS[$action] ?? ($action !== '' ? $action : '—');
    }

    // ------------------------------------------------------------------
    // 写入
    // ------------------------------------------------------------------

    /**
     * 记录一条后台操作日志（写入失败不影响主流程）
     *
     * @param array<string, mixed> $detail 结构化补充信息，将以 JSON 落库
     */
    public static function recordOperation(
        string $action,
        string $targetType = '',
        ?int $targetId = null,
        array $detail = []
    ): void {
        try {
            $user = Auth::user();

            Database::execute(
                'INSERT INTO `operation_logs`
                    (`user_id`, `username`, `action`, `target_type`, `target_id`, `detail`, `ip`, `ua`, `created_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $user !== null ? (int) $user['id'] : null,
                    $user !== null ? (string) $user['username'] : '',
                    $action,
                    $targetType,
                    $targetId,
                    $detail !== [] ? self::encode($detail) : null,
                    Request::ip(),
                    Request::userAgent(),
                ]
            );
        } catch (Throwable $e) {
            Logger::warning('写入操作日志失败：' . $e->getMessage());
        }
    }

    /**
     * 记录一条验证码日志（下发 / 通过 / 失败）
     */
    public static function recordCaptcha(string $scene, string $status): void
    {
        try {
            Database::execute(
                'INSERT INTO `captcha_logs` (`scene`, `ip`, `status`, `created_at`) VALUES (?, ?, ?, NOW())',
                [$scene, Request::ip(), $status]
            );
        } catch (Throwable $e) {
            Logger::warning('写入验证码日志失败：' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    /**
     * 分页查询指定类型的日志
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function page(string $type, array $filters, int $perPage, int $offset): array
    {
        $type = self::normalizeType($type);
        [$where, $bindings] = self::buildWhere($type, $filters);

        return Database::select(
            'SELECT * FROM `' . self::TABLES[$type] . '`' . $where . '
              ORDER BY `id` DESC
              LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );
    }

    /**
     * 统计指定类型日志条数
     *
     * @param array<string, mixed> $filters
     */
    public static function count(string $type, array $filters): int
    {
        $type = self::normalizeType($type);
        [$where, $bindings] = self::buildWhere($type, $filters);

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::TABLES[$type] . '`' . $where,
            $bindings
        );
    }

    /**
     * 查询某个用户自身的日志（登录 / 操作），用于后台用户详情页
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(string $type, int $userId, int $limit = 20): array
    {
        $type = self::normalizeType($type);

        if ($userId <= 0 || !in_array($type, ['login', 'operation'], true)) {
            return [];
        }

        return Database::select(
            'SELECT * FROM `' . self::TABLES[$type] . '`
              WHERE `user_id` = ?
              ORDER BY `id` DESC
              LIMIT ' . max(1, $limit),
            [$userId]
        );
    }

    /**
     * 各类型日志总量（用于 Tab 上的计数徽标）
     *
     * @return array<string, int>
     */
    public static function totals(): array
    {
        $totals = [];

        foreach (array_keys(self::TYPES) as $type) {
            $totals[$type] = (int) Database::scalar('SELECT COUNT(*) FROM `' . self::TABLES[$type] . '`');
        }

        return $totals;
    }

    /**
     * 解析并归一化筛选条件（非法值一律置空，避免拼进 SQL）
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function normalizeFilters(string $type, array $raw): array
    {
        $filters = [
            'keyword'   => trim((string) ($raw['keyword'] ?? '')),
            'status'    => trim((string) ($raw['status'] ?? '')),
            'action'    => trim((string) ($raw['action'] ?? '')),
            'scene'     => trim((string) ($raw['scene'] ?? '')),
            'date_from' => trim((string) ($raw['date_from'] ?? '')),
            'date_to'   => trim((string) ($raw['date_to'] ?? '')),
        ];

        // 按类型收敛枚举字段
        $allowed = match (self::normalizeType($type)) {
            'login'     => array_keys(self::LOGIN_STATUS),
            'payment'   => array_keys(self::PAYMENT_STATUS),
            'mail'      => array_keys(self::MAIL_STATUS),
            'captcha'   => array_keys(self::CAPTCHA_STATUS),
            default     => [],
        };

        if (!in_array($filters['status'], $allowed, true)) {
            $filters['status'] = '';
        }

        if ($filters['scene'] !== '' && !array_key_exists($filters['scene'], self::CAPTCHA_SCENES)) {
            $filters['scene'] = '';
        }

        return $filters;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * 按类型拼装 WHERE 子句
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function buildWhere(string $type, array $filters): array
    {
        $conditions = [];
        $bindings   = [];

        $from = trim((string) ($filters['date_from'] ?? ''));
        if ($from !== '') {
            $conditions[] = '`created_at` >= ?';
            $bindings[]   = $from . ' 00:00:00';
        }

        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($to !== '') {
            $conditions[] = '`created_at` <= ?';
            $bindings[]   = $to . ' 23:59:59';
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $status  = (string) ($filters['status'] ?? '');
        $action  = trim((string) ($filters['action'] ?? ''));
        $scene   = (string) ($filters['scene'] ?? '');

        switch ($type) {
            case 'login':
                if ($keyword !== '') {
                    $conditions[] = '(`username` LIKE ? OR `ip` LIKE ?)';
                    $bindings[]   = Search::likePattern($keyword);
                    $bindings[]   = Search::likePattern($keyword);
                }
                if ($status !== '') {
                    $conditions[] = '`status` = ?';
                    $bindings[]   = $status;
                }
                break;

            case 'operation':
                if ($keyword !== '') {
                    $conditions[] = '(`username` LIKE ? OR `action` LIKE ? OR `target_type` LIKE ?)';
                    $like         = Search::likePattern($keyword);
                    $bindings[]   = $like;
                    $bindings[]   = $like;
                    $bindings[]   = $like;
                }
                if ($action !== '') {
                    $conditions[] = '`action` LIKE ?';
                    $bindings[]   = Search::likePattern($action);
                }
                break;

            case 'payment':
                if ($keyword !== '') {
                    $conditions[] = '`order_no` LIKE ?';
                    $bindings[]   = Search::likePattern($keyword);
                }
                if ($action !== '') {
                    $conditions[] = '`action` = ?';
                    $bindings[]   = $action;
                }
                if ($status !== '') {
                    $conditions[] = '`status` = ?';
                    $bindings[]   = $status;
                }
                break;

            case 'mail':
                if ($keyword !== '') {
                    $conditions[] = '(`to_email` LIKE ? OR `subject` LIKE ?)';
                    $bindings[]   = Search::likePattern($keyword);
                    $bindings[]   = Search::likePattern($keyword);
                }
                if ($status !== '') {
                    $conditions[] = '`status` = ?';
                    $bindings[]   = $status;
                }
                break;

            case 'captcha':
                if ($keyword !== '') {
                    $conditions[] = '`ip` LIKE ?';
                    $bindings[]   = Search::likePattern($keyword);
                }
                if ($scene !== '') {
                    $conditions[] = '`scene` = ?';
                    $bindings[]   = $scene;
                }
                if ($status !== '') {
                    $conditions[] = '`status` = ?';
                    $bindings[]   = $status;
                }
                break;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    /**
     * 结构化详情转 JSON 并做长度保护（TEXT 上限 65535 字节）
     *
     * @param array<string, mixed> $detail
     */
    private static function encode(array $detail): string
    {
        $json = (string) json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (strlen($json) <= 60000) {
            return $json;
        }

        return mb_strcut($json, 0, 60000, 'UTF-8');
    }
}
