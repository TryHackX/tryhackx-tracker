/**
 * The picture and cover editor (1.63.0): the frame, the dialog around it, and the account page.
 *
 * ── the idea (the owner's own flarum-cover-studio, rebuilt) ─────────────────────────────────────
 * Nothing is cropped in the browser and nothing is uploaded until Save. A chosen file opens as a
 * LOCAL preview (a data: URL — readLocal() below says why never an object URL); the owner drags a
 * focal point and sets a zoom; Save sends the file and the three numbers in ONE multipart request,
 * and the server cuts the picture's squares with the very formula this frame draws (CSS
 * object-position semantics). A cover is never cut at all — the
 * profile page paints the same three numbers with the same CSS — so what is framed here is, to the
 * pixel, what everybody sees.
 *
 * ── what changed from the research's FocusDragArea (its section 6b) ────────────────────────────
 *   · pinch zoom with two pointers (it had none, and on a phone the slider was the only way);
 *   · the wheel zooms in proportion to deltaY (it stepped 0.1 per event, which a trackpad fires in
 *     dozens), and the slider covers the whole 0.5–4 (it stopped at 3);
 *   · the hint says "pinch" on a touch screen (it said "scroll" to a phone);
 *   · an image that fails to load says so (its spinner span for ever);
 *   · Re-centre; Save stays disabled until something changed; closing with changes asks first, and
 *     so does leaving the page (it threw them away without a word);
 *   · a cover is framed in the header's REAL shape, desktop or phone, not a 2.5:1 box that matches
 *     neither.
 *
 * The three numbers reach the CSS as custom properties on .fe-body, set through the CSSOM — which no
 * Content-Security-Policy governs — and never as a style="" attribute. No inline handlers anywhere:
 * every listener is added here.
 *
 * window.MediaEditor = { open(options) } is what the account page below and Settings → Profiles
 * (assets/js/admin-profiles.js) both call; a picked file is handed over as `file` and read in here,
 * so there is one reader for both sides and one place that decides what kind of URL it becomes.
 * Strings are `js.media.*`, which the public pages carry (LANG_JS_PUBLIC) and the panel carries
 * with everything else. The buttons take each side's own standard classes (`buttons`), so the
 * editor is drawn in the account page's buttons on the account page and in Bootstrap's in the panel.
 */
(function () {
    'use strict';
    const T = (k, v) => (typeof window.t === 'function' ? window.t(k, v) : k);
    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
    const r2 = (v) => Math.round(v * 100) / 100;
    const ZMIN = 0.5, ZMAX = 4;
    // The real shapes of the profile header, when the page does not say (USER_COVER_DESKTOP_W and
    // USER_COVER_PHONE_W in includes/usermedia.php): the 62em column less its 0.5rem padding each
    // side, and a 390 px phone less 1.5rem each side.
    const DESKTOP_W = 976, PHONE_W = 342;
    const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const EXT = /\.(jpe?g|png|webp|gif)$/i;
    const coarse = () => (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || (navigator.maxTouchPoints || 0) > 0;

    /** createElement with text only — nothing here is ever parsed as HTML. */
    function el(tag, attrs, kids) {
        const n = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (v === null || v === undefined || v === false) return;
            if (k === 'className') n.className = v;
            else if (k === 'text') n.textContent = v;
            else n.setAttribute(k, v === true ? '' : String(v));
        });
        (Array.isArray(kids) ? kids : (kids ? [kids] : [])).forEach((c) => {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return n;
    }

    /**
     * A picked file as a data: URL, for the preview. Resolves to the URL; rejects when the file
     * cannot be read (gone from the disk since it was picked, a permission, a cloud placeholder).
     *
     * NEVER URL.createObjectURL(). That makes a blob: URL, and the policy production ENFORCES — the
     * fallback header in .htaccess, which Apache adds whenever PHP sends no enforcing policy of its
     * own, and production's csp_mode is 'report' — says img-src 'self' data: https:, with no blob:. So in 1.63.0 every new picture and cover
     * failed on the live site with "The image could not be loaded", while locally it worked: php -S
     * reads no .htaccess, and includes/csp.php runs in report-only mode, which blocks nothing.
     * data: is already allowed. Adding blob: to the policy instead would widen it for every page of
     * the site to spare one editor a FileReader.
     *
     * The size is bounded before this is reached (preflight(), from avatar_max_kb, at most 20 MB —
     * USER_MEDIA_KB_MAX): about 27 MB as base64, under the smallest ceiling a browser puts on a data:
     * URL (Firefox, 32 MB). tests/usermedia_test.php fails if either editor script creates an object
     * URL, and scratchpad/shots/media_check.js runs under an ENFORCED policy so it fails the way the
     * live site did.
     */
    function readLocal(file) {
        return new Promise((resolve, reject) => {
            const fr = new FileReader();
            fr.addEventListener('load', () => {
                const url = typeof fr.result === 'string' ? fr.result : '';
                if (url.indexOf('data:') === 0) resolve(url); else reject(new Error('unreadable'));
            });
            fr.addEventListener('error', () => reject(fr.error || new Error('unreadable')));
            fr.readAsDataURL(file);
        });
    }

    /* ─────────────────────────────── the frame ─────────────────────────────── */

    /**
     * One position editor inside `host`. The numbers are written to `vars` (an ancestor of the frame
     * AND of anything that previews it) and every change is reported to opts.onChange.
     */
    function makeFrame(host, vars, opts) {
        const mode = opts.mode === 'cover' ? 'cover' : 'avatar';
        const state = { x: 50, y: 50, zoom: 1 };
        const nat = { w: 0, h: 0 };
        let ready = false;

        const blur = el('img', { className: 'fe-blur', alt: '', 'aria-hidden': 'true', draggable: 'false' });
        const img = el('img', { className: 'fe-img', alt: '', draggable: 'false', decoding: 'async', fetchpriority: 'high' });
        const hint = el('div', { className: 'fe-hint', 'aria-hidden': 'true' });
        const loading = el('div', { className: 'fe-state fe-loading' }, [el('span', { className: 'fe-spin', 'aria-hidden': 'true' }), el('span', { text: T('js.media.loading') })]);
        const error = el('div', { className: 'fe-state fe-error', hidden: true, role: 'alert', text: T('js.media.load_error') });
        const frame = el('div', { className: 'fe-frame', tabindex: '0', role: 'application', 'aria-label': T('js.media.aria') }, [
            blur, img,
            mode === 'avatar' ? el('div', { className: 'fe-mask', 'aria-hidden': 'true' }) : null,
            el('div', { className: 'fe-grid', 'aria-hidden': 'true' }),
            mode === 'cover' ? el('div', { className: 'fe-cross', 'aria-hidden': 'true' }) : null,
            hint, loading, error,
        ]);
        host.appendChild(frame);

        const setHint = (touch) => { hint.textContent = T(touch ? 'js.media.hint_touch' : 'js.media.hint_mouse'); };
        setHint(coarse());

        let raf = 0;
        function render() {
            raf = 0;
            vars.style.setProperty('--fe-x', state.x + '%');
            vars.style.setProperty('--fe-y', state.y + '%');
            vars.style.setProperty('--fe-z', String(state.zoom));
            vars.classList.toggle('fe-zoomout', state.zoom < 1);
            if (opts.onChange) opts.onChange(get());
        }
        const schedule = () => { if (!raf) raf = requestAnimationFrame(render); };
        function set(s, now) {
            if (s.x !== undefined) state.x = clamp(Number(s.x) || 0, 0, 100);
            if (s.y !== undefined) state.y = clamp(Number(s.y) || 0, 0, 100);
            if (s.zoom !== undefined) state.zoom = clamp(Number(s.zoom) || 1, ZMIN, ZMAX);
            if (now) { if (raf) cancelAnimationFrame(raf); render(); } else schedule();
        }
        /** Rounded to what the columns keep; the frame itself works unrounded so small moves add up. */
        const get = () => ({ x: r2(state.x), y: r2(state.y), zoom: r2(state.zoom) });

        /**
         * The signed pan range per axis at zoom z: natural × cover-fit scale × zoom − box. Positive:
         * the picture overflows and the frame slides over it; negative (zoomed out): it floats inside
         * and follows the pointer. One formula for both, and an axis within a pixel is locked.
         */
        function geom(z) {
            const r = frame.getBoundingClientRect();
            const s = Math.max(r.width / nat.w, r.height / nat.h);
            return { left: r.left, top: r.top, bw: r.width, bh: r.height, ww: nat.w * s, wh: nat.h * s,
                     rx: nat.w * s * z - r.width, ry: nat.h * s * z - r.height };
        }

        // ── pointers: one drags, two pinch ──
        const pts = new Map();
        let drag = null, pinch = null;
        function seed() {
            const list = Array.from(pts.values());
            if (list.length === 1) {
                drag = { x0: list[0].x, y0: list[0].y, fx: state.x, fy: state.y, g: geom(state.zoom) };
                pinch = null;
            } else if (list.length >= 2) {
                const [a, b] = list;
                const g = geom(state.zoom);
                const mx = (a.x + b.x) / 2 - g.left, my = (a.y + b.y) / 2 - g.top;
                // The picture's point under the fingers, in cover-fit pixels: P = X/100·(B − Z·W') + Z·u.
                const ux = (mx - state.x / 100 * (g.bw - state.zoom * g.ww)) / state.zoom;
                const uy = (my - state.y / 100 * (g.bh - state.zoom * g.wh)) / state.zoom;
                pinch = { d0: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)), z0: state.zoom, ux, uy, fx: state.x, fy: state.y };
                drag = null;
            }
        }
        function onMove(e) {
            if (!pts.has(e.pointerId)) return;
            pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (drag && pts.size === 1) {
                const p = pts.values().next().value;
                // 1:1 — the point under the finger stays under the finger.
                const x = Math.abs(drag.g.rx) > 1 ? drag.fx - (p.x - drag.x0) / drag.g.rx * 100 : drag.fx;
                const y = Math.abs(drag.g.ry) > 1 ? drag.fy - (p.y - drag.y0) / drag.g.ry * 100 : drag.fy;
                set({ x, y });
            } else if (pinch && pts.size >= 2) {
                const [a, b] = Array.from(pts.values());
                const z = clamp(pinch.z0 * Math.hypot(a.x - b.x, a.y - b.y) / pinch.d0, ZMIN, ZMAX);
                const g = geom(z);
                const mx = (a.x + b.x) / 2 - g.left, my = (a.y + b.y) / 2 - g.top;
                // Solved for the focus that keeps the same picture point under the moving fingers.
                const dx = g.bw - z * g.ww, dy = g.bh - z * g.wh;
                const x = Math.abs(dx) > 1 ? 100 * (mx - z * pinch.ux) / dx : pinch.fx;
                const y = Math.abs(dy) > 1 ? 100 * (my - z * pinch.uy) / dy : pinch.fy;
                set({ x, y, zoom: z });
            }
        }
        function onUp(e) {
            if (!pts.delete(e.pointerId)) return;
            if (pts.size) { seed(); return; }       // one finger lifted from a pinch: carry on dragging
            drag = pinch = null;
            vars.classList.remove('fe-dragging');
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onUp);
        }
        function onDown(e) {
            if (!ready || (e.pointerType === 'mouse' && e.button !== 0)) return;
            e.preventDefault();
            try { frame.focus({ preventScroll: true }); } catch (x) { frame.focus(); }
            if (e.pointerType === 'touch') setHint(true);
            if (!pts.size) {
                window.addEventListener('pointermove', onMove);
                window.addEventListener('pointerup', onUp);
                window.addEventListener('pointercancel', onUp);
            }
            pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
            vars.classList.add('fe-dragging');
            seed();
        }
        frame.addEventListener('pointerdown', onDown);
        // In proportion to how far the wheel turned: a notch of a mouse is ~100 px, a trackpad sends
        // a stream of small numbers, and both should feel like the same control.
        frame.addEventListener('wheel', (e) => {
            if (!ready) return;
            e.preventDefault();
            let dy = e.deltaY;
            if (e.deltaMode === 1) dy *= 16;
            else if (e.deltaMode === 2) dy *= 400;
            set({ zoom: state.zoom * Math.exp(-dy * 0.0015) });
        }, { passive: false });
        frame.addEventListener('keydown', (e) => {
            if (!ready) return;
            const step = e.shiftKey ? 10 : 2;
            switch (e.key) {
                case 'ArrowLeft':  set({ x: state.x - step }, true); break;
                case 'ArrowRight': set({ x: state.x + step }, true); break;
                case 'ArrowUp':    set({ y: state.y - step }, true); break;
                case 'ArrowDown':  set({ y: state.y + step }, true); break;
                case '+': case '=': set({ zoom: state.zoom + 0.1 }, true); break;
                case '-': case '_': set({ zoom: state.zoom - 0.1 }, true); break;
                default: return;
            }
            e.preventDefault();
        });
        frame.addEventListener('dragstart', (e) => e.preventDefault());

        /**
         * Show an image: an address, or a promise of one (a file still being read). Resolves true
         * once it has drawn, false if it could not be read or loaded — one error either way, since
         * to the person both mean "choose it again, or another one".
         */
        function load(src) {
            ready = false;
            loading.hidden = false;
            error.hidden = true;
            const draw = (url) => new Promise((resolve) => {
                const done = (ok) => {
                    img.removeEventListener('load', onLoad);
                    img.removeEventListener('error', onErr);
                    resolve(ok);
                };
                const onLoad = () => { nat.w = img.naturalWidth || 1; nat.h = img.naturalHeight || 1; done(true); };
                const onErr = () => done(false);
                img.addEventListener('load', onLoad);
                img.addEventListener('error', onErr);
                img.src = url;
                blur.src = url;
            });
            return Promise.resolve(src).then(draw, () => false).then((ok) => {
                loading.hidden = true;
                error.hidden = ok;
                ready = ok;
                return ok;
            });
        }
        return { frame, load, set, get, isReady: () => ready };
    }

    /* ─────────────────────────────── the dialog ─────────────────────────────── */

    let openNow = null;

    /**
     * Open the editor. Resolves to true when saved, false when closed without saving.
     *   mode      'avatar' | 'cover'
     *   file      a File just picked: read here into a data: URL (readLocal) while the frame spins
     *   src       …or the address of the stored image (Adjust)
     *   fresh     true for a new file (it is a change by itself, so Save starts enabled)
     *   x, y, zoom  where to start
     *   coverH, coverHm  the header's height on a desktop and on a phone (covers)
     *   desktopW, phoneW the header's width on each, in CSS px (covers)
     *   note      the sentence under the frame
     *   save(state) → Promise<{ok, message}>
     *   buttons   {primary, secondary, danger} class lists for this side of the site
     */
    function open(o) {
        if (openNow) openNow.force();
        return new Promise((resolve) => {
            const mode = o.mode === 'cover' ? 'cover' : 'avatar';
            // The account page's own standard buttons, all at one size: 1.63.0 drew Save at the full
            // .btn size between two .btn-small ones, so the footer's buttons did not line up. The panel
            // passes Bootstrap's. `danger` is what throws work away (Discard); on the account page it
            // is the secondary button that turns red on hover, the way the site's other "delete?" reads.
            const btn = Object.assign({ primary: 'btn btn-small fe-btn-primary', secondary: 'btn btn-secondary btn-small',
                                        danger: 'btn btn-secondary btn-small fe-btn-danger' }, o.buttons || {});
            const num = (v, d) => (v === null || v === undefined || v === '' || !isFinite(Number(v))) ? d : Number(v);
            const start = { x: r2(clamp(num(o.x, 50), 0, 100)), y: r2(clamp(num(o.y, 50), 0, 100)),
                            zoom: r2(clamp(num(o.zoom, 1), ZMIN, ZMAX)) };
            const titleId = 'fe-title-' + Math.random().toString(36).slice(2, 8);
            const body = el('div', { className: 'fe-body fe-mode-' + mode });
            const stage = el('div', { className: 'fe-stage' });
            const msg = el('p', { className: 'fe-msg', role: 'status', 'aria-live': 'polite' });
            const zoomIn = el('input', { type: 'range', min: String(ZMIN), max: String(ZMAX), step: '0.01', list: titleId + '-ticks', 'aria-label': T('js.media.zoom') });
            const readout = el('button', { type: 'button', className: btn.secondary + ' fe-readout', title: T('js.media.zoom_reset'), 'aria-label': T('js.media.zoom_reset') });
            const save = el('button', { type: 'button', className: btn.primary + ' fe-save', disabled: true, text: T('js.media.save') });
            const cancel = el('button', { type: 'button', className: btn.secondary + ' fe-cancel', text: T('js.media.cancel') });
            const recentre = el('button', { type: 'button', className: btn.secondary + ' fe-recentre' },
                [el('i', { className: 'bi bi-crosshair', 'aria-hidden': 'true' }), ' ' + T('js.media.recentre')]);
            const discard = el('button', { type: 'button', className: btn.danger + ' fe-discard', text: T('js.media.discard') });
            const keep = el('button', { type: 'button', className: btn.secondary + ' fe-keep', text: T('js.media.keep') });
            const ask = el('span', { className: 'fe-ask', hidden: true }, [el('span', { text: T('js.media.discard_q') }), discard, keep]);
            const close = el('button', { type: 'button', className: 'fe-close', 'aria-label': T('js.media.close'), title: T('js.media.close'), text: '×' });
            const box = el('div', { className: 'fe-box fe-box-' + mode, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId }, [
                el('div', { className: 'fe-head' }, [el('h3', { id: titleId, text: o.title || T(mode === 'cover' ? 'js.media.title_cover' : 'js.media.title_avatar') }), close]),
                body,
                el('div', { className: 'fe-foot' }, [recentre, el('span', { className: 'fe-spacer' }), ask, cancel, save]),
            ]);
            const overlay = el('div', { className: 'fe-overlay' }, [box]);

            // A cover, in the header's real shape — and the phone's, one click away.
            let shapeDesc = null;
            const setShape = (phone) => {
                const w = Number(phone ? o.phoneW : o.desktopW) || (phone ? PHONE_W : DESKTOP_W);
                const h = Math.max(1, Number(phone ? o.coverHm : o.coverH) || (phone ? 160 : 220));
                body.classList.toggle('fe-shape-phone', phone);
                stage.style.setProperty('--fe-ar', w + ' / ' + h);
                if (shapeDesc) shapeDesc.textContent = T(phone ? 'js.media.shape_phone_desc' : 'js.media.shape_desktop_desc', { w: w, h: h });
                body.querySelectorAll('.fe-shape button').forEach((b) => b.setAttribute('aria-pressed', String((b.dataset.shape === 'phone') === phone)));
            };
            if (mode === 'cover') {
                shapeDesc = el('span', { className: 'fe-shape-desc' });
                const mk = (id, label) => {
                    const b = el('button', { type: 'button', className: btn.secondary, 'data-shape': id, 'aria-pressed': 'false' },
                        [el('i', { className: 'bi ' + (id === 'phone' ? 'bi-phone' : 'bi-display'), 'aria-hidden': 'true' }), ' ' + label]);
                    b.addEventListener('click', () => setShape(id === 'phone'));
                    return b;
                };
                body.appendChild(el('div', { className: 'fe-shape', role: 'group', 'aria-label': T('js.media.shape_label') },
                    [mk('desktop', T('js.media.shape_desktop')), mk('phone', T('js.media.shape_phone')), shapeDesc]));
            }
            body.appendChild(stage);
            body.appendChild(el('div', { className: 'fe-zoomrow' }, [
                el('label', { text: T('js.media.zoom') }), zoomIn,
                el('datalist', { id: titleId + '-ticks' }, [el('option', { value: '1' })]), readout]));
            // A picture, at the three sizes it is really drawn at — live.
            const minis = [];
            if (mode === 'avatar') {
                const row = el('div', { className: 'fe-sizes', 'aria-hidden': 'true' }, [el('span', { className: 'fe-sizes-label', text: T('js.media.sizes') })]);
                [64, 32, 24].forEach((px) => {
                    const a = el('img', { className: 'fe-mini-blur', alt: '' });
                    const b = el('img', { className: 'fe-mini-img', alt: '' });
                    const m = el('div', { className: 'fe-mini' }, [a, b]);
                    m.style.width = px + 'px';
                    m.style.height = px + 'px';
                    minis.push(a, b);
                    row.appendChild(el('div', {}, [m, el('span', { className: 'fe-mini-cap', text: px + ' px' })]));
                });
                body.appendChild(row);
            }
            if (o.note) body.appendChild(el('p', { className: 'fe-note', text: o.note }));
            body.appendChild(msg);

            let dirty = !!o.fresh, saving = false, done = false, loaded = false;
            const same = (a, b) => a.x === b.x && a.y === b.y && a.zoom === b.zoom;
            const onChange = (st) => {
                zoomIn.value = String(st.zoom);
                readout.textContent = st.zoom.toFixed(2) + '×';
                dirty = !!o.fresh || !same(st, start);
                save.disabled = saving || !loaded || !dirty;
            };
            const fe = makeFrame(stage, body, { mode: mode, onChange: onChange });
            if (mode === 'cover') setShape(false);
            fe.set(start, true);

            const say = (text, kind) => { msg.textContent = text || ''; msg.className = 'fe-msg' + (kind ? ' is-' + kind : ''); };
            zoomIn.addEventListener('input', () => fe.set({ zoom: Number(zoomIn.value) }));
            readout.addEventListener('click', () => fe.set({ zoom: 1 }, true));
            recentre.addEventListener('click', () => { fe.set({ x: 50, y: 50 }, true); fe.frame.focus(); });

            const guard = (e) => { if (dirty && !done) { e.preventDefault(); e.returnValue = ''; } };
            window.addEventListener('beforeunload', guard);
            const finish = (saved) => {
                if (done) return;
                done = true;
                window.removeEventListener('beforeunload', guard);
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                openNow = null;
                if (o.returnFocus && o.returnFocus.isConnected) { try { o.returnFocus.focus(); } catch (x) { /* gone */ } }
                resolve(saved);
            };
            const tryClose = () => {
                if (saving) return;
                if (dirty) { ask.hidden = false; cancel.hidden = true; save.hidden = true; keep.focus(); return; }
                finish(false);
            };
            discard.addEventListener('click', () => finish(false));
            keep.addEventListener('click', () => { ask.hidden = true; cancel.hidden = false; save.hidden = false; fe.frame.focus(); });
            cancel.addEventListener('click', tryClose);
            close.addEventListener('click', tryClose);
            // A press that starts AND ends on the backdrop; a drag that strays off the frame is not a click away.
            let downOnBackdrop = false;
            overlay.addEventListener('pointerdown', (e) => { downOnBackdrop = e.target === overlay; });
            overlay.addEventListener('click', (e) => { if (e.target === overlay && downOnBackdrop) tryClose(); });
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); if (!ask.hidden) { keep.click(); } else tryClose(); return; }
                if (e.key !== 'Tab') return;
                // The focus stays inside while the dialog is open.
                const f = Array.from(box.querySelectorAll('button, input, [tabindex="0"]')).filter((n) => !n.disabled && !n.hidden && n.getClientRects().length);
                if (!f.length) return;
                const i = f.indexOf(document.activeElement);
                if (e.shiftKey && (i <= 0)) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && (i === f.length - 1 || i === -1)) { e.preventDefault(); f[0].focus(); }
            }
            document.addEventListener('keydown', onKey, true);
            save.addEventListener('click', async () => {
                if (saving || save.disabled) return;
                saving = true;
                save.disabled = true;
                save.textContent = T('js.media.saving');
                say('');
                let r;
                try { r = await o.save(fe.get()); } catch (x) { r = { ok: false }; }
                saving = false;
                save.textContent = T('js.media.save');
                if (r && r.ok) { dirty = false; finish(true); return; }
                save.disabled = false;
                say((r && r.message) || T('js.media.failed'), 'bad');
            });

            openNow = { force: () => finish(false) };
            document.body.appendChild(overlay);
            // A picked file is read HERE, not by the caller: the dialog is on screen at once with its
            // spinner, a file that cannot be read ends in the same error as one that cannot be decoded,
            // and a slow read of an earlier pick cannot open over a newer one — the newer open() has
            // already closed this dialog (openNow.force above), and a closed dialog draws nothing.
            const src = (o.file ? readLocal(o.file) : Promise.resolve(o.src)).then((url) => {
                if (done) throw new Error('closed');
                minis.forEach((m) => { m.src = url; });
                return url;
            });
            fe.load(src).then((ok) => {
                if (done) return;
                loaded = ok;
                onChange(fe.get());
                if (ok) { try { fe.frame.focus({ preventScroll: true }); } catch (x) { fe.frame.focus(); } }
                else { recentre.disabled = true; zoomIn.disabled = true; readout.disabled = true; close.focus(); }
            });
            close.focus();
        });
    }

    /** Is this a file the server could take? Its type (or, failing that, its name) and its size. */
    function preflight(file, maxBytes) {
        if (!file) return T('js.media.not_image');
        if (!(TYPES.indexOf(file.type) !== -1 || (!file.type && EXT.test(file.name || '')))) return T('js.media.not_image');
        if (maxBytes > 0 && file.size > maxBytes) return T('js.media.too_large', { kb: Math.floor(maxBytes / 1024) });
        return '';
    }

    window.MediaEditor = { open: open, preflight: preflight };

    /* ─────────────────────────────── the account page ─────────────────────────────── */

    const data = (() => {
        const n = document.getElementById('acc-media-data');
        if (!n) return null;
        try { return JSON.parse(n.textContent || '{}'); } catch (e) { return null; }
    })();
    if (!data || !document.getElementById('acc-media')) return;
    const csrf = () => { const n = document.getElementById('account-csrf'); return n ? n.value : ''; };

    /** POST to user_avatar / user_cover. Multipart: the file, when there is one, travels as itself. */
    async function post(kind, fields, file) {
        const fd = new FormData();
        fd.append('csrf_token', csrf());
        Object.keys(fields).forEach((k) => fd.append(k, String(fields[k])));
        if (file) fd.append('file', file, file.name || 'image');
        let res, j;
        try {
            res = await fetch(APP_API + 'user_' + kind, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
            j = await res.json();
        } catch (e) {
            return { ok: false, message: T('js.media.failed') };
        }
        if (!j || !j.success) return { ok: false, message: (j && (j.message || (typeof j.error === 'string' && j.error.indexOf(' ') !== -1 ? j.error : ''))) || T('js.media.failed') };
        return { ok: true, message: j.message || T('js.media.saved'), state: j[kind], firstFrame: !!j.first_frame };
    }

    /**
     * "Are you sure?", in the place the button was — the shoutbox's in-place shape, but with the
     * page's standard small buttons: the shoutbox's own are sized for a two-line shout, and beside
     * the Adjust and Remove they replace they read as a different control.
     */
    function askInPlace(btn, question, yesLabel, onYes) {
        const yes = el('button', { type: 'button', className: 'btn btn-secondary btn-small fe-btn-danger acc-media-yes', text: yesLabel });
        const no = el('button', { type: 'button', className: 'btn btn-secondary btn-small acc-media-no', text: T('js.media.no') });
        const box = el('span', { className: 'shout-confirm acc-media-ask', role: 'group' }, [el('span', { className: 'shout-confirm-q', text: question }), yes, no]);
        yes.addEventListener('click', async () => { yes.disabled = no.disabled = true; await onYes(); box.replaceWith(btn); btn.focus(); });
        no.addEventListener('click', () => { box.replaceWith(btn); btn.focus(); });
        btn.replaceWith(box);
        no.focus();
    }

    function block(kind) {
        const root = document.getElementById('acc-' + kind);
        if (!root) return;
        const st = data[kind] || {};
        const drop = root.querySelector('.acc-media-drop');
        const input = root.querySelector('input[type="file"]');
        const adjust = root.querySelector('.acc-media-adjust');
        const remove = root.querySelector('.acc-media-remove');
        const status = root.querySelector('.acc-media-status');
        const say = (text, bad) => { if (!status) return; status.textContent = text || ''; status.classList.toggle('is-bad', !!bad); };

        function paint(s) {
            Object.assign(st, s || {});
            if (adjust) adjust.hidden = !st.has;
            if (remove) remove.hidden = !st.has;
            if (kind === 'avatar') {
                // The three real sizes: 64 CSS px uses the 128 square, 32 and 24 the 64 one. Through
                // window.userAvatarSet() (assets/js/avatar.js) from 1.63.0 phase B, because the element
                // carries a srcset now and a dense screen would go on showing the old picture from it —
                // and the same for the reader's own picture elsewhere on the page (the navigation, the
                // heading), which is the same picture beside the same name.
                const mine = Array.from(root.querySelectorAll('img.acc-av')).concat(Array.from(document.querySelectorAll('img.js-avatar-me')));
                mine.forEach((im) => {
                    const px = Number(im.getAttribute('width')) || 64;
                    const src = px >= 64 ? st.url128 : st.url64;
                    if (!src) return;
                    if (typeof window.userAvatarSet === 'function') window.userAvatarSet(im, src);
                    else if (im.getAttribute('src') !== src) im.setAttribute('src', src);
                });
            } else {
                const band = root.querySelector('.acc-cover-now');
                if (band) {
                    // Written onto the element through the CSSOM, which outranks the page's nonce'd
                    // <style> for the same element — including when there is nothing to paint any more.
                    const css = String(st.css || '');
                    if (css === '') band.style.setProperty('--cv-img', 'none');
                    css.split(';').forEach((decl) => {
                        const i = decl.indexOf(':');
                        if (i > 0) band.style.setProperty(decl.slice(0, i).trim(), decl.slice(i + 1).trim());
                    });
                    band.classList.toggle('has-cover', css !== '');
                    band.classList.toggle('cv-zoomout', css !== '' && /--cv-z:0\./.test(css));
                    const lab = root.querySelector('.acc-cover-default');
                    if (lab) lab.hidden = !st.is_default;
                }
            }
        }

        const note = T(kind === 'cover' ? 'js.media.note_cover' : 'js.media.note_avatar');
        const edit = (src, fresh, file) => window.MediaEditor.open({
            mode: kind, src: src, file: fresh ? file : null, fresh: fresh, x: fresh ? 50 : st.x, y: fresh ? 50 : st.y, zoom: fresh ? 1 : st.zoom,
            coverH: data.cover_h, coverHm: data.cover_hm, desktopW: data.desk_w, phoneW: data.phone_w,
            note: note, returnFocus: fresh ? drop : adjust,
            save: async (s) => {
                const r = await post(kind, fresh ? { x: s.x, y: s.y, zoom: s.zoom } : { op: 'position', x: s.x, y: s.y, zoom: s.zoom }, fresh ? file : null);
                if (r.ok) { paint(r.state); say(r.firstFrame ? T('js.media.first_frame') : r.message); }
                return r;
            },
        });

        function take(file) {
            if (input) input.value = '';        // the same file picked twice must still be a change
            const bad = preflight(file, Number(data.max_bytes) || 0);
            if (bad) { say(bad, true); return; }
            say('');
            edit(null, true, file);             // the editor reads it (readLocal): see there for why
        }
        if (drop && input) {
            input.setAttribute('tabindex', '-1');   // the box is the keyboard's way in, not a second stop
            input.addEventListener('change', () => { if (input.files && input.files[0]) take(input.files[0]); });
            drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
            // dragover must be cancelled, or the browser opens the file and the page is gone.
            ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('dragging'); }));
            ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('dragging')));
            drop.addEventListener('drop', (e) => {
                e.preventDefault();
                const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (f) take(f);
            });
        }
        if (adjust) adjust.addEventListener('click', () => { if (st.has && st.src) edit(st.src, false, null); });
        if (remove) remove.addEventListener('click', () => askInPlace(remove, T(kind === 'cover' ? 'js.media.remove_q_cover' : 'js.media.remove_q_avatar'),
            T('js.media.yes_remove'), async () => {
                const r = await post(kind, { op: 'remove' }, null);
                if (r.ok) { paint(r.state); say(T('js.media.removed')); } else say(r.message, true);
            }));
        paint({});
    }
    block('avatar');
    block('cover');
})();
