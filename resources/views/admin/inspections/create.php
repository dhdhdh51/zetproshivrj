<?php
/**
 * Start an inspection: which supervisor, which day, and the inspector's own GPS.
 *
 * No work is chosen here. This is the monthly inspection of a BC point and its agent — the
 * Bank's 27-item form asks about the outlet, the registers, the equipment, the earnings and
 * what the villagers say. None of that belongs to one customer visit, so there is nothing
 * to pick.
 *
 * @var array      $supervisors
 * @var array|null $supervisor
 * @var array|null $existingThisMonth  an inspection already recorded for this month, if any
 * @var string     $monthLabel
 * @var array|null $form
 */
?>

<div class="page-head">
    <div class="grow">
        <h1>Start BCA inspection</h1>
        <div class="subtitle">
            The monthly inspection of the BC point and its agent. Your own GPS position is recorded,
            so the visit can be shown to have happened where it says it did.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/inspections')) ?>">Cancel</a>
    </div>
</div>

<div class="steps">
    <div class="step active"><span class="n">1</span> BCA</div>
    <div class="step"><span class="n">2</span> GPS &amp; photos</div>
    <div class="step"><span class="n">3</span> The form</div>
    <div class="step"><span class="n">4</span> Submit</div>
</div>

<?php if ($supervisor === null): ?>
    <div class="card content-narrow">
        <div class="card-head"><h2>Which BCA are you inspecting?</h2></div>
        <form method="get" action="<?= e(url('/admin/inspections/create')) ?>">
            <div class="card-body">
                <div class="field">
                    <label for="bc_supervisor_id">BCA <span class="req">*</span></label>
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

    <?php if ($existingThisMonth !== null): ?>
        <?php
        // Once a month is the expectation, not a rule the software enforces: a follow-up
        // visit after a Poor grade is a real thing that happens. So this says so and offers
        // the existing one, rather than refusing.
        ?>
        <div class="alert alert-warning">
            <?= icon('alert-triangle', '', 17) ?>
            <div>
                <strong><?= e($supervisor['name']) ?></strong> already has an inspection recorded for
                <strong><?= e($monthLabel) ?></strong> —
                <?= e(format_date((string) $existingThisMonth['inspection_date'])) ?>,
                <?= e(enum_label((string) $existingThisMonth['status'])) ?><?php
                    if ($existingThisMonth['result'] !== null) {
                        echo ', ' . e(inspection_result_label((string) $existingThisMonth['result']));
                    }
                ?>.
                This inspection is expected once a month, so open that one unless you deliberately
                need a second visit.
                <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
                    <a class="btn btn-sm" href="<?= e(url(
                        '/admin/inspections/' . (int) $existingThisMonth['id']
                        . ((string) $existingThisMonth['status'] === 'draft' ? '/edit' : '')
                    )) ?>">
                        <?= icon('arrow-right', '', 14) ?>
                        <?= (string) $existingThisMonth['status'] === 'draft'
                            ? 'Carry on with that one'
                            : 'Open that inspection' ?>
                    </a>
                </div>
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
                        <label>Counts towards</label>
                        <div class="v"><strong><?= e($monthLabel) ?></strong></div>
                        <div class="help">
                            Taken from the date on the left. This inspection is expected once a month
                            per BCA.
                        </div>
                    </div>
                </div>

                <!-- Inspector GPS -->
                <fieldset data-gps-capture>
                    <legend>Your location <span class="req">*</span></legend>
                    <p class="help" style="margin-top:0">
                        Capture your position at the BC point. The server validates accuracy
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
