<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Enrollment;
use App\Models\Log;
use App\Models\LoginDevice;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountNotifier;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Csv;
use App\Support\Permission;
use App\Support\Request;

/**
 * 后台用户管理
 *
 * 提供用户列表与筛选、资料编辑、启停、管理员设置、重置密码、封禁与软删除。
 * 关键约束：不能对自己执行降权 / 禁用 / 封禁 / 删除；系统必须保留至少一名管理员。
 */
final class UserController extends AdminController
{
    /** 每页条数 */
    private const PER_PAGE = 20;

    /**
     * 用户列表
     */
    public function index(): void
    {
        $filters = self::filters();

        $total      = User::adminCount($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min(max(1, Request::int('page', 1)), $totalPages);

        $this->view('admin.users.index', [
            'pageTitle'  => '用户管理',
            'filters'    => $filters,
            'users'      => User::adminList($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'adminId'    => (int) (Auth::id() ?? 0),
        ]);
    }

    /**
     * 导出用户 CSV（沿用列表当前筛选条件）
     */
    public function export(): void
    {
        $filters = self::filters();
        $rows    = User::adminExportRows($filters);

        $headers = [
            'ID', '用户名', '昵称', '邮箱', '角色', '状态', '封禁状态',
            '订单数', '注册时间', '最后登录时间',
        ];

        $data = [];

        foreach ($rows as $row) {
            $isAdmin = self::isAdminUser($row);

            $data[] = [
                (int) $row['id'],
                (string) $row['username'],
                (string) ($row['nickname'] ?? ''),
                (string) ($row['email'] ?? ''),
                $isAdmin ? '管理员' : '普通用户',
                (int) $row['status'] === 1 ? '正常' : '已禁用',
                self::banLabel($row),
                (int) ($row['orders_count'] ?? 0),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['last_login_at'] ?? ''),
            ];
        }

        Csv::download('users_' . date('Ymd_His') . '.csv', $headers, $data);
    }

    /**
     * 新增用户页
     */
    public function create(): void
    {
        $this->view('admin.users.create', [
            'pageTitle' => '新增用户',
        ]);
    }

    /**
     * 保存新增用户
     */
    public function store(): void
    {
        Csrf::check();

        $backUrl  = url('/admin/users/create');
        $username = Request::string('username');
        $nickname = Request::string('nickname');
        $email    = strtolower(Request::string('email'));
        $password = Request::raw('password');
        $isAdmin  = Request::string('is_admin', '0');

        $old = [
            'username' => $username,
            'nickname' => $nickname,
            'email'    => $email,
            'is_admin' => $isAdmin,
        ];

        $validator = $this->validator(Request::all())
            ->required('username', '用户名')
            ->username('username', '用户名')
            ->min('username', 3, '用户名')
            ->max('username', 20, '用户名')
            ->max('nickname', 50, '昵称')
            ->email('email', '邮箱')
            ->max('email', 190, '邮箱')
            ->required('password', '密码')
            ->min('password', 8, '密码')
            ->max('password', 64, '密码')
            ->in('is_admin', ['0', '1'], '管理员标记');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), $old);
        }

        // 管理员标记属于提权操作：仅超级管理员可创建管理员账号
        if ($isAdmin === '1' && !Auth::isSuperAdmin()) {
            $this->fail($backUrl, '仅超级管理员可创建管理员账号。', $old);
        }

        if (User::usernameExists($username)) {
            $this->fail($backUrl, '该用户名已被使用，请更换一个。', $old);
        }

        if ($email !== '' && User::emailExists($email)) {
            $this->fail($backUrl, '该邮箱已被其他账号使用。', $old);
        }

        $userId = User::adminCreate([
            'username'      => $username,
            'nickname'      => $nickname,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin'      => $isAdmin,
            'status'        => 1,
        ]);

        Log::recordOperation('user.create', 'user', $userId, [
            'username' => $username,
            'is_admin' => $isAdmin,
        ]);

        $this->success(url('/admin/users'), sprintf('已创建账号 %s。', $username));
    }

    /**
     * 用户详情：购买课程、登录日志、操作日志
     */
    public function show(string $id): void
    {
        $userId = (int) $id;
        $target = User::findById($userId);

        if ($target === null) {
            $this->fail(url('/admin/users'), '用户不存在或已被删除。');
        }

        $this->view('admin.users.show', [
            'pageTitle'    => '用户详情',
            'user'         => $target,
            'isAdmin'      => self::isAdminUser($target),
            'enrollments'  => Enrollment::adminCoursesForUser($userId),
            'orderCount'   => Order::countForUser($userId),
            'paidAmount'   => Order::paidAmountForUser($userId),
            'loginLogs'    => Log::forUser('login', $userId, 20),
            'operationLogs' => Log::forUser('operation', $userId, 20),
            'loginStatus'  => Log::LOGIN_STATUS,
        ]);
    }

    /**
     * 解析并归一化列表筛选参数（列表与导出共用）
     *
     * @return array<string, string>
     */
    private static function filters(): array
    {
        $filters = [
            'keyword' => Request::string('keyword'),
            'status'  => Request::string('status'),
            'role'    => Request::string('role'),
            'banned'  => Request::string('banned'),
        ];

        // 归一化，避免非法值进入 SQL 条件拼装
        if (!in_array($filters['status'], ['0', '1'], true)) {
            $filters['status'] = '';
        }
        if (!in_array($filters['role'], ['admin', 'user'], true)) {
            $filters['role'] = '';
        }
        if (!in_array($filters['banned'], ['0', '1'], true)) {
            $filters['banned'] = '';
        }

        return $filters;
    }

    /**
     * 封禁状态文案（供 CSV 导出使用）
     *
     * @param array<string, mixed> $user
     */
    private static function banLabel(array $user): string
    {
        $type = (string) ($user['ban_type'] ?? 'none');

        if ($type === 'none') {
            return '未封禁';
        }

        $until = $user['banned_until'] ?? null;

        if ($type === 'permanent') {
            return '永久封禁';
        }

        if ($until === null || strtotime((string) $until) > time()) {
            return '限时封禁至 ' . (string) $until;
        }

        return '未封禁';
    }

    /**
     * 编辑用户页
     */
    public function edit(string $id): void
    {
        $userId = (int) $id;
        $target = User::findById($userId);

        if ($target === null) {
            $this->fail(url('/admin/users'), '用户不存在或已被删除。');
        }

        $this->view('admin.users.edit', [
            'pageTitle'      => '编辑用户',
            'user'           => $target,
            'isAdmin'        => self::isAdminUser($target),
            'isLastAdmin'    => self::isAdminUser($target) && User::countAdmins() <= 1,
            'isSelf'         => $userId === (int) (Auth::id() ?? 0),
            'roles'          => Role::options(),
            'userRoleIds'    => Role::userIdsRoleIds($userId),
            'canAssignRoles' => Auth::can('rbac.manage'),
        ]);
    }

    /**
     * 保存用户资料
     */
    public function update(string $id): void
    {
        Csrf::check();

        $userId = (int) $id;
        $target = User::findById($userId);

        if ($target === null) {
            $this->fail(url('/admin/users'), '用户不存在或已被删除。');
        }

        $backUrl = url('/admin/users/' . $userId . '/edit');

        $nickname    = Request::string('nickname');
        $email       = strtolower(Request::string('email'));
        $status      = Request::string('status', '1');
        $isAdmin     = Request::string('is_admin', '0');
        $banType     = Request::string('ban_type', 'none');
        $banUntilRaw = Request::string('banned_until');
        $banReason   = Request::string('ban_reason');

        $old = [
            'nickname'     => $nickname,
            'email'        => $email,
            'status'       => $status,
            'is_admin'     => $isAdmin,
            'ban_type'     => $banType,
            'banned_until' => $banUntilRaw,
            'ban_reason'   => $banReason,
        ];

        $validator = $this->validator(Request::all())
            ->required('nickname', '昵称')
            ->max('nickname', 50, '昵称')
            ->email('email', '邮箱')
            ->max('email', 190, '邮箱')
            ->in('status', ['0', '1'], '账号状态')
            ->in('is_admin', ['0', '1'], '管理员标记')
            ->in('ban_type', ['none', 'temp', 'permanent'], '封禁类型')
            ->max('ban_reason', 255, '封禁原因');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), $old);
        }

        if ($email !== '' && User::emailExists($email, $userId)) {
            $this->fail($backUrl, '该邮箱已被其他账号使用。', $old);
        }

        $bannedUntil = null;
        if ($banType === 'temp') {
            $timestamp = $banUntilRaw !== '' ? strtotime($banUntilRaw) : false;
            if ($timestamp === false || $timestamp <= time()) {
                $this->fail($backUrl, '限时封禁需填写晚于当前时间的到期时间。', $old);
            }
            $bannedUntil = date('Y-m-d H:i:s', $timestamp);
        }

        $isSelf    = $userId === (int) (Auth::id() ?? 0);
        $isLastAdmin = self::isAdminUser($target) && User::countAdmins() <= 1;
        $canAssign = Auth::can('rbac.manage');

        // 目标为管理员时，仅超级管理员可编辑（编辑自己除外），防止横向越权
        if (!$isSelf) {
            $this->guardManageTarget($target, $backUrl, $old);
        }

        // 角色分配：无 rbac.manage 权限 / 编辑自己 / 编辑最后一名管理员时锁定既有角色，
        // 避免越权提权或把系统锁死。
        $lockRoles = !$canAssign || $isSelf || $isLastAdmin;

        if ($lockRoles) {
            $roleIds = Role::userIdsRoleIds($userId);
        } else {
            $roleIds = Role::existingIds((array) Request::input('roles', []));

            // 仅超级管理员可授予 super_admin 角色，防止越权提权
            if ($roleIds !== [] && !Auth::isSuperAdmin()) {
                $superAdminRoleId = 0;
                foreach (Role::options() as $option) {
                    if ((string) $option['code'] === 'super_admin') {
                        $superAdminRoleId = (int) $option['id'];
                        break;
                    }
                }

                if ($superAdminRoleId > 0) {
                    $roleIds = array_values(array_filter(
                        $roleIds,
                        static fn (int $roleId): bool => $roleId !== $superAdminRoleId
                    ));
                }
            }
        }

        // 主角色决定 users.role 与 is_admin：超级管理员 / 管理员视为后台管理员。
        // 未选择任何角色时回退到「管理员标记」下拉，兼容 P0 时期的旧账号；
        // 该回退属于提权操作，仅超级管理员可借此把用户设为管理员，避免越权提权。
        $roleCodes = Role::codesByIds($roleIds);
        if ($roleCodes !== []) {
            $primaryRole = Role::primaryCode($roleCodes);
        } elseif (Auth::isSuperAdmin() && $isAdmin === '1') {
            $primaryRole = 'admin';
        } else {
            $primaryRole = (string) ($target['role'] ?? 'user');
        }
        $isAdminResult = in_array($primaryRole, ['super_admin', 'admin'], true);

        if ($isSelf) {
            if (!$isAdminResult) {
                $this->fail($backUrl, '不能取消自己的管理员权限。', $old);
            }
            if ($status !== '1') {
                $this->fail($backUrl, '不能禁用自己的账号。', $old);
            }
            if ($banType !== 'none') {
                $this->fail($backUrl, '不能封禁自己的账号。', $old);
            }
        }

        $willLoseAccess = !$isAdminResult || $status !== '1' || $banType !== 'none';
        $this->guardLastAdmin($target, $willLoseAccess, $backUrl, $old);

        $updateData = [
            'nickname' => $nickname,
            'email'    => $email,
            'status'   => $status,
            'is_admin' => $isAdminResult ? '1' : '0',
            'role'     => $primaryRole,
        ];

        // 管理员手动改绑邮箱：邮箱变更时视为已验证，清空邮箱时同步清除验证时间
        if (strtolower(trim((string) ($target['email'] ?? ''))) !== $email) {
            $updateData['email_verified'] = $email !== '';
        }

        User::adminUpdate($userId, $updateData);

        if (!$lockRoles) {
            Role::syncUserRoles($userId, $roleIds);
        }

        if ($banType === 'none') {
            User::unban($userId);
        } else {
            User::ban($userId, $banType, $bannedUntil, $banReason !== '' ? $banReason : null);

            // 仅在「未封禁 -> 已封禁」时发信，避免重复保存表单反复打扰用户
            if ((string) ($target['ban_type'] ?? 'none') === 'none') {
                AccountNotifier::notifyBanned($userId, $banType, $bannedUntil, $banReason !== '' ? $banReason : null);
            }
        }

        // 封禁状态发生变更时优先归类为封禁 / 解封，否则记为资料修改
        $banAction = (string) ($target['ban_type'] ?? 'none') !== $banType
            ? ($banType === 'none' ? 'user.unban' : 'user.ban')
            : 'user.update';

        Log::recordOperation($banAction, 'user', $userId, [
            'username' => (string) $target['username'],
            'ban_type' => $banType,
            'status'   => $status,
        ]);

        $this->success(url('/admin/users'), '用户信息已保存。');
    }

    /**
     * 启用 / 禁用账号
     */
    public function toggleStatus(string $id): void
    {
        Csrf::check();

        $userId = (int) $id;
        $target = User::findById($userId);

        if ($target === null) {
            $this->fail(url('/admin/users'), '用户不存在或已被删除。');
        }

        if ($userId === (int) (Auth::id() ?? 0)) {
            $this->fail(url('/admin/users'), '不能切换自己的账号状态。');
        }

        // 目标为管理员时仅超级管理员可操作，防止横向越权禁用其他管理员
        $this->guardManageTarget($target, url('/admin/users'));

        $newStatus = (int) $target['status'] === 1 ? 0 : 1;
        $this->guardLastAdmin($target, $newStatus === 0, url('/admin/users'));

        User::setStatus($userId, $newStatus);

        Log::recordOperation('user.update', 'user', $userId, [
            'username' => (string) $target['username'],
            'status'   => (string) $newStatus,
        ]);

        $this->success(url('/admin/users'), $newStatus === 1 ? '账号已启用。' : '账号已禁用。');
    }

    /**
     * 设置 / 取消管理员权限
     */
    public function toggleAdmin(string $id): void
    {
        Csrf::check();

        $userId = (int) $id;
        $target = User::findById($userId);

        if ($target === null) {
            $this->fail(url('/admin/users'), '用户不存在或已被删除。');
        }

        if ($userId === (int) (Auth::id() ?? 0)) {
            $this->fail(url('/admin/users'), '不能修改自己的管理员权限。');
        }

        // 授予 / 取消管理员权限均属提权操作：仅超级管理员可执行
        if (!Auth::isSuperAdmin()) {
            $this->fail(url('/admin/users'), '仅超级管理员可修改管理员权限。');
        }

        $this->guardManageTarget($target, url('/admin/users'));

        $newIsAdmin = !self::isAdminUser($target);
        $this->guardLastAdmin($target, !$newIsAdmin, url('/admin/users'));

        User::setAdmin($userId, $newIsAdmin);

        Log::recordOperation('user.update', 'user', $userId, [
            'username' => (string) $target['username'],
            'is_admin' => $newIsAdmin ? '1' : '0',
        ]);

        $this->success(url('/admin/users'), $newIsAdmin ? '已设为管理员。' : '已取消管理员权限。');
    }

    /**
     * 重置密码（生成随机密码；优先邮件发送给用户，无法投递时仅在本次提示中回显）
     */
    public function resetPassword(string $id): void
    {
        Csrf::check();

        $userId = (int) $id;
        $target = User::findById($userId);

        if ($target === null) {
            $this->fail(url('/admin/users'), '用户不存在或已被删除。');
        }

        // 目标为管理员时仅超级管理员可重置其密码，防止越权重置超管密码
        $this->guardManageTarget($target, url('/admin/users'));

        $password = self::randomPassword(10);
        User::updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));

        // 密码已变更：撤销该用户其余登录会话，避免旧会话继续有效
        LoginDevice::revokeAll($userId);

        Log::recordOperation('user.reset_password', 'user', $userId, ['username' => (string) $target['username']]);

        // 优先通过邮件下发新密码；仅当无法投递时才在提示中回显，降低明文暴露面
        $delivered = AccountNotifier::notifyPasswordReset($userId, $password);

        $this->success(
            url('/admin/users'),
            $delivered
                ? sprintf('已重置 %s 的密码，新密码已发送到该用户的绑定邮箱。', (string) $target['username'])
                : sprintf(
                    '已重置 %s 的密码。新密码：%s（该用户未绑定有效邮箱，请通过安全渠道告知并提醒尽快修改）。',
                    (string) $target['username'],
                    $password
                )
        );
    }

    /**
     * 软删除用户（保留订单等历史数据）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $userId = (int) $id;
        $target = User::findById($userId);

        if ($target === null) {
            $this->fail(url('/admin/users'), '用户不存在或已被删除。');
        }

        if ($userId === (int) (Auth::id() ?? 0)) {
            $this->fail(url('/admin/users'), '不能删除自己的账号。');
        }

        // 目标为管理员时仅超级管理员可删除，防止横向越权删除其他管理员
        $this->guardManageTarget($target, url('/admin/users'));

        $this->guardLastAdmin($target, true, url('/admin/users'));

        User::softDelete($userId);

        Log::recordOperation('user.delete', 'user', $userId, ['username' => (string) $target['username']]);

        $this->success(url('/admin/users'), '用户已删除（订单等历史数据保留）。');
    }

    /**
     * 批量操作：启用 / 禁用 / 解封 / 删除
     */
    public function batch(): void
    {
        Csrf::check();

        $backUrl = url('/admin/users');
        $action  = Request::string('action');
        $ids     = $this->batchIds();

        $labels = [
            'enable'  => '启用',
            'disable' => '禁用',
            'unban'   => '解封',
            'delete'  => '删除',
        ];

        if (!isset($labels[$action])) {
            $this->fail($backUrl, '未知的批量操作类型。');
        }

        if ($ids === []) {
            $this->fail($backUrl, '请至少选择一个账号。');
        }

        // 目标中包含管理员时仅超级管理员可批量操作，防止横向越权批量操作其他管理员
        $targets     = User::findManyByIds($ids);
        $hasAdmin    = false;
        foreach ($targets as $row) {
            if (self::isAdminUser($row)) {
                $hasAdmin = true;
                break;
            }
        }

        if ($hasAdmin && !Auth::isSuperAdmin()) {
            $this->fail($backUrl, '批量操作包含管理员账号，仅超级管理员可执行。');
        }

        // 禁用 / 删除会使账号失去访问能力：禁止包含自己；结合管理员保护，避免锁死系统
        if (in_array($action, ['disable', 'delete'], true)) {
            if (in_array((int) (Auth::id() ?? 0), $ids, true)) {
                $this->fail($backUrl, '批量操作不能包含自己的账号。');
            }

            if ($hasAdmin && User::countAdmins() <= 1) {
                $this->fail($backUrl, '系统必须保留至少一名管理员，该操作已被拒绝。');
            }
        }

        $affected = match ($action) {
            'enable'  => User::setStatusMany($ids, User::STATUS_ACTIVE),
            'disable' => User::setStatusMany($ids, User::STATUS_DISABLED),
            'unban'   => User::unbanMany($ids),
            'delete'  => User::softDeleteMany($ids),
        };

        Log::recordOperation(
            match ($action) {
                'unban'  => 'user.unban',
                'delete' => 'user.delete',
                default  => 'user.update',
            },
            'user',
            null,
            ['action' => $action, 'ids' => $ids, 'affected' => $affected]
        );

        $this->success($backUrl, sprintf('已批量%s %d 个账号。', $labels[$action], $affected));
    }

    /**
     * 目标用户是否为管理员
     *
     * 与 Permission::isAdminUser 口径保持一致（is_admin 标记 / 主角色 / RBAC 后台角色），
     * 避免仅通过 RBAC 获得后台角色的管理员绕过「仅超级管理员可管理管理员」的保护。
     *
     * @param array<string, mixed> $user
     */
    private static function isAdminUser(array $user): bool
    {
        return Permission::isAdminUser($user);
    }

    /**
     * 校验当前操作者是否有权管理目标用户
     *
     * 仅有 user.manage 权限（如运营 / 客服）的管理员不得对「其他管理员」执行敏感操作，
     * 否则会形成横向越权（重置超管密码、禁用 / 删除 / 降权其他管理员）。
     * 规则：目标为管理员且非当前操作者本人时，仅超级管理员可执行。
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $old
     */
    private function guardManageTarget(array $target, string $backUrl, array $old = []): void
    {
        if (!self::isAdminUser($target) || Auth::isSuperAdmin()) {
            return;
        }

        $this->fail($backUrl, '目标账号为管理员，仅超级管理员可执行该操作。', $old);
    }

    /**
     * 保护最后一个管理员
     *
     * 当目标用户是管理员、且本次操作会使其失去管理能力（降权 / 禁用 / 封禁 / 删除），
     * 而系统中管理员仅剩一人时拒绝操作。
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $old
     */
    private function guardLastAdmin(array $target, bool $willLoseAccess, string $backUrl, array $old = []): void
    {
        if (!$willLoseAccess || !self::isAdminUser($target)) {
            return;
        }

        if (User::countAdmins() <= 1) {
            $this->fail($backUrl, '系统必须保留至少一名管理员，该操作已被拒绝。', $old);
        }
    }

    /**
     * 生成随机密码（剔除易混淆字符）
     */
    private static function randomPassword(int $length = 10): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $max   = strlen($chars) - 1;
        $out   = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }
}
