<?php
/**
 * @var string|null $output
 * @var array<string, mixed>|null $result
 * @var bool $dryRun
 */
?>

<div class="page-head">
    <div class="grow">
        <h1>Update the database</h1>
        <div class="subtitle">
            After uploading a newer version of the application, this adds whatever the new
            version needs — tables, columns, indexes and settings. It adds only what is
            missing and never deletes anything, so it is safe to run more than once.
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/settings')) ?>">Back to settings</a>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Do not reinstall to update</h2></div>
    <div class="card-body">
        <p>
            Deleting the files and running <code>install.php</code> again is not an update. The
            installer builds an empty system: it drops every table first, which removes every
            loan account, visit, photograph and report on this site. It normally refuses when it
            finds a working installation, but it recognises one by the files it is being asked to
            delete — so deleting them first is exactly what gets past the guard.
        </p>
        <p>
            Upload the new files over the old ones, keep <code>config/config.local.php</code>
            (your database details) and the <code>storage/</code> folder, then run the update here.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2><?= $output === null ? 'Run the update' : ($dryRun ? 'What would change' : 'What changed') ?></h2></div>
    <div class="card-body">
        <?php if ($output === null): ?>
            <p>
                Start with <strong>Preview</strong>. It touches nothing and lists what it would do,
                which is also the quickest way to confirm the new files uploaded completely.
            </p>
        <?php else: ?>
            <?php if ($result !== null): ?>
                <div class="stat-grid">
                    <div class="stat <?= (int) $result['applied'] > 0 ? 'accent' : '' ?>">
                        <div class="label"><?= $dryRun ? 'Would change' : 'Changed' ?></div>
                        <div class="value"><?= (int) $result['applied'] ?></div>
                    </div>
                    <div class="stat">
                        <div class="label">Already present</div>
                        <div class="value"><?= (int) $result['skipped'] ?></div>
                        <div class="meta tiny">Nothing to do</div>
                    </div>
                    <div class="stat <?= (int) $result['failed'] > 0 ? 'bad' : '' ?>">
                        <div class="label">Failed</div>
                        <div class="value"><?= (int) $result['failed'] ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($dryRun && $result !== null && (int) $result['applied'] === 0): ?>
                <p class="muted">
                    Nothing is missing — this database already matches the application. There is
                    no need to apply anything.
                </p>
            <?php endif; ?>

            <pre class="log"><?= e($output) ?></pre>
        <?php endif; ?>
    </div>

    <div class="card-foot">
        <form method="post" action="<?= e(url('/admin/settings/upgrade')) ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="preview">
            <button type="submit" class="btn btn-secondary">Preview the changes</button>
        </form>

        <form method="post" action="<?= e(url('/admin/settings/upgrade')) ?>" style="display:inline"
              data-confirm="Apply the missing changes to this database? Nothing is deleted, but take a backup first if you have not.">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="apply">
            <button type="submit" class="btn">Apply the update</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>If you have a terminal</h2></div>
    <div class="card-body">
        <p class="muted">
            The same thing, and the same script, from the command line:
        </p>
        <pre class="log">php database/upgrade.php --dry-run    # list the changes, touch nothing
php database/upgrade.php              # apply them</pre>
    </div>
</div>
