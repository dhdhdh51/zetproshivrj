<?php
/**
 * @var array{data:array,total:int,page:int,per_page:int,last_page:int,from:int,to:int} $paginator
 * @var array<string, mixed>|null $query  extra query-string values to preserve
 */
$query = $query ?? [];
$page = (int) $paginator['page'];
$lastPage = (int) $paginator['last_page'];

if ($lastPage <= 1) {
    return;
}

$link = static function (int $target) use ($query): string {
    $params = array_filter(
        array_merge($query, ['page' => $target]),
        static fn ($value): bool => $value !== '' && $value !== null && $value !== 0
    );

    return '?' . http_build_query($params);
};

$window = 2;
$start = max(1, $page - $window);
$end = min($lastPage, $page + $window);
?>
<nav class="d-flex flex-wrap gap-3 align-items-center justify-content-between mt-3" aria-label="Pagination">
    <p class="text-muted-2 small mb-0">
        Showing <?= (int) $paginator['from'] ?>–<?= (int) $paginator['to'] ?> of <?= (int) $paginator['total'] ?>
    </p>

    <div class="pagination-dp">
        <?php if ($page > 1): ?>
            <a href="<?= e($link($page - 1)) ?>" rel="prev" aria-label="Previous page"><?= icon('chevron-left', '', 16) ?></a>
        <?php else: ?>
            <span class="disabled"><?= icon('chevron-left', '', 16) ?></span>
        <?php endif; ?>

        <?php if ($start > 1): ?>
            <a href="<?= e($link(1)) ?>">1</a>
            <?php if ($start > 2): ?><span class="disabled">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current" aria-current="page"><?= $i ?></span>
            <?php else: ?>
                <a href="<?= e($link($i)) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $lastPage): ?>
            <?php if ($end < $lastPage - 1): ?><span class="disabled">…</span><?php endif; ?>
            <a href="<?= e($link($lastPage)) ?>"><?= $lastPage ?></a>
        <?php endif; ?>

        <?php if ($page < $lastPage): ?>
            <a href="<?= e($link($page + 1)) ?>" rel="next" aria-label="Next page"><?= icon('chevron-right', '', 16) ?></a>
        <?php else: ?>
            <span class="disabled"><?= icon('chevron-right', '', 16) ?></span>
        <?php endif; ?>
    </div>
</nav>
