<?php

declare(strict_types=1);

/**
 * 后台课程管理 - 列表
 *
 * @var array<string, mixed> $filters 筛选条件
 * @var array<int, array<string, mixed>> $courses 课程列表
 * @var int $total 课程总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var array<int, array<string, mixed>> $categories 课程分类列表
 * @var array<int, array<string, mixed>> $tags 标签列表
 * @var array<string, string> $statuses 课程状态选项
 */

$pageUrl = static function (int $target): string {
    $query         = $_GET;
    $query['page'] = $target;

    return url('/admin/courses') . '?' . http_build_query($query);
};

/** 状态 => 徽标样式 */
$statusBadge = [
    'published' => 'badge--success',
    'draft'     => 'badge',
    'offline'   => 'badge--warning',
];

$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $windowStart + 4);
$windowStart = max(1, $windowEnd - 4);
?>
<div class="admin-page-head">
    <div>
        <h2 class="admin-page-head__title">课程管理</h2>
        <p class="admin-page-head__desc">共 <?= (int) $total ?> 门课程，可创建课程、编辑信息并控制上架状态。</p>
    </div>
    <div class="admin-page-head__actions">
        <a class="btn btn--sm" href="<?= url('/admin/courses/create') ?>">新增课程</a>
    </div>
</div>

<form class="admin-toolbar" method="get" action="<?= url('/admin/courses') ?>">
    <div class="admin-toolbar__field admin-toolbar__field--grow">
        <label class="form-label" for="filter-keyword">关键词</label>
        <input class="input" id="filter-keyword" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="课程名称 / 副标题">
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-category">分类</label>
        <select class="select" id="filter-category" name="category_id">
            <option value="">全部分类</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>"
                    <?= (int) $filters['category_id'] === (int) $category['id'] ? ' selected' : '' ?>>
                    <?= (int) ($category['depth'] ?? 0) > 0 ? '— ' : '' ?><?= e((string) $category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-tag">标签</label>
        <select class="select" id="filter-tag" name="tag_id">
            <option value="">全部标签</option>
            <?php foreach ($tags as $tag): ?>
                <option value="<?= (int) $tag['id'] ?>"
                    <?= (int) $filters['tag_id'] === (int) $tag['id'] ? ' selected' : '' ?>>
                    <?= e((string) $tag['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__field">
        <label class="form-label" for="filter-status">状态</label>
        <select class="select" id="filter-status" name="status">
            <option value="">全部状态</option>
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-toolbar__actions">
        <button class="btn btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/admin/courses') ?>">重置</a>
    </div>
</form>

<?php if ($courses === []): ?>
    <div class="card card--flat">
        <div class="empty-state">
            <p class="mb-0">没有符合条件的课程。</p>
        </div>
    </div>
<?php else: ?>
    <form class="admin-batch-bar" id="batch-form" method="post" action="<?= url('/admin/courses/batch') ?>"
          data-batch-form data-confirm="确定对已选中的课程执行该批量操作吗？">
        <?= csrf_field() ?>
        <span class="admin-batch-bar__count">已选 <strong data-batch-count>0</strong> 项</span>
        <div class="admin-batch-bar__actions">
            <select class="select" name="action" required aria-label="批量操作">
                <option value="">批量操作…</option>
                <option value="publish">上架课程</option>
                <option value="offline">下架课程</option>
                <option value="draft">转为草稿</option>
                <option value="delete">删除课程</option>
            </select>
            <button class="btn btn--sm" type="submit">执行</button>
        </div>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" data-batch-select-all aria-label="全选本页"></th>
                    <th>序号</th>
                    <th>课程</th>
                    <th>分类</th>
                    <th>价格</th>
                    <th>状态</th>
                    <th>试看</th>
                    <th>销量</th>
                    <th>更新时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($courses as $index => $item): ?>
                    <?php
                    $courseId = (int) $item['id'];
                    $status   = (string) $item['status'];
                    $price    = (int) $item['price'];
                    $original = (int) $item['original_price'];
                    ?>
                    <tr>
                        <td class="col-check">
                            <input type="checkbox" form="batch-form" name="ids[]" value="<?= $courseId ?>"
                                   data-batch-checkbox aria-label="选择课程 <?= e((string) $item['title']) ?>">
                        </td>
                        <td class="text-faint"><?= $index + 1 ?></td>
                        <td>
                            <div class="admin-course-cell">
                                <span class="admin-course-cell__title"><?= e((string) $item['title']) ?></span>
                                <?php if (!empty($item['subtitle'])): ?>
                                    <span class="admin-course-cell__sub"><?= e((string) $item['subtitle']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= e((string) ($item['category_name'] ?? '未分类')) ?></td>
                        <td>
                            <span class="admin-course-price"><?= price_html($price) ?></span>
                            <?php if ($original > $price && $original > 0): ?>
                                <span class="admin-course-price__original">&yen;<?= format_money($original) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $statusBadge[$status] ?? 'badge' ?>">
                                <?= e($statuses[$status] ?? $status) ?>
                            </span>
                        </td>
                        <td class="text-faint">
                            <?= (int) $item['preview_enabled'] === 1
                                ? '前 ' . (int) $item['preview_chapter_count'] . ' 节'
                                : '关闭' ?>
                        </td>
                        <td class="text-faint"><?= (int) $item['sales_count'] ?></td>
                        <td class="text-faint"><?= e((string) $item['updated_at']) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn--outline btn--sm" href="<?= url('/admin/courses/' . $courseId . '/chapters') ?>">章节</a>
                                <a class="btn btn--outline btn--sm" href="<?= url('/admin/courses/' . $courseId . '/edit') ?>">编辑</a>

                                <?php if ($status === 'published'): ?>
                                    <form method="post" action="<?= url('/admin/courses/' . $courseId . '/status') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="offline">
                                        <button class="btn btn--ghost btn--sm" type="submit">下架</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= url('/admin/courses/' . $courseId . '/status') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="published">
                                        <button class="btn btn--ghost btn--sm" type="submit">上架</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="<?= url('/admin/courses/' . $courseId . '/delete') ?>"
                                      data-confirm="确定删除该课程吗？课程将下架，订单与章节等历史数据保留。">
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

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="分页">
            <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
               href="<?= $page <= 1 ? '#' : $pageUrl($page - 1) ?>" rel="prev">上一页</a>

            <?php if ($windowStart > 1): ?>
                <a class="pagination__item" href="<?= $pageUrl(1) ?>">1</a>
                <?php if ($windowStart > 2): ?>
                    <span class="pagination__item is-disabled">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                <a class="pagination__item<?= $p === $page ? ' is-active' : '' ?>" href="<?= $pageUrl($p) ?>"><?= $p ?></a>
            <?php endfor; ?>

            <?php if ($windowEnd < $totalPages): ?>
                <?php if ($windowEnd < $totalPages - 1): ?>
                    <span class="pagination__item is-disabled">…</span>
                <?php endif; ?>
                <a class="pagination__item" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <a class="pagination__item<?= $page >= $totalPages ? ' is-disabled' : '' ?>"
               href="<?= $page >= $totalPages ? '#' : $pageUrl($page + 1) ?>" rel="next">下一页</a>
        </nav>
    <?php endif; ?>
<?php endif; ?>
