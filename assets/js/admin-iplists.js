/**
 * "Address lists" card on the admin Traffic page — includes/iplist.php on the server.
 *
 * Whole networks and whole countries, from a pasted file or a URL that is re-downloaded on a timer.
 * Three behaviours, and the card's job is to make which one a row has impossible to misread:
 *
 *   Allow           never dropped, whatever else says
 *   Block           dropped always
 *   Under pressure  dropped only when the machine is busy
 *
 * Nothing here reaches the firewall except "Push to firewall". Every other action writes to the
 * database and to the file the root helper reads, so a list can be built, its parsed entry count
 * checked, and only then enforced — which is why the push is the one action that asks for the
 * password.
 *
 * Renders through textContent / createElement only.
 */
(function () {
    'use strict';

    const card = document.getElementById('iplists-card');
    if (!card || typeof window.AdminCommon === 'undefined') return;
    const { apiCall, el, showToast, confirmAction, promptPassword } = window.AdminCommon;

    const $ = (id) => document.getElementById(id);
    const MAX_UPLOAD = 8 * 1024 * 1024;

    let state = null;
    let busy = false;

    const fmt = (n) => Number(n || 0).toLocaleString();
    /** 342983424 -> "343 M". A column is not the place for nine digits. */
    const short = (n) => {
        n = Number(n || 0);
        if (n >= 1e9) return (n / 1e9).toFixed(n >= 1e10 ? 0 : 1) + ' B';
        if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1) + ' M';
        if (n >= 1e3) return (n / 1e3).toFixed(n >= 1e4 ? 0 : 1) + ' k';
        return String(n);
    };

    function ago(sqlDate) {
        if (!sqlDate) return t('js.iplists.never');
        const ts = Date.parse(String(sqlDate).replace(' ', 'T') + 'Z');
        if (!isFinite(ts)) return String(sqlDate);
        const s = Math.max(0, (Date.now() - ts) / 1000);
        if (s < 90) return t('js.iplists.ago_s', { n: Math.round(s) });
        if (s < 5400) return t('js.iplists.ago_min', { n: Math.round(s / 60) });
        if (s < 172800) return t('js.iplists.ago_h', { n: Math.round(s / 3600) });
        return t('js.iplists.ago_d', { n: Math.round(s / 86400) });
    }

    // A row's label has to say which of the three behaviours it has: "block" on its own is exactly
    // the ambiguity this feature exists to remove.
    function badge(l) {
        if (l.kind === 'allow') return [t('js.iplists.badge_allow'), 'text-success', t('js.iplists.badge_allow_desc')];
        if (l.mode === 'soft') return [t('js.iplists.badge_soft'), 'text-warning', t('js.iplists.badge_soft_desc')];
        return [t('js.iplists.badge_block'), 'text-danger', t('js.iplists.badge_block_desc')];
    }

    function iconBtn(icon, title, cls, fn) {
        const b = el('button', { type: 'button', className: 'btn btn-sm ' + cls + ' ms-1 py-0 px-1', title: title },
                     [el('i', { className: 'bi ' + icon })]);
        b.addEventListener('click', fn);
        return b;
    }

    function render() {
        if (!state) return;
        const box = $('ipl-list');
        box.textContent = '';

        const c = state.counts || {};
        const cv = state.cover || {};
        const total = (c.allow || 0) + (c.hard || 0) + (c.soft || 0);
        const totalAddr = (cv.allow || 0) + (cv.hard || 0) + (cv.soft || 0);
        const head = $('ipl-updated');
        if (head) {
            // Networks first, addresses second: the ceiling is on networks, and the reader's question
            // ("is this enough to block a country?") is about addresses.
            head.textContent = state.enabled
                ? t('js.iplists.head_enforced', { n: fmt(total) })
                  + (totalAddr ? ' · ' + t('js.iplists.head_addresses', { n: short(totalAddr) }) : '')
                  + ' · ' + t('js.iplists.head_max', { n: fmt(state.max) })
                : t('js.iplists.head_off');
            head.className = 'wl-status-updated' + (state.enabled ? '' : ' text-warning');
        }

        if (!state.lists.length) {
            box.appendChild(el('p', { className: 'wl-small text-muted mb-0',
                text: t('js.iplists.no_lists') }));
            return;
        }

        const thead = el('thead', null, [el('tr', null, [
            el('th', { style: 'width:1%' }),
            el('th', { text: t('js.iplists.th_list') }),
            el('th', { text: t('js.iplists.th_what') }),
            el('th', { className: 'text-end', text: t('js.iplists.th_networks') }),
            el('th', { text: t('js.iplists.th_source') }),
            el('th', { className: 'text-end', text: t('js.iplists.th_actions') }),
        ])]);
        const tb = el('tbody');

        state.lists.forEach((l) => {
            const tr = el('tr', { className: l.enabled ? null : 'ipl-off' });

            const cb = el('input', { type: 'checkbox', className: 'form-check-input',
                                     title: l.enabled ? t('js.iplists.enabled') : t('js.iplists.disabled') });
            cb.checked = !!l.enabled;
            cb.addEventListener('change', () => act('toggle', { id: l.id, enabled: cb.checked }));
            tr.appendChild(el('td', null, [el('div', { className: 'form-check form-switch mb-0' }, [cb])]));

            const name = el('td', null, [el('span', { className: 'text-light', text: l.name })]);
            if (l.last_error) {
                name.appendChild(el('br'));
                name.appendChild(el('span', {
                    className: 'wl-small text-danger',
                    title: t('js.iplists.last_error_title'),
                    text: '⚠ ' + l.last_error,
                }));
            }
            tr.appendChild(name);

            const b = badge(l);
            tr.appendChild(el('td', null, [
                el('span', { className: b[1], text: b[0] }),
                el('br'),
                el('span', { className: 'wl-small text-muted', text: b[2] }),
            ]));

            // ENTRIES AND WHAT THEY COVER. "8 810" is a true and useless answer to "is this enough
            // to block China"; "343 million addresses" is the one the reader is actually asking for.
            const n = el('td', { className: 'text-end' },
                [el('span', { className: 'text-light', text: fmt(l.entries) })]);
            if (l.addr4 || l.nets6) {
                n.appendChild(el('br'));
                const parts = [];
                if (l.addr4) parts.push(t('js.iplists.addr_short', { n: short(l.addr4) }));
                if (l.nets6) parts.push(t('js.iplists.v6_short', { n: fmt(l.nets6) }));
                n.appendChild(el('span', { className: 'wl-small text-muted', text: parts.join(' · '),
                    title: (l.addr4 ? t('js.iplists.ipv4_addresses', { n: fmt(l.addr4) }) : '')
                        + (l.addr4 && l.nets6 ? ', ' : '')
                        + (l.nets6 ? t('js.iplists.ipv6_ranges', { n: fmt(l.nets6) }) : '') }));
            }
            tr.appendChild(n);

            const src = el('td');
            if (l.source === 'url') {
                src.appendChild(el('a', {
                    className: 'wl-small', href: l.url, target: '_blank', rel: 'noopener noreferrer',
                    title: l.url, text: l.url.length > 46 ? l.url.slice(0, 44) + '…' : l.url,
                }));
                src.appendChild(el('br'));
                src.appendChild(el('span', { className: 'wl-small text-muted',
                    text: t('js.iplists.fetched_every', { ago: ago(l.last_fetch_at), n: fmt(l.ttl_minutes) }) }));
            } else {
                src.appendChild(el('span', { className: 'wl-small text-muted', text: t('js.iplists.uploaded', { ago: ago(l.last_fetch_at) }) }));
            }
            tr.appendChild(src);

            const acts = el('td', { className: 'text-end text-nowrap' });
            if (l.source === 'url') {
                acts.appendChild(iconBtn('bi-arrow-clockwise', t('js.iplists.refresh_now'), 'btn-outline-info',
                    () => act('refresh', { id: l.id })));
            } else {
                acts.appendChild(iconBtn('bi-upload', t('js.iplists.replace_from_file'), 'btn-outline-info',
                    () => replaceEntries(l)));
            }
            acts.appendChild(iconBtn('bi-trash', t('js.iplists.delete_list'), 'btn-outline-danger', async () => {
                const ok = await confirmAction(t('js.iplists.delete_title'),
                    t('js.iplists.delete_body', { name: l.name, count: fmt(l.entries) }), { danger: true, okLabel: t('js.iplists.delete') });
                if (ok) act('delete', { id: l.id });
            }));
            tr.appendChild(acts);
            tb.appendChild(tr);
        });

        box.appendChild(el('table', { className: 'table table-sm table-dark align-middle mb-0 ipl-table' }, [thead, tb]));

        // Not errors — both of these are the precedence order working — but both are things somebody
        // would otherwise work out the hard way, by watching an address behave the opposite of how
        // the list they just imported says it should.
        (state.conflicts || []).length && box.appendChild(el('div', {
            className: 'alert alert-info py-2 wl-small mb-0 mt-2',
            text: t(state.conflicts.length === 1 ? 'js.iplists.conflict_one' : 'js.iplists.conflict_many',
                { list: state.conflicts.map(c => t('js.iplists.conflict_item', { addr: c.manual, net: c.covered_by })).join(', ') }) }));

        (state.exceptions || []).length && box.appendChild(el('div', {
            className: 'alert alert-secondary py-2 wl-small mb-0 mt-2',
            text: t('js.iplists.exceptions', { list: state.exceptions.map(c => t('js.iplists.exception_item', { addr: c.manual, net: c.covered_by })).join(', ') }) }));
        const cov = state.cover || {};
        const withCover = (n, a) => a ? t('js.iplists.with_cover', { n: fmt(n), a: short(a) }) : fmt(n);
        box.appendChild(el('p', { className: 'wl-small text-muted mb-0 mt-2',
            text: t('js.iplists.enforced_now', { allow: withCover(c.allow, cov.allow), hard: withCover(c.hard, cov.hard), soft: withCover(c.soft, cov.soft) })
                + (state.manual && state.manual.length
                    ? t('js.iplists.plus_trusted', { n: state.manual.length })
                    : '')
                + (state.blocked && state.blocked.length
                    ? t(state.manual && state.manual.length ? 'js.iplists.plus_blocked_and' : 'js.iplists.plus_blocked', { n: state.blocked.length })
                    : '.') }));
    }

    async function load() {
        const r = await apiCall('admin/ip_lists');
        if (r.error) {
            $('ipl-list').textContent = '';
            $('ipl-list').appendChild(el('p', { className: 'wl-small text-danger mb-0',
                text: t('js.iplists.load_failed', { error: r.error }) }));
            return;
        }
        state = r;
        render();
    }

    async function act(op, body) {
        if (busy) return;
        busy = true;
        try {
            const r = await apiCall('admin/ip_list_action', 'POST', Object.assign({ op: op }, body));
            if (r.error) showToast(r.error, 'danger');
            else showToast(r.message || t('js.iplists.done'), r.note ? 'warning' : 'success');
            await load();
        } finally {
            busy = false;
        }
    }

    /**
     * Read a chosen file, and say what was understood BEFORE anything is stored.
     *
     * Nothing here is trusted and nothing here is executed. The text is parsed for addresses and
     * CIDRs and everything else is thrown away — the server parses it again the same way, and the
     * root helper validates every entry a third time in awk before it reaches a firewall ruleset.
     * The checks below are not the security boundary; they exist so that a file that is not what the
     * reader thinks it is says so here rather than after it has been saved.
     */
    function readFile(file, onText) {
        if (file.size > MAX_UPLOAD) {
            showToast(t('js.iplists.file_too_big', { size: fmtSize(file.size) }), 'danger');
            return;
        }
        if (file.size === 0) { showToast(t('js.iplists.file_empty'), 'danger'); return; }
        const rd = new FileReader();
        rd.onload = () => {
            const text = String(rd.result || '');
            // A NUL byte means this is not a list of addresses, whatever its name says. Reading a
            // JPEG as text would otherwise produce a page of mojibake, parse to zero entries, and
            // leave the reader wondering why their upload did nothing.
            if (text.indexOf('\u0000') !== -1) {
                showToast(t('js.iplists.file_binary'), 'danger');
                return;
            }
            onText(text, file);
        };
        rd.onerror = () => showToast(t('js.iplists.file_read_failed'), 'danger');
        rd.readAsText(file);
    }

    const fmtSize = (n) => {
        const u = ['B', 'KiB', 'MiB'];
        let i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
    };

    // The same rules the server applies (ipListParse in includes/iplist.php) — run here only to
    // describe the file, never to decide anything.
    const CIDR4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/;
    function looksLikeCidr(t) {
        if (t.indexOf(':') !== -1) {
            if (!/^[0-9A-Fa-f:]+(?:\/\d{1,3})?$/.test(t)) return false;
            const b = t.indexOf('/') === -1 ? 128 : Number(t.slice(t.indexOf('/') + 1));
            return b >= 0 && b <= 128;
        }
        const m = CIDR4.exec(t);
        if (!m) return false;
        for (let i = 1; i <= 4; i++) if (Number(m[i]) > 255) return false;
        return m[5] === undefined || Number(m[5]) <= 32;
    }

    /** What this text amounts to: entries, IPv4 addresses covered, and what was thrown away. */
    function describe(text) {
        const seen = new Set();
        let bad = 0, addr4 = 0, nets6 = 0;
        const badSamples = [];
        for (let line of text.split(/\r\n|\r|\n/)) {
            line = line.trim();
            if (line === '' || line[0] === '#' || line[0] === ';') continue;
            const t = line.split(/[\s,;#]/)[0].trim();
            if (t === '') continue;
            if (!looksLikeCidr(t)) {
                bad++;
                if (badSamples.length < 3) badSamples.push(line.slice(0, 40));
                continue;
            }
            if (seen.has(t)) continue;
            seen.add(t);
            if (t.indexOf(':') !== -1) { nets6++; continue; }
            const bits = t.indexOf('/') === -1 ? 32 : Number(t.slice(t.indexOf('/') + 1));
            addr4 += Math.pow(2, 32 - bits);
        }
        return { entries: seen.size, addr4, nets6, bad, badSamples };
    }

    function showPreview(text, file) {
        const box = $('ipl-preview');
        if (!box) return;
        box.textContent = '';
        if (text.trim() === '') { box.classList.add('d-none'); return; }
        const d = describe(text);
        box.classList.remove('d-none');
        const head = el('div', null, [
            el('strong', { text: t(d.entries === 1 ? 'js.iplists.entries_one' : 'js.iplists.entries_many', { n: fmt(d.entries) }) }),
            el('span', { text: file ? t('js.iplists.read_from', { name: file.name, size: fmtSize(file.size) }) : t('js.iplists.pasted') }),
        ]);
        box.appendChild(head);
        if (d.addr4 || d.nets6) {
            const parts = [];
            if (d.addr4) parts.push(t('js.iplists.ipv4_addresses', { n: fmt(d.addr4) }));
            if (d.nets6) parts.push(t(d.nets6 === 1 ? 'js.iplists.ipv6_range_one' : 'js.iplists.ipv6_ranges', { n: fmt(d.nets6) }));
            box.appendChild(el('div', { text: t('js.iplists.covering', { parts: parts.join(t('js.iplists.join_and')) }) }));
        }
        if (d.bad) {
            box.appendChild(el('div', { className: 'ipl-preview-bad',
                text: t(d.bad === 1 ? 'js.iplists.bad_lines_one' : 'js.iplists.bad_lines_many', { n: fmt(d.bad), samples: d.badSamples.join(' · ') }) }));
        }
    }

    function replaceEntries(l) {
        const inp = el('input', { type: 'file', accept: '.txt,.zone,.list,.cidr,text/plain', style: 'display:none' });
        inp.addEventListener('change', () => {
            const f = inp.files && inp.files[0];
            if (f) {
                readFile(f, async (t, file) => {
                    const d = describe(t);
                    if (!d.entries) { showToast(t('js.iplists.no_addresses'), 'danger'); return; }
                    const ok = await confirmAction(t('js.iplists.replace_title'),
                        t('js.iplists.replace_body', { count: fmt(d.entries),
                            file: file.name + (d.bad ? t('js.iplists.lines_ignored', { n: fmt(d.bad) }) : ''),
                            name: l.name }),
                        { okLabel: t('js.iplists.replace') });
                    if (ok) act('entries', { id: l.id, text: t });
                });
            }
            inp.remove();
        });
        document.body.appendChild(inp);
        inp.click();
    }

    // ── the add dialog ──────────────────────────────────────────────────────
    function syncAddForm() {
        const url = $('ipl-source').value === 'url';
        $('ipl-url-wrap').classList.toggle('d-none', !url);
        $('ipl-text-wrap').classList.toggle('d-none', url);
        // An allow list has no "when": hard and soft are two ways of refusing, and this one accepts.
        $('ipl-mode-wrap').classList.toggle('invisible', $('ipl-kind').value === 'allow');
    }

    function openAdd() {
        ['ipl-name', 'ipl-url', 'ipl-text'].forEach((id) => { $(id).value = ''; });
        $('ipl-file').value = '';
        const dz = $('ipl-drop');
        if (dz) {
            dz.classList.remove('has-file', 'dragging');
            const main = dz.querySelector('.ipl-drop-main');
            if (main) { main.textContent = t('js.iplists.drop_here'); main.appendChild(el('u', { text: t('js.iplists.choose_file') })); }
        }
        const pv = $('ipl-preview');
        if (pv) { pv.textContent = ''; pv.classList.add('d-none'); }
        $('ipl-kind').value = 'block';
        $('ipl-mode').value = 'hard';
        $('ipl-source').value = 'url';
        $('ipl-ttl').value = card.dataset.ttl || '720';
        $('ipl-error').classList.add('d-none');
        syncAddForm();
        bootstrap.Modal.getOrCreateInstance($('iplAddModal')).show();
    }

    async function saveAdd() {
        const err = $('ipl-error');
        err.classList.add('d-none');
        const body = {
            op: 'create',
            name: $('ipl-name').value.trim(),
            kind: $('ipl-kind').value,
            mode: $('ipl-mode').value,
            source: $('ipl-source').value,
            url: $('ipl-url').value.trim(),
            ttl_minutes: parseInt($('ipl-ttl').value, 10) || 720,
            text: $('ipl-text').value,
        };
        const bad = !body.name ? t('js.iplists.need_name')
            : (body.source === 'url' && !/^https?:\/\//i.test(body.url))
                ? t('js.iplists.need_url')
                : (body.source === 'manual' && body.text.trim() === '')
                    ? t('js.iplists.need_text') : '';
        if (bad) { err.textContent = bad; err.classList.remove('d-none'); return; }

        const save = $('ipl-save');
        save.disabled = true;
        try {
            const r = await apiCall('admin/ip_list_action', 'POST', body);
            if (r.error) { err.textContent = r.error; err.classList.remove('d-none'); return; }
            bootstrap.Modal.getOrCreateInstance($('iplAddModal')).hide();
            showToast(r.message || t('js.iplists.added'), r.note ? 'warning' : 'success');
            await load();
        } finally {
            save.disabled = false;
        }
    }

    // ── pushing to the firewall ─────────────────────────────────────────────
    async function push() {
        const pw = await promptPassword(t('js.iplists.push_title'), t('js.iplists.push_body'));
        if (!pw) return;
        const b = $('btn-ipl-push');
        b.disabled = true;
        try {
            const r = await apiCall('admin/ip_list_action', 'POST', { op: 'push', password: pw, enabled: true });
            if (r.error) { showToast(r.error, 'danger'); return; }
            showToast(r.message || t('js.iplists.loaded'), 'success');
            card.dataset.enabled = '1';
            await load();
        } finally {
            b.disabled = false;
        }
    }

    $('btn-ipl-add').addEventListener('click', openAdd);
    $('btn-ipl-push').addEventListener('click', push);
    $('ipl-save').addEventListener('click', saveAdd);
    $('ipl-source').addEventListener('change', syncAddForm);
    $('ipl-kind').addEventListener('change', syncAddForm);
    $('ipl-file').addEventListener('change', () => {
        const f = $('ipl-file').files && $('ipl-file').files[0];
        if (f) readFile(f, (t, file) => { $('ipl-text').value = t; showPreview(t, file); markDropped(file); });
    });
    // Pasting is the other half of the same control, so it gets the same read-back.
    $('ipl-text').addEventListener('input', () => showPreview($('ipl-text').value, null));

    function markDropped(file) {
        const z = $('ipl-drop');
        if (!z) return;
        z.classList.add('has-file');
        const main = z.querySelector('.ipl-drop-main');
        if (main) main.textContent = file ? file.name : t('js.iplists.file_loaded');
    }

    const drop = $('ipl-drop');
    if (drop) {
        // The whole box is a drop target. dragover must be cancelled or the browser navigates to
        // the file instead, which loses whatever was typed in the dialog.
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            drop.classList.add('dragging');
        }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, () => drop.classList.remove('dragging')));
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) readFile(f, (t, file) => { $('ipl-text').value = t; showPreview(t, file); markDropped(file); });
        });
        // Keyboard: the invisible input is focusable and clickable, but the box carries the visible
        // focus ring, so Enter and Space on the box have to reach it.
        drop.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('ipl-file').click(); }
        });
    }

    load();
})();
