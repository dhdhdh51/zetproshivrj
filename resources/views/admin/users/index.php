<?php
/**
 * @var string $search
 * @var string $status
 * @var string $role
 * @var array  $users  paginator
 * @var array  $stats
 */
?>
<div class="page-head">
    <div>
        <h1>Users</h1>
        <p><?= number_format((int) $stats['total']) ?> accounts · <?= number_format((int) $stats['active']) ?> active · <?= number_format((int) $stats['admins']) ?> administrators</p>
    </div>
</div>

<div class="card-dp">
    <div class="card-dp__head">
        <form method="get" action="<?= e(url('admin/users')) ?>" class="row g-2 flex-grow-1">
            <div class="col-sm-6 col-lg-5">
                <input type="search" name="q" value="<?= e($search) ?>" class="input-dp" placeholder="Search by name or email…">
            </div>
            <div class="col-6 col-lg-3">
                <select name="status" class="select-dp" data-auto-submit>
                    <option value="">All statuses</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <select name="role" class="select-dp" data-auto-submit>
                    <option value="">All roles</option>
                    <option value="user" <?= $role === 'user' ? 'selected' : '' ?>>Users</option>
                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admins</option>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn-dp btn-outline-dp flex-grow-1"><?= icon('search', '', 16) ?></button>
                <?php if ($search !== '' || $status !== '' || $role !== ''): ?>
                    <a href="<?= e(url('admin/users')) ?>" class="btn-dp btn-ghost-dp"><?= icon('x', '', 16) ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($users['data'] === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon('users', '', 26) ?></div>
            <h3>No users match those filters</h3>
            <p>Try a different search term or clear the filters.</p>
            <a href="<?= e(url('admin/users')) ?>" class="btn-dp btn-outline-dp">Clear filters</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-dp">
                <thead>
                <tr>
                    <th>User</th>
                    <th>Plan</th>
                    <th class="num">Documents</th>
                    <th class="num">AI</th>
                    <th>Status</th>
                    <th class="num">Joined</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users['data'] as $user): ?>
                    <?php $id = (int) $user['id']; ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar avatar-sm"><?= e(initials((string) $user['name'])) ?></span>
                                <div>
                                    <a href="<?= e(url('admin/users/' . $id)) ?>" class="fw-650"><?= e((string) $user['name']) ?></a>
                                    <?php if ((string) $user['role'] === 'admin'): ?><span class="admin-badge ms-1">ADMIN</span><?php endif; ?>
                                    <div class="text-muted-2" style="font-size:.82rem">
                                        <?= e((string) $user['email']) ?>
                                        <?php if (empty($user['email_verified_at'])): ?>
                                            <span class="badge badge-warning ms-1">unverified</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge <?= empty($user['plan_name']) ? 'badge-muted' : 'badge-primary' ?>"><?= e((string) ($user['plan_name'] ?? 'Free')) ?></span></td>
                        <td class="num"><?= number_format((int) ($user['documents_count'] ?? 0)) ?></td>
                        <td class="num"><?= number_format((int) ($user['ai_count'] ?? 0)) ?></td>
                        <td><span class="badge <?= (string) $user['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>"><?= e((string) $user['status']) ?></span></td>
                        <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date((string) $user['created_at'])) ?></td>
                        <td class="num">
                            <div class="btn-group-dp justify-content-end">
                                <a href="<?= e(url('admin/users/' . $id)) ?>" class="btn-dp btn-outline-dp btn-sm-dp"><?= icon('eye', '', 15) ?></a>
                                <form method="post" action="<?= e(url('admin/users/' . $id . '/status')) ?>"
                                      data-confirm="<?= (string) $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?> <?= e((string) $user['email']) ?>?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp"
                                            title="<?= (string) $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                        <?= icon((string) $user['status'] === 'active' ? 'lock' : 'check', '', 15) ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-dp__foot">
            <?= view_partial('partials.pagination', [
                'paginator' => $users,
                'query' => array_filter(['q' => $search, 'status' => $status, 'role' => $role]),
            ]) ?>
        </div>
    <?php endif; ?>
</div>
