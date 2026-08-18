<?php
/**
 * Customer Visit Report (TYPE A) — one visit in full.
 *
 * @var array $visit
 * @var array $answers
 * @var array $photos
 * @var array $points
 * @var array $recoveries
 * @var array $promises
 * @var array $followups
 * @var array $inspections
 * @var bool  $canReview
 */
$basePath = $canReview ? '/admin' : '/manager';
$visitId = (int) $visit['id'];
?>

<div class="page-head">
    <div class="grow">
        <h1>Customer Visit Report</h1>
        <div class="subtitle">
            <?= e(format_date((string) $visit['visit_date'])) ?> ·
            <?= e($visit['borrower_name']) ?> ·
            <span class="mono"><?= e($visit['account_number']) ?></span> ·
            <span class="<?= e(badge((string) $visit['status'])) ?>"><?= e(enum_label((string) $visit['status'])) ?></span>
            <?php if ((int) $visit['is_late'] === 1): ?>
                <span class="badge badge-warning">submitted after deadline</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url($basePath . '/reports/customer_visit')) ?>"><?= icon('arrow-left', '', 15) ?> All visits</a>
        <a class="btn btn-secondary" href="<?= e(url($basePath . '/visits/' . $visitId . '/pdf')) ?>"><?= icon('download', '', 15) ?> PDF</a>
        <?php if ($canReview): ?>
            <a class="btn" href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . (int) $visit['supervisor_id'] . '&visit_id=' . $visitId)) ?>">
                <?= icon('search-check', '', 15) ?> Inspect this visit
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($inspections !== []): ?>
    <div class="alert <?= inspection_result_is_negative((string) $inspections[0]['result']) ? 'alert-warning' : 'alert-success' ?>">
        <?= icon('search-check', '', 17) ?>
        <div>
            This visit has been inspected <?= count($inspections) ?> time(s). Latest result:
            <strong><?= e(inspection_result_label((string) $inspections[0]['result'])) ?></strong>
            by <?= e($inspections[0]['inspector_name']) ?> on <?= e(format_date((string) $inspections[0]['inspection_date'])) ?>.
            <?php if ($canReview): ?>
                <a href="<?= e(url('/admin/inspections/' . (int) $inspections[0]['id'])) ?>">Open inspection report</a>.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Account and borrower</h2></div>
        <div class="card-body">
            <div class="kv">
                <div><div class="k">Account number</div><div class="v mono"><?= e($visit['account_number']) ?></div></div>
                <div><div class="k">CIF</div><div class="v"><?= e($visit['cif'] ?: '—') ?></div></div>
                <div><div class="k">Borrower</div><div class="v"><?= e($visit['borrower_name']) ?></div></div>
                <div><div class="k">Father / guardian</div><div class="v"><?= e($visit['father_name'] ?: '—') ?></div></div>
                <div><div class="k">Mobile</div><div class="v"><?= e($visit['mobile'] ?: '—') ?></div></div>
                <div><div class="k">Village</div><div class="v"><?= e($visit['village'] ?: '—') ?></div></div>
                <div><div class="k">Loan type</div><div class="v"><?= e($visit['loan_type'] ?: '—') ?></div></div>
                <div><div class="k">NPA date</div><div class="v"><?= e(format_date($visit['npa_date'])) ?></div></div>
                <div><div class="k">Outstanding</div><div class="v"><?= e(money((float) $visit['outstanding'])) ?></div></div>
                <div><div class="k">Overdue</div><div class="v strong"><?= e(money((float) $visit['overdue'])) ?></div></div>
                <div style="grid-column:1/-1"><div class="k">Address</div><div class="v"><?= e($visit['address'] ?: '—') ?></div></div>
            </div>
            <p style="margin:12px 0 0">
                <a class="btn btn-secondary btn-sm" href="<?= e(url($basePath . '/accounts/' . (int) $visit['loan_account_id'])) ?>">
                    Open account history
                </a>
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Visit details</h2></div>
        <div class="card-body">
            <div class="kv">
                <div><div class="k">BC Supervisor</div><div class="v"><?= e($visit['supervisor_name']) ?> (<?= e($visit['bc_code']) ?>)</div></div>
                <div><div class="k">Branch</div><div class="v"><?= e($visit['branch_name']) ?> (<?= e($visit['branch_code']) ?>)</div></div>
                <div><div class="k">Started</div><div class="v"><?= e(format_datetime($visit['started_at'])) ?></div></div>
                <div><div class="k">Submitted</div><div class="v"><?= e(format_datetime($visit['submitted_at'])) ?></div></div>
                <div><div class="k">Received by server</div><div class="v"><?= e(format_datetime($visit['server_received_at'])) ?></div></div>
                <div><div class="k">Visit status</div><div class="v"><?= e(visit_status_label($visit['visit_status'])) ?></div></div>
                <div><div class="k">Recovery possibility</div><div class="v"><?= e(enum_label($visit['recovery_possibility'])) ?></div></div>
                <div><div class="k">Visit type</div><div class="v"><?= e(strtoupper(str_replace('_', ' ', (string) $visit['visit_type']))) ?></div></div>
                <div><div class="k">Device</div><div class="v"><?= e($visit['device_model'] ?: '—') ?> <?= e($visit['app_version'] ? 'v' . $visit['app_version'] : '') ?></div></div>
                <div><div class="k">Form used</div><div class="v"><?= e($visit['form_name'] ?: '—') ?></div></div>
            </div>

            <?php if (!empty($visit['remarks'])): ?>
                <div style="margin-top:12px">
                    <div class="k" style="font-size:11.5px;text-transform:uppercase;color:var(--ink-500);font-weight:650">Remarks</div>
                    <p style="margin:4px 0 0"><?= nl2br(e($visit['remarks'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($visit['recommendation'])): ?>
                <div style="margin-top:12px">
                    <div class="k" style="font-size:11.5px;text-transform:uppercase;color:var(--ink-500);font-weight:650">Recommendation</div>
                    <p style="margin:4px 0 0"><?= nl2br(e($visit['recommendation'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($answers !== []): ?>
    <div class="card">
        <div class="card-head"><h2>Visit form answers</h2><div class="spacer"></div>
            <span class="small muted">As configured in the visit form builder</span>
        </div>
        <div class="table-wrap">
            <table class="data compact">
                <thead><tr><th style="width:45%">Question</th><th>Answer</th></tr></thead>
                <tbody>
                    <?php foreach ($answers as $answer): ?>
                        <tr>
                            <td><?= e($answer['label'] ?: $answer['field_key']) ?></td>
                            <td><?= nl2br(e((string) $answer['value'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h2>GPS trail</h2>
            <div class="spacer"></div>
            <?php if ((int) $visit['gps_verified'] === 1): ?>
                <span class="badge badge-success">verified server-side</span>
            <?php else: ?>
                <span class="badge badge-danger">not verified</span>
            <?php endif; ?>
        </div>
        <?php if ($points === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No GPS points recorded', 'iconName' => 'map-pin']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Event</th><th>Coordinates</th><th class="right">Accuracy</th><th>Captured</th><th class="center">Valid</th></tr></thead>
                    <tbody>
                        <?php foreach ($points as $point): ?>
                            <tr>
                                <td><span class="badge badge-muted"><?= e(enum_label((string) $point['event'])) ?></span></td>
                                <td class="small">
                                    <?php $map = map_link($point['latitude'], $point['longitude']); ?>
                                    <?php if ($map !== null): ?>
                                        <a href="<?= e($map) ?>" target="_blank" rel="noopener"><?= e(coordinates($point['latitude'], $point['longitude'], 6)) ?></a>
                                    <?php else: ?>
                                        <?= e(coordinates($point['latitude'], $point['longitude'], 6)) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($point['address'])): ?>
                                        <div class="tiny muted"><?= e(str_excerpt((string) $point['address'], 46)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="right num small"><?= $point['accuracy'] === null ? '—' : number_format((float) $point['accuracy'], 0) . ' m' ?></td>
                                <td class="small"><?= e(format_datetime($point['captured_at'])) ?></td>
                                <td class="center">
                                    <?= (int) $point['is_valid'] === 1 ? icon('check-circle', 'success-text', 15) : icon('x-circle', 'danger-text', 15) ?>
                                    <?php if (!empty($point['validation_note'])): ?>
                                        <div class="tiny danger-text"><?= e(str_excerpt((string) $point['validation_note'], 30)) ?></div>
                                    <?php endif; ?>
                                    <?php if ((int) $point['is_mock'] === 1): ?>
                                        <div class="tiny danger-text">mock location</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Recovery, PTP and follow-up</h2></div>
        <div class="card-body">
            <?php if ($recoveries === [] && $promises === [] && $followups === []): ?>
                <p class="small muted" style="margin:0">Nothing was recorded against this visit.</p>
            <?php else: ?>
                <table class="data compact">
                    <thead><tr><th>Type</th><th class="right">Amount</th><th>Detail</th><th class="center">Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recoveries as $recovery): ?>
                            <tr>
                                <td>Recovery</td>
                                <td class="right num"><?= e(money((float) $recovery['amount'])) ?></td>
                                <td class="small"><?= e($recovery['payment_mode']) ?><?= $recovery['receipt_number'] ? ' · ' . e($recovery['receipt_number']) : '' ?></td>
                                <td class="center"><span class="<?= e(badge((string) $recovery['status'])) ?>"><?= e(enum_label((string) $recovery['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($promises as $promise): ?>
                            <tr>
                                <td>Promise to pay</td>
                                <td class="right num"><?= e(money((float) $promise['promise_amount'])) ?></td>
                                <td class="small">by <?= e(format_date((string) $promise['promise_date'])) ?></td>
                                <td class="center"><span class="<?= e(badge((string) $promise['status'])) ?>"><?= e(enum_label((string) $promise['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($followups as $followup): ?>
                            <tr>
                                <td>Follow-up</td>
                                <td class="right">—</td>
                                <td class="small"><?= e(enum_label((string) $followup['action'])) ?> on <?= e(format_date((string) $followup['followup_date'])) ?></td>
                                <td class="center"><span class="<?= e(badge((string) $followup['status'])) ?>"><?= e(enum_label((string) $followup['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Photographic evidence</h2>
        <div class="spacer"></div>
        <span class="small muted"><?= count($photos) ?> photo(s), watermarked at capture</span>
    </div>
    <div class="card-body">
        <?php if ($photos === []): ?>
            <p class="small muted" style="margin:0">No photographs were attached to this visit.</p>
        <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($photos as $photo): ?>
                    <figure>
                        <a href="<?= e(url('/files/visit-photo/' . (int) $photo['id'])) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(url('/files/visit-photo/' . (int) $photo['id'])) ?>"
                                 alt="<?= e(photo_types()[$photo['photo_type']] ?? 'Photo') ?>" loading="lazy">
                        </a>
                        <figcaption>
                            <strong><?= e(photo_types()[$photo['photo_type']] ?? ucfirst((string) $photo['photo_type'])) ?></strong><br>
                            <?= e(format_datetime($photo['captured_at'])) ?><br>
                            <?= e(coordinates($photo['latitude'], $photo['longitude'])) ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($visit['borrower_signature']) || !empty($visit['supervisor_signature'])): ?>
            <h3 style="margin-top:18px">Signatures</h3>
            <div class="photo-grid">
                <?php if (!empty($visit['borrower_signature'])): ?>
                    <figure>
                        <img src="<?= e(url('/files/signature/visit/' . $visitId . '/borrower')) ?>" alt="Borrower signature" style="object-fit:contain;background:#fff">
                        <figcaption>Borrower signature</figcaption>
                    </figure>
                <?php endif; ?>
                <?php if (!empty($visit['supervisor_signature'])): ?>
                    <figure>
                        <img src="<?= e(url('/files/signature/visit/' . $visitId . '/supervisor')) ?>" alt="BC Supervisor signature" style="object-fit:contain;background:#fff">
                        <figcaption>BC Supervisor signature</figcaption>
                    </figure>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canReview): ?>
    <div class="card content-narrow">
        <div class="card-head"><h2>Review</h2></div>
        <div class="card-body">
            <?php if ($visit['approver_name'] !== null): ?>
                <p class="small muted">
                    <?= e(enum_label((string) $visit['status'])) ?> by <?= e($visit['approver_name']) ?>
                    on <?= e(format_datetime($visit['approved_at'])) ?>.
                </p>
            <?php endif; ?>

            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <form method="post" action="<?= e(url('/admin/visits/' . $visitId . '/approve')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-success btn-sm"><?= icon('check', '', 15) ?> Approve visit</button>
                </form>

                <form method="post" action="<?= e(url('/admin/visits/' . $visitId . '/reject')) ?>" style="display:flex;gap:8px;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <input type="text" name="reason" placeholder="Reason for rejection" required maxlength="255" style="max-width:300px">
                    <button type="submit" class="btn btn-danger btn-sm"><?= icon('x', '', 15) ?> Reject</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
