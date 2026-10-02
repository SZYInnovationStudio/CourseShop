<?php

declare(strict_types=1);

/**
 * 后台角色权限 - 列表
 *
 * @var array<int, array<string, mixed>> $roles 角色列表
 */
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">角色权限</h2>
        <p class="admin-page-head__desc">
            共 <?= count($roles) ?> 个角色。系统内置角色不可删除，超级管理员始终拥有全部权限。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--sm" href="<?= url('/admin/roles/create') ?>">新增角色</a>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>角色标识</th>
                <th>角色名称</th>
                <th>说明</th>
                <th>权限数</th>
                <th>账号数</th>
                <th>类型</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <?php
                $roleId   = (int) $role['id'];
                $isSystem = (int) $role['is_system'] === 1;
                ?>
                <tr>
                    <td class="text-faint"><?= $roleId ?></td>
                    <td><code><?= e((string) $role['code']) ?></code></td>
                    <td><?= e((string) $role['name']) ?></td>
                    <td class="text-faint"><?= e((string) ($role['description'] ?? '')) ?></td>
                    <td class="text-faint"><?= (int) $role['permissions_count'] ?> / <?= (int) $role['permissions_total'] ?></td>
                    <td class="text-faint"><?= (int) $role['users_count'] ?></td>
                    <td>
                        <?php if ($isSystem): ?>
                            <span class="badge badge--primary">内置</span>
                        <?php else: ?>
                            <span class="badge badge--info">自定义</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="admin-actions">
                            <a class="btn btn--outline btn--sm"
                               href="<?= url('/admin/roles/' . $roleId . '/edit') ?>">编辑</a>
                            <?php if (!$isSystem): ?>
                                <form method="post" action="<?= url('/admin/roles/' . $roleId . '/delete') ?>"
                                      data-confirm="确定删除该角色吗？">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--danger btn--sm" type="submit">删除</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
