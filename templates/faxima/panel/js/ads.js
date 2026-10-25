(function () {
    'use strict';

    var LABEL_SPONSORED = 'حمایت‌شده';
    var LABEL_CLOSE = 'بستن';
    var LABEL_CLOSE_AD = 'بستن تبلیغ';
    var LABEL_OPEN = 'مشاهده';
    var SVG_CLOSE = '<svg class="svg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text) n.textContent = text;
        return n;
    }

    function safeUrl(value) {
        if (typeof value !== 'string' || value.length > 2048) return '';
        try {
            var u = new URL(value);
            return u.protocol === 'https:' && !u.username && !u.password ? u.href : '';
        } catch (e) {
            return '';
        }
    }

    function closeButton(cls, label) {
        var b = el('button', cls);
        b.type = 'button';
        b.setAttribute('aria-label', label);
        b.innerHTML = SVG_CLOSE;
        return b;
    }

    function sponsorRow(ad, cls) {
        var row = el('div', cls);
        var avatarUrl = safeUrl(ad.sponsor && ad.sponsor.avatar_url);
        var name = (ad.sponsor && ad.sponsor.name) || '';
        var avatar = el('span', 'fx-ad__avatar');
        var initial = el('span', 'fx-ad__avatar-initial', name ? Array.from(name)[0] : '');
        avatar.appendChild(initial);
        if (avatarUrl) {
            var img = new Image();
            img.alt = '';
            img.decoding = 'async';
            img.referrerPolicy = 'no-referrer';
            img.addEventListener('load', function () { avatar.classList.add('has-img'); });
            img.addEventListener('error', function () { img.remove(); });
            img.src = avatarUrl;
            avatar.appendChild(img);
        }
        var meta = el('span', 'fx-ad__meta');
        meta.appendChild(el('span', 'fx-ad__name', name));
        meta.appendChild(el('span', 'fx-ad__badge', LABEL_SPONSORED));
        row.appendChild(avatar);
        row.appendChild(meta);
        return row;
    }

    function imageNode(url, cls) {
        var wrap = el('div', cls);
        wrap.hidden = true;
        var img = new Image();
        img.alt = '';
        img.decoding = 'async';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('load', function () { wrap.hidden = false; });
        img.addEventListener('error', function () { wrap.remove(); });
        img.src = url;
        wrap.appendChild(img);
        return wrap;
    }

    function request(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.cache = 'no-store';
        return fetch(url, opts).then(function (r) { return r.json(); });
    }

    function initBanner(host) {
        var endpoint = host.getAttribute('data-endpoint');
        var nonce = host.getAttribute('data-nonce');
        if (!endpoint || !nonce) return;
        request(endpoint + '?action=banner&t=' + encodeURIComponent(nonce)).then(function (res) {
            if (!res || !res.ok || !res.ad) return;
            var ad = res.ad;
            var href = ad.link && safeUrl(ad.link.url);
            if (!href || !ad.headline) return;

            var card = el('div', 'fx-ad-banner');
            card.setAttribute('role', 'complementary');

            var close = closeButton('fx-ad__close', LABEL_CLOSE);
            close.addEventListener('click', function () { host.hidden = true; host.textContent = ''; });

            var link = el('a', 'fx-ad-banner__link');
            link.href = href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer sponsored';
            link.appendChild(sponsorRow(ad, 'fx-ad__sponsor'));
            var mediaUrl = ad.media && ad.media.type === 'image' ? safeUrl(ad.media.url) : '';
            if (mediaUrl) link.appendChild(imageNode(mediaUrl, 'fx-ad-banner__media'));
            link.appendChild(el('strong', 'fx-ad__headline', ad.headline));
            if (ad.description) link.appendChild(el('p', 'fx-ad__desc', ad.description));

            card.appendChild(close);
            card.appendChild(link);
            host.textContent = '';
            host.appendChild(card);
            host.hidden = false;
        }).catch(function () {});
    }

    function dialogOpen() {
        return !!document.querySelector('.modal-overlay.active:not(#fxAdModal), .kb-modal.open, .lb-modal.active, dialog[open], [role="dialog"][aria-modal="true"]:not(#fxAdModal)');
    }

    function initModal(host) {
        var endpoint = host.getAttribute('data-endpoint');
        if (!endpoint || window.__fxAdModalState) return;
        window.__fxAdModalState = 'loading';

        request(endpoint + '?action=modal').then(function (res) {
            if (!res || !res.ok || !res.ad || !res.ad.headline) {
                window.__fxAdModalState = 'none';
                return;
            }
            var tries = 0;
            (function attempt() {
                if (dialogOpen() && tries++ < 40) {
                    setTimeout(attempt, 1500);
                    return;
                }
                if (dialogOpen()) {
                    window.__fxAdModalState = 'none';
                    return;
                }
                showModal(host, endpoint, res.ad, res.csrf || '');
            })();
        }).catch(function () { window.__fxAdModalState = 'none'; });
    }

    function showModal(host, endpoint, ad, csrf) {
        if (document.getElementById('fxAdModal')) return;
        window.__fxAdModalState = 'open';

        var overlay = el('div', 'modal-overlay fx-ad-modal');
        overlay.id = 'fxAdModal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', ad.headline);

        var box = el('div', 'modal-box fx-ad-modal__box');
        var head = el('div', 'fx-ad-modal__head');
        head.appendChild(sponsorRow(ad, 'fx-ad__sponsor'));
        var xBtn = closeButton('fx-ad__close fx-ad__close--modal', LABEL_CLOSE);
        head.appendChild(xBtn);
        box.appendChild(head);

        var body = el('div', 'fx-ad-modal__body');
        var video = null;
        var m = ad.media;
        if (m && m.type === 'image' && safeUrl(m.url)) {
            body.appendChild(imageNode(safeUrl(m.url), 'fx-ad-modal__media'));
        } else if (m && m.type === 'video' && safeUrl(m.url)) {
            var vwrap = el('div', 'fx-ad-modal__media fx-ad-modal__media--video');
            video = document.createElement('video');
            video.controls = true;
            video.autoplay = false;
            video.preload = 'metadata';
            video.setAttribute('playsinline', '');
            video.setAttribute('controlsList', 'nodownload');
            video.setAttribute('referrerpolicy', 'no-referrer');
            var poster = safeUrl(m.poster_url);
            if (poster) video.poster = poster;
            video.addEventListener('error', function () { vwrap.remove(); });
            video.src = safeUrl(m.url);
            vwrap.appendChild(video);
            body.appendChild(vwrap);
        }
        body.appendChild(el('h3', 'fx-ad__headline fx-ad__headline--modal', ad.headline));
        if (ad.description) body.appendChild(el('p', 'fx-ad__desc fx-ad__desc--modal', ad.description));
        var href = ad.link && safeUrl(ad.link.url);
        if (href) {
            var cta = el('a', 'btn btn-primary fx-ad-modal__cta', (ad.link.label || LABEL_OPEN));
            cta.href = href;
            cta.target = '_blank';
            cta.rel = 'noopener noreferrer sponsored';
            body.appendChild(cta);
        }
        box.appendChild(body);

        var foot = el('div', 'fx-ad-modal__foot');
        var closeAd = el('button', 'btn btn-outline fx-ad-modal__closebtn', LABEL_CLOSE_AD);
        closeAd.type = 'button';
        foot.appendChild(closeAd);
        box.appendChild(foot);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        var closed = false;
        function dismiss() {
            if (closed) return;
            closed = true;
            document.removeEventListener('keydown', onKey, true);
            if (video) {
                try { video.pause(); } catch (e) {}
                video.removeAttribute('src');
                video.removeAttribute('poster');
                try { video.load(); } catch (e) {}
            }
            overlay.classList.remove('active');
            overlay.remove();
            window.__fxAdModalState = 'closed';
            send(0);
        }
        function send(n) {
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                keepalive: true,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: 'action=dismiss'
            }).then(function (r) {
                if (!r.ok && n < 2) setTimeout(function () { send(n + 1); }, 1200);
            }).catch(function () {
                if (n < 2) setTimeout(function () { send(n + 1); }, 1200);
            });
        }
        function onKey(ev) {
            if (ev.key === 'Escape') { ev.stopPropagation(); dismiss(); }
        }
        xBtn.addEventListener('click', dismiss);
        closeAd.addEventListener('click', dismiss);
        document.addEventListener('keydown', onKey, true);

        requestAnimationFrame(function () {
            overlay.classList.add('active');
            closeAd.focus({ preventScroll: true });
        });
    }

    function boot() {
        var banner = document.getElementById('fxAdBanner');
        if (banner) initBanner(banner);
        var modal = document.getElementById('fxAdModalHost');
        if (modal) setTimeout(function () { initModal(modal); }, 400);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
