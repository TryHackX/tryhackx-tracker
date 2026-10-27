(function () {
    'use strict';
    if (window.MagnetCards) return;

    var me = document.currentScript;
    var base = me && me.src ? me.src.replace(/assets\/js\/magnetcard\.js[^/]*$/, '') : '';
    var known = new Map();
    var pending = new Set();
    var timer = 0;

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
                hs.forEach(function (h) { known.set(h, items[h] || null); });
                document.querySelectorAll('.rt-magnet[data-mh]').forEach(function (card) {
                    var n = known.get(card.getAttribute('data-mh'));
                    if (n) fill(card, n);
                });
            })
            .catch(function () {});
    }

    function take(card) {
        var h = card.getAttribute('data-mh') || '';
        if (card.hasAttribute('data-mh-done') || !/^[0-9a-f]{40}$/.test(h)) return;
        card.setAttribute('data-mh-done', '1');
        if (known.has(h)) {
            var n = known.get(h);
            if (n) fill(card, n);
            return;
        }
        pending.add(h);
        if (!timer) timer = setTimeout(flush, 120);
    }

    function scan(root) {
        if (!root || root.nodeType !== 1 && root.nodeType !== 9) return;
        if (root.nodeType === 1 && root.matches('.rt-magnet[data-mh]')) take(root);
        root.querySelectorAll('.rt-magnet[data-mh]').forEach(take);
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
        if (!navigator.clipboard || !window.isSecureContext) { reveal(btn, uri); return; }
        navigator.clipboard.writeText(uri).then(function () { flash(btn, true); }, function () { reveal(btn, uri); });
    }

    function info(btn) {
        var card = btn.closest('.rt-magnet');
        var h = card ? card.getAttribute('data-mh') || '' : '';
        if (!/^[0-9a-f]{40}$/.test(h)) return;
        if (window.TorrentInfo && typeof window.TorrentInfo.open === 'function') { window.TorrentInfo.open(h, null); return; }
        window.open(base + '?action=search&hash=' + h, '_blank', 'noopener');
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('.rt-magnet-copy, .rt-magnet-info') : null;
        if (!btn) return;
        ev.preventDefault();
        if (btn.classList.contains('rt-magnet-copy')) copy(btn); else info(btn);
    });

    window.MagnetCards = { scan: scan };
    scan(document);
    new MutationObserver(function (list) {
        list.forEach(function (m) { m.addedNodes.forEach(scan); });
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
