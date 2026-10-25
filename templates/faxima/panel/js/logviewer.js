(function () {
    'use strict';

    var root = document.getElementById('lv');
    if (!root) return;

    var API = root.getAttribute('data-api');
    var CSRF = root.getAttribute('data-csrf');
    var MAX_RENDER = 1000;
    var PAGE = 50;
    var POLL_PAGES = 4;

    var LEVELS = [
        ['critical', 'بحرانی'], ['error', 'خطا'], ['warning', 'هشدار'], ['notice', 'اعلان'],
        ['info', 'اطلاعات'], ['debug', 'دیباگ'], ['unknown', 'نامشخص']
    ];
    var LEVEL_LABEL = {};
    LEVELS.forEach(function (l) { LEVEL_LABEL[l[0]] = l[1]; });
    var LEVEL_COLOR = {
        critical: 'var(--color-danger)', error: 'var(--color-danger)', warning: 'var(--color-warning)',
        notice: 'var(--color-info)', info: 'var(--color-info)', debug: 'var(--text-dim)', unknown: 'var(--text-dim)'
    };
    var AUTO_OPTIONS = [['0', 'خاموش'], ['5', '۵ ثانیه'], ['10', '۱۰ ثانیه'], ['30', '۳۰ ثانیه'], ['60', '۶۰ ثانیه']];
    var FMT_LABEL = {
        php: 'خطای PHP', api: 'API', client: 'مرورگر', ui: 'رفتار رابط', backup: 'بکاپ',
        payment: 'پرداخت', diag: 'عیب‌یابی', text: 'متنی', support: 'پشتیبان'
    };
    var ERR_TEXT = {
        permission: ['دسترسی خواندن ندارید', 'سرور اجازه خواندن این فایل را نمی‌دهد. مجوزهای فایل یا پوشه را در هاست بررسی کنید.'],
        binary: ['فایل باینری است', 'محتوای باینری به‌صورت متن نمایش داده نمی‌شود.'],
        missing: ['فایل پیدا نشد', 'ممکن است فایل حذف یا چرخانده شده باشد. فهرست فایل‌ها به‌روزرسانی شد.'],
        invalid: ['درخواست نامعتبر', 'فایل انتخاب‌شده معتبر نیست.'],
        unsupported: ['نمایش پشتیبانی نمی‌شود', 'این فایل جزو فایل‌های لاگ نیست.'],
        auth: ['نشست منقضی شده', 'برای ادامه دوباره وارد پنل شوید.'],
        forbidden: ['دسترسی مجاز نیست', 'آی‌پی شما اجازه دسترسی به این بخش را ندارد.'],
        network: ['خطا در ارتباط با سرور', 'اتصال برقرار نشد یا پاسخ نامعتبر بود. دوباره تلاش کنید.'],
        server: ['خطای سرور', 'پردازش درخواست با خطا مواجه شد.']
    };
    var STATUS_TEXT = {
        deleted: 'حذف شد', missing: 'از قبل وجود نداشت', permission: 'دسترسی لازم برای حذف وجود ندارد',
        failed: 'حذف انجام نشد', protected: 'فایل محافظت‌شده است', unsafe: 'مسیر ناایمن است'
    };
    var ICON = {
        alert: '<svg class="svg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        check: '<svg class="svg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        file: '<svg class="svg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        chev: '<svg class="svg-icon svg-sm lv-ev__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
        trash: '<svg class="svg-icon svg-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
        copy: '<svg class="svg-icon svg-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
    };

    var state = {
        files: [], counts: null, stats: null, sel: '*', view: 'errors', levels: {}, q: '', qTimer: null,
        events: [], keys: {}, next: null, cursors: null, more: false, pending: [], gap: false,
        scanned: 0, size: 0, mode: 'merge', mergeInfo: null, loading: false, seq: 0, hops: 0,
        auto: 10, timer: null, busy: false, lastRefresh: 0, statsSig: '', refreshSig: '', listError: null, refreshError: false
    };

    var el = {};
    ['lvStats', 'lvFilesList', 'lvFilesToggle', 'lvFilesBody', 'lvFilesCurrent', 'lvFileFilter', 'lvFileFilterClear', 'lvBulkDelete',
        'lvAutoBtn', 'lvAutoText', 'lvRefresh', 'lvExportBtn', 'lvExportPop', 'lvExportMenu', 'lvQ', 'lvSearchBox', 'lvClear', 'lvLevels',
        'lvLevelsNote', 'lvScope', 'lvNew', 'lvEvents', 'lvMore', 'lvDialog', 'lvDialogTitle', 'lvDialogBody', 'lvDialogCancel',
        'lvDialogOk', 'lvDialogForm', 'lvToast'
    ].forEach(function (id) { el[id] = document.getElementById(id); });

    function store(k, v) {
        try {
            if (v === undefined) return window.localStorage.getItem(k);
            window.localStorage.setItem(k, v);
        } catch (e) {}
        return null;
    }

    function h(tag, props, kids) {
        var n = document.createElement(tag);
        if (props) {
            Object.keys(props).forEach(function (k) {
                var v = props[k];
                if (v === null || v === undefined || v === false) return;
                if (k === 'class') n.className = v;
                else if (k === 'text') n.textContent = v;
                else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), v);
                else n.setAttribute(k, v === true ? '' : v);
            });
        }
        (kids || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return n;
    }

    function svg(markup) {
        var t = document.createElement('template');
        t.innerHTML = markup;
        return t.content.firstChild;
    }

    function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }

    function fa(n) {
        try { return Number(n).toLocaleString('fa-IR'); } catch (e) { return String(n); }
    }

    function fsize(b) {
        b = Number(b) || 0;
        if (b < 1024) return fa(b) + ' بایت';
        if (b < 1048576) return fa(Math.round(b / 102.4) / 10) + ' کیلوبایت';
        if (b < 1073741824) return fa(Math.round(b / 104857.6) / 10) + ' مگابایت';
        return fa(Math.round(b / 107374182.4) / 10) + ' گیگابایت';
    }

    function toast(msg) {
        el.lvToast.textContent = msg;
        el.lvToast.hidden = false;
        clearTimeout(toast.t);
        toast.t = setTimeout(function () { el.lvToast.hidden = true; }, 3800);
    }

    function qs(obj) {
        return Object.keys(obj).filter(function (k) { return obj[k] !== '' && obj[k] !== null && obj[k] !== undefined; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]); }).join('&');
    }

    function request(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, opts.headers || {});
        return fetch(url, opts).then(function (r) {
            return r.text().then(function (t) {
                var d = null;
                try { d = JSON.parse(t); } catch (e) {}
                if (!d) return { status: r.status, data: { ok: false, code: r.status === 401 ? 'auth' : (r.status === 403 ? 'forbidden' : 'server') } };
                return { status: r.status, data: d };
            });
        }, function () {
            return { status: 0, data: { ok: false, code: 'network' } };
        });
    }

    function get(params) { return request(API + '?' + qs(params)); }

    function post(params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        body.append('_csrf', CSRF);
        return request(API, { method: 'POST', body: body, headers: { 'X-CSRF-Token': CSRF } });
    }

    function isMerge() { return state.sel.charAt(0) === '*'; }

    function mergeSource() {
        if (state.sel === '*logs') return 'logs';
        if (state.sel === '*php') return 'php';
        return '';
    }

    function anyLevel() {
        var any = false;
        Object.keys(state.levels).forEach(function (k) { if (state.levels[k]) any = true; });
        return any;
    }

    function filterParams() {
        var lv = [];
        if (state.view === 'all') {
            Object.keys(state.levels).forEach(function (k) { if (state.levels[k]) lv.push(k); });
        }
        return { view: state.view, levels: lv.join(','), q: state.q };
    }

    function hasFilters() {
        return state.q !== '' || (state.view === 'all' && anyLevel());
    }

    function updateFilterUi() {
        el.lvClear.disabled = !hasFilters();
        el.lvSearchBox.classList.toggle('is-active', state.q !== '');
    }

    function fileById(id) {
        for (var i = 0; i < state.files.length; i++) if (state.files[i].id === id) return state.files[i];
        return null;
    }

    function logFiles() { return state.files.filter(function (f) { return f.kind === 'log'; }); }

    function selLabel() {
        if (state.sel === '*') return 'همه فایل‌ها';
        if (state.sel === '*logs') return 'لاگ‌های برنامه';
        if (state.sel === '*php') return 'error_log های PHP';
        return state.sel;
    }

    function stateCard(kind, title, text, actions) {
        return h('div', { class: 'lv-state' + (kind === 'err' ? ' lv-state--err' : '') }, [
            svg(kind === 'err' ? ICON.alert : (kind === 'ok' ? ICON.check : ICON.file)),
            h('h4', { text: title }),
            text ? h('p', { text: text }) : null
        ].concat(actions || []));
    }

    function showEventsState(card) {
        clear(el.lvEvents);
        clear(el.lvMore);
        el.lvEvents.appendChild(card);
    }

    function showSkeleton() {
        clear(el.lvEvents);
        clear(el.lvMore);
        for (var i = 0; i < 5; i++) el.lvEvents.appendChild(h('div', { class: 'lv-skel' }));
    }

    function errCard(code, retry) {
        var t = ERR_TEXT[code] || ERR_TEXT.server;
        var acts = [];
        if (code === 'auth') acts.push(h('a', { class: 'btn btn-primary btn-sm', href: 'login.php' }, ['ورود دوباره']));
        else if (retry) acts.push(h('button', { class: 'btn btn-outline btn-sm', type: 'button', onclick: retry }, ['تلاش دوباره']));
        return stateCard('err', t[0], t[1], acts);
    }

    function renderStats() {
        clear(el.lvStats);
        var s = state.stats;
        if (!s) {
            el.lvStats.appendChild(h('div', { class: 'lv-skel lv-stats-skel' }));
            return;
        }
        var sum = s.sum;
        var sc = s.scope;
        [
            ['فایل‌های لاگ', fa(sc.files_eligible), ''],
            ['رویدادهای خطا', fa(sum.error + sum.critical), 'lv-stat--err'],
            ['هشدارها', fa(sum.warning), 'lv-stat--warn'],
            ['رویدادهای بررسی‌شده', fa(sum.events), 'lv-stat--info']
        ].forEach(function (t) {
            el.lvStats.appendChild(h('div', { class: 'lv-stat ' + t[2] }, [
                h('span', { class: 'lv-stat__k', text: t[0] }), h('span', { class: 'lv-stat__v', text: t[1] })
            ]));
        });
        var note;
        if (sc.complete) {
            note = 'آمار کلی (مستقل از فایل و فیلتر انتخاب‌شده): همه ' + fa(sc.files_eligible) + ' فایل قابل‌خواندن (' + fsize(sc.bytes_eligible) + ') به‌طور کامل شمارش شد.';
        } else {
            var parts = [];
            if (sc.files_partial) parts.push(fa(sc.files_partial) + ' فایل فقط از انتهای آن (حداکثر ' + fsize(sc.per_file_bytes) + ') شمارش شد');
            if (sc.files_skipped_limit) parts.push(fa(sc.files_skipped_limit) + ' فایل به‌دلیل سقف تعداد بررسی نشد');
            if (sc.files_skipped_time) parts.push(fa(sc.files_skipped_time) + ' فایل به‌دلیل محدودیت زمانی بررسی نشد');
            if (sc.files_skipped_error) parts.push(fa(sc.files_skipped_error) + ' فایل به‌دلیل خطا بررسی نشد');
            note = 'آمار کلی نمونه‌برداری‌شده (مستقل از فایل و فیلتر انتخاب‌شده): ' + fa(sc.files_scanned) + ' از ' + fa(sc.files_eligible) + ' فایل بررسی شد؛ ' + parts.join('، ') + '. حجم بررسی‌شده ' + fsize(sc.bytes_scanned) + ' از ' + fsize(sc.bytes_eligible) + ' است و این اعداد مجموع کل تاریخچه نیستند.';
        }
        note += ' رویدادهای تکراری که ثبت نشده‌اند شمارش نمی‌شوند.';
        el.lvStats.appendChild(h('div', { class: 'lv-stat-note', text: note }));
    }

    function fileRow(f) {
        var st = state.stats && state.stats.files[f.id];
        var errs = st ? st.error + st.critical : 0;
        var tags = [h('span', { class: 'lv-tag', text: FMT_LABEL[f.fmt] || FMT_LABEL.text })];
        if (f.binary) tags.push(h('span', { class: 'lv-tag', text: 'باینری' }));
        if (!f.readable) tags.push(h('span', { class: 'lv-tag', text: 'بدون دسترسی' }));
        if (f.size === 0) tags.push(h('span', { class: 'lv-tag', text: 'خالی' }));
        var usable = f.readable && !f.binary && f.size > 0;
        return h('div', { class: 'lv-file' + (state.sel === f.id ? ' is-active' : ''), role: 'listitem' }, [
            h('button', {
                type: 'button', class: 'lv-file__btn', disabled: !usable, 'aria-current': state.sel === f.id ? 'true' : null,
                title: f.id, onclick: function () { selectFile(f.id); }
            }, [
                h('span', { class: 'lv-file__name' }, [h('b', { text: f.name }), errs > 0 ? h('span', { class: 'lv-count', title: 'خطا در بخش بررسی‌شده', text: fa(errs) }) : null]),
                h('span', { class: 'lv-file__meta' }, [
                    h('span', { class: 'lv-ltr', text: f.mtime_s }), h('span', { text: fsize(f.size) })
                ].concat(tags))
            ]),
            f.deletable ? h('button', {
                type: 'button', class: 'lv-file__del', 'aria-label': 'حذف ' + f.name, title: 'حذف دائمی',
                onclick: function (e) { confirmDeleteFile(f, e.currentTarget); }
            }, [svg(ICON.trash)]) : null
        ]);
    }

    function supportRow(f) {
        return h('div', { class: 'lv-file', role: 'listitem' }, [
            h('div', { class: 'lv-file__btn', title: f.id }, [
                h('span', { class: 'lv-file__name' }, [h('b', { text: f.name }), h('span', { class: 'lv-tag', text: 'محافظت‌شده' })]),
                h('span', { class: 'lv-file__meta' }, [h('span', { class: 'lv-ltr', text: f.mtime_s }), h('span', { text: fsize(f.size) })])
            ])
        ]);
    }

    function specialRow(id, label, count) {
        return h('div', { class: 'lv-file' + (state.sel === id ? ' is-active' : ''), role: 'listitem' }, [
            h('button', { type: 'button', class: 'lv-file__btn', 'aria-current': state.sel === id ? 'true' : null, onclick: function () { selectFile(id); } }, [
                h('span', { class: 'lv-file__name' }, [h('b', { text: label }), h('span', { class: 'lv-tag', text: fa(count) })])
            ])
        ]);
    }

    function group(label, n) {
        return h('div', { class: 'lv-group' }, [h('span', { text: label }), h('span', { text: fa(n) })]);
    }

    function renderFiles() {
        var q = el.lvFileFilter.value.trim().toLowerCase();
        el.lvFileFilterClear.hidden = el.lvFileFilter.value === '';
        clear(el.lvFilesList);
        var match = function (f) { return !q || f.id.toLowerCase().indexOf(q) !== -1; };
        var logs = state.files.filter(function (f) { return f.kind === 'log' && f.source === 'logs' && match(f); });
        var php = state.files.filter(function (f) { return f.kind === 'log' && f.source === 'php' && match(f); });
        var sup = state.files.filter(function (f) { return f.kind === 'support' && match(f); });
        var nLogs = state.files.filter(function (f) { return f.kind === 'log' && f.source === 'logs'; }).length;
        var nPhp = state.files.filter(function (f) { return f.kind === 'log' && f.source === 'php'; }).length;
        if (!q) {
            el.lvFilesList.appendChild(specialRow('*', 'همه فایل‌ها', nLogs + nPhp));
            if (nLogs && nPhp) {
                el.lvFilesList.appendChild(specialRow('*logs', 'فقط لاگ‌های برنامه', nLogs));
                el.lvFilesList.appendChild(specialRow('*php', 'فقط error_log های PHP', nPhp));
            }
        }
        if (logs.length) { el.lvFilesList.appendChild(group('لاگ‌های برنامه', logs.length)); logs.forEach(function (f) { el.lvFilesList.appendChild(fileRow(f)); }); }
        if (php.length) { el.lvFilesList.appendChild(group('error_log های PHP', php.length)); php.forEach(function (f) { el.lvFilesList.appendChild(fileRow(f)); }); }
        if (sup.length) { el.lvFilesList.appendChild(group('فایل‌های پشتیبان (غیرلاگ، فقط مشخصات)', sup.length)); sup.forEach(function (f) { el.lvFilesList.appendChild(supportRow(f)); }); }
        if (!logs.length && !php.length && !sup.length && q) el.lvFilesList.appendChild(h('div', { class: 'lv-group', text: 'فایلی پیدا نشد' }));
        el.lvFilesCurrent.textContent = selLabel();
        el.lvBulkDelete.disabled = (nLogs + nPhp) === 0;
        el.lvExportPop.querySelector('[data-export="raw"]').disabled = isMerge();
    }

    function renderLevels() {
        clear(el.lvLevels);
        var locked = state.view === 'errors';
        LEVELS.forEach(function (l) {
            var pressed = locked ? (l[0] === 'error' || l[0] === 'critical') : !!state.levels[l[0]];
            el.lvLevels.appendChild(h('button', {
                type: 'button', class: 'lv-level', 'aria-pressed': pressed ? 'true' : 'false', disabled: locked,
                style: '--lv-c:' + LEVEL_COLOR[l[0]],
                onclick: function () {
                    if (state.view !== 'all') return;
                    state.levels[l[0]] = !state.levels[l[0]];
                    renderLevels();
                    updateFilterUi();
                    resetEvents();
                }
            }, [l[1]]));
        });
        el.lvLevelsNote.textContent = locked
            ? 'در نمای «خطاها» فقط شدت‌های «خطا» و «بحرانی» اعمال می‌شود؛ برای انتخاب شدت‌های دیگر به «همه لاگ‌ها» بروید.'
            : (anyLevel() ? '' : 'بدون انتخاب شدت، همه شدت‌ها نمایش داده می‌شوند.');
    }

    function renderScope() {
        clear(el.lvScope);
        var parts = [];
        parts.push(h('span', {}, ['نمایش ', h('b', { text: fa(state.events.length) }), ' رویداد از ', h('b', { text: selLabel() })]));
        if (!isMerge() && state.size) {
            var done = state.next === null;
            parts.push(h('span', { text: 'بخش بررسی‌شده: ' + fsize(Math.max(0, state.size - state.scanned)) + ' از ' + fsize(state.size) + (done ? ' (تا ابتدای فایل)' : ' (انتهای فایل؛ موارد قدیمی‌تر هنوز بررسی نشده)') }));
        } else if (isMerge() && state.mergeInfo) {
            var mi = state.mergeInfo;
            var t = fa(mi.files_done) + ' از ' + fa(mi.files_total) + ' فایل تا ابتدا بررسی شده';
            t += state.more ? '؛ جستجو در دسته‌های بعدی ادامه می‌یابد (هر مرحله حداکثر ' + fa(25) + ' فایل)' : '؛ همه فایل‌های قابل‌خواندن بررسی شد';
            parts.push(h('span', { text: t }));
        }
        if (state.q !== '') parts.push(h('span', {}, ['جستجو: «', h('b', { text: state.q }), '»']));
        if (state.view === 'all' && anyLevel()) parts.push(h('span', { text: 'فیلتر شدت فعال است' }));
        if (state.refreshError) parts.push(h('span', { class: 'lv-bad', text: 'آخرین بازخوانی ناموفق بود' }));
        parts.forEach(function (p) { el.lvScope.appendChild(p); });
    }

    function renderMore() {
        clear(el.lvMore);
        var more = isMerge() ? state.more : state.next !== null;
        if (state.events.length >= MAX_RENDER) {
            el.lvMore.appendChild(h('span', { text: 'حداکثر ' + fa(MAX_RENDER) + ' رویداد نمایش داده می‌شود. برای دیدن موارد دیگر فیلتر را محدودتر کنید.' }));
            return;
        }
        if (more) {
            el.lvMore.appendChild(h('button', {
                type: 'button', class: 'btn btn-outline btn-sm', disabled: state.loading, onclick: function () { loadMore(); }
            }, [state.loading ? 'در حال بارگذاری…' : 'بارگذاری رویدادهای قدیمی‌تر']));
        } else if (state.events.length) {
            el.lvMore.appendChild(h('span', { text: 'به ابتدای محدوده قابل خواندن رسیدید' }));
        }
    }

    function copyText(text) {
        var done = function () { toast('کپی شد'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
        } else {
            legacyCopy(text);
            done();
        }
    }

    function legacyCopy(text) {
        var ta = h('textarea', { style: 'position:fixed;opacity:0;inset-block-start:0' });
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    function eventObject(ev) {
        var o = { time: ev.ts, level: ev.lv, source: ev.f, message: ev.msg };
        if (ev.file) o.file = ev.file;
        if (ev.line) o.line = ev.line;
        if (ev.trace && ev.trace.length) o.trace = ev.trace;
        if (ev.ctx) o.context = ev.ctx;
        return o;
    }

    function ctxValue(v) {
        if (v !== null && typeof v === 'object') return h('pre', { class: 'lv-pre lv-code', text: JSON.stringify(v, null, 2) });
        return h('span', { class: 'lv-ltr', text: String(v) });
    }

    function buildBody(ev) {
        var body = h('div', { class: 'lv-ev__body' });
        var view = h('div', {});
        var mode = 'detail';
        var btns = {};
        function render() {
            clear(view);
            Object.keys(btns).forEach(function (k) { btns[k].setAttribute('aria-pressed', k === mode ? 'true' : 'false'); });
            if (mode === 'json') {
                view.appendChild(h('pre', { class: 'lv-pre lv-code', text: JSON.stringify(eventObject(ev), null, 2) }));
                return;
            }
            if (mode === 'raw') {
                view.appendChild(h('pre', { class: 'lv-pre lv-code', text: ev.raw }));
                return;
            }
            var kv = h('dl', { class: 'lv-kv' });
            var add = function (k, v) { kv.appendChild(h('dt', { text: k })); kv.appendChild(h('dd', {}, [v])); };
            if (ev.ts) add('زمان', h('span', { class: 'lv-ltr', text: ev.ts }));
            add('شدت', h('span', { text: LEVEL_LABEL[ev.lv] || ev.lv }));
            add('فایل لاگ', h('span', { class: 'lv-ltr', text: ev.f }));
            if (ev.file) add('محل', h('span', { class: 'lv-ltr', text: ev.file + (ev.line ? ':' + ev.line : '') }));
            view.appendChild(kv);
            view.appendChild(h('div', { class: 'lv-sub', text: 'پیام کامل' }));
            view.appendChild(h('pre', { class: 'lv-pre lv-code', text: ev.msg || ev.raw }));
            if (ev.trace && ev.trace.length) {
                view.appendChild(h('div', { class: 'lv-sub', text: 'Stack trace' }));
                var ul = h('ul', { class: 'lv-trace lv-code' });
                ev.trace.forEach(function (t) { ul.appendChild(h('li', { text: t })); });
                view.appendChild(ul);
            }
            if (ev.ctx) {
                view.appendChild(h('div', { class: 'lv-sub', text: 'اطلاعات تکمیلی' }));
                var ckv = h('dl', { class: 'lv-kv' });
                Object.keys(ev.ctx).forEach(function (k) {
                    ckv.appendChild(h('dt', { class: 'lv-ltr', text: k }));
                    ckv.appendChild(h('dd', {}, [ctxValue(ev.ctx[k])]));
                });
                view.appendChild(ckv);
            }
            if (ev.cut) view.appendChild(h('div', { class: 'lv-sub', text: 'این رویداد بیش از حد طولانی بود و کوتاه شده است.' }));
        }
        var tabs = h('div', { class: 'lv-ev__tabs' });
        [['detail', 'جزئیات'], ['json', 'JSON'], ['raw', 'متن خام']].forEach(function (t) {
            btns[t[0]] = h('button', { type: 'button', class: 'lv-mini', onclick: function () { mode = t[0]; render(); } }, [t[1]]);
            tabs.appendChild(btns[t[0]]);
        });
        tabs.appendChild(h('button', { type: 'button', class: 'lv-mini lv-mini--end', onclick: function () { copyText(mode === 'json' ? JSON.stringify(eventObject(ev), null, 2) : (mode === 'raw' ? ev.raw : (ev.msg || ev.raw))); } }, [svg(ICON.copy), 'کپی']));
        body.appendChild(tabs);
        body.appendChild(view);
        render();
        return body;
    }

    function renderEvent(ev, fresh) {
        var first = (ev.msg || ev.raw || '').split('\n')[0];
        var meta = h('div', { class: 'lv-ev__meta' }, [
            ev.ts ? h('span', { class: 'lv-ltr', text: ev.ts }) : null,
            ev.ctx && ev.ctx.type ? h('span', { class: 'lv-tag lv-ltr', text: ev.ctx.type }) : null,
            h('span', { class: 'lv-ltr', text: ev.f.split('/').pop() }),
            ev.file ? h('span', { class: 'lv-ltr', text: ev.file + (ev.line ? ':' + ev.line : '') }) : null
        ]);
        var head = h('button', { type: 'button', class: 'lv-ev__head', 'aria-expanded': 'false' }, [
            h('span', { class: 'lv-sev', text: LEVEL_LABEL[ev.lv] || ev.lv }),
            h('span', { class: 'lv-ev__main' }, [h('span', { class: 'lv-ev__msg', text: first || '—' }), meta]),
            svg(ICON.chev)
        ]);
        var card = h('article', { class: 'lv-ev' + (fresh ? ' is-fresh' : ''), 'data-lv': ev.lv, 'data-k': ev.k }, [head]);
        var body = null;
        head.addEventListener('click', function () {
            var open = !card.classList.contains('is-open');
            if (open && !body) { body = buildBody(ev); card.appendChild(body); }
            card.classList.toggle('is-open', open);
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        return card;
    }

    function addEvents(list, atTop, fresh) {
        var room = atTop ? list.length : Math.max(0, MAX_RENDER - state.events.length);
        var added = [];
        for (var i = 0; i < list.length && added.length < room; i++) {
            var ev = list[i];
            if (state.keys[ev.k]) continue;
            state.keys[ev.k] = true;
            added.push(ev);
        }
        if (!added.length) return 0;
        if (el.lvEvents.querySelector('.lv-state, .lv-skel')) clear(el.lvEvents);
        var frag = document.createDocumentFragment();
        added.forEach(function (ev) { frag.appendChild(renderEvent(ev, fresh)); });
        if (atTop) {
            el.lvEvents.insertBefore(frag, el.lvEvents.firstChild);
            state.events = added.concat(state.events);
        } else {
            el.lvEvents.appendChild(frag);
            state.events = state.events.concat(added);
        }
        return added.length;
    }

    function emptyCard() {
        if (!logFiles().length) {
            return stateCard('ok', 'هنوز فایل لاگی وجود ندارد', 'به‌محض ثبت اولین رویداد، فایل‌ها به‌صورت خودکار اینجا نمایش داده می‌شوند.');
        }
        if (hasFilters()) {
            return stateCard('', 'رویدادی مطابق فیلترها پیدا نشد', 'عبارت جستجو یا شدت‌های انتخاب‌شده را تغییر دهید. جستجو فقط در بخش بررسی‌شده فایل انجام می‌شود.', [
                h('button', { type: 'button', class: 'btn btn-outline btn-sm', onclick: clearFilters }, ['پاک‌کردن فیلترها'])
            ]);
        }
        if (state.view === 'errors') {
            return stateCard('ok', 'خطایی در بخش بررسی‌شده ثبت نشده است', 'برای دیدن همه رویدادها به «همه لاگ‌ها» بروید.', [
                h('button', { type: 'button', class: 'btn btn-outline btn-sm', onclick: function () { setView('all'); } }, ['نمایش همه لاگ‌ها'])
            ]);
        }
        return stateCard('', 'رویدادی برای نمایش وجود ندارد', 'فایل انتخاب‌شده خالی است.');
    }

    function afterPage(res, first) {
        state.refreshError = false;
        if (res.mode === 'merge') {
            state.mode = 'merge';
            state.cursors = res.cursors;
            state.more = !!res.more;
            state.mergeInfo = { files_total: res.files_total, files_done: res.files_done };
        } else {
            state.mode = 'file';
            state.next = res.next === null || res.next === undefined ? null : res.next;
            state.size = res.size;
            state.scanned = first ? res.scanned_from : Math.min(state.scanned, res.scanned_from);
        }
        addEvents(res.events, false, false);
        if (!state.events.length) showEventsState(emptyCard());
        renderScope();
        renderMore();
        if (!state.events.length && ((isMerge() && state.more) || (!isMerge() && state.next !== null)) && res.events.length === 0 && state.hops < 6) {
            state.hops++;
            loadMore();
        } else {
            state.hops = 0;
        }
    }

    function pageParams(first, local) {
        var p = filterParams();
        p.action = 'read';
        p.limit = PAGE;
        if (isMerge()) {
            p.id = '*';
            p.source = mergeSource();
            var c = local ? local.cursors : state.cursors;
            if (!first && c) p.cursors = JSON.stringify(c);
        } else {
            p.id = state.sel;
            var n = local ? local.next : state.next;
            if (!first && n !== null && n !== undefined) p.before = n;
        }
        return p;
    }

    function resetEvents() {
        clearTimeout(state.qTimer);
        state.qTimer = null;
        state.seq++;
        var seq = state.seq;
        state.events = [];
        state.keys = {};
        state.pending = [];
        state.gap = false;
        state.next = null;
        state.cursors = null;
        state.more = false;
        state.scanned = 0;
        state.size = 0;
        state.hops = 0;
        state.loading = true;
        updatePill();
        showSkeleton();
        renderScope();
        return get(pageParams(true)).then(function (r) {
            if (seq !== state.seq) return null;
            state.loading = false;
            if (!r.data.ok) {
                clear(el.lvMore);
                showEventsState(errCard(r.data.code, function () { resetEvents(); }));
                if (r.data.code === 'missing') loadFiles().then(function () { if (!fileById(state.sel)) selectFile('*'); });
                return false;
            }
            afterPage(r.data, true);
            return true;
        });
    }

    function loadMore() {
        if (state.loading) return;
        state.loading = true;
        var seq = state.seq;
        renderMore();
        get(pageParams(false)).then(function (r) {
            if (seq !== state.seq) return;
            state.loading = false;
            if (!r.data.ok) {
                toast((ERR_TEXT[r.data.code] || ERR_TEXT.server)[0]);
                renderMore();
                return;
            }
            if (r.data.reset) { toast('فایل تغییر کرده است؛ فهرست دوباره بارگذاری شد'); resetEvents(); return; }
            afterPage(r.data, false);
        });
    }

    function updatePill() {
        var n = state.pending.length;
        el.lvNew.hidden = n === 0 && !state.gap;
        if (state.gap) el.lvNew.textContent = 'رویدادهای جدید زیادی ثبت شده — برای بارگذاری مجدد کلیک کنید';
        else if (n) el.lvNew.textContent = fa(n) + ' رویداد جدید — برای نمایش کلیک کنید';
    }

    function applyPending() {
        if (!state.pending.length && !state.gap) return;
        if (state.gap || state.events.length + state.pending.length > MAX_RENDER) {
            resetEvents();
            return;
        }
        var list = state.pending;
        state.pending = [];
        addEvents(list, true, true);
        updatePill();
        renderScope();
        renderMore();
    }

    function atTop() {
        return window.scrollY < 120;
    }

    function pollNew(manual) {
        var seq = state.seq;
        if (state.loading || !state.events.length) return Promise.resolve();
        var pend = {};
        state.pending.forEach(function (ev) { pend[ev.k] = true; });
        var collected = [];
        var gap = false;
        var local = { next: null, cursors: null };
        function step(pageNo) {
            return get(pageParams(pageNo === 0, local)).then(function (r) {
                if (seq !== state.seq) return 'stale';
                if (!r.data.ok) return r.data.code;
                var hitKnown = false;
                for (var i = 0; i < r.data.events.length; i++) {
                    var ev = r.data.events[i];
                    if (state.keys[ev.k]) { hitKnown = true; break; }
                    if (!pend[ev.k]) collected.push(ev);
                }
                var more = r.data.mode === 'merge' ? !!r.data.more : (r.data.next !== null && r.data.next !== undefined);
                if (hitKnown || !r.data.events.length || !more) return 'ok';
                if (pageNo + 1 >= POLL_PAGES) { gap = true; return 'ok'; }
                local.next = r.data.next;
                local.cursors = r.data.cursors;
                return step(pageNo + 1);
            });
        }
        return step(0).then(function (code) {
            if (code === 'stale' || seq !== state.seq) return;
            if (code !== 'ok') {
                if (code === 'missing' || code === 'binary' || code === 'permission') { state.refreshError = false; resetEvents(); return; }
                state.refreshError = true;
                renderScope();
                if (manual) toast((ERR_TEXT[code] || ERR_TEXT.server)[0]);
                return;
            }
            state.refreshError = false;
            if (gap) state.gap = true;
            if (collected.length) state.pending = collected.concat(state.pending);
            if (state.events.length + state.pending.length > MAX_RENDER) state.gap = true;
            if (state.gap || state.pending.length) {
                if (atTop() && !manual) applyPending();
                else {
                    updatePill();
                    if (manual) toast(state.gap ? 'رویدادهای جدید زیادی ثبت شده است' : fa(state.pending.length) + ' رویداد جدید؛ برای نمایش روی نشانگر کلیک کنید');
                }
            } else if (manual) {
                toast('رویداد جدیدی وجود ندارد');
            }
            renderScope();
        });
    }

    function sig(files) {
        return files.map(function (f) { return f.id + ':' + f.size + ':' + f.mtime + ':' + f.ino; }).join('|');
    }

    function detectChange(prev, now) {
        var pm = {};
        var nm = {};
        prev.forEach(function (f) { pm[f.id] = f; });
        now.forEach(function (f) { nm[f.id] = f; });
        var ids = [];
        if (isMerge()) {
            var seen = {};
            state.events.forEach(function (ev) { if (!seen[ev.f]) { seen[ev.f] = true; ids.push(ev.f); } });
        } else {
            ids = [state.sel];
        }
        var result = null;
        ids.forEach(function (id) {
            var p = pm[id];
            var n = nm[id];
            if (!n) { result = 'gone'; return; }
            if (p && (n.size < p.size || (p.ino && n.ino && p.ino !== n.ino)) && result === null) result = 'truncated';
        });
        return result;
    }

    function loadFiles() {
        return get({ action: 'list' }).then(function (r) {
            if (!r.data.ok) { state.listError = r.data.code; return { ok: false, code: r.data.code }; }
            state.listError = null;
            var prev = state.files;
            state.files = r.data.files;
            state.counts = r.data.counts;
            var changed = prev.length ? detectChange(prev, r.data.files) : null;
            renderFiles();
            return { ok: true, changed: changed };
        });
    }

    function loadStats() {
        return get({ action: 'stats' }).then(function (r) {
            if (r.data.ok) { state.stats = r.data; renderStats(); renderFiles(); return true; }
            if (!state.stats) {
                clear(el.lvStats);
                el.lvStats.appendChild(h('div', { class: 'lv-stat-note', text: 'محاسبه آمار ناموفق بود: ' + (ERR_TEXT[r.data.code] || ERR_TEXT.server)[0] }));
            }
            return false;
        });
    }

    function refresh(manual) {
        if (state.busy) return Promise.resolve();
        state.busy = true;
        el.lvRefresh.disabled = true;
        state.lastRefresh = Date.now();
        var finish = function () { state.busy = false; el.lvRefresh.disabled = false; };
        return loadFiles().then(function (res) {
            if (!res.ok) {
                state.refreshError = true;
                renderScope();
                if (res.code === 'auth' || res.code === 'forbidden') showEventsState(errCard(res.code));
                else if (manual) toast((ERR_TEXT[res.code] || ERR_TEXT.server)[0]);
                return;
            }
            var s = sig(logFiles());
            var statsP = null;
            if (s !== state.statsSig || manual) { state.statsSig = s; statsP = loadStats(); }
            if (res.changed === 'gone' && !isMerge()) {
                toast('فایل انتخاب‌شده دیگر وجود ندارد؛ نمای همه فایل‌ها نمایش داده شد');
                state.sel = '*';
                renderFiles();
                return Promise.all([resetEvents(), statsP]);
            }
            if (res.changed) {
                toast(res.changed === 'gone' ? 'یکی از فایل‌های نمایش‌داده‌شده حذف شد؛ فهرست دوباره بارگذاری شد' : 'فایل کوتاه یا چرخانده شد؛ فهرست دوباره بارگذاری شد');
                return Promise.all([resetEvents(), statsP]);
            }
            if (!state.events.length && !state.loading && s !== state.refreshSig) {
                state.refreshSig = s;
                return Promise.all([resetEvents(), statsP]);
            }
            state.refreshSig = s;
            return Promise.all([pollNew(manual), statsP]);
        }).then(finish, function () {
            state.refreshError = true;
            renderScope();
            finish();
        });
    }

    function schedule() {
        clearTimeout(state.timer);
        state.timer = null;
        if (!state.auto) return;
        state.timer = setTimeout(function () {
            state.timer = null;
            if (document.hidden) { schedule(); return; }
            refresh(false).then(schedule, schedule);
        }, state.auto * 1000);
    }

    function selectFile(id) {
        state.sel = id;
        renderFiles();
        if (window.matchMedia('(max-width: 899px)').matches) setFilesOpen(false);
        resetEvents();
    }

    function setView(v) {
        state.view = v;
        store('faoxima_lv_view', v);
        Array.prototype.forEach.call(document.querySelectorAll('.lv-tab'), function (b) {
            var on = b.getAttribute('data-view') === v;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        renderLevels();
        updateFilterUi();
        resetEvents();
    }

    function clearFilters() {
        clearTimeout(state.qTimer);
        state.qTimer = null;
        state.levels = {};
        state.q = '';
        el.lvQ.value = '';
        renderLevels();
        updateFilterUi();
        resetEvents();
    }

    function setFilesOpen(open) {
        el.lvFilesBody.classList.toggle('is-open', open);
        el.lvFilesToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    var dlg = { id: 0, onOk: null, trigger: null, busy: false, down: false };

    function outsideDialog(e) {
        var r = el.lvDialog.getBoundingClientRect();
        return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
    }

    function openDialog(cfg) {
        dlg.id++;
        dlg.busy = false;
        dlg.down = false;
        dlg.trigger = cfg.trigger || document.activeElement;
        el.lvDialogTitle.textContent = cfg.title;
        clear(el.lvDialogBody);
        cfg.body.forEach(function (n) { el.lvDialogBody.appendChild(n); });
        el.lvDialogOk.textContent = cfg.okText;
        el.lvDialogOk.hidden = false;
        el.lvDialogOk.disabled = !!cfg.okDisabled;
        el.lvDialogCancel.disabled = false;
        el.lvDialogCancel.textContent = 'انصراف';
        dlg.onOk = cfg.onOk;
        if (typeof el.lvDialog.showModal === 'function') {
            if (!el.lvDialog.open) el.lvDialog.showModal();
            el.lvDialogBody.scrollTop = 0;
            el.lvDialogCancel.focus();
        }
        return dlg.id;
    }

    function closeDialog() {
        if (dlg.busy) return;
        dlg.id++;
        if (el.lvDialog.open) el.lvDialog.close();
    }

    function setBusy(on) {
        dlg.busy = on;
        el.lvDialogCancel.disabled = on;
        if (on) el.lvDialogOk.disabled = true;
    }

    function dialogDone(nodes) {
        dlg.busy = false;
        clear(el.lvDialogBody);
        nodes.forEach(function (n) { el.lvDialogBody.appendChild(n); });
        el.lvDialogOk.hidden = true;
        el.lvDialogCancel.disabled = false;
        el.lvDialogCancel.textContent = 'بستن';
        dlg.onOk = null;
        if (document.activeElement === document.body || !el.lvDialog.contains(document.activeElement)) el.lvDialogCancel.focus();
    }

    function writerNote() {
        return h('div', { text: 'توجه: حذف فقط فایل‌های فعلی را پاک می‌کند. اگر بخشی از برنامه همچنان در حال ثبت لاگ باشد، همان فایل (یا فایل جدید روز) دوباره ساخته می‌شود.' });
    }

    function statusLine(kind, text, spin) {
        return h('div', { class: 'lv-dialog__status ' + (kind || '') }, [spin ? h('span', { class: 'lv-spin', 'aria-hidden': 'true' }) : null, h('span', { text: text })]);
    }

    function refreshAll(deletedIds) {
        var out = { files: false, stats: false, events: null, recreated: [] };
        return loadFiles().then(function (res) {
            if (!res.ok) return out;
            out.files = true;
            deletedIds.forEach(function (id) { if (fileById(id)) out.recreated.push(id); });
            var selGone = !isMerge() && !fileById(state.sel);
            if (selGone) state.sel = '*';
            renderFiles();
            state.statsSig = sig(logFiles());
            var jobs = [loadStats()];
            if (selGone || isMerge()) jobs.push(resetEvents());
            return Promise.all(jobs).then(function (rs) {
                out.stats = rs[0] === true;
                out.events = rs.length > 1 ? rs[1] : true;
                return out;
            });
        }, function () { return out; });
    }

    function refreshNodes(out, retry) {
        var nodes = [];
        if (out.files && out.stats && out.events !== false) {
            nodes.push(statusLine('lv-ok', 'فهرست فایل‌ها، آمار و رویدادها به‌روزرسانی شد.'));
        } else {
            nodes.push(statusLine('lv-warnc', 'حذف انجام شد، اما به‌روزرسانی صفحه کامل نشد' + (out.files ? '' : ' (فهرست فایل‌ها)') + (out.files && !out.stats ? ' (آمار)' : '') + (out.events === false ? ' (رویدادها)' : '') + '.'));
            nodes.push(h('button', { type: 'button', class: 'btn btn-outline btn-sm', onclick: retry }, ['تلاش دوباره برای به‌روزرسانی']));
        }
        if (out.recreated.length) {
            nodes.push(statusLine('lv-warnc', fa(out.recreated.length) + ' فایل پس از حذف دوباره توسط برنامه ساخته شد؛ این به معنی شکست حذف نیست، برنامه همچنان در حال ثبت لاگ است.'));
        }
        return nodes;
    }

    function runRefresh(my, base, ids) {
        dialogDone(base.concat([statusLine('', 'در حال به‌روزرسانی…', true)]));
        refreshAll(ids).then(function (out) {
            if (my !== dlg.id) return;
            dialogDone(base.concat(refreshNodes(out, function () { runRefresh(my, base, ids); })));
        });
    }

    function confirmDeleteFile(f, trigger) {
        var my = openDialog({
            title: 'حذف دائمی فایل لاگ',
            okText: 'حذف دائمی',
            trigger: trigger,
            body: [
                h('div', { text: 'فایل زیر برای همیشه از هاست حذف می‌شود و قابل بازیابی نیست:' }),
                h('pre', { class: 'lv-pre lv-code', text: f.id + '\n' + fsize(f.size) + ' — ' + f.mtime_s }),
                writerNote()
            ],
            onOk: function () {
                if (dlg.busy) return;
                setBusy(true);
                clear(el.lvDialogBody);
                el.lvDialogBody.appendChild(statusLine('', 'در حال حذف…', true));
                post({ action: 'delete_file', id: f.id }).then(function (r) {
                    if (my !== dlg.id) return;
                    var res = r.data.result;
                    var status = res ? res.status : (r.data.code || 'failed');
                    var good = status === 'deleted' || status === 'missing';
                    var base = [statusLine(good ? 'lv-ok' : 'lv-bad', (STATUS_TEXT[status] || (ERR_TEXT[status] && ERR_TEXT[status][0]) || 'خطا') + ': ' + f.name)];
                    if (!good) {
                        base.push(h('div', { text: 'فایل دست‌نخورده باقی ماند. مجوزهای فایل یا پوشه را بررسی کنید.' }));
                        dialogDone(base);
                        return;
                    }
                    runRefresh(my, base, status === 'deleted' ? [f.id] : []);
                });
            }
        });
    }

    function confirmBulk(trigger) {
        var scopes = [['logs', 'فقط لاگ‌های برنامه (پوشه logs)'], ['php', 'فقط فایل‌های error_log پی‌اچ‌پی'], ['all', 'همه منابع پشتیبانی‌شده']];
        var scope = 'all';
        var pv = { seq: 0, state: 'loading', count: 0, bytes: 0, snapshot: '', note: '' };
        var submitting = false;
        var info = h('div', { class: 'lv-pre' });
        var radios = [];
        var wrap = h('div', { class: 'lv-dialog__result', role: 'radiogroup', 'aria-label': 'محدوده حذف' });
        var my = 0;
        scopes.forEach(function (s) {
            var radio = h('input', { type: 'radio', name: 'lvScope', value: s[0] });
            if (s[0] === scope) radio.checked = true;
            radio.addEventListener('change', function () {
                if (submitting || !radio.checked) return;
                scope = s[0];
                loadPreview();
            });
            radios.push(radio);
            wrap.appendChild(h('label', { class: 'lv-dialog__radio' }, [radio, h('span', { text: s[1] })]));
        });
        function paint() {
            if (my !== dlg.id) return;
            clear(info);
            if (pv.state === 'loading') {
                info.appendChild(statusLine('', 'در حال محاسبه فهرست فایل‌ها…', true));
            } else if (pv.state === 'failed') {
                info.appendChild(statusLine('lv-bad', pv.note || 'دریافت فهرست فایل‌ها ناموفق بود.'));
                info.appendChild(h('button', { type: 'button', class: 'btn btn-outline btn-sm', onclick: function () { loadPreview(); } }, ['تلاش دوباره']));
            } else {
                if (pv.note) info.appendChild(statusLine('lv-warnc', pv.note));
                info.appendChild(h('div', { text: pv.count === 0 ? 'فایلی برای حذف وجود ندارد.' : fa(pv.count) + ' فایل (' + fsize(pv.bytes) + ') برای همیشه حذف خواهد شد.' }));
            }
            radios.forEach(function (r) { r.disabled = submitting; });
            el.lvDialogOk.disabled = submitting || pv.state !== 'ready' || pv.count === 0;
        }
        function loadPreview() {
            var id = ++pv.seq;
            var sc = scope;
            pv.state = 'loading';
            pv.note = '';
            pv.snapshot = '';
            paint();
            get({ action: 'delete_preview', scope: sc }).then(function (r) {
                if (my !== dlg.id || id !== pv.seq || sc !== scope) return;
                if (!r.data.ok) {
                    pv.state = 'failed';
                    pv.note = (ERR_TEXT[r.data.code] || ERR_TEXT.server)[0];
                } else {
                    pv.state = 'ready';
                    pv.count = r.data.count;
                    pv.bytes = r.data.bytes;
                    pv.snapshot = r.data.snapshot;
                }
                paint();
            });
        }
        my = openDialog({
            title: 'حذف دائمی همه لاگ‌ها',
            okText: 'حذف دائمی همه',
            okDisabled: true,
            trigger: trigger,
            body: [h('div', { text: 'محدوده حذف را انتخاب کنید. فایل‌ها از هاست پاک می‌شوند و قابل بازیابی نیستند. فایل‌های محافظت‌شده (htaccess، فایل‌های JSON وضعیت، آپلودهای موقت و فایل‌های برنامه) حذف نمی‌شوند.' }), wrap, h('div', {}, [info]), writerNote()],
            onOk: function () {
                if (submitting || pv.state !== 'ready' || pv.count === 0) return;
                submitting = true;
                setBusy(true);
                var sc = scope;
                var snap = pv.snapshot;
                radios.forEach(function (r) { r.disabled = true; });
                clear(info);
                info.appendChild(statusLine('', 'در حال حذف فایل‌ها…', true));
                post({ action: 'delete_bulk', scope: sc, confirm: '1', snapshot: snap }).then(function (r) {
                    if (my !== dlg.id) return;
                    var d = r.data;
                    submitting = false;
                    dlg.busy = false;
                    el.lvDialogCancel.disabled = false;
                    if (d.code === 'changed') {
                        pv.state = 'ready';
                        pv.count = d.count;
                        pv.bytes = d.bytes;
                        pv.snapshot = d.snapshot;
                        pv.note = 'مجموعه فایل‌های قابل‌حذف تغییر کرد. فهرست جدید را بررسی و دوباره تأیید کنید.';
                        paint();
                        return;
                    }
                    if (!d.summary) {
                        pv.state = 'failed';
                        pv.note = 'نتیجه حذف نامشخص است (' + (ERR_TEXT[d.code] || ERR_TEXT.server)[0] + '). فهرست فایل‌ها را دوباره بررسی کنید.';
                        paint();
                        loadFiles();
                        return;
                    }
                    var s = d.summary;
                    var nodes = [statusLine(s.deleted ? 'lv-ok' : '', fa(s.deleted) + ' فایل حذف شد')];
                    if (s.missing) nodes.push(h('div', { text: fa(s.missing) + ' فایل هنگام حذف دیگر وجود نداشت' }));
                    if (s.permission) nodes.push(statusLine('lv-bad', fa(s.permission) + ' فایل به‌دلیل نبود دسترسی حذف نشد'));
                    if (s.failed) nodes.push(statusLine('lv-bad', fa(s.failed) + ' فایل به‌دلیل خطا حذف نشد'));
                    if (s.protected || s.unsafe) nodes.push(statusLine('lv-bad', fa((s.protected || 0) + (s.unsafe || 0)) + ' فایل محافظت‌شده یا ناایمن رد شد'));
                    if (d.failures && d.failures.length) {
                        nodes.push(h('pre', { class: 'lv-pre lv-code', text: d.failures.map(function (x) { return x.id + ' — ' + (STATUS_TEXT[x.status] || x.status); }).join('\n') }));
                    }
                    runRefresh(my, nodes, d.deleted_ids || []);
                });
            }
        });
        loadPreview();
    }

    function popPlace(btn, pop) {
        pop.style.visibility = 'hidden';
        pop.hidden = false;
        pop.style.maxHeight = '';
        pop.style.minWidth = pop.classList.contains('lv-pop--menu') ? '' : btn.getBoundingClientRect().width + 'px';
        var r = btn.getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var vh = window.innerHeight;
        var below = vh - r.bottom - 12;
        var above = r.top - 12;
        var ph = pop.scrollHeight + 2;
        var up = ph > below && above > below;
        var maxH = Math.max(120, up ? above : below);
        pop.style.maxHeight = maxH + 'px';
        ph = Math.min(ph, maxH);
        var w = pop.offsetWidth;
        var left = Math.min(Math.max(8, r.right - w), Math.max(8, vw - w - 8));
        pop.style.left = left + 'px';
        pop.style.top = (up ? Math.max(8, r.top - ph - 6) : r.bottom + 6) + 'px';
        pop.style.visibility = '';
    }

    var pops = { current: null };

    function popClose(refocus) {
        var c = pops.current;
        if (!c) return;
        pops.current = null;
        c.pop.hidden = true;
        c.btn.setAttribute('aria-expanded', 'false');
        if (refocus) c.btn.focus();
    }

    function popOpen(btn, pop, onOpen) {
        if (pops.current && pops.current.pop !== pop) popClose(false);
        pops.current = { btn: btn, pop: pop };
        btn.setAttribute('aria-expanded', 'true');
        popPlace(btn, pop);
        if (onOpen) onOpen();
    }

    function popReposition() {
        if (pops.current) popPlace(pops.current.btn, pops.current.pop);
    }

    var autoPop = h('div', { class: 'lv-pop', role: 'listbox', tabindex: '-1', id: 'lvAutoPop', 'aria-label': 'فاصله به‌روزرسانی خودکار', hidden: true });
    var autoOpts = AUTO_OPTIONS.map(function (o, i) {
        var opt = h('div', { role: 'option', id: 'lvAutoOpt' + i, 'data-v': o[0], 'aria-selected': 'false', text: o[1] });
        opt.addEventListener('click', function () { chooseAuto(i); });
        autoPop.appendChild(opt);
        return opt;
    });
    var autoIndex = 0;

    function paintAuto() {
        autoOpts.forEach(function (o, i) {
            var cur = o.getAttribute('data-v') === String(state.auto);
            o.setAttribute('aria-selected', cur ? 'true' : 'false');
            if (cur) el.lvAutoText.textContent = AUTO_OPTIONS[i][1];
        });
    }

    function focusAutoOption(i) {
        autoIndex = (i + autoOpts.length) % autoOpts.length;
        autoOpts.forEach(function (o, k) { o.classList.toggle('is-focus', k === autoIndex); });
        autoPop.setAttribute('aria-activedescendant', autoOpts[autoIndex].id);
        autoOpts[autoIndex].scrollIntoView({ block: 'nearest' });
    }

    function setAuto(v) {
        var n = parseInt(v, 10) || 0;
        if (n === state.auto) return;
        state.auto = n;
        store('faoxima_lv_auto', String(n));
        paintAuto();
        schedule();
    }

    function chooseAuto(i) {
        setAuto(autoOpts[i].getAttribute('data-v'));
        popClose(true);
    }

    function openAuto() {
        popOpen(el.lvAutoBtn, autoPop, function () {
            var cur = 0;
            autoOpts.forEach(function (o, i) { if (o.getAttribute('data-v') === String(state.auto)) cur = i; });
            focusAutoOption(cur);
            autoPop.focus();
        });
    }

    function bindAuto() {
        document.body.appendChild(autoPop);
        el.lvAutoBtn.addEventListener('click', function () {
            if (pops.current && pops.current.pop === autoPop) popClose(true);
            else openAuto();
        });
        el.lvAutoBtn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); openAuto(); }
        });
        autoPop.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); focusAutoOption(autoIndex + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); focusAutoOption(autoIndex - 1); }
            else if (e.key === 'Home') { e.preventDefault(); focusAutoOption(0); }
            else if (e.key === 'End') { e.preventDefault(); focusAutoOption(autoOpts.length - 1); }
            else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); chooseAuto(autoIndex); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); popClose(true); }
            else if (e.key === 'Tab') { popClose(false); }
        });
    }

    function bindExport() {
        document.body.appendChild(el.lvExportPop);
        var items = function () { return Array.prototype.filter.call(el.lvExportPop.querySelectorAll('[role="menuitem"]'), function (b) { return !b.disabled; }); };
        var openMenu = function () { popOpen(el.lvExportBtn, el.lvExportPop, function () { var i = items(); if (i.length) i[0].focus(); }); };
        el.lvExportBtn.addEventListener('click', function () {
            if (pops.current && pops.current.pop === el.lvExportPop) popClose(true);
            else openMenu();
        });
        el.lvExportBtn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); openMenu(); }
        });
        el.lvExportPop.addEventListener('keydown', function (e) {
            var list = items();
            var idx = list.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); if (list.length) list[(idx + 1) % list.length].focus(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (list.length) list[(idx - 1 + list.length) % list.length].focus(); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); popClose(true); }
            else if (e.key === 'Tab') { popClose(false); }
        });
        Array.prototype.forEach.call(el.lvExportPop.querySelectorAll('[data-export]'), function (b) {
            b.addEventListener('click', function () { var k = b.getAttribute('data-export'); popClose(true); exportNow(k); });
        });
    }

    function exportNow(kind) {
        var p = filterParams();
        p.action = 'download';
        p.mode = kind;
        p.id = isMerge() ? '*' : state.sel;
        if (isMerge()) p.source = mergeSource();
        if (kind === 'raw' && isMerge()) { toast('برای دانلود متن، یک فایل مشخص را انتخاب کنید'); return; }
        var a = h('a', { href: API + '?' + qs(p), download: '' });
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    function bind() {
        el.lvFilesToggle.addEventListener('click', function () { setFilesOpen(el.lvFilesToggle.getAttribute('aria-expanded') !== 'true'); });
        el.lvFileFilter.addEventListener('input', renderFiles);
        el.lvFileFilterClear.addEventListener('click', function () { el.lvFileFilter.value = ''; renderFiles(); el.lvFileFilter.focus(); });
        el.lvFileFilter.addEventListener('keydown', function (e) { if (e.key === 'Escape' && el.lvFileFilter.value) { e.preventDefault(); el.lvFileFilter.value = ''; renderFiles(); } });
        el.lvBulkDelete.addEventListener('click', function (e) { confirmBulk(e.currentTarget); });
        el.lvRefresh.addEventListener('click', function () { refresh(true).then(schedule, schedule); });
        el.lvClear.addEventListener('click', function () { if (!el.lvClear.disabled) clearFilters(); });
        el.lvNew.addEventListener('click', function () {
            applyPending();
            var r = el.lvEvents.getBoundingClientRect();
            window.scrollTo({ top: Math.max(0, window.scrollY + r.top - 80), behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        });
        Array.prototype.forEach.call(document.querySelectorAll('.lv-tab'), function (b) {
            b.addEventListener('click', function () { if (state.view !== b.getAttribute('data-view')) setView(b.getAttribute('data-view')); });
        });
        el.lvQ.addEventListener('input', function () {
            state.q = el.lvQ.value.trim();
            updateFilterUi();
            clearTimeout(state.qTimer);
            state.qTimer = setTimeout(function () { state.qTimer = null; resetEvents(); }, 350);
        });
        bindAuto();
        bindExport();
        document.addEventListener('pointerdown', function (e) {
            var c = pops.current;
            if (c && !c.pop.contains(e.target) && !c.btn.contains(e.target)) popClose(false);
        });
        window.addEventListener('resize', popReposition);
        window.addEventListener('scroll', popReposition, true);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && state.auto && Date.now() - state.lastRefresh > state.auto * 1000) refresh(false);
            schedule();
        });
        el.lvDialogCancel.addEventListener('click', closeDialog);
        el.lvDialogForm.addEventListener('submit', function (e) { e.preventDefault(); });
        el.lvDialogOk.addEventListener('click', function () { if (dlg.onOk) dlg.onOk(); });
        el.lvDialog.addEventListener('pointerdown', function (e) { dlg.down = e.target === el.lvDialog && outsideDialog(e); });
        el.lvDialog.addEventListener('click', function (e) {
            if (e.target === el.lvDialog && dlg.down && outsideDialog(e)) closeDialog();
            dlg.down = false;
        });
        el.lvDialog.addEventListener('cancel', function (e) {
            if (dlg.busy) { e.preventDefault(); return; }
            dlg.id++;
        });
        el.lvDialog.addEventListener('close', function () {
            var t = dlg.trigger;
            dlg.trigger = null;
            dlg.onOk = null;
            if (t && document.contains(t) && !t.disabled) t.focus();
            else if (!el.lvBulkDelete.disabled) el.lvBulkDelete.focus();
            else el.lvRefresh.focus();
        });
    }

    function start() {
        state.listError = null;
        showSkeleton();
        loadFiles().then(function (res) {
            if (!res.ok) {
                clear(el.lvStats);
                showEventsState(errCard(res.code, start));
                return;
            }
            state.statsSig = sig(logFiles());
            state.refreshSig = state.statsSig;
            loadStats();
            resetEvents();
            state.lastRefresh = Date.now();
            schedule();
        });
    }

    function init() {
        var a = store('faoxima_lv_auto');
        if (a !== null && ['0', '5', '10', '30', '60'].indexOf(a) !== -1) state.auto = parseInt(a, 10);
        paintAuto();
        var v = store('faoxima_lv_view');
        if (v === 'all') state.view = 'all';
        Array.prototype.forEach.call(document.querySelectorAll('.lv-tab'), function (b) {
            var on = b.getAttribute('data-view') === state.view;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        bind();
        renderStats();
        renderLevels();
        updateFilterUi();
        start();
    }

    init();
}());
