<?php

declare(strict_types=1);

/**
 * 后台角色权限 - 新增 / 编辑表单
 *
 * @var bool $isEdit 是否为编辑模式
 * @var array<string, mixed>|null $role 编辑时的角色记录，新增时为 null
 * @var array<string, array<int, array<string, mixed>>> $groups 按分组归类的权限
 * @var array<string, string> $groupLabels 分组标题
 * @var array<int, int> $selectedIds 已选中的权限 ID
 * @var bool $isSuperAdminRole 是否为超级管理员角色
 */

$roleId      = $isEdit ? (int) $role['id'] : 0;
$code        = $isEdit ? (string) $role['code'] : (string) old('code');
$name        = (string) old('name', $isEdit ? (string) $role['name'] : '');
$description = (string) old('description', $isEdit ? (string) ($role['description'] ?? '') : '');

$oldPermissions = old('permissions');
$selectedIds    = is_array($oldPermissions)
    ? array_map('intval', $oldPermissions)
    : $selectedIds;

$action = $isEdit ? url('/admin/roles/' . $roleId) : url('/admin/roles');
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title"><?= $isEdit ? '编辑角色' : '新增角色' ?></h2>
        <p class="admin-page-head__desc">
            <?= $isEdit ? '调整该角色的名称、说明与权限集合。' : '创建自定义角色，并为其勾选权限。' ?>
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/roles') ?>">返回列表</a>
    </div>
</div>

<?php if ($isSuperAdminRole): ?>
    <div class="alert alert--info">超级管理员角色始终拥有全部权限，权限项不可修改。</div>
<?php endif; ?>

<form method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">基本信息</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="code">角色标识<span class="required">*</span></label>
                    <?php if ($isEdit): ?>
                        <input class="input" id="code" type="text" value="<?= e($code) ?>" disabled>
                        <p class="form-hint">角色标识创建后不可修改。</p>
                    <?php else: ?>
                        <input class="input" id="code" type="text" name="code" maxlength="50"
                               value="<?= e($code) ?>" placeholder="例如：editor" required>
                        <p class="form-hint">仅小写字母、数字与下划线，且以字母开头，用于程序判定。</p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="name">角色名称<span class="required">*</span></label>
                    <input class="input" id="name" type="text" name="name" maxlength="50"
                           value="<?= e($name) ?>" required>
                </div>

                <div class="form-group form-group--full">
                    <label class="form-label" for="description">说明</label>
                    <input class="input" id="description" type="text" name="description" maxlength="255"
                           value="<?= e($description) ?>" placeholder="选填，简要说明该角色的职责">
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">权限配置</div>
        <div class="card__body">
            <?php if ($groups === []): ?>
                <p class="text-muted mb-0">暂无可分配的权限。</p>
            <?php else: ?>
                <?php foreach ($groups as $groupKey => $permissions): ?>
                    <div class="form-group">
                        <label class="form-label"><?= e($groupLabels[$groupKey] ?? (string) $groupKey) ?></label>
                        <div class="form-grid">
                            <?php foreach ($permissions as $permission): ?>
                                <?php
                                $permissionId = (int) $permission['id'];
                                $checked      = in_array($permissionId, $selectedIds, true);
                                ?>
                                <label class="checkbox">
                                    <input type="checkbox" name="permissions[]"
                                           value="<?= $permissionId ?>"
                                           <?= $checked ? 'checked' : '' ?>
                                           <?= $isSuperAdminRole ? 'disabled' : '' ?>>
                                    <span><?= e((string) $permission['name']) ?>
                                        <span class="text-faint">（<?= e((string) $permission['code']) ?>）</span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit"><?= $isEdit ? '保存修改' : '创建角色' ?></button>
        <a class="btn btn--ghost" href="<?= url('/admin/roles') ?>">取消</a>
    </div>
</form>
