<?php
/**
 * @var int $page
 * @var int $lastPage
 * @var int $total
 * @var int $perPage
 */
$page = (int) ($page ?? 1);
$lastPage = max(1, (int) ($lastPage ?? 1));
$total = (int) ($total ?? 0);
$perPage = (int) ($perPage ?? 50);

$from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to = min($total, $page * $perPage);

$window = 2;
$start = max(1, $page - $window);
$end = min($lastPage, $page + $window);
?>
<div class="card-foot">
    <div class="small muted">
        Showing <strong><?= number_format($from) ?></strong>–<strong><?= number_format($to) ?></strong>
        of <strong><?= number_format($total) ?></strong>
    </div>
    <div class="spacer" style="flex:1 1 auto"></div>

    <?php if ($lastPage > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= e(query_string(['page' => $page - 1])) ?>" rel="prev">Previous</a>
            <?php else: ?>
                <span class="disabled">Previous</span>
            <?php endif; ?>

            <?php if ($start > 1): ?>
                <a href="<?= e(query_string(['page' => 1])) ?>">1</a>
                <?php if ($start > 2): ?><span class="disabled">…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= e(query_string(['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $lastPage): ?>
                <?php if ($end < $lastPage - 1): ?><span class="disabled">…</span><?php endif; ?>
                <a href="<?= e(query_string(['page' => $lastPage])) ?>"><?= $lastPage ?></a>
            <?php endif; ?>

            <?php if ($page < $lastPage): ?>
                <a href="<?= e(query_string(['page' => $page + 1])) ?>" rel="next">Next</a>
            <?php else: ?>
                <span class="disabled">Next</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
