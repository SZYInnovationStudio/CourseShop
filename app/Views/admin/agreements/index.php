<?php

declare(strict_types=1);

/**
 * 后台协议管理 - 总览
 *
 * @var array<int, array<string, mixed>> $items 各协议概览项（type / label / current）
 */
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">协议管理</h2>
        <p class="admin-page-head__desc">
            维护用户协议、隐私政策与退款政策。保存后立即生效，原版本自动存档为历史版本。
        </p>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>协议类型</th>
                <th>当前版本</th>
                <th>生效日期</th>
                <th>最近更新</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php $current = $item['current']; ?>
                <tr>
                    <td><?= e((string) $item['label']) ?></td>
                    <td>
                        <?php if ($current === null): ?>
                            <span class="badge">未配置</span>
                        <?php else: ?>
                            v<?= e((string) $current['version']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-faint">
                        <?= $current !== null ? e((string) ($current['effective_at'] ?? '—')) : '—' ?>
                    </td>
                    <td class="text-faint">
                        <?= $current !== null ? e((string) ($current['updated_at'] ?? '—')) : '—' ?>
                    </td>
                    <td>
                        <div class="admin-actions">
                            <a class="btn btn--outline btn--sm"
                               href="<?= url('/admin/agreements/' . $item['type'] . '/edit') ?>">编辑</a>
                            <a class="btn btn--ghost btn--sm" href="<?= url('/agreements/' . $item['type']) ?>"
                               target="_blank" rel="noopener">前台查看</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
