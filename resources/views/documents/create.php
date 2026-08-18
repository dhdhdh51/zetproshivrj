<?php
/**
 * Document creation wizard.
 *
 * @var array $profile
 * @var bool  $profile_complete
 * @var array $clients
 * @var array|null $selected_client
 * @var array $templates
 * @var bool  $all_templates
 * @var string $type
 * @var array{allowed:bool,message:string} $limit
 * @var array $summary
 * @var bool  $ai_ready
 * @var bool  $ai_enabled
 */
$currency = (string) (old('currency') !== '' ? old('currency') : ($profile['default_currency'] ?? 'INR'));
$selectedType = (string) (old('document_type') !== '' ? old('document_type') : $type);
$selectedTemplate = (string) (old('template') !== '' ? old('template') : ($profile['default_template'] ?? 'modern'));
$clientId = (int) (old('client_id') !== '' ? (int) old('client_id') : (int) ($selected_client['id'] ?? 0));
?>
<div class="page-head">
    <div>
        <h1>Create a document</h1>
        <p>Tell DocuPilot what you need — it drafts the content, you stay in control of every number.</p>
    </div>
    <div class="text-lg-end">
        <div class="small-caps">This month</div>
        <div class="fw-650">Documents <span data-usage="documents"><?= (int) $summary['documents_used'] ?> / <?= (int) $summary['documents_limit'] ?></span></div>
        <div class="text-muted-2" style="font-size:.85rem">AI <span data-usage="ai"><?= (int) $summary['ai_used'] ?> / <?= (int) $summary['ai_limit'] ?></span></div>
    </div>
</div>

<?php if (!$limit['allowed']): ?>
    <div class="card-dp">
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon('zap', '', 26) ?></div>
            <h3>You have reached this month's document limit</h3>
            <p><?= e($limit['message']) ?></p>
            <div class="btn-group-dp justify-content-center">
                <a href="<?= e(url('pricing')) ?>" class="btn-dp btn-primary-dp"><?= icon('zap', '', 17) ?> See plans</a>
                <a href="<?= e(url('documents')) ?>" class="btn-dp btn-outline-dp">Back to documents</a>
            </div>
        </div>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if (!$profile_complete): ?>
    <div class="alert-dp alert-warning-dp">
        <?= icon('briefcase') ?>
        <div>
            Your business profile is incomplete, so documents will be missing your business name and logo.
            <a href="<?= e(url('profile/business')) ?>">Complete it first</a>
        </div>
    </div>
<?php endif; ?>

<div class="wizard-steps">
    <span class="wizard-step active"><b>1</b> Type</span>
    <span class="wizard-step active"><b>2</b> Client</span>
    <span class="wizard-step active"><b>3</b> Requirement</span>
    <span class="wizard-step active"><b>4</b> Items &amp; pricing</span>
    <span class="wizard-step active"><b>5</b> Review &amp; save</span>
</div>

<form method="post" action="<?= e(url('documents')) ?>" data-document-editor novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="ai_generated" value="<?= old('ai_generated') === '1' ? '1' : '0' ?>">

    <div class="row g-3">
        <div class="col-xl-8">
            <!-- Step 1 -------------------------------------------------- -->
            <div class="card-dp">
                <div class="card-dp__head"><h2>1 · What are you creating?</h2></div>
                <div class="card-dp__body">
                    <div class="type-grid">
                        <?php foreach (document_types() as $key => $meta): ?>
                            <div class="type-card">
                                <input type="radio" id="type-<?= e($key) ?>" name="document_type" value="<?= e($key) ?>"
                                    <?= $selectedType === $key ? 'checked' : '' ?>>
                                <label for="type-<?= e($key) ?>">
                                    <?= icon($meta['icon'], '', 20) ?>
                                    <span><?= e($meta['label']) ?></span>
                                    <small><?= e($meta['prefix']) ?>-<?= date('Y') ?>-0001</small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-grid mt-3">
                        <div>
                            <label class="form-label-dp" for="currency">Currency</label>
                            <select id="currency" name="currency" class="select-dp">
                                <?php foreach (currencies() as $code => $meta): ?>
                                    <option value="<?= e($code) ?>" <?= $currency === $code ? 'selected' : '' ?>>
                                        <?= e($meta['symbol'] . ' ' . $code . ' · ' . $meta['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label-dp" for="issue_date">Document date</label>
                            <input type="date" id="issue_date" name="issue_date" class="input-dp"
                                   value="<?= e(old('issue_date') !== '' ? old('issue_date') : date('Y-m-d')) ?>" required>
                        </div>
                        <div>
                            <label class="form-label-dp" for="valid_until">Valid until <span class="opt">(optional)</span></label>
                            <input type="date" id="valid_until" name="valid_until" class="input-dp"
                                   value="<?= e(old('valid_until')) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2 -------------------------------------------------- -->
            <div class="card-dp">
                <div class="card-dp__head">
                    <h2>2 · Who is it for?</h2>
                    <button type="button" class="btn-dp btn-soft-dp btn-sm-dp" data-modal-open="#quick-client-modal">
                        <?= icon('plus', '', 15) ?> New client
                    </button>
                </div>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="client_id">Saved clients</label>
                        <select id="client_id" name="client_id" class="select-dp" data-client-select>
                            <option value="">— Enter client details manually —</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int) $client['id'] ?>"
                                        data-name="<?= e((string) $client['name']) ?>"
                                        data-company="<?= e((string) ($client['company'] ?? '')) ?>"
                                        data-email="<?= e((string) ($client['email'] ?? '')) ?>"
                                        data-phone="<?= e((string) ($client['phone'] ?? '')) ?>"
                                        data-address="<?= e((string) ($client['address'] ?? '')) ?>"
                                    <?= $clientId === (int) $client['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $client['name']) ?><?= !empty($client['company']) ? ' · ' . e((string) $client['company']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="client_name">Client name</label>
                            <input type="text" id="client_name" name="client_name" required
                                   class="input-dp <?= has_error('client_name') ? 'is-invalid-dp' : '' ?>"
                                   value="<?= e(old('client_name') !== '' ? old('client_name') : (string) ($selected_client['name'] ?? '')) ?>"
                                   placeholder="Rahul Verma">
                            <?php if (has_error('client_name')): ?><p class="field-error"><?= e(error_for('client_name')) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label-dp" for="client_company">Company <span class="opt">(optional)</span></label>
                            <input type="text" id="client_company" name="client_company" class="input-dp"
                                   value="<?= e(old('client_company') !== '' ? old('client_company') : (string) ($selected_client['company'] ?? '')) ?>"
                                   placeholder="ABC Technologies">
                        </div>
                        <div>
                            <label class="form-label-dp" for="client_email">Email <span class="opt">(optional)</span></label>
                            <input type="email" id="client_email" name="client_email" class="input-dp"
                                   value="<?= e(old('client_email') !== '' ? old('client_email') : (string) ($selected_client['email'] ?? '')) ?>">
                        </div>
                        <div>
                            <label class="form-label-dp" for="client_phone">Phone <span class="opt">(optional)</span></label>
                            <input type="text" id="client_phone" name="client_phone" class="input-dp"
                                   value="<?= e(old('client_phone') !== '' ? old('client_phone') : (string) ($selected_client['phone'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="form-row mt-3 mb-0">
                        <label class="form-label-dp" for="client_address">Client address <span class="opt">(optional)</span></label>
                        <textarea id="client_address" name="client_address" class="textarea-dp" rows="2"><?= e(old('client_address') !== '' ? old('client_address') : (string) ($selected_client['address'] ?? '')) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Step 3 -------------------------------------------------- -->
            <div class="ai-panel pos-rel" data-editor-section>
                <div class="loading-overlay" data-ai-overlay>
                    <div class="text-center">
                        <span class="spinner-dp spinner-dark" style="width:26px;height:26px"></span>
                        <p class="mb-0 mt-2 text-muted-2" style="font-size:.9rem">Drafting your document…</p>
                    </div>
                </div>

                <h3><?= icon('sparkles', '', 20) ?> 3 · What do you want to create?</h3>
                <p class="hint">One or two sentences is enough. No prompt engineering needed.</p>

                <textarea name="ai_prompt" class="textarea-dp" rows="4"
                          placeholder="Create a professional quotation for ABC Technologies for website development worth ₹40,000 including 3 months maintenance."><?= e(old('ai_prompt')) ?></textarea>

                <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                    <?php if ($ai_ready): ?>
                        <button type="button" class="btn-dp btn-primary-dp" data-ai-generate>
                            <?= icon('sparkles', '', 17) ?> Generate with AI
                        </button>
                        <span class="text-muted-2" style="font-size:.85rem">
                            Fills the title, summary, line items, notes and terms — edit anything afterwards.
                        </span>
                    <?php else: ?>
                        <span class="badge badge-warning"><?= icon('alert', '', 13) ?> AI unavailable</span>
                        <span class="text-muted-2" style="font-size:.85rem">
                            <?= $ai_enabled
                                ? 'An administrator needs to add an OpenRouter API key. You can still fill everything in manually.'
                                : 'AI is switched off by the administrator. You can still fill everything in manually.' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 4 -------------------------------------------------- -->
            <?= view_partial('partials.document-items', [
                'items' => [],
                'currency' => $currency,
                'discount_type' => (string) (old('discount_type') !== '' ? old('discount_type') : 'fixed'),
                'discount_value' => (float) (old('discount_value') !== '' ? (float) old('discount_value') : 0),
            ]) ?>

            <!-- Step 5 -------------------------------------------------- -->
            <div class="card-dp">
                <div class="card-dp__head"><h2>5 · Content</h2></div>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="title">Document title</label>
                        <input type="text" id="title" name="title" required
                               class="input-dp <?= has_error('title') ? 'is-invalid-dp' : '' ?>"
                               value="<?= e(old('title')) ?>" placeholder="Website Development Quotation">
                        <?php if (has_error('title')): ?><p class="field-error"><?= e(error_for('title')) ?></p><?php endif; ?>
                    </div>

                    <div class="form-row">
                        <label class="form-label-dp" for="summary">Summary <span class="opt">(optional)</span></label>
                        <textarea id="summary" name="summary" class="textarea-dp" rows="2"
                                  placeholder="Professional website development services including design, build and support."><?= e(old('summary')) ?></textarea>
                        <?php if ($ai_ready): ?>
                            <div class="ai-tools">
                                <button type="button" class="ai-tool" data-ai-action="improve" data-ai-target="#summary"><?= icon('sparkles', '', 14) ?> Improve</button>
                                <button type="button" class="ai-tool" data-ai-action="professional" data-ai-target="#summary">Make professional</button>
                                <button type="button" class="ai-tool" data-ai-action="shorter" data-ai-target="#summary">Make shorter</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-row">
                        <label class="form-label-dp" for="notes">Notes <span class="opt">(shown to the client)</span></label>
                        <textarea id="notes" name="notes" class="textarea-dp" rows="3"><?= e(old('notes') !== '' ? old('notes') : (string) ($profile['default_notes'] ?? '')) ?></textarea>
                        <?php if ($ai_ready): ?>
                            <div class="ai-tools">
                                <button type="button" class="ai-tool" data-ai-action="improve" data-ai-target="#notes"><?= icon('sparkles', '', 14) ?> Improve</button>
                                <button type="button" class="ai-tool" data-ai-action="grammar" data-ai-target="#notes">Fix grammar</button>
                                <button type="button" class="ai-tool" data-ai-action="expand" data-ai-target="#notes">Expand</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-row mb-0">
                        <label class="form-label-dp" for="terms">Terms &amp; conditions</label>
                        <textarea id="terms" name="terms" class="textarea-dp" rows="5"><?= e(old('terms') !== '' ? old('terms') : (string) ($profile['default_terms'] ?? '')) ?></textarea>
                        <div class="ai-tools">
                            <?php if ($ai_ready): ?>
                                <button type="button" class="ai-tool" data-ai-terms data-ai-target="#terms"><?= icon('sparkles', '', 14) ?> Generate terms with AI</button>
                                <button type="button" class="ai-tool" data-ai-action="professional" data-ai-target="#terms">Make professional</button>
                                <button type="button" class="ai-tool" data-ai-action="shorter" data-ai-target="#terms">Make shorter</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar ---------------------------------------------------- -->
        <div class="col-xl-4">
            <div class="card-dp">
                <div class="card-dp__head"><h3>Template</h3></div>
                <div class="card-dp__body">
                    <div class="template-grid">
                        <?php foreach ($templates as $template): ?>
                            <?php $locked = !$all_templates && (int) $template['is_basic'] !== 1; ?>
                            <div class="template-card <?= $locked ? 'locked' : '' ?>">
                                <input type="radio" id="tpl-<?= e((string) $template['slug']) ?>" name="template"
                                       value="<?= e((string) $template['slug']) ?>"
                                    <?= $selectedTemplate === (string) $template['slug'] ? 'checked' : '' ?>
                                    <?= $locked ? 'disabled' : '' ?>>
                                <label for="tpl-<?= e((string) $template['slug']) ?>">
                                    <div class="template-card__preview" style="border-top:4px solid <?= e((string) $template['accent_color']) ?>">
                                        <i style="width:44%;height:7px;background:<?= e((string) $template['accent_color']) ?>"></i>
                                        <i style="width:76%;height:5px"></i>
                                        <i style="width:64%;height:5px"></i>
                                        <i style="width:88%;height:5px"></i>
                                        <i style="width:36%;height:5px;margin-left:auto"></i>
                                    </div>
                                    <div class="template-card__meta">
                                        <strong><?= e((string) $template['name']) ?></strong>
                                        <span><?= $locked ? 'Paid plans only' : e(str_excerpt((string) $template['description'], 38)) ?></span>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__head"><h3>Save</h3></div>
                <div class="card-dp__body">
                    <label class="form-label-dp" for="status">Status</label>
                    <select id="status" name="status" class="select-dp">
                        <option value="draft">Draft — still working on it</option>
                        <option value="final">Final — ready to send</option>
                    </select>

                    <button type="submit" class="btn-dp btn-primary-dp btn-block-dp btn-lg-dp mt-3">
                        <?= icon('check', '', 17) ?> Create document
                    </button>
                    <p class="field-hint text-center mt-2 mb-0">
                        Totals are recalculated on the server, then you can preview, export a PDF, share or email it.
                    </p>
                </div>
            </div>

            <div class="card-dp">
                <div class="card-dp__body">
                    <p class="small-caps mb-2">From your business profile</p>
                    <dl class="kv mb-0">
                        <dt>Business</dt><dd><?= e((string) ($profile['business_name'] ?? '—') ?: '—') ?></dd>
                        <dt>GSTIN</dt><dd><?= e((string) ($profile['gstin'] ?? '—') ?: '—') ?></dd>
                        <dt>Bank</dt><dd><?= e((string) ($profile['bank_name'] ?? '—') ?: '—') ?></dd>
                    </dl>
                    <p class="text-muted-2 mt-2 mb-0" style="font-size:.84rem">
                        AI never changes these values — they are printed from your profile.
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Quick client modal ------------------------------------------------- -->
<div class="modal-dp" id="quick-client-modal" role="dialog" aria-modal="true" aria-label="Add client">
    <div class="modal-dp__backdrop" data-modal-close></div>
    <div class="modal-dp__panel">
        <div class="modal-dp__head">
            <h3>Add a client</h3>
            <button type="button" class="btn-dp btn-ghost-dp btn-sm-dp" data-modal-close><?= icon('x', '', 16) ?></button>
        </div>
        <form data-quick-client-form>
            <div class="modal-dp__body">
                <div class="form-grid">
                    <div>
                        <label class="form-label-dp" for="qc-name">Client name</label>
                        <input type="text" id="qc-name" name="name" class="input-dp" required>
                    </div>
                    <div>
                        <label class="form-label-dp" for="qc-company">Company</label>
                        <input type="text" id="qc-company" name="company" class="input-dp">
                    </div>
                    <div>
                        <label class="form-label-dp" for="qc-email">Email</label>
                        <input type="email" id="qc-email" name="email" class="input-dp">
                    </div>
                    <div>
                        <label class="form-label-dp" for="qc-phone">Phone</label>
                        <input type="text" id="qc-phone" name="phone" class="input-dp">
                    </div>
                </div>
                <div class="form-row mt-3 mb-0">
                    <label class="form-label-dp" for="qc-address">Address</label>
                    <textarea id="qc-address" name="address" class="textarea-dp" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-dp__foot">
                <button type="button" class="btn-dp btn-ghost-dp" data-modal-close>Cancel</button>
                <button type="submit" class="btn-dp btn-primary-dp"><?= icon('check', '', 16) ?> Save client</button>
            </div>
        </form>
    </div>
</div>
