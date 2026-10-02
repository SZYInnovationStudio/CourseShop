<?php

declare(strict_types=1);

/**
 * 后台用户管理 - 新增用户
 *
 * 仅渲染表单；提交后由 UserController::store 校验并创建账号。
 */
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">新增用户</h2>
        <p class="admin-page-head__desc">由管理员直接创建账号，可指定昵称、邮箱与管理员权限。</p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--outline btn--sm" href="<?= url('/admin/users') ?>">返回列表</a>
    </div>
</div>

<form method="post" action="<?= url('/admin/users') ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__header">账号信息</div>
        <div class="card__body">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="username">用户名<span class="required">*</span></label>
                    <input class="input" id="username" type="text" name="username" maxlength="20"
                           value="<?= e((string) old('username')) ?>" placeholder="3-20 位字母、数字或下划线" required>
                    <p class="form-hint">用户名是登录凭据，创建后不可修改。</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="nickname">昵称</label>
                    <input class="input" id="nickname" type="text" name="nickname" maxlength="50"
                           value="<?= e((string) old('nickname')) ?>" placeholder="留空则与用户名一致">
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">邮箱</label>
                    <input class="input" id="email" type="email" name="email" maxlength="190"
                           value="<?= e((string) old('email')) ?>" placeholder="可留空，填写后视为已验证">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">初始密码<span class="required">*</span></label>
                    <input class="input" id="password" type="password" name="password" minlength="8" maxlength="64"
                           autocomplete="new-password" placeholder="至少 8 位" required>
                    <p class="form-hint">请提醒用户在首次登录后及时修改密码。</p>
                </div>

                <div class="form-group form-group--full">
                    <label class="form-label" for="is_admin">管理员权限</label>
                    <?php $isAdminValue = (string) old('is_admin', '0'); ?>
                    <select class="select" id="is_admin" name="is_admin">
                        <option value="0"<?= $isAdminValue === '0' ? ' selected' : '' ?>>否（普通用户）</option>
                        <option value="1"<?= $isAdminValue === '1' ? ' selected' : '' ?>>是（管理员）</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="btn-group">
        <button class="btn" type="submit">创建账号</button>
        <a class="btn btn--ghost" href="<?= url('/admin/users') ?>">取消</a>
    </div>
</form>
