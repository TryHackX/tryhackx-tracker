(function () {
    'use strict';
    if (window.SiteVideo) return;

    function embedUrl(id, start) {
        return 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1&rel=0'
            + (start > 0 ? '&start=' + start : '');
    }

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

    function play(box, id, start) {
        if (box.classList.contains('rt-yt-on')) return;
        box.classList.add('rt-yt-on');
        var frame = el('iframe', {
            className: 'rt-yt-frame', src: embedUrl(id, start), title: t.key('js.video.title'),
            allow: 'autoplay; encrypted-media; picture-in-picture; fullscreen', allowfullscreen: true,
            referrerpolicy: 'strict-origin-when-cross-origin',
        });
        box.replaceChildren(frame);
        frame.focus();
    }

    function upgrade(a) {
        var id = a.getAttribute('data-yt') || '';
        if (a.hasAttribute('data-yt-done') || !/^[A-Za-z0-9_-]{11}$/.test(id)) return;
        a.setAttribute('data-yt-done', '1');
        var start = parseInt(a.getAttribute('data-yt-start') || '0', 10) || 0;
        var thumb = a.getAttribute('data-yt-thumb') === '1';
        var box = el('span', { className: 'rt-yt' }, [
            thumb ? el('img', { className: 'rt-yt-thumb', alt: '', loading: 'lazy', referrerpolicy: 'no-referrer',
                                src: 'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg' }) : null,
            thumb ? null : el('span', { className: 'rt-yt-cap' }, a.textContent.trim()),
            el('button', { type: 'button', className: 'rt-yt-play', title: t.key('js.video.play'), 'aria-label': t.key('js.video.play') },
               el('i', { className: 'bi bi-play-fill', 'aria-hidden': 'true' })),
        ]);
        box.addEventListener('click', function () { play(box, id, start); });
        a.replaceWith(el('span', { className: 'rt-yt-wrap' }, box));
    }

    function upgradeFile(a) {
        var src = a.getAttribute('href') || '';
        if (a.hasAttribute('data-vfile-done') || !/^https:\/\//i.test(src)) return;
        a.setAttribute('data-vfile-done', '1');
        var box = el('span', { className: 'rt-yt rt-vf' }, [
            el('span', { className: 'rt-yt-cap' }, a.textContent.trim()),
            el('button', { type: 'button', className: 'rt-yt-play', title: t.key('js.video.play'), 'aria-label': t.key('js.video.play') },
               el('i', { className: 'bi bi-play-fill', 'aria-hidden': 'true' })),
        ]);
        box.addEventListener('click', function () {
            if (box.classList.contains('rt-yt-on')) return;
            box.classList.add('rt-yt-on');
            var v = el('video', { className: 'rt-yt-frame', src: src, controls: true, autoplay: true, preload: 'metadata', playsinline: true });
            box.replaceChildren(v);
            v.focus();
        });
        a.replaceWith(el('span', { className: 'rt-yt-wrap' }, box));
    }

    function scan(root) {
        if (!root || root.nodeType !== 1 && root.nodeType !== 9) return;
        if (root.nodeType === 1 && root.matches('a.rt-video[data-yt]')) upgrade(root);
        if (root.nodeType === 1 && root.matches('a.rt-vfile[data-vfile]')) upgradeFile(root);
        root.querySelectorAll('a.rt-video[data-yt]').forEach(upgrade);
        root.querySelectorAll('a.rt-vfile[data-vfile]').forEach(upgradeFile);
    }

    window.SiteVideo = { scan: scan };
    scan(document);
    new MutationObserver(function (list) {
        list.forEach(function (m) { m.addedNodes.forEach(scan); });
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
