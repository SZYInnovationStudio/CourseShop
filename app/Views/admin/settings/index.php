<?php

declare(strict_types=1);

/**
 * 后台系统设置
 *
 * @var array<string, array<string, mixed>> $groups 设置分组定义
 * @var string $active 当前分组键
 * @var array<string, mixed> $values 当前分组各字段的值
 */

$activeGroup = $groups[$active] ?? null;
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">系统设置</h2>
        <p class="admin-page-head__desc">
            按分组维护站点配置，每个分组独立保存，保存后立即生效。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <form method="post" action="<?= url('/admin/settings/cache/clear') ?>"
              data-confirm="确定要清空全部缓存吗？清空后系统会在下次访问时自动重建。">
            <?= csrf_field() ?>
            <button class="btn btn--outline btn--sm" type="submit">清空缓存</button>
        </form>
    </div>
</div>

<div class="settings-layout">
    <nav class="settings-nav" aria-label="设置分组">
        <?php foreach ($groups as $groupKey => $groupItem): ?>
            <a class="settings-nav__link<?= (string) $groupKey === $active ? ' is-active' : '' ?>"
               href="<?= url('/admin/settings?tab=' . $groupKey) ?>">
                <?= e((string) $groupItem['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="settings-panel">
        <?php if ($activeGroup === null): ?>
            <div class="card card--flat">
                <div class="empty-state"><p class="mb-0">设置分组不存在。</p></div>
            </div>
        <?php else: ?>
            <form method="post" action="<?= url('/admin/settings/' . $active) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <div class="card">
                    <div class="card__header"><?= e((string) $activeGroup['label']) ?></div>
                    <div class="card__body">
                        <?php if ((string) ($activeGroup['desc'] ?? '') !== ''): ?>
                            <p class="form-hint settings-panel__desc"><?= e((string) $activeGroup['desc']) ?></p>
                        <?php endif; ?>

                        <?php foreach ($activeGroup['fields'] as $field): ?>
                            <?php
                            $fieldKey   = (string) $field['key'];
                            $fieldType  = (string) ($field['type'] ?? 'text');
                            $fieldLabel = (string) $field['label'];
                            $fieldHint  = (string) ($field['hint'] ?? '');
                            $fieldId    = 'set-' . $fieldKey;
                            $fieldValue = (string) old($fieldKey, (string) ($values[$fieldKey] ?? ''));
                            $required   = !empty($field['required']);
                            ?>
                            <?php if ($fieldType === 'bool'): ?>
                                <div class="form-group">
                                    <label class="checkbox" for="<?= e($fieldId) ?>">
                                        <input type="checkbox" id="<?= e($fieldId) ?>" name="<?= e($fieldKey) ?>"
                                               value="1"<?= $fieldValue === '1' ? ' checked' : '' ?>>
                                        <span>
                                            <?= e($fieldLabel) ?>
                                            <?php if ($fieldHint !== ''): ?>
                                                <span class="form-hint form-hint--inline"><?= e($fieldHint) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <label class="form-label" for="<?= e($fieldId) ?>">
                                        <?= e($fieldLabel) ?><?= $required ? '<span class="required">*</span>' : '' ?>
                                    </label>

                                    <?php if ($fieldType === 'textarea'): ?>
                                        <textarea class="textarea" id="<?= e($fieldId) ?>" name="<?= e($fieldKey) ?>"
                                                  rows="3"<?= isset($field['max']) ? ' maxlength="' . (int) $field['max'] . '"' : '' ?>><?= e($fieldValue) ?></textarea>
                                    <?php elseif ($fieldType === 'select'): ?>
                                        <select class="select" id="<?= e($fieldId) ?>" name="<?= e($fieldKey) ?>">
                                            <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                                                <option value="<?= e((string) $optionValue) ?>"
                                                    <?= (string) $optionValue === $fieldValue ? ' selected' : '' ?>>
                                                    <?= e((string) $optionLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($fieldType === 'color'): ?>
                                        <?php
                                        // 存储值非法时回退到默认色，避免颜色选择器显示为黑色
                                        $colorValue = preg_match('/^#[0-9a-fA-F]{6}$/', $fieldValue) === 1
                                            ? $fieldValue
                                            : (string) ($field['default'] ?? '#4F6F52');
                                        ?>
                                        <div class="settings-color">
                                            <input class="input settings-color__picker" type="color"
                                                   id="<?= e($fieldId) ?>" name="<?= e($fieldKey) ?>"
                                                   value="<?= e($colorValue) ?>" data-color-preview>
                                            <span class="settings-color__value" data-color-value><?= e($colorValue) ?></span>
                                        </div>
                                    <?php elseif ($fieldType === 'password'): ?>
                                        <div class="input-group">
                                            <input class="input" type="password" id="<?= e($fieldId) ?>"
                                                   name="<?= e($fieldKey) ?>" value="" autocomplete="new-password"
                                                   placeholder="留空表示不修改"<?= isset($field['max']) ? ' maxlength="' . (int) $field['max'] . '"' : '' ?>>
                                            <button type="button" class="input-group__suffix" data-password-toggle aria-label="显示密码">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                </svg>
                                            </button>
                                        </div>
                                    <?php elseif ($fieldType === 'image'): ?>
                                        <div class="image-field">
                                            <input class="input" type="text" id="<?= e($fieldId) ?>"
                                                   name="<?= e($fieldKey) ?>" value="<?= e($fieldValue) ?>"
                                                   placeholder="图片地址（https://... 或 /uploads/...）"<?= isset($field['max']) ? ' maxlength="' . (int) $field['max'] . '"' : '' ?>>
                                            <input class="input image-field__file" type="file"
                                                   name="<?= e($fieldKey) ?>_file"
                                                   accept="image/jpeg,image/png,image/gif,image/webp">
                                            <?php if ($fieldValue !== ''): ?>
                                                <div class="image-field__preview">
                                                    <img src="<?= e(url($fieldValue)) ?>" alt="<?= e($fieldLabel) ?>预览">
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php
                                        $inputType = match ($fieldType) {
                                            'number' => 'number',
                                            'email'  => 'email',
                                            default  => 'text',
                                        };
                                        ?>
                                        <input class="input" type="<?= $inputType ?>" id="<?= e($fieldId) ?>"
                                               name="<?= e($fieldKey) ?>" value="<?= e($fieldValue) ?>"
                                               <?php if ($fieldType === 'number'): ?>min="<?= (int) ($field['min'] ?? PHP_INT_MIN) ?>"
                                               max="<?= (int) ($field['max_value'] ?? PHP_INT_MAX) ?>" step="1"
                                               <?php elseif (isset($field['max'])): ?>maxlength="<?= (int) $field['max'] ?>"<?php endif; ?>>
                                    <?php endif; ?>

                                    <?php if ($fieldHint !== ''): ?>
                                        <p class="form-hint"><?= e($fieldHint) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="btn-group">
                    <button class="btn" type="submit">保存设置</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
