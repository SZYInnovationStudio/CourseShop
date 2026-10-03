<?php

declare(strict_types=1);

/**
 * 账户设置
 *
 * @var array<string, mixed> $profile 当前登录用户
 * @var string $tab 当前面板
 * @var array<int, array<string, mixed>> $devices 活跃登录设备列表（仅「登录安全」面板）
 * @var string $currentToken 当前会话的设备令牌（仅「登录安全」面板）
 * @var bool $twoFactorEnabled 是否已启用两步验证（仅「登录安全」面板）
 */

use App\Support\Setting;

$email         = (string) ($profile['email'] ?? '');
$emailVerified = !empty($profile['email_verified_at']);
$isAdmin       = (int) ($profile['is_admin'] ?? 0) === 1;
$needBind      = Setting::bool('force_email_bind', false) && !$emailVerified && !$isAdmin;

$username   = (string) ($profile['username'] ?? '');
$nickname   = (string) ($profile['nickname'] ?? '');
$avatar     = (string) ($profile['avatar'] ?? '');
$darkMode   = (string) ($profile['dark_mode'] ?? 'system');
$displayName = $nickname !== '' ? $nickname : $username;
$initial    = mb_substr($displayName !== '' ? $displayName : '?', 0, 1);

$tabs = [
    'profile'  => t('个人资料'),
    'password' => t('修改密码'),
    'security' => t('登录安全'),
    'theme'    => t('外观主题'),
    'danger'   => t('账号注销'),
];
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e(t('账户设置')) ?></h1>
    <p class="page-head__desc"><?= e(t('管理个人资料、登录密码与外观偏好。')) ?></p>
</div>

<div class="container">
    <?php if ($needBind): ?>
        <div class="alert alert--warning" role="alert">
            <span class="grow"><?= e(t('本站已开启强制邮箱绑定，请先完成邮箱绑定后再使用其他功能。')) ?></span>
            <a class="btn btn--sm" href="<?= url('/account/email') ?>"><?= e(t('立即绑定')) ?></a>
        </div>
    <?php endif; ?>

    <div class="settings-layout">
        <nav class="settings-nav" aria-label="<?= e(t('账户设置导航')) ?>">
            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <a class="settings-nav__link<?= $tab === $tabKey ? ' is-active' : '' ?>"
                   href="<?= url('/account?tab=' . $tabKey) ?>">
                    <?= e($tabLabel) ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$emailVerified): ?>
                <a class="settings-nav__link" href="<?= url('/account/email') ?>">
                    <?= e(t('邮箱绑定（未完成）')) ?>
                </a>
            <?php endif; ?>
        </nav>

        <div class="settings-panel">
            <?php if ($tab === 'password'): ?>

                <form method="post" action="<?= url('/account/password') ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="card">
                        <div class="card__header"><?= e(t('修改密码')) ?></div>
                        <div class="card__body">
                            <p class="form-hint settings-panel__desc">
                                <?= e(t('为了账号安全，修改密码需先验证当前密码。新密码至少 8 位。')) ?>
                            </p>

                            <div class="form-group">
                                <label class="form-label" for="current_password"><?= e(t('当前密码')) ?><span class="required">*</span></label>
                                <div class="input-group">
                                    <input class="input" type="password" id="current_password" name="current_password"
                                           autocomplete="current-password" maxlength="72" required>
                                    <button type="button" class="input-group__suffix" data-password-toggle
                                            aria-label="<?= e(t('显示密码')) ?>"
                                            data-label-show="<?= e(t('显示密码')) ?>" data-label-hide="<?= e(t('隐藏密码')) ?>">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="password"><?= e(t('新密码')) ?><span class="required">*</span></label>
                                <div class="input-group">
                                    <input class="input" type="password" id="password" name="password"
                                           autocomplete="new-password" minlength="8" maxlength="72" required>
                                    <button type="button" class="input-group__suffix" data-password-toggle
                                            aria-label="<?= e(t('显示密码')) ?>"
                                            data-label-show="<?= e(t('显示密码')) ?>" data-label-hide="<?= e(t('隐藏密码')) ?>">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                                <p class="form-hint"><?= e(t('建议使用字母、数字与符号的组合，长度 8-72 位。')) ?></p>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="password_confirmation"><?= e(t('确认新密码')) ?><span class="required">*</span></label>
                                <div class="input-group">
                                    <input class="input" type="password" id="password_confirmation" name="password_confirmation"
                                           autocomplete="new-password" minlength="8" maxlength="72" required>
                                    <button type="button" class="input-group__suffix" data-password-toggle
                                            aria-label="<?= e(t('显示密码')) ?>"
                                            data-label-show="<?= e(t('显示密码')) ?>" data-label-hide="<?= e(t('隐藏密码')) ?>">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="btn-group">
                        <button class="btn" type="submit"><?= e(t('保存新密码')) ?></button>
                    </div>
                </form>

            <?php elseif ($tab === 'security'): ?>

                <div class="card">
                    <div class="card__header"><?= e(t('两步验证（2FA）')) ?></div>
                    <div class="card__body">
                        <p class="form-hint settings-panel__desc">
                            <?= e(t('开启后，登录时除密码外还需输入身份验证器中的 6 位动态口令，可显著降低密码泄露带来的风险。')) ?>
                        </p>

                        <?php if ($twoFactorEnabled): ?>
                            <div class="alert alert--success" role="alert">
                                <span class="grow"><?= e(t('两步验证已开启，你的账号得到了额外保护。')) ?></span>
                                <span class="badge badge--success"><?= e(t('已启用')) ?></span>
                            </div>

                            <form method="post" action="<?= url('/account/2fa/disable') ?>"
                                  data-confirm="<?= e(t('确定要关闭两步验证吗？关闭后账号安全性将降低。')) ?>">
                                <?= csrf_field() ?>
                                <div class="form-group">
                                    <label class="form-label" for="disable_2fa_password"><?= e(t('输入当前密码以确认')) ?><span class="required">*</span></label>
                                    <input class="input" type="password" id="disable_2fa_password" name="password"
                                           autocomplete="current-password" maxlength="72" required>
                                </div>
                                <button class="btn btn--danger" type="submit"><?= e(t('关闭两步验证')) ?></button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert--warning" role="alert">
                                <span class="grow"><?= e(t('两步验证尚未开启，建议开启以保护账号安全。')) ?></span>
                                <span class="badge badge--warning"><?= e(t('未开启')) ?></span>
                            </div>
                            <a class="btn" href="<?= url('/account/2fa/setup') ?>"><?= e(t('启用两步验证')) ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card__header"><?= e(t('登录设备')) ?></div>
                    <div class="card__body">
                        <p class="form-hint settings-panel__desc">
                            <?= e(t('以下是当前账号的活跃登录设备，按最近活跃时间排序。如发现陌生设备，可将其踢下线。')) ?>
                        </p>

                        <?php if ($devices === []): ?>
                            <p class="text-muted"><?= e(t('暂无登录设备记录。')) ?></p>
                        <?php else: ?>
                            <ul class="list-plain">
                                <?php foreach ($devices as $device): ?>
                                    <?php $isCurrent = $currentToken !== '' && (string) ($device['session_token'] ?? '') === $currentToken; ?>
                                    <li class="flex-between">
                                        <span>
                                            <span><?= e(device_label($device['ua'] ?? '')) ?></span>
                                            <?php if ($isCurrent): ?>
                                                <span class="badge badge--primary"><?= e(t('当前设备')) ?></span>
                                            <?php endif; ?>
                                            <br>
                                            <span class="text-faint">
                                                IP <?= e((string) ($device['ip'] ?? t('未知'))) ?> ·
                                                <?= e(t('最近活跃')) ?> <?= e((string) ($device['last_active_at'] ?? '—')) ?>
                                            </span>
                                        </span>
                                        <?php if (!$isCurrent): ?>
                                            <form method="post"
                                                  action="<?= url('/account/devices/' . (int) $device['id'] . '/revoke') ?>"
                                                  data-confirm="<?= e(t('确定要让该设备退出登录吗？')) ?>">
                                                <?= csrf_field() ?>
                                                <button class="btn btn--sm btn--outline" type="submit"><?= e(t('退出该设备')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <div class="card__footer">
                        <form method="post" action="<?= url('/account/devices/revoke-others') ?>"
                              data-confirm="<?= e(t('确定要退出除当前设备外的所有设备吗？')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--outline" type="submit"><?= e(t('退出其他所有设备')) ?></button>
                        </form>
                    </div>
                </div>

            <?php elseif ($tab === 'theme'): ?>

                <form method="post" action="<?= url('/account/theme') ?>">
                    <?= csrf_field() ?>
                    <div class="card">
                        <div class="card__header"><?= e(t('外观主题')) ?></div>
                        <div class="card__body">
                            <p class="form-hint settings-panel__desc">
                                <?= e(t('选择你偏好的界面主题，保存后将在所有设备生效。')) ?>
                            </p>

                            <?php
                            $themeOptions = [
                                'light'  => [t('亮色'), t('始终使用亮色主题')],
                                'dark'   => [t('暗色'), t('始终使用暗色主题')],
                                'system' => [t('跟随系统'), t('根据系统设置自动切换')],
                            ];
                            foreach ($themeOptions as $modeValue => $modeInfo):
                                ?>
                                <div class="form-group">
                                    <label class="checkbox">
                                        <input type="radio" name="mode" value="<?= e($modeValue) ?>"
                                            <?= $darkMode === $modeValue ? ' checked' : '' ?>>
                                        <span>
                                            <?= e($modeInfo[0]) ?>
                                            <span class="form-hint form-hint--inline"><?= e($modeInfo[1]) ?></span>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="btn-group">
                        <button class="btn" type="submit"><?= e(t('保存主题偏好')) ?></button>
                    </div>
                </form>

            <?php elseif ($tab === 'danger'): ?>

                <div class="card danger-zone">
                    <div class="card__header"><?= e(t('账号注销')) ?></div>
                    <div class="card__body">
                        <div class="alert alert--error" role="alert">
                            <span class="grow">
                                <?= e(t('注销后账号将无法登录，个人资料会被匿名化处理；已购课程与订单记录会保留以便查询。')) ?>
                                <?= e(t('此操作不可撤销，请谨慎操作。')) ?>
                            </span>
                        </div>

                        <form method="post" action="<?= url('/account/delete') ?>"
                              data-confirm="<?= e(t('确定要注销当前账号吗？此操作不可撤销。')) ?>">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label class="form-label" for="delete_password"><?= e(t('输入密码以确认')) ?><span class="required">*</span></label>
                                <input class="input" type="password" id="delete_password" name="password"
                                       autocomplete="current-password" maxlength="72" required>
                            </div>
                            <button class="btn btn--danger" type="submit"><?= e(t('永久注销账号')) ?></button>
                        </form>
                    </div>
                </div>

            <?php else: ?>

                <form method="post" action="<?= url('/account') ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <div class="card">
                        <div class="card__header"><?= e(t('个人资料')) ?></div>
                        <div class="card__body">
                            <div class="avatar-form">
                                <div class="avatar avatar--lg">
                                    <?php if ($avatar !== ''): ?>
                                        <img src="<?= e(asset($avatar)) ?>" alt="<?= e(t('当前头像')) ?>">
                                    <?php else: ?>
                                        <span class="avatar__fallback"><?= e($initial) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="avatar-form__fields">
                                    <div class="form-group">
                                        <label class="form-label" for="avatar"><?= e(t('更换头像')) ?></label>
                                        <input class="input" type="file" id="avatar" name="avatar"
                                               accept="image/jpeg,image/png,image/gif,image/webp">
                                        <p class="form-hint"><?= e(t('支持 jpg、png、gif、webp，大小不超过 2 MB。')) ?></p>
                                    </div>
                                    <?php if ($avatar !== ''): ?>
                                        <label class="checkbox">
                                            <input type="checkbox" name="remove_avatar" value="1">
                                            <span><?= e(t('移除当前头像')) ?></span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="nickname"><?= e(t('昵称')) ?><span class="required">*</span></label>
                                <input class="input" type="text" id="nickname" name="nickname"
                                       value="<?= e(old('nickname', $nickname)) ?>"
                                       maxlength="50" placeholder="<?= e(t('用于站内展示的名称')) ?>" required>
                            </div>
                        </div>
                        <div class="card__footer">
                            <button class="btn" type="submit"><?= e(t('保存资料')) ?></button>
                        </div>
                    </div>
                </form>

                <div class="card card--flat mt-4">
                    <div class="card__header"><?= e(t('账号信息')) ?></div>
                    <div class="card__body">
                        <ul class="list-plain">
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('用户名')) ?></span>
                                <span><?= e($username) ?></span>
                            </li>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('邮箱')) ?></span>
                                <span>
                                    <?php if ($email === ''): ?>
                                        <span class="text-faint"><?= e(t('未绑定')) ?></span>
                                    <?php elseif ($emailVerified): ?>
                                        <?= e(mask_email($email)) ?>
                                        <span class="badge badge--success"><?= e(t('已验证')) ?></span>
                                    <?php else: ?>
                                        <?= e(mask_email($email)) ?>
                                        <span class="badge badge--warning"><?= e(t('未验证')) ?></span>
                                    <?php endif; ?>
                                </span>
                            </li>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('账号角色')) ?></span>
                                <span><?= $isAdmin ? e(t('管理员')) : e(t('普通用户')) ?></span>
                            </li>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('注册时间')) ?></span>
                                <span><?= e((string) ($profile['created_at'] ?? '—')) ?></span>
                            </li>
                            <li class="flex-between">
                                <span class="text-muted"><?= e(t('最近登录')) ?></span>
                                <span><?= e((string) ($profile['last_login_at'] ?? '—')) ?></span>
                            </li>
                        </ul>
                    </div>
                    <div class="card__footer flex flex-wrap gap-3">
                        <?php if (!$emailVerified): ?>
                            <a class="btn btn--outline" href="<?= url('/account/email') ?>"><?= e(t('绑定邮箱')) ?></a>
                        <?php endif; ?>
                        <a class="btn btn--outline" href="<?= url('/my/courses') ?>"><?= e(t('我的课程')) ?></a>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>
