<?php
/**
 * @var array $account
 * @var array $visits
 * @var array $recoveries
 * @var array $promises
 * @var array $followups
 * @var array $assignments
 * @var array|null $krmCase
 * @var array|null $ckccCase
 * @var array $supervisors
 * @var bool  $canManage
 */
$basePath = $canManage ? '/admin' : '/manager';
?>

<div class="page-head">
    <div class="grow">
        <h1><?= e($account['borrower_name']) ?></h1>
        <div class="subtitle">
            <span class="mono"><?= e($account['account_number']) ?></span>
            <?php if (!empty($account['cif'])): ?> · CIF <?= e($account['cif']) ?><?php endif; ?>
            · <?= e($account['branch_name']) ?> (<?= e($account['branch_code']) ?>)
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url($basePath . '/accounts')) ?>"><?= icon('arrow-left', '', 15) ?> Back</a>
        <?php if ($canManage && $account['bc_supervisor_id'] !== null): ?>
            <a class="btn btn-secondary" href="<?= e(url('/admin/inspections/create?bc_supervisor_id=' . (int) $account['bc_supervisor_id'])) ?>">
                <?= icon('search-check', '', 15) ?> Inspect this BC point
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label">Outstanding</div>
        <div class="value sm"><?= e(money((float) $account['outstanding'])) ?></div>
        <div class="meta">Limit <?= e(money((float) $account['limit_amount'])) ?></div>
    </div>
    <div class="stat bad">
        <div class="label">Overdue</div>
        <div class="value sm"><?= e(money((float) $account['overdue'])) ?></div>
        <div class="meta">NPA <?= e(format_date($account['npa_date'])) ?></div>
    </div>
    <div class="stat good">
        <div class="label">Recovered</div>
        <div class="value sm"><?= e(money((float) $account['total_recovered'])) ?></div>
        <div class="meta"><?= count($recoveries) ?> entry(ies)</div>
    </div>
    <div class="stat">
        <div class="label">Visits</div>
        <div class="value"><?= (int) $account['visit_count'] ?></div>
        <div class="meta">Last <?= e($account['last_visit_at'] ? time_ago((string) $account['last_visit_at']) : 'never') ?></div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Borrower and loan</h2></div>
        <div class="card-body">
            <div class="kv">
                <div><div class="k">Father / guardian</div><div class="v"><?= e($account['father_name'] ?: '—') ?></div></div>
                <div><div class="k">Mobile</div><div class="v"><?= e($account['mobile'] ?: '—') ?></div></div>
                <div><div class="k">Village</div><div class="v"><?= e($account['village'] ?: '—') ?></div></div>
                <div><div class="k">Loan type</div><div class="v"><?= e($account['loan_type'] ?: '—') ?></div></div>
                <div><div class="k">Sanctioned</div><div class="v"><?= e(format_date($account['sanction_date'])) ?></div></div>
                <div><div class="k">Work stream</div><div class="v"><?= e(strtoupper(str_replace('_', ' ', (string) $account['loan_category']))) ?></div></div>
                <div><div class="k">Recovery status</div><div class="v"><span class="<?= e(badge((string) $account['recovery_status'])) ?>"><?= e(enum_label((string) $account['recovery_status'])) ?></span></div></div>
                <div><div class="k">Account status</div><div class="v"><span class="<?= e(badge((string) $account['status'])) ?>"><?= e(enum_label((string) $account['status'])) ?></span></div></div>
                <div class="span-2" style="grid-column:1/-1"><div class="k">Address</div><div class="v"><?= e($account['address'] ?: '—') ?></div></div>
            </div>
            <?php if (!empty($account['import_name'])): ?>
                <p class="tiny muted" style="margin:10px 0 0">
                    Imported from <?= e($account['import_name']) ?>
                    <?php if (!empty($account['bc_code_raw'])): ?> · sheet BC code <span class="mono"><?= e($account['bc_code_raw']) ?></span><?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Allocation</h2></div>
        <div class="card-body">
            <?php if ($account['supervisor_name'] !== null): ?>
                <div class="kv">
                    <div><div class="k">BC Supervisor</div><div class="v"><?= e($account['supervisor_name']) ?></div></div>
                    <div><div class="k">BC code</div><div class="v"><?= e($account['bc_code']) ?></div></div>
                    <div><div class="k">Allocated</div><div class="v"><?= e(format_datetime($account['assigned_at'])) ?></div></div>
                    <div><div class="k">Method</div><div class="v"><?= e(enum_label((string) $account['allocation_method'])) ?></div></div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning" style="margin:0">
                    <?= icon('alert-triangle', '', 16) ?>
                    <div>This account has no BC Supervisor. It will not appear on any device until allocated.</div>
                </div>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <form method="post" action="<?= e(url('/admin/accounts/' . (int) $account['id'] . '/reassign')) ?>" style="margin-top:14px">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="bc_supervisor_id">Reassign to</label>
                        <select id="bc_supervisor_id" name="bc_supervisor_id" required>
                            <option value="">Select BC Supervisor…</option>
                            <?php foreach ($supervisors as $supervisor): ?>
                                <option value="<?= (int) $supervisor['id'] ?>"
                                    <?= (int) $supervisor['id'] === (int) ($account['bc_supervisor_id'] ?? 0) ? 'disabled' : '' ?>>
                                    <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Only supervisors of this branch are listed.</div>
                    </div>
                    <div class="field">
                        <label for="reason">Reason <span class="req">*</span></label>
                        <input type="text" id="reason" name="reason" required maxlength="255"
                               placeholder="e.g. workload rebalance, supervisor on leave">
                    </div>
                    <button type="submit" class="btn btn-sm"><?= icon('layers', '', 15) ?> Reassign</button>

                    <?php if ($account['bc_supervisor_id'] !== null): ?>
                        <button type="submit" class="btn btn-secondary btn-sm"
                                formaction="<?= e(url('/admin/accounts/' . (int) $account['id'] . '/unassign')) ?>"
                                formnovalidate>Remove allocation</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Visit history: TYPE A work -->
<div class="card">
    <div class="card-head">
        <h2>Customer visits</h2>
        <div class="spacer"></div>
        <span class="small muted">Performed by BC Supervisors</span>
    </div>
    <?php if ($visits === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No visits recorded yet', 'iconName' => 'clipboard']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compact">
                <thead>
                    <tr>
                        <th>Date</th><th>BC Supervisor</th><th>Status</th><th>Possibility</th>
                        <th class="center">Photos</th><th class="center">GPS</th><th class="center">Inspected</th>
                        <th>Remarks</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visits as $visit): ?>
                        <tr>
                            <td class="nowrap"><?= e(format_date((string) $visit['visit_date'])) ?>
                                <?php if ((int) $visit['is_late'] === 1): ?><div class="tiny warning-text">late</div><?php endif; ?>
                            </td>
                            <td class="small"><?= e($visit['supervisor_name']) ?><div class="tiny muted"><?= e($visit['bc_code']) ?></div></td>
                            <td><span class="badge badge-muted"><?= e(visit_status_label($visit['visit_status'])) ?></span></td>
                            <td class="small"><?= e(enum_label($visit['recovery_possibility'])) ?></td>
                            <td class="center num"><?= (int) $visit['photos'] ?></td>
                            <td class="center"><?= (int) $visit['gps_verified'] === 1 ? icon('check-circle', 'success-text', 15) : icon('x-circle', 'danger-text', 15) ?></td>
                            <td class="center"><?= (int) $visit['inspections'] > 0 ? icon('check-circle', 'success-text', 15) : '<span class="muted tiny">—</span>' ?></td>
                            <td class="small"><?= e(str_excerpt((string) $visit['remarks'], 60)) ?></td>
                            <td><a class="btn btn-link btn-sm" href="<?= e(url($basePath . '/visits/' . (int) $visit['id'])) ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Recovery entries</h2></div>
        <?php if ($recoveries === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No recovery recorded', 'iconName' => 'rupee']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Date</th><th class="right">Amount</th><th>Mode</th><th>Receipt</th><th class="center">Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recoveries as $recovery): ?>
                            <tr>
                                <td><?= e(format_date((string) $recovery['recovery_date'])) ?></td>
                                <td class="right num"><?= e(money((float) $recovery['amount'])) ?></td>
                                <td class="small"><?= e($recovery['payment_mode']) ?></td>
                                <td class="small mono"><?= e($recovery['receipt_number'] ?: '—') ?></td>
                                <td class="center"><span class="<?= e(badge((string) $recovery['status'])) ?>"><?= e(enum_label((string) $recovery['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Promises to pay</h2></div>
        <?php if ($promises === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No promises recorded', 'iconName' => 'calendar']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Promise date</th><th class="right">Amount</th><th class="right">Paid</th><th class="center">Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($promises as $promise): ?>
                            <tr>
                                <td><?= e(format_date((string) $promise['promise_date'])) ?></td>
                                <td class="right num"><?= e(money((float) $promise['promise_amount'])) ?></td>
                                <td class="right num"><?= e(money((float) $promise['kept_amount'])) ?></td>
                                <td class="center"><span class="<?= e(badge((string) $promise['status'])) ?>"><?= e(enum_label((string) $promise['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
    <div class="grid grid-2">
        <!-- KRM OTS -->
        <div class="card">
            <div class="card-head"><h2>KRM OTS</h2><div class="spacer"></div>
                <?php if ($krmCase !== null): ?>
                    <span class="<?= e(badge((string) $krmCase['ots_status'])) ?>"><?= e(App\Services\KrmOts::STATUSES[$krmCase['ots_status']] ?? '') ?></span>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('/admin/accounts/' . (int) $account['id'] . '/krm-ots')) ?>">
                <?= csrf_field() ?>
                <div class="card-body">
                    <p class="muted small">Section 4, 9 and 13 of the KRM OTS field visit verification report.</p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="ots_eligible">Eligible for KRM OTS</label>
                            <select id="ots_eligible" name="ots_eligible">
                                <option value="">—</option>
                                <option value="yes" <?= ($krmCase['ots_eligible'] ?? null) !== null && (int) $krmCase['ots_eligible'] === 1 ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= ($krmCase['ots_eligible'] ?? null) !== null && (int) $krmCase['ots_eligible'] === 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="scheme">Applicable scheme</label>
                            <select id="scheme" name="scheme">
                                <?php foreach (App\Services\KrmOts::SCHEMES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($krmCase['scheme'] ?? 'krm_ots') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="ots_amount">Proposed settlement</label>
                            <input type="text" id="ots_amount" name="ots_amount" value="<?= e($krmCase['ots_amount'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="borrower_share">Borrower's share</label>
                            <input type="text" id="borrower_share" name="borrower_share" value="<?= e($krmCase['borrower_share'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="initial_deposit_required">Initial deposit required</label>
                            <input type="text" id="initial_deposit_required" name="initial_deposit_required" value="<?= e($krmCase['initial_deposit_required'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="paid_amount">Paid so far</label>
                            <input type="text" id="paid_amount" name="paid_amount" value="<?= e($krmCase['paid_amount'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="customer_response">Customer response</label>
                            <select id="customer_response" name="customer_response">
                                <option value="">—</option>
                                <?php foreach (App\Services\KrmOts::CUSTOMER_RESPONSES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($krmCase['customer_response'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="promise_date">Expected deposit date</label>
                            <input type="date" id="promise_date" name="promise_date" value="<?= e($krmCase['promise_date'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="ots_status">Case status</label>
                            <select id="ots_status" name="ots_status">
                                <?php foreach (App\Services\KrmOts::STATUSES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($krmCase['ots_status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="krm_recommendation">Recommendation</label>
                            <select id="krm_recommendation" name="recommendation">
                                <option value="">—</option>
                                <?php foreach (App\Services\KrmOts::RECOMMENDATIONS as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($krmCase['recommendation'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field span-2">
                            <label for="krm_final_status">Final report status</label>
                            <select id="krm_final_status" name="final_status">
                                <option value="">—</option>
                                <?php foreach (App\Services\KrmOts::FINAL_STATUSES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($krmCase['final_status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field span-2">
                            <label for="krm_remarks">Remarks</label>
                            <input type="text" id="krm_remarks" name="remarks" value="<?= e($krmCase['remarks'] ?? '') ?>" maxlength="500">
                        </div>
                    </div>
                </div>
                <div class="card-foot"><button type="submit" class="btn btn-sm">Save OTS case</button></div>
            </form>
        </div>

        <!-- CKCC OD-2 -->
        <div class="card">
            <div class="card-head"><h2>CKCC OD-2 renewal</h2><div class="spacer"></div>
                <?php if ($ckccCase !== null): ?>
                    <span class="<?= e(badge((string) $ckccCase['renewal_status'])) ?>"><?= e(App\Services\CkccRenewals::STATUSES[$ckccCase['renewal_status']] ?? '') ?></span>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('/admin/accounts/' . (int) $account['id'] . '/ckcc')) ?>">
                <?= csrf_field() ?>
                <div class="card-body">
                    <p class="muted small">Section 5, 9 and 13 of the CKCC OD-2 renewal field visit verification report.</p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="renewal_eligible">Eligible for renewal</label>
                            <select id="renewal_eligible" name="renewal_eligible">
                                <option value="">&mdash;</option>
                                <option value="yes" <?= ($ckccCase['renewal_eligible'] ?? null) !== null && (int) $ckccCase['renewal_eligible'] === 1 ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= ($ckccCase['renewal_eligible'] ?? null) !== null && (int) $ckccCase['renewal_eligible'] === 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="renewal_due_bucket">Renewal due</label>
                            <select id="renewal_due_bucket" name="renewal_due_bucket">
                                <option value="">&mdash;</option>
                                <?php foreach (App\Services\CkccRenewals::DUE_BUCKETS as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['renewal_due_bucket'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="renewal_due_date">Renewal due date</label>
                            <input type="date" id="renewal_due_date" name="renewal_due_date" value="<?= e($ckccCase['renewal_due_date'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="expected_npa_date">Expected NPA date</label>
                            <input type="date" id="expected_npa_date" name="expected_npa_date" value="<?= e($ckccCase['expected_npa_date'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="days_remaining">Days remaining</label>
                            <input type="number" id="days_remaining" name="days_remaining" value="<?= e($ckccCase['days_remaining'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="kyc_status">KYC status</label>
                            <select id="kyc_status" name="kyc_status">
                                <option value="">&mdash;</option>
                                <?php foreach (App\Services\CkccRenewals::KYC_STATUSES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['kyc_status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="aadhaar_seeded">Aadhaar seeded</label>
                            <select id="aadhaar_seeded" name="aadhaar_seeded">
                                <option value="">&mdash;</option>
                                <option value="yes" <?= ($ckccCase['aadhaar_seeded'] ?? null) !== null && (int) $ckccCase['aadhaar_seeded'] === 1 ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= ($ckccCase['aadhaar_seeded'] ?? null) !== null && (int) $ckccCase['aadhaar_seeded'] === 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="mobile_linked">Mobile linked</label>
                            <select id="mobile_linked" name="mobile_linked">
                                <option value="">&mdash;</option>
                                <option value="yes" <?= ($ckccCase['mobile_linked'] ?? null) !== null && (int) $ckccCase['mobile_linked'] === 1 ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= ($ckccCase['mobile_linked'] ?? null) !== null && (int) $ckccCase['mobile_linked'] === 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="aadhaar_authentication">Aadhaar authentication</label>
                            <select id="aadhaar_authentication" name="aadhaar_authentication">
                                <option value="">&mdash;</option>
                                <?php foreach (App\Services\CkccRenewals::AUTHENTICATION as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['aadhaar_authentication'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="renewal_consent">Borrower willing to renew</label>
                            <select id="renewal_consent" name="renewal_consent">
                                <option value="">&mdash;</option>
                                <option value="yes" <?= ($ckccCase['renewal_consent'] ?? null) !== null && (int) $ckccCase['renewal_consent'] === 1 ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= ($ckccCase['renewal_consent'] ?? null) !== null && (int) $ckccCase['renewal_consent'] === 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="renewal_form_signed">Renewal form signed</label>
                            <select id="renewal_form_signed" name="renewal_form_signed">
                                <option value="">&mdash;</option>
                                <option value="yes" <?= ($ckccCase['renewal_form_signed'] ?? null) !== null && (int) $ckccCase['renewal_form_signed'] === 1 ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= ($ckccCase['renewal_form_signed'] ?? null) !== null && (int) $ckccCase['renewal_form_signed'] === 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="biometrics_completed">Biometrics completed</label>
                            <select id="biometrics_completed" name="biometrics_completed">
                                <option value="">&mdash;</option>
                                <option value="yes" <?= ($ckccCase['biometrics_completed'] ?? null) !== null && (int) $ckccCase['biometrics_completed'] === 1 ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= ($ckccCase['biometrics_completed'] ?? null) !== null && (int) $ckccCase['biometrics_completed'] === 0 ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="renewal_status">Renewal status</label>
                            <select id="renewal_status" name="renewal_status">
                                <?php foreach (App\Services\CkccRenewals::STATUSES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['renewal_status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="documents_status">Documents</label>
                            <select id="documents_status" name="documents_status">
                                <?php foreach (App\Services\CkccRenewals::DOCUMENT_STATUSES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['documents_status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="customer_availability">Customer availability</label>
                            <select id="customer_availability" name="customer_availability">
                                <option value="">&mdash;</option>
                                <?php foreach (App\Services\CkccRenewals::AVAILABILITY as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['customer_availability'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="renewed_on">Renewed on</label>
                            <input type="date" id="renewed_on" name="renewed_on" value="<?= e($ckccCase['renewed_on'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="ckcc_recommendation">Recommendation</label>
                            <select id="ckcc_recommendation" name="recommendation">
                                <option value="">&mdash;</option>
                                <?php foreach (App\Services\CkccRenewals::RECOMMENDATIONS as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['recommendation'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field span-2">
                            <label for="ckcc_final_status">Final report status</label>
                            <select id="ckcc_final_status" name="final_status">
                                <option value="">&mdash;</option>
                                <?php foreach (App\Services\CkccRenewals::FINAL_STATUSES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($ckccCase['final_status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field span-2">
                            <label for="ckcc_remarks">Remarks</label>
                            <input type="text" id="ckcc_remarks" name="remarks" value="<?= e($ckccCase['remarks'] ?? '') ?>" maxlength="500">
                        </div>
                    </div>                </div>
                <div class="card-foot"><button type="submit" class="btn btn-sm">Save renewal</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Follow-ups</h2></div>
        <?php if ($followups === []): ?>
            <?= view_partial('partials.empty', ['message' => 'No follow-ups scheduled', 'iconName' => 'history']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>Due</th><th>Action</th><th class="center">Status</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($followups as $followup): ?>
                            <tr>
                                <td><?= e(format_date((string) $followup['followup_date'])) ?></td>
                                <td class="small"><?= e(enum_label((string) $followup['action'])) ?></td>
                                <td class="center"><span class="<?= e(badge((string) $followup['status'])) ?>"><?= e(enum_label((string) $followup['status'])) ?></span></td>
                                <td class="small"><?= e(str_excerpt((string) $followup['notes'], 50)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Allocation history</h2></div>
        <div class="card-body">
            <ul class="timeline">
                <?php foreach ($assignments as $assignment): ?>
                    <li class="<?= $assignment['is_active'] !== null ? 'good' : '' ?>">
                        <time>
                            <?= e(format_datetime($assignment['assigned_at'])) ?>
                            <?php if (!empty($assignment['assigned_by_name'])): ?> · by <?= e($assignment['assigned_by_name']) ?><?php endif; ?>
                        </time>
                        <?= e($assignment['supervisor_name']) ?> (<?= e($assignment['bc_code']) ?>)
                        — <?= e(enum_label((string) $assignment['method'])) ?>
                        <?= $assignment['is_active'] !== null ? '<span class="badge badge-success">current</span>' : '' ?>
                        <?php if (!empty($assignment['reason'])): ?>
                            <div class="tiny muted"><?= e($assignment['reason']) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($assignments === []): ?>
                    <li class="muted small">Never allocated.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
