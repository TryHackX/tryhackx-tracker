(function () {
    'use strict';
    if (window.MagnetCards) return;

    var me = document.currentScript;
    var base = me && me.src ? me.src.replace(/assets\/js\/magnetcard\.js[^/]*$/, '') : '';
    var known = new Map();
    var pending = new Set();
    var timer = 0;
    var refreshOn = false;

    function csrf() {
        if (typeof window.csrfToken === 'function') return window.csrfToken();
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m) return m.getAttribute('content') || '';
        return document.body ? document.body.getAttribute('data-csrf') || '' : '';
    }

    function post(endpoint, body) {
        return fetch(base + 'api.php?endpoint=' + endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body),
        }).then(function (r) { return r.json().catch(function () { return null; }); }).catch(function () { return null; });
    }

    function icon(cls) {
        var i = document.createElement('i');
        i.className = 'bi ' + cls;
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function stat(cls, v, title) {
        var s = document.createElement('span');
        s.className = 'rt-magnet-stat';
        s.setAttribute('title', title);
        s.append(icon(cls), ' ', t.num(v));
        return s;
    }

    function tip(btn, text) {
        var old = btn.getAttribute('title');
        btn.setAttribute('title', text);
        setTimeout(function () { if (old !== null) btn.setAttribute('title', old); }, 2500);
    }

    function cardsOf(h) {
        return document.querySelectorAll('.rt-magnet[data-mh="' + h + '"]');
    }

    function fillClicks(card, k) {
        var el = card.querySelector('.rt-magnet-clicks');
        if (!el || !k) return;
        el.replaceChildren(icon('bi-cursor'), ' ', t.num(k));
        el.setAttribute('title', t.key('js.magnet.clicks'));
        el.hidden = false;
    }

    function fill(card, n) {
        var sl = card.querySelector('.rt-magnet-sl');
        if (sl) {
            sl.replaceChildren(stat('bi-arrow-up', n.s, t.key('js.magnet.seeders')),
                               stat('bi-arrow-down', n.l, t.key('js.magnet.leechers')),
                               stat('bi-check2', n.c, t.key('js.magnet.completed')));
            sl.hidden = false;
        }
        var info = card.querySelector('.rt-magnet-info');
        if (info) info.hidden = false;
        var acts = card.querySelector('.rt-magnet-acts');
        if (refreshOn && acts && !acts.querySelector('.rt-magnet-refresh')) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'rt-magnet-refresh';
            b.setAttribute('title', t.key('js.magnet.refresh'));
            b.setAttribute('aria-label', t.key('js.magnet.refresh'));
            b.append(icon('bi-arrow-repeat'));
            acts.insertBefore(b, info || null);
        }
    }

    function show(card, h) {
        var got = known.get(h);
        if (!got) return;
        if (got.n) fill(card, got.n);
        if (got.k) fillClicks(card, got.k);
    }

    function flush() {
        timer = 0;
        var hs = Array.from(pending).slice(0, 50);
        hs.forEach(function (h) { pending.delete(h); });
        if (pending.size) timer = setTimeout(flush, 60);
        if (!hs.length) return;
        fetch(base + 'api.php?endpoint=magnet_stats&h=' + hs.join(','), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                var items = j && j.items ? j.items : {};
                var clicks = j && j.clicks ? j.clicks : {};
                refreshOn = !!(j && j.refresh);
                hs.forEach(function (h) { known.set(h, { n: items[h] || null, k: Number(clicks[h]) || 0 }); });
                hs.forEach(function (h) { cardsOf(h).forEach(function (card) { show(card, h); }); });
            })
            .catch(function () {});
    }

    function take(card) {
        var h = card.getAttribute('data-mh') || '';
        if (card.hasAttribute('data-mh-done') || !/^[0-9a-f]{40}$/.test(h)) return;
        card.setAttribute('data-mh-done', '1');
        if (known.has(h)) { show(card, h); return; }
        pending.add(h);
        if (!timer) timer = setTimeout(flush, 120);
    }

    function scan(root) {
        if (!root || root.nodeType !== 1 && root.nodeType !== 9) return;
        if (root.nodeType === 1 && root.matches('.rt-magnet[data-mh]')) take(root);
        root.querySelectorAll('.rt-magnet[data-mh]').forEach(take);
    }

    function hashOf(el) {
        var h = el.getAttribute('data-h') || '';
        if (!h) {
            var card = el.closest('.rt-magnet');
            h = card ? card.getAttribute('data-mh') || '' : '';
        }
        return /^[0-9a-f]{40}$/.test(h) ? h : '';
    }

    function count(el) {
        var h = hashOf(el);
        if (!h) return;
        post('magnet_click', { h: h, csrf_token: csrf() }).then(function (j) {
            if (!j || !j.success) return;
            var got = known.get(h) || { n: null, k: 0 };
            got.k = Number(j.clicks) || got.k;
            known.set(h, got);
            cardsOf(h).forEach(function (card) { fillClicks(card, got.k); });
        });
    }

    function flash(btn, ok) {
        var i = btn.querySelector('i');
        var title = btn.getAttribute('title');
        if (i) i.className = 'bi ' + (ok ? 'bi-clipboard-check' : 'bi-x-lg');
        btn.classList.add(ok ? 'copied' : 'failed');
        btn.setAttribute('title', t.key(ok ? 'js.magnet.copied' : 'js.magnet.copy_failed'));
        setTimeout(function () {
            if (i) i.className = 'bi bi-clipboard';
            btn.classList.remove('copied', 'failed');
            if (title !== null) btn.setAttribute('title', title);
        }, 1500);
    }

    function reveal(btn, uri) {
        var card = btn.closest('.rt-magnet');
        var box = card && card.querySelector('.rt-magnet-url');
        if (!box) {
            box = document.createElement('input');
            box.type = 'text';
            box.readOnly = true;
            box.className = 'rt-magnet-url';
            box.setAttribute('aria-label', t.key('js.magnet.copy_box'));
            (card || btn.parentNode).append(box);
        }
        box.value = uri;
        box.hidden = false;
        box.focus();
        box.select();
    }

    function copy(btn) {
        var uri = btn.getAttribute('data-magnet') || '';
        if (!/^magnet:\?/i.test(uri)) return;
        count(btn);
        if (!navigator.clipboard || !window.isSecureContext) { reveal(btn, uri); return; }
        navigator.clipboard.writeText(uri).then(function () { flash(btn, true); }, function () { reveal(btn, uri); });
    }

    function info(btn) {
        var h = hashOf(btn);
        if (!h) return;
        if (window.TorrentInfo && typeof window.TorrentInfo.open === 'function') { window.TorrentInfo.open(h, null); return; }
        window.open(base + '?action=search&hash=' + h, '_blank', 'noopener');
    }

    function refresh(btn) {
        var h = hashOf(btn);
        if (!h || btn.classList.contains('is-spinning')) return;
        btn.classList.add('is-spinning');
        post('index_info&hash=' + h, { op: 'refresh', csrf_token: csrf() }).then(function (j) {
            btn.classList.remove('is-spinning');
            if (!j || !j.success) { tip(btn, (j && (j.error || j.message)) || t.key('js.magnet.copy_failed')); return; }
            var n = { s: Number(j.seeders) || 0, l: Number(j.leechers) || 0, c: Number(j.completed) || 0 };
            var got = known.get(h) || { n: null, k: 0 };
            got.n = n;
            known.set(h, got);
            cardsOf(h).forEach(function (card) { fill(card, n); });
            tip(btn, j.message || t.key('js.magnet.refreshed'));
        });
    }

    document.addEventListener('click', function (ev) {
        var el = ev.target && ev.target.closest ? ev.target.closest('.rt-magnet-copy, .rt-magnet-info, .rt-magnet-refresh, a.rt-magnet-name, a.rt-magnet-open') : null;
        if (!el || !el.closest('.rt-magnet')) return;
        if (el.tagName === 'A') { count(el); return; }
        ev.preventDefault();
        if (el.classList.contains('rt-magnet-copy')) copy(el);
        else if (el.classList.contains('rt-magnet-refresh')) refresh(el);
        else info(el);
    });

    window.MagnetCards = { scan: scan };
    scan(document);
    new MutationObserver(function (list) {
        list.forEach(function (m) { m.addedNodes.forEach(scan); });
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
