/**
 * The anti-spam layer, as a page meets it (1.71.0 — the server's half is includes/antispam.php).
 *
 * Every place people write asks the same layer, and every answer it refuses with has the same shape:
 * `error`, a sentence in the reader's language with the time in it (`message`), `retry_after`, and
 * `antispam` {kind: wait | captcha | duplicate | spread | conversations | unavailable, context, seconds,
 * tpl} — `tpl` being the same sentence with "{time}" where the time goes — and, when a CAPTCHA is due,
 * `captcha_required` with the provider and its public key. So one helper serves every composer:
 *
 *     const r = await Antispam.send((extra) => post('shout_post', {body, ...extra}), {button, note, action});
 *
 *   · a CAPTCHA: the site's own box opens (assets/js/captcha.js — drawn on demand where the page had none)
 *     and the SAME request goes again with the token; cancelled, the answer says so;
 *   · a wait: the Send button counts down and comes back by itself at zero, and the note beside it reads
 *     the server's sentence with the time left in it, second by second;
 *   · anything else is the caller's to show (its `message` is already a sentence).
 *
 * The countdown is drawn by CSS (`data-as-wait` + ::after, assets/css/style.css) and never as a text node
 * in the button: the live language switch (assets/js/lang-swap.js) pairs a button's text nodes by position,
 * and a number put in among them would be handed another language's words. The button keeps its width.
 * Loaded on every public page before app.js; it posts nothing itself (each composer's own post() does,
 * with the page's token).
 */
(function () {
    'use strict';

    // `keyed`: the word goes into the page (a note, a message shown), so it is the t.key() word that leaves its key
    // on the element and follows the live language switch (1.73.0); a unit glued into a time stays plain words.
    function tr(key, vars, fallback, keyed) {
        if (typeof window.t === 'function') {
            const s = window.t(key, vars || {});
            if (s && s !== key) return keyed && window.t.key ? window.t.key(key, vars || {}) : s;
        }
        return fallback !== undefined ? fallback : key;
    }

    /**
     * The refusal's sentence, with `left` seconds in it when it is a wait (1.73.0): the dictionary's own words —
     * the t.key() word, which leaves its key on the note it is written into, so the note follows the live language
     * switch — when the page carries the key the answer names (api.antispam.*, LANG_JS_PUBLIC); else the server's
     * template (`tpl`, the time at "{time}"), else its sentence.
     */
    function sentence(r, left) {
        const a = r && r.antispam;
        if (!a) return (r && r.message) || '';
        if (a.key && typeof window.t === 'function' && window.t.has && window.t.has(a.key)) {
            return window.t.key(a.key, Object.assign({}, a.vars || {}, left > 0 ? { time: timeText(left) } : {}));
        }
        if (left > 0 && typeof a.tpl === 'string' && a.tpl.indexOf('{time}') !== -1) return a.tpl.split('{time}').join(timeText(left));
        return r.message || '';
    }

    /** Is this answer the layer asking for a CAPTCHA? */
    function isCaptcha(r) {
        return !!(r && (r.captcha_required || (r.antispam && r.antispam.kind === 'captcha')));
    }

    /** The seconds a refusal says to wait (0: not a wait, or not the layer's). */
    function waitSeconds(r) {
        if (!r || !r.antispam || r.antispam.kind === 'captcha') return 0;
        return Math.max(0, Math.round(Number(r.retry_after || r.antispam.seconds || 0)));
    }

    /** "12 s", "2 min 5 s", "1 h 5 min" — the server's own way of writing a time (antispamTimeText()). */
    function timeText(s) {
        s = Math.max(1, Math.round(s));
        const u = (k, d) => tr('js.antispam.u_' + k, null, d);
        if (s < 60) return s + ' ' + u('s', 's');
        if (s < 3600) {
            const m = Math.floor(s / 60), r = s % 60;
            return m + ' ' + u('min', 'min') + (r ? ' ' + r + ' ' + u('s', 's') : '');
        }
        let h = Math.floor(s / 3600), m = Math.ceil((s % 3600) / 60);
        if (m === 60) { h++; m = 0; }
        return h + ' ' + u('h', 'h') + (m ? ' ' + m + ' ' + u('min', 'min') : '');
    }

    /** What the button shows: "12 s" under a minute, then "4:59", then "1:04:59". */
    function clock(s) {
        s = Math.max(0, Math.round(s));
        if (s < 60) return s + ' ' + tr('js.antispam.u_s', null, 's');
        const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
        const pad = (n) => (n < 10 ? '0' : '') + n;
        return h > 0 ? h + ':' + pad(m) + ':' + pad(r) : m + ':' + pad(r);
    }

    const running = new Map();   // button -> {timer, finish}

    /** Is this button counting down? A composer asks before it enables its button again. */
    function waiting(button) { return !!button && running.has(button); }

    /** Stop a countdown now (the page is going away, or the composer is drawn again). */
    function stop(button) {
        const e = running.get(button);
        if (e) e.finish(true);
    }

    /**
     * Count down on a button: disabled, the seconds left drawn on it, the note (a function taking a sentence)
     * rewritten each second from the template; at zero everything is as it was and `onDone` runs. Resolves then.
     */
    function countdown(button, seconds, opts) {
        opts = opts || {};
        if (!button || !(seconds > 0)) return Promise.resolve();
        stop(button);
        const until = Date.now() + seconds * 1000;
        const note = typeof opts.note === 'function' ? opts.note : null;
        const tpl = typeof opts.tpl === 'string' && opts.tpl.indexOf('{time}') !== -1 ? opts.tpl : '';
        // The answer itself (1.73.0), when the caller has it: its sentence by key, see sentence().
        const answer = opts.answer && opts.answer.antispam ? opts.answer : null;
        button.classList.add('as-waiting');
        button.setAttribute('aria-disabled', 'true');
        return new Promise((resolve) => {
            let entry = null;
            const finish = (quiet) => {
                if (!entry) return;
                clearInterval(entry.timer);
                running.delete(button);
                entry = null;
                button.removeAttribute('data-as-wait');
                button.classList.remove('as-waiting');
                button.removeAttribute('aria-disabled');
                button.disabled = false;
                if (!quiet && note) note(opts.doneText !== undefined ? opts.doneText : '');
                if (!quiet && typeof opts.onDone === 'function') opts.onDone();
                resolve();
            };
            const tick = () => {
                const left = Math.ceil((until - Date.now()) / 1000);
                if (left <= 0) { finish(false); return; }
                // Held down every tick: a composer that re-enables its button after its request does not
                // cut the wait short.
                button.disabled = true;
                button.setAttribute('data-as-wait', clock(left));
                if (note && answer) note(sentence(answer, left));
                else if (note && tpl) note(tpl.split('{time}').join(timeText(left)));
            };
            entry = { timer: setInterval(tick, 250), finish };
            running.set(button, entry);
            tick();
        });
    }

    /** The site's CAPTCHA box, drawn on demand from the answer if the page had none. A token, or null. */
    async function solve(answer, action) {
        if (typeof window.captchaEnsure === 'function') window.captchaEnsure(answer && answer.captcha ? answer.captcha : null);
        if (typeof window.showCaptchaModal !== 'function') return null;
        const tok = await window.showCaptchaModal({ action: action || 'submit' });
        return tok || null;
    }

    function cancelled() {
        const gone = typeof window.captchaWasUnavailable === 'function' && window.captchaWasUnavailable();
        return { success: false, error: 'captcha_cancelled',
                 message: tr(gone ? 'js.app.captcha_unavailable' : 'js.app.captcha_cancelled', null, undefined, true) };
    }

    /**
     * One write, the layer's way. `doPost(extra)` is the composer's own request (its endpoint, its body, its
     * token) with `extra` merged into the body — the CAPTCHA's token when there is one. Options:
     *   button      the Send button: it counts down on a wait
     *   note        fn(sentence): the line beside it (the countdown's sentence, "sending again…")
     *   action      the endpoint's name, for reCAPTCHA v3's action
     *   solveFirst  a CAPTCHA before the first request (a guest's comment: the server would ask every time)
     *   doneText    what the note says when the wait is over ('' by default)
     * → the last answer.
     */
    async function send(doPost, opts) {
        opts = opts || {};
        const note = typeof opts.note === 'function' ? opts.note : null;
        let extra = {};
        if (opts.solveFirst) {
            const tok = await solve(null, opts.action);
            if (!tok) return cancelled();
            extra = { captcha_token: tok, 'g-recaptcha-response': tok };
        }
        let r = await doPost(extra);
        for (let i = 0; i < 2 && isCaptcha(r); i++) {
            if (note && r && r.message) note(sentence(r, 0));
            const tok = await solve(r, opts.action);
            if (!tok) return cancelled();
            if (note) note(tr('js.antispam.solving', null, '', true));
            r = await doPost({ captcha_token: tok, 'g-recaptcha-response': tok });
        }
        const s = waitSeconds(r);
        if (s > 0 && opts.button) {
            countdown(opts.button, s, { note, tpl: r.antispam && r.antispam.tpl, answer: r, doneText: opts.doneText });
        }
        return r;
    }

    window.Antispam = { send, countdown, solve, isCaptcha, waitSeconds, waiting, stop, timeText, clock, sentence };
})();
