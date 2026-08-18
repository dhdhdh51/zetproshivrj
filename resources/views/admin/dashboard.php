<?php
/**
 * @var array $users
 * @var array $documents
 * @var array $ai
 * @var array $payments
 * @var array $emails
 * @var int   $active_subscriptions
 * @var array $recent_users
 * @var array $recent_documents
 * @var array $recent_payments
 * @var array $activity
 * @var array $usage_trend
 * @var array $system
 */
$status = static fn (bool $ok): string => '<span class="status-dot ' . ($ok ? 'on' : 'off') . '"></span> ' . ($ok ? 'Ready' : 'Needs setup');
?>
<div class="page-head">
    <div>
        <h1>Admin dashboard</h1>
        <p>Platform health, usage and revenue at a glance.</p>
    </div>
    <div class="btn-group-dp">
        <a href="<?= e(url('admin/ai')) ?>" class="btn-dp btn-outline-dp"><?= icon('sparkles', '', 17) ?> AI settings</a>
        <a href="<?= e(url('admin/users')) ?>" class="btn-dp btn-primary-dp"><?= icon('users', '', 17) ?> Manage users</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat__label">Total users</div>
        <div class="stat__value"><?= number_format((int) $users['total']) ?></div>
        <div class="stat__meta"><?= number_format((int) $users['this_month']) ?> new this month · <?= number_format((int) $users['active']) ?> active</div>
    </div>
    <div class="stat">
        <div class="stat__label">Total documents</div>
        <div class="stat__value"><?= number_format((int) $documents['total']) ?></div>
        <div class="stat__meta"><?= number_format((int) $documents['this_month']) ?> this month · <?= number_format((int) $documents['sent']) ?> sent</div>
    </div>
    <div class="stat">
        <div class="stat__label">AI generations</div>
        <div class="stat__value"><?= number_format((int) $ai['total']) ?></div>
        <div class="stat__meta"><?= number_format((int) $ai['this_month']) ?> this month · <?= number_format((int) $ai['tokens']) ?> tokens</div>
    </div>
    <div class="stat">
        <div class="stat__label">Revenue</div>
        <div class="stat__value"><?= e(money((float) $payments['revenue'], 'INR')) ?></div>
        <div class="stat__meta"><?= e(money((float) $payments['revenue_this_month'], 'INR')) ?> this month · <?= (int) $payments['successful'] ?> payments</div>
    </div>
    <div class="stat">
        <div class="stat__label">Active subscriptions</div>
        <div class="stat__value"><?= number_format($active_subscriptions) ?></div>
        <div class="stat__meta"><?= (int) $payments['failed'] ?> failed payments recorded</div>
    </div>
    <div class="stat">
        <div class="stat__label">Emails delivered</div>
        <div class="stat__value"><?= number_format((int) $emails['sent']) ?></div>
        <div class="stat__meta"><?= number_format((int) $emails['failed']) ?> failed</div>
    </div>
</div>

<div class="row g-3 mt-3">
    <div class="col-lg-4">
        <div class="card-dp">
            <div class="card-dp__head"><h3>System status</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>PHP</dt><dd><?= e((string) $system['php']) ?></dd>
                    <dt>Database</dt><dd><?= $status((bool) $system['database']) ?></dd>
                    <dt>PDF engine</dt><dd><?= $status((bool) $system['pdf_available']) ?></dd>
                    <dt>Storage</dt><dd><?= $status((bool) $system['storage_writable']) ?></dd>
                    <dt>OpenRouter</dt>
                    <dd>
                        <?= $status((bool) $system['ai_configured']) ?>
                        <?php if ((bool) $system['ai_configured'] && !(bool) $system['ai_enabled']): ?>
                            <span class="badge badge-warning ms-1">disabled</span>
                        <?php endif; ?>
                        <div class="text-muted-2" style="font-size:.8rem"><?= e((string) $system['ai_model']) ?></div>
                    </dd>
                    <dt>SMTP</dt><dd><?= $status((bool) $system['mail_configured'] && (bool) $system['mail_available']) ?></dd>
                    <dt>PayU</dt>
                    <dd>
                        <?= $status((bool) $system['payu_configured']) ?>
                        <span class="badge <?= (string) $system['payu_mode'] === 'live' ? 'badge-success' : 'badge-warning' ?> ms-1">
                            <?= e((string) $system['payu_mode']) ?>
                        </span>
                    </dd>
                </dl>
            </div>
            <div class="card-dp__foot d-flex flex-wrap gap-2">
                <a href="<?= e(url('admin/email')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">Email</a>
                <a href="<?= e(url('admin/payu')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">PayU</a>
                <a href="<?= e(url('admin/settings')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">System</a>
            </div>
        </div>

        <?php if ($documents['by_type'] !== []): ?>
            <div class="card-dp">
                <div class="card-dp__head"><h3>Documents by type</h3></div>
                <div class="card-dp__body">
                    <?php foreach ($documents['by_type'] as $row): ?>
                        <?php $percent = percent_of((int) $row['total'], max(1, (int) $documents['total'])); ?>
                        <div class="usage-row">
                            <div class="usage-row__top">
                                <span><?= e(document_type_label((string) $row['document_type'])) ?></span>
                                <strong><?= number_format((int) $row['total']) ?></strong>
                            </div>
                            <div class="progress-dp"><span style="width:<?= $percent ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card-dp">
            <div class="card-dp__head">
                <h3>Newest users</h3>
                <a href="<?= e(url('admin/users')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp">All users <?= icon('arrow-right', '', 15) ?></a>
            </div>
            <?php if ($recent_users === []): ?>
                <div class="card-dp__body text-muted-2">No users yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-dp">
                        <thead><tr><th>User</th><th>Status</th><th class="num">Joined</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent_users as $user): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('admin/users/' . (int) $user['id'])) ?>" class="fw-650"><?= e((string) $user['name']) ?></a>
                                    <div class="text-muted-2" style="font-size:.82rem"><?= e((string) $user['email']) ?></div>
                                </td>
                                <td><span class="badge <?= (string) $user['status'] === 'active' ? 'badge-success' : 'badge-muted' ?>"><?= e((string) $user['status']) ?></span></td>
                                <td class="num text-muted-2" style="font-size:.85rem"><?= e(format_date((string) $user['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card-dp h-100">
                    <div class="card-dp__head">
                        <h3>Latest documents</h3>
                        <a href="<?= e(url('admin/documents')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp"><?= icon('arrow-right', '', 15) ?></a>
                    </div>
                    <div class="card-dp__body">
                        <?php if ($recent_documents === []): ?>
                            <p class="text-muted-2 mb-0">No documents yet.</p>
                        <?php else: ?>
                            <?php foreach ($recent_documents as $document): ?>
                                <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.88rem">
                                    <div>
                                        <a href="<?= e(url('admin/documents/' . (int) $document['id'])) ?>" class="mono"><?= e((string) $document['document_number']) ?></a>
                                        <div class="text-muted-2" style="font-size:.78rem"><?= e(str_excerpt((string) $document['user_email'], 26)) ?></div>
                                    </div>
                                    <span class="fw-650"><?= e(money((float) $document['total'], (string) $document['currency'])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card-dp h-100">
                    <div class="card-dp__head">
                        <h3>Latest payments</h3>
                        <a href="<?= e(url('admin/payments')) ?>" class="btn-dp btn-ghost-dp btn-sm-dp"><?= icon('arrow-right', '', 15) ?></a>
                    </div>
                    <div class="card-dp__body">
                        <?php if ($recent_payments === []): ?>
                            <p class="text-muted-2 mb-0">No payments yet.</p>
                        <?php else: ?>
                            <?php foreach ($recent_payments as $payment): ?>
                                <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.88rem">
                                    <div>
                                        <a href="<?= e(url('admin/payments/' . (int) $payment['id'])) ?>" class="mono" style="font-size:.8rem"><?= e((string) $payment['txnid']) ?></a>
                                        <div class="text-muted-2" style="font-size:.78rem"><?= e(str_excerpt((string) $payment['user_email'], 24)) ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-650"><?= e(money((float) $payment['amount'], (string) $payment['currency'])) ?></div>
                                        <span class="<?= status_class((string) $payment['status'] === 'success' ? 'paid' : (string) $payment['status']) ?>"><?= e((string) $payment['status']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Recent activity</h3></div>
            <div class="card-dp__body">
                <?php if ($activity === []): ?>
                    <p class="text-muted-2 mb-0">No activity recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($activity as $entry): ?>
                        <div class="d-flex justify-content-between gap-3 py-1" style="font-size:.86rem">
                            <div>
                                <span class="badge badge-muted"><?= e((string) $entry['action']) ?></span>
                                <?= e(str_excerpt((string) ($entry['description'] ?? ''), 54)) ?>
                            </div>
                            <span class="text-muted-2 text-nowrap"><?= e(format_date((string) $entry['created_at'], 'd M, H:i')) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
