<?php
/**
 * Start an inspection: who, what, and the inspector's own GPS.
 *
 * @var array      $supervisors
 * @var array|null $supervisor
 * @var array      $visits
 * @var array      $accounts
 * @var int        $selectedVisitId
 * @var int        $selectedAccountId
 * @var array|null $form
 */
?>

<div class="page-head">
    <div class="grow">
        <h1>Start BC supervisor inspection</h1>
        <div class="subtitle">
            Verification of field work. Your own GPS position is recorded so the inspection can be
            compared with what the BC Supervisor reported.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/inspections')) ?>">Cancel</a>
    </div>
</div>

<div class="steps">
    <div class="step active"><span class="n">1</span> Select work</div>
    <div class="step"><span class="n">2</span> GPS &amp; photos</div>
    <div class="step"><span class="n">3</span> Form &amp; result</div>
    <div class="step"><span class="n">4</span> Submit</div>
</div>

<?php if ($supervisor === null): ?>
    <div class="card content-narrow">
        <div class="card-head"><h2>Which BC Supervisor are you inspecting?</h2></div>
        <form method="get" action="<?= e(url('/admin/inspections/create')) ?>">
            <div class="card-body">
                <div class="field">
                    <label for="bc_supervisor_id">BC Supervisor <span class="req">*</span></label>
                    <select id="bc_supervisor_id" name="bc_supervisor_id" required>
                        <option value="">Select…</option>
                        <?php foreach ($supervisors as $option): ?>
                            <option value="<?= (int) $option['id'] ?>">
                                <?= e($option['name']) ?> (<?= e($option['bc_code']) ?>) — <?= e($option['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="card-foot">
                <button type="submit" class="btn"><?= icon('arrow-right', '', 15) ?> Continue</button>
            </div>
        </form>
    </div>
<?php else: ?>
    <?php if ($form === null): ?>
        <div class="alert alert-warning">
            <?= icon('alert-triangle', '', 17) ?>
            <div>
                No active inspection form is configured, so the questionnaire will be empty.
                <a href="<?= e(url('/admin/forms/inspection')) ?>">Configure the inspection form</a>.
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/inspections')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="bc_supervisor_id" value="<?= (int) $supervisor['id'] ?>">

        <div class="card">
            <div class="card-head">
                <h2>Inspecting <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>)</h2>
                <div class="spacer"></div>
                <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/inspections/supervisor/' . (int) $supervisor['id'])) ?>">
                    View their full work picture
                </a>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="inspection_date">Inspection date <span class="req">*</span></label>
                        <input type="date" id="inspection_date" name="inspection_date" value="<?= e(today()) ?>" max="<?= e(today()) ?>" required>
                    </div>

                    <div class="field">
                        <label for="visit_id">Visit being verified</label>
                        <select id="visit_id" name="visit_id">
                            <option value="">— not verifying a specific visit —</option>
                            <?php foreach ($visits as $visit): ?>
                                <option value="<?= (int) $visit['id'] ?>" <?= $selectedVisitId === (int) $visit['id'] ? 'selected' : '' ?>>
                                    <?= e(format_date((string) $visit['visit_date'], 'd M')) ?> ·
                                    <?= e($visit['account_number']) ?> ·
                                    <?= e(str_excerpt((string) $visit['borrower_name'], 20)) ?> ·
                                    <?= e(visit_status_label($visit['visit_status'])) ?>
                                    <?= (int) $visit['inspected'] > 0 ? ' (already inspected)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">
                            Choosing a visit lets LRMS compute the distance between your position and the
                            point the supervisor recorded.
                        </div>
                    </div>

                    <div class="field span-2">
                        <label for="loan_account_id">Account / customer being checked</label>
                        <select id="loan_account_id" name="loan_account_id">
                            <option value="">— select an allocated account —</option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>" <?= $selectedAccountId === (int) $account['id'] ? 'selected' : '' ?>>
                                    <?= e($account['account_number']) ?> ·
                                    <?= e(str_excerpt((string) $account['borrower_name'], 24)) ?> ·
                                    <?= e($account['village'] ?: 'no village') ?> ·
                                    overdue <?= e(money((float) $account['overdue'])) ?>
                                    <?= (int) $account['visit_count'] === 0 ? ' (never visited)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Filled in automatically when you pick a visit above.</div>
                    </div>
                </div>

                <!-- Inspector GPS -->
                <fieldset data-gps-capture>
                    <legend>Your location <span class="req">*</span></legend>
                    <p class="help" style="margin-top:0">
                        Capture your position at the customer's location. The server validates accuracy
                        and rejects mock locations.
                    </p>

                    <input type="hidden" name="gps[latitude]" value="">
                    <input type="hidden" name="gps[longitude]" value="">
                    <input type="hidden" name="gps[accuracy]" value="">
                    <input type="hidden" name="gps[captured_at]" value="">
                    <input type="hidden" name="gps[provider]" value="">

                    <div class="gps-box">
                        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                            <button type="button" class="btn btn-secondary btn-sm" data-gps-button>
                                <?= icon('map-pin', '', 15) ?> Capture my location
                            </button>
                            <span class="small muted" data-gps-output>Not captured yet.</span>
                        </div>
                    </div>

                    <div class="field">
                        <label for="gps_address">Location description</label>
                        <input type="text" id="gps_address" name="gps[address]" maxlength="255"
                               placeholder="Village, landmark or address where you are standing">
                    </div>
                </fieldset>
            </div>

            <div class="card-foot">
                <button type="submit" class="btn"><?= icon('search-check', '', 15) ?> Start inspection</button>
                <span class="small muted">You will add photographs and complete the questionnaire next.</span>
            </div>
        </div>
    </form>
<?php endif; ?>
