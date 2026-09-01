<?php

/**
 * One BCA's Social Security Scheme standing, in the columns the SSS register uses.
 *
 * A partial because three places have to say the same thing: the inspection being filled in,
 * the submitted report, and — through RecordExport, which redraws this for print — the sheet
 * the branch signs. The client asked for the inspection to read the same as the register these
 * figures come from, so the headings, the `achieved/target` cell and the badge thresholds are
 * the register's own rather than a second design.
 *
 * The register lists one row per agent. This is one agent, so it is one row, without the rank,
 * name and branch columns that only mean something in a ranking.
 *
 * @var array<string, mixed> $sss  a block from App\Services\Inspections::sssPerformance()
 */

$schemes = App\Services\Sss::schemes();
$schemeNames = App\Services\Sss::schemeNames();
$window = $sss['window'];
?>
<div class="tiny muted" style="margin-bottom:8px">
    <?= e(format_date($window['from'])) ?> to <?= e(format_date($window['to'])) ?>.
    Each scheme cell reads achievement of target. Targets are set per working day, so this
    window is <?= number_format((int) $sss['working_days']) ?> working day(s) of the daily figure.
    <?php if ($sss['frozen']): ?>
        These are the figures this inspection was signed against, not today's.
    <?php endif; ?>
</div>

<div class="table-wrap">
    <table class="data">
        <thead>
            <tr>
                <th class="center">Days</th>
                <?php foreach ($schemes as $column => $abbreviation): ?>
                    <th class="center" title="<?= e($schemeNames[$column] ?? '') ?>"><?= e($abbreviation) ?></th>
                <?php endforeach; ?>
                <th class="right">Target</th>
                <th class="right">Achieved</th>
                <th class="right">%</th>
                <th class="right">Gap</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="center num">
                    <?= (int) $sss['days_reported'] ?>
                    <?php if ((int) $sss['days_reopened'] > 0): ?>
                        <div class="tiny muted" title="Days handed back for correction">
                            <?= (int) $sss['days_reopened'] ?> re-opened
                        </div>
                    <?php endif; ?>
                </td>
                <?php foreach (array_keys($schemes) as $column): ?>
                    <?php
                    $done = (int) ($sss['achievement'][$column] ?? 0);
                    $want = (int) ($sss['target'][$column] ?? 0);
                    ?>
                    <td class="center num <?= $done === 0 && $want === 0 ? 'muted' : '' ?>">
                        <?= number_format($done) ?><span class="muted">/<?= number_format($want) ?></span>
                    </td>
                <?php endforeach; ?>
                <td class="right num"><?= number_format((int) $sss['total_target']) ?></td>
                <td class="right num"><strong><?= number_format((int) $sss['total_achievement']) ?></strong></td>
                <td class="right num">
                    <?php if ($sss['has_target']): ?>
                        <span class="badge <?= badge_for_percent((float) $sss['percent']) ?>">
                            <?= number_format((float) $sss['percent'], 1) ?>%
                        </span>
                    <?php else: ?>
                        <span class="tiny muted">No target</span>
                    <?php endif; ?>
                </td>
                <td class="right num <?= (int) $sss['gap'] === 0 ? 'muted' : '' ?>">
                    <?= number_format((int) $sss['gap']) ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>
