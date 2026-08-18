<?php
/**
 * BC SUPERVISOR INSPECTION REPORT (TYPE B) — deliberately separate from the
 * customer Visit Report.
 *
 * @var array $inspection
 * @var array $answers
 * @var array $photos
 * @var array $gps
 * @var array $visit_photos
 * @var array $visit_gps
 */
$id = (int) $inspection['id'];
$distance = null;

foreach ($gps as $point) {
    if ($point['distance_to_visit_metres'] !== null) {
        $distance = (float) $point['distance_to_visit_metres'];
        break;
    }
}

$negative = inspection_result_is_negative((string) $inspection['result']);
?>

<div class="page-head">
    <div class="grow">
        <h1>BC Supervisor Inspection Report</h1>
        <div class="subtitle">
            Inspection <span class="mono">#<?= $id ?></span> ·
            <?= e(format_date((string) $inspection['inspection_date'])) ?> ·
            <?= e($inspection['supervisor_name']) ?> (<?= e($inspection['bc_code']) ?>) ·
            <span class="<?= e(badge((string) $inspection['result'])) ?>"><?= e(inspection_result_label($inspection['result'])) ?></span>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/reports/bc_inspection')) ?>"><?= icon('arrow-left', '', 15) ?> Register</a>
        <a class="btn btn-secondary" href="<?= e(url('/admin/inspections/' . $id . '/pdf')) ?>"><?= icon('download', '', 15) ?> PDF</a>
        <a class="btn" href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . (int) $inspection['bc_supervisor_id'])) ?>">
            <?= icon('plus', '', 15) ?> New inspection
        </a>
    </div>
</div>

<div class="alert <?= $negative ? 'alert-warning' : 'alert-success' ?>">
    <?= icon($negative ? 'alert-triangle' : 'check-circle', '', 17) ?>
    <div>
        <strong><?= e(inspection_result_label($inspection['result'])) ?></strong>
        — inspected by <?= e($inspection['inspector_name']) ?>
        <?php if (!empty($inspection['inspector_code'])): ?>(<?= e($inspection['inspector_code']) ?>)<?php endif; ?>
        on <?= e(format_datetime($inspection['submitted_at'])) ?>.
        <?php if ((int) $inspection['followup_required'] === 1): ?>
            Follow-up is required.
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Inspection</h2></div>
        <div class="card-body">
            <div class="kv">
                <div><div class="k">Inspection ID</div><div class="v mono">#<?= $id ?></div></div>
                <div><div class="k">Reference</div><div class="v mono tiny"><?= e($inspection['uuid']) ?></div></div>
                <div><div class="k">Admin / Supervisor</div><div class="v"><?= e($inspection['inspector_name']) ?></div></div>
                <div><div class="k">BC Supervisor</div><div class="v"><?= e($inspection['supervisor_name']) ?> (<?= e($inspection['bc_code']) ?>)</div></div>
                <div><div class="k">Branch</div><div class="v"><?= e($inspection['branch_name']) ?> (<?= e($inspection['branch_code']) ?>)</div></div>
                <div><div class="k">Date</div><div class="v"><?= e(format_date((string) $inspection['inspection_date'])) ?></div></div>
                <div><div class="k">Started</div><div class="v"><?= e(format_datetime($inspection['started_at'])) ?></div></div>
                <div><div class="k">Submitted</div><div class="v"><?= e(format_datetime($inspection['submitted_at'])) ?></div></div>
                <div><div class="k">Form used</div><div class="v"><?= e($inspection['form_name'] ?: '—') ?></div></div>
                <div><div class="k">Photographs</div><div class="v"><?= (int) $inspection['photo_count'] ?></div></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Account and borrower</h2></div>
        <div class="card-body">
            <?php if ($inspection['account_number'] === null): ?>
                <p class="small muted" style="margin:0">
                    This inspection was not linked to a specific account — it checked the supervisor's
                    general field activity.
                </p>
            <?php else: ?>
                <div class="kv">
                    <div><div class="k">Account</div><div class="v mono"><?= e($inspection['account_number']) ?></div></div>
                    <div><div class="k">CIF</div><div class="v"><?= e($inspection['cif'] ?: '—') ?></div></div>
                    <div><div class="k">Borrower</div><div class="v"><?= e($inspection['borrower_name']) ?></div></div>
                    <div><div class="k">Father / guardian</div><div class="v"><?= e($inspection['father_name'] ?: '—') ?></div></div>
                    <div><div class="k">Village</div><div class="v"><?= e($inspection['village'] ?: '—') ?></div></div>
                    <div><div class="k">Loan type</div><div class="v"><?= e($inspection['loan_type'] ?: '—') ?></div></div>
                    <div><div class="k">Outstanding</div><div class="v"><?= e(money((float) $inspection['outstanding'])) ?></div></div>
                    <div><div class="k">Overdue</div><div class="v"><?= e(money((float) $inspection['overdue'])) ?></div></div>
                </div>
                <p style="margin:12px 0 0">
                    <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/accounts/' . (int) $inspection['loan_account_id'])) ?>">Open account</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($inspection['visit_id'] !== null): ?>
    <div class="card">
        <div class="card-head">
            <h2>BC Supervisor visit that was verified</h2>
            <div class="spacer"></div>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/visits/' . (int) $inspection['visit_id'])) ?>">Open visit report</a>
        </div>
        <div class="card-body">
            <div class="kv">
                <div><div class="k">Visit date</div><div class="v"><?= e(format_date((string) $inspection['visit_date'])) ?></div></div>
                <div><div class="k">Submitted</div><div class="v"><?= e(format_datetime($inspection['visit_submitted_at'])) ?></div></div>
                <div><div class="k">Reported status</div><div class="v"><?= e(visit_status_label($inspection['visit_status'])) ?></div></div>
                <div><div class="k">Visit photographs</div><div class="v"><?= (int) $inspection['visit_photo_count'] ?></div></div>
                <div>
                    <div class="k">Distance: inspector vs supervisor point</div>
                    <div class="v">
                        <?php if ($distance === null): ?>
                            <span class="muted">not comparable</span>
                        <?php else: ?>
                            <strong class="<?= $distance > 500 ? 'danger-text' : 'success-text' ?>"><?= number_format($distance, 0) ?> m</strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($inspection['visit_remarks'])): ?>
                <p class="small" style="margin-top:12px"><strong>Supervisor's remarks:</strong> <?= e($inspection['visit_remarks']) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($answers !== []): ?>
    <div class="card">
        <div class="card-head"><h2>Inspection questionnaire</h2></div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th style="width:55%">Question</th><th>Answer</th></tr></thead>
                <tbody>
                    <?php foreach ($answers as $answer): ?>
                        <tr>
                            <td><?= e($answer['label'] ?: $answer['field_key']) ?></td>
                            <td>
                                <?php $value = (string) $answer['value']; ?>
                                <?php if (strcasecmp($value, 'Yes') === 0): ?>
                                    <span class="badge badge-success">Yes</span>
                                <?php elseif (strcasecmp($value, 'No') === 0): ?>
                                    <span class="badge badge-danger">No</span>
                                <?php else: ?>
                                    <?= nl2br(e($value)) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Inspector remarks</h2></div>
    <div class="card-body">
        <p style="margin:0"><?= $inspection['remarks'] !== null ? nl2br(e($inspection['remarks'])) : '<span class="muted">No remarks recorded.</span>' ?></p>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h2>Inspector GPS</h2>
            <div class="spacer"></div>
            <?php if ((int) $inspection['gps_verified'] === 1): ?>
                <span class="badge badge-success">validated</span>
            <?php else: ?>
                <span class="badge badge-warning">not validated</span>
            <?php endif; ?>
        </div>
        <?php if ($gps === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No inspector position recorded', 'iconName' => 'map-pin']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Event</th><th>Coordinates</th><th class="right">Accuracy</th><th>Captured</th><th class="center">Valid</th></tr></thead>
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
                                    <?php if (!empty($point['address'])): ?>
                                        <div class="tiny muted"><?= e(str_excerpt((string) $point['address'], 40)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="right num small"><?= $point['accuracy'] === null ? '—' : number_format((float) $point['accuracy'], 0) . ' m' ?></td>
                                <td class="small"><?= e(format_datetime($point['captured_at'])) ?></td>
                                <td class="center"><?= (int) $point['is_valid'] === 1 ? icon('check-circle', 'success-text', 15) : icon('x-circle', 'danger-text', 15) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Signatures</h2></div>
        <div class="card-body">
            <?php if (empty($inspection['inspector_signature']) && empty($inspection['bc_signature'])): ?>
                <p class="small muted" style="margin:0">No signatures were captured for this inspection.</p>
            <?php else: ?>
                <div class="photo-grid">
                    <?php if (!empty($inspection['inspector_signature'])): ?>
                        <figure>
                            <img src="<?= e(url('/files/signature/inspection/' . $id . '/inspector')) ?>" alt="Inspector signature" style="object-fit:contain;background:#fff">
                            <figcaption>Inspector — <?= e($inspection['inspector_name']) ?></figcaption>
                        </figure>
                    <?php endif; ?>
                    <?php if (!empty($inspection['bc_signature'])): ?>
                        <figure>
                            <img src="<?= e(url('/files/signature/inspection/' . $id . '/bc')) ?>" alt="BC Supervisor signature" style="object-fit:contain;background:#fff">
                            <figcaption>BC Supervisor — <?= e($inspection['supervisor_name']) ?></figcaption>
                        </figure>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Inspection photographs</h2></div>
    <div class="card-body">
        <?php if ($photos === []): ?>
            <p class="small muted" style="margin:0">No photographs.</p>
        <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($photos as $photo): ?>
                    <figure>
                        <a href="<?= e(url('/files/inspection-photo/' . (int) $photo['id'])) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(url('/files/inspection-photo/' . (int) $photo['id'])) ?>" alt="Inspection photo" loading="lazy">
                        </a>
                        <figcaption>
                            <strong><?= e(inspection_photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type'])) ?></strong><br>
                            <?= e(format_datetime($photo['captured_at'])) ?><br>
                            <?= e(coordinates($photo['latitude'], $photo['longitude'])) ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($visit_photos !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2>Photographs submitted by the BC Supervisor</h2>
            <div class="spacer"></div>
            <span class="small muted">For comparison with the inspection evidence above</span>
        </div>
        <div class="card-body">
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
        </div>
    </div>
<?php endif; ?>
