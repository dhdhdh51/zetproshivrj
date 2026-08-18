/* ==========================================================================
   DocuPilot AI — front-end behaviour (vanilla JS, no build step)
   ========================================================================== */
(function () {
    'use strict';

    const DP = window.DP = {};

    const meta = (name) => {
        const el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') || '' : '';
    };

    DP.csrf = () => meta('csrf-token');
    DP.baseUrl = () => (meta('base-url') || '').replace(/\/$/, '');
    DP.url = (path) => DP.baseUrl() + '/' + String(path).replace(/^\//, '');

    /* ---------------------------------------------------------------- */
    /* Toasts                                                           */
    /* ---------------------------------------------------------------- */

    function toastStack() {
        let stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }
        return stack;
    }

    DP.toast = function (message, type = 'info', timeout = 5200) {
        if (!message) return;
        const el = document.createElement('div');
        el.className = 'toast-dp ' + type;
        el.setAttribute('role', 'status');
        el.innerHTML = '<div style="flex:1">' + String(message).replace(/</g, '&lt;') + '</div>'
            + '<button type="button" aria-label="Dismiss">&times;</button>';
        el.querySelector('button').addEventListener('click', () => remove(el));
        toastStack().appendChild(el);
        if (timeout) setTimeout(() => remove(el), timeout);

        function remove(node) {
            if (!node.parentNode) return;
            node.classList.add('hiding');
            setTimeout(() => node.remove(), 200);
        }
    };

    /* ---------------------------------------------------------------- */
    /* Requests                                                         */
    /* ---------------------------------------------------------------- */

    DP.request = async function (path, payload = {}, method = 'POST') {
        const options = {
            method,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        };

        if (method === 'POST') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = DP.csrf();
            options.body = JSON.stringify(Object.assign({ _token: DP.csrf() }, payload));
        }

        let response;
        try {
            response = await fetch(DP.url(path), options);
        } catch (e) {
            return { success: false, message: 'Network error — please check your connection and try again.' };
        }

        let body;
        try {
            body = await response.json();
        } catch (e) {
            return { success: false, message: 'Unexpected server response (HTTP ' + response.status + ').' };
        }

        if (!response.ok && typeof body.success === 'undefined') {
            body.success = false;
        }
        body.status = response.status;
        return body;
    };

    DP.busy = function (button, busy) {
        if (!button) return;
        if (busy) {
            button.dataset.label = button.innerHTML;
            button.classList.add('is-loading');
            button.disabled = true;
            const dark = !button.classList.contains('btn-primary-dp') && !button.classList.contains('btn-dark-dp');
            button.innerHTML = '<span class="spinner-dp ' + (dark ? 'spinner-dark' : '') + '"></span> Working…';
        } else {
            button.classList.remove('is-loading');
            button.disabled = false;
            if (button.dataset.label) button.innerHTML = button.dataset.label;
        }
    };

    /* ---------------------------------------------------------------- */
    /* Modals                                                           */
    /* ---------------------------------------------------------------- */

    DP.openModal = function (selector) {
        const modal = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!modal) return;
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        const focusable = modal.querySelector('input:not([type=hidden]), textarea, select, button');
        if (focusable) setTimeout(() => focusable.focus(), 60);
    };

    DP.closeModal = function (modal) {
        const node = typeof modal === 'string' ? document.querySelector(modal) : modal;
        if (!node) return;
        node.classList.remove('open');
        document.body.style.overflow = '';
    };

    /* ---------------------------------------------------------------- */
    /* Money helpers (display only — the server always recalculates)     */
    /* ---------------------------------------------------------------- */

    const CURRENCY_SYMBOLS = {
        INR: '\u20B9', USD: '$', EUR: '\u20AC', GBP: '\u00A3',
        AUD: 'A$', CAD: 'C$', AED: 'AED ', SGD: 'S$'
    };

    DP.symbol = (code) => CURRENCY_SYMBOLS[(code || 'INR').toUpperCase()] || ((code || '') + ' ');
    DP.money = (amount, code) => DP.symbol(code) + Number(amount || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });

    const num = (value) => {
        const parsed = parseFloat(String(value == null ? '' : value).replace(/,/g, ''));
        return isNaN(parsed) ? 0 : parsed;
    };
    const round2 = (value) => Math.round((value + Number.EPSILON) * 100) / 100;

    /* ---------------------------------------------------------------- */
    /* Document editor                                                  */
    /* ---------------------------------------------------------------- */

    function initItemsEditor(root) {
        const tbody = root.querySelector('[data-items-body]');
        const template = document.getElementById('item-row-template');
        if (!tbody || !template) return;

        const currencyInput = document.querySelector('[name="currency"]');
        const discountTypeInput = document.querySelector('[name="discount_type"]');
        const discountValueInput = document.querySelector('[name="discount_value"]');

        const currency = () => (currencyInput ? currencyInput.value : 'INR');

        function reindex() {
            Array.from(tbody.querySelectorAll('[data-item-row]')).forEach((row, index) => {
                row.querySelectorAll('[name]').forEach((field) => {
                    field.name = field.name.replace(/items\[\d+\]/, 'items[' + index + ']');
                });
                const label = row.querySelector('[data-row-number]');
                if (label) label.textContent = String(index + 1);
            });
        }

        function recalc() {
            const rows = Array.from(tbody.querySelectorAll('[data-item-row]'));
            let subtotal = 0;

            const lines = rows.map((row) => {
                const quantity = num(row.querySelector('[data-field="quantity"]').value);
                const rate = num(row.querySelector('[data-field="rate"]').value);
                const taxPercent = num(row.querySelector('[data-field="tax_percent"]').value);
                const lineSubtotal = round2(quantity * rate);
                subtotal += lineSubtotal;
                return { row, lineSubtotal, taxPercent };
            });

            subtotal = round2(subtotal);

            const discountType = discountTypeInput ? discountTypeInput.value : 'fixed';
            let discountValue = discountValueInput ? num(discountValueInput.value) : 0;
            let discountTotal;

            if (discountType === 'percent') {
                discountValue = Math.min(100, Math.max(0, discountValue));
                discountTotal = round2(subtotal * discountValue / 100);
            } else {
                discountTotal = Math.min(Math.max(0, discountValue), subtotal);
            }

            const factor = subtotal > 0 ? (subtotal - discountTotal) / subtotal : 1;
            let taxTotal = 0;

            lines.forEach((line) => {
                const taxable = round2(line.lineSubtotal * factor);
                const lineTax = round2(taxable * line.taxPercent / 100);
                taxTotal += lineTax;
                const amountCell = line.row.querySelector('[data-line-total]');
                if (amountCell) amountCell.textContent = DP.money(line.lineSubtotal, currency());
            });

            taxTotal = round2(taxTotal);
            const total = round2(subtotal - discountTotal + taxTotal);

            setText('[data-total="subtotal"]', DP.money(subtotal, currency()));
            setText('[data-total="tax"]', DP.money(taxTotal, currency()));
            setText('[data-total="discount"]', '− ' + DP.money(discountTotal, currency()));
            setText('[data-total="grand"]', DP.money(total, currency()));

            const empty = root.querySelector('[data-items-empty]');
            if (empty) empty.style.display = rows.length ? 'none' : '';
        }

        function setText(selector, value) {
            document.querySelectorAll(selector).forEach((el) => { el.textContent = value; });
        }

        DP.addItemRow = function (data = {}) {
            const index = tbody.querySelectorAll('[data-item-row]').length;
            const html = template.innerHTML.replace(/__INDEX__/g, String(index));
            const wrapper = document.createElement('tbody');
            wrapper.innerHTML = html.trim();
            const row = wrapper.querySelector('[data-item-row]');
            tbody.appendChild(row);

            ['description', 'quantity', 'unit', 'rate', 'tax_percent'].forEach((field) => {
                if (typeof data[field] !== 'undefined' && data[field] !== null) {
                    const input = row.querySelector('[data-field="' + field + '"]');
                    if (input) input.value = data[field];
                }
            });

            reindex();
            recalc();
            return row;
        };

        DP.setItems = function (items) {
            tbody.innerHTML = '';
            (items || []).forEach((item) => DP.addItemRow(item));
            if (!items || !items.length) DP.addItemRow({ quantity: 1, unit: 'unit', rate: 0, tax_percent: 0 });
        };

        DP.recalcItems = recalc;

        root.addEventListener('input', (event) => {
            if (event.target.closest('[data-item-row]') || event.target.name === 'discount_value') recalc();
        });
        root.addEventListener('change', (event) => {
            if (event.target.name === 'discount_type' || event.target.name === 'currency') recalc();
        });
        if (currencyInput) currencyInput.addEventListener('change', recalc);
        if (discountTypeInput) discountTypeInput.addEventListener('change', recalc);

        root.addEventListener('click', (event) => {
            const addBtn = event.target.closest('[data-add-item]');
            if (addBtn) {
                event.preventDefault();
                DP.addItemRow({ quantity: 1, unit: 'unit', rate: 0, tax_percent: 0 });
                const rows = tbody.querySelectorAll('[data-item-row]');
                const last = rows[rows.length - 1];
                if (last) last.querySelector('[data-field="description"]').focus();
            }

            const removeBtn = event.target.closest('[data-remove-item]');
            if (removeBtn) {
                event.preventDefault();
                const row = removeBtn.closest('[data-item-row]');
                if (row) row.remove();
                if (!tbody.querySelectorAll('[data-item-row]').length) {
                    DP.addItemRow({ quantity: 1, unit: 'unit', rate: 0, tax_percent: 0 });
                }
                reindex();
                recalc();
            }
        });

        if (!tbody.querySelectorAll('[data-item-row]').length) {
            DP.addItemRow({ quantity: 1, unit: 'unit', rate: 0, tax_percent: 0 });
        }

        recalc();
    }

    /* ---------------------------------------------------------------- */
    /* Client picker                                                    */
    /* ---------------------------------------------------------------- */

    function initClientPicker() {
        const select = document.querySelector('[data-client-select]');
        if (!select) return;

        const fill = (option) => {
            if (!option) return;
            const map = {
                client_name: option.dataset.name || '',
                client_company: option.dataset.company || '',
                client_email: option.dataset.email || '',
                client_phone: option.dataset.phone || '',
                client_address: option.dataset.address || ''
            };
            Object.keys(map).forEach((name) => {
                const field = document.querySelector('[name="' + name + '"]');
                if (field && (map[name] !== '' || field.dataset.autofill !== 'keep')) field.value = map[name];
            });
        };

        select.addEventListener('change', () => {
            if (!select.value) return;
            fill(select.options[select.selectedIndex]);
        });

        const quickForm = document.querySelector('[data-quick-client-form]');
        if (quickForm) {
            quickForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = quickForm.querySelector('[type="submit"]');
                DP.busy(button, true);

                const payload = {};
                new FormData(quickForm).forEach((value, key) => { payload[key] = value; });

                const result = await DP.request('api/clients', payload);
                DP.busy(button, false);

                if (!result.success) {
                    DP.toast(result.message || 'The client could not be saved.', 'error');
                    return;
                }

                const option = document.createElement('option');
                option.value = result.client.id;
                option.textContent = result.client.name + (result.client.company ? ' · ' + result.client.company : '');
                option.dataset.name = result.client.name;
                option.dataset.company = result.client.company;
                option.dataset.email = result.client.email;
                option.dataset.phone = result.client.phone;
                option.dataset.address = result.client.address;
                select.appendChild(option);
                select.value = String(result.client.id);
                fill(option);

                quickForm.reset();
                DP.closeModal('#quick-client-modal');
                DP.toast('Client added.', 'success');
            });
        }
    }

    /* ---------------------------------------------------------------- */
    /* AI: generate document draft                                      */
    /* ---------------------------------------------------------------- */

    function collectFormValue(name) {
        const field = document.querySelector('[name="' + name + '"]');
        if (!field) return '';
        if (field.type === 'radio') {
            const checked = document.querySelector('[name="' + name + '"]:checked');
            return checked ? checked.value : '';
        }
        return field.value;
    }

    function collectItems() {
        return Array.from(document.querySelectorAll('[data-item-row]')).map((row) => ({
            description: (row.querySelector('[data-field="description"]') || {}).value || '',
            quantity: (row.querySelector('[data-field="quantity"]') || {}).value || 1,
            unit: (row.querySelector('[data-field="unit"]') || {}).value || 'unit',
            rate: (row.querySelector('[data-field="rate"]') || {}).value || 0,
            tax_percent: (row.querySelector('[data-field="tax_percent"]') || {}).value || 0
        })).filter((item) => String(item.description).trim() !== '');
    }

    function initAiGenerate() {
        const button = document.querySelector('[data-ai-generate]');
        if (!button) return;

        button.addEventListener('click', async () => {
            const promptField = document.querySelector('[name="ai_prompt"]');
            const instructions = promptField ? promptField.value.trim() : '';

            if (instructions.length < 10) {
                DP.toast('Describe what you need in a sentence or two first.', 'warning');
                if (promptField) promptField.focus();
                return;
            }

            const overlay = document.querySelector('[data-ai-overlay]');
            if (overlay) overlay.classList.add('show');
            DP.busy(button, true);

            const result = await DP.request('api/ai/document', {
                instructions,
                document_type: collectFormValue('document_type'),
                currency: collectFormValue('currency'),
                client_id: collectFormValue('client_id'),
                client_name: collectFormValue('client_name'),
                client_company: collectFormValue('client_company'),
                discount_type: collectFormValue('discount_type'),
                discount_value: collectFormValue('discount_value'),
                items: collectItems()
            });

            DP.busy(button, false);
            if (overlay) overlay.classList.remove('show');

            if (!result.success) {
                DP.toast(result.message || 'The AI request failed.', 'error');
                if (result.upgrade) setTimeout(() => { window.location.href = DP.url('pricing'); }, 2200);
                return;
            }

            const data = result.data || {};
            setValue('title', data.title);
            setValue('summary', data.summary);
            setValue('notes', data.notes);
            setValue('terms', data.terms);

            if (data.items && data.items.length && DP.setItems) DP.setItems(data.items);

            const flag = document.querySelector('[name="ai_generated"]');
            if (flag) flag.value = '1';

            updateUsageBadges(result.usage);
            DP.toast(result.message || 'Draft generated.', 'success');

            const editorSection = document.querySelector('[data-editor-section]');
            if (editorSection) editorSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        function setValue(name, value) {
            if (typeof value !== 'string' || value.trim() === '') return;
            const field = document.querySelector('[name="' + name + '"]');
            if (field) field.value = value;
        }
    }

    function updateUsageBadges(usage) {
        if (!usage) return;
        document.querySelectorAll('[data-usage="ai"]').forEach((el) => {
            el.textContent = usage.ai_used + ' / ' + usage.ai_limit;
        });
        document.querySelectorAll('[data-usage="documents"]').forEach((el) => {
            el.textContent = usage.documents_used + ' / ' + usage.documents_limit;
        });
        document.querySelectorAll('[data-usage-bar="ai"]').forEach((el) => {
            el.style.width = (usage.ai_percent || 0) + '%';
        });
    }

    /* ---------------------------------------------------------------- */
    /* AI: writing tools, terms, client email                           */
    /* ---------------------------------------------------------------- */

    function initAiTools() {
        document.addEventListener('click', async (event) => {
            const tool = event.target.closest('[data-ai-action]');
            if (tool) {
                event.preventDefault();
                const action = tool.dataset.aiAction;
                const target = document.querySelector(tool.dataset.aiTarget);

                if (!target) return;

                const text = target.value.trim();
                if (text.length < 3) {
                    DP.toast('Write a little text first, then let AI polish it.', 'warning');
                    target.focus();
                    return;
                }

                DP.busy(tool, true);
                const result = await DP.request('api/ai/write', {
                    action,
                    text,
                    document_id: tool.dataset.documentId || ''
                });
                DP.busy(tool, false);

                if (!result.success) {
                    DP.toast(result.message || 'The AI request failed.', 'error');
                    if (result.upgrade) setTimeout(() => { window.location.href = DP.url('pricing'); }, 2200);
                    return;
                }

                target.value = result.content;
                target.dispatchEvent(new Event('input', { bubbles: true }));
                updateUsageBadges(result.usage);
                DP.toast('Updated with AI.', 'success');
            }

            const termsBtn = event.target.closest('[data-ai-terms]');
            if (termsBtn) {
                event.preventDefault();
                const target = document.querySelector(termsBtn.dataset.aiTarget || '[name="terms"]');
                DP.busy(termsBtn, true);
                const result = await DP.request('api/ai/terms', {
                    document_type: collectFormValue('document_type'),
                    document_id: termsBtn.dataset.documentId || ''
                });
                DP.busy(termsBtn, false);

                if (!result.success) {
                    DP.toast(result.message || 'The AI request failed.', 'error');
                    return;
                }
                if (target) target.value = result.content;
                updateUsageBadges(result.usage);
                DP.toast('Terms & conditions generated.', 'success');
            }

            const emailBtn = event.target.closest('[data-ai-email]');
            if (emailBtn) {
                event.preventDefault();
                DP.busy(emailBtn, true);
                const result = await DP.request('api/ai/email', {
                    document_id: emailBtn.dataset.documentId || ''
                });
                DP.busy(emailBtn, false);

                if (!result.success) {
                    DP.toast(result.message || 'The AI request failed.', 'error');
                    return;
                }

                const subject = document.querySelector('[name="subject"]');
                const message = document.querySelector('[name="message"]');
                if (subject && result.subject) subject.value = result.subject;
                if (message && result.content) message.value = result.content;
                updateUsageBadges(result.usage);
                DP.toast('Email drafted with AI.', 'success');
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Misc UI                                                          */
    /* ---------------------------------------------------------------- */

    function initSidebar() {
        const sidebar = document.querySelector('.app-sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        const backdrop = document.querySelector('.sidebar-backdrop');
        if (!sidebar || !toggle) return;

        const close = () => {
            sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('show');
        };

        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            if (backdrop) backdrop.classList.toggle('show', sidebar.classList.contains('open'));
        });

        if (backdrop) backdrop.addEventListener('click', close);
        window.addEventListener('resize', () => { if (window.innerWidth > 992) close(); });
    }

    function initGlobalHandlers() {
        document.addEventListener('click', (event) => {
            const opener = event.target.closest('[data-modal-open]');
            if (opener) {
                event.preventDefault();
                DP.openModal(opener.dataset.modalOpen);
            }

            const closer = event.target.closest('[data-modal-close]');
            if (closer) {
                event.preventDefault();
                DP.closeModal(closer.closest('.modal-dp'));
            }

            if (event.target.classList.contains('modal-dp__backdrop')) {
                DP.closeModal(event.target.closest('.modal-dp'));
            }

            const copyBtn = event.target.closest('[data-copy]');
            if (copyBtn) {
                event.preventDefault();
                const value = copyBtn.dataset.copy;
                const done = () => DP.toast('Link copied to clipboard.', 'success', 2600);
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(value).then(done, fallback);
                } else {
                    fallback();
                }
                function fallback() {
                    const input = document.createElement('input');
                    input.value = value;
                    document.body.appendChild(input);
                    input.select();
                    try { document.execCommand('copy'); done(); } catch (e) { DP.toast(value, 'info', 9000); }
                    input.remove();
                }
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal-dp.open').forEach((modal) => DP.closeModal(modal));
            }
        });

        // Confirmation prompts on destructive forms/links.
        document.addEventListener('submit', (event) => {
            const form = event.target;
            const message = form.dataset.confirm;
            if (message && !window.confirm(message)) {
                event.preventDefault();
                return;
            }

            const submitter = event.submitter;
            if (submitter && submitter.dataset.noBusy === undefined && form.dataset.busy !== 'off') {
                setTimeout(() => DP.busy(submitter, true), 10);
            }
        });

        // Auto-submit filter selects.
        document.querySelectorAll('[data-auto-submit]').forEach((el) => {
            el.addEventListener('change', () => el.form && el.form.submit());
        });

        // Textarea character counters.
        document.querySelectorAll('[data-counter]').forEach((textarea) => {
            const target = document.querySelector(textarea.dataset.counter);
            if (!target) return;
            const update = () => { target.textContent = textarea.value.length + ' characters'; };
            textarea.addEventListener('input', update);
            update();
        });
    }

    /* ---------------------------------------------------------------- */
    /* Boot                                                             */
    /* ---------------------------------------------------------------- */

    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        initGlobalHandlers();
        initClientPicker();
        initAiGenerate();
        initAiTools();

        const editor = document.querySelector('[data-document-editor]');
        if (editor) initItemsEditor(editor);

        // Server-side flash messages arrive as data attributes on <body>.
        document.querySelectorAll('[data-flash]').forEach((node) => {
            DP.toast(node.dataset.flashMessage, node.dataset.flash);
            node.remove();
        });
    });
})();
