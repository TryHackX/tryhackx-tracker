(function () {
    'use strict';
    if (window.SiteLightbox) return;

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'className') n.className = v;
            else n.setAttribute(k, v === true ? '' : v);
        });
        (Array.isArray(kids) ? kids : kids ? [kids] : []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(t.child(c));
        });
        return n;
    }

    var lb = null;
    function canReport() {
        return typeof APP_REPORT_IMAGES !== 'undefined' && APP_REPORT_IMAGES === true && !!window.Reports;
    }
    function ownImage(src) {
        try {
            var u = new URL(String(src || ''), location.href);
            if (u.origin !== location.origin) return 0;
            var m = /\/i\/(\d+)-\d+x\d+\.(?:webp|gif)$/.exec(u.pathname);
            return m ? Number(m[1]) : 0;
        } catch (e) { return 0; }
    }
    function build() {
        var img = el('img', { className: 'shout-lb-img', alt: '', referrerpolicy: 'no-referrer' });
        var orig = el('a', { className: 'shout-lb-orig', target: '_blank', rel: 'noopener noreferrer' }, [
            el('i', { className: 'bi bi-box-arrow-up-right', 'aria-hidden': 'true' }),
            ' ', t.key('js.shout.lb_original'),
        ]);
        var shut = el('button', { type: 'button', className: 'shout-lb-close', title: t.key('js.shout.lb_close'),
                                  'aria-label': t.key('js.shout.lb_close') },
                      el('i', { className: 'bi bi-x-lg', 'aria-hidden': 'true' }));
        var prev = el('button', { type: 'button', className: 'shout-lb-prev', title: t.key('js.shout.lb_prev'),
                                  'aria-label': t.key('js.shout.lb_prev') },
                      el('i', { className: 'bi bi-chevron-left', 'aria-hidden': 'true' }));
        var next = el('button', { type: 'button', className: 'shout-lb-next', title: t.key('js.shout.lb_next'),
                                  'aria-label': t.key('js.shout.lb_next') },
                      el('i', { className: 'bi bi-chevron-right', 'aria-hidden': 'true' }));
        var flag = el('button', { type: 'button', className: 'shout-lb-report', hidden: true },
                      el('i', { className: 'bi bi-flag', 'aria-hidden': 'true' }));
        var frame = el('div', { className: 'shout-lb-frame' }, [img, shut, flag, orig, prev, next]);
        var root = el('div', { className: 'shout-lightbox', hidden: true, role: 'dialog', 'aria-modal': 'true',
                               'aria-label': t.key('js.shout.lb_label') }, [frame]);
        var from = null;
        var items = [];
        var at = 0;
        var reported = {};
        function dropBox() {
            var box = frame.querySelector(':scope > .report-box');
            if (box) box.remove();
        }
        function flagState(id) {
            var on = !!reported[id];
            var label = t.key(on ? 'js.report.reported_tip' : 'js.report.tip_image');
            flag.dataset.id = id ? String(id) : '';
            flag.classList.toggle('is-reported', on);
            flag.setAttribute('title', label);
            flag.setAttribute('aria-label', label);
            flag.setAttribute('aria-pressed', on ? 'true' : 'false');
            flag.querySelector('i').className = 'bi ' + (on ? 'bi-flag-fill' : 'bi-flag');
        }
        function show(i) {
            at = (i + items.length) % items.length;
            var it = items[at];
            img.alt = it.alt || '';
            img.src = it.src;
            orig.href = it.href || it.src;
            prev.hidden = next.hidden = items.length < 2;
            dropBox();
            var id = canReport() && it.report !== false ? ownImage(it.src) : 0;
            flag.hidden = !id;
            flagState(id);
        }
        function close() {
            if (root.hidden) return;
            dropBox();
            root.hidden = true;
            img.removeAttribute('src');
            document.removeEventListener('keydown', onKey, true);
            var back = from;
            from = null;
            if (back && back.isConnected) back.focus({ preventScroll: true });
        }
        function onKey(e) {
            if (root.hidden) return;
            var a = document.activeElement;
            if (a && a.closest && a.closest('.report-box')) return;
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
            if (items.length > 1 && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
                e.preventDefault();
                show(at + (e.key === 'ArrowLeft' ? -1 : 1));
                return;
            }
            if (e.key !== 'Tab') return;
            var stops = items.length > 1 ? [prev, orig, next, shut] : [orig, shut];
            if (!flag.hidden) stops.unshift(flag);
            var i = stops.indexOf(document.activeElement);
            e.preventDefault();
            stops[(i + (e.shiftKey ? stops.length - 1 : 1) + stops.length) % stops.length].focus();
        }
        var lbFromBackdrop = false;
        var onBackdrop = function (n) { return n === root || (n.classList && n.classList.contains('shout-lb-frame')); };
        root.addEventListener('pointerdown', function (e) { lbFromBackdrop = onBackdrop(e.target); });
        root.addEventListener('click', function (e) {
            if (onBackdrop(e.target) && lbFromBackdrop) close();
        });
        shut.addEventListener('click', close);
        flag.addEventListener('click', function () {
            var id = Number(flag.dataset.id || 0);
            if (!id || !window.Reports || reported[id] || frame.querySelector(':scope > .report-box')) return;
            window.Reports.open(flag, frame, { kind: 'image', id: id }, function () {
                reported[id] = true;
                if (Number(flag.dataset.id || 0) === id) flagState(id);
            });
        });
        prev.addEventListener('click', function () { show(at - 1); });
        next.addEventListener('click', function () { show(at + 1); });
        document.body.appendChild(root);
        function openList(list, index, origin) {
            if (!list || !list.length) return false;
            items = list;
            from = origin || null;
            show(index || 0);
            root.hidden = false;
            document.addEventListener('keydown', onKey, true);
            shut.focus({ preventScroll: true });
            return true;
        }
        return {
            root: root,
            open: function (link) {
                var href = link.getAttribute('href') || '';
                if (!href) return false;
                var small = link.querySelector('img');
                return openList([{ src: href, href: href, alt: small ? (small.getAttribute('alt') || '') : '' }], 0, link);
            },
            show: openList,
            close: close,
            isOpen: function () { return !root.hidden; },
        };
    }

    window.SiteLightbox = {
        get: function () { return lb || (lb = build()); },
        open: function (link) { return this.get().open(link); },
        show: function (list, index, from) { return this.get().show(list, index, from); },
        close: function () { if (lb) lb.close(); },
        isOpen: function () { return !!lb && lb.isOpen(); },
    };
})();
