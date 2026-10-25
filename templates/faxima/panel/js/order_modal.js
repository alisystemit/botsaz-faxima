(function () {
    var ICONS = {
        grip: '<circle cx="9" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="15" cy="18" r="1.4"/>',
        up: '<polyline points="18 15 12 9 6 15"/>',
        down: '<polyline points="6 9 12 15 18 9"/>',
        check: '<circle cx="12" cy="12" r="10"/><polyline points="9 12 12 15 16 10"/>',
        alert: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'
    };
    var SCROLL_ZONE = 44;
    var SCROLL_MAX = 18;
    var DRAG_THRESHOLD = 4;
    var PAGE_SIZE = 5;
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function fa(n) {
        return String(n).replace(/\d/g, function (d) { return FA_DIGITS[+d]; });
    }

    function svg(name) {
        var el = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        el.setAttribute('viewBox', '0 0 24 24');
        el.setAttribute('fill', name === 'grip' ? 'currentColor' : 'none');
        el.setAttribute('stroke', name === 'grip' ? 'none' : 'currentColor');
        el.setAttribute('stroke-width', '2');
        el.setAttribute('stroke-linecap', 'round');
        el.setAttribute('stroke-linejoin', 'round');
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = ICONS[name];
        return el;
    }

    function make(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text !== undefined) el.textContent = text;
        return el;
    }

    function init(root) {
        var entity = root.getAttribute('data-entity');
        var endpoint = root.getAttribute('data-endpoint');
        var csrf = root.getAttribute('data-csrf');
        var emptyText = root.getAttribute('data-empty') || '';
        var box = root.querySelector('.ord-modal');
        var listEl = root.querySelector('[data-order-list]');
        var pagerEl = root.querySelector('[data-order-pager]');
        var stateEl = root.querySelector('[data-order-state]');
        var statusEl = root.querySelector('[data-order-status]');
        var live = make('span', 'ord-sr');
        live.setAttribute('role', 'status');
        live.setAttribute('aria-live', 'polite');
        box.appendChild(live);
        box.tabIndex = -1;

        var items = [];
        var version = '';
        var loadToken = 0;
        var saving = false;
        var dirty = false;
        var changed = false;
        var failed = false;
        var warned = false;
        var wasOpen = false;
        var saveTimer = null;
        var returnFocus = null;
        var drag = null;
        var currentPage = 1;
        var suppressOpen = false;

        function isOpen() { return root.classList.contains('active'); }

        function announce(msg) {
            live.textContent = '';
            setTimeout(function () { live.textContent = msg; }, 30);
        }

        function setState(kind, message, retry) {
            stateEl.className = 'ord-state' + (kind === 'error' ? ' is-error' : '');
            stateEl.textContent = '';
            if (!kind) {
                stateEl.hidden = true;
                return;
            }
            stateEl.hidden = false;
            if (kind === 'loading') stateEl.appendChild(make('span', 'ord-spinner'));
            stateEl.appendChild(make('span', '', message));
            if (retry) {
                var b = make('button', 'btn btn-soft-purple btn-sm', 'تلاش دوباره');
                b.type = 'button';
                b.addEventListener('click', retry);
                stateEl.appendChild(b);
            }
        }

        function setStatus(kind, message, noRetry) {
            statusEl.className = 'ord-status' + (kind ? ' is-' + kind : '');
            statusEl.textContent = '';
            if (!kind) return;
            if (kind === 'saving') statusEl.appendChild(make('span', 'ord-spinner'));
            else if (kind === 'saved') statusEl.appendChild(svg('check'));
            else if (kind === 'error') statusEl.appendChild(svg('alert'));
            statusEl.appendChild(make('span', '', message));
            if (kind === 'error' && !noRetry) {
                var r = make('button', 'ord-retry', 'تلاش دوباره');
                r.type = 'button';
                r.addEventListener('click', function () { dirty = true; failed = false; flush(); });
                statusEl.appendChild(r);
            }
        }

        function request(method, body) {
            var opts = {
                method: method,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
            };
            var url = endpoint;
            if (method === 'GET') {
                url += '?entity=' + encodeURIComponent(entity) + '&_=' + Date.now();
            } else {
                opts.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
                opts.body = body;
            }
            return fetch(url, opts).then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) { data = null; }
                    var valid = data !== null && typeof data === 'object' && !Array.isArray(data);
                    return { status: res.status, body: valid ? data : {}, malformed: !valid };
                });
            });
        }

        function errorText(r, fallback) {
            if (r.status === 401) return 'نشست شما منقضی شده است. دوباره وارد شوید.';
            if (r.status === 403) return 'توکن امنیتی نامعتبر است. صفحه را دوباره بارگذاری کنید.';
            if (r.malformed) return 'پاسخ نامعتبر از سرور دریافت شد (کد ' + r.status + ').';
            if (r.status >= 500) return 'خطای سرور در بارگذاری فهرست (کد ' + r.status + ').';
            if (typeof r.body.message === 'string' && r.body.message) return r.body.message;
            return fallback + ' (کد ' + r.status + ').';
        }

        function load() {
            var token = ++loadToken;
            items = [];
            listEl.textContent = '';
            setStatus('', '');
            setState('loading', 'در حال بارگذاری…');
            request('GET').then(function (r) {
                if (token !== loadToken) return;
                if (r.status === 200 && r.body.ok && Array.isArray(r.body.items)) {
                    items = r.body.items;
                    version = r.body.version;
                    render();
                    if (box && isOpen()) box.focus({ preventScroll: true });
                } else {
                    setState('error', errorText(r, 'بارگذاری فهرست ناموفق بود'), load);
                }
            }).catch(function () {
                if (token !== loadToken) return;
                setState('error', 'ارتباط با سرور برقرار نشد.', load);
            });
        }

        function pageCount() {
            return Math.max(1, Math.ceil(items.length / PAGE_SIZE));
        }

        function pageStart() {
            return (currentPage - 1) * PAGE_SIZE;
        }

        function pageOf(index) {
            return Math.floor(index / PAGE_SIZE) + 1;
        }

        function render() {
            listEl.textContent = '';
            if (currentPage > pageCount()) currentPage = pageCount();
            if (currentPage < 1) currentPage = 1;
            if (!items.length) {
                setState('empty', emptyText);
                renderPager();
                return;
            }
            setState('', '');
            var start = pageStart();
            items.slice(start, start + PAGE_SIZE).forEach(function (item) { listEl.appendChild(buildItem(item)); });
            refresh();
            renderPager();
            listEl.scrollTop = 0;
        }

        function goToPage(page) {
            if (drag) return;
            var target = Math.min(Math.max(1, page), pageCount());
            if (target === currentPage) return;
            currentPage = target;
            render();
            announce('صفحه ' + fa(currentPage) + ' از ' + fa(pageCount()));
        }

        function pageButton(label, page, cls, disabled, aria) {
            var b = make('button', 'mdt-page' + (cls ? ' ' + cls : '') + (disabled ? ' disabled' : ''), label);
            b.type = 'button';
            if (aria) b.setAttribute('aria-label', aria);
            if (disabled) {
                b.disabled = true;
            } else {
                b.addEventListener('click', function () { goToPage(page); });
            }
            return b;
        }

        function makeZone(kind, label, active) {
            var z = make('span', 'ord-zone' + (active ? '' : ' is-off'), label);
            z.setAttribute('data-zone', kind);
            return z;
        }

        function renderPager() {
            var pages = pageCount();
            pagerEl.textContent = '';
            if (items.length <= PAGE_SIZE) {
                pagerEl.hidden = true;
                return;
            }
            pagerEl.hidden = false;
            var start = pageStart();
            var end = Math.min(start + PAGE_SIZE, items.length);
            pagerEl.appendChild(make('div', 'mdt-info ord-pager-info', 'نمایش ' + fa(start + 1) + ' تا ' + fa(end) + ' از ' + fa(items.length) + ' ردیف'));
            var nav = make('div', 'mdt-pager ord-pager-nav');
            nav.setAttribute('role', 'navigation');
            nav.setAttribute('aria-label', 'صفحه‌بندی');
            nav.appendChild(pageButton('قبلی', currentPage - 1, '', currentPage <= 1, 'صفحه قبل'));
            var from = Math.max(1, currentPage - 1);
            var to = Math.min(pages, from + 2);
            from = Math.max(1, to - 2);
            for (var p = from; p <= to; p++) {
                var cur = p === currentPage;
                var pb = pageButton(fa(p), p, cur ? 'active' : '', false, 'صفحه ' + fa(p));
                if (cur) pb.setAttribute('aria-current', 'page');
                nav.appendChild(pb);
            }
            nav.appendChild(pageButton('بعدی', currentPage + 1, '', currentPage >= pages, 'صفحه بعد'));
            pagerEl.appendChild(nav);
            var zones = make('div', 'ord-zones');
            zones.appendChild(makeZone('next', 'انتقال به ابتدای صفحه ' + fa(Math.min(pages, currentPage + 1)), currentPage < pages));
            zones.appendChild(makeZone('prev', 'انتقال به انتهای صفحه ' + fa(Math.max(1, currentPage - 1)), currentPage > 1));
            pagerEl.appendChild(zones);
        }

        function buildItem(item) {
            var li = make('li', 'ord-item');
            li.setAttribute('data-id', String(item.id));

            var handle = make('span', 'ord-handle');
            handle.setAttribute('role', 'button');
            handle.tabIndex = 0;
            handle.setAttribute('aria-label', 'جابجایی «' + item.name + '». برای کشیدن، نگه دارید؛ با کلیدهای بالا و پایین هم می‌توانید جابجا کنید.');
            handle.title = 'بکشید';
            handle.appendChild(svg('grip'));
            handle.addEventListener('pointerdown', function (ev) { startDrag(ev, li); });
            handle.addEventListener('keydown', function (ev) { handleKey(ev, li); });
            li.appendChild(handle);

            li.appendChild(make('span', 'ord-num'));

            var text = make('span', 'ord-text');
            var name = make('span', 'ord-name', item.name);
            name.setAttribute('dir', 'auto');
            name.title = item.name;
            text.appendChild(name);
            var meta = make('span', 'ord-meta');
            var idChip = make('span', 'ord-chip ord-chip--id', 'شناسه ' + item.id);
            meta.appendChild(idChip);
            if (item.inactive) meta.appendChild(make('span', 'ord-chip ord-chip--warn', 'غیرفعال'));
            (item.badges || []).forEach(function (b) {
                var chip = make('span', 'ord-chip', b);
                chip.setAttribute('dir', 'auto');
                chip.title = b;
                meta.appendChild(chip);
            });
            text.appendChild(meta);
            li.appendChild(text);

            var moves = make('span', 'ord-moves');
            [['up', -1, 'انتقال به بالا'], ['down', 1, 'انتقال به پایین']].forEach(function (d) {
                var btn = make('button', 'ord-move');
                btn.type = 'button';
                btn.setAttribute('data-dir', String(d[1]));
                btn.setAttribute('aria-label', d[2] + ': ' + item.name);
                btn.title = d[2];
                btn.appendChild(svg(d[0]));
                btn.addEventListener('click', function () {
                    var idx = pageStart() + indexOfNode(li);
                    moveTo(idx, idx + d[1], btn);
                });
                moves.appendChild(btn);
            });
            li.appendChild(moves);
            return li;
        }

        function indexOfNode(li) {
            return Array.prototype.indexOf.call(listEl.children, li);
        }

        function refresh() {
            var nodes = listEl.children;
            var start = pageStart();
            for (var i = 0; i < nodes.length; i++) {
                nodes[i].querySelector('.ord-num').textContent = String(start + i + 1);
                nodes[i].querySelector('[data-dir="-1"]').disabled = start + i === 0;
                nodes[i].querySelector('[data-dir="1"]').disabled = start + i === items.length - 1;
            }
        }

        function focusControl(node, kind) {
            if (!node) return;
            var target = kind === 'handle' ? node.querySelector('.ord-handle') : node.querySelector('[data-dir="' + kind + '"]');
            if (!target || target.disabled) target = node.querySelector('.ord-move:not(:disabled)') || node.querySelector('.ord-handle');
            if (target) target.focus({ preventScroll: true });
        }

        function nodeById(id) {
            return listEl.querySelector('[data-id="' + id + '"]');
        }

        function moveTo(from, to, focusEl) {
            if (from < 0 || to < 0 || from >= items.length || to >= items.length || from === to) return false;
            var start = pageStart();
            var moved = items[from];
            var kind = focusEl ? (focusEl.classList.contains('ord-handle') ? 'handle' : focusEl.getAttribute('data-dir')) : null;
            var inPage = from >= start && from < start + PAGE_SIZE && to >= start && to < start + PAGE_SIZE;
            items.splice(from, 1);
            items.splice(to, 0, moved);
            var node;
            if (inPage) {
                var nodes = listEl.children;
                var lf = from - start;
                var lt = to - start;
                node = nodes[lf];
                var ref = lt > lf ? nodes[lt].nextSibling : nodes[lt];
                listEl.insertBefore(node, ref);
                refresh();
                if (focusEl) {
                    if (focusEl.disabled) focusControl(node, kind);
                    else focusEl.focus({ preventScroll: true });
                }
            } else {
                currentPage = pageOf(to);
                render();
                node = nodeById(moved.id);
                if (kind) focusControl(node, kind);
            }
            if (node) {
                node.classList.add('is-moved');
                setTimeout(function () { node.classList.remove('is-moved'); }, 700);
                if (node.scrollIntoView) node.scrollIntoView({ block: 'nearest' });
            }
            announce(moved.name + ' به جایگاه ' + (to + 1) + ' از ' + items.length + ' منتقل شد');
            scheduleSave();
            return true;
        }

        function handleKey(ev, li) {
            var idx = pageStart() + indexOfNode(li);
            var handle = ev.currentTarget;
            var to = null;
            if (ev.key === 'ArrowUp') to = idx - 1;
            else if (ev.key === 'ArrowDown') to = idx + 1;
            else if (ev.key === 'Home') to = 0;
            else if (ev.key === 'End') to = items.length - 1;
            if (to === null) return;
            ev.preventDefault();
            moveTo(idx, to, handle);
        }

        function slotAt(y) {
            var nodes = listEl.children;
            for (var i = 0; i < nodes.length; i++) {
                var r = nodes[i].getBoundingClientRect();
                if (y < r.top + r.height / 2) return i;
            }
            return nodes.length;
        }

        function clearIndicators() {
            var nodes = listEl.children;
            for (var i = 0; i < nodes.length; i++) nodes[i].classList.remove('drop-before', 'drop-after');
        }

        function startDrag(ev, li) {
            if (drag || (ev.pointerType === 'mouse' && ev.button !== 0)) return;
            ev.preventDefault();
            var handle = ev.currentTarget;
            var rect = li.getBoundingClientRect();
            drag = {
                li: li,
                handle: handle,
                pointerId: ev.pointerId,
                startX: ev.clientX,
                startY: ev.clientY,
                x: ev.clientX,
                y: ev.clientY,
                offsetY: ev.clientY - rect.top,
                from: indexOfNode(li),
                slot: -1,
                zone: null,
                active: false,
                ghost: null,
                raf: 0
            };
            try { handle.setPointerCapture(ev.pointerId); } catch (e) {}
            handle.addEventListener('pointermove', onDragMove);
            handle.addEventListener('pointerup', onDragEnd);
            handle.addEventListener('pointercancel', onDragCancel);
            handle.addEventListener('lostpointercapture', onDragCancel);
        }

        function beginDrag() {
            var d = drag;
            d.active = true;
            var rect = d.li.getBoundingClientRect();
            d.ghost = d.li.cloneNode(true);
            d.ghost.classList.add('ord-ghost');
            d.ghost.setAttribute('aria-hidden', 'true');
            d.ghost.style.width = rect.width + 'px';
            d.ghost.style.left = rect.left + 'px';
            d.ghost.style.top = rect.top + 'px';
            root.appendChild(d.ghost);
            d.li.classList.add('is-dragging');
            document.body.classList.add('ord-dragging');
            pagerEl.classList.add('is-drag');
            if (navigator.vibrate) { try { navigator.vibrate(10); } catch (e) {} }
            d.raf = requestAnimationFrame(tick);
        }

        function zoneAt(x, y) {
            if (pagerEl.hidden) return null;
            var zs = pagerEl.querySelectorAll('.ord-zone:not(.is-off)');
            for (var i = 0; i < zs.length; i++) {
                var r = zs[i].getBoundingClientRect();
                if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return zs[i].getAttribute('data-zone');
            }
            return null;
        }

        function markZone(kind) {
            var zs = pagerEl.querySelectorAll('.ord-zone');
            for (var i = 0; i < zs.length; i++) zs[i].classList.toggle('is-hot', zs[i].getAttribute('data-zone') === kind);
        }

        function updateDrag() {
            var d = drag;
            if (!d || !d.active) return;
            d.ghost.style.top = (d.y - d.offsetY) + 'px';
            clearIndicators();
            d.zone = zoneAt(d.x, d.y);
            markZone(d.zone);
            if (d.zone) {
                d.slot = -1;
                return;
            }
            var slot = slotAt(d.y);
            var nodes = listEl.children;
            d.slot = slot;
            if (slot === d.from || slot === d.from + 1) return;
            if (slot < nodes.length) nodes[slot].classList.add('drop-before');
            else nodes[nodes.length - 1].classList.add('drop-after');
        }

        function tick() {
            var d = drag;
            if (!d) return;
            var r = listEl.getBoundingClientRect();
            var delta = 0;
            var zone = Math.min(SCROLL_ZONE, r.height / 4);
            if (d.y < r.top + zone) delta = -Math.ceil(SCROLL_MAX * Math.min(1, (r.top + zone - d.y) / zone));
            else if (d.y > r.bottom - zone) delta = Math.ceil(SCROLL_MAX * Math.min(1, (d.y - (r.bottom - zone)) / zone));
            if (delta) {
                listEl.scrollTop += delta;
                updateDrag();
            }
            d.raf = requestAnimationFrame(tick);
        }

        function onDragMove(ev) {
            var d = drag;
            if (!d || ev.pointerId !== d.pointerId) return;
            d.x = ev.clientX;
            d.y = ev.clientY;
            if (!d.active) {
                if (Math.abs(d.x - d.startX) + Math.abs(d.y - d.startY) < DRAG_THRESHOLD) return;
                beginDrag();
            }
            ev.preventDefault();
            updateDrag();
        }

        function endDrag() {
            var d = drag;
            if (!d) return null;
            drag = null;
            cancelAnimationFrame(d.raf);
            d.handle.removeEventListener('pointermove', onDragMove);
            d.handle.removeEventListener('pointerup', onDragEnd);
            d.handle.removeEventListener('pointercancel', onDragCancel);
            d.handle.removeEventListener('lostpointercapture', onDragCancel);
            try { d.handle.releasePointerCapture(d.pointerId); } catch (e) {}
            if (d.ghost) d.ghost.remove();
            d.li.classList.remove('is-dragging');
            document.body.classList.remove('ord-dragging');
            pagerEl.classList.remove('is-drag');
            markZone(null);
            clearIndicators();
            return d;
        }

        function onDragEnd(ev) {
            if (!drag || ev.pointerId !== drag.pointerId) return;
            var d = endDrag();
            if (!d.active) return;
            var start = pageStart();
            if (d.zone) {
                var gTo = d.zone === 'prev' ? start - 1 : Math.min(start + PAGE_SIZE, items.length - 1);
                moveTo(start + d.from, gTo, d.handle);
                return;
            }
            var slot = d.slot;
            if (slot < 0 || slot === d.from || slot === d.from + 1) return;
            moveTo(start + d.from, start + (slot > d.from ? slot - 1 : slot), d.handle);
        }

        function onDragCancel() {
            endDrag();
        }

        function scheduleSave() {
            dirty = true;
            failed = false;
            warned = false;
            setStatus('pending', 'تغییرات ذخیره نشده…');
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = setTimeout(flush, 500);
        }

        function flush() {
            if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
            if (saving || !dirty) return;
            doSave();
        }

        function reload() {
            window.location.reload();
        }

        function settle(ok) {
            if (isOpen()) return;
            if (!ok) {
                reopenWithWarning();
            } else if (changed) {
                reload();
            }
        }

        function reopenWithWarning() {
            suppressOpen = true;
            if (typeof window.openModal === 'function') window.openModal(root.id);
            else root.classList.add('active');
            warned = true;
            setStatus('error', 'تغییرات ذخیره نشد.');
        }

        function doSave() {
            saving = true;
            dirty = false;
            var ids = items.map(function (i) { return i.id; });
            setStatus('saving', 'در حال ذخیره…');
            var body = 'entity=' + encodeURIComponent(entity) + '&ids=' + encodeURIComponent(ids.join(',')) + '&version=' + encodeURIComponent(version) + '&_csrf=' + encodeURIComponent(csrf);
            request('POST', body).then(function (r) {
                saving = false;
                if (r.status === 200 && r.body.ok && Array.isArray(r.body.items)) {
                    version = r.body.version;
                    changed = true;
                    if (dirty) {
                        flush();
                        return;
                    }
                    failed = false;
                    setStatus('saved', 'ذخیره شد');
                    settle(true);
                    return;
                }
                if (r.status === 409 && Array.isArray(r.body.items)) {
                    items = r.body.items;
                    version = r.body.version;
                    dirty = false;
                    changed = true;
                    failed = false;
                    render();
                    setStatus('error', 'فهرست در این بین تغییر کرده بود و به‌روزرسانی شد. ترتیب را دوباره بررسی کنید.', true);
                    settle(true);
                    return;
                }
                failSave(r.status === 401 || r.status === 403 ? errorText(r, '') : r.malformed ? 'پاسخ نامعتبر از سرور دریافت شد.' : 'ذخیره ناموفق بود.');
            }).catch(function () {
                saving = false;
                failSave('ارتباط با سرور برقرار نشد.');
            });
        }

        function failSave(message) {
            failed = true;
            dirty = true;
            setStatus('error', message);
            settle(false);
        }

        function trapTab(ev) {
            if (ev.key !== 'Tab') return;
            var f = Array.prototype.filter.call(root.querySelectorAll('button:not(:disabled), [tabindex="0"]'), function (el) {
                return el.offsetParent !== null && !el.closest('[hidden]');
            });
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (ev.shiftKey && (document.activeElement === first || document.activeElement === box)) {
                ev.preventDefault();
                last.focus();
            } else if (!ev.shiftKey && document.activeElement === last) {
                ev.preventDefault();
                first.focus();
            }
        }

        root.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                ev.preventDefault();
                if (drag) endDrag();
                else window.closeModal(root.id);
                return;
            }
            trapTab(ev);
        });

        root.querySelectorAll('[data-order-close]').forEach(function (b) {
            b.addEventListener('click', function () { window.closeModal(root.id); });
        });

        function onOpened() {
            returnFocus = document.activeElement;
            changed = false;
            failed = false;
            warned = false;
            dirty = false;
            currentPage = 1;
            load();
            box.focus({ preventScroll: true });
        }

        function onClosed() {
            if (drag) endDrag();
            if (saving) return;
            if (dirty || failed) {
                if (!warned) {
                    if (failed) {
                        reopenWithWarning();
                    } else {
                        flush();
                    }
                    return;
                }
                dirty = false;
                failed = false;
                changed = true;
            }
            if (returnFocus && returnFocus.focus) {
                try { returnFocus.focus({ preventScroll: true }); } catch (e) {}
            }
            if (changed) reload();
        }

        new MutationObserver(function () {
            var open = isOpen();
            if (open === wasOpen) return;
            wasOpen = open;
            if (open && suppressOpen) {
                suppressOpen = false;
                return;
            }
            if (open) onOpened();
            else onClosed();
        }).observe(root, { attributes: true, attributeFilter: ['class'] });
        wasOpen = isOpen();
    }

    function boot() {
        document.querySelectorAll('[data-order-modal]').forEach(init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
