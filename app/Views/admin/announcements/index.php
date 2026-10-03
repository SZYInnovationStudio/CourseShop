<?php

declare(strict_types=1);

/**
 * 后台公告管理 - 列表
 *
 * @var array<int, array<string, mixed>> $announcements 公告列表
 */
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">公告管理</h2>
        <p class="admin-page-head__desc">
            共 <?= count($announcements) ?> 条公告。仅「已启用」的公告会展示在前台首页，无启用公告时首页不显示公告区块。
        </p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--sm" href="<?= url('/admin/announcements/create') ?>">新增公告</a>
    </div>
</div>

<?php if ($announcements === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">暂无公告，点击右上角「新增公告」创建。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>序号</th>
                    <th>标题</th>
                    <th>状态</th>
                    <th>发布时间</th>
                    <th>更新时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($announcements as $index => $item): ?>
                    <?php $itemId = (int) $item['id']; ?>
                    <tr>
                        <td class="text-faint"><?= $index + 1 ?></td>
                        <td><?= e((string) $item['title']) ?></td>
                        <td>
                            <?php if ((int) $item['is_active'] === 1): ?>
                                <span class="badge badge--success">已启用</span>
                            <?php else: ?>
                                <span class="badge">已停用</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-faint"><?= e((string) ($item['published_at'] ?? '')) ?></td>
                        <td class="text-faint"><?= e((string) $item['updated_at']) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm"
                                   href="<?= url('/admin/announcements/' . $itemId . '/edit') ?>">编辑</a>
                                <form method="post" action="<?= url('/admin/announcements/' . $itemId . '/delete') ?>"
                                      data-confirm="确定删除该公告吗？">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--danger btn--sm" type="submit">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
