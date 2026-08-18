<?php
/**
 * @var array $user
 * @var array|null $profile
 * @var array $summary
 * @var array $documents
 * @var int   $documents_count
 * @var array $ai_recent
 * @var array $payments
 * @var array $subscriptions
 * @var array $plans
 * @var array $activity
 */
$id = (int) $user['id'];
$plan = $summary['plan'];
$isSelf = $id === (int) App\Core\Auth::id();
?>
<div class="page-head">
    <div class="d-flex align-items-center gap-3">
        <span class="avatar" style="width:48px;height:48px;flex:0 0 48px;font-size:1rem"><?= e(initials((string) $user['name'])) ?></span>
        <div>
            <h1 class="mb-1">
                <?= e((string) $user['name']) ?>
                <?php if ((string) $user['role'] === 'admin'): ?><span class="admin-badge">ADMIN</span><?php endif; ?>
            </h1>
            <p><?= e((string) $user['email']) ?> · joined <?= e(format_date((string) $user['created_at'])) ?></p>
        </div>
    </div>
    <a href="<?= e(url('admin/users')) ?>" class="btn-dp btn-ghost-dp"><?= icon('arrow-left', '', 17) ?> All users</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-dp">
            <div class="card-dp__head">
                <h3>Account</h3>
                <span class="badge <?= (string) $user['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>"><?= e((string) $user['status']) ?></span>
            </div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Email</dt><dd><?= e((string) $user['email']) ?></dd>
                    <dt>Verified</dt>
                    <dd><?= empty($user['email_verified_at']) ? '<span class="badge badge-warning">No</span>' : e(format_date((string) $user['email_verified_at'], 'd M Y')) ?></dd>
                    <dt>Sign-in method</dt><dd><?= empty($user['google_id']) ? 'Email &amp; password' : 'Google' ?></dd>
                    <dt>Last sign-in</dt><dd><?= e(format_date($user['last_login_at'] ?? null, 'd M Y, H:i')) ?></dd>
                    <dt>Role</dt><dd class="text-capitalize"><?= e((string) $user['role']) ?></dd>
                    <dt>Password</dt><dd class="text-muted-2">Never displayed</dd>
                </dl>
            </div>
            <div class="card-dp__foot d-flex flex-wrap gap-2">
                <?php if (!$isSelf): ?>
                    <form method="post" action="<?= e(url('admin/users/' . $id . '/status')) ?>"
                          data-confirm="<?= (string) $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this account?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-dp btn-outline-dp btn-sm-dp">
                            <?= icon((string) $user['status'] === 'active' ? 'lock' : 'check', '', 15) ?>
                            <?= (string) $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('admin/users/' . $id . '/role')) ?>"
                      data-confirm="Change this user's role?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="<?= (string) $user['role'] === 'admin' ? 'user' : 'admin' ?>">
                    <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" <?= $isSelf && (string) $user['role'] === 'admin' ? 'disabled' : '' ?>>
                        <?= icon('shield', '', 15) ?> Make <?= (string) $user['role'] === 'admin' ? 'standard user' : 'administrator' ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head">
                <h3>Plan &amp; usage</h3>
                <span class="badge badge-primary"><?= e((string) $plan['name']) ?></span>
            </div>
            <div class="card-dp__body">
                <div class="usage-row">
                    <div class="usage-row__top"><span>Documents this month</span><strong><?= (int) $summary['documents_used'] ?> / <?= (int) $summary['documents_limit'] ?></strong></div>
                    <div class="progress-dp"><span style="width:<?= (float) $summary['documents_percent'] ?>%"></span></div>
                </div>
                <div class="usage-row">
                    <div class="usage-row__top"><span>AI generations this month</span><strong><?= (int) $summary['ai_used'] ?> / <?= (int) $summary['ai_limit'] ?></strong></div>
                    <div class="progress-dp"><span style="width:<?= (float) $summary['ai_percent'] ?>%"></span></div>
                </div>
                <dl class="kv mt-3 mb-0">
                    <dt>Documents (all time)</dt><dd><?= number_format($documents_count) ?></dd>
                    <dt>Emails sent</dt><dd><?= (int) $summary['emails_used'] ?></dd>
                    <?php if (!$plan['is_free']): ?>
                        <dt>Renews</dt><dd><?= e(format_date($summary['renews_at'] ?? null)) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
            <div class="card-dp__foot">
                <form method="post" action="<?= e(url('admin/users/' . $id . '/plan')) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-6">
                        <label class="form-label-dp" for="plan_id">Assign plan</label>
                        <select id="plan_id" name="plan_id" class="select-dp">
                            <?php foreach ($plans as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= (string) $option['slug'] === (string) $plan['slug'] ? 'selected' : '' ?>>
                                    <?= e((string) $option['name']) ?> · <?= e(money((float) $option['price'], (string) $option['currency'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-3">
                        <label class="form-label-dp" for="months">Months</label>
                        <input type="number" id="months" name="months" class="input-dp" value="1" min="1" max="24">
                    </div>
                    <div class="col-3">
                        <button type="submit" class="btn-dp btn-primary-dp btn-block-dp">Apply</button>
                    </div>
                </form>
                <?php if (!$plan['is_free']): ?>
                    <form method="post" action="<?= e(url('admin/users/' . $id . '/plan/cancel')) ?>" class="mt-2"
                          data-confirm="Cancel this subscription and move the account back to Free?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp" style="color:var(--dp-danger)">
                            <?= icon('x', '', 15) ?> Cancel subscription
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Business profile</h3></div>
            <div class="card-dp__body">
                <?php if ($profile === null): ?>
                    <p class="text-muted-2 mb-0">This user has not set up a business profile yet.</p>
                <?php else: ?>
                    <dl class="kv mb-0">
                        <dt>Business</dt><dd><?= e((string) $profile['business_name']) ?></dd>
                        <dt>Email</dt><dd><?= e((string) ($profile['email'] ?? '—') ?: '—') ?></dd>
                        <dt>Phone</dt><dd><?= e((string) ($profile['phone'] ?? '—') ?: '—') ?></dd>
                        <dt>City</dt><dd><?= e(trim((string) ($profile['city'] ?? '') . ' ' . (string) ($profile['country'] ?? '')) ?: '—') ?></dd>
                        <dt>GSTIN</dt><dd><?= e((string) ($profile['gstin'] ?? '—') ?: '—') ?></dd>
                        <dt>Template</dt><dd class="text-capitalize"><?= e((string) $profile['default_template']) ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Recent documents</h3></div>
            <?php if ($documents === []): ?>
                <div class="card-dp__body text-muted-2">No documents yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-dp">
                        <thead><tr><th>Number</th><th>Title</th><th>Status</th><th class="num">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td><a href="<?= e(url('admin/documents/' . (int) $document['id'])) ?>" class="mono"><?= e((string) $document['document_number']) ?></a></td>
                                <td><?= e(str_excerpt((string) $document['title'], 34)) ?></td>
                                <td><span class="<?= status_class((string) $document['status']) ?>"><?= e((string) $document['status']) ?></span></td>
                                <td class="num"><?= e(money((float) $document['total'], (string) $document['currency'])) ?></td>
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
                    <div class="card-dp__head"><h3>AI usage</h3></div>
                    <div class="card-dp__body">
                        <?php if ($ai_recent === []): ?>
                            <p class="text-muted-2 mb-0">No AI generations yet.</p>
                        <?php else: ?>
                            <?php foreach ($ai_recent as $generation): ?>
                                <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.85rem">
                                    <span class="text-capitalize"><?= e(str_replace(['writing_', '_'], ['', ' '], (string) $generation['type'])) ?></span>
                                    <span class="text-muted-2">
                                        <?php if ((string) $generation['status'] === 'failed'): ?>
                                            <span class="badge badge-danger">failed</span>
                                        <?php else: ?>
                                            <?= number_format((int) $generation['total_tokens']) ?> tok
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card-dp h-100">
                    <div class="card-dp__head"><h3>Payments</h3></div>
                    <div class="card-dp__body">
                        <?php if ($payments === []): ?>
                            <p class="text-muted-2 mb-0">No payments recorded.</p>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.85rem">
                                    <a href="<?= e(url('admin/payments/' . (int) $payment['id'])) ?>" class="mono" style="font-size:.78rem">
                                        <?= e((string) $payment['txnid']) ?>
                                    </a>
                                    <span>
                                        <?= e(money((float) $payment['amount'], (string) $payment['currency'])) ?>
                                        <span class="<?= status_class((string) $payment['status'] === 'success' ? 'paid' : (string) $payment['status']) ?>"><?= e((string) $payment['status']) ?></span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Activity log</h3></div>
            <div class="card-dp__body">
                <?php if ($activity === []): ?>
                    <p class="text-muted-2 mb-0">Nothing logged yet.</p>
                <?php else: ?>
                    <?php foreach ($activity as $entry): ?>
                        <div class="d-flex justify-content-between gap-3 py-1" style="font-size:.85rem">
                            <div>
                                <span class="badge badge-muted"><?= e((string) $entry['action']) ?></span>
                                <?= e(str_excerpt((string) ($entry['description'] ?? ''), 46)) ?>
                            </div>
                            <span class="text-muted-2 text-nowrap"><?= e(format_date((string) $entry['created_at'], 'd M, H:i')) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
