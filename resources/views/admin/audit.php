<?php
/**
 * @var array $logs
 * @var array $filters
 * @var array $actions
 * @var array $entityTypes
 * @var array $users
 */
$f = static fn (string $key): string => (string) ($filters[$key] ?? '');
?>

<div class="page-head">
    <div class="grow">
        <h1>Audit log</h1>
        <div class="subtitle">
            Append-only record of sign-ins, imports, allocations, visits, inspections, money entries,
            configuration changes and exports. <?= number_format($total) ?> entries.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <form method="get" action="<?= e(url('/admin/audit')) ?>" class="filters" style="flex:1 1 auto">
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($f('from')) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($f('to')) ?>">
            </div>
            <div class="field">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $row): ?>
                        <option value="<?= e((string) $row['action']) ?>" <?= $f('action') === (string) $row['action'] ? 'selected' : '' ?>>
                            <?= e(enum_label((string) $row['action'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="user_id">User</label>
                <select id="user_id" name="user_id">
                    <option value="">Everyone</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= $f('user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?> (<?= e(enum_label((string) $user['role'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="entity_type">Record type</label>
                <select id="entity_type" name="entity_type">
                    <option value="">Any</option>
                    <?php foreach ($entityTypes as $row): ?>
                        <option value="<?= e((string) $row['entity_type']) ?>" <?= $f('entity_type') === (string) $row['entity_type'] ? 'selected' : '' ?>>
                            <?= e(enum_label((string) $row['entity_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field wide">
                <label for="search">Search</label>
                <input type="search" id="search" name="search" value="<?= e($f('search')) ?>" placeholder="Description, user, path">
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-secondary"><?= icon('filter', '', 15) ?> Apply</button>
                <a class="btn btn-link" href="<?= e(url('/admin/audit')) ?>">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($logs === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No audit entries match', 'iconName' => 'shield']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Description</th><th>Change</th><th>Origin</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="small nowrap">
                                <?= e(format_datetime($log['created_at'])) ?>
                                <div class="tiny muted"><?= e(time_ago($log['created_at'])) ?></div>
                            </td>
                            <td class="small">
                                <?= e($log['user_name'] ?: 'system') ?>
                                <?php if (!empty($log['role_slug'])): ?>
                                    <div class="tiny muted"><?= e(enum_label((string) $log['role_slug'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= str_contains((string) $log['action'], 'failed') ? 'badge-danger' : 'badge-muted' ?>">
                                    <?= e(enum_label((string) $log['action'])) ?>
                                </span>
                            </td>
                            <td class="small">
                                <?php if (!empty($log['entity_type'])): ?>
                                    <?= e(enum_label((string) $log['entity_type'])) ?>
                                    <?php if ($log['entity_id'] !== null): ?>
                                        <span class="mono tiny">#<?= (int) $log['entity_id'] ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e($log['description'] ?: '—') ?></td>
                            <td class="tiny muted" style="max-width:280px">
                                <?php
                                $old = $log['old_values'] === null ? null : json_decode((string) $log['old_values'], true);
                                $new = $log['new_values'] === null ? null : json_decode((string) $log['new_values'], true);
                                ?>
                                <?php if (is_array($old) || is_array($new)): ?>
                                    <?php foreach (array_slice(array_keys(array_merge($old ?? [], $new ?? [])), 0, 4) as $key): ?>
                                        <div>
                                            <strong><?= e($key) ?>:</strong>
                                            <?php if (is_array($old) && array_key_exists($key, $old)): ?>
                                                <span style="text-decoration:line-through"><?= e(str_excerpt(audit_value($old[$key]), 18)) ?></span> →
                                            <?php endif; ?>
                                            <?= e(str_excerpt(audit_value($new[$key] ?? null), 20)) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="tiny muted">
                                <?= e($log['ip_address'] ?: '—') ?>
                                <?php if (!empty($log['request_path'])): ?>
                                    <div><?= e($log['request_method']) ?> <?= e(str_excerpt((string) $log['request_path'], 24)) ?></div>
                                <?php endif; ?>
                                <?php if ($log['device_id'] !== null): ?>
                                    <div>device #<?= (int) $log['device_id'] ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= view_partial('partials.pagination', [
            'page' => $page, 'lastPage' => $lastPage, 'total' => $total, 'perPage' => $perPage,
        ]) ?>
    <?php endif; ?>
</div>
