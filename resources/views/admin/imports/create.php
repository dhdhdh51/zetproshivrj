<div class="page-head">
    <div class="grow">
        <h1>Upload loan Excel</h1>
        <div class="subtitle">.xlsx or .csv. Column names can be anything — you map them on the next screen.</div>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('/admin/imports/sample')) ?>">
            <?= icon('download', '', 16) ?> Sample Excel
        </a>
        <a class="btn btn-secondary" href="<?= e(url('/admin/imports')) ?>">Import history</a>
    </div>
</div>

<div class="steps">
    <div class="step active"><span class="n">1</span> Upload</div>
    <div class="step"><span class="n">2</span> Map columns</div>
    <div class="step"><span class="n">3</span> Preview</div>
    <div class="step"><span class="n">4</span> Import</div>
</div>

<?php if ($branchCount === 0): ?>
    <div class="alert alert-warning">
        <?= icon('alert-triangle', '', 17) ?>
        <div>
            No branches are set up yet. Accounts can only be imported against an existing branch —
            <a href="<?= e(url('/admin/branches/create')) ?>">add your branches first</a>.
        </div>
    </div>
<?php endif; ?>

<?php if ($supervisorCount === 0): ?>
    <div class="alert alert-info">
        <?= icon('info', '', 17) ?>
        <div>
            There are no active BC Supervisors, so imported accounts cannot be allocated yet.
            You can still import them and allocate later.
        </div>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <?= icon('download', '', 17) ?>
    <div>
        <strong>Not sure how the sheet should look?</strong>
        Download the sample — it has every column heading LRMS recognises and three filled-in example rows,
        already using <em>your</em> branch codes, so it imports as-is.
        <a href="<?= e(url('/admin/imports/sample')) ?>">Sample .xlsx</a> ·
        <a href="<?= e(url('/admin/imports/sample?format=csv')) ?>">Sample .csv</a>
        <div class="small muted" style="margin-top:4px">
            The example rows use account numbers starting <code>SAMPLE-</code>. Replace them with your own
            data — or import once to see how it works, then delete those three accounts.
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Choose your file</h2></div>
        <form method="post" action="<?= e(url('/admin/imports')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="field">
                    <label for="file">Loan account sheet <span class="req">*</span></label>
                    <input type="file" id="file" name="file" accept=".xlsx,.xlsm,.csv,.txt" required>
                    <div class="help">
                        Legacy .xls is not supported — open it in Excel and use “Save As” to create an
                        .xlsx or .csv file first.
                    </div>
                </div>

                <?php if ($templates !== []): ?>
                    <div class="field">
                        <label for="template_id">Apply a saved mapping</label>
                        <select id="template_id" name="template_id">
                            <option value="">Detect columns automatically</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?= (int) $template['id'] ?>"><?= e($template['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Use this when the file has the same layout as a previous upload.</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-foot">
                <button type="submit" class="btn" <?= $branchCount === 0 ? 'disabled' : '' ?>>
                    <?= icon('upload', '', 15) ?> Upload and detect columns
                </button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2>What LRMS looks for</h2></div>
        <div class="card-body">
            <p class="small muted">
                These are the fields the importer understands. Only Account Number and Borrower Name
                are mandatory, plus a Branch Code or Branch Name so the account can be linked to a branch.
            </p>
            <div class="table-wrap">
                <table class="data compact">
                    <thead><tr><th>System field</th><th>Recognised spellings</th></tr></thead>
                    <tbody>
                        <?php foreach ($systemFields as $key => $field): ?>
                            <tr>
                                <td class="nowrap">
                                    <?= e($field['label']) ?>
                                    <?php if ($field['required']): ?>
                                        <span class="badge badge-danger">required</span>
                                    <?php endif; ?>
                                </td>
                                <td class="tiny muted"><?= e(implode(', ', array_slice($field['aliases'], 0, 6))) ?>…</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h3>How allocation works after import</h3></div>
    <div class="card-body">
        <ol class="small" style="margin:0;padding-left:18px;line-height:1.9">
            <li>If the row carries a <strong>BC Code</strong> that matches an active BC Supervisor in that branch, the account goes to them.</li>
            <li>Otherwise the account is given to the <strong>least loaded</strong> active supervisor of that branch, so the spread stays even (40 / 40 / 39).</li>
            <li>Accounts whose branch is unknown are <strong>not imported</strong> and are listed as errors, so nothing lands in the wrong branch.</li>
            <li>Accounts that already exist are <strong>updated</strong> — figures refresh, and their visits and allocation history are kept.</li>
            <li>Every allocation and reassignment is recorded in the audit log.</li>
        </ol>
    </div>
</div>
