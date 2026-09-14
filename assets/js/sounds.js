/**
 * Sounds: a short noise when something arrives, for the readers who asked for one (1.56.0).
 *
 * The navigation badge carries `data-sounds` — the reader's resolved choices (includes/sounds.php,
 * soundClientConfig): volume, the pre-roll, and a URL per event. Nothing is carried when the reader
 * has not switched sounds on, and then this file does nothing but exist.
 *
 * WHO PLAYS. The tab that FETCHED a count is the one that plays: window.Sounds.observe(counts) is
 * called by the pulse loop and the inbox poll with what they were just told, the first observation
 * is the baseline, and a later, higher number plays the event's sound. A tab that learned the
 * number from another tab (the pulse's localStorage lease) does not observe, so one reader with
 * six tabs hears one chime.
 *
 * THE PRE-ROLL. An amplifier on the far end of an HDMI link wakes when a stream starts and eats
 * the first second; some stand by until they sense a signal. So the stream is opened and either
 * silence or a very quiet 60 Hz hum plays for `pre` ms before the sound itself. Afterwards the
 * context is suspended again, so the device is not kept awake by a silent stream for ever.
 *
 * HONESTY ABOUT AUTOPLAY. A browser lets a page make a sound only after the person has clicked or
 * typed on it (Chrome remembers a click anywhere on the domain; Firefox wants one on each page).
 * When the context cannot start, the small note beside the account link (#sound-chip) is shown and
 * the sound that was due is kept; the first click anywhere starts the context, hides the note and
 * plays what was waiting — if it is less than five minutes old.
 */
(function () {
    'use strict';
    const badge = document.getElementById('nav-unread');
    const chip = document.getElementById('sound-chip');
    let cfg = null;
    try { cfg = badge && badge.dataset.sounds ? JSON.parse(badge.dataset.sounds) : null; } catch (e) { cfg = null; }
    const AC = window.AudioContext || window.webkitAudioContext;
    // Which count plays which event. A message from a friend and one from anyone else are two
    // events: the callers hand over the total and the friends' share, and the difference is the rest.
    const KEYS = { unread: 'notification', unread_pm_friend: 'message_friend', unread_pm_other: 'message',
                   unread_shout_friend: 'shout_friend', unread_shout_other: 'shout', unread_shout_mention: 'mention' };
    // The three of those that the room can ALSO announce by itself (1.61.0, `shout:new`). One batch
    // of lines can reach this file down either road, and it has to be heard once.
    const SHOUT_KEYS = { unread_shout_friend: 1, unread_shout_other: 1, unread_shout_mention: 1 };
    // How long after a `shout:new` the pulse's version of the same news is swallowed. Comfortably
    // longer than a poll's round trip and far shorter than somebody stepping away from the room.
    const PATH_OVERLAP_MS = 8000;
    let quietUntil = 0;
    const PENDING_MAX_MS = 300000;
    let ctx = null;
    let idleTimer = 0;
    let pending = null;                // {kind, opts, at}: due while the page was still untouched
    const buffers = new Map();
    const last = {};                   // the counts last seen, per key
    const log = [];                    // what happened, for the browser check

    function context() {
        if (!AC) return null;
        if (!ctx) { try { ctx = new AC(); } catch (e) { ctx = null; } }
        return ctx;
    }
    function showChip(on) { if (chip) chip.hidden = !on; }
    async function ensureRunning() {
        const c = context();
        if (!c) return false;
        if (c.state !== 'running') { try { await c.resume(); } catch (e) { /* not allowed yet */ } }
        return c.state === 'running';
    }
    async function load(url) {
        if (buffers.has(url)) return buffers.get(url);
        const c = context();
        if (!c) return null;
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) return null;
        const buf = await c.decodeAudioData(await r.arrayBuffer());
        buffers.set(url, buf);
        return buf;
    }

    /**
     * Play the sound for an event (or, with opts.url, any sound with the given volume and pre-roll).
     * Resolves to 'played' | 'blocked' | 'none' | 'unsupported' | 'missing'.
     */
    async function play(kind, opts) {
        const o = Object.assign({ vol: 60, pre: 1000, pre_kind: 'hum' }, cfg || {}, opts || {});
        const url = o.url || (cfg && cfg.ev && cfg.ev[kind] ? cfg.ev[kind].url : null);
        const done = (r) => { log.push({ kind, r, at: Date.now() }); return r; };
        if (!url) return done('none');
        if (!AC) return done('unsupported');
        if (!(await ensureRunning())) { pending = { kind, opts, at: Date.now() }; showChip(true); return done('blocked'); }
        let buf = null;
        try { buf = await load(url); } catch (e) { buf = null; }
        if (!buf) return done('missing');
        const c = ctx;
        clearTimeout(idleTimer);
        const vol = Math.max(0, Math.min(100, Number(o.vol) || 0)) / 100;
        const pre = Math.max(0, Math.min(3000, Number(o.pre) || 0)) / 1000;
        const t0 = c.currentTime + 0.05;
        if (pre > 0 && o.pre_kind === 'hum') {
            const osc = c.createOscillator();
            osc.type = 'sine';
            osc.frequency.value = 60;
            const g = c.createGain();
            g.gain.setValueAtTime(0, t0);
            // -48 dBFS: enough for a signal-sensing amplifier, not a buzz before every chime
            g.gain.linearRampToValueAtTime(0.004, t0 + 0.08);
            g.gain.setValueAtTime(0.004, t0 + Math.max(0.08, pre - 0.08));
            g.gain.linearRampToValueAtTime(0, t0 + pre);
            osc.connect(g).connect(c.destination);
            osc.start(t0);
            osc.stop(t0 + pre + 0.01);
        } else if (pre > 0) {
            // Silence is still a stream: the device opens and the link wakes on it.
            const z = c.createBuffer(1, Math.max(1, Math.ceil(c.sampleRate * pre)), c.sampleRate);
            const s = c.createBufferSource();
            s.buffer = z;
            s.connect(c.destination);
            s.start(t0);
        }
        const gain = c.createGain();
        gain.gain.value = vol * vol;      // a slider that feels even rather than one that is loud from 20% on
        const src = c.createBufferSource();
        src.buffer = buf;
        src.connect(gain).connect(c.destination);
        src.start(t0 + pre);
        // Let the device go quiet again once this is over. A running context keeps the output open,
        // and an amplifier kept awake by silence is one that never sleeps — the opposite of the point.
        idleTimer = setTimeout(() => { if (ctx && ctx.state === 'running') ctx.suspend().catch(() => {}); },
                               (pre + buf.duration + 1.5) * 1000);
        return done('played');
    }

    /** The tab that fetched these counts says so. The first look is the baseline; a rise plays. */
    function observe(counts) {
        if (!counts || typeof counts !== 'object') return;
        const c = Object.assign({}, counts);
        if (c.unread_pm !== undefined && c.unread_pm !== null) {
            const total = Math.max(0, Number(c.unread_pm) || 0);
            // A caller that does not know the friends' share (an older answer) counts everything as
            // "anyone else" and leaves the friends' baseline alone — nothing false plays either way.
            if (c.unread_pm_friend === undefined || c.unread_pm_friend === null) { c.unread_pm_other = total; delete c.unread_pm_friend; }
            else c.unread_pm_other = Math.max(0, total - Math.max(0, Number(c.unread_pm_friend) || 0));
        }
        // The shoutbox's counts arrive the same way: a total, the friends' share, and the mentions.
        if (c.unread_shout !== undefined && c.unread_shout !== null) {
            const total = Math.max(0, Number(c.unread_shout) || 0);
            if (c.unread_shout_friend === undefined || c.unread_shout_friend === null) { c.unread_shout_other = total; delete c.unread_shout_friend; }
            else c.unread_shout_other = Math.max(0, total - Math.max(0, Number(c.unread_shout_friend) || 0));
        }
        Object.keys(KEYS).forEach((key) => {
            if (c[key] === undefined || c[key] === null) return;
            const n = Math.max(0, Number(c[key]) || 0);
            const prev = last[key];
            last[key] = n;
            if (prev === undefined || n <= prev) return;
            // Just heard through `shout:new`, from the tab that was looking at the room: the pulse is
            // now reporting the same rows, and two chimes for one batch is worse than none. The
            // baseline above has already moved — the point is to swallow a sound, not to lose count.
            if (SHOUT_KEYS[key] && Date.now() < quietUntil) return;
            if (cfg && cfg.ev && cfg.ev[KEYS[key]]) play(KEYS[key]);
        });
    }

    /**
     * Which sound a batch of shouts deserves, or null for silence. Pure, so the decision can be
     * read back and tested without a speaker.
     *
     * ONE sound per batch and the strongest kind wins: three lines landing together is one thing
     * happening, and three chimes on top of each other is a noise nobody asked for. A mention beats
     * a friend and a friend beats anybody else, which is the order the three events are worth
     * interrupting somebody for.
     *
     * MY OWN LINE IS NEVER NEWS. Neither is a line the site said — an announcement is nobody's
     * unread (includes/shout.php agrees) and a room that pinged every time a torrent was registered
     * is the thing `shout_system_lines` must never turn into.
     */
    function shoutKind(rows) {
        if (!Array.isArray(rows)) return null;
        let kind = null;
        for (const r of rows) {
            if (!r || r.own || r.system) continue;
            if (r.mentions_me) return 'mention';        // nothing beats it: stop looking
            if (r.friend) kind = 'shout_friend';
            else if (kind === null) kind = 'shout';
        }
        return kind;
    }

    /**
     * Rows landed while somebody was LOOKING at the room (1.61.0).
     *
     * The pulse cannot answer this one, and that is the whole reason this exists: a reader with the
     * widget on screen has `users.shout_seen_id` stamped the moment it draws, so their unread counts
     * never rise — which made the reader most likely to want a chime the one reader who could never
     * get one. assets/js/shoutbox.js dispatches the rows it has just appended; the mute, the
     * reader's per-kind choices and `sounds.use` all still decide, exactly as they do for the pulse,
     * because every one of those lives inside play().
     */
    function onShoutRows(rows) {
        const kind = shoutKind(rows);
        if (!kind) return null;
        quietUntil = Date.now() + PATH_OVERLAP_MS;
        play(kind);
        return kind;
    }
    window.addEventListener('shout:new', (e) => { onShoutRows(e && e.detail ? e.detail.rows : null); });

    // The gesture that lifts the gate. Listened for only until the browser has let the page play
    // once: afterwards a click must NOT wake the context again, or the device the idle-suspend
    // exists for (an amplifier that should be allowed to sleep) would be kept awake by every click.
    let unlocked = false;
    function onGesture() {
        if (unlocked || (!ctx && !cfg)) return;      // done, or nobody asked for a sound on this page
        ensureRunning().then((ok) => {
            if (!ok) return;
            unlocked = true;
            showChip(false);
            const p = pending;
            pending = null;
            if (p && Date.now() - p.at < PENDING_MAX_MS) play(p.kind, p.opts);
            else if (ctx && ctx.state === 'running') ctx.suspend().catch(() => {});   // nothing to play: back to sleep
        });
    }
    ['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, onGesture, { passive: true, capture: true }));

    // Say so up front: a page that would swallow its first chime shows the note before that happens.
    // A page the browser lets play at once needs no gesture at all — and is put back to sleep until
    // there is something to play.
    if (cfg && AC) {
        const c = context();
        if (c && c.state === 'running') { unlocked = true; c.suspend().catch(() => {}); }
        else if (c) showChip(true);
    }

    /* ─────────────────────────── the account page's tab ─────────────────────────── */
    function initPane() {
        const root = document.getElementById('account-sounds');
        const dataEl = document.getElementById('snd-data');
        if (!root || !dataEl || typeof window.t !== 'function') return;
        const t = window.t;
        let data = null;
        try { data = JSON.parse(dataEl.textContent || 'null'); } catch (e) { data = null; }
        if (!data || !data.library) return;
        const $ = (id) => document.getElementById(id);
        const on = $('snd-on'), vol = $('snd-vol'), volOut = $('snd-vol-out'), pre = $('snd-pre'), preKind = $('snd-pre-kind');
        const status = $('snd-status');
        let sayTimer = 0;
        // A note that says what just happened and then goes: "Saved." sitting there for ever reads as
        // a state the page is in rather than a thing that happened.
        const say = (msg, keep) => {
            if (!status) return;
            status.textContent = msg;
            clearTimeout(sayTimer);
            if (!keep) sayTimer = setTimeout(() => { if (status.textContent === msg) status.textContent = ''; }, 3500);
        };
        const byId = {};
        data.library.forEach((s) => { byId[s.id] = s; });
        const opt = (value, text) => { const o = document.createElement('option'); o.value = value; o.textContent = text; return o; };
        const label = (s) => s.name + (s.ms ? ' · ' + (s.ms / 1000).toFixed(1) + ' s' : '');
        data.kinds.forEach((k) => {
            const sel = $('snd-ev-' + k);
            if (!sel) return;
            const defId = data.defaults[k] || '';
            sel.textContent = '';
            sel.appendChild(opt('default', t('js.sounds.site_default', { name: defId && byId[defId] ? byId[defId].name : t('js.sounds.none') })));
            sel.appendChild(opt('off', t('js.sounds.off')));
            data.library.forEach((s) => sel.appendChild(opt(s.id, label(s))));
            const v = data.prefs.ev ? data.prefs.ev[k] : null;
            sel.value = v === null || v === undefined ? 'default' : (v === '' ? 'off' : v);
            if (!sel.value) sel.value = 'default';
        });
        on.checked = !!data.prefs.on;
        vol.value = data.prefs.vol;
        volOut.textContent = data.prefs.vol + '%';
        pre.value = String(data.prefs.pre);
        if (!pre.value) pre.value = '1000';
        preKind.value = data.prefs.pre_kind;
        vol.addEventListener('input', () => { volOut.textContent = vol.value + '%'; });
        const current = () => {
            const ev = {};
            data.kinds.forEach((k) => { const v = $('snd-ev-' + k).value; ev[k] = v === 'default' ? null : (v === 'off' ? '' : v); });
            return { on: on.checked ? 1 : 0, vol: Number(vol.value), pre: Number(pre.value), pre_kind: preKind.value, ev };
        };
        root.querySelectorAll('.snd-test').forEach((btn) => btn.addEventListener('click', async () => {
            const k = btn.dataset.kind;
            const p = current();
            const choice = p.ev[k];
            const id = choice === null ? (data.defaults[k] || '') : choice;
            const s = id ? byId[id] : null;
            if (!s) { say(t('js.sounds.nothing_to_play')); return; }
            say(t('js.sounds.testing'), true);
            const r = await play(k, { url: s.url, vol: p.vol, pre: p.pre, pre_kind: p.pre_kind });
            say(t('js.sounds.test_' + r));
        }));
        $('snd-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = $('snd-save');
            btn.disabled = true;
            const r = typeof postJson === 'function'
                ? await postJson('user_sound_prefs', { csrf_token: $('account-csrf').value, prefs: current() }) : null;
            btn.disabled = false;
            if (!r || !r.success) { say((r && r.error) || t('js.sounds.save_failed')); return; }
            data.prefs = r.prefs;
            cfg = r.client || null;
            say(t('js.sounds.saved'));
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPane);
    else initPane();

    window.Sounds = {
        play, observe, shoutKind, onShoutRows,
        wants: () => !!cfg,
        config: () => cfg,
        state: () => (ctx ? ctx.state : 'none'),
        last, log,
    };
})();
