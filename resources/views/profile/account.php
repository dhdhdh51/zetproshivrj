<?php
/**
 * @var array $user
 * @var array $summary
 */
$plan = $summary['plan'];
$isGoogleOnly = empty($user['password']);
?>
<div class="page-head">
    <div>
        <h1>Account settings</h1>
        <p>Manage your sign-in details and see your current plan usage.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-dp">
            <div class="card-dp__head"><h2><?= icon('user', '', 18) ?> Profile</h2></div>
            <form method="post" action="<?= e(url('profile/account')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="name">Full name</label>
                        <input type="text" id="name" name="name" class="input-dp <?= has_error('name') ? 'is-invalid-dp' : '' ?>"
                               value="<?= e(old('name') !== '' ? old('name') : (string) $user['name']) ?>" required>
                        <?php if (has_error('name')): ?><p class="field-error"><?= e(error_for('name')) ?></p><?php endif; ?>
                    </div>
                    <div class="form-row mb-0">
                        <label class="form-label-dp" for="email">Email address</label>
                        <input type="email" id="email" name="email" class="input-dp <?= has_error('email') ? 'is-invalid-dp' : '' ?>"
                               value="<?= e(old('email') !== '' ? old('email') : (string) $user['email']) ?>" required>
                        <?php if (has_error('email')): ?>
                            <p class="field-error"><?= e(error_for('email')) ?></p>
                        <?php else: ?>
                            <p class="field-hint">
                                <?php if (!empty($user['email_verified_at'])): ?>
                                    <span class="badge badge-success"><?= icon('check', '', 13) ?> Verified</span>
                                    Changing it will require confirmation again.
                                <?php else: ?>
                                    <span class="badge badge-warning">Not verified</span>
                                    <a href="<?= e(url('email/verify')) ?>">Send confirmation email</a>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-dp__foot">
                    <button type="submit" class="btn-dp btn-primary-dp"><?= icon('check', '', 17) ?> Save changes</button>
                </div>
            </form>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h2><?= icon('lock', '', 18) ?> Password</h2></div>
            <form method="post" action="<?= e(url('profile/password')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="card-dp__body">
                    <?php if ($isGoogleOnly): ?>
                        <div class="alert-dp alert-info-dp">
                            <?= icon('info') ?>
                            <div>You signed up with Google. Set a password here if you would also like to sign in with email.</div>
                        </div>
                    <?php else: ?>
                        <div class="form-row">
                            <label class="form-label-dp" for="current_password">Current password</label>
                            <input type="password" id="current_password" name="current_password" class="input-dp" required autocomplete="current-password">
                        </div>
                    <?php endif; ?>

                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="password">New password</label>
                            <input type="password" id="password" name="password" class="input-dp <?= has_error('password') ? 'is-invalid-dp' : '' ?>"
                                   required autocomplete="new-password">
                            <?php if (has_error('password')): ?><p class="field-error"><?= e(error_for('password')) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label-dp" for="password_confirmation">Confirm new password</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" class="input-dp" required autocomplete="new-password">
                        </div>
                    </div>
                    <p class="field-hint mb-0">At least 8 characters with one letter and one number.</p>
                </div>
                <div class="card-dp__foot">
                    <button type="submit" class="btn-dp btn-primary-dp"><?= icon('lock', '', 17) ?> Update password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-dp">
            <div class="card-dp__head">
                <h3>Plan &amp; usage</h3>
                <span class="badge badge-primary"><?= e((string) $plan['name']) ?></span>
            </div>
            <div class="card-dp__body">
                <div class="usage-row">
                    <div class="usage-row__top">
                        <span>Documents this month</span>
                        <strong data-usage="documents"><?= (int) $summary['documents_used'] ?> / <?= (int) $summary['documents_limit'] ?></strong>
                    </div>
                    <div class="progress-dp <?= $summary['documents_percent'] >= 100 ? 'full' : '' ?>">
                        <span style="width: <?= (float) $summary['documents_percent'] ?>%"></span>
                    </div>
                </div>
                <div class="usage-row">
                    <div class="usage-row__top">
                        <span>AI generations this month</span>
                        <strong data-usage="ai"><?= (int) $summary['ai_used'] ?> / <?= (int) $summary['ai_limit'] ?></strong>
                    </div>
                    <div class="progress-dp <?= $summary['ai_percent'] >= 100 ? 'full' : '' ?>">
                        <span data-usage-bar="ai" style="width: <?= (float) $summary['ai_percent'] ?>%"></span>
                    </div>
                </div>
                <dl class="kv mt-3 mb-0">
                    <dt>Period</dt><dd><?= e(format_date($summary['period'] . '-01', 'F Y')) ?></dd>
                    <dt>Member since</dt><dd><?= e(format_date((string) $user['created_at'])) ?></dd>
                    <?php if (!$plan['is_free']): ?>
                        <dt>Renews</dt><dd><?= e(format_date($summary['renews_at'] ?? null)) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
            <div class="card-dp__foot d-flex gap-2">
                <a href="<?= e(url('billing')) ?>" class="btn-dp btn-outline-dp btn-sm-dp">Billing</a>
                <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-primary-dp btn-sm-dp"><?= icon('zap', '', 15) ?> <?= $plan['is_free'] ? 'Upgrade' : 'Change plan' ?></a>
            </div>
        </div>
    </div>
</div>
