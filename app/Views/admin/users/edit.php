<?php

declare(strict_types=1);

/**
 * 后台用户管理 - 编辑
 *
 * @var array<string, mixed> $user 正在编辑的用户记录
 * @var bool $isAdmin 目标用户是否为管理员
 * @var bool $isLastAdmin 目标用户是否为最后一名管理员
 * @var bool $isSelf 是否在编辑自己
 * @var array<int, array<string, mixed>>|null $roles 可分配的角色列表
 * @var array<int, int>|null $userRoleIds 目标用户当前拥有的角色 ID
 * @var bool|null $canAssignRoles 当前管理员是否具备分配角色的权限
 */

$userId   = (int) $user['id'];
$lockAccess = $isSelf || $isLastAdmin;

$username = (string) $user['username'];
$nickname = (string) old('nickname', (string) ($user['nickname'] ?? ''));
$email    = (string) old('email', (string) ($user['email'] ?? ''));

$status  = (string) old('status', (int) $user['status'] === 1 ? '1' : '0');
$isAdminValue = (string) old('is_admin', $isAdmin ? '1' : '0');
$banType = (string) old('ban_type', (string) ($user['ban_type'] ?? 'none'));
$banReason = (string) old('ban_reason', (string) ($user['ban_reason'] ?? ''));

$bannedUntilRaw   = (string) ($user['banned_until'] ?? '');
$bannedUntilValue = (string) old('banned_until', $bannedUntilRaw !== '' ? date('Y-m-d\TH:i', (int) strtotime($bannedUntilRaw)) : '');

$banTypeLabels = [
    'none'      => '不封禁',
    'temp'      => '限时封禁',
    'permanent' => '永久封禁',
];

$roleOptions    = $roles ?? [];
$currentRoleIds = array_map('intval', $userRoleIds ?? []);
$canAssignRoles = (bool) ($canAssignRoles ?? false);

// 与控制器保持一致：无权限 / 本人 / 最后一名管理员时锁定角色选择
$lockRoles       = !$canAssignRoles || $isSelf || $isLastAdmin;
$oldRoles        = old('roles');
$selectedRoleIds = is_array($oldRoles) ? array_map('intval', $oldRoles) : $currentRoleIds;
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">编辑用户</h2>
        <p class="admin-page-head__desc">
            账号 <strong><?= e($username) ?></strong>（ID：<?= $userId ?>），注册于 <?= e((string) $user['created_at']) ?>。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/users') ?>">返回列表</a>
    </div>
</div>

<?php if ($isSelf): ?>
    <div class="alert alert--info">这是你自己的账号，为保证后台可用，不能取消自己的管理员权限、禁用或封禁自己。</div>
<?php elseif ($isLastAdmin): ?>
    <div class="alert alert--warning">该账号是系统中最后一名管理员，不能取消其管理员权限、禁用或封禁。</div>
<?php endif; ?>

<form method="post" action="<?= url('/admin/users/' . $userId) ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">基本信息</div>
        <div class="card__body">
            <div class="form-group">
                <label class="form-label">用户名</label>
                <input class="input" type="text" value="<?= e($username) ?>" disabled>
                <p class="form-hint">用户名是登录凭据，创建后不可修改。</p>
            </div>

            <div class="form-group">
                <label class="form-label" for="nickname">昵称<span class="required">*</span></label>
                <input class="input" id="nickname" type="text" name="nickname" maxlength="50"
                       value="<?= e($nickname) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">邮箱</label>
                <input class="input" id="email" type="email" name="email" maxlength="190"
                       value="<?= e($email) ?>" placeholder="用于邮箱验证与通知，可留空">
            </div>

            <div class="form-group">
                <label class="form-label" for="status">账号状态</label>
                <?php if ($lockAccess): ?>
                    <input type="hidden" name="status" value="1">
                    <select class="select" id="status" disabled>
                        <option value="1" selected>正常</option>
                    </select>
                <?php else: ?>
                    <select class="select" id="status" name="status">
                        <option value="1"<?= $status === '1' ? ' selected' : '' ?>>正常</option>
                        <option value="0"<?= $status === '0' ? ' selected' : '' ?>>已禁用</option>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="is_admin">管理员权限</label>
                <?php if ($lockAccess): ?>
                    <input type="hidden" name="is_admin" value="1">
                    <select class="select" id="is_admin" disabled>
                        <option value="1" selected>是（管理员）</option>
                    </select>
                <?php else: ?>
                    <select class="select" id="is_admin" name="is_admin">
                        <option value="0"<?= $isAdminValue === '0' ? ' selected' : '' ?>>否（普通用户）</option>
                        <option value="1"<?= $isAdminValue === '1' ? ' selected' : '' ?>>是（管理员）</option>
                    </select>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__header">角色分配</div>
        <div class="card__body">
            <?php if (!$canAssignRoles): ?>
                <p class="form-hint mb-0">你没有「角色权限」的管理权限，无法调整该账号的角色。</p>
            <?php elseif ($roleOptions === []): ?>
                <p class="text-muted mb-0">暂无可用角色。</p>
            <?php else: ?>
                <?php if ($lockRoles): ?>
                    <p class="form-hint">为保证后台可用，不能调整自己或最后一名管理员的角色。</p>
                <?php endif; ?>
                <div class="form-grid">
                    <?php foreach ($roleOptions as $roleOption): ?>
                        <?php
                        $roleId  = (int) $roleOption['id'];
                        $checked = in_array($roleId, $selectedRoleIds, true);
                        ?>
                        <label class="checkbox">
                            <input type="checkbox" name="roles[]" value="<?= $roleId ?>"
                                   <?= $checked ? 'checked' : '' ?>
                                   <?= $lockRoles ? 'disabled' : '' ?>>
                            <span><?= e((string) $roleOption['name']) ?>
                                <span class="text-faint">（<?= e((string) $roleOption['code']) ?>）</span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint">
                    勾选角色后，账号的后台访问权限由所选角色决定；未勾选任何角色时，沿用上方「管理员权限」下拉的设置。
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header">封禁设置</div>
        <div class="card__body">
            <?php if ($lockAccess): ?>
                <input type="hidden" name="ban_type" value="none">
                <p class="text-muted mb-0">当前不可对该账号执行封禁。</p>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label" for="ban_type">封禁类型</label>
                    <select class="select" id="ban_type" name="ban_type">
                        <?php foreach ($banTypeLabels as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $banType === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="banned_until">解封时间</label>
                    <input class="input" id="banned_until" type="datetime-local" name="banned_until"
                           value="<?= e($bannedUntilValue) ?>">
                    <p class="form-hint">仅在「限时封禁」时生效，需填写晚于当前时间的时刻。</p>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" for="ban_reason">封禁原因</label>
                    <input class="input" id="ban_reason" type="text" name="ban_reason" maxlength="255"
                           value="<?= e($banReason) ?>" placeholder="选填，仅管理员可见">
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit">保存修改</button>
        <a class="btn btn--ghost" href="<?= url('/admin/users') ?>">取消</a>
    </div>
</form>
