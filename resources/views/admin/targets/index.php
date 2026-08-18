<div class="page-head">
    <div class="grow">
        <h1>Target management</h1>
        <div class="subtitle">
            Daily and monthly visit and recovery targets. Achievement is computed from actual
            submitted visits and recorded recovery.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/reports/target')) ?>"><?= icon('file-text', '', 15) ?> Target report</a>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Set targets</h2></div>
    <form method="post" action="<?= e(url('/admin/targets')) ?>">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label for="scope">Applies to</label>
                    <select id="scope" name="scope">
                        <option value="bc_supervisor">BC Supervisor(s)</option>
                        <option value="branch">A whole branch</option>
                    </select>
                </div>
                <div class="field">
                    <label for="period_new">Period</label>
                    <select id="period_new" name="period">
                        <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
                        <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                </div>
                <div class="field">
                    <label for="period_start">Starting</label>
                    <input type="date" id="period_start" name="period_start" value="<?= e($periodStart) ?>" required>
                    <div class="help">Monthly targets always cover the whole calendar month.</div>
                </div>
                <div class="field">
                    <label for="visit_target">Visit target</label>
                    <input type="number" id="visit_target" name="visit_target" min="0" value="0">
                </div>
                <div class="field">
                    <label for="recovery_target">Recovery target (₹)</label>
                    <input type="text" id="recovery_target" name="recovery_target" value="0">
                </div>
                <div class="field">
                    <label for="branch_id">Branch (for branch targets)</label>
                    <select id="branch_id" name="branch_id">
                        <option value="">—</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch['id'] ?>"><?= e($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="bc_supervisor_ids">BC Supervisors</label>
                    <select id="bc_supervisor_ids" name="bc_supervisor_ids[]" multiple size="6">
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= (int) $supervisor['id'] ?>">
                                <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>) — <?= e($supervisor['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Hold Ctrl / Cmd to select several — the same target is applied to each.</div>
                </div>
                <div class="field span-2">
                    <label for="notes">Notes</label>
                    <input type="text" id="notes" name="notes" maxlength="255">
                </div>
            </div>
        </div>
        <div class="card-foot">
            <button type="submit" class="btn"><?= icon('target', '', 15) ?> Save targets</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h2>Target vs achievement</h2>
        <div class="spacer"></div>
        <form method="get" action="<?= e(url('/admin/targets')) ?>" class="filters">
            <div class="field">
                <select name="period" data-auto-submit aria-label="Period">
                    <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
                </select>
            </div>
            <div class="field">
                <input type="date" name="period_start" value="<?= e($periodStart) ?>" data-auto-submit aria-label="Period start">
            </div>
        </form>
    </div>

    <?php if ($targets === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No targets set for this period',
            'hint' => 'Use the form above to set visit and recovery targets.',
            'iconName' => 'target',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Target for</th><th>Branch</th><th>Period</th>
                        <th class="center">Visits</th><th style="width:130px">Visit progress</th>
                        <th class="right">Recovery target</th><th class="right">Recovered</th>
                        <th style="width:130px">Recovery progress</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($targets as $target): ?>
                        <?php
                        $visitPercent = percent_of((int) $target['visits_done'], (int) $target['visit_target']);
                        $recoveryPercent = percent_of((float) $target['recovery_done'], (float) $target['recovery_target']);
                        $barClass = static fn (float $p): string => $p >= 100 ? 'good' : ($p >= 60 ? 'warn' : 'bad');
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($target['subject']) ?></strong>
                                <?php if (!empty($target['bc_code'])): ?><div class="tiny muted"><?= e($target['bc_code']) ?></div><?php endif; ?>
                            </td>
                            <td class="small"><?= e($target['branch_name'] ?: '—') ?></td>
                            <td class="small">
                                <?= e(enum_label((string) $target['period'])) ?>
                                <div class="tiny muted"><?= e(format_date((string) $target['period_start'], 'd M')) ?>–<?= e(format_date((string) $target['period_end'], 'd M')) ?></div>
                            </td>
                            <td class="center num">
                                <?= (int) $target['visits_done'] ?> / <?= (int) $target['visit_target'] ?>
                                <div class="tiny muted"><?= max(0, (int) $target['visit_target'] - (int) $target['visits_done']) ?> pending</div>
                            </td>
                            <td>
                                <div class="bar"><span class="<?= $barClass($visitPercent) ?>" style="width:<?= min(100, $visitPercent) ?>%"></span></div>
                                <div class="tiny muted"><?= $visitPercent ?>%</div>
                            </td>
                            <td class="right num"><?= e(money((float) $target['recovery_target'])) ?></td>
                            <td class="right num"><?= e(money((float) $target['recovery_done'])) ?></td>
                            <td>
                                <div class="bar"><span class="<?= $barClass($recoveryPercent) ?>" style="width:<?= min(100, $recoveryPercent) ?>%"></span></div>
                                <div class="tiny muted"><?= $recoveryPercent ?>%</div>
                            </td>
                            <td>
                                <form method="post" action="<?= e(url('/admin/targets/' . (int) $target['id'] . '/delete')) ?>"
                                      data-confirm="Remove this target?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-link btn-sm"><?= icon('trash', '', 14) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
