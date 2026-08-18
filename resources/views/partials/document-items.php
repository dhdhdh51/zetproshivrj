<?php
/**
 * Line items editor + live totals. Values shown here are for convenience only —
 * the server recalculates every amount on save.
 *
 * @var array  $items
 * @var string $currency
 * @var string $discount_type
 * @var float  $discount_value
 */
$items = $items ?? [];
$currency = $currency ?? 'INR';
$discountType = $discount_type ?? 'fixed';
$discountValue = (float) ($discount_value ?? 0);
$units = ['unit', 'hour', 'day', 'month', 'page', 'piece', 'project', 'set', 'kg', 'licence'];
?>
<div class="card-dp" data-items-editor>
    <div class="card-dp__head">
        <h2>Line items</h2>
        <button type="button" class="btn-dp btn-soft-dp btn-sm-dp" data-add-item><?= icon('plus', '', 15) ?> Add item</button>
    </div>

    <div class="card-dp__body">
        <div class="table-wrap">
            <table class="items-table">
                <thead>
                <tr>
                    <th style="width:34px">#</th>
                    <th>Description</th>
                    <th style="width:90px">Qty</th>
                    <th style="width:120px">Unit</th>
                    <th style="width:130px">Rate</th>
                    <th style="width:96px">Tax %</th>
                    <th style="width:120px" class="text-end">Amount</th>
                    <th style="width:44px"></th>
                </tr>
                </thead>
                <tbody data-items-body>
                <?php foreach ($items as $index => $item): ?>
                    <tr data-item-row>
                        <td class="text-muted-2 pt-3" data-row-number><?= $index + 1 ?></td>
                        <td>
                            <input type="text" class="input-dp" data-field="description"
                                   name="items[<?= $index ?>][description]" required
                                   value="<?= e((string) $item['description']) ?>" placeholder="Website design and development">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" class="input-dp" data-field="quantity"
                                   name="items[<?= $index ?>][quantity]" value="<?= e(rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.')) ?>">
                        </td>
                        <td>
                            <input type="text" class="input-dp" list="unit-options" data-field="unit"
                                   name="items[<?= $index ?>][unit]" value="<?= e((string) $item['unit']) ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" class="input-dp" data-field="rate"
                                   name="items[<?= $index ?>][rate]" value="<?= e(number_format((float) $item['rate'], 2, '.', '')) ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" max="100" class="input-dp" data-field="tax_percent"
                                   name="items[<?= $index ?>][tax_percent]" value="<?= e(rtrim(rtrim(number_format((float) $item['tax_percent'], 2, '.', ''), '0'), '.')) ?>">
                        </td>
                        <td class="text-end pt-3 fw-650" data-line-total><?= e(money((float) $item['line_subtotal'], $currency)) ?></td>
                        <td class="pt-2">
                            <button type="button" class="item-remove" data-remove-item aria-label="Remove item"><?= icon('trash', '', 15) ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <datalist id="unit-options">
            <?php foreach ($units as $unit): ?><option value="<?= e($unit) ?>"></option><?php endforeach; ?>
        </datalist>

        <template id="item-row-template">
            <tr data-item-row>
                <td class="text-muted-2 pt-3" data-row-number>1</td>
                <td>
                    <input type="text" class="input-dp" data-field="description" name="items[__INDEX__][description]"
                           required placeholder="Describe the product or service">
                </td>
                <td><input type="number" step="0.01" min="0" class="input-dp" data-field="quantity" name="items[__INDEX__][quantity]" value="1"></td>
                <td><input type="text" class="input-dp" list="unit-options" data-field="unit" name="items[__INDEX__][unit]" value="unit"></td>
                <td><input type="number" step="0.01" min="0" class="input-dp" data-field="rate" name="items[__INDEX__][rate]" value="0.00"></td>
                <td><input type="number" step="0.01" min="0" max="100" class="input-dp" data-field="tax_percent" name="items[__INDEX__][tax_percent]" value="0"></td>
                <td class="text-end pt-3 fw-650" data-line-total>—</td>
                <td class="pt-2"><button type="button" class="item-remove" data-remove-item aria-label="Remove item"><?= icon('trash', '', 15) ?></button></td>
            </tr>
        </template>

        <div class="row g-3 mt-3">
            <div class="col-md-6">
                <label class="form-label-dp">Discount <span class="opt">(applied to the subtotal)</span></label>
                <div class="d-flex gap-2">
                    <select name="discount_type" class="select-dp" style="max-width:150px">
                        <option value="fixed" <?= $discountType === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                        <option value="percent" <?= $discountType === 'percent' ? 'selected' : '' ?>>Percentage</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="discount_value" class="input-dp"
                           value="<?= e(number_format($discountValue, 2, '.', '')) ?>">
                </div>
                <p class="field-hint">
                    Tax is calculated per line on the discounted amount. Every total is verified on the server when you save.
                </p>
            </div>

            <div class="col-md-6">
                <div class="totals-box">
                    <div class="totals-row"><span>Subtotal</span><span data-total="subtotal">—</span></div>
                    <div class="totals-row"><span>Discount</span><span data-total="discount">—</span></div>
                    <div class="totals-row"><span>Tax</span><span data-total="tax">—</span></div>
                    <div class="totals-row grand"><span>Grand total</span><span data-total="grand">—</span></div>
                </div>
            </div>
        </div>
    </div>
</div>
