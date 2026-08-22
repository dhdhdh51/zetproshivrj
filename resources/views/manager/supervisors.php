<div class="page-head">
    <div class="grow">
        <h1>BCAs</h1>
        <div class="subtitle">
            Field officers working in this branch. Read-only — accounts, credentials and devices are
            managed by the BC Supervisor.
        </div>
    </div>
</div>

<div class="card">
    <?php if ($supervisors === []): ?>
        <?= view_partial('partials.empty', ['message' => 'No BCAs in this branch', 'iconName' => 'users']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th class="center">State</th><th>Supervisor</th><th class="right">Accounts</th>
                        <th class="center">Visits (MTD)</th><th class="right">Recovery (MTD)</th>
                        <th class="center">Adverse (30d)</th><th>Last login</th><th class="center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supervisors as $s): ?>
                        <?php $online = $s['last_seen_at'] !== null && strtotime((string) $s['last_seen_at']) > time() - ($offlineMinutes * 60); ?>
                        <tr>
                            <td class="center">
                                <span class="dot <?= $online ? 'online' : 'offline' ?>"></span>
                                <div class="tiny muted"><?= e(time_ago($s['last_seen_at'])) ?></div>
                            </td>
                            <td>
                                <strong><?= e($s['name']) ?></strong>
                                <div class="tiny muted">
                                    <?= e($s['bc_code']) ?> · <?= e($s['mobile'] ?: '—') ?>
                                    <?php if (!empty($s['village'])): ?> · <?= e($s['village']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td class="right num"><?= number_format((int) $s['accounts']) ?></td>
                            <td class="center num"><?= number_format((int) $s['visits_month']) ?></td>
                            <td class="right num"><?= e(money((float) $s['recovery_month'])) ?></td>
                            <td class="center num <?= (int) $s['adverse_30d'] > 0 ? 'danger-text strong' : '' ?>"><?= (int) $s['adverse_30d'] ?></td>
                            <td class="small"><?= e(time_ago($s['last_login_at'])) ?></td>
                            <td class="center"><span class="<?= e(badge((string) $s['status'])) ?>"><?= e(enum_label((string) $s['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
