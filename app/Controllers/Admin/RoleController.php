<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Csrf;
use App\Support\Ids;
use App\Support\Request;

/**
 * 后台角色权限管理
 *
 * 维护 RBAC 角色及其权限集合。
 * 约束：
 * - 系统内置角色（is_system = 1）不可删除；
 * - super_admin 角色始终拥有全部权限，不允许被削减；
 * - 角色仍分配给账号时不可删除，需先调整这些账号的角色。
 */
final class RoleController extends AdminController
{
    /**
     * 角色列表
     */
    public function index(): void
    {
        $this->view('admin.roles.index', [
            'pageTitle' => '角色权限',
            'roles'     => Role::all(),
        ]);
    }

    /**
     * 新增角色页
     */
    public function create(): void
    {
        $this->formData(null);
    }

    /**
     * 保存新角色
     */
    public function store(): void
    {
        Csrf::check();

        $code        = strtolower(trim(Request::string('code')));
        $name        = Request::string('name');
        $description = trim(Request::string('description'));
        $backUrl     = url('/admin/roles/create');

        $validator = $this->validator(array_merge(Request::all(), ['code' => $code]))
            ->required('code', '角色标识')
            ->max('code', 50, '角色标识')
            ->required('name', '角色名称')
            ->max('name', 50, '角色名称')
            ->max('description', 255, '说明');

        if ($code !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1) {
            $validator->addError('code', '角色标识只能由小写字母、数字与下划线组成，且需以字母开头。');
        }

        if ($code !== '' && Role::codeExists($code)) {
            $validator->addError('code', '该角色标识已存在。');
        }

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), [
                'code'        => $code,
                'name'        => $name,
                'description' => $description,
            ]);
        }

        $roleId = Role::create($code, $name, $description !== '' ? $description : null);
        Role::syncPermissions($roleId, $this->submittedPermissionIds());

        Log::recordOperation('role.create', 'role', $roleId, ['code' => $code, 'name' => $name]);

        $this->success(url('/admin/roles'), '角色已创建。');
    }

    /**
     * 编辑角色页
     */
    public function edit(string $id): void
    {
        $role = Role::find((int) $id);

        if ($role === null) {
            $this->fail(url('/admin/roles'), '角色不存在或已被删除。');
        }

        $this->formData($role);
    }

    /**
     * 保存角色修改
     */
    public function update(string $id): void
    {
        Csrf::check();

        $roleId = (int) $id;
        $role   = Role::find($roleId);

        if ($role === null) {
            $this->fail(url('/admin/roles'), '角色不存在或已被删除。');
        }

        $backUrl     = url('/admin/roles/' . $roleId . '/edit');
        $name        = Request::string('name');
        $description = trim(Request::string('description'));

        $validator = $this->validator(Request::all())
            ->required('name', '角色名称')
            ->max('name', 50, '角色名称')
            ->max('description', 255, '说明');

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), [
                'name'        => $name,
                'description' => $description,
            ]);
        }

        Role::update($roleId, $name, $description !== '' ? $description : null);

        // 超级管理员角色恒定拥有全部权限，忽略提交的勾选结果
        $permissionIds = (string) $role['code'] === 'super_admin'
            ? Permission::allIds()
            : $this->submittedPermissionIds();

        Role::syncPermissions($roleId, $permissionIds);

        Log::recordOperation('role.update', 'role', $roleId, [
            'code'          => (string) $role['code'],
            'name'          => $name,
            'permission_num' => count($permissionIds),
        ]);

        $this->success(url('/admin/roles'), '角色权限已保存。');
    }

    /**
     * 删除角色（系统内置角色或仍有账号使用时拒绝）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $roleId = (int) $id;
        $role   = Role::find($roleId);

        if ($role === null) {
            $this->fail(url('/admin/roles'), '角色不存在或已被删除。');
        }

        if ((int) $role['is_system'] === 1) {
            $this->fail(url('/admin/roles'), '系统内置角色不可删除。');
        }

        $usersCount = Role::usersCount($roleId);
        if ($usersCount > 0) {
            $this->fail(
                url('/admin/roles'),
                '该角色仍分配给了 ' . $usersCount . ' 个账号，请先调整这些账号的角色后再删除。'
            );
        }

        Role::softDelete($roleId);

        Log::recordOperation('role.delete', 'role', $roleId, [
            'code' => (string) $role['code'],
            'name' => (string) $role['name'],
        ]);

        $this->success(url('/admin/roles'), '角色已删除。');
    }

    /**
     * 渲染新增 / 编辑表单
     *
     * @param array<string, mixed>|null $role
     */
    private function formData(?array $role): void
    {
        $isEdit          = $role !== null;
        $isSuperAdmin    = $isEdit && (string) $role['code'] === 'super_admin';
        $permissionIds   = $isEdit ? Role::permissionIds((int) $role['id']) : [];

        // 超级管理员角色展示为「全部权限已选中」
        if ($isSuperAdmin) {
            $permissionIds = Permission::allIds();
        }

        $this->view('admin.roles.form', [
            'pageTitle'        => $isEdit ? '编辑角色' : '新增角色',
            'isEdit'           => $isEdit,
            'role'             => $role,
            'groups'           => Permission::grouped(),
            'groupLabels'      => Permission::groupLabels(),
            'selectedIds'      => $permissionIds,
            'isSuperAdminRole' => $isSuperAdmin,
        ]);
    }

    /**
     * 读取并过滤表单提交的权限 ID
     *
     * @return array<int, int>
     */
    private function submittedPermissionIds(): array
    {
        $ids = Ids::normalize((array) Request::input('permissions', []));

        if ($ids === []) {
            return [];
        }

        return array_values(array_intersect($ids, Permission::allIds()));
    }
}
