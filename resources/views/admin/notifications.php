<?php
/**
 * @var array $notifications
 * @var int   $unread
 */
$isAdmin = App\Core\Auth::isAdmin();
$basePath = $isAdmin ? '/admin' : '/manager';
?>

<div class="page-head">
    <div class="grow">
        <h1>Notifications</h1>
        <div class="subtitle"><?= (int) $unread ?> unread of <?= count($notifications) ?> shown.</div>
    </div>
    <div class="page-actions">
        <?php if ($unread > 0): ?>
            <form method="post" action="<?= e(url($basePath . '/notifications/read-all')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-secondary"><?= icon('check', '', 15) ?> Mark all read</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin): ?>
    <div class="card">
        <div class="card-head"><h2>Send an announcement</h2></div>
        <form method="post" action="<?= e(url('/admin/notifications/broadcast')) ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="audience">Send to</label>
                        <select id="audience" name="audience">
                            <option value="all_bc">All BC Supervisors</option>
                            <option value="all_managers">All Branch Managers</option>
                            <option value="branch">One branch</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="branch_id">Branch (for branch announcements)</label>
                        <select id="branch_id" name="branch_id">
                            <option value="">—</option>
                            <?php foreach (App\Core\Database::select("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name") as $branch): ?>
                                <option value="<?= (int) $branch['id'] ?>"><?= e($branch['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label for="title">Title <span class="req">*</span></label>
                        <input type="text" id="title" name="title" required maxlength="190">
                    </div>
                    <div class="field span-2">
                        <label for="body">Message</label>
                        <textarea id="body" name="body" maxlength="500"></textarea>
                    </div>
                </div>
            </div>
            <div class="card-foot">
                <button type="submit" class="btn btn-sm"><?= icon('bell', '', 15) ?> Send announcement</button>
                <span class="small muted">Appears in the app and in the recipient's notification list.</span>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Your notifications</h2></div>
    <?php if ($notifications === []): ?>
        <?= view_partial('partials.empty', ['message' => 'Nothing here yet', 'iconName' => 'bell']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th style="width:30px"></th><th>Notification</th><th>Type</th><th>When</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($notifications as $notification): ?>
                        <tr style="<?= (int) $notification['is_read'] === 0 ? 'background:#fbfdff' : '' ?>">
                            <td class="center">
                                <?php if ((int) $notification['is_read'] === 0): ?>
                                    <span class="dot online" title="Unread"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e($notification['title']) ?></strong>
                                <?php if (!empty($notification['body'])): ?>
                                    <div class="small muted"><?= e($notification['body']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="<?= e(badge((string) $notification['type'])) ?>"><?= e(enum_label((string) $notification['type'])) ?></span></td>
                            <td class="small"><?= e(time_ago($notification['created_at'])) ?></td>
                            <td class="nowrap">
                                <?php if (!empty($notification['link'])): ?>
                                    <a class="btn btn-link btn-sm" href="<?= e(url((string) $notification['link'])) ?>">Open</a>
                                <?php endif; ?>
                                <?php if ((int) $notification['is_read'] === 0): ?>
                                    <form method="post" action="<?= e(url($basePath . '/notifications/' . (int) $notification['id'] . '/read')) ?>" style="display:inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-link btn-sm">Mark read</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
