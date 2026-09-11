/**
 * The account page's security block: the second factor, and where this account is signed in.
 *
 * Its own file rather than more of app.js, which is already the largest thing here — and because
 * both halves are only ever drawn on one page, so every other page pays one getElementById for
 * them.
 *
 * The QR arrives as SVG the server drew (includes/qr.php, from integers and fixed strings — no part
 * of it comes from anybody's input), which is why the one innerHTML in this file is not a habit.
 */
(function () {
    'use strict';

    var API = (typeof APP_API === 'string') ? APP_API : 'api.php?endpoint=';

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'className') n.className = v;
            else if (k === 'text') n.textContent = v;
            else n.setAttribute(k, v === true ? '' : v);
        });
        (Array.isArray(kids) ? kids : kids ? [kids] : []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return n;
    }
    function csrf() { var i = document.getElementById('account-csrf'); return i ? i.value : ''; }
    async function get(qs) {
        try {
            var r = await fetch(API + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return await r.json();
        } catch (e) { return null; }
    }
    async function post(endpoint, body) {
        try {
            body = body || {};
            body.csrf_token = csrf();
            var r = await fetch(API + endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify(body),
            });
            return await r.json();
        } catch (e) { return null; }
    }
    function when(unix) {
        if (!unix) return '';
        try { return new Date(unix * 1000).toISOString().replace('T', ' ').slice(0, 16); } catch (e) { return ''; }
    }

    /* ─────────────────────────── the second factor ─────────────────────────── */

    function initTwofa() {
        var box = document.getElementById('acc-2fa-box');
        var toggle = document.getElementById('acc-2fa');
        if (!box || !toggle) return;
        var state = document.getElementById('acc-2fa-state');

        function say(on) {
            toggle.checked = !!on;
            if (state) state.textContent = t(on ? 'js.sec.on' : 'js.sec.off');
        }
        function clear() { box.textContent = ''; box.hidden = true; }

        /** The codes, once. They are never shown again, so the box says so out loud. */
        function showRecovery(codes) {
            box.textContent = '';
            box.hidden = false;
            box.appendChild(el('p', { className: 'acc-2fa-head', text: t('js.sec.codes_head') }));
            var list = el('div', { className: 'acc-2fa-codes' });
            (codes || []).forEach(function (c) { list.appendChild(el('code', { text: c })); });
            box.appendChild(list);
            box.appendChild(el('p', { className: 'text-muted', text: t('js.sec.codes_note') }));
            var done = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.sec.done') });
            done.addEventListener('click', clear);
            box.appendChild(done);
        }

        /** Step one: prove it is you, then point a camera at the square. */
        function askPassword(action, label, onOk) {
            box.textContent = '';
            box.hidden = false;
            var pass = el('input', { type: 'password', className: 'profile-search', autocomplete: 'current-password',
                                     placeholder: t('js.sec.password_ph') });
            var go = el('button', { type: 'button', className: 'btn btn-small', text: label });
            var msg = el('span', { className: 'text-muted acc-2fa-msg' });
            var cancel = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.sec.cancel') });
            cancel.addEventListener('click', function () { clear(); say(action === 'disable'); });
            var run = async function () {
                if (!pass.value) { pass.focus(); return; }
                go.disabled = true;
                var r = await post('user_2fa', { op: action, current_password: pass.value });
                go.disabled = false;
                if (!r || !r.success) {
                    msg.textContent = (r && r.error === 'twofa_disabled') ? t('js.sec.err_off')
                        : (r && r.error) ? t('js.sec.err_password') : t('js.sec.err_failed');
                    return;
                }
                onOk(r);
            };
            go.addEventListener('click', run);
            pass.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
            // The field on its own line and the two answers under it. Side by side they wrapped
            // wherever the card happened to end, which put Cancel on a line of its own looking like
            // a third, unrelated button.
            box.appendChild(el('p', { className: 'acc-2fa-head', text: t('js.sec.password_head') }));
            box.appendChild(pass);
            var row = el('div', { className: 'acc-2fa-row' });
            row.appendChild(go); row.appendChild(cancel); row.appendChild(msg);
            box.appendChild(row);
            pass.focus();
        }

        function showSetup(r) {
            box.textContent = '';
            box.hidden = false;
            box.appendChild(el('p', { className: 'acc-2fa-head', text: t('js.sec.setup_head') }));
            if (r.qr) {
                // Server-drawn SVG — see the file header.
                var q = el('div', { className: 'acc-2fa-qr' });
                q.innerHTML = r.qr;
                box.appendChild(q);
            }
            // This sentence belongs to the key underneath it, not to the square above it — so the
            // space goes above the sentence and not between the sentence and what it introduces.
            box.appendChild(el('p', { className: 'text-muted acc-2fa-keynote', text: t('js.sec.secret_is') }));
            box.appendChild(el('code', { className: 'acc-2fa-secret', text: r.secret }));
            var code = el('input', { type: 'text', className: 'profile-search', inputmode: 'numeric',
                                     autocomplete: 'one-time-code', maxlength: 10, placeholder: t('js.sec.code_ph') });
            var go = el('button', { type: 'button', className: 'btn btn-small', text: t('js.sec.confirm') });
            var msg = el('span', { className: 'text-muted acc-2fa-msg' });
            var cancel = el('button', { type: 'button', className: 'btn btn-secondary btn-small', text: t('js.sec.cancel') });
            cancel.addEventListener('click', function () { clear(); say(false); });
            var run = async function () {
                if (!code.value.trim()) { code.focus(); return; }
                go.disabled = true;
                var c = await post('user_2fa', { op: 'confirm', code: code.value.trim() });
                go.disabled = false;
                if (!c || !c.success) { msg.textContent = t(c && c.error === 'bad_code' ? 'js.sec.err_code' : 'js.sec.err_failed'); return; }
                say(true);
                showRecovery(c.recovery);
            };
            go.addEventListener('click', run);
            code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
            box.appendChild(code);
            var row = el('div', { className: 'acc-2fa-row' });
            row.appendChild(go); row.appendChild(cancel); row.appendChild(msg);
            box.appendChild(row);
            code.focus();
        }

        toggle.addEventListener('change', function () {
            if (toggle.checked) {
                // Not on yet — it is on when a code from the phone proves the secret arrived.
                say(false);
                askPassword('begin', t('js.sec.start'), showSetup);
            } else {
                say(true);
                askPassword('disable', t('js.sec.turn_off'), function () {
                    say(false);
                    clear();
                    var left = document.getElementById('acc-2fa-left');
                    if (left) left.hidden = true;
                });
            }
        });

        var newCodes = document.getElementById('acc-2fa-newcodes');
        if (newCodes) newCodes.addEventListener('click', function () {
            askPassword('recovery', t('js.sec.newcodes'), function (r) { showRecovery(r.recovery); });
        });
    }

    /* ─────────────────────────── signed in where ─────────────────────────── */

    function initSessions() {
        var root = document.getElementById('acc-sessions');
        if (!root) return;
        var head = document.getElementById('acc-sessions-head');
        var list = document.getElementById('acc-sessions-list');
        var act = document.getElementById('acc-sessions-act');
        var pass = document.getElementById('acc-sessions-pass');
        var go = document.getElementById('acc-sessions-go');
        var msg = document.getElementById('acc-sessions-msg');

        async function load() {
            var j = await get('user_sessions');
            list.textContent = '';
            if (!j || !j.success) { head.textContent = t('js.sec.sessions_failed'); return; }
            var n = j.count || 0;
            head.textContent = n === 1 ? t('js.sec.devices_one') : t('js.sec.devices_many', { n: n });
            (j.sessions || []).forEach(function (s) {
                var row = el('div', { className: 'acc-session' + (s.current ? ' acc-session-me' : '') });
                row.appendChild(el('span', { className: 'acc-session-what',
                    text: (s.ua ? shortUa(s.ua) : t('js.sec.unknown_device')) + (s.ip ? ' · ' + s.ip : '') }));
                row.appendChild(el('span', { className: 'text-muted acc-session-when',
                    text: t('js.sec.since', { date: when(s.created) }) }));
                if (s.current) row.appendChild(el('span', { className: 'pf-badge', text: t('js.sec.this_one') }));
                list.appendChild(row);
            });
            // Offered whenever there is anything to end — which includes the sessions this list
            // cannot show, because the sweep reaches those too.
            if (act) act.hidden = false;
        }

        /** Enough of a user agent to recognise a device by, without pretending to parse one. */
        function shortUa(ua) {
            var m = /(Firefox|Chrome|Safari|Edg|OPR)\/[\d.]+/.exec(ua);
            var os = /(Windows|Android|iPhone|iPad|Mac OS X|Linux)/.exec(ua);
            var name = m ? m[1].replace('Edg', 'Edge').replace('OPR', 'Opera') : null;
            if (name && os) return name + ' · ' + os[1];
            return name || (os ? os[1] : ua.slice(0, 40));
        }

        if (go) go.addEventListener('click', async function () {
            if (!pass.value) { pass.focus(); return; }
            go.disabled = true;
            var r = await post('user_sessions', { op: 'others', current_password: pass.value });
            go.disabled = false;
            pass.value = '';
            if (!r || !r.success) { msg.textContent = t(r && r.error ? 'js.sec.err_password' : 'js.sec.err_failed'); return; }
            msg.textContent = t('js.sec.ended', { n: r.ended || 0 });
            load();
        });
        load();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTwofa();
        initSessions();
    });
})();
