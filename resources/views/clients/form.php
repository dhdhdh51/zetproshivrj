<?php
/**
 * @var array|null $client
 * @var string $return_to
 */
$isEdit = $client !== null;
$action = $isEdit ? url('clients/' . (int) $client['id']) : url('clients');
$value = static fn (string $key): string => e((string) (old($key) !== '' ? old($key) : ($client[$key] ?? '')));
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit client' : 'Add a client' ?></h1>
        <p>Client details are copied onto documents, and you can still tweak them per document.</p>
    </div>
    <a href="<?= e(url($isEdit ? 'clients/' . (int) $client['id'] : 'clients')) ?>" class="btn-dp btn-ghost-dp">
        <?= icon('arrow-left', '', 17) ?> Back
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <form method="post" action="<?= e($action) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($return_to !== ''): ?><input type="hidden" name="return_to" value="<?= e($return_to) ?>"><?php endif; ?>

            <div class="card-dp">
                <div class="card-dp__body">
                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="name">Client name</label>
                            <input type="text" id="name" name="name" required autofocus
                                   class="input-dp <?= has_error('name') ? 'is-invalid-dp' : '' ?>"
                                   value="<?= $value('name') ?>" placeholder="Rahul Verma">
                            <?php if (has_error('name')): ?><p class="field-error"><?= e(error_for('name')) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label-dp" for="company">Company <span class="opt">(optional)</span></label>
                            <input type="text" id="company" name="company" class="input-dp" value="<?= $value('company') ?>" placeholder="ABC Technologies">
                        </div>
                        <div>
                            <label class="form-label-dp" for="email">Email <span class="opt">(optional)</span></label>
                            <input type="email" id="email" name="email"
                                   class="input-dp <?= has_error('email') ? 'is-invalid-dp' : '' ?>"
                                   value="<?= $value('email') ?>" placeholder="rahul@abctech.com">
                            <?php if (has_error('email')): ?>
                                <p class="field-error"><?= e(error_for('email')) ?></p>
                            <?php else: ?>
                                <p class="field-hint">Used to pre-fill the “Send to client” form.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label-dp" for="phone">Phone <span class="opt">(optional)</span></label>
                            <input type="text" id="phone" name="phone" class="input-dp" value="<?= $value('phone') ?>" placeholder="+91 90000 00000">
                        </div>
                    </div>

                    <div class="form-row mt-3">
                        <label class="form-label-dp" for="address">Address <span class="opt">(optional)</span></label>
                        <textarea id="address" name="address" class="textarea-dp" rows="3"
                                  placeholder="Office 402, Tech Park, Bengaluru 560001"><?= $value('address') ?></textarea>
                    </div>

                    <div class="form-row mb-0">
                        <label class="form-label-dp" for="notes">Internal notes <span class="opt">(never shown to the client)</span></label>
                        <textarea id="notes" name="notes" class="textarea-dp" rows="3"
                                  placeholder="Prefers weekly updates. Payment terms: 15 days."><?= $value('notes') ?></textarea>
                    </div>
                </div>
                <div class="card-dp__foot d-flex gap-2">
                    <button type="submit" class="btn-dp btn-primary-dp">
                        <?= icon('check', '', 17) ?> <?= $isEdit ? 'Save client' : 'Add client' ?>
                    </button>
                    <a href="<?= e(url('clients')) ?>" class="btn-dp btn-ghost-dp">Cancel</a>
                </div>
            </div>
        </form>

        <?php if ($isEdit): ?>
            <form method="post" action="<?= e(url('clients/' . (int) $client['id'] . '/delete')) ?>" class="mt-3"
                  data-confirm="Delete <?= e((string) $client['name']) ?>? Their documents will be kept.">
                <?= csrf_field() ?>
                <button type="submit" class="btn-dp btn-ghost-dp" style="color:var(--dp-danger)">
                    <?= icon('trash', '', 16) ?> Delete client
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
