<?php
/**
 * @var array<string, mixed>|null $entry
 * @var array<string, string> $schemes      column => abbreviation
 * @var array<string, string> $schemeNames  column => full scheme name
 * @var array<int, array<string, mixed>> $supervisors
 * @var int $maxPerScheme
 */
$editing = $entry !== null;
$action = $editing ? '/admin/sss/' . (int) $entry['id'] : '/admin/sss';

$value = static function (string $key, mixed $default = '') use ($entry) {
    $old = old($key, null);

    if ($old !== null) {
        return $old;
    }

    return $entry[$key] ?? $default;
};
?>

<div class="page-head">
    <div class="grow">
        <h1><?= $editing ? 'Correct SSS enrolments' : 'Record SSS enrolments' ?></h1>
        <div class="subtitle">
            <?php if ($editing): ?>
                Correcting the figures for <?= e(format_date((string) $entry['enrolment_date'])) ?>.
                The supervisor and the date cannot be changed — a day belongs to one supervisor.
            <?php else: ?>
                One entry per supervisor per day. Leave a scheme blank if there were no enrolments for it.
            <?php endif; ?>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/sss')) ?>">Back to list</a>
    </div>
</div>

<div class="card content-narrow">
    <form method="post" action="<?= e(url($action)) ?>">
        <?= csrf_field() ?>

        <div class="card-body">
            <?php if ($editing): ?>
                <div class="kv">
                    <div>
                        <div class="k">BCA</div>
                        <div class="v">
                            <?= e($entry['supervisor_name']) ?>
                            <span class="tiny muted mono">(<?= e($entry['bc_code']) ?>)</span>
                        </div>
                    </div>
                    <div>
                        <div class="k">Branch</div>
                        <div class="v"><?= e($entry['branch_name']) ?></div>
                    </div>
                    <div>
                        <div class="k">Date</div>
                        <div class="v"><?= e(format_date((string) $entry['enrolment_date'])) ?></div>
                    </div>
                    <div>
                        <div class="k">Reported from</div>
                        <div class="v"><?= (string) $entry['source'] === 'panel' ? 'The panel' : 'The app' ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="form-grid">
                    <div class="field <?= has_error('bc_supervisor_id') ? 'has-error' : '' ?>">
                        <label for="bc_supervisor_id">BCA <span class="req">*</span></label>
                        <select id="bc_supervisor_id" name="bc_supervisor_id" required>
                            <option value="">Choose a supervisor</option>
                            <?php foreach ($supervisors as $supervisor): ?>
                                <option value="<?= (int) $supervisor['id'] ?>"
                                    <?= (int) $value('bc_supervisor_id', 0) === (int) $supervisor['id'] ? 'selected' : '' ?>>
                                    <?= e($supervisor['name']) ?> (<?= e($supervisor['bc_code']) ?>) — <?= e($supervisor['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (has_error('bc_supervisor_id')): ?><div class="error-text"><?= e(error_for('bc_supervisor_id')) ?></div><?php endif; ?>
                    </div>

                    <div class="field <?= has_error('enrolment_date') ? 'has-error' : '' ?>">
                        <label for="enrolment_date">Date <span class="req">*</span></label>
                        <input type="date" id="enrolment_date" name="enrolment_date"
                               value="<?= e($value('enrolment_date', today())) ?>"
                               max="<?= e(today()) ?>" required>
                        <div class="help">The day the enrolments were made, not the day you are typing them.</div>
                        <?php if (has_error('enrolment_date')): ?><div class="error-text"><?= e(error_for('enrolment_date')) ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-grid">
                <?php foreach ($schemes as $column => $abbreviation): ?>
                    <div class="field <?= has_error($column) ? 'has-error' : '' ?>">
                        <label for="<?= e($column) ?>"><?= e($abbreviation) ?></label>
                        <input type="number" id="<?= e($column) ?>" name="<?= e($column) ?>"
                               value="<?= e((string) $value($column, '')) ?>"
                               min="0" max="<?= (int) $maxPerScheme ?>" step="1" inputmode="numeric"
                               placeholder="0">
                        <div class="help"><?= e($schemeNames[$column] ?? '') ?></div>
                        <?php if (has_error($column)): ?><div class="error-text"><?= e(error_for($column)) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="field <?= has_error('remarks') ? 'has-error' : '' ?>">
                <label for="remarks">Remarks</label>
                <textarea id="remarks" name="remarks" rows="3" maxlength="500"
                          placeholder="Anything worth noting about the day, e.g. a camp at the panchayat office."><?= e((string) $value('remarks', '')) ?></textarea>
                <?php if (has_error('remarks')): ?><div class="error-text"><?= e(error_for('remarks')) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="card-foot">
            <button type="submit" class="btn"><?= $editing ? 'Save correction' : 'Record enrolments' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('/admin/sss')) ?>">Cancel</a>
        </div>
    </form>
</div>
