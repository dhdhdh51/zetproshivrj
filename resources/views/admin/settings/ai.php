<?php
/**
 * @var array $config
 * @var bool  $enabled
 * @var bool  $configured
 * @var array $models
 * @var array $stats
 */
$maskedKey = $configured ? '••••••••••••' . substr((string) $config['api_key'], -4) : '';
?>
<div class="page-head">
    <div>
        <h1>AI settings</h1>
        <p>DocuPilot uses OpenRouter for every AI feature. Requests are made server-side — the key is never exposed to the browser.</p>
    </div>
    <div class="text-lg-end">
        <span class="badge <?= $configured ? ($enabled ? 'badge-success' : 'badge-warning') : 'badge-danger' ?>">
            <?= $configured ? ($enabled ? 'Active' : 'Configured but disabled') : 'Not configured' ?>
        </span>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-dp">
            <div class="card-dp__head"><h2><?= icon('sparkles', '', 18) ?> OpenRouter</h2></div>
            <form method="post" action="<?= e(url('admin/ai')) ?>" id="ai-settings-form">
                <?= csrf_field() ?>
                <div class="card-dp__body">
                    <div class="form-row">
                        <label class="form-label-dp" for="openrouter_api_key">API key</label>
                        <input type="text" id="openrouter_api_key" name="openrouter_api_key" class="input-dp mono"
                               value="<?= e($maskedKey) ?>" placeholder="sk-or-v1-…" autocomplete="off">
                        <p class="field-hint">
                            Get a key at <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>.
                            Leave the masked value untouched to keep the current key.
                        </p>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="form-label-dp" for="openrouter_model">Model</label>
                            <input type="text" id="openrouter_model" name="openrouter_model" class="input-dp mono" list="model-options"
                                   value="<?= e((string) $config['model']) ?>" required>
                            <datalist id="model-options">
                                <?php foreach ($models as $model): ?><option value="<?= e($model) ?>"></option><?php endforeach; ?>
                            </datalist>
                            <p class="field-hint">Any chat-completions model ID available on your OpenRouter account.</p>
                        </div>
                        <div>
                            <label class="form-label-dp" for="openrouter_base_url">Base URL</label>
                            <input type="url" id="openrouter_base_url" name="openrouter_base_url" class="input-dp mono"
                                   value="<?= e((string) $config['base_url']) ?>" required>
                        </div>
                        <div>
                            <label class="form-label-dp" for="ai_temperature">Temperature</label>
                            <input type="number" step="0.05" min="0" max="2" id="ai_temperature" name="ai_temperature" class="input-dp"
                                   value="<?= e((string) $config['temperature']) ?>" required>
                            <p class="field-hint">0 = predictable, 1+ = more creative. 0.3–0.5 works well for documents.</p>
                        </div>
                        <div>
                            <label class="form-label-dp" for="ai_max_tokens">Maximum tokens</label>
                            <input type="number" min="100" max="32000" id="ai_max_tokens" name="ai_max_tokens" class="input-dp"
                                   value="<?= e((string) $config['max_tokens']) ?>" required>
                        </div>
                    </div>

                    <div class="switch-row mt-3">
                        <div class="switch-row__text">
                            <strong>AI features enabled</strong>
                            <span>Turn off to hide every AI button across the app without deleting the key.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="ai_enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span></span>
                        </label>
                    </div>
                </div>
                <div class="card-dp__foot d-flex flex-wrap gap-2">
                    <button type="submit" class="btn-dp btn-primary-dp"><?= icon('check', '', 17) ?> Save AI settings</button>
                    <button type="submit" formaction="<?= e(url('admin/ai/test')) ?>" formnovalidate class="btn-dp btn-outline-dp">
                        <?= icon('zap', '', 17) ?> Test AI connection
                    </button>
                </div>
            </form>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>How AI is constrained</h3></div>
            <div class="card-dp__body">
                <ul class="mb-0" style="font-size:.92rem">
                    <li>The model receives the document type, business name, client name, line items and the user's instruction.</li>
                    <li>It is explicitly forbidden from producing GSTIN, tax numbers, bank details, addresses, phone numbers or emails.</li>
                    <li>It may only use amounts supplied by the user; when line items exist, the user's quantity, rate and tax are re-applied after generation.</li>
                    <li>Structured JSON is requested and validated before anything is written to a document.</li>
                    <li>Every request is rate limited per user and counted against the plan's monthly AI limit.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card-dp">
            <div class="card-dp__head"><h3>Usage</h3></div>
            <div class="card-dp__body">
                <dl class="kv mb-0">
                    <dt>Total generations</dt><dd><?= number_format((int) $stats['total']) ?></dd>
                    <dt>This month</dt><dd><?= number_format((int) $stats['this_month']) ?></dd>
                    <dt>Failed</dt><dd><?= number_format((int) $stats['failed']) ?></dd>
                    <dt>Tokens used</dt><dd><?= number_format((int) $stats['tokens']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card-dp">
            <div class="card-dp__head"><h3>Suggested models</h3></div>
            <div class="card-dp__body">
                <?php foreach ($models as $model): ?>
                    <div class="d-flex justify-content-between align-items-center py-1" style="font-size:.85rem">
                        <span class="mono"><?= e($model) ?></span>
                        <?php if ($model === (string) $config['model']): ?><span class="badge badge-primary">in use</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <p class="field-hint mt-2 mb-0">Pricing and availability depend on your OpenRouter account.</p>
            </div>
        </div>
    </div>
</div>
