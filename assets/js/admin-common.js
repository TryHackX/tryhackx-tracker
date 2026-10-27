// === Shared admin helpers (every panel page; the dashboard's admin.js asks through them too since 1.74.0) ===
// Exposes window.AdminCommon = { apiCall, esc, el, emptyState, showToast, confirmAction, promptModal, flashTip, makeSortStack,
// renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard, el, reportsWaiting, … }. Everything renders via textContent /
// createElement — values shown here (torrent names, file paths, IPs, snapshots) come from untrusted sources.
// 1.75.0: adminRichField() — the one box for words with a format (plain / BBCode / Markdown, a bulk message's HTML), see its comment.
(function () {
    'use strict';

    const API_BASE = () => document.body.dataset.apiBase || '';
    const CSRF = () => document.body.dataset.csrf || '';

    /**
     * JSON call to api.php. Adds X-CSRF-Token. Resolves with the parsed body (non-2xx bodies carry `error`).
     *
     * A SESSION THAT HAS ENDED IS SAID HERE, ONCE, FOR EVERY PAGE OF THE PANEL (1.74.0). A panel endpoint answers 401
     * when the session is gone — idle past its limit (a computer asleep over lunch), past its twelve hours, signed out
     * in another tab, or signed out by a reauthentication locked out. The Reports page used to read that answer as an
     * empty queue ("No reports found. Total: 0", its badges gone), so somebody back at the desk saw nothing to do; every
     * other page said nothing or said a code. Now the answer carries `__expired` and the page gets one bar at its top —
     * "your session has expired, sign in again" — whose button reloads the page, which IS the way back: a panel page
     * asked for without a session renders the sign-in form in its place (index.php), and comes back after it. Only
     * `admin/*` endpoints speak for the panel's session; nothing else this file is asked to call does.
     */
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
        try { json = await res.json(); } catch { json = { error: t.key('js.common.invalid_response', { status: res.status }) }; }
        if (!json || typeof json !== 'object') json = { error: t.key('js.common.invalid_response', { status: res.status }) };
        if (!res.ok && json.error === undefined) json.error = 'HTTP ' + res.status;
        json.__status = res.status;
        // (The sign-in's own 401 — a wrong password — is not an ended session; the sign-in page has its own script.)
        if (res.status === 401 && String(endpoint).startsWith('admin/') && !String(endpoint).startsWith('admin/login')) {
            json.__expired = true;
            sessionExpired();
        }
        return json;
    }

    /** The bar that says the session has ended (see apiCall()). One per page, however many requests came back 401. */
    let expiredBar = null;
    function sessionExpired() {
        if (expiredBar && expiredBar.isConnected) return;
        expiredBar = el('div', { className: 'session-expired-bar', role: 'alert' }, [
            el('i', { className: 'bi bi-exclamation-triangle-fill', 'aria-hidden': 'true' }),
            el('span', { className: 'session-expired-text' }, t.key('js.common.session_expired')),
            el('button', { type: 'button', className: 'btn btn-sm btn-warning', onclick: () => location.reload() },
               t.key('js.common.sign_in_again')),
        ]);
        document.body.prepend(expiredBar);
    }

    // A t.key() word comes out as markup that keeps its key (t.html(), assets/js/i18n.js, 1.73.0) — text content only.
    function esc(str) {
        if (t.isKey(str)) return t.html(str);
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
                // data-* through setAttribute, which keeps a t.key() word's key (dataset writes its words only, 1.73.0)
                else if (k === 'dataset') Object.keys(v).forEach(d => node.setAttribute('data-' + d.replace(/[A-Z]/g, c => '-' + c.toLowerCase()), v[d]));
                else node.setAttribute(k, v === true ? '' : v);
            }
        }
        if (children !== undefined) {
            (Array.isArray(children) ? children : [children]).forEach(c => {
                if (c === null || c === undefined || c === false) return;
                node.appendChild(t.child(c));   // a t.key() word as a <span> that keeps its key
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

    /**
     * showToast(message, type) — type: success | danger | warning | info. Also accepts legacy (type, msg).
     * `message` may be a t.key() word or a list of pieces (1.73.0): a t.key() word in it keeps its key, so a toast still on the
     * screen when the language changes is said in the new one (assets/js/i18n.js).
     */
    function showToast(msg, type = 'success') {
        if (['success', 'danger', 'warning', 'info', 'error'].includes(msg) && typeof type === 'string' && !['success', 'danger', 'warning', 'info', 'error'].includes(type)) {
            [msg, type] = [type, msg];
        }
        if (type === 'error') type = 'danger';
        const iconMap = { success: 'bi-check-circle-fill text-success', danger: 'bi-exclamation-circle-fill text-danger', warning: 'bi-exclamation-triangle-fill text-warning', info: 'bi-info-circle-fill text-info' };
        const container = ensureToastContainer();
        const toast = el('div', { className: 'toast align-items-center border-0 show toast-dark', role: 'alert' }, [
            el('div', { className: 'd-flex' }, [
                el('div', { className: 'toast-body text-light' }, [el('i', { className: 'bi ' + (iconMap[type] || iconMap.info) }), ' ']
                    .concat((Array.isArray(msg) ? msg : [msg]).map(p => (p instanceof Node || t.isKey(p)) ? p : String(p)))),
                el('button', { type: 'button', className: 'btn-close btn-close-white me-2 m-auto', 'aria-label': t.key('js.common.close'), onclick: () => toast.remove() }),
            ]),
        ]);
        container.appendChild(toast);
        // Its clock stops while the pointer rests on it or the focus is in it (1.74.0), as the public pages' toast does:
        // an error read slowly, or its × reached with the keyboard, is not taken away mid-sentence.
        let left = type === 'danger' ? 7000 : 4000, since = Date.now(), timer = setTimeout(() => toast.remove(), left);
        let hover = false, focus = false;
        const sync = () => {
            if (hover || focus) { if (timer) { clearTimeout(timer); timer = 0; left = Math.max(1500, left - (Date.now() - since)); } }
            else if (!timer) { since = Date.now(); timer = setTimeout(() => toast.remove(), left); }
        };
        toast.addEventListener('pointerenter', () => { hover = true; sync(); });
        toast.addEventListener('pointerleave', () => { hover = false; sync(); });
        toast.addEventListener('focusin', () => { focus = true; sync(); });
        toast.addEventListener('focusout', (e) => { if (!toast.contains(e.relatedTarget)) { focus = false; sync(); } });
    }

    /* Small dialogs (confirm / prompt) may be opened on top of another modal (details modal → Ban / Delete).
       Bootstrap drops body.modal-open when the top one closes, which un-locks page scroll under the modal
       that is still open — put it back. */
    function restoreModalOpen() {
        if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
    }

    /**
     * Lift a small dialog above the modal it was opened from.
     *
     * Bootstrap gives every .modal the same z-index and appends each backdrop to the end of <body>,
     * so a confirm opened over an open modal gets a backdrop that sits ABOVE its own dialog: the
     * buttons are visible but greyed out and unclickable. Done in JS because "the backdrop that
     * belongs to this dialog" is the last one in the DOM — a fact no CSS selector can state.
     *
     * Returns a function that puts everything back.
     */
    function stackAbove(dialogEl) {
        const under = [...document.querySelectorAll('.modal.show')].filter(m => m !== dialogEl);
        if (!under.length) return () => {};
        const backdrops = document.querySelectorAll('.modal-backdrop');
        const top = backdrops[backdrops.length - 1] || null;
        dialogEl.style.zIndex = '1075';
        if (top) top.style.zIndex = '1070';
        return () => {
            dialogEl.style.zIndex = '';
            if (top) top.style.zIndex = '';
        };
    }

    /**
     * The focus goes back where it came from once a dialog this file opened has closed (1.74.0). Bootstrap gives it
     * back only to a data-bs-toggle button, and these open from code — after an answer, Esc or Cancel the focus fell to
     * <body>, and somebody working with the keyboard was back at the top of the page. Back to the element that had it
     * (the button that asked), if it is still on the page and drawn; not when the focus has moved on by itself.
     */
    function focusBackTo(from, modalEl) {
        return () => {
            const now = document.activeElement;
            if (now && now !== document.body && !modalEl.contains(now)) return;
            if (from && from.isConnected && from !== document.body && from.getClientRects().length && typeof from.focus === 'function') {
                try { from.focus({ preventScroll: true }); } catch (e) { from.focus(); }
            }
        };
    }

    let confirmModalEl = null;
    /** confirmAction(title, message, {okLabel, danger}) → Promise<boolean>. Also accepts (message). */
    function confirmAction(title, message, opts = {}) {
        if (message === undefined) { message = title; title = t.key('js.common.please_confirm'); }
        const from = document.activeElement;
        return new Promise((resolve) => {
            if (!confirmModalEl) {
                confirmModalEl = el('div', { className: 'modal confirm-modal', id: 'commonConfirmModal', tabindex: '-1' }, [
                    el('div', { className: 'modal-dialog modal-dialog-centered modal-sm' }, [
                        el('div', { className: 'modal-content bg-dark' }, [
                            el('div', { className: 'modal-body text-center' }, [
                                el('h6', { className: 'text-light mb-2', id: 'commonConfirm-title' }),
                                el('p', { className: 'text-light mb-2 confirm-msg', id: 'commonConfirm-msg' }),
                                // opts.code: an identifier the reader is meant to CHECK, not read past.
                                el('code', { className: 'confirm-code', id: 'commonConfirm-code', style: 'display:none' }),
                                el('p', { className: 'text-light mb-3 confirm-msg', id: 'commonConfirm-after', style: 'display:none' }),
                                el('div', { className: 'd-flex justify-content-center gap-2' }, [
                                    el('button', { className: 'btn btn-sm btn-outline-secondary', id: 'commonConfirm-cancel', type: 'button' }, [el('i', { className: 'bi bi-x-lg' }), ' ', t.key('js.common.cancel')]),
                                    el('button', { className: 'btn btn-sm btn-outline-danger', id: 'commonConfirm-ok', type: 'button' }, [el('i', { className: 'bi bi-check-lg' }), ' ', t.key('js.common.ok')]),
                                ]),
                            ]),
                        ]),
                    ]),
                ]);
                document.body.appendChild(confirmModalEl);
            }
            confirmModalEl.querySelector('#commonConfirm-title').textContent = title || '';
            confirmModalEl.querySelector('#commonConfirm-msg').textContent = message || '';
            const codeEl = confirmModalEl.querySelector('#commonConfirm-code');
            const afterEl = confirmModalEl.querySelector('#commonConfirm-after');
            codeEl.textContent = opts.code || '';
            codeEl.style.display = opts.code ? '' : 'none';
            afterEl.textContent = opts.after || '';
            afterEl.style.display = opts.after ? '' : 'none';
            const okBtn = confirmModalEl.querySelector('#commonConfirm-ok');
            const cancelBtn = confirmModalEl.querySelector('#commonConfirm-cancel');
            okBtn.className = 'btn btn-sm ' + (opts.danger === false ? 'btn-outline-info' : 'btn-outline-danger');
            okBtn.textContent = '';
            okBtn.appendChild(el('i', { className: 'bi bi-check-lg' }));
            okBtn.append(' ', opts.okLabel || t.key('js.common.ok'));   // a t.key() word keeps its key (1.73.0)
            const modal = bootstrap.Modal.getOrCreateInstance(confirmModalEl);
            let resolved = false;
            const cleanup = () => {
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                confirmModalEl.removeEventListener('hidden.bs.modal', onHidden);
            };
            const onOk = () => { resolved = true; cleanup(); modal.hide(); resolve(true); };
            const onCancel = () => { resolved = true; cleanup(); modal.hide(); resolve(false); };
            let unstack = () => {};
            const onHidden = () => { if (!resolved) { cleanup(); resolve(false); } };
            // Whichever way it closed — OK, Cancel, Esc, the backdrop — and only once: the window it was stacked over
            // gets its place back, and the focus goes back to what asked (1.74.0; the first two used to skip both).
            const back = focusBackTo(from, confirmModalEl);
            const afterHidden = () => {
                confirmModalEl.removeEventListener('hidden.bs.modal', afterHidden);
                unstack(); restoreModalOpen(); back();
            };
            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            confirmModalEl.addEventListener('hidden.bs.modal', onHidden);
            confirmModalEl.addEventListener('hidden.bs.modal', afterHidden);
            modal.show();
            unstack = stackAbove(confirmModalEl);
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
        if (typeof opts === 'string' || t.isKey(opts)) opts = { title: opts };
        const o = Object.assign({ title: t.key('js.common.input'), label: '', value: '', placeholder: '', okLabel: t.key('js.common.ok'), danger: false, multiline: false, maxlength: null, hint: '', password: false }, opts);
        const from = document.activeElement;
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
                                    el('button', { className: 'btn btn-sm btn-outline-secondary', id: 'commonPrompt-cancel', type: 'button' }, [el('i', { className: 'bi bi-x-lg' }), ' ', t.key('js.common.cancel')]),
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
            // a list of pieces too: t.key() words among them keep their keys (1.73.0)
            if (Array.isArray(o.hint)) hintEl.replaceChildren(...o.hint.filter(p => p !== null && p !== undefined && p !== false).map(t.child));
            else hintEl.textContent = o.hint || '';
            hintEl.classList.toggle('d-hidden', !o.hint);
            // Fresh field each time so input ⇄ textarea and maxlength never leak between calls.
            const field = promptModalEl.querySelector('#commonPrompt-field');
            field.textContent = '';
            const input = o.multiline
                ? el('textarea', { className: 'form-control form-control-sm bg-dark text-light border-secondary', id: 'commonPrompt-input', rows: '3' })
                : el('input', { type: o.password ? 'password' : 'text', className: 'form-control form-control-sm bg-dark text-light border-secondary', id: 'commonPrompt-input', autocomplete: o.password ? 'current-password' : 'off' });
            if (o.placeholder) input.setAttribute('placeholder', o.placeholder);
            if (o.maxlength) input.setAttribute('maxlength', String(o.maxlength));
            input.value = o.value == null ? '' : String(o.value);
            field.appendChild(input);
            const okBtn = promptModalEl.querySelector('#commonPrompt-ok');
            const cancelBtn = promptModalEl.querySelector('#commonPrompt-cancel');
            okBtn.className = 'btn btn-sm ' + (o.danger ? 'btn-danger' : 'btn-primary');
            okBtn.textContent = '';
            okBtn.appendChild(el('i', { className: 'bi ' + (o.danger ? 'bi-slash-circle' : 'bi-check-lg') }));
            okBtn.append(' ', o.okLabel || t.key('js.common.ok'));   // a t.key() word keeps its key (1.73.0)

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
            // The focus back to what asked, whichever way it closed (1.74.0, focusBackTo()).
            const back = focusBackTo(from, promptModalEl);
            const afterHidden = () => { promptModalEl.removeEventListener('hidden.bs.modal', afterHidden); restoreModalOpen(); back(); };
            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            input.addEventListener('keydown', onKey);
            promptModalEl.addEventListener('shown.bs.modal', onShown);
            promptModalEl.addEventListener('hidden.bs.modal', onHidden);
            promptModalEl.addEventListener('hidden.bs.modal', afterHidden);
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
     *
     * Each header holds a <button class="th-sort"> in the page's own markup (1.74.0): the keyboard reaches it, and Enter
     * or Space is its click, which the header hears. The order is told to a screen reader by `aria-sort` on the column
     * that decides it — the first of the stack (ARIA asks for one sorted header at a time); the others carry none.
     */
    function sortMark(th, dir, first) {
        if (dir && first) th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
        else th.removeAttribute('aria-sort');
    }
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
                            const badge = el('sup', { className: 'sort-priority', text: String(idx + 1), 'aria-hidden': 'true' });
                            icon.after(badge);
                        }
                        sortMark(th, s.dir, idx === 0);
                    } else {
                        icon.className = 'bi bi-arrow-down-up sort-icon';
                        sortMark(th, null, false);
                    }
                });
            },
            bindHeaders: () => {
                if (!table) return;
                table.querySelectorAll('th.sortable').forEach(th => {
                    th.addEventListener('click', () => {
                        const col = th.dataset.sort;
                        const idx = stack.findIndex(s => s.col === col);
                        // biggest-first is what people reach for on numeric columns: desc → asc → off
                        if (idx === -1) stack.push({ col, dir: 'desc' });
                        else if (stack[idx].dir === 'desc') stack[idx].dir = 'asc';
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
        const first = btn([el('i', { className: 'bi bi-chevron-double-left' }), ' ', t.key('js.common.pg_first')], 1, page <= 1, 'pg-edge');
        const prev = btn([el('i', { className: 'bi bi-chevron-left' }), ' ', t.key('js.common.pg_prev')], page - 1, page <= 1);
        // Words and glyphs as separate pieces: a t.key() word keeps its key only on its own (assets/js/i18n.js, 1.73.0).
        const next = btn([t.key('js.common.pg_next'), ' ', el('i', { className: 'bi bi-chevron-right' })], page + 1, page >= pages);
        const last = btn([t.key('js.common.pg_last'), ' ', el('i', { className: 'bi bi-chevron-double-right' })], pages, page >= pages, 'pg-edge');
        const input = el('input', { type: 'number', className: 'pg-input', min: '1', max: String(pages), step: '1', value: String(page), title: t.key('js.common.pg_goto_title'), 'aria-label': t.key('js.common.pg_page_number') });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); goFromInput(input); } });
        input.addEventListener('change', () => goFromInput(input));
        input.addEventListener('focus', () => input.select());
        const jump = el('span', { className: 'pg-jump' }, [t.key('js.common.pg_page'), ' ', input, ' ', t.key('js.common.pg_of', { n: pages })]);
        container.appendChild(first);
        container.appendChild(prev);
        container.appendChild(jump);
        if (total) container.appendChild(el('span', { className: 'pg-total', text: t.key('js.common.pg_rows', { n: total }) }));
        container.appendChild(next);
        container.appendChild(last);
    }

    // torrent sizes are powers of 1024 — label them with the matching IEC units (KiB/MiB/GiB)
    /**
     * animatedClear(input, done) — empties a search box with an accelerating "held backspace"
     * effect (each character deletes a bit faster than the last), then calls done() once. A very
     * long value fast-forwards to its last 40 characters first so the whole thing stays snappy.
     */
    function animatedClear(input, done) {
        if (!input) { if (done) done(); return; }
        if (input.__clearing) return;
        input.__clearing = true;
        if (input.value.length > 40) input.value = input.value.slice(-40);
        let delay = 55;
        const step = () => {
            if (!input.value.length) { input.__clearing = false; if (done) done(); return; }
            input.value = input.value.slice(0, -1);
            delay = Math.max(7, delay * 0.82);
            setTimeout(step, delay);
        };
        step();
    }

    /**
     * busyDot(anchor, on) — a small pulsing dot right after `anchor` (a counter like "1 234 rows")
     * while a list request is in flight. Silent background refreshes light it too, so the user can
     * SEE the live updates without the table dimming/flickering every 5 s.
     */
    function busyDot(anchor, on) {
        if (!anchor) return;
        let dot = anchor.nextElementSibling;
        if (!dot || !dot.classList || !dot.classList.contains('sync-dot')) {
            dot = el('span', { className: 'sync-dot', title: t.key('js.common.refreshing') });
            anchor.after(dot);
        }
        dot.classList.toggle('on', !!on);
    }

    /**
     * bindSearchClear(input, btn, onCleared) — the red-glow clear × next to a toolbar search box:
     * visible only while the box has text, click empties it with the accelerating animation and
     * then calls onCleared() once. (Only the dashboard used to toggle .visible — on the other
     * pages the button existed but stayed at opacity 0 forever.)
     */
    function bindSearchClear(input, btn, onCleared) {
        if (!input || !btn) return;
        const sync = () => btn.classList.toggle('visible', input.value.length > 0);
        input.addEventListener('input', sync);
        btn.addEventListener('click', () => animatedClear(input, () => { sync(); input.focus(); if (onCleared) onCleared(); }));
        sync();
    }

    /**
     * buildFileTree(files, opts) — collapsible folder tree for a torrent file list
     * ([{path,size}]). Shared by the Whitelist and Index detail modals. First 200 leaves render,
     * the rest hide behind a "Show all" toggle. opts.mark: lowercase substring — matching parts of
     * file names are wrapped in <mark> (used when the search matched on file names).
     */
    function buildFileTree(files, opts = {}) {
        const mark = (opts.mark || '').toLowerCase();
        const nameNode = (text) => {
            const span = el('span', { className: 'wl-tree-name', title: text });
            if (mark && text.toLowerCase().includes(mark)) {
                let rest = text;
                while (rest) {
                    const i = rest.toLowerCase().indexOf(mark);
                    if (i === -1) { span.appendChild(document.createTextNode(rest)); break; }
                    if (i > 0) span.appendChild(document.createTextNode(rest.slice(0, i)));
                    span.appendChild(el('mark', { text: rest.slice(i, i + mark.length) }));
                    rest = rest.slice(i + mark.length);
                }
            } else span.textContent = text;
            return span;
        };
        const root = { dirs: new Map(), files: [] };
        files.forEach(f => {
            const parts = String(f.path).split('/').filter(Boolean);
            let node = root;
            for (let i = 0; i < parts.length - 1; i++) {
                if (!node.dirs.has(parts[i])) node.dirs.set(parts[i], { dirs: new Map(), files: [] });
                node = node.dirs.get(parts[i]);
            }
            node.files.push({ name: parts[parts.length - 1] || String(f.path), size: f.size });
        });
        let rendered = 0;
        const LIMIT = 200;
        let hidden = 0;
        const container = el('div', { className: 'wl-tree' });
        function countFiles(n) { let c = n.files.length; n.dirs.forEach(d => { c += countFiles(d); }); return c; }
        function renderNode(node, parent, depth) {
            [...node.dirs.keys()].sort().forEach(name => {
                const sub = node.dirs.get(name);
                const det = el('details', { open: depth === 0 ? '' : null });
                det.appendChild(el('summary', null, [el('i', { className: 'bi bi-chevron-right disc-chev', 'aria-hidden': 'true' }), el('i', { className: 'bi bi-folder2' }), ' ', nameNode(name), ' ', el('span', { className: 'text-muted wl-small', text: `(${countFiles(sub)})` })]));
                const inner = el('div', { className: 'wl-tree-children' });
                renderNode(sub, inner, depth + 1);
                det.appendChild(inner);
                parent.appendChild(det);
            });
            node.files.sort((a, b) => a.name.localeCompare(b.name)).forEach(f => {
                rendered++;
                const line = el('div', { className: 'wl-tree-file' + (rendered > LIMIT ? ' wl-tree-more d-hidden' : '') }, [el('i', { className: 'bi bi-file-earmark' }), ' ', nameNode(f.name), el('span', { className: 'wl-tree-size text-muted', text: fmtBytes(f.size) })]);
                if (rendered > LIMIT) hidden++;
                parent.appendChild(line);
            });
        }
        renderNode(root, container, 0);
        if (hidden > 0) {
            const more = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary mt-2', text: t.key('js.common.show_all_more', { n: hidden }) });
            more.addEventListener('click', () => { container.querySelectorAll('.wl-tree-more').forEach(x => x.classList.remove('d-hidden')); more.remove(); });
            container.appendChild(more);
        }
        return container;
    }

    // The site's one size rule (1.74.0, t.bytes() in assets/js/i18n.js): the same digits as before, in the page
    // language's decimal separator — "1,27 GiB" on a Polish page, a keyed word the live switch says again.
    function fmtBytes(n) {
        return t.bytes(n);
    }

    // The panel's one short date (1.74.0): "2026-10-05 18:00", as the Reports table and the public pages write it —
    // never the browser's language ("10/05/2026" stood in the Polish panel's Whitelist, Index, Users and Log). A server
    // DATETIME (no zone) is shortened as it came, the server's clock as before; a moment with a zone in this browser's.
    function fmtDate(s) {
        if (!s) return '—';
        const v = String(s);
        if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(v)) return v.replace('T', ' ').slice(0, 16);
        const d = new Date(v);
        if (isNaN(d.getTime())) return v;
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
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
            flashTip(target, t.key('js.common.copied'), { variant: 'success' });
        }).catch(() => flashTip(target, t.key('js.common.clipboard_unavailable'), { variant: 'warning', duration: 2000 }));
    }

    /** The 40-hex `hash` in the address, or null. Anything else is not an address we wrote. */
    function hashFromUrl() {
        try {
            const h = String(new URL(location.href).searchParams.get('hash') || '').toLowerCase();
            return /^[0-9a-f]{40}$/.test(h) ? h : null;
        } catch (e) { return null; }
    }

    /**
     * Make a details modal addressable: `?hash=<40 hex>` on the page opens that row, opening the
     * modal writes the hash into the address, closing it takes it away, and a button in the modal's
     * header copies the address back out.
     *
     * replaceState, NOT pushState. The panel is a workplace with polls and forms; stacking a history
     * entry for every row somebody glances at would turn Back into a tour of the last thirty rows
     * instead of the way out of the page. The public search page pushes because moving between
     * views is what a reader does there; here the modal is a look at one row of the page you are on.
     *
     * The button is hidden until there is a hash to copy — a Copy link that copies the page you are
     * already on is a button that lies.
     */
    function bindHashModal(modalEl, action) {
        const noop = { show() {}, clear() {} };
        if (!modalEl) return noop;
        const head = modalEl.querySelector('.modal-header');
        let current = null;

        const linkFor = (h) => location.origin + location.pathname + '?action=' + encodeURIComponent(action) + '&hash=' + h;
        const setUrl = (h) => {
            try {
                const u = new URL(location.href);
                if (h) u.searchParams.set('hash', h); else u.searchParams.delete('hash');
                const qs = u.searchParams.toString();
                history.replaceState(history.state, '', u.pathname + (qs ? '?' + qs : '') + u.hash);
            } catch (e) { /* opaque origin: the modal simply is not addressable */ }
        };

        let btn = null;
        if (head) {
            btn = el('button', {
                type: 'button', className: 'btn btn-sm btn-outline-secondary ms-auto me-2',
                title: t.key('js.common.copy_link_title'),
            }, [el('i', { className: 'bi bi-link-45deg' }), ' ', t.key('js.common.copy_link')]);
            btn.hidden = true;
            btn.addEventListener('click', () => { if (current) copyToClipboard(linkFor(current), btn); });
            const close = head.querySelector('.btn-close');
            if (close) {
                // Bootstrap's own `.modal-header .btn-close` carries margin-left:auto. A second auto
                // margin on the same flex line does not queue behind the first — the two split the
                // free space between them, which parked this button in the middle of the header
                // instead of beside the X, and made its `me-2` do nothing. Cancel the close button's
                // margin so exactly one auto margin is left on the line.
                close.classList.add('ms-0');
                head.insertBefore(btn, close);
            } else {
                head.appendChild(btn);
            }
            // The button is built once per page and never appears in the document lang-swap.js fetches; its
            // words are t.key() words that keep their keys (assets/js/i18n.js, 1.73.0), so the switch says them again
            // itself (it used to need a `langswap` listener here that built the button's insides anew).
        }
        modalEl.addEventListener('hidden.bs.modal', () => {
            current = null;
            if (btn) btn.hidden = true;
            setUrl(null);
        });
        return {
            show(hash) {
                const h = String(hash || '').toLowerCase();
                current = /^[0-9a-f]{40}$/.test(h) ? h : null;
                if (btn) btn.hidden = !current;
                setUrl(current);
            },
            clear() { current = null; if (btn) btn.hidden = true; setUrl(null); },
            linkFor,
        };
    }

    /**
     * Ask for the admin password.
     *
     * A thin wrapper, but a wrapper worth having: window.prompt() is unstyled, is suppressed by some
     * browsers, and shows the password in clear on screen. Every dangerous action in this panel ends
     * up here, so it should look and behave like the panel.
     */
    function promptPassword(title, message) {
        return promptModal({
            title: title || t.key('js.common.confirm'),
            label: t.key('js.common.admin_password'),
            hint: message || '',
            password: true,
            okLabel: t.key('js.common.confirm'),
        });
    }


    /* ── leaving the site, in the panel too ─────────────────────────────────
     *
     * The public pages have this; the panel needs it more. A moderator working through a review
     * queue is precisely the person who will click a submitter's link by accident, and the whole
     * question in front of them is whether that link is safe.
     *
     * One delegated listener on the document, for the same reason as on the public side: a
     * description can be rendered into a detail modal, a queue card or a panel opened from another
     * panel, and whoever adds the next place should not have to remember this.
     */
    function askBeforeLeaving(url) {
        const box = document.createElement('div');
        box.className = 'leave-modal';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        const inner = el('div', { className: 'leave-box' }, [
            el('h3', { text: t.key('js.common.leave_title') }),
            el('p', { text: t.key('js.common.leave_body') }),
            // textContent, not innerHTML: the URL is the untrusted part of a dialog whose entire
            // purpose is warning about untrusted things.
            el('code', { className: 'leave-url', text: url }),
        ]);
        const acts = el('div', { className: 'leave-acts' });
        const cancel = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', text: t.key('js.common.leave_stay') });
        const go = el('a', { className: 'btn btn-sm btn-outline-warning', href: url, target: '_blank',
                             rel: 'nofollow noopener noreferrer ugc', text: t.key('js.common.leave_open') });
        const close = () => { if (box.parentNode) box.parentNode.removeChild(box); document.removeEventListener('keydown', onEsc, true); };
        // Its Esc is its own (1.70.0): heard in the capture phase and taken, so the detail modal it was opened
        // from — Bootstrap's, which closes on an Esc that reaches it — stays open, and a second Esc closes that.
        function onEsc(e) {
            if (e.key !== 'Escape') return;
            e.preventDefault();
            e.stopImmediatePropagation();
            close();
        }
        cancel.addEventListener('click', close);
        go.addEventListener('click', close);
        acts.appendChild(cancel); acts.appendChild(go);
        inner.appendChild(acts);
        box.appendChild(inner);
        // The backdrop closes it only when the press STARTED on the backdrop (1.64.0). A `click` is
        // delivered to the common ancestor of the press and the release, so a press inside the box
        // that is let go outside it raises one here — and the answer somebody was typing goes with
        // the box. The panel does not load app.js, which is why these three lines are here too.
        let fromBackdrop = false;
        box.addEventListener('pointerdown', (e) => { fromBackdrop = e.target === box; });
        box.addEventListener('click', (e) => { if (e.target === box && fromBackdrop) close(); });
        document.body.appendChild(box);
        document.addEventListener('keydown', onEsc, true);
        cancel.focus();
    }

    document.addEventListener('click', function (e) {
        const a = e.target && e.target.closest ? e.target.closest('a[data-external]') : null;
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href)) return;
        e.preventDefault();
        askBeforeLeaving(href);
    }, true);

    /**
     * The panel's one empty state: an icon, the sentence, an optional hint. Returns an element, so
     * a caller drops it into a cell or a box instead of writing a bare sentence there.
     */
    function emptyState(text, icon, hint) {
        const box = document.createElement('div'); box.className = 'empty-state';
        const i = document.createElement('i'); i.className = 'bi ' + (icon || 'bi-inbox'); box.appendChild(i);
        const t = document.createElement('div'); t.className = 'es-text'; t.textContent = text; box.appendChild(t);
        if (hint) { const h = document.createElement('div'); h.className = 'es-hint'; h.textContent = hint; box.appendChild(h); }
        return box;
    }

    /**
     * The waits every list shares. `sort`: a header click redraws the arrows at once but the fetch
     * waits this long, so a whole decision (desc → asc → off is two or three clicks, several keys
     * more) becomes one request. `search`: typing. admin.js and app.js do not load this file and
     * repeat the two numbers with a comment pointing here.
     */
    const DEBOUNCE = { sort: 1200, search: 400 };
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

    // Keeping the reader's place across a language switch used to live here. It now lives in
    // assets/js/lang-swap.js, which the i18n bridge loads on EVERY page — public pages have a
    // switcher too and never load this file — and which restores by anchor element rather than by
    // scroll pixel, because the page is still growing when the old timers fired.

    /**
     * The header's "Reports" button carries ONE number (1.74.3): everything waiting on the Reports page's tabs that this
     * session may handle. Every panel page draws it from the server (templates/admin/_header_actions.php;
     * panelReportsWaiting() in includes/reports.php), its `data-parts` the numbers of the tabs' badges one by one — torrent
     * reports, archived ones, appeals, reported messages, comments, descriptions, shouts. The Reports page's own scripts
     * say a part as soon as they have a fresh count (the number their tab badge shows: admin.js updateBadge(),
     * admin-messages.js, admin-contentreports.js) and the total follows, hidden at 0, as the tab badges are.
     */
    function reportsWaiting(part, n) {
        const b = document.getElementById('nav-reports-badge');
        if (!b || !part) return;
        let parts;
        try { parts = JSON.parse(b.dataset.parts || '{}') || {}; } catch (e) { parts = {}; }
        parts[part] = Math.max(0, Math.floor(Number(n)) || 0);
        b.dataset.parts = JSON.stringify(parts);
        const total = Object.values(parts).reduce((sum, v) => sum + (Number(v) || 0), 0);
        b.textContent = total > 0 ? String(total) : '';
        b.classList.toggle('d-hidden', total <= 0);
    }

    /* ── adminRichField(): the panel's ONE box for words that have a format (1.75.0) ───────────────────────────────────────
     *
     * A bulk message, a note to one member, a moderator's reason to an author and answer to the reporters, a reply to a
     * person who reported a torrent, the answer to an appeal — each of them is a textarea with a FORMAT (plain, and
     * BBCode / Markdown wherever the site has switched them on; a bulk message may also be HTML), a small toolbar for
     * the syntax chosen, and a preview made by the SERVER. It used to be written out once, inline in admin-users.js, for the
     * bulk composer; this is that, taken out, so the next window does not need a copy of it:
     *
     *   const f = AdminCommon.adminRichField({ textId: 'bm-body', formatId: 'bm-format', rows: 7, maxLength: 60000,
     *       countMax: 60000, countBytes: true, formats: ['plain', 'markdown', 'bbcode', 'html'], preview: 'frame',
     *       previewFetch: async (text, format) => ({ html: '<!doctype html>…', problem: null }), onFormat: (fmt) => … });
     *   host.appendChild(f.el);                       f.getValue() / f.getFormat() / f.reset() / f.setFormats([…])
     *
     * Options (all optional):
     *   textId, formatId   ids for the textarea and the select (a host's <label for> and its own code reach them by id);
     *   rows, maxLength, placeholder, ariaLabel   the textarea's;
     *   formats    the formats to offer. Without it they are asked of the server once per page (admin/rich_preview): 'plain'
     *              and the ones the site accepts — so a window never offers a syntax the renderer has been told to refuse;
     *   format     the one chosen to begin with ('plain');
     *   preview    'inline' (default): the server's rendering of the text (admin/rich_preview, the site's own renderer on
     *              escaped input) is put into the box under it, for BBCode / Markdown only;
     *              'frame': previewFetch() gives a whole DOCUMENT and it goes into an <iframe sandbox="" srcdoc> — never
     *              into this page's DOM. The bulk composer's, whose HTML is a campaign's;
     *              'none';
     *   previewFetch(text, format) → Promise<{html, problem}>   the host's own preview (the bulk composer's is its whole mail);
     *   countMax / countBytes   a counter under the box ("120 / 2 000"; in bytes where the limit is);
     *   compact    a small box for a card (fewer rows, the toolbar behind the format when it is not plain);
     *   onFormat(fmt), onInput()   the host hears a change.
     * The toolbar hides for plain and for html (HTML is typed as HTML) and each button hides whose syntax the chosen
     * format cannot express — Ctrl+B / Ctrl+I / Ctrl+K do what the buttons do.
     */
    let richUid = 0;
    let richFormatsPromise = null;
    /** The formats the site accepts for the words a moderator writes, once per page: ['plain', …]. */
    function richFormats() {
        if (!richFormatsPromise) {
            richFormatsPromise = apiCall('admin/rich_preview', 'POST', { text: '', format: 'plain' })
                .then(r => (r && r.success && Array.isArray(r.formats) && r.formats.length) ? r.formats : ['plain'])
                .catch(() => ['plain']);
        }
        return richFormatsPromise;
    }
    // Kept in step with SYNTAX in assets/js/app.js — the same renderer serves both. A third element means "a whole block".
    const RICH_SYNTAX = {
        markdown: {
            bold: ['**', '**'], italic: ['*', '*'], strike: ['~~', '~~'], code: ['`', '`'],
            highlight: ['==', '=='],
            link: ['[', '](https://example.org)'], image: ['![](', ')'],
            quote: ['> ', ''], list: ['- ', ''], olist: ['1. ', ''],
            table: ['| A | B |\n|---|---|\n| 1 | 2 |', '', true],
            hr: ['\n---\n', '', true],
        },
        bbcode: {
            bold: ['[b]', '[/b]'], italic: ['[i]', '[/i]'], underline: ['[u]', '[/u]'],
            strike: ['[s]', '[/s]'], code: ['[code]', '[/code]'],
            color: ['[color=#e74c3c]', '[/color]'], size: ['[size=18]', '[/size]'],
            highlight: ['[highlight=yellow]', '[/highlight]'],
            link: ['[url=https://example.org]', '[/url]'], image: ['[img]', '[/img]'],
            quote: ['[quote]', '[/quote]'],
            list: ['[list]\n[*] ', '\n[/list]'], olist: ['[list=1]\n[*] ', '\n[/list]'],
            center: ['[center]', '[/center]'],
            table: ['[table]\n[tr][th]A[/th][th]B[/th][/tr]\n[tr][td]1[/td][td]2[/td][/tr]\n[/table]', '', true],
            hr: ['\n[hr]\n', '', true],
        },
    };
    RICH_SYNTAX.flarum = RICH_SYNTAX.bbcode;
    const RICH_TOOLS = [
        [['bold', 'type-bold'], ['italic', 'type-italic'], ['underline', 'type-underline'], ['strike', 'type-strikethrough']],
        [['color', 'palette'], ['size', 'fonts'], ['highlight', 'highlighter']],
        [['link', 'link-45deg'], ['image', 'image'], ['list', 'list-ul'], ['olist', 'list-ol']],
        [['quote', 'quote'], ['code', 'code-slash'], ['table', 'table'], ['center', 'text-center'], ['hr', 'dash-lg']],
    ];

    function adminRichField(o) {
        o = o || {};
        richUid += 1;
        const previewMode = o.preview || 'inline';
        const compact = !!o.compact;

        const root = el('div', { className: 'arf' + (compact ? ' arf-compact' : '') });
        const tools = el('div', { className: 'arf-tools d-hidden', role: 'toolbar', 'aria-label': t.key('js.rich.toolbar') });
        RICH_TOOLS.forEach(group => {
            const g = el('span', { className: 'arf-tool-group' });
            group.forEach(([name, icon]) => g.appendChild(el('button', {
                type: 'button', className: 'btn btn-sm btn-outline-secondary', title: t.key('js.rich.' + name),
                dataset: { md: name } }, el('i', { className: 'bi bi-' + icon, 'aria-hidden': 'true' }))));
            tools.appendChild(g);
        });
        const sel = el('select', { id: o.formatId || null, className: 'form-select form-select-sm bg-dark text-light border-secondary arf-fmt',
                                   title: t.key('js.rich.format_title'), 'aria-label': t.key('js.rich.format_title') });
        const head = el('div', { className: 'arf-head' }, [tools, sel]);
        const ta = el('textarea', { id: o.textId || null, className: 'form-control form-control-sm bg-dark text-light border-secondary arf-text',
                                    rows: String(o.rows || (compact ? 3 : 5)), maxlength: o.maxLength ? String(o.maxLength) : null,
                                    placeholder: o.placeholder || null, 'aria-label': o.ariaLabel || null });
        const hint = el('small', { className: 'settings-hint arf-hint' });
        const count = el('small', { className: 'text-muted arf-count' });
        const foot = el('div', { className: 'arf-foot' }, [hint, count]);
        const problem = el('div', { className: 'arf-problem wl-small', role: 'status' });
        const wrap = el('div', { className: 'arf-preview-wrap d-hidden' });
        wrap.appendChild(el('div', { className: 'arf-preview-head', text: t.key(previewMode === 'frame' ? 'js.rich.preview_mail' : 'js.rich.preview') }));
        let box = null, frame = null;
        if (previewMode === 'frame') {
            // The REAL document, in a frame with no permission at all: no script, no form, no top navigation, no same-origin —
            // a campaign's HTML never reaches this page's DOM or its cookies.
            frame = el('iframe', { className: 'arf-frame', title: t.key('js.rich.preview_mail') });
            frame.setAttribute('sandbox', '');
            wrap.appendChild(frame);
        } else if (previewMode === 'inline') {
            box = el('div', { className: 'arf-preview rt-body richtext' });
            wrap.appendChild(box);
        }
        [head, ta, foot, problem, wrap].forEach(n => root.appendChild(n));

        const LABEL = { plain: () => t.key('js.users.fmt_plain'), markdown: () => 'Markdown', bbcode: () => 'BBCode', flarum: () => 'Flarum', html: () => 'HTML' };
        const getFormat = () => sel.value || 'plain';
        const syntax = () => RICH_SYNTAX[getFormat()] || null;

        function setFormats(list) {
            const keep = sel.value || o.format || 'plain';
            const formats = (Array.isArray(list) && list.length ? list : ['plain']).filter(f => LABEL[f]);
            sel.textContent = '';
            formats.forEach(f => sel.appendChild(el('option', { value: f, text: LABEL[f]() })));
            sel.value = formats.indexOf(keep) >= 0 ? keep : formats[0];
            // One format is no choice: a select with one entry says "plain text" and nothing else, so it goes.
            sel.hidden = formats.length < 2;
            syncUi();
        }

        function syncUi() {
            const fmt = getFormat();
            const syn = syntax();
            tools.classList.toggle('d-hidden', !syn);
            if (syn) {
                tools.querySelectorAll('[data-md]').forEach(b => { b.hidden = !syn[b.dataset.md]; });
                tools.querySelectorAll('.arf-tool-group').forEach(g => { g.hidden = ![...g.querySelectorAll('[data-md]')].some(b => !b.hidden); });
            }
            hint.textContent = '';
            hint.append(t.key(fmt === 'markdown' ? 'js.users.fmt_hint_markdown' : fmt === 'bbcode' ? 'js.users.fmt_hint_bbcode' : fmt === 'flarum' ? 'js.app.hint_flarum'
                              : fmt === 'html' ? 'js.rich.hint_html' : 'js.users.fmt_hint_plain'));
            if (o.onFormat) o.onFormat(fmt);
            previewSoon(true);
        }

        function updateCount() {
            if (!o.countMax) return;
            const n = o.countBytes ? new TextEncoder().encode(ta.value).length : [...ta.value].length;
            count.textContent = '';
            count.append(t.key(o.countBytes ? 'js.rich.count_bytes' : 'js.rich.count', { n: t.num(n), max: t.num(o.countMax) }));
            count.classList.toggle('text-danger', n > o.countMax);
            count.classList.toggle('text-muted', n <= o.countMax);
        }

        /** Wrap the selection (or drop a stub at the caret) and keep the caret somewhere useful. */
        function wrapSelection(kind) {
            const syn = syntax();
            if (!syn || !syn[kind]) return;
            const [open, close, block] = syn[kind];
            const start = ta.selectionStart, end = ta.selectionEnd;
            const picked = ta.value.slice(start, end);
            if (block) {
                // A whole construct. Wrapping a selection in a table skeleton would put the author's sentence in the header.
                const pre = start > 0 && ta.value[start - 1] !== '\n' ? '\n' : '';
                ta.setRangeText(pre + open + '\n', start, end, 'end');
            } else if (close === '') {
                // Line-prefix marks (a quote, a list in Markdown) belong at the start of every selected line.
                ta.setRangeText((picked || 'text').split('\n').map(l => open + l).join('\n'), start, end, 'end');
                if (!picked) ta.setSelectionRange(start + open.length, start + open.length + 4);
            } else {
                ta.setRangeText(open + (picked || 'text') + close, start, end, 'end');
                if (!picked) ta.setSelectionRange(start + open.length, start + open.length + 4);
            }
            ta.focus();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }

        let timer = 0, seq = 0;
        async function renderPreview() {
            if (previewMode === 'none') return;
            const text = ta.value, fmt = getFormat();
            const my = ++seq;
            // Inline: nothing to show for plain text (it is what was typed). Frame: the mail, whatever the format.
            if (text.trim() === '' || (previewMode === 'inline' && fmt === 'plain')) {
                wrap.classList.add('d-hidden'); problem.textContent = ''; return;
            }
            let r;
            try {
                r = o.previewFetch ? await o.previewFetch(text, fmt)
                                   : await apiCall('admin/rich_preview', 'POST', { text, format: fmt });
            } catch (e) { r = null; }
            if (my !== seq) return;                 // a newer keystroke is on its way
            if (!r || (r.success === false && !r.html)) { wrap.classList.add('d-hidden'); return; }
            problem.textContent = r.problem || '';
            wrap.classList.remove('d-hidden');
            // Inline: the server's rendering of ESCAPED input by the site's own renderer (includes/richtext.php), the markup
            // every card and every member's notification page is handed. Frame: a whole document, in a sandbox with no powers.
            if (frame) frame.srcdoc = r.html || '';
            else if (box) box.innerHTML = r.html || '';
        }
        function previewSoon(now) {
            clearTimeout(timer);
            if (now) renderPreview(); else timer = setTimeout(renderPreview, 350);
        }

        tools.addEventListener('click', (e) => {
            const b = e.target.closest('[data-md]');
            if (b) wrapSelection(b.dataset.md);
        });
        ta.addEventListener('input', () => { updateCount(); previewSoon(false); if (o.onInput) o.onInput(); });
        ta.addEventListener('keydown', (e) => {
            if (!(e.ctrlKey || e.metaKey) || e.altKey || !syntax()) return;
            const kind = { b: 'bold', i: 'italic', k: 'link' }[String(e.key).toLowerCase()];
            if (!kind) return;
            e.preventDefault();
            wrapSelection(kind);
        });
        sel.addEventListener('change', syncUi);

        // Formats: the host's, or the site's (once per page).
        if (Array.isArray(o.formats)) setFormats(o.formats);
        else { setFormats(['plain']); richFormats().then(setFormats); }
        updateCount();

        return {
            el: root, textarea: ta, select: sel,
            getValue: () => ta.value,
            getFormat,
            setValue(v) { ta.value = v || ''; updateCount(); previewSoon(true); },
            setFormat(f) { if ([...sel.options].some(op => op.value === f)) { sel.value = f; syncUi(); } },
            setFormats,
            /** Empty the box, keep the format. */
            reset() { ta.value = ''; problem.textContent = ''; wrap.classList.add('d-hidden'); updateCount(); },
            focus() { ta.focus(); },
            isEmpty: () => ta.value.trim() === '',
            refresh: () => previewSoon(true),
            /** Show a sentence under the box (a refusal from the server for this field). */
            setProblem(msg) { problem.textContent = msg || ''; },
        };
    }

    // The close button on a toast built with insertAdjacentHTML (assets/js/admin.js and the inline
    // block in templates/admin/settings.php both do). It used to be
    // onclick="this.closest('.toast').remove()", which an enforcing script-src blocks — and
    // Bootstrap's own data-bs-dismiss="toast" is NOT the same thing: it calls Toast.hide(), which
    // leaves the element in the DOM. admin.js happens to remove it on a timer anyway; the settings
    // toast does not, so a dismissed toast would have stayed in the layout for ever. Removing it is
    // what the attribute did, so removing it is what this does.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-toast-close]');
        if (btn) btn.closest('.toast')?.remove();
    });

    function resizableDialog(modal, key) {
        const dlg = modal ? modal.querySelector('.modal-dialog') : null;
        const content = dlg ? dlg.querySelector('.modal-content') : null;
        if (!content || content.querySelector('.dlg-grip')) return;
        const head = content.querySelector('.modal-header');
        const reset = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary dlg-size-reset', title: t.key('js.app.info_size_reset_tip'),
                                     'aria-label': t.key('js.app.info_size_reset') }, [el('i', { className: 'bi bi-arrows-angle-contract', 'aria-hidden': 'true' })]);
        if (head) head.insertBefore(reset, head.querySelector('.btn-close'));
        const stored = () => {
            try {
                const v = JSON.parse(localStorage.getItem(key) || 'null');
                return v && v.w > 0 ? { w: Number(v.w), h: Number(v.h) || 0 } : null;
            } catch (e) {
                return null;
            }
        };
        const fit = (v) => ({
            w: Math.round(Math.min(Math.max(v.w, 480), window.innerWidth - 24)),
            h: v.h ? Math.round(Math.min(Math.max(v.h, 300), window.innerHeight - 48)) : 0,
        });
        const apply = (v) => {
            const f = v && window.innerWidth >= 768 ? fit(v) : null;
            dlg.classList.toggle('dlg-sized', !!f);
            dlg.classList.toggle('dlg-sized-h', !!(f && f.h));
            if (f) dlg.style.setProperty('--dlg-w', f.w + 'px'); else dlg.style.removeProperty('--dlg-w');
            if (f && f.h) dlg.style.setProperty('--dlg-h', f.h + 'px'); else dlg.style.removeProperty('--dlg-h');
            reset.hidden = !f;
        };
        const keep = (v) => {
            try {
                if (v) localStorage.setItem(key, JSON.stringify(v)); else localStorage.removeItem(key);
            } catch (e) {
                return;
            }
        };
        const clear = () => { keep(null); apply(null); };
        reset.addEventListener('click', clear);
        ['e', 'w', 's', 'se', 'sw'].forEach(dir => {
            const grip = el('span', { className: 'dlg-grip dlg-grip-' + dir, 'aria-hidden': 'true' });
            grip.addEventListener('dblclick', clear);
            grip.addEventListener('pointerdown', (e) => {
                if (e.button !== 0) return;
                e.preventDefault();
                const r = content.getBoundingClientRect();
                const x0 = e.clientX, y0 = e.clientY;
                const before = stored();
                let last = null;
                grip.setPointerCapture(e.pointerId);
                dlg.classList.add('dlg-resizing');
                const move = (ev) => {
                    const dx = ev.clientX - x0;
                    const w = dir.includes('e') ? r.width + 2 * dx : (dir.includes('w') ? r.width - 2 * dx : r.width);
                    const h = dir.includes('s') ? r.height + ev.clientY - y0 : (before ? before.h : 0);
                    last = fit({ w, h });
                    apply(last);
                };
                const up = () => {
                    grip.removeEventListener('pointermove', move);
                    grip.removeEventListener('pointerup', up);
                    grip.removeEventListener('pointercancel', up);
                    dlg.classList.remove('dlg-resizing');
                    if (last) keep(last);
                };
                grip.addEventListener('pointermove', move);
                grip.addEventListener('pointerup', up);
                grip.addEventListener('pointercancel', up);
            });
            content.appendChild(grip);
        });
        apply(stored());
        window.addEventListener('resize', () => apply(stored()));
    }

    window.AdminCommon = { apiCall, esc, el, emptyState, DEBOUNCE, debounce, showToast, confirmAction, promptModal, promptPassword, askBeforeLeaving, flashTip, makeSortStack, sortMark, renderPagination, fmtBytes, fmtDate, fmtAgo, copyToClipboard, hashFromUrl, bindHashModal, animatedClear, bindSearchClear, buildFileTree, busyDot, reportsWaiting, adminRichField, richFormats, resizableDialog };
})();
