<?php

declare(strict_types=1);

/**
 * 课程列表：搜索、分类筛选、标签筛选、排序、分页
 *
 * @var array<int, array<string, mixed>> $categories 课程分类列表
 * @var array<int, array<string, mixed>> $tags 标签列表
 * @var array<int, array<string, mixed>> $courses 课程列表
 * @var int $total 课程总数
 * @var int $page 当前页码
 * @var int $totalPages 总页数
 * @var string $keyword 搜索关键词
 * @var int $categoryId 当前分类 ID（0 表示全部）
 * @var int $tagId 当前标签 ID（0 表示全部）
 * @var string $sort 排序方式
 * @var array<string, string> $sortOptions 排序方式选项
 */

$queryParams = [];
if ($keyword !== '') {
    $queryParams['q'] = $keyword;
}
if ($categoryId > 0) {
    $queryParams['category'] = $categoryId;
}
if ($tagId > 0) {
    $queryParams['tag'] = $tagId;
}
if ($sort !== 'recommended') {
    $queryParams['sort'] = $sort;
}

/** 生成带筛选条件的分页地址 */
$pageUrl = static function (int $target) use ($queryParams): string {
    return url('/courses') . '?' . http_build_query($queryParams + ['page' => $target]);
};

/**
 * 生成带筛选条件的地址
 *
 * $overrides 仅覆盖需要变更的维度：category / tag 传 0 表示清除该筛选，
 * 未出现在数组中的维度保持当前值。
 */
$filterUrl = static function (array $overrides) use ($keyword, $categoryId, $tagId, $sort): string {
    $nextCategory = array_key_exists('category', $overrides) ? (int) $overrides['category'] : $categoryId;
    $nextTag      = array_key_exists('tag', $overrides) ? (int) $overrides['tag'] : $tagId;

    $params = [];
    if ($keyword !== '') {
        $params['q'] = $keyword;
    }
    if ($nextCategory > 0) {
        $params['category'] = $nextCategory;
    }
    if ($nextTag > 0) {
        $params['tag'] = $nextTag;
    }
    if ($sort !== 'recommended') {
        $params['sort'] = $sort;
    }

    $query = http_build_query($params);

    return url('/courses') . ($query === '' ? '' : '?' . $query);
};

// 分页窗口：最多显示 5 个页码
$windowStart = max(1, min($page - 2, $totalPages - 4));
$windowEnd   = min($totalPages, $windowStart + 4);
?>
<div class="page-head container">
    <h1 class="page-head__title"><?= e(t('全部课程')) ?></h1>
    <p class="page-head__desc">
        <?= e(t('共 %d 门课程', [(int) $total])) ?><?= $keyword !== '' ? e(t('，关键词「%s」', [$keyword])) : '' ?>
    </p>
</div>

<div class="container">
    <form class="card card--flat mb-6" method="get" action="<?= url('/courses') ?>">
        <?php if ($categoryId > 0): ?>
            <input type="hidden" name="category" value="<?= (int) $categoryId ?>">
        <?php endif; ?>
        <?php if ($tagId > 0): ?>
            <input type="hidden" name="tag" value="<?= (int) $tagId ?>">
        <?php endif; ?>

        <div class="card__body">
            <div class="search-form mb-4">
                <input class="input grow" type="search" name="q" value="<?= e($keyword) ?>"
                       placeholder="<?= e(t('搜索课程名称或简介')) ?>" aria-label="<?= e(t('搜索课程')) ?>">
                <button class="btn" type="submit"><?= e(t('搜索')) ?></button>
            </div>

            <?php // 移动端：唤起筛选抽屉（桌面隐藏） ?>
            <button type="button" class="btn btn--outline filter-trigger" data-sheet-toggle="course-filter">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 6h16M7 12h10M10 18h4"></path>
                </svg>
                <?= e(t('筛选与排序')) ?>
            </button>

            <?php // 桌面为行内筛选栏；移动端变为底部抽屉 ?>
            <div class="filter-bar" id="course-filter" data-sheet="course-filter">
                <div class="filter-bar__head">
                    <span class="filter-bar__title"><?= e(t('筛选与排序')) ?></span>
                    <button type="button" class="filter-bar__close" data-sheet-close aria-label="<?= e(t('关闭筛选')) ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6l12 12M18 6L6 18"></path>
                        </svg>
                    </button>
                </div>

                <div class="filter-bar__filters">
                    <div class="filter-bar__group">
                        <span class="filter-bar__label"><?= e(t('分类')) ?></span>
                        <div class="filter-bar__chips">
                            <a class="chip<?= $categoryId === 0 ? ' is-active' : '' ?>"
                               href="<?= $filterUrl(['category' => 0]) ?>"><?= e(t('全部')) ?></a>
                            <?php foreach ($categories as $category): ?>
                                <a class="chip<?= $categoryId === (int) $category['id'] ? ' is-active' : '' ?>"
                                   href="<?= $filterUrl(['category' => (int) $category['id']]) ?>">
                                    <?= (int) ($category['depth'] ?? 0) > 0 ? '— ' : '' ?><?= e($category['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($tags !== []): ?>
                        <div class="filter-bar__group">
                            <span class="filter-bar__label"><?= e(t('标签')) ?></span>
                            <div class="filter-bar__chips">
                                <a class="chip<?= $tagId === 0 ? ' is-active' : '' ?>"
                                   href="<?= $filterUrl(['tag' => 0]) ?>"><?= e(t('全部')) ?></a>
                                <?php foreach ($tags as $tag): ?>
                                    <a class="chip<?= $tagId === (int) $tag['id'] ? ' is-active' : '' ?>"
                                       href="<?= $filterUrl(['tag' => (int) $tag['id']]) ?>">
                                        <?= e($tag['name']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <label class="sr-only" for="sort"><?= e(t('排序方式')) ?></label>
                <select class="select" id="sort" name="sort" data-auto-submit>
                    <?php foreach ($sortOptions as $sortKey => $sortLabel): ?>
                        <option value="<?= e($sortKey) ?>"<?= $sort === $sortKey ? ' selected' : '' ?>>
                            <?= e(t($sortLabel)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="btn btn--block filter-bar__apply" data-sheet-close><?= e(t('查看结果')) ?></button>
            </div>
        </div>
    </form>

    <div class="sheet-scrim" data-sheet-scrim="course-filter" hidden></div>

    <?php if ($courses !== []): ?>
        <div class="course-grid">
            <?php foreach ($courses as $cardCourse): ?>
                <?php require VIEW_PATH . '/partials/course-card.php'; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="<?= e(t('分页')) ?>">
                <a class="pagination__item<?= $page <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= $page <= 1 ? '#' : $pageUrl($page - 1) ?>" rel="prev"><?= e(t('上一页')) ?></a>

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
                   href="<?= $page >= $totalPages ? '#' : $pageUrl($page + 1) ?>" rel="next"><?= e(t('下一页')) ?></a>
            </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="card card--flat">
            <div class="empty-state">
                <div class="empty-state__icon" aria-hidden="true">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                </div>
                <p><?= e(t('没有找到符合条件的课程。')) ?></p>
                <p class="mb-0">
                    <a class="btn btn--outline btn--sm" href="<?= url('/courses') ?>"><?= e(t('重置筛选条件')) ?></a>
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>
