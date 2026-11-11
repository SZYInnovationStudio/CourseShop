<?php

declare(strict_types=1);

/**
 * 后台系统设置
 *
 * @var array<string, array<string, mixed>> $groups 设置分组定义
 * @var string $active 当前分组键
 * @var array<string, mixed> $values 当前分组各字段的值
 * @var array<string, array<string, string>> $translations 可翻译字段的语言译文（字段键 => 语言码 => 译文）
 */

use App\Support\I18n;

$activeGroup = $groups[$active] ?? null;

// 当前分组内的可翻译文本字段（弹窗面板按此渲染）
$translatableFields = [];
foreach ((array) ($activeGroup['fields'] ?? []) as $field) {
    if (!empty($field['translatable']) && in_array((string) ($field['type'] ?? 'text'), ['text', 'textarea'], true)) {
        $translatableFields[] = $field;
    }
}
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
            <form method="post" action="<?= url('/admin/settings/' . $active) ?>" enctype="multipart/form-data"
                  data-ajax-save
                  data-validate-upload
                  data-max-files="1"
                  data-max-mb="5"
                  data-allowed="jpg,jpeg,png,gif,webp"
                  data-msg-files="<?= e(t('最多上传 %d 个附件。')) ?>"
                  data-msg-size="<?= e(t('单个附件不能超过 %d MB。')) ?>"
                  data-msg-type="<?= e(t('不支持的文件类型：%s')) ?>">
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
                            $isTranslatable = !empty($field['translatable']) && in_array($fieldType, ['text', 'textarea'], true);
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
                            <?php elseif ($fieldType === 'checkboxes'): ?>
                                <?php
                                $pickedRaw    = old($fieldKey, $fieldValue);
                                $pickedRaw    = is_array($pickedRaw) ? implode(',', $pickedRaw) : (string) $pickedRaw;
                                $pickedValues = $pickedRaw === '' ? [] : explode(',', $pickedRaw);
                                ?>
                                <div class="form-group">
                                    <span class="form-label">
                                        <?= e($fieldLabel) ?><?= $required ? '<span class="required">*</span>' : '' ?>
                                    </span>
                                    <div class="checkbox-group">
                                        <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                                            <label class="checkbox">
                                                <input type="checkbox" name="<?= e($fieldKey) ?>[]"
                                                       value="<?= e((string) $optionValue) ?>"
                                                    <?= in_array((string) $optionValue, $pickedValues, true) ? ' checked' : '' ?>>
                                                <span><?= e((string) $optionLabel) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($fieldHint !== ''): ?>
                                        <p class="form-hint"><?= e($fieldHint) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($fieldType === 'palette'): ?>
                                <?php $paletteList = (array) ($field['palettes'] ?? []); ?>
                                <div class="form-group">
                                    <span class="form-label">
                                        <?= e($fieldLabel) ?><?= $required ? '<span class="required">*</span>' : '' ?>
                                    </span>
                                    <div class="palette-picker">
                                        <?php foreach ($paletteList as $paletteKey => $paletteItem): ?>
                                            <?php
                                            $paletteLight = (array) ($paletteItem['light'] ?? []);
                                            $paletteChips = [
                                                $paletteLight['bg'] ?? '#F6F7F5',
                                                $paletteLight['surface'] ?? '#FFFFFF',
                                                $paletteLight['primary'] ?? '#4F6F52',
                                                $paletteLight['accent'] ?? '#8A6434',
                                                $paletteLight['text'] ?? '#22282A',
                                            ];
                                            ?>
                                            <label class="palette-option">
                                                <input type="radio" name="<?= e($fieldKey) ?>"
                                                       value="<?= e((string) $paletteKey) ?>"<?= $fieldValue === (string) $paletteKey ? ' checked' : '' ?>>
                                                <span class="palette-option__preview" aria-hidden="true">
                                                    <?php foreach ($paletteChips as $chipColor): ?>
                                                        <span style="background: <?= e($chipColor) ?>"></span>
                                                    <?php endforeach; ?>
                                                </span>
                                                <span class="palette-option__text">
                                                    <span class="palette-option__name"><?= e((string) ($paletteItem['label'] ?? $paletteKey)) ?></span>
                                                    <span class="palette-option__desc"><?= e((string) ($paletteItem['desc'] ?? '')) ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($fieldHint !== ''): ?>
                                        <p class="form-hint"><?= e($fieldHint) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <?php if ($isTranslatable): ?>
                                        <div class="form-label-row">
                                            <label class="form-label" for="<?= e($fieldId) ?>">
                                                <?= e($fieldLabel) ?><?= $required ? '<span class="required">*</span>' : '' ?>
                                            </label>
                                            <button type="button" class="btn btn--outline btn--sm i18n-trigger"
                                                    data-i18n-field="<?= e($fieldKey) ?>"
                                                    data-i18n-label="<?= e($fieldLabel) ?>"
                                                    aria-haspopup="dialog">
                                                多语言
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <label class="form-label" for="<?= e($fieldId) ?>">
                                            <?= e($fieldLabel) ?><?= $required ? '<span class="required">*</span>' : '' ?>
                                        </label>
                                    <?php endif; ?>

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

                <p class="form-hint upload-status" data-upload-status role="alert" hidden></p>

                <div class="btn-group mt-4">
                    <button class="btn" type="submit">保存设置</button>
                </div>

                <?php if ($translatableFields !== []): ?>
                    <div class="i18n-modal" data-i18n-modal hidden>
                        <div class="i18n-modal__backdrop" data-i18n-close></div>
                        <div class="i18n-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="i18n-modal-title">
                            <div class="i18n-modal__head">
                                <h3 class="i18n-modal__title" id="i18n-modal-title" data-i18n-title>多语言内容</h3>
                                <button type="button" class="i18n-modal__close" data-i18n-close aria-label="关闭">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                        <path d="M6 6l12 12M18 6L6 18"></path>
                                    </svg>
                                </button>
                            </div>
                            <div class="i18n-modal__body" data-i18n-body>
                                <?php foreach ($translatableFields as $field): ?>
                                    <?php
                                    $panelKey    = (string) $field['key'];
                                    $panelType   = (string) ($field['type'] ?? 'text');
                                    $panelMax    = isset($field['max']) ? (int) $field['max'] : null;
                                    $panelSource = (string) old($panelKey, (string) ($values[$panelKey] ?? ''));
                                    ?>
                                    <div class="i18n-panel" id="i18n-<?= e($panelKey) ?>" data-i18n-panel hidden>
                                        <p class="i18n-panel__source">
                                            默认（简体中文）：<?= e(mb_substr($panelSource, 0, 120)) ?><?= mb_strlen($panelSource) > 120 ? '…' : '' ?>
                                        </p>
                                        <?php foreach (I18n::codes() as $locale): ?>
                                            <?php if ($locale === I18n::DEFAULT_LOCALE) {
                                                continue;
                                            } ?>
                                            <?php
                                            $panelId   = 'i18n-' . $panelKey . '-' . $locale;
                                            $panelName = $panelKey . '@' . $locale;
                                            $panelVal  = (string) old($panelName, (string) ($translations[$panelKey][$locale] ?? ''));
                                            $maxLength = $panelMax !== null ? ' maxlength="' . $panelMax . '"' : '';
                                            ?>
                                            <div class="form-group">
                                                <label class="form-label" for="<?= e($panelId) ?>">
                                                    <?= e((string) (I18n::supported()[$locale]['label'] ?? $locale)) ?>
                                                    <span class="form-hint form-hint--inline"><?= e($locale) ?></span>
                                                </label>
                                                <?php if ($panelType === 'textarea'): ?>
                                                    <textarea class="textarea" id="<?= e($panelId) ?>"
                                                              name="<?= e($panelName) ?>" rows="3"<?= $maxLength ?>><?= e($panelVal) ?></textarea>
                                                <?php else: ?>
                                                    <input class="input" type="text" id="<?= e($panelId) ?>"
                                                           name="<?= e($panelName) ?>" value="<?= e($panelVal) ?>"<?= $maxLength ?>>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <p class="form-hint">留空表示该语言使用默认语言（简体中文）的内容。</p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="i18n-modal__foot">
                                <p class="form-hint form-hint--inline">译文随「保存设置」一起提交，留空则回退默认语言。</p>
                                <button type="button" class="btn btn--sm" data-i18n-close>完成</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
