<?php
/**
 * Today's route for one BC Supervisor: the ordered points they actually recorded.
 *
 * @var array      $supervisor
 * @var string     $date
 * @var array      $points
 * @var array|null $attendance
 */
$basePath = App\Core\Auth::isAdmin() ? '/admin' : '/manager';
$totalDistance = 0.0;
$previous = null;

foreach ($points as $point) {
    if ($previous !== null) {
        $totalDistance += App\Services\Gps::distance(
            (float) $previous['latitude'],
            (float) $previous['longitude'],
            (float) $point['latitude'],
            (float) $point['longitude']
        );
    }

    $previous = $point;
}
?>

<div class="page-head">
    <div class="grow">
        <h1>Route: <?= e($supervisor['name']) ?></h1>
        <div class="subtitle">
            <?= e($supervisor['bc_code']) ?> · <?= e($supervisor['branch_name']) ?> ·
            <?= e(format_date($date, 'l, d F Y')) ?>
        </div>
    </div>
    <div class="page-actions">
        <form method="get" action="<?= e(url($basePath . '/monitoring/route/' . (int) $supervisor['id'])) ?>">
            <input type="date" name="date" value="<?= e($date) ?>" data-auto-submit>
        </form>
        <a class="btn btn-secondary" href="<?= e(url($basePath . '/monitoring')) ?>">Back to monitoring</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat accent">
        <div class="label">Points recorded</div>
        <div class="value"><?= count($points) ?></div>
    </div>
    <div class="stat">
        <div class="label">Approx. distance between points</div>
        <div class="value sm"><?= number_format($totalDistance / 1000, 1) ?> km</div>
        <div class="meta">straight-line, not road distance</div>
    </div>
    <div class="stat">
        <div class="label">Check in / out</div>
        <div class="value sm">
            <?= e($attendance !== null && $attendance['check_in_at'] !== null ? format_time($attendance['check_in_at']) : '—') ?>
            →
            <?= e($attendance !== null && $attendance['check_out_at'] !== null ? format_time($attendance['check_out_at']) : '—') ?>
        </div>
        <div class="meta">
            <?= $attendance !== null ? e(minutes_to_hours((int) $attendance['working_minutes'])) : 'no attendance record' ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Points in order</h2></div>
    <?php if ($points === []): ?>
        <?= view_partial('partials.empty', [
            'message' => 'No positions recorded on this date',
            'hint' => 'Points are recorded when visits are started and submitted from the app.',
            'iconName' => 'map-pin',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>#</th><th>Time</th><th>Customer</th><th>Village</th><th>Event</th><th>Coordinates</th><th class="right">Accuracy</th><th class="right">From previous</th><th></th></tr>
                </thead>
                <tbody>
                    <?php $previous = null; ?>
                    <?php foreach ($points as $index => $point): ?>
                        <?php
                        $leg = null;

                        if ($previous !== null) {
                            $leg = App\Services\Gps::distance(
                                (float) $previous['latitude'],
                                (float) $previous['longitude'],
                                (float) $point['latitude'],
                                (float) $point['longitude']
                            );
                        }

                        $previous = $point;
                        ?>
                        <tr>
                            <td class="tiny muted"><?= $index + 1 ?></td>
                            <td class="small nowrap"><?= e(format_time($point['captured_at'])) ?></td>
                            <td class="small">
                                <?= e($point['borrower_name']) ?>
                                <div class="tiny muted mono"><?= e($point['account_number']) ?></div>
                            </td>
                            <td class="small"><?= e($point['village'] ?: '—') ?></td>
                            <td><span class="badge badge-muted"><?= e(enum_label((string) $point['event'])) ?></span></td>
                            <td class="small">
                                <?php $map = map_link($point['latitude'], $point['longitude']); ?>
                                <?php if ($map !== null): ?>
                                    <a href="<?= e($map) ?>" target="_blank" rel="noopener"><?= e(coordinates($point['latitude'], $point['longitude'])) ?></a>
                                <?php endif; ?>
                                <?php if (!empty($point['address'])): ?>
                                    <div class="tiny muted"><?= e(str_excerpt((string) $point['address'], 36)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="right num small"><?= $point['accuracy'] === null ? '—' : number_format((float) $point['accuracy'], 0) . ' m' ?></td>
                            <td class="right num small"><?= $leg === null ? '—' : number_format($leg / 1000, 2) . ' km' ?></td>
                            <td><a class="btn btn-link btn-sm" href="<?= e(url($basePath . '/visits/' . (int) $point['visit_id'])) ?>">Visit</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
