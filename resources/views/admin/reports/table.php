<?php
/**
 * Generic report runner: filter bar, table, totals, exports, pagination.
 *
 * @var string $slug
 * @var string $name
 * @var array  $columns
 * @var array  $rows
 * @var array  $totals
 * @var array  $filters
 * @var array  $availableFilters
 * @var array|null $detailRoute
 * @var string $basePath
 * @var string $summary
 */

use App\Services\CkccRenewals;
use App\Services\KrmOts;
use App\Services\Reports;

$f = static fn (string $key, string $default = ''): string => (string) ($filters[$key] ?? $default);
$has = static fn (string $key): bool => in_array($key, $availableFilters, true);
$exportQuery = query_string(['page' => null], ['page']);
?>

<div class="page-head">
    <div class="grow">
        <h1><?= e($name) ?></h1>
        <div class="subtitle"><?= e($description ?? '') ?></div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url($basePath . '/reports')) ?>"><?= icon('arrow-left', '', 15) ?> All reports</a>
        <?php foreach (Reports::FORMATS as $format => $label): ?>
            <a class="btn btn-secondary"
               href="<?= e(url($basePath . '/reports/' . $slug . '/export/' . $format) . $exportQuery) ?>">
                <?= icon('download', '', 15) ?> <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <form method="get" action="<?= e(url($basePath . '/reports/' . $slug)) ?>" class="filters" style="flex:1 1 auto">
            <?php if ($has('from')): ?>
                <div class="field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?= e($f('from')) ?>">
                </div>
                <div class="field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?= e($f('to')) ?>">
                </div>
            <?php endif; ?>

            <?php if ($has('branch_id') && App\Core\Auth::isAdmin()): ?>
                <div class="field">
                    <label for="branch_id">Branch</label>
                    <select id="branch_id" name="branch_id">
                        <option value="">All branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>" <?= $f('branch_id') === (string) $branch['id'] ? 'selected' : '' ?>>
                                <?= e($branch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($has('bc_supervisor_id')): ?>
                <div class="field">
                    <label for="bc_supervisor_id">BCA</label>
                    <select id="bc_supervisor_id" name="bc_supervisor_id">
                        <option value="">All</option>
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= (int) $supervisor['id'] ?>" <?= $f('bc_supervisor_id') === (string) $supervisor['id'] ? 'selected' : '' ?>>
                                <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php
            // Report specific dropdowns.
            $enumFilters = [
                'visit_status' => ['label' => 'Visit status', 'options' => [
                    'customer_met' => 'Customer met', 'family_met' => 'Family met', 'phone_contact' => 'Phone only',
                    'house_locked' => 'House locked', 'not_available' => 'Not available',
                    'address_not_found' => 'Address not found', 'deceased' => 'Deceased', 'shifted' => 'Shifted',
                    'refused' => 'Refused', 'other' => 'Other',
                ]],
                'result' => ['label' => 'Result', 'options' => inspection_results()],
                'ots_status' => ['label' => 'OTS status', 'options' => KrmOts::STATUSES],
                'customer_response' => ['label' => 'Customer response', 'options' => KrmOts::CUSTOMER_RESPONSES],
                'renewal_status' => ['label' => 'Renewal status', 'options' => CkccRenewals::STATUSES],
                'documents_status' => ['label' => 'Documents', 'options' => CkccRenewals::DOCUMENT_STATUSES],
                'renewal_due_bucket' => ['label' => 'Renewal due', 'options' => CkccRenewals::DUE_BUCKETS],
                'kyc_status' => ['label' => 'KYC status', 'options' => CkccRenewals::KYC_STATUSES],
                // Section 13 exists on both registers with a different option
                // set, so it follows whichever report is open.
                'final_status' => ['label' => 'Final status', 'options' => $slug === 'krm_ots'
                    ? KrmOts::FINAL_STATUSES
                    : CkccRenewals::FINAL_STATUSES],
                'action' => ['label' => 'Action', 'options' => [
                    'call' => 'Call', 'visit' => 'Visit', 'notice' => 'Notice', 'legal' => 'Legal', 'other' => 'Other',
                ]],
                'photo_type' => ['label' => 'Photo type', 'options' => photo_types()],
                'period' => ['label' => 'Period', 'options' => ['daily' => 'Daily', 'monthly' => 'Monthly']],
                // "Case Type" in section 1 of the field visit verification report.
                'visit_type' => ['label' => 'Case type', 'options' => \App\Services\Visits::CASE_TYPES],
            ];
            ?>

            <?php foreach ($enumFilters as $key => $config): ?>
                <?php if ($has($key)): ?>
                    <div class="field">
                        <label for="<?= e($key) ?>"><?= e($config['label']) ?></label>
                        <select id="<?= e($key) ?>" name="<?= e($key) ?>">
                            <option value="">Any</option>
                            <?php foreach ($config['options'] as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>" <?= $f($key) === (string) $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($has('status')): ?>
                <div class="field">
                    <label for="status">Status</label>
                    <?php
                    $statusOptions = match ($slug) {
                        'customer_visit' => ['submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'],
                        'bc_inspection' => ['submitted' => 'Submitted', 'draft' => 'Draft'],
                        'recovery' => ['recorded' => 'Recorded', 'verified' => 'Verified', 'rejected' => 'Rejected'],
                        'ptp' => ['pending' => 'Pending', 'kept' => 'Kept', 'partially_kept' => 'Partially kept',
                                  'broken' => 'Broken', 'cancelled' => 'Cancelled'],
                        'followup' => ['pending' => 'Pending', 'done' => 'Done', 'cancelled' => 'Cancelled'],
                        'attendance' => ['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent',
                                         'leave' => 'Leave', 'holiday' => 'Holiday'],
                        default => [],
                    };
                    ?>
                    <select id="status" name="status">
                        <option value="">Any</option>
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $f('status') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($has('payment_mode')): ?>
                <div class="field">
                    <label for="payment_mode">Mode</label>
                    <select id="payment_mode" name="payment_mode">
                        <option value="">Any</option>
                        <?php foreach (payment_modes() as $mode): ?>
                            <option value="<?= e($mode) ?>" <?= $f('payment_mode') === $mode ? 'selected' : '' ?>><?= e($mode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($has('search')): ?>
                <div class="field wide">
                    <label for="search">Search</label>
                    <input type="search" id="search" name="search" value="<?= e($f('search')) ?>" placeholder="Account, borrower, code">
                </div>
            <?php endif; ?>

            <?php if ($has('late_only') || $has('gps_invalid_only')): ?>
                <div class="field">
                    <label>Only show</label>
                    <?php if ($has('late_only')): ?>
                        <div class="check">
                            <input type="checkbox" id="late_only" name="late_only" value="1" <?= $f('late_only') === '1' ? 'checked' : '' ?>>
                            <label for="late_only">Late</label>
                        </div>
                    <?php endif; ?>
                    <?php if ($has('gps_invalid_only')): ?>
                        <div class="check">
                            <input type="checkbox" id="gps_invalid_only" name="gps_invalid_only" value="1" <?= $f('gps_invalid_only') === '1' ? 'checked' : '' ?>>
                            <label for="gps_invalid_only">Failed GPS check</label>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="actions">
                <button type="submit" class="btn btn-secondary"><?= icon('filter', '', 15) ?> Apply</button>
                <a class="btn btn-link" href="<?= e(url($basePath . '/reports/' . $slug)) ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="card-body tight" style="border-bottom:1px solid var(--line)">
        <span class="small muted"><?= icon('filter', '', 13) ?> <?= e($summary) ?> · <strong><?= number_format($total) ?></strong> row(s)</span>
    </div>

    <?php if ($rows === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No records match these filters',
            'hint' => 'Try widening the date range or clearing filters.',
            'iconName' => 'search',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th class="<?= e($column['align'] ?? '') ?>"><?= e($column['label']) ?></th>
                        <?php endforeach; ?>
                        <?php if ($detailRoute !== null): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php
                                $value = Reports::value($column, $row);
                                $rendered = Reports::format($column, $value);
                                $type = $column['type'] ?? '';
                                $class = ($column['align'] ?? '') . (in_array($type, ['money', 'count'], true) ? ' num' : '');
                                ?>
                                <td class="<?= e(trim($class)) ?>">
                                    <?php if ($type === 'boolean'): ?>
                                        <?= $rendered === 'Yes' ? icon('check-circle', 'success-text', 15) : icon('x-circle', 'danger-text', 15) ?>
                                    <?php elseif (in_array($type, ['enum', 'inspection_result'], true)): ?>
                                        <span class="<?= e(badge((string) $value)) ?>"><?= e($rendered) ?></span>
                                    <?php else: ?>
                                        <?= e($rendered) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <?php if ($detailRoute !== null): ?>
                                <td class="nowrap">
                                    <?php $id = (int) ($row[$detailRoute['key']] ?? 0); ?>
                                    <?php if ($id > 0): ?>
                                        <a class="btn btn-link btn-sm" href="<?= e(url($detailRoute['path'] . $id)) ?>">Open</a>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php if ($totals !== []): ?>
                    <tfoot>
                        <tr>
                            <?php $first = true; ?>
                            <?php foreach ($columns as $column): ?>
                                <?php
                                $key = (string) $column['key'];
                                $isTotal = array_key_exists($key, $totals);
                                ?>
                                <td class="<?= e($column['align'] ?? '') ?> <?= $isTotal ? 'num' : '' ?>">
                                    <?php if ($first): ?>
                                        Totals
                                        <?php $first = false; ?>
                                    <?php elseif ($isTotal): ?>
                                        <?= e(Reports::format($column, $totals[$key])) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <?php if ($detailRoute !== null): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?= view_partial('partials.pagination', [
            'page' => $page, 'lastPage' => $last_page, 'total' => $total, 'perPage' => $per_page,
        ]) ?>
    <?php endif; ?>
</div>
