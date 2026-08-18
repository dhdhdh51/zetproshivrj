<?php
/**
 * Inspection in progress: photographs, questionnaire, result, submit.
 *
 * @var array $inspection
 * @var array $fields
 * @var array $photos
 * @var array $gps
 * @var array $visit_photos
 * @var array $visit_gps
 * @var int   $minPhotos
 */
$id = (int) $inspection['id'];
$distance = null;

foreach ($gps as $point) {
    if ($point['distance_to_visit_metres'] !== null) {
        $distance = (float) $point['distance_to_visit_metres'];
        break;
    }
}
?>

<div class="page-head">
    <div class="grow">
        <h1>Inspection in progress</h1>
        <div class="subtitle">
            <?= e($inspection['supervisor_name']) ?> (<?= e($inspection['bc_code']) ?>) ·
            <?= e($inspection['branch_name']) ?> ·
            started <?= e(format_datetime($inspection['started_at'])) ?>
        </div>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e(url('/admin/inspections/' . $id . '/delete')) ?>"
              data-confirm="Discard this draft inspection? Nothing will be recorded.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">Discard draft</button>
        </form>
    </div>
</div>

<div class="steps">
    <div class="step done"><span class="n"><?= icon('check', '', 12) ?></span> Select work</div>
    <div class="step <?= $photos === [] ? 'active' : 'done' ?>"><span class="n"><?= $photos === [] ? '2' : icon('check', '', 12) ?></span> GPS &amp; photos</div>
    <div class="step active"><span class="n">3</span> Form &amp; result</div>
    <div class="step"><span class="n">4</span> Submit</div>
</div>

<!-- What is being verified -->
<div class="card">
    <div class="card-head"><h2>What you are verifying</h2></div>
    <div class="card-body">
        <div class="kv">
            <div><div class="k">BC Supervisor</div><div class="v"><?= e($inspection['supervisor_name']) ?> (<?= e($inspection['bc_code']) ?>)</div></div>
            <div><div class="k">Inspection date</div><div class="v"><?= e(format_date((string) $inspection['inspection_date'])) ?></div></div>
            <div><div class="k">Account</div><div class="v"><?= e($inspection['account_number'] ?: 'not linked') ?></div></div>
            <div><div class="k">Borrower</div><div class="v"><?= e($inspection['borrower_name'] ?: '—') ?></div></div>
            <div><div class="k">Village</div><div class="v"><?= e($inspection['village'] ?: '—') ?></div></div>
            <div><div class="k">Overdue</div><div class="v"><?= $inspection['overdue'] === null ? '—' : e(money((float) $inspection['overdue'])) ?></div></div>
        </div>

        <?php if ($inspection['visit_id'] !== null): ?>
            <h3 style="margin-top:16px">The visit reported by the BC Supervisor</h3>
            <div class="kv">
                <div><div class="k">Visit date</div><div class="v"><?= e(format_date((string) $inspection['visit_date'])) ?></div></div>
                <div><div class="k">Reported status</div><div class="v"><?= e(visit_status_label($inspection['visit_status'])) ?></div></div>
                <div><div class="k">Photos on the visit</div><div class="v"><?= (int) $inspection['visit_photo_count'] ?></div></div>
                <div>
                    <div class="k">Distance from your point</div>
                    <div class="v">
                        <?php if ($distance === null): ?>
                            <span class="muted">not comparable yet</span>
                        <?php else: ?>
                            <strong class="<?= $distance > 500 ? 'danger-text' : 'success-text' ?>">
                                <?= number_format($distance, 0) ?> m
                            </strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($inspection['visit_remarks'])): ?>
                <p class="small" style="margin-top:10px">
                    <strong>Supervisor's remarks:</strong> <?= e($inspection['visit_remarks']) ?>
                </p>
            <?php endif; ?>

            <?php if ($visit_photos !== []): ?>
                <h3 style="margin-top:14px">Photographs the supervisor submitted</h3>
                <div class="photo-grid">
                    <?php foreach ($visit_photos as $photo): ?>
                        <figure>
                            <a href="<?= e(url('/files/visit-photo/' . (int) $photo['id'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('/files/visit-photo/' . (int) $photo['id'])) ?>" alt="Visit photo" loading="lazy">
                            </a>
                            <figcaption>
                                <?= e(photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type'])) ?><br>
                                <?= e(format_datetime($photo['captured_at'])) ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p style="margin:12px 0 0">
                <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/visits/' . (int) $inspection['visit_id'])) ?>" target="_blank" rel="noopener">
                    Open the full visit report
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>

<!-- GPS status -->
<div class="card">
    <div class="card-head">
        <h2>Your GPS</h2>
        <div class="spacer"></div>
        <?php if ((int) $inspection['gps_verified'] === 1): ?>
            <span class="badge badge-success">captured and valid</span>
        <?php else: ?>
            <span class="badge badge-warning">not captured</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($gps === []): ?>
            <p class="small muted" style="margin:0">
                No position recorded yet. Capture it in the form below before submitting — a field
                inspection without a location cannot be verified later.
            </p>
        <?php else: ?>
            <table class="data compact">
                <thead><tr><th>Event</th><th>Coordinates</th><th class="right">Accuracy</th><th class="right">Distance to visit</th><th class="center">Valid</th></tr></thead>
                <tbody>
                    <?php foreach ($gps as $point): ?>
                        <tr>
                            <td><?= e(enum_label((string) $point['event'])) ?></td>
                            <td class="small">
                                <?php $map = map_link($point['latitude'], $point['longitude']); ?>
                                <?php if ($map !== null): ?>
                                    <a href="<?= e($map) ?>" target="_blank" rel="noopener"><?= e(coordinates($point['latitude'], $point['longitude'], 6)) ?></a>
                                <?php else: ?>
                                    <?= e(coordinates($point['latitude'], $point['longitude'], 6)) ?>
                                <?php endif; ?>
                            </td>
                            <td class="right num small"><?= $point['accuracy'] === null ? '—' : number_format((float) $point['accuracy'], 0) . ' m' ?></td>
                            <td class="right num small"><?= $point['distance_to_visit_metres'] === null ? '—' : number_format((float) $point['distance_to_visit_metres'], 0) . ' m' ?></td>
                            <td class="center"><?= (int) $point['is_valid'] === 1 ? icon('check-circle', 'success-text', 15) : icon('x-circle', 'danger-text', 15) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Photographs -->
<div class="card">
    <div class="card-head">
        <h2>Inspection photographs</h2>
        <div class="spacer"></div>
        <span class="small <?= count($photos) >= $minPhotos ? 'success-text' : 'warning-text' ?>">
            <?= count($photos) ?> of <?= (int) $minPhotos ?> required
        </span>
    </div>
    <div class="card-body">
        <?php if ($photos !== []): ?>
            <div class="photo-grid" style="margin-bottom:14px">
                <?php foreach ($photos as $photo): ?>
                    <figure>
                        <a href="<?= e(url('/files/inspection-photo/' . (int) $photo['id'])) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(url('/files/inspection-photo/' . (int) $photo['id'])) ?>" alt="Inspection photo" loading="lazy">
                        </a>
                        <figcaption>
                            <?= e(inspection_photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type'])) ?><br>
                            <?= e(format_datetime($photo['captured_at'])) ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/admin/inspections/' . $id . '/photos')) ?>" enctype="multipart/form-data"
              data-gps-capture>
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="photo">Photograph <span class="req">*</span></label>
                    <input type="file" id="photo" name="photo" accept="image/*" capture="environment" required>
                    <div class="help">Watermarked with your name, time and coordinates on upload.</div>
                </div>
                <div class="field">
                    <label for="photo_type">Type</label>
                    <select id="photo_type" name="photo_type">
                        <?php foreach (inspection_photo_types() as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="caption">Caption</label>
                    <input type="text" id="caption" name="caption" maxlength="255">
                </div>
            </div>

            <input type="hidden" name="latitude" value="">
            <input type="hidden" name="longitude" value="">
            <input type="hidden" name="accuracy" value="">
            <input type="hidden" name="captured_at" value="">
            <input type="hidden" name="provider" value="">
            <input type="hidden" name="address" value="">

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <button type="button" class="btn btn-secondary btn-sm" data-gps-button><?= icon('map-pin', '', 14) ?> Tag with my location</button>
                <span class="small muted" data-gps-output></span>
            </div>

            <p style="margin:12px 0 0">
                <button type="submit" class="btn btn-sm"><?= icon('camera', '', 15) ?> Upload photograph</button>
            </p>
        </form>
    </div>
</div>

<!-- Questionnaire + result -->
<form method="post" action="<?= e(url('/admin/inspections/' . $id . '/submit')) ?>" data-dynamic-form>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-head">
            <h2>Inspection form</h2>
            <div class="spacer"></div>
            <span class="small muted"><?= e($inspection['form_name'] ?: 'no form configured') ?></span>
        </div>
        <div class="card-body">
            <?php if ($fields === []): ?>
                <p class="small muted">
                    No form fields are configured. You can still record the result and remarks below —
                    or <a href="<?= e(url('/admin/forms/inspection')) ?>">configure the inspection form</a>.
                </p>
            <?php endif; ?>

            <?php foreach ($fields as $field): ?>
                <?php
                $key = (string) $field['field_key'];
                $type = (string) $field['field_type'];
                $required = (int) $field['is_required'] === 1;
                $name = 'form[' . $key . ']';
                $conditionKey = null;

                if ($field['condition_field_id'] !== null) {
                    foreach ($fields as $candidate) {
                        if ((int) $candidate['id'] === (int) $field['condition_field_id']) {
                            $conditionKey = (string) $candidate['field_key'];
                            break;
                        }
                    }
                }

                $wrapperAttributes = '';

                if ($conditionKey !== null) {
                    $wrapperAttributes = sprintf(
                        ' data-condition-field="%s" data-condition-operator="%s" data-condition-value="%s"',
                        e($conditionKey),
                        e((string) $field['condition_operator']),
                        e((string) $field['condition_value'])
                    );
                }
                ?>

                <?php if ($type === 'section'): ?>
                    <h3 style="margin-top:16px;padding-top:12px;border-top:1px solid var(--line)"><?= e($field['label']) ?></h3>
                    <?php if (!empty($field['help_text'])): ?>
                        <p class="help" style="margin-top:-4px"><?= e($field['help_text']) ?></p>
                    <?php endif; ?>
                    <?php continue; ?>
                <?php endif; ?>

                <div class="field" data-field-key="<?= e($key) ?>"<?= $wrapperAttributes ?>>
                    <label for="field_<?= e($key) ?>">
                        <?= e($field['label']) ?><?= $required ? ' <span class="req">*</span>' : '' ?>
                    </label>

                    <?php if ($type === 'yes_no'): ?>
                        <select id="field_<?= e($key) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                            <option value="">Select…</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    <?php elseif (in_array($type, ['dropdown', 'radio'], true)): ?>
                        <select id="field_<?= e($key) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                            <option value="">Select…</option>
                            <?php foreach ($field['option_list'] as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'checkbox'): ?>
                        <?php foreach ($field['option_list'] as $index => $option): ?>
                            <div class="check">
                                <input type="checkbox" id="field_<?= e($key) ?>_<?= $index ?>" name="<?= e($name) ?>[]" value="<?= e($option) ?>">
                                <label for="field_<?= e($key) ?>_<?= $index ?>"><?= e($option) ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif (in_array($type, ['textarea', 'remarks'], true)): ?>
                        <textarea id="field_<?= e($key) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>
                                  placeholder="<?= e($field['placeholder'] ?? '') ?>"></textarea>
                    <?php elseif ($type === 'date'): ?>
                        <input type="date" id="field_<?= e($key) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                    <?php elseif ($type === 'time'): ?>
                        <input type="time" id="field_<?= e($key) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                    <?php elseif (in_array($type, ['number', 'decimal'], true)): ?>
                        <input type="number" step="<?= $type === 'decimal' ? '0.01' : '1' ?>" id="field_<?= e($key) ?>"
                               name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                    <?php elseif (in_array($type, ['photo', 'gps', 'signature'], true)): ?>
                        <p class="help" style="margin:0">
                            Captured above<?= $type === 'signature' ? ' / below' : '' ?> — no typing needed.
                        </p>
                    <?php else: ?>
                        <input type="text" id="field_<?= e($key) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>
                               placeholder="<?= e($field['placeholder'] ?? '') ?>" maxlength="500">
                    <?php endif; ?>

                    <?php if (!empty($field['help_text'])): ?>
                        <div class="help"><?= e($field['help_text']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Verification result</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label for="result">Result <span class="req">*</span></label>
                    <select id="result" name="result" required>
                        <option value="">Select the result…</option>
                        <?php foreach (inspection_results() as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Remarks are mandatory for every result other than "Work Verified".</div>
                </div>
                <div class="field">
                    <label for="followup_required">Follow-up required</label>
                    <select id="followup_required" name="followup_required">
                        <option value="">Decide automatically</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="remarks">Inspector remarks</label>
                    <textarea id="remarks" name="remarks" placeholder="What you observed, what the customer said, and what should happen next."></textarea>
                </div>
            </div>

            <!-- Capture / re-capture GPS at submit time -->
            <fieldset data-gps-capture>
                <legend>Location at submission</legend>
                <input type="hidden" name="gps[latitude]" value="">
                <input type="hidden" name="gps[longitude]" value="">
                <input type="hidden" name="gps[accuracy]" value="">
                <input type="hidden" name="gps[captured_at]" value="">
                <input type="hidden" name="gps[provider]" value="">
                <div class="gps-box" style="margin-bottom:0">
                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                        <button type="button" class="btn btn-secondary btn-sm" data-gps-button>
                            <?= icon('map-pin', '', 15) ?> Capture location
                        </button>
                        <span class="small muted" data-gps-output>
                            <?= $gps === [] ? 'Required: no position recorded yet.' : 'Optional: a start position is already recorded.' ?>
                        </span>
                    </div>
                </div>
            </fieldset>

            <div class="grid grid-2" style="margin-top:14px">
                <div>
                    <label>Inspector signature</label>
                    <canvas class="signature" data-target="#inspector_signature" data-clear="#clear_inspector"></canvas>
                    <input type="hidden" id="inspector_signature" name="inspector_signature" value="">
                    <button type="button" class="btn btn-link btn-sm" id="clear_inspector">Clear</button>
                </div>
                <div>
                    <label>BC Supervisor signature (if present)</label>
                    <canvas class="signature" data-target="#bc_signature" data-clear="#clear_bc"></canvas>
                    <input type="hidden" id="bc_signature" name="bc_signature" value="">
                    <button type="button" class="btn btn-link btn-sm" id="clear_bc">Clear</button>
                </div>
            </div>
        </div>

        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('check', '', 15) ?> Submit inspection report</button>
            <span class="small muted">
                Once submitted the inspection becomes part of the audit record and cannot be deleted.
            </span>
        </div>
    </div>
</form>
