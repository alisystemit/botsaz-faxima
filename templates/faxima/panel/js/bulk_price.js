(function () {
    'use strict';

    var MAX_AMOUNT = 999999999999;
    var MAX_SELECTION = 2000;
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function fa(n) {
        return String(n).replace(/\d/g, function (d) { return FA_DIGITS[+d]; });
    }

    function money(n) {
        return Number(n).toLocaleString('en-US');
    }

    function make(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text !== undefined && text !== null) el.textContent = text;
        return el;
    }

    function newRequestId() {
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        var out = '';
        for (var j = 0; j < bytes.length; j++) out += ('0' + bytes[j].toString(16)).slice(-2);
        return out;
    }

    function init(root) {
        var endpoint = root.getAttribute('data-endpoint');
        var csrf = root.getAttribute('data-csrf');
        var box = root.querySelector('.bp-modal');
        var titleEl = root.querySelector('[data-bp-title]');
        var bodyEl = root.querySelector('[data-bp-body]');
        var footEl = root.querySelector('[data-bp-foot]');
        var live = make('span', 'bp-sr');
        live.setAttribute('role', 'status');
        live.setAttribute('aria-live', 'polite');
        box.appendChild(live);

        var op = '';
        var options = null;
        var criteria = { cat_mode: 'all', category: '', panel: '', q: '' };
        var selected = new Map();
        var view = 'results';
        var page = 1;
        var pages = 1;
        var total = 0;
        var items = [];
        var selPage = 1;
        var loadToken = 0;
        var pending = false;
        var requestId = '';
        var requestSig = '';
        var changed = false;
        var wasOpen = false;
        var returnFocus = null;
        var searchTimer = null;
        var matching = false;
        var matchToken = 0;
        var sessionId = 0;
        var optStatus = 'idle';
        var pageDirty = false;
        var pageSize = parseInt(root.getAttribute('data-page-size'), 10) || 5;
        var lockedScroll = null;
        var vvTimer = 0;
        var ui = {};

        function isOpen() { return root.classList.contains('active'); }

        function announce(msg) {
            live.textContent = '';
            setTimeout(function () { live.textContent = msg; }, 30);
        }

        function opWord() { return op === 'increase' ? 'افزایش' : 'کاهش'; }

        function request(method, params, body) {
            var opts = {
                method: method,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
            };
            var url = endpoint;
            if (method === 'GET') {
                url += '?' + params + '&_=' + Date.now();
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
            if (r.malformed) return 'پاسخ نامعتبر از سرور دریافت شد (کد ' + fa(r.status) + ').';
            if (typeof r.body.message === 'string' && r.body.message) return r.body.message;
            if (r.status >= 500) return 'خطای سرور (کد ' + fa(r.status) + ').';
            return fallback + ' (کد ' + fa(r.status) + ').';
        }

        function parseAmount() {
            var raw = '';
            if (ui.amount) {
                raw = window.FaoximaMoneyInput ? window.FaoximaMoneyInput.rawValue(ui.amount) : String(ui.amount.value).replace(/\D/g, '');
            }
            raw = raw.replace(/^0+/, '');
            if (!/^[1-9]\d{0,11}$/.test(raw)) return null;
            var n = parseInt(raw, 10);
            return n >= 1 && n <= MAX_AMOUNT ? n : null;
        }

        function newPriceOf(price, amount) {
            return op === 'increase' ? price + amount : price - amount;
        }

        function analysis() {
            var amount = parseAmount();
            var negatives = [];
            var overs = [];
            if (amount !== null) {
                selected.forEach(function (p) {
                    var np = newPriceOf(p.price, amount);
                    if (np < 0) negatives.push(p);
                    else if (np > MAX_AMOUNT) overs.push(p);
                });
            }
            return { amount: amount, negatives: negatives, overs: overs };
        }

        function setAlert(kind, message, products) {
            ui.alert.textContent = '';
            ui.alert.className = 'bp-alert' + (kind ? ' is-' + kind : '');
            if (!kind) {
                ui.alert.hidden = true;
                return;
            }
            ui.alert.hidden = false;
            ui.alert.appendChild(make('div', 'bp-alert__msg', message));
            if (products && products.length) {
                var ul = make('ul', 'bp-alert__list');
                products.slice(0, 6).forEach(function (p) {
                    ul.appendChild(make('li', '', '#' + fa(p.id) + ' ' + p.name));
                });
                if (products.length > 6) ul.appendChild(make('li', '', 'و ' + fa(products.length - 6) + ' محصول دیگر'));
                ui.alert.appendChild(ul);
            }
        }

        function refreshFooter() {
            if (!ui.submit) return;
            var a = analysis();
            var count = selected.size;
            ui.count.textContent = fa(count) + ' محصول انتخاب شده';
            ui.tabSelected.textContent = 'انتخاب‌شده‌ها (' + fa(count) + ')';
            ui.clearBtn.disabled = count === 0 || pending;
            ui.submit.textContent = count > 0
                ? 'اعمال ' + opWord() + ' روی ' + fa(count) + ' محصول'
                : 'اعمال ' + opWord();

            var raw = ui.amount.value.trim();
            var amountBad = raw !== '' && a.amount === null;
            ui.amountError.hidden = !amountBad;
            ui.amount.setAttribute('aria-invalid', amountBad ? 'true' : 'false');

            if (pending) {
                ui.submit.disabled = true;
                return;
            }
            var blocked = a.negatives.length > 0 || a.overs.length > 0;
            if (a.negatives.length) {
                setAlert('error', 'قیمت جدید این محصولات منفی می‌شود؛ مبلغ را کمتر کنید یا آن‌ها را از انتخاب خارج کنید:', a.negatives);
            } else if (a.overs.length) {
                setAlert('error', 'قیمت جدید این محصولات از حداکثر مقدار مجاز بیشتر می‌شود:', a.overs);
            } else if (ui.alert.getAttribute('data-sticky') !== '1') {
                setAlert('', '');
            }
            ui.submit.disabled = count === 0 || a.amount === null || blocked;
            ui.summary.textContent = a.amount !== null && count > 0
                ? opWord() + ' ' + fa(money(a.amount)) + ' تومان به قیمت پایه هر محصول انتخابی'
                : '';
            renderRowsPreview();
        }

        function renderRowsPreview() {
            var a = analysis();
            var cells = ui.list.querySelectorAll('[data-bp-new]');
            for (var i = 0; i < cells.length; i++) {
                var id = parseInt(cells[i].getAttribute('data-bp-new'), 10);
                var p = selected.get(id);
                paintNew(cells[i], p, a.amount);
            }
        }

        function paintNew(cell, p, amount) {
            cell.className = 'bp-new';
            cell.textContent = '';
            var lbl = make('span', 'bp-lbl', 'جدید');
            cell.appendChild(lbl);
            if (!p || amount === null) {
                cell.appendChild(make('span', 'bp-val bp-val--muted', '—'));
                return;
            }
            var np = newPriceOf(p.price, amount);
            var bad = np < 0 || np > MAX_AMOUNT;
            cell.classList.add(bad ? 'is-bad' : 'is-ok');
            cell.appendChild(make('span', 'bp-val', money(np)));
        }

        function metaOf(it) {
            return { id: it.id, name: it.name, price: it.price, panels: it.panels || [], categories: it.categories || [] };
        }

        function toggleItem(it, on) {
            if (on) {
                if (selected.size >= MAX_SELECTION && !selected.has(it.id)) return false;
                selected.set(it.id, metaOf(it));
            } else {
                selected.delete(it.id);
            }
            return true;
        }

        function buildRow(it) {
            var li = make('li', 'bp-item');
            var label = make('label', 'bp-row');
            var invalid = it.price === null;
            var cb = make('input');
            cb.type = 'checkbox';
            cb.className = 'bp-check';
            cb.checked = selected.has(it.id);
            cb.disabled = invalid || pending;
            cb.setAttribute('aria-label', 'انتخاب محصول ' + it.name);
            cb.addEventListener('change', function () {
                if (!toggleItem(it, cb.checked)) {
                    cb.checked = false;
                    setAlert('error', 'حداکثر ' + fa(MAX_SELECTION) + ' محصول در هر بار قابل انتخاب است.');
                    ui.alert.removeAttribute('data-sticky');
                    return;
                }
                if (ui.alert.getAttribute('data-sticky') === '1') {
                    ui.alert.removeAttribute('data-sticky');
                    setAlert('', '');
                }
                if (view === 'selected' && !cb.checked) {
                    renderList();
                }
                refreshFooter();
            });
            label.appendChild(cb);
            label.appendChild(make('span', 'bp-id', '#' + fa(it.id)));
            label.appendChild(make('span', 'bp-name', it.name));

            var tags = make('span', 'bp-tags');
            (it.panels || []).forEach(function (pn) {
                tags.appendChild(make('span', 'badge badge-cyan', pn === '/all' ? 'تمامی پنل‌ها' : pn));
            });
            (it.categories || []).forEach(function (cn) {
                tags.appendChild(make('span', 'badge badge-gray', cn));
            });
            label.appendChild(tags);

            var prices = make('span', 'bp-prices');
            var cur = make('span', 'bp-cur');
            cur.appendChild(make('span', 'bp-lbl', 'فعلی'));
            if (invalid) {
                cur.appendChild(make('span', 'badge badge-danger', 'قیمت نامعتبر'));
            } else {
                cur.appendChild(make('span', 'bp-val', money(it.price)));
            }
            prices.appendChild(cur);

            var nw = make('span', 'bp-new');
            nw.setAttribute('data-bp-new', String(it.id));
            prices.appendChild(nw);
            label.appendChild(prices);
            li.appendChild(label);
            return li;
        }

        function setListState(kind, message, retry) {
            ui.state.className = 'bp-state' + (kind === 'error' ? ' is-error' : '');
            ui.state.textContent = '';
            if (!kind) {
                ui.state.hidden = true;
                return;
            }
            ui.state.hidden = false;
            if (kind === 'loading') ui.state.appendChild(make('span', 'bp-spinner'));
            ui.state.appendChild(make('span', '', message));
            if (retry) {
                var b = make('button', 'btn btn-soft-purple btn-sm', 'تلاش دوباره');
                b.type = 'button';
                b.addEventListener('click', retry);
                ui.state.appendChild(b);
            }
        }

        function currentRows() {
            if (view === 'selected') {
                var all = Array.from(selected.values());
                var pc = Math.max(1, Math.ceil(all.length / pageSize));
                if (selPage > pc) selPage = pc;
                var slice = all.slice((selPage - 1) * pageSize, selPage * pageSize);
                return { rows: slice, pages: pc, page: selPage, total: all.length };
            }
            return { rows: items, pages: pages, page: page, total: total };
        }

        function renderList() {
            var cur = currentRows();
            ui.list.textContent = '';
            ui.listHead.hidden = cur.rows.length === 0;
            cur.rows.forEach(function (it) { ui.list.appendChild(buildRow(it)); });
            if (!cur.rows.length) {
                setListState('empty', view === 'selected' ? 'هنوز محصولی انتخاب نشده است.' : 'محصولی با این فیلترها پیدا نشد.');
            } else {
                setListState('', '');
            }
            ui.pager.textContent = '';
            if (cur.pages > 1) {
                ui.pager.hidden = false;
                var prev = make('button', 'btn btn-soft-purple btn-sm', 'قبلی');
                prev.type = 'button';
                prev.disabled = cur.page <= 1 || pending;
                var next = make('button', 'btn btn-soft-purple btn-sm', 'بعدی');
                next.type = 'button';
                next.disabled = cur.page >= cur.pages || pending;
                var info = make('span', 'bp-pager__info', 'صفحه ' + fa(cur.page) + ' از ' + fa(cur.pages) + ' — ' + fa(cur.total) + ' محصول');
                prev.addEventListener('click', function () { goPage(cur.page - 1); });
                next.addEventListener('click', function () { goPage(cur.page + 1); });
                ui.pager.appendChild(prev);
                ui.pager.appendChild(info);
                ui.pager.appendChild(next);
            } else {
                ui.pager.hidden = true;
            }
            ui.selectAll.disabled = matching || pending || view !== 'results' || total === 0;
            ui.selectAll.textContent = 'انتخاب همه نتایج' + (view === 'results' && total ? ' (' + fa(total) + ')' : '');
            renderRowsPreview();
        }

        function goPage(n) {
            if (view === 'selected') {
                selPage = n;
                renderList();
            } else {
                loadList(n);
            }
            ui.list.scrollTop = 0;
        }

        function queryString(extra) {
            var p = [];
            p.push('cat_mode=' + encodeURIComponent(criteria.cat_mode));
            p.push('category=' + encodeURIComponent(criteria.category));
            p.push('panel=' + encodeURIComponent(criteria.panel));
            p.push('q=' + encodeURIComponent(criteria.q));
            return p.join('&') + (extra ? '&' + extra : '');
        }

        function loadList(n) {
            var token = ++loadToken;
            setListState('loading', 'در حال بارگذاری…');
            ui.list.textContent = '';
            ui.pager.hidden = true;
            ui.listHead.hidden = true;
            request('GET', 'action=list&page=' + (n || 1) + '&' + queryString()).then(function (r) {
                if (token !== loadToken) return;
                if (r.status === 200 && r.body.ok && Array.isArray(r.body.items)) {
                    items = r.body.items;
                    page = r.body.page;
                    pages = r.body.pages;
                    total = r.body.total;
                    items.forEach(function (it) {
                        var s = selected.get(it.id);
                        if (s && it.price !== null) selected.set(it.id, metaOf(it));
                    });
                    setListState('', '');
                    renderList();
                    refreshFooter();
                    announce(fa(total) + ' محصول یافت شد.');
                } else {
                    items = [];
                    total = 0;
                    setListState('error', errorText(r, 'بارگذاری محصولات ناموفق بود'), function () { loadList(n); });
                    ui.selectAll.disabled = true;
                }
            }).catch(function () {
                if (token !== loadToken) return;
                items = [];
                total = 0;
                setListState('error', 'ارتباط با سرور برقرار نشد.', function () { loadList(n); });
                ui.selectAll.disabled = true;
            });
        }

        function readCriteria() {
            var catVal = ui.category.value;
            var c = { cat_mode: 'all', category: '', panel: ui.panel.value, q: ui.search.value.trim() };
            if (catVal === '__uncat__') c.cat_mode = 'uncat';
            else if (catVal !== '__all__') {
                c.cat_mode = 'name';
                c.category = catVal.substring(2);
            }
            return c;
        }

        function criteriaChanged(a, b) {
            return a.cat_mode !== b.cat_mode || a.category !== b.category || a.panel !== b.panel || a.q !== b.q;
        }

        function onFilterChange() {
            var next = readCriteria();
            if (!criteriaChanged(criteria, next)) return;
            criteria = next;
            matchToken++;
            matching = false;
            var had = selected.size;
            if (had) {
                selected.clear();
                ui.clearNote.textContent = 'با تغییر فیلتر یا جستجو، انتخاب‌های قبلی (' + fa(had) + ' محصول) پاک شد.';
                ui.clearNote.hidden = false;
                announce(ui.clearNote.textContent);
            } else {
                ui.clearNote.hidden = true;
            }
            ui.alert.removeAttribute('data-sticky');
            setAlert('', '');
            if (view === 'selected') setView('results', true);
            page = 1;
            loadList(1);
            refreshFooter();
        }

        function setView(v, skipRender) {
            view = v;
            selPage = 1;
            ui.tabResults.setAttribute('aria-pressed', v === 'results' ? 'true' : 'false');
            ui.tabSelected.setAttribute('aria-pressed', v === 'selected' ? 'true' : 'false');
            ui.tabResults.classList.toggle('is-active', v === 'results');
            ui.tabSelected.classList.toggle('is-active', v === 'selected');
            if (skipRender) return;
            setListState('', '');
            renderList();
            refreshFooter();
        }

        function doSelectAll() {
            if (matching || pending) return;
            matching = true;
            ui.selectAll.disabled = true;
            var token = ++matchToken;
            var sid = sessionId;
            request('GET', 'action=match&' + queryString()).then(function (r) {
                if (token !== matchToken || sid !== sessionId) return;
                matching = false;
                if (!(r.status === 200 && r.body.ok && Array.isArray(r.body.items))) {
                    setAlert('error', errorText(r, 'انتخاب نتایج ناموفق بود'));
                    ui.alert.removeAttribute('data-sticky');
                    renderList();
                    return;
                }
                var list = r.body.items;
                var valid = list.filter(function (it) { return it.price !== null; });
                var skipped = list.length - valid.length;
                var combined = new Set();
                selected.forEach(function (_, id) { combined.add(id); });
                valid.forEach(function (it) { combined.add(it.id); });
                if (combined.size > MAX_SELECTION) {
                    setAlert('error', 'حداکثر ' + fa(MAX_SELECTION) + ' محصول در هر بار قابل انتخاب است. فیلترها را محدودتر کنید.');
                    ui.alert.removeAttribute('data-sticky');
                    renderList();
                    return;
                }
                valid.forEach(function (it) { selected.set(it.id, metaOf(it)); });
                if (skipped) {
                    setAlert('warn', fa(skipped) + ' محصول به‌دلیل قیمت نامعتبر انتخاب نشد.');
                    ui.alert.setAttribute('data-sticky', '1');
                } else {
                    ui.alert.removeAttribute('data-sticky');
                    setAlert('', '');
                }
                renderList();
                refreshFooter();
                announce(fa(valid.length) + ' محصول انتخاب شد.');
            }).catch(function () {
                if (token !== matchToken || sid !== sessionId) return;
                matching = false;
                setAlert('error', 'ارتباط با سرور برقرار نشد.');
                ui.alert.removeAttribute('data-sticky');
                renderList();
            });
        }

        function clearSelection() {
            selected.clear();
            ui.clearNote.hidden = true;
            ui.alert.removeAttribute('data-sticky');
            setAlert('', '');
            if (view === 'selected') setView('results', true);
            renderList();
            refreshFooter();
        }

        function setPending(on) {
            pending = on;
            box.setAttribute('aria-busy', on ? 'true' : 'false');
            ui.submit.disabled = true;
            ui.amount.disabled = on;
            ui.category.disabled = on;
            ui.panel.disabled = on;
            ui.search.disabled = on;
            ui.selectAll.disabled = on;
            ui.clearBtn.disabled = on;
            ui.back.disabled = on;
            ui.tabResults.disabled = on;
            ui.tabSelected.disabled = on;
            if (on) {
                ui.submit.textContent = 'در حال ثبت…';
                renderList();
            } else {
                refreshFooter();
                renderList();
            }
        }

        function signature(a) {
            var ids = Array.from(selected.keys()).sort(function (x, y) { return x - y; });
            return op + '|' + a.amount + '|' + ids.map(function (id) { return id + ':' + selected.get(id).price; }).join(',');
        }

        function submit() {
            if (pending) return;
            var a = analysis();
            if (!selected.size || a.amount === null || a.negatives.length || a.overs.length) {
                refreshFooter();
                return;
            }
            var sig = signature(a);
            if (sig !== requestSig || !requestId) {
                requestSig = sig;
                requestId = newRequestId();
            }
            var payload = [];
            selected.forEach(function (p) { payload.push({ id: p.id, price: String(p.price) }); });
            var body = 'action=apply'
                + '&_csrf=' + encodeURIComponent(csrf)
                + '&operation=' + encodeURIComponent(op)
                + '&amount=' + encodeURIComponent(String(a.amount))
                + '&request_id=' + encodeURIComponent(requestId)
                + '&items=' + encodeURIComponent(JSON.stringify(payload));
            ui.alert.removeAttribute('data-sticky');
            setAlert('', '');
            var sid = sessionId;
            setPending(true);
            request('POST', '', body).then(function (r) {
                if (sid !== sessionId) {
                    if (r.status === 200 && r.body.ok) pageDirty = true;
                    return;
                }
                setPending(false);
                handleResult(r);
            }).catch(function () {
                if (sid !== sessionId) return;
                setPending(false);
                setAlert('error', 'ارتباط با سرور برقرار نشد و نتیجه ثبت مشخص نیست. دوباره «' + ui.submit.textContent + '» را بزنید؛ درخواست تکراری دوبار اعمال نمی‌شود.');
                ui.alert.setAttribute('data-sticky', '1');
            });
        }

        function handleResult(r) {
            var b = r.body;
            if (r.status === 200 && b.ok) {
                requestId = '';
                requestSig = '';
                changed = true;
                showSuccess(b);
                return;
            }
            if (r.status >= 500 || r.malformed) {
                setAlert('error', errorText(r, 'ثبت تغییرات ناموفق بود') + ' اگر مطمئن نیستید، دوباره تلاش کنید؛ درخواست تکراری دوبار اعمال نمی‌شود.');
                ui.alert.setAttribute('data-sticky', '1');
                return;
            }
            requestId = '';
            requestSig = '';
            if (r.status === 409 && b.error === 'stale') {
                if (Array.isArray(b.items)) {
                    b.items.forEach(function (it) {
                        var s = selected.get(it.id);
                        if (s && it.price !== null) s.price = it.price;
                    });
                }
                var stale = [];
                (b.stale_ids || []).forEach(function (id) {
                    var s = selected.get(id);
                    if (s) stale.push(s);
                });
                setAlert('error', b.message || 'قیمت برخی محصولات تغییر کرده است.', stale);
                ui.alert.setAttribute('data-sticky', '1');
                loadList(page);
                refreshFooter();
                return;
            }
            if (r.status === 409 && b.error === 'not_found') {
                (b.missing || []).forEach(function (id) { selected.delete(id); });
                setAlert('error', b.message || 'برخی محصولات دیگر وجود ندارند.');
                ui.alert.setAttribute('data-sticky', '1');
                loadList(page);
                refreshFooter();
                return;
            }
            if (b.error === 'negative_price' || b.error === 'over_limit' || b.error === 'invalid_price') {
                setAlert('error', b.message || 'درخواست نامعتبر است.', Array.isArray(b.products) ? b.products : []);
                ui.alert.setAttribute('data-sticky', '1');
                return;
            }
            setAlert('error', errorText(r, 'ثبت تغییرات ناموفق بود'));
            ui.alert.setAttribute('data-sticky', '1');
        }

        function showSuccess(b) {
            titleEl.textContent = 'قیمت گروهی محصولات';
            footEl.hidden = true;
            bodyEl.textContent = '';
            bodyEl.classList.add('bp-body--done');
            var wrap = make('div', 'bp-done');
            wrap.appendChild(make('div', 'bp-done__title', opWord() + ' قیمت روی ' + fa(b.updated) + ' محصول با موفقیت اعمال شد.'));
            if (b.replayed) wrap.appendChild(make('div', 'bp-hint', 'این درخواست پیش‌تر ثبت شده بود و دوباره اعمال نشد.'));
            var ul = make('ul', 'bp-done__list');
            (b.items || []).slice(0, 8).forEach(function (c) {
                ul.appendChild(make('li', '', '#' + fa(c.id) + ' ' + c.name + ': ' + money(c.old) + ' ← ' + money(c.new) + ' تومان'));
            });
            if ((b.items || []).length > 8) ul.appendChild(make('li', '', 'و ' + fa(b.items.length - 8) + ' محصول دیگر'));
            wrap.appendChild(ul);
            var close = make('button', 'btn btn-primary btn-sm', 'بستن و به‌روزرسانی فهرست');
            close.type = 'button';
            close.addEventListener('click', function () { closeSelf(); });
            wrap.appendChild(close);
            bodyEl.appendChild(wrap);
            announce('تغییر قیمت با موفقیت اعمال شد.');
            close.focus();
        }

        function closeSelf() {
            if (window.closeModal) window.closeModal(root.id);
            else root.classList.remove('active');
        }

        function showStepOne() {
            op = '';
            ui = {};
            loadToken++;
            matchToken++;
            matching = false;
            titleEl.textContent = 'قیمت گروهی محصولات';
            footEl.hidden = true;
            footEl.textContent = '';
            bodyEl.textContent = '';
            bodyEl.classList.remove('bp-body--done');
            bodyEl.classList.remove('bp-body--work');
            var hint = make('p', 'bp-hint', 'نوع عملیات را انتخاب کنید. مبلغ به‌صورت ثابت و به تومان روی قیمت فعلی محصولات اعمال می‌شود.');
            var grid = make('div', 'bp-ops');
            [['increase', 'افزایش گروهی قیمت محصول', 'مبلغ واردشده به قیمت هر محصول انتخابی اضافه می‌شود.'],
             ['decrease', 'کاهش گروهی قیمت محصول', 'مبلغ واردشده از قیمت هر محصول انتخابی کم می‌شود.']].forEach(function (o) {
                var b = make('button', 'bp-op bp-op--' + o[0]);
                b.type = 'button';
                b.setAttribute('data-op', o[0]);
                b.appendChild(make('span', 'bp-op__title', o[1]));
                b.appendChild(make('span', 'bp-op__sub', o[2]));
                b.addEventListener('click', function () { startWork(o[0]); });
                grid.appendChild(b);
            });
            bodyEl.appendChild(hint);
            bodyEl.appendChild(grid);
        }

        function buildSelect(label, id) {
            var g = make('div', 'bp-field');
            var l = make('label', 'form-label', label);
            l.setAttribute('for', id);
            var s = make('select', 'form-control');
            s.id = id;
            g.appendChild(l);
            g.appendChild(s);
            return { group: g, select: s };
        }

        function startWork(chosen) {
            op = chosen;
            ui = {};
            bodyEl.textContent = '';
            bodyEl.classList.add('bp-body--work');
            titleEl.textContent = (op === 'increase' ? 'افزایش گروهی قیمت محصول' : 'کاهش گروهی قیمت محصول');

            var top = make('div', 'bp-top');
            ui.back = make('button', 'btn btn-soft-purple btn-sm', 'تغییر نوع عملیات');
            ui.back.type = 'button';
            ui.back.addEventListener('click', function () {
                if (pending) return;
                showStepOne();
                var first = bodyEl.querySelector('.bp-op');
                if (first) first.focus();
            });
            top.appendChild(ui.back);
            top.appendChild(make('span', 'badge ' + (op === 'increase' ? 'badge-success' : 'badge-danger'), op === 'increase' ? 'افزایش' : 'کاهش'));
            bodyEl.appendChild(top);

            var note = make('details', 'bp-note');
            note.appendChild(make('summary', '', 'توضیح درباره قیمت مشترک محصولات'));
            note.appendChild(make('p', '', 'هر محصول فقط یک قیمت مشترک دارد. انتخاب پنل فقط محصولات را فیلتر می‌کند و تغییر قیمت محصولِ مشترک در همه پنل‌ها و دسته‌بندی‌هایی که از آن استفاده می‌کنند اعمال می‌شود. این مبلغ روی قیمت پایه (تومان) اعمال می‌شود و در پنل‌های دارای ضریب ارز، قیمت نهایی از روی آن محاسبه می‌شود.'));
            note.open = window.innerWidth > 760 && window.innerHeight > 520;
            bodyEl.appendChild(note);

            var filters = make('div', 'bp-filters');
            var cat = buildSelect('دسته‌بندی', 'bp-cat');
            var pan = buildSelect('پنل', 'bp-panel');
            ui.category = cat.select;
            ui.panel = pan.select;
            var sg = make('div', 'bp-field');
            var sl = make('label', 'form-label', 'جستجو (نام یا شناسه محصول)');
            sl.setAttribute('for', 'bp-search');
            ui.search = make('input', 'form-control');
            ui.search.type = 'search';
            ui.search.id = 'bp-search';
            ui.search.placeholder = 'نام یا شناسه محصول…';
            ui.search.autocomplete = 'off';
            sg.appendChild(sl);
            sg.appendChild(ui.search);
            filters.appendChild(cat.group);
            filters.appendChild(pan.group);
            filters.appendChild(sg);
            bodyEl.appendChild(filters);

            [['__all__', 'همه دسته‌بندی‌ها'], ['__uncat__', 'بدون دسته‌بندی']].forEach(function (o) {
                var opt = make('option', '', o[1]);
                opt.value = o[0];
                ui.category.appendChild(opt);
            });
            var allPanels = make('option', '', 'همه پنل‌ها');
            allPanels.value = '';
            ui.panel.appendChild(allPanels);
            ui.optState = make('div', 'bp-optstate');
            ui.optState.hidden = true;
            ui.optState.setAttribute('role', 'status');
            bodyEl.appendChild(ui.optState);
            populateOptions();

            ui.clearNote = make('div', 'bp-clearnote');
            ui.clearNote.hidden = true;
            ui.clearNote.setAttribute('role', 'status');
            bodyEl.appendChild(ui.clearNote);

            var bar = make('div', 'bp-bar');
            ui.selectAll = make('button', 'btn btn-soft-purple btn-sm', 'انتخاب همه نتایج');
            ui.selectAll.type = 'button';
            ui.clearBtn = make('button', 'btn btn-soft-danger btn-sm', 'پاک کردن انتخاب');
            ui.clearBtn.type = 'button';
            ui.count = make('span', 'bp-count', '۰ محصول انتخاب شده');
            var tabs = make('div', 'bp-tabs');
            ui.tabResults = make('button', 'bp-tab is-active', 'نتایج');
            ui.tabResults.type = 'button';
            ui.tabResults.setAttribute('aria-pressed', 'true');
            ui.tabSelected = make('button', 'bp-tab', 'انتخاب‌شده‌ها (۰)');
            ui.tabSelected.type = 'button';
            ui.tabSelected.setAttribute('aria-pressed', 'false');
            tabs.appendChild(ui.tabResults);
            tabs.appendChild(ui.tabSelected);
            var actions = make('div', 'bp-bar__actions');
            actions.appendChild(ui.selectAll);
            actions.appendChild(ui.clearBtn);
            bar.appendChild(actions);
            bar.appendChild(ui.count);
            bar.appendChild(tabs);
            bodyEl.appendChild(bar);

            var wrap = make('div', 'bp-listwrap');
            ui.listHead = make('div', 'bp-listhead');
            ui.listHead.setAttribute('aria-hidden', 'true');
            ui.listHead.hidden = true;
            ['', 'شناسه', 'نام محصول', 'پنل / دسته‌بندی', 'قیمت فعلی', 'قیمت جدید'].forEach(function (t) {
                ui.listHead.appendChild(make('span', '', t));
            });
            ui.state = make('div', 'bp-state');
            ui.state.hidden = true;
            ui.list = make('ul', 'bp-list');
            ui.list.setAttribute('aria-label', 'فهرست محصولات');
            ui.pager = make('div', 'bp-pager');
            ui.pager.hidden = true;
            wrap.appendChild(ui.listHead);
            wrap.appendChild(ui.state);
            wrap.appendChild(ui.list);
            wrap.appendChild(ui.pager);
            bodyEl.appendChild(wrap);

            footEl.hidden = false;
            footEl.textContent = '';
            ui.alert = make('div', 'bp-alert');
            ui.alert.hidden = true;
            ui.alert.setAttribute('role', 'alert');
            var row = make('div', 'bp-foot__row');
            var amountField = make('div', 'bp-field bp-amount');
            var al = make('label', 'form-label', 'مبلغ ' + (op === 'increase' ? 'افزایش' : 'کاهش') + ' (تومان)');
            al.setAttribute('for', 'bp-amount');
            ui.amount = make('input', 'form-control');
            ui.amount.type = 'text';
            ui.amount.id = 'bp-amount';
            ui.amount.setAttribute('data-money', '');
            ui.amount.placeholder = 'مثلاً 50,000';
            ui.amount.setAttribute('inputmode', 'numeric');
            ui.amount.autocomplete = 'off';
            ui.amountError = make('small', 'bp-field__error', 'مبلغ باید یک عدد صحیح مثبت (به تومان) باشد.');
            ui.amountError.hidden = true;
            amountField.appendChild(al);
            amountField.appendChild(ui.amount);
            amountField.appendChild(ui.amountError);
            ui.submit = make('button', 'btn btn-primary bp-submit', 'اعمال ' + opWord());
            ui.submit.type = 'button';
            ui.submit.disabled = true;
            row.appendChild(amountField);
            row.appendChild(ui.submit);
            ui.summary = make('div', 'bp-summary');
            footEl.appendChild(ui.alert);
            footEl.appendChild(row);
            footEl.appendChild(ui.summary);

            if (window.FaoximaMoneyInput) window.FaoximaMoneyInput.wire(ui.amount);

            ui.amount.addEventListener('beforeinput', function (ev) {
                if (ev.data && /[.٫٬\-−+eE\/]/.test(ev.data.replace(/[,٬]/g, ''))) {
                    ev.preventDefault();
                    ui.amountError.hidden = false;
                }
            });
            ui.amount.addEventListener('focus', function () {
                setTimeout(function () {
                    if (ui.amount && document.activeElement === ui.amount && ui.amount.scrollIntoView) {
                        ui.amount.scrollIntoView({ block: 'nearest' });
                    }
                }, 300);
            });
            ui.amount.addEventListener('input', function () {
                if (ui.alert.getAttribute('data-sticky') === '1') {
                    ui.alert.removeAttribute('data-sticky');
                    setAlert('', '');
                }
                refreshFooter();
            });
            ui.category.addEventListener('change', onFilterChange);
            ui.panel.addEventListener('change', onFilterChange);
            ui.search.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(onFilterChange, 350);
            });
            ui.search.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    clearTimeout(searchTimer);
                    onFilterChange();
                }
            });
            ui.selectAll.addEventListener('click', doSelectAll);
            ui.clearBtn.addEventListener('click', clearSelection);
            ui.tabResults.addEventListener('click', function () { setView('results'); });
            ui.tabSelected.addEventListener('click', function () { setView('selected'); });
            ui.submit.addEventListener('click', submit);

            criteria = { cat_mode: 'all', category: '', panel: '', q: '' };
            selected.clear();
            view = 'results';
            page = 1;
            loadList(1);
            refreshFooter();
            ui.category.focus();
        }

        function populateOptions() {
            if (!ui.optState) return;
            ui.optState.textContent = '';
            if (optStatus === 'loading' || optStatus === 'idle') {
                ui.optState.hidden = false;
                ui.optState.appendChild(make('span', 'bp-spinner'));
                ui.optState.appendChild(make('span', '', 'در حال بارگذاری فهرست دسته‌بندی‌ها و پنل‌ها…'));
                return;
            }
            if (optStatus === 'error') {
                ui.optState.hidden = false;
                ui.optState.classList.add('is-error');
                ui.optState.appendChild(make('span', '', 'بارگذاری فهرست دسته‌بندی‌ها و پنل‌ها ناموفق بود.'));
                var retry = make('button', 'btn btn-soft-purple btn-sm', 'تلاش دوباره');
                retry.type = 'button';
                retry.addEventListener('click', loadOptions);
                ui.optState.appendChild(retry);
                return;
            }
            ui.optState.hidden = true;
            ui.optState.classList.remove('is-error');
            var catVal = ui.category.value;
            var panVal = ui.panel.value;
            Array.prototype.slice.call(ui.category.querySelectorAll('option[data-dyn]')).forEach(function (o) { o.remove(); });
            Array.prototype.slice.call(ui.panel.querySelectorAll('option[data-dyn]')).forEach(function (o) { o.remove(); });
            options.categories.forEach(function (c) {
                var opt = make('option', '', c);
                opt.value = 'c:' + c;
                opt.setAttribute('data-dyn', '1');
                ui.category.appendChild(opt);
            });
            options.panels.forEach(function (pn) {
                var opt = make('option', '', pn);
                opt.value = pn;
                opt.setAttribute('data-dyn', '1');
                ui.panel.appendChild(opt);
            });
            ui.category.value = catVal;
            ui.panel.value = panVal;
        }

        function loadOptions() {
            var sid = sessionId;
            optStatus = 'loading';
            populateOptions();
            request('GET', 'action=options').then(function (r) {
                if (sid !== sessionId) return;
                if (r.status === 200 && r.body.ok) {
                    options = { categories: r.body.categories || [], panels: r.body.panels || [] };
                    optStatus = 'ready';
                } else {
                    optStatus = 'error';
                }
                populateOptions();
            }).catch(function () {
                if (sid !== sessionId) return;
                optStatus = 'error';
                populateOptions();
            });
        }

        function lockScroll() {
            if (lockedScroll) return;
            var y = window.pageYOffset || document.documentElement.scrollTop || 0;
            var st = document.body.style;
            lockedScroll = { y: y, position: st.position, top: st.top, width: st.width, left: st.left, right: st.right };
            st.position = 'fixed';
            st.top = '-' + y + 'px';
            st.left = '0';
            st.right = '0';
            st.width = '100%';
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', onViewport);
                onViewport();
            }
        }

        function unlockScroll() {
            if (window.visualViewport) window.visualViewport.removeEventListener('resize', onViewport);
            cancelAnimationFrame(vvTimer);
            root.style.removeProperty('--bp-vh');
            if (!lockedScroll) return;
            var st = document.body.style;
            st.position = lockedScroll.position;
            st.top = lockedScroll.top;
            st.width = lockedScroll.width;
            st.left = lockedScroll.left;
            st.right = lockedScroll.right;
            window.scrollTo(0, lockedScroll.y);
            lockedScroll = null;
        }

        function onViewport() {
            cancelAnimationFrame(vvTimer);
            vvTimer = requestAnimationFrame(function () {
                if (window.visualViewport && isOpen()) {
                    root.style.setProperty('--bp-vh', window.visualViewport.height + 'px');
                    if (ui.amount && document.activeElement === ui.amount && ui.amount.scrollIntoView) {
                        ui.amount.scrollIntoView({ block: 'nearest' });
                    }
                }
            });
        }

        function focusables() {
            var nodes = box.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
            return Array.prototype.filter.call(nodes, function (n) {
                if (n.disabled || n.hidden) return false;
                if (n.closest('[hidden]')) return false;
                return n.offsetWidth > 0 || n.offsetHeight > 0;
            });
        }

        function onOpen() {
            clearTimeout(searchTimer);
            sessionId++;
            loadToken++;
            matchToken++;
            matching = false;
            selected.clear();
            options = null;
            optStatus = 'idle';
            changed = false;
            pending = false;
            requestId = '';
            requestSig = '';
            lockScroll();
            showStepOne();
            loadOptions();
            box.focus({ preventScroll: true });
            var first = bodyEl.querySelector('.bp-op');
            if (first) first.focus();
        }

        function onClose() {
            clearTimeout(searchTimer);
            sessionId++;
            loadToken++;
            matchToken++;
            matching = false;
            unlockScroll();
            if (returnFocus && document.contains(returnFocus)) {
                try { returnFocus.focus({ preventScroll: true }); } catch (e) {}
            }
            returnFocus = null;
            if (changed || pageDirty) {
                changed = false;
                pageDirty = false;
                window.location.reload();
            }
        }

        new MutationObserver(function () {
            var open = isOpen();
            if (open && !wasOpen) {
                wasOpen = true;
                onOpen();
            } else if (!open && wasOpen) {
                wasOpen = false;
                onClose();
            }
        }).observe(root, { attributes: true, attributeFilter: ['class'] });

        root.addEventListener('keydown', function (ev) {
            if (!isOpen()) return;
            if (ev.key === 'Escape') {
                ev.preventDefault();
                closeSelf();
                return;
            }
            if (ev.key !== 'Tab') return;
            var f = focusables();
            if (!f.length) {
                ev.preventDefault();
                box.focus();
                return;
            }
            var firstEl = f[0];
            var lastEl = f[f.length - 1];
            var active = document.activeElement;
            if (ev.shiftKey && (active === firstEl || active === box)) {
                ev.preventDefault();
                lastEl.focus();
            } else if (!ev.shiftKey && active === lastEl) {
                ev.preventDefault();
                firstEl.focus();
            } else if (!box.contains(active)) {
                ev.preventDefault();
                firstEl.focus();
            }
        });

        root.querySelectorAll('[data-bp-close]').forEach(function (b) {
            b.addEventListener('click', closeSelf);
        });

        document.querySelectorAll('[data-bp-open="' + root.id + '"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                returnFocus = btn;
                if (window.openModal) window.openModal(root.id);
                else root.classList.add('active');
            });
        });
    }

    function boot() {
        var nodes = document.querySelectorAll('[data-bp-modal]');
        for (var i = 0; i < nodes.length; i++) init(nodes[i]);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
