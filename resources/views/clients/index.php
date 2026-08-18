<?php
/**
 * @var string $search
 * @var array  $clients  paginator
 */
?>
<div class="page-head">
    <div>
        <h1>Clients</h1>
        <p>Save your clients once and reuse them on every document.</p>
    </div>
    <a href="<?= e(url('clients/create')) ?>" class="btn-dp btn-primary-dp"><?= icon('plus', '', 17) ?> Add client</a>
</div>

<div class="card-dp">
    <div class="card-dp__head">
        <form method="get" action="<?= e(url('clients')) ?>" class="d-flex gap-2 flex-grow-1" style="max-width:460px">
            <input type="search" name="q" value="<?= e($search) ?>" class="input-dp" placeholder="Search name, company, email or phone…">
            <button type="submit" class="btn-dp btn-outline-dp"><?= icon('search', '', 17) ?></button>
            <?php if ($search !== ''): ?>
                <a href="<?= e(url('clients')) ?>" class="btn-dp btn-ghost-dp">Clear</a>
            <?php endif; ?>
        </form>
        <span class="text-muted-2 small"><?= (int) $clients['total'] ?> client<?= (int) $clients['total'] === 1 ? '' : 's' ?></span>
    </div>

    <?php if ($clients['data'] === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon('users', '', 26) ?></div>
            <h3><?= $search !== '' ? 'No clients match that search' : 'No clients yet' ?></h3>
            <p>
                <?= $search !== ''
                    ? 'Try a different name, company or email address.'
                    : 'Add your first client to speed up document creation — their details are filled in automatically.' ?>
            </p>
            <a href="<?= e(url('clients/create')) ?>" class="btn-dp btn-primary-dp"><?= icon('plus', '', 17) ?> Add your first client</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-dp">
                <thead>
                <tr>
                    <th>Client</th>
                    <th>Contact</th>
                    <th class="num">Documents</th>
                    <th class="num">Added</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients['data'] as $client): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar avatar-sm"><?= e(initials((string) $client['name'])) ?></span>
                                <div>
                                    <a href="<?= e(url('clients/' . (int) $client['id'])) ?>" class="fw-650"><?= e((string) $client['name']) ?></a>
                                    <?php if (!empty($client['company'])): ?>
                                        <div class="text-muted-2" style="font-size:.83rem"><?= e((string) $client['company']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:.88rem">
                            <?php if (!empty($client['email'])): ?><div><?= e((string) $client['email']) ?></div><?php endif; ?>
                            <?php if (!empty($client['phone'])): ?><div class="text-muted-2"><?= e((string) $client['phone']) ?></div><?php endif; ?>
                            <?php if (empty($client['email']) && empty($client['phone'])): ?><span class="text-muted-2">—</span><?php endif; ?>
                        </td>
                        <td class="num"><?= (int) ($client['documents_count'] ?? 0) ?></td>
                        <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date((string) $client['created_at'])) ?></td>
                        <td class="num">
                            <div class="btn-group-dp justify-content-end">
                                <a href="<?= e(url('documents/create?client_id=' . (int) $client['id'])) ?>" class="btn-dp btn-soft-dp btn-sm-dp" title="New document">
                                    <?= icon('plus', '', 15) ?>
                                </a>
                                <a href="<?= e(url('clients/' . (int) $client['id'] . '/edit')) ?>" class="btn-dp btn-outline-dp btn-sm-dp" title="Edit">
                                    <?= icon('edit', '', 15) ?>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-dp__foot">
            <?= view_partial('partials.pagination', ['paginator' => $clients, 'query' => ['q' => $search]]) ?>
        </div>
    <?php endif; ?>
</div>
