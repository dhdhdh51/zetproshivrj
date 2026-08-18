<?php
/**
 * @var array $plans
 * @var int   $active_subscriptions
 */
?>
<div class="page-head">
    <div>
        <h1>Plans</h1>
        <p>Pricing, monthly limits and features. <?= number_format($active_subscriptions) ?> active paid subscription(s).</p>
    </div>
    <button type="button" class="btn-dp btn-outline-dp" data-modal-open="#new-plan-modal"><?= icon('plus', '', 17) ?> New plan</button>
</div>

<?php foreach ($plans as $plan): ?>
    <div class="card-dp">
        <div class="card-dp__head">
            <h2>
                <?= e((string) $plan['name']) ?>
                <span class="badge badge-muted mono"><?= e((string) $plan['slug']) ?></span>
                <?php if ((int) $plan['is_active'] === 1): ?>
                    <span class="badge badge-success">active</span>
                <?php else: ?>
                    <span class="badge badge-danger">inactive</span>
                <?php endif; ?>
            </h2>
            <form method="post" action="<?= e(url('admin/plans/' . (int) $plan['id'] . '/toggle')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn-dp btn-ghost-dp btn-sm-dp">
                    <?= (int) $plan['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                </button>
            </form>
        </div>

        <form method="post" action="<?= e(url('admin/plans/' . (int) $plan['id'])) ?>">
            <?= csrf_field() ?>
            <div class="card-dp__body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <div class="form-grid">
                            <div>
                                <label class="form-label-dp">Display name</label>
                                <input type="text" name="name" class="input-dp" value="<?= e((string) $plan['name']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label-dp">Price</label>
                                <input type="number" step="0.01" min="0" name="price" class="input-dp" value="<?= e(number_format((float) $plan['price'], 2, '.', '')) ?>" required>
                            </div>
                            <div>
                                <label class="form-label-dp">Currency</label>
                                <input type="text" name="currency" class="input-dp" maxlength="3" value="<?= e((string) $plan['currency']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label-dp">Billing interval</label>
                                <select name="billing_interval" class="select-dp">
                                    <option value="monthly" <?= (string) $plan['billing_interval'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    <option value="yearly" <?= (string) $plan['billing_interval'] === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label-dp">Documents / month</label>
                                <input type="number" min="0" name="document_limit" class="input-dp" value="<?= (int) $plan['document_limit'] ?>" required>
                            </div>
                            <div>
                                <label class="form-label-dp">AI generations / month</label>
                                <input type="number" min="0" name="ai_limit" class="input-dp" value="<?= (int) $plan['ai_limit'] ?>" required>
                            </div>
                            <div>
                                <label class="form-label-dp">Sort order</label>
                                <input type="number" min="0" name="sort_order" class="input-dp" value="<?= (int) $plan['sort_order'] ?>">
                            </div>
                            <div>
                                <label class="form-label-dp">Short description</label>
                                <input type="text" name="description" class="input-dp" value="<?= e((string) ($plan['description'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="form-row mt-3 mb-0">
                            <label class="form-label-dp">Marketing features <span class="opt">(one per line)</span></label>
                            <textarea name="features" class="textarea-dp" rows="5"><?= e(implode("\n", $plan['features_list'])) ?></textarea>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="switch-row">
                            <div class="switch-row__text"><strong>All templates</strong><span>Modern, Corporate and Minimal</span></div>
                            <label class="switch">
                                <input type="checkbox" name="all_templates" value="1" <?= $plan['all_templates'] ? 'checked' : '' ?>><span></span>
                            </label>
                        </div>
                        <div class="switch-row">
                            <div class="switch-row__text"><strong>PDF export</strong><span>Download and attach PDFs</span></div>
                            <label class="switch">
                                <input type="checkbox" name="pdf_enabled" value="1" <?= $plan['pdf_enabled'] ? 'checked' : '' ?>><span></span>
                            </label>
                        </div>
                        <div class="switch-row">
                            <div class="switch-row__text"><strong>Email delivery</strong><span>Send documents to clients</span></div>
                            <label class="switch">
                                <input type="checkbox" name="email_enabled" value="1" <?= $plan['email_enabled'] ? 'checked' : '' ?>><span></span>
                            </label>
                        </div>
                        <div class="switch-row">
                            <div class="switch-row__text"><strong>Visible on pricing</strong><span>Show this plan to users</span></div>
                            <label class="switch">
                                <input type="checkbox" name="is_active" value="1" <?= (int) $plan['is_active'] === 1 ? 'checked' : '' ?>><span></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-dp__foot">
                <button type="submit" class="btn-dp btn-primary-dp"><?= icon('check', '', 17) ?> Save <?= e((string) $plan['name']) ?></button>
            </div>
        </form>
    </div>
<?php endforeach; ?>

<div class="modal-dp" id="new-plan-modal" role="dialog" aria-modal="true" aria-label="Create plan">
    <div class="modal-dp__backdrop" data-modal-close></div>
    <div class="modal-dp__panel">
        <div class="modal-dp__head">
            <h3>Create a plan</h3>
            <button type="button" class="btn-dp btn-ghost-dp btn-sm-dp" data-modal-close><?= icon('x', '', 16) ?></button>
        </div>
        <form method="post" action="<?= e(url('admin/plans')) ?>">
            <?= csrf_field() ?>
            <div class="modal-dp__body">
                <div class="form-grid">
                    <div>
                        <label class="form-label-dp">Slug <span class="opt">(letters and numbers)</span></label>
                        <input type="text" name="slug" class="input-dp mono" required placeholder="agency">
                    </div>
                    <div>
                        <label class="form-label-dp">Name</label>
                        <input type="text" name="name" class="input-dp" required placeholder="Agency">
                    </div>
                    <div>
                        <label class="form-label-dp">Price</label>
                        <input type="number" step="0.01" min="0" name="price" class="input-dp" value="1499" required>
                    </div>
                    <div>
                        <label class="form-label-dp">Currency</label>
                        <input type="text" name="currency" class="input-dp" maxlength="3" value="INR">
                    </div>
                    <div>
                        <label class="form-label-dp">Documents / month</label>
                        <input type="number" min="0" name="document_limit" class="input-dp" value="1000" required>
                    </div>
                    <div>
                        <label class="form-label-dp">AI generations / month</label>
                        <input type="number" min="0" name="ai_limit" class="input-dp" value="1000" required>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="check-dp"><input type="checkbox" name="all_templates" value="1" checked><span>All templates</span></label>
                    <label class="check-dp mt-1"><input type="checkbox" name="pdf_enabled" value="1" checked><span>PDF export</span></label>
                    <label class="check-dp mt-1"><input type="checkbox" name="email_enabled" value="1" checked><span>Email delivery</span></label>
                </div>
            </div>
            <div class="modal-dp__foot">
                <button type="button" class="btn-dp btn-ghost-dp" data-modal-close>Cancel</button>
                <button type="submit" class="btn-dp btn-primary-dp">Create plan</button>
            </div>
        </form>
    </div>
</div>
