// === Shared admin helpers (whitelist page; the dashboard keeps its own copies in admin.js) ===
// Exposes window.AdminCommon = { apiCall, esc, showToast, confirmAction, promptModal, flashTip, makeSortStack,
// renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard, el }. Everything renders via textContent /
// createElement — values shown here (torrent names, file paths, IPs, snapshots) come from untrusted sources.
(function () {
    'use strict';

    const API_BASE = () => document.body.dataset.apiBase || '';
    const CSRF = () => document.body.dataset.csrf || '';

    /** JSON call to api.php. Adds X-CSRF-Token. Resolves with the parsed body (non-2xx bodies carry `error`). */
    async function apiCall(endpoint, method = 'GET', body = null) {
        const opts = { method, headers: { 'Accept': 'application/json' } };
        const csrf = CSRF();
        if (csrf) opts.headers['X-CSRF-Token'] = csrf;
        if (body !== null && body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(API_BASE() + endpoint, opts);
        let json;
        try { json = await res.json(); } catch { json = { error: 'Invalid server response (HTTP ' + res.status + ')' }; }
        if (!res.ok && json && json.error === undefined) json.error = 'HTTP ' + res.status;
        json.__status = res.status;
        return json;
    }

    function esc(str) {
        if (str === null || str === undefined || str === '') return '';
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    /** createElement helper: el('td', {className:'x', title:'y'}, [child, 'text']) */
    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        if (attrs) {
            for (const [k, v] of Object.entries(attrs)) {
                if (v === null || v === undefined || v === false) continue;
                if (k === 'className') node.className = v;
                else if (k === 'text') node.textContent = v;
                else if (k === 'html') node.innerHTML = v; // only for trusted static markup (icons)
                else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
                else if (k === 'dataset') Object.assign(node.dataset, v);
                else node.setAttribute(k, v === true ? '' : v);
            }
        }
        if (children !== undefined) {
            (Array.isArray(children) ? children : [children]).forEach(c => {
                if (c === null || c === undefined || c === false) return;
                node.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
            });
        }
        return node;
    }

    function ensureToastContainer() {
        let c = document.getElementById('toast-container');
        if (!c) {
            c = el('div', { id: 'toast-container', className: 'toast-container position-fixed top-0 start-50 translate-middle-x p-3', style: 'z-index:1080;' });
            document.body.appendChild(c);
        }
        return c;
    }

    /** showToast(message, type) — type: success | danger | warning | info. Also accepts legacy (type, msg). */
    function showToast(msg, type = 'success') {
        if (['success', 'danger', 'warning', 'info', 'error'].includes(msg) && typeof type === 'string' && !['success', 'danger', 'warning', 'info', 'error'].includes(type)) {
            [msg, type] = [type, msg];
        }
        if (type === 'error') type = 'danger';
        const iconMap = { success: 'bi-check-circle-fill text-success', danger: 'bi-exclamation-circle-fill text-danger', warning: 'bi-exclamation-triangle-fill text-warning', info: 'bi-info-circle-fill text-info' };
        const container = ensureToastContainer();
        const toast = el('div', { className: 'toast align-items-center border-0 show toast-dark', role: 'alert' }, [
            el('div', { className: 'd-flex' }, [
                el('div', { className: 'toast-body text-light' }, [el('i', { className: 'bi ' + (iconMap[type] || iconMap.info) }), ' ', String(msg)]),
                el('button', { type: 'button', className: 'btn-close btn-close-white me-2 m-auto', onclick: () => toast.remove() }),
            ]),
        ]);
        container.appendChild(toast);
        setTimeout(() => toast.remove(), type === 'danger' ? 7000 : 4000);
    }

    /* Small dialogs (confirm / prompt) may be opened on top of another modal (details modal → Ban / Delete).
       Bootstrap drops body.modal-open when the top one closes, which un-locks page scroll under the modal
       that is still open — put it back. */
    function restoreModalOpen() {
        if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
    }

    let confirmModalEl = null;
    /** confirmAction(title, message, {okLabel, danger}) → Promise<boolean>. Also accepts (message). */
    function confirmAction(title, message, opts = {}) {
        if (message === undefined) { message = title; title = 'Please confirm'; }
        return new Promise((resolve) => {
            if (!confirmModalEl) {
                confirmModalEl = el('div', { className: 'modal confirm-modal', id: 'commonConfirmModal', tabindex: '-1' }, [
                    el('div', { className: 'modal-dialog modal-dialog-centered modal-sm' }, [
                        el('div', { className: 'modal-content bg-dark' }, [
                            el('div', { className: 'modal-body text-center' }, [
                                el('h6', { className: 'text-light mb-2', id: 'commonConfirm-title' }),
                                el('p', { className: 'text-light mb-3 confirm-msg', id: 'commonConfirm-msg' }),
                                el('div', { className: 'd-flex justify-content-center gap-2' }, [
                                    el('button', { className: 'btn btn-sm btn-outline-secondary', id: 'commonConfirm-cancel', type: 'button' }, [el('i', { className: 'bi bi-x-lg' }), ' Cancel']),
                                    el('button', { className: 'btn btn-sm btn-outline-danger', id: 'commonConfirm-ok', type: 'button' }, [el('i', { className: 'bi bi-check-lg' }), ' OK']),
                                ]),
                            ]),
                        ]),
                    ]),
                ]);
                document.body.appendChild(confirmModalEl);
            }
            confirmModalEl.querySelector('#commonConfirm-title').textContent = title || '';
            confirmModalEl.querySelector('#commonConfirm-msg').textContent = message || '';
            const okBtn = confirmModalEl.querySelector('#commonConfirm-ok');
            const cancelBtn = confirmModalEl.querySelector('#commonConfirm-cancel');
            okBtn.className = 'btn btn-sm ' + (opts.danger === false ? 'btn-outline-info' : 'btn-outline-danger');
            okBtn.textContent = '';
            okBtn.appendChild(el('i', { className: 'bi bi-check-lg' }));
            okBtn.appendChild(document.createTextNode(' ' + (opts.okLabel || 'OK')));
            const modal = bootstrap.Modal.getOrCreateInstance(confirmModalEl);
            let resolved = false;
            const cleanup = () => {
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                confirmModalEl.removeEventListener('hidden.bs.modal', onHidden);
            };
            const onOk = () => { resolved = true; cleanup(); modal.hide(); resolve(true); };
            const onCancel = () => { resolved = true; cleanup(); modal.hide(); resolve(false); };
            const onHidden = () => { restoreModalOpen(); if (!resolved) { cleanup(); resolve(false); } };
            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            confirmModalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    let promptModalEl = null;
    /**
     * promptModal({ title, label, value, placeholder, okLabel, danger, multiline, maxlength, hint })
     * → Promise<string|null>. Replacement for window.prompt: one shared Bootstrap modal, input focused on
     * show, Enter submits (Ctrl/Cmd+Enter for the textarea), Esc / Cancel / backdrop resolve null.
     * The value is returned untrimmed (empty string is a valid answer — callers decide).
     */
    function promptModal(opts = {}) {
        if (typeof opts === 'string') opts = { title: opts };
        const o = Object.assign({ title: 'Input', label: '', value: '', placeholder: '', okLabel: 'OK', danger: false, multiline: false, maxlength: null, hint: '' }, opts);
        return new Promise((resolve) => {
            if (!promptModalEl) {
                promptModalEl = el('div', { className: 'modal confirm-modal prompt-modal', id: 'commonPromptModal', tabindex: '-1', 'aria-labelledby': 'commonPrompt-title' }, [
                    el('div', { className: 'modal-dialog modal-dialog-centered prompt-dialog' }, [
                        el('div', { className: 'modal-content bg-dark' }, [
                            el('div', { className: 'modal-body' }, [
                                el('h6', { className: 'text-light mb-3 prompt-title', id: 'commonPrompt-title' }),
                                el('label', { className: 'form-label wl-label prompt-label', id: 'commonPrompt-label', for: 'commonPrompt-input' }),
                                el('div', { id: 'commonPrompt-field' }),
                                el('div', { className: 'wl-small text-muted mt-1 prompt-hint', id: 'commonPrompt-hint' }),
                                el('div', { className: 'd-flex justify-content-end gap-2 mt-3' }, [
                                    el('button', { className: 'btn btn-sm btn-outline-secondary', id: 'commonPrompt-cancel', type: 'button' }, [el('i', { className: 'bi bi-x-lg' }), ' Cancel']),
                                    el('button', { className: 'btn btn-sm btn-primary', id: 'commonPrompt-ok', type: 'button' }),
                                ]),
                            ]),
                        ]),
                    ]),
                ]);
                document.body.appendChild(promptModalEl);
            }
            promptModalEl.querySelector('#commonPrompt-title').textContent = o.title || '';
            const labelEl = promptModalEl.querySelector('#commonPrompt-label');
            labelEl.textContent = o.label || '';
            labelEl.classList.toggle('d-hidden', !o.label);
            const hintEl = promptModalEl.querySelector('#commonPrompt-hint');
            hintEl.textContent = o.hint || '';
            hintEl.classList.toggle('d-hidden', !o.hint);
            // Fresh field each time so input ⇄ textarea and maxlength never leak between calls.
            const field = promptModalEl.querySelector('#commonPrompt-field');
            field.textContent = '';
            const input = o.multiline
                ? el('textarea', { className: 'form-control form-control-sm bg-dark text-light border-secondary', id: 'commonPrompt-input', rows: '3' })
                : el('input', { type: 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary', id: 'commonPrompt-input', autocomplete: 'off' });
            if (o.placeholder) input.setAttribute('placeholder', o.placeholder);
            if (o.maxlength) input.setAttribute('maxlength', String(o.maxlength));
            input.value = o.value == null ? '' : String(o.value);
            field.appendChild(input);
            const okBtn = promptModalEl.querySelector('#commonPrompt-ok');
            const cancelBtn = promptModalEl.querySelector('#commonPrompt-cancel');
            okBtn.className = 'btn btn-sm ' + (o.danger ? 'btn-danger' : 'btn-primary');
            okBtn.textContent = '';
            okBtn.appendChild(el('i', { className: 'bi ' + (o.danger ? 'bi-slash-circle' : 'bi-check-lg') }));
            okBtn.appendChild(document.createTextNode(' ' + (o.okLabel || 'OK')));

            const modal = bootstrap.Modal.getOrCreateInstance(promptModalEl);
            let resolved = false;
            const cleanup = () => {
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                input.removeEventListener('keydown', onKey);
                promptModalEl.removeEventListener('shown.bs.modal', onShown);
                promptModalEl.removeEventListener('hidden.bs.modal', onHidden);
            };
            const finish = (val) => { if (resolved) return; resolved = true; cleanup(); modal.hide(); resolve(val); };
            const onOk = () => finish(input.value);
            const onCancel = () => finish(null);
            const onKey = (e) => {
                if (e.key !== 'Enter' || e.isComposing) return;
                if (o.multiline && !(e.ctrlKey || e.metaKey)) return;
                e.preventDefault();
                finish(input.value);
            };
            const onShown = () => { input.focus(); if (!o.multiline && input.value) input.select(); };
            const onHidden = () => { restoreModalOpen(); if (!resolved) { resolved = true; cleanup(); resolve(null); } };
            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            input.addEventListener('keydown', onKey);
            promptModalEl.addEventListener('shown.bs.modal', onShown);
            promptModalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    /**
     * flashTip(el, text, {variant, duration, placement}) — short Bootstrap tooltip anchored to the element the
     * user just clicked ("Copied!"), auto-disposed after ~1.2 s. Falls back to showToast when the tooltip
     * plugin (needs the bootstrap *bundle* with Popper) is unavailable or the element is not in the DOM.
     */
    function flashTip(target, text, opts = {}) {
        const variant = opts.variant === 'error' ? 'danger' : (opts.variant || 'success');
        const ms = Number(opts.duration) > 0 ? Number(opts.duration) : 1200;
        const bs = typeof bootstrap !== 'undefined' ? bootstrap : null;
        if (!bs || !bs.Tooltip || !target || !(target instanceof Element) || !target.isConnected) {
            showToast(text, variant);
            return;
        }
        try {
            const prev = bs.Tooltip.getInstance(target);
            if (prev) prev.dispose();
            const tip = new bs.Tooltip(target, {
                title: String(text),
                trigger: 'manual',
                placement: opts.placement || 'top',
                container: 'body',
                customClass: 'flash-tip flash-tip-' + variant,
                animation: true,
            });
            tip.show();
            setTimeout(() => { try { tip.dispose(); } catch { /* element gone */ } }, ms);
        } catch {
            showToast(text, variant);
        }
    }

    /**
     * Multi-column sort stack bound to a table's th.sortable[data-sort] headers.
     * Click cycles asc → desc → removed; other columns keep their place (priority badges when >1).
     * makeSortStack({ table, defaultSort:[{col,dir}], onChange }) → { get, serialize, reset, bindHeaders, update }
     */
    function makeSortStack({ table, defaultSort = [{ col: 'date', dir: 'desc' }], onChange = null }) {
        let stack = defaultSort.map(s => ({ ...s }));
        const api = {
            get: () => stack.map(s => ({ ...s })),
            serialize: () => stack.map(s => s.col + ':' + s.dir).join(','),
            reset: () => { stack = defaultSort.map(s => ({ ...s })); api.update(); },
            set: (arr) => { stack = arr.map(s => ({ ...s })); api.update(); },
            update: () => {
                if (!table) return;
                table.querySelectorAll('th.sortable').forEach(th => {
                    const icon = th.querySelector('.sort-icon');
                    if (!icon) return;
                    const col = th.dataset.sort;
                    const idx = stack.findIndex(s => s.col === col);
                    const oldBadge = th.querySelector('.sort-priority');
                    if (oldBadge) oldBadge.remove();
                    if (idx !== -1) {
                        const s = stack[idx];
                        icon.className = s.dir === 'asc' ? 'bi bi-arrow-up sort-icon active' : 'bi bi-arrow-down sort-icon active';
                        if (stack.length > 1) {
                            const badge = el('sup', { className: 'sort-priority', text: String(idx + 1) });
                            icon.after(badge);
                        }
                    } else {
                        icon.className = 'bi bi-arrow-down-up sort-icon';
                    }
                });
            },
            bindHeaders: () => {
                if (!table) return;
                table.querySelectorAll('th.sortable').forEach(th => {
                    th.addEventListener('click', () => {
                        const col = th.dataset.sort;
                        const idx = stack.findIndex(s => s.col === col);
                        if (idx === -1) stack.push({ col, dir: 'asc' });
                        else if (stack[idx].dir === 'asc') stack[idx].dir = 'desc';
                        else stack.splice(idx, 1);
                        api.update();
                        if (onChange) onChange(api.get());
                    });
                });
                api.update();
            },
        };
        return api;
    }

    /**
     * renderPagination(container, {total, page, pages, onPage}) — « First / ‹ Prev / [page] of M / Next › / Last ».
     * The number box jumps on Enter or change (clamped to 1..pages). Same look as the dashboard, which keeps
     * its own renderer in admin.js; this one is used by every whitelist view.
     */
    function renderPagination(container, { total = 0, page = 1, pages = 1, onPage }) {
        if (!container) return;
        container.textContent = '';
        page = Math.max(1, Number(page) || 1);
        pages = Math.max(1, Number(pages) || 1);
        if (pages <= 1) return;
        const go = (p) => {
            p = Math.min(pages, Math.max(1, Math.round(p)));
            if (p !== page && onPage) onPage(p);
        };
        const goFromInput = (inp) => {
            const raw = String(inp.value).trim();
            const n = raw === '' ? NaN : Number(raw);
            if (!isFinite(n)) { inp.value = String(page); return; } // empty / garbage: stay put
            go(n);
        };
        const btn = (label, target, disabled, cls) => {
            const b = el('button', { type: 'button', className: cls || null, disabled: disabled ? true : null }, label);
            b.addEventListener('click', () => go(target));
            return b;
        };
        const first = btn([el('i', { className: 'bi bi-chevron-double-left' }), ' First'], 1, page <= 1, 'pg-edge');
        const prev = btn([el('i', { className: 'bi bi-chevron-left' }), ' Prev'], page - 1, page <= 1);
        const next = btn(['Next ', el('i', { className: 'bi bi-chevron-right' })], page + 1, page >= pages);
        const last = btn(['Last ', el('i', { className: 'bi bi-chevron-double-right' })], pages, page >= pages, 'pg-edge');
        const input = el('input', { type: 'number', className: 'pg-input', min: '1', max: String(pages), step: '1', value: String(page), title: 'Go to page (Enter)', 'aria-label': 'Page number' });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); goFromInput(input); } });
        input.addEventListener('change', () => goFromInput(input));
        input.addEventListener('focus', () => input.select());
        const jump = el('span', { className: 'pg-jump' }, ['Page ', input, ` of ${pages}`]);
        container.appendChild(first);
        container.appendChild(prev);
        container.appendChild(jump);
        if (total) container.appendChild(el('span', { className: 'pg-total', text: `· ${total} rows` }));
        container.appendChild(next);
        container.appendChild(last);
    }

    // torrent sizes are powers of 1024 — label them with the matching IEC units (KiB/MiB/GiB)
    function fmtBytes(n) {
        n = Number(n);
        if (!isFinite(n) || n <= 0) return '—';
        const u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(n >= 100 ? 0 : n >= 10 ? 1 : 2)) + ' ' + u[i];
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(s);
        return d.toLocaleString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function fmtAgo(seconds) {
        seconds = Number(seconds);
        if (!isFinite(seconds) || seconds < 0) return '—';
        if (seconds < 60) return seconds + ' s';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' min';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' h ' + Math.floor((seconds % 3600) / 60) + ' min';
        return Math.floor(seconds / 86400) + ' d ' + Math.floor((seconds % 86400) / 3600) + ' h';
    }

    /**
     * copyToClipboard(text, anchor) — quick feedback is a tooltip on the clicked element (button, hash cell…),
     * not a toast. An icon-only button briefly swaps its icon for a check mark. Without an anchor (or without
     * the tooltip plugin) flashTip falls back to a toast.
     */
    function copyToClipboard(text, anchor) {
        const target = anchor instanceof Element ? anchor : null;
        const write = navigator.clipboard && navigator.clipboard.writeText
            ? navigator.clipboard.writeText(String(text))
            : Promise.reject(new Error('no clipboard'));
        return write.then(() => {
            if (target) {
                const icon = target.matches('button') ? target.querySelector('i.bi') : null;
                if (icon) {
                    const orig = icon.className;
                    icon.className = 'bi bi-check-lg text-success';
                    setTimeout(() => { icon.className = orig; }, 1200);
                }
            }
            flashTip(target, 'Copied!', { variant: 'success' });
        }).catch(() => flashTip(target, 'Clipboard not available', { variant: 'warning', duration: 2000 }));
    }

    window.AdminCommon = { apiCall, esc, el, showToast, confirmAction, promptModal, flashTip, makeSortStack, renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard };
})();
