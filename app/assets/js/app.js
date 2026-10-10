(function(){
'use strict';
var tg = window.Telegram && window.Telegram.WebApp;
if (tg) {
  tg.ready(); tg.expand();
  try { tg.setHeaderColor('#0b1220'); tg.setBackgroundColor('#0b1220'); } catch(e){}
  /* همگام‌سازی با تم تلگرام */
  try {
    var p = tg.themeParams || {};
    if (p.bg_color) document.documentElement.style.setProperty('--bg', p.bg_color);
    if (p.text_color) document.documentElement.style.setProperty('--txt', p.text_color);
    if (p.hint_color) document.documentElement.style.setProperty('--muted', p.hint_color);
    if (p.button_color) document.documentElement.style.setProperty('--acc', p.button_color);
  } catch(e){}
}
/* ===== تم: تیره / روشن / خودکار (تلگرام + سیستم‌عامل) ===== */
function curTheme(){
  try { return localStorage.getItem('mx_theme') || 'auto'; } catch(e){ return 'auto'; }
}
function applyAutoTheme(){
  var t = null;
  try { t = localStorage.getItem('mx_theme'); } catch(e){}
  if (t === 'light') { document.body.classList.add('light'); return; }
  if (t === 'dark') { document.body.classList.remove('light'); return; }
  // خودکار: اولویت با colorScheme تلگرام، بعد prefers-color-scheme مرورگر
  var scheme = (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.colorScheme) || '';
  if (!scheme && window.matchMedia) scheme = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  document.body.classList.toggle('light', scheme === 'light');
}
applyAutoTheme();
try {
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: light)');
    var onScheme = function(){ 
      try { if (!localStorage.getItem('mx_theme')) applyAutoTheme(); } catch(e){}
    };
    if (mq.addEventListener) mq.addEventListener('change', onScheme);
    else if (mq.addListener) mq.addListener(onScheme);
  }
} catch(e){}

var API = window.__MINIAPP_API__ || 'api.php';
var initData = (tg && tg.initData) ? tg.initData
  : (new URLSearchParams(location.search).get('initData') || '');

function $(s){ return document.querySelector(s); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function fmt(n){ try { return Number(n||0).toLocaleString('fa-IR'); } catch(e){ return n; } }
function haptic(t){ try { tg && tg.HapticFeedback && tg.HapticFeedback.impactOccurred(t||'light'); } catch(e){} }

function call(action, opts){
  opts = opts || {};
  var method = opts.method || 'GET';
  var url = API + '?action=' + encodeURIComponent(action) + '&initData=' + encodeURIComponent(initData);
  var body = null;
  if (method === 'POST') {
    body = new URLSearchParams(opts.data || {});
    body.append('initData', initData);
  } else if (opts.data) {
    for (var k in opts.data) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.data[k]);
  }
  return fetch(url, { method: method, body: body }).then(function(r){ return r.json(); });
}

/* ===== Toast نوع‌دار ===== */
function toast(msg, kind){
  var t = document.createElement('div');
  t.className = 'toast' + (kind ? ' ' + kind : '');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function(){
    t.style.transition = 'opacity .25s, transform .25s';
    t.style.opacity = '0'; t.style.transform = 'translate(-50%,8px)';
    setTimeout(function(){ t.remove(); }, 260);
  }, 2200);
}
function ok(m){ toast(m, 'ok'); haptic('success'); }
function bad(m){ toast(m, 'bad'); haptic('error'); }

/* ===== دیالوگ تأیید داخلی (به‌جای confirm بومی) ===== */
function askConfirm(title, desc, onYes){
  var wrap = document.createElement('div');
  wrap.className = 'dlg';
  wrap.innerHTML =
    '<div class="dlg-box"><h4>' + esc(title) + '</h4><p>' + esc(desc) + '</p>' +
    '<div class="dlg-acts">' +
      '<button class="btn btn-ghost" data-x="no">انصراف</button>' +
      '<button class="btn btn-bad" data-x="yes">بله، انجام بده</button>' +
    '</div></div>';
  document.body.appendChild(wrap);
  function close(){ wrap.remove(); }
  wrap.addEventListener('click', function(e){
    var x = e.target.getAttribute && e.target.getAttribute('data-x');
    if (x === 'yes') { close(); onYes(); }
    else if (e.target === wrap || x === 'no') close();
  });
}

/* ===== کپی در کلیپ‌بورد ===== */
function copyText(text, label){
  function fallback(){
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); ok('کپی شد ✅'); }
    catch(e){ bad('کپی ناموفق'); }
    ta.remove();
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(function(){ ok(label || 'کپی شد ✅'); }, fallback);
  } else fallback();
}

/* ===== تاریخ کمکی ===== */
function isoDate(d){
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}
function daysAgo(n){
  var d = new Date(); d.setDate(d.getDate() - n); return isoDate(d);
}

/* ===== قالب‌بندی حجم دیسک (خواناتر از «۱۹۶۸۴ مگابایت») ===== */
function humanSize(bytes){
  var b = Number(bytes) || 0;
  if (b <= 0) return 'نامشخص';
  var units = ['بایت','کیلوبایت','مگابایت','گیگابایت','ترابایت'];
  var i = Math.floor(Math.log(b) / Math.log(1024));
  if (i > units.length - 1) i = units.length - 1;
  var v = b / Math.pow(1024, i);
  return (Math.round(v * 10) / 10).toLocaleString('fa-IR', {maximumFractionDigits: 1}) + ' ' + units[i];
}

var TABS = {dashboard:'داشبورد', bots:'ربات‌ها', pending:'درخواست‌ها', payments:'پرداخت‌ها', users:'کاربران', templates:'قالب‌ها', update:'بروزرسانی', settings:'تنظیمات'};
var SUBS = {
  dashboard:'نمای کلی ربات‌ساز',
  bots:'مدیریت ربات‌های ساخته‌شده',
  pending:'درخواست‌های در انتظار تأیید',
  payments:'مالی و تراکنش‌ها',
  users:'کاربران و دسترسی‌ها',
  templates:'قالب‌های نصب‌شده روی سرور',
  update:'بروزرسانی سورس قالب‌ها و ربات‌ها',
  settings:'پیکربندی کلی ربات'
};
var current = 'dashboard';
var lastDetail = '';
function goTab(name){
  document.querySelectorAll('.tabbar .tab').forEach(function(x){
    x.classList.toggle('active', x.dataset.tab === name);
  });
  current = name;
  var t = $('#pageTitle'), s = $('#pageSub');
  if (t) t.textContent = TABS[name] || '';
  if (s) s.textContent = SUBS[name] || '';
  haptic();
  render();
}
document.querySelectorAll('.tabbar .tab').forEach(function(b){
  b.addEventListener('click', function(){ goTab(b.dataset.tab); });
});
$('#btnRefresh').addEventListener('click', function(){ haptic(); spinRefresh(); render(); });
function spinRefresh(){
  var b = $('#btnRefresh'); if (!b) return;
  b.style.transition = 'transform .6s cubic-bezier(.22,1,.36,1)';
  b.style.transform = 'rotate(360deg)';
  setTimeout(function(){ b.style.transition = 'none'; b.style.transform = 'none'; }, 620);
}

/* نقطه‌های اعلان روی تب‌ها */
function setDot(id, n){
  var el = $(id); if (!el) return;
  if (n > 0) { el.textContent = n > 99 ? '99+' : n; el.classList.add('on'); }
  else el.classList.remove('on');
}
function refreshDots(){
  call('dashboard').then(function(d){
    if (!d || !d.ok) return;
    setDot('#dotPending', d.pending);
    setDot('#dotPay', d.open_payments);
  }).catch(function(){});
}

/* کشیدن به پایین برای بروزرسانی */
(function(){
  var ptr = $('#ptr'), startY = 0, pulling = false;
  window.addEventListener('touchstart', function(e){ startY = e.touches[0].clientY; }, {passive:true});
  window.addEventListener('touchmove', function(e){
    if (!ptr) return;
    var dy = e.touches[0].clientY - startY;
    if (dy > 70 && !pulling) {
      pulling = true;
      ptr.style.transform = 'translate(-50%,10px)';
      ptr.classList.add('spin');
      haptic('medium');
    }
  }, {passive:true});
  window.addEventListener('touchend', function(){
    if (!pulling || !ptr) return;
    pulling = false;
    ptr.style.transform = 'translate(-50%,-60px)';
    ptr.classList.remove('spin');
    spinRefresh();
    render();
    refreshDots();
  });
})();

/* شمارندهٔ نرم برای اعداد */
function countUp(el, to){
  var from = 0, dur = 600, t0 = performance.now();
  function step(t){
    var k = Math.min(1, (t - t0) / dur);
    var e = 1 - Math.pow(1 - k, 3);
    el.textContent = fmt(Math.round(from + (to - from) * e));
    if (k < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}
/* نمودار میله‌ای با برچسب و تولتیپ */
function chart(html, green){
  return '<div class="chart">'+html+'</div>';
}
function bar(value, max, dateLbl, green){
  var hh = Math.max(3, Math.round(88 * (Number(value) || 0) / (max || 1)));
  return '<div class="col">' +
    '<div class="bar' + (green ? ' green' : '') + '" data-v="' + fmt(value) + '" style="height:' + hh + 'px"></div>' +
    '<div class="lbl">' + esc(String(dateLbl).slice(5)) + '</div></div>';
}
function stat(ic, label, val, id){
  var n = Number(val) || 0;
  return '<div class="stat tap"><div class="ic">'+ic+'</div><b data-count="'+n+'">'+fmt(n)+'</b><span>'+label+'</span></div>';
}
function animateStats(){
  document.querySelectorAll('[data-count]').forEach(function(el){
    countUp(el, Number(el.getAttribute('data-count')) || 0);
  });
}
var helpModal = $('#helpModal');
if ($('#btnHelp')) $('#btnHelp').addEventListener('click', function(){ helpModal.style.display = 'flex'; haptic(); });
if ($('#btnHelpClose')) $('#btnHelpClose').addEventListener('click', function(){ helpModal.style.display = 'none'; });
if (helpModal) helpModal.addEventListener('click', function(e){ if (e.target === helpModal) helpModal.style.display = 'none'; });

function loading(){
  $('#view').innerHTML =
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px"><div class="skel" style="height:76px"></div><div class="skel" style="height:76px"></div></div>' +
    '<div class="skel"></div><div class="skel"></div><div class="skel"></div>';
}
function err(m){ $('#view').innerHTML = '<div class="card" style="text-align:center;color:#f87171">❌ ' + esc(m) + '</div>'; }
function statLegacy(ic,label,val){ return stat(ic,label,val); }

var SUBS_LOADED = {};
function render(){
  var t = $('#pageTitle'), s = $('#pageSub');
  if (t) t.textContent = TABS[current] || '';
  if (s) s.textContent = SUBS[current] || '';
  loading();
  var map = {dashboard:vDashboard, bots:vBots, pending:vPending, payments:vPayments, users:vUsers, templates:vTemplates, update:vUpdate, settings:vSettings};
  (map[current] || vDashboard)();
  if (!SUBS_LOADED[current]) { refreshDots(); SUBS_LOADED[current] = true; }
}

/* ---------- داشبورد ---------- */
function vDashboard(){
  call('dashboard').then(function(d){
    if (!d.ok) return err(d.msg || 'خطا');
    var byType = (d.by_type||[]).map(function(r){ return '<div class="kv"><b>'+esc(r.type)+'</b><span>'+fmt(r.c)+' عدد</span></div>'; }).join('') || '<div style="color:var(--muted);font-size:12px">—</div>';
    var bots = (d.recent_bots||[]).map(function(b){
      return '<div class="row"><div><div class="t">@'+esc(b.bot_username||b.folder)+'</div><div class="s">'+esc(b.type)+' • '+esc(b.created_at||'')+'</div></div>' +
             '<span class="badge '+(b.status==='active'?'b-ok':'b-bad')+'">'+(b.status==='active'?'فعال':'غیرفعال')+'</span></div>';
    }).join('') || '<div class="empty">هنوز رباتی ساخته نشده</div>';
    var pays = (d.recent_payments||[]).map(function(p){
      return '<div class="row"><div><div class="t">#'+p.id+' • '+esc(p.kind==='limit'?'اسلات':(p.template||'—'))+'</div><div class="s">کاربر '+p.user_id+' • '+esc(p.method||'—')+'</div></div><span class="badge b-warn">'+fmt(p.amount)+' ت</span></div>';
    }).join('') || '<div style="color:var(--muted);font-size:12px">پرداخت در انتظار نداریم</div>';
    $('#view').innerHTML =
      ((d.pending > 0 || d.open_payments > 0)
        ? '<div class="card" style="border-color:rgba(245,158,11,.5)"><h3>🔔 نیاز به توجه شما</h3>' +
          (d.pending > 0 ? '<div class="kv"><b>📥 درخواست ساخت معلق</b><span>'+fmt(d.pending)+'</span></div>' : '') +
          (d.open_payments > 0 ? '<div class="kv"><b>🧾 پرداخت منتظر تأیید</b><span>'+fmt(d.open_payments)+'</span></div>' : '') + '</div>'
        : '') +
      '<div class="grid2">' +
        stat('🤖','ربات‌ها', d.bots) + stat('👥','کاربران', d.users) +
        stat('📥','درخواست معلق', d.pending) + stat('🧾','پرداخت باز', d.open_payments) +
      '</div>' +
      '<div class="card" style="margin-top:12px;text-align:center"><div style="font-size:12px;color:var(--muted)">💰 مجموع درآمد تأییدشده</div>' +
        '<div style="font-size:26px;font-weight:700;margin-top:6px;background:linear-gradient(135deg,#a5b4fc,#d8b4fe);-webkit-background-clip:text;background-clip:text;color:transparent">'+fmt(d.revenue)+' تومان</div></div>' +
      '<div class="card"><h3>📈 درآمد ۱۴ روز اخیر</h3><div id="revChart" style="color:var(--muted);font-size:12px">…</div></div>' +
      '<div class="card"><h3>👥 کاربران جدید ۱۴ روز اخیر</h3><div id="userChart" style="color:var(--muted);font-size:12px">…</div></div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">' +
        '<button class="btn btn-acc" onclick="MX.goTab(\'pending\')">📥 درخواست‌ها</button>' +
        '<button class="btn btn-ghost" onclick="MX.goTab(\'payments\')">💳 پرداخت‌ها</button>' +
      '</div>' +
      '<div class="card"><h3>📊 توزیع قالب‌ها</h3>'+byType+'</div>' +
      '<div class="card"><h3>⏳ پرداخت‌های منتظر تأیید</h3>'+pays+'</div>' +
      '<div class="card"><h3>🆕 آخرین ربات‌ها</h3>'+bots+'</div>';
    call('revenue_daily').then(function(r){
      if (!r || !r.ok || !r.days || !r.days.length) { var el = $('#revChart'); if (el) el.innerHTML = 'هنوز داده‌ای نیست'; return; }
      var max = 1;
      r.days.forEach(function(d){ max = Math.max(max, Number(d.total)||0); });
      var bars = r.days.map(function(d){ return bar(d.total, max, d.d); }).join('');
      $('#revChart').innerHTML = chart(bars);
    });
    call('users_growth').then(function(r){
      if (!r || !r.ok || !r.days || !r.days.length) { var el2 = $('#userChart'); if (el2) el2.innerHTML = 'هنوز داده‌ای نیست'; return; }
      var max = 1;
      r.days.forEach(function(d){ max = Math.max(max, Number(d.c)||0); });
      var bars = r.days.map(function(d){ return bar(d.c, max, d.d, true); }).join('');
      $('#userChart').innerHTML = chart(bars, true);
    });
    animateStats();
  }).catch(function(){ err('ارتباط برقرار نشد — آیا داخل تلگرام باز کردی؟'); });
}

/* ---------- ربات‌ها ---------- */
var botsCache = [];
var botPage = 1, botTotal = 0;
function debounce(fn, ms){
  var t;
  return function(){
    var args = arguments, self = this;
    clearTimeout(t);
    t = setTimeout(function(){ fn.apply(self, args); }, ms || 220);
  };
}
function vBots(){
  botPage = 1; botTotal = 0; botsCache = [];
  $('#view').innerHTML = '<div class="search"><input id="botQ" type="search" placeholder="🔎 جستجوی ربات یا کاربر…" autocomplete="off"></div><div id="botList"></div><div id="botMore"></div>';
  $('#botQ').addEventListener('input', debounce(function(){ drawBots(this.value); }, 180));
  loadBotsPage();
}
function loadBotsPage(){
  setMoreBusy(true);
  call('bots_list', {data:{page:botPage, per:30}}).then(function(d){
    setMoreBusy(false);
    if (!d.ok) return err(d.msg);
    botsCache = botsCache.concat(d.bots); botTotal = d.total;
    drawBots(($('#botQ')||{}).value || '');
    updatePager('#botMore', botsCache.length, botTotal, 'MX.moreBots()');
  }).catch(function(){ setMoreBusy(false); err('خطا در دریافت'); });
}
/* ===== صفحه‌بندی: هم «نمایش بیشتر» و هم شماره‌صفحه + اسکرول بی‌نهایت ===== */
function updatePager(sel, loaded, total, fn){
  var el = $(sel); if (!el) return;
  if (loaded >= total) { el.innerHTML = loaded > 0 ? '<div class="pager-info">همهٔ '+fmt(total)+' مورد نمایش داده شد</div>' : ''; return; }
  var per = 30;
  var pages = Math.ceil(total / per);
  var cur = Math.min(pages, Math.max(1, Math.ceil(loaded / per)));
  var nums = [];
  var start = Math.max(1, cur - 2), end = Math.min(pages, cur + 2);
  for (var i = start; i <= end; i++) nums.push(i);
  var html = '<div class="pager-info">نمایش '+fmt(loaded)+' از '+fmt(total)+' مورد • صفحهٔ '+fmt(cur)+' از '+fmt(pages)+'</div>';
  html += '<div class="pager">';
  html += '<button data-go="1" '+(cur<=1?'disabled':'')+'>»</button>';
  html += '<button data-go="'+(cur-1)+'" '+(cur<=1?'disabled':'')+'>‹</button>';
  if (start > 1) html += '<span class="gap">…</span>';
  nums.forEach(function(n){
    html += '<button data-go="'+n+'" class="'+(n===cur?'cur':'')+'">'+fmt(n)+'</button>';
  });
  if (end < pages) html += '<span class="gap">…</span>';
  html += '<button data-go="'+(cur+1)+'" '+(cur>=pages?'disabled':'')+'>›</button>';
  html += '<button data-go="'+pages+'" '+(cur>=pages?'disabled':'')+'>»</button>';
  html += '</div>';
  html += '<button class="btn btn-ghost loadmore" id="moreBtn" onclick="' + fn + '">⬇️ نمایش بیشتر<span class="more-spin"></span></button>';
  el.innerHTML = html;
  el.querySelectorAll('[data-go]').forEach(function(b){
    b.addEventListener('click', function(){
      var n = Number(b.getAttribute('data-go')) || 1;
      var target = (n - 1) * per;
      while (loaded < target && !loadingMore) { fn(); break; }
      window.scrollTo({top:0, behavior:'smooth'});
    });
  });
}

/* وضعیت بارگذاری بیشتر + اسکرول بی‌نهایت */
var loadingMore = false;
function setMoreBusy(on){
  loadingMore = on;
  var b = $('#moreBtn');
  if (b) b.classList.toggle('busy', on);
}
var nearBottomOnce = false;
window.addEventListener('scroll', function(){
  if (loadingMore || nearBottomOnce) return;
  var b = $('#moreBtn');
  if (!b) return;
  var y = window.innerHeight + window.scrollY;
  if (y >= document.body.offsetHeight - 320) {
    nearBottomOnce = true;
    b.click();
    setTimeout(function(){ nearBottomOnce = false; }, 900);
  }
}, {passive:true});
function drawBots(q){
  q = (q||'').trim();
  var rows = botsCache.filter(function(b){
    if (!q) return true;
    return (b.bot_username||'').indexOf(q) >= 0 || (b.folder||'').indexOf(q) >= 0 ||
           (b.type||'').indexOf(q) >= 0 || String(b.owner_id).indexOf(q) >= 0 ||
           (b.first_name||'').indexOf(q) >= 0 || (b.username||'').indexOf(q) >= 0;
  });
  $('#botList').innerHTML = rows.length ? rows.map(function(b){
    return '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center">' +
      '<div><b class="copyable" onclick="MX.copyText(\'@' + esc(b.bot_username || b.folder) + '\')">@' + esc(b.bot_username || b.folder) + '</b>' +
      '<div class="s" style="color:var(--muted);font-size:11px;margin-top:3px">' + esc(b.type) + ' • مالک: ' + esc(b.first_name||b.username||b.owner_id) + '</div></div>' +
      '<span class="badge ' + (b.status==='active'?'b-ok':'b-bad') + '">' + (b.status==='active'?'فعال':'غیرفعال') + '</span></div>' +
      '<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">' +
        (b.status==='active'
          ? '<button class="btn btn-ghost btn-sm" onclick="MX.setStatus(' + b.id + ',\'disabled\')">⏸ غیرفعال</button>'
          : '<button class="btn btn-ok btn-sm" onclick="MX.setStatus(' + b.id + ',\'active\')">▶️ فعال‌سازی</button>') +
        '<button class="btn btn-ghost btn-sm" onclick="MX.botDetail(' + b.id + ')">🔍 جزئیات</button>' +
        '<button class="btn btn-bad btn-sm" onclick="MX.delBot(' + b.id + ')">🗑 حذف</button></div></div>';
  }).join('') : '<div class="empty"><div class="em-ic">🔍</div><div>موردی یافت نشد</div></div>';
}

/* ---------- درخواست‌ها ---------- */
function vPending(){
  call('pending_list').then(function(d){
    if (!d.ok) return err(d.msg);
    var pend = d.requests.filter(function(r){return r.status==='pending';});
    if (!pend.length) return $('#view').innerHTML = '<div class="empty">درخواست معلقی نیست ✅</div>';
    $('#view').innerHTML = pend.map(function(r){
      return '<div class="card"><div style="display:flex;gap:11px;align-items:center"><div class="avatar">📥</div><div><div class="t">کاربر <b>'+r.user_id+'</b></div><div class="s" style="color:var(--muted);font-size:11px;margin-top:3px">قالب: '+esc(r.type)+' • '+esc(r.created_at)+'</div></div></div>' +
        '<div style="display:flex;gap:8px;margin-top:13px">' +
        '<button class="btn btn-ok btn-sm" onclick="MX.req('+r.id+',\'approve\')" style="flex:1">✅ تأیید</button>' +
        '<button class="btn btn-bad btn-sm" onclick="MX.req('+r.id+',\'decline\')" style="flex:1">❌ رد</button></div></div>';
    }).join('');
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- پرداخت‌ها ---------- */
var payFilter = '';
var payCache = [];
var payPage = 1, payTotal = 0;
var payFrom = '', payTo = '';
function vPayments(){
  payPage = 1; payTotal = 0; payCache = [];
  var tabs = '<div class="chips">' +
    [['','همه'],['pending','در انتظار'],['await_admin','منتظر تأیید'],['await_receipt','منتظر فیش'],['paid','موفق'],['declined','ردشده']].map(function(p){
      return '<button class="chip'+(payFilter===p[0]?' active':'')+'" onclick="MX.setPayFilter(\''+p[0]+'\')">'+p[1]+'</button>';
    }).join('') + '</div>' +
    '<div class="daterange">' +
      '<button class="mini-chip" onclick="MX.quickDate(\'today\')">امروز</button>' +
      '<button class="mini-chip" onclick="MX.quickDate(\'7d\')">۷ روز</button>' +
      '<button class="mini-chip" onclick="MX.quickDate(\'30d\')">۳۰ روز</button>' +
      '<button class="mini-chip" onclick="MX.quickDate(\'month\')">این ماه</button>' +
      '<button class="mini-chip" onclick="MX.quickDate(\'clear\')">پاک کردن</button>' +
    '</div>' +
    '<div class="daterange">' +
      '<input id="payFrom" type="date" value="'+esc(payFrom)+'">' +
      '<input id="payTo" type="date" value="'+esc(payTo)+'">' +
      '<button class="btn btn-ghost btn-sm" onclick="MX.applyDates()">🔎 اعمال</button>' +
      '<button class="btn btn-ghost btn-sm" onclick="MX.exportCsv()">⬇️ CSV</button>' +
    '</div>' +
    '<div id="paySummary"></div><div id="payList"></div><div id="payMore"></div>';
  $('#view').innerHTML = tabs;
  loadPaymentsPage();
}
function loadPaymentsPage(){
  var data = {page:payPage, per:30};
  if (payFilter) data.status = payFilter;
  if (payFrom) data.from = payFrom;
  if (payTo) data.to = payTo;
  setMoreBusy(true);
  call('payments_list', {data:data}).then(function(d){
    setMoreBusy(false);
    if (!d.ok) return err(d.msg);
    payCache = payCache.concat(d.payments); payTotal = d.total;
    var rows = payCache.map(function(p){
      var canAct = (p.status==='await_admin' || p.status==='await_receipt');
      return '<div class="card"><div style="display:flex;justify-content:space-between"><b>#'+p.id+' • '+esc(p.kind==='limit'?'اسلات':(p.template||'—'))+'</b>' +
        '<span class="badge b-info">'+esc(p.status)+'</span></div>' +
        '<div class="s" style="color:var(--muted);font-size:12px;margin-top:6px">کاربر '+p.user_id+' • '+fmt(p.amount)+' تومان • '+esc(p.method||'—')+'</div>' +
        '<button class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="MX.payDetail('+p.id+')">🔍 جزئیات</button>' +
        (canAct ? '<button class="btn btn-ok btn-sm" style="margin-top:10px" onclick="MX.pay('+p.id+',\'approve\')">✅ تأیید</button>' +
        '<button class="btn btn-bad btn-sm" style="margin-top:10px" onclick="MX.pay('+p.id+',\'decline\')">❌ رد</button>' : '') + '</div>';
    }).join('') || '<div class="empty">موردی یافت نشد</div>';
    var el = $('#payList'); if (el) el.innerHTML = rows;
    var sum = payCache.reduce(function(a,p){ return a + (Number(p.amount)||0); }, 0);
    var ss = $('#paySummary');
    if (ss) {
      var paid = payCache.filter(function(p){ return p.status==='paid'||p.status==='used'; });
      var paidSum = paid.reduce(function(a,p){ return a + (Number(p.amount)||0); }, 0);
      ss.innerHTML = '<div class="card" style="padding:11px 13px"><div class="kv"><b>💰 جمع مبالغ این نتیجه</b><span>' + fmt(sum) + ' ت</span></div>' +
        '<div class="kv"><b>✅ جمع پرداخت‌شده</b><span>' + fmt(paidSum) + ' ت</span></div></div>';
    }
    updatePager('#payMore', payCache.length, payTotal, 'MX.morePayments()');
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- کاربران ---------- */
var usersCache = [];
var userPage = 1, userTotal = 0;
function vUsers(){
  userPage = 1; userTotal = 0; usersCache = [];
  $('#view').innerHTML = '<div class="search"><input id="userQ" type="search" placeholder="🔎 جستجوی کاربر…" autocomplete="off"></div><div id="userList"></div><div id="userMore"></div>';
  $('#userQ').addEventListener('input', debounce(function(){ drawUsers(this.value); }, 180));
  loadUsersPage();
}
function loadUsersPage(){
  setMoreBusy(true);
  call('users_list', {data:{page:userPage, per:30}}).then(function(d){
    setMoreBusy(false);
    if (!d.ok) return err(d.msg);
    usersCache = usersCache.concat(d.users); userTotal = d.total;
    drawUsers(($('#userQ')||{}).value || '');
    updatePager('#userMore', usersCache.length, userTotal, 'MX.moreUsers()');
  }).catch(function(){ setMoreBusy(false); err('خطا در دریافت'); });
}
function drawUsers(q){
  q = (q||'').trim();
  var rows = usersCache.filter(function(u){
    if (!q) return true;
    return (u.first_name||'').indexOf(q) >= 0 || (u.username||'').indexOf(q) >= 0 || String(u.user_id).indexOf(q) >= 0;
  });
  $('#userList').innerHTML = rows.length ? rows.map(function(u){
    var sup = !!u.is_super;
    var badge = sup
      ? '<span class="badge b-info">👑 سوپرادمین</span>'
      : '<span class="badge ' + (u.is_allowed?'b-ok':'b-warn') + '">' + (u.is_allowed?'مجاز':'معلق') + '</span>';
    // سقف فقط برای کسانی معنا دارد که مجازند؛ سوپرادمین در ربات سقف ندارد
    var limitLine = sup
      ? '<div class="s" style="color:var(--muted);font-size:11px;margin-top:3px">ساخته: ' + u.build_count + ' • دسترسی کامل</div>'
      : '<div class="s" style="color:var(--muted);font-size:11px;margin-top:3px">ساخته: ' + u.build_count + ' • سقف: ' + u.bot_limit + ' • ادمین: ' + (u.is_admin?'✅':'—') + '</div>';
    var btns =
      '<button class="btn btn-ghost btn-sm" onclick="MX.userBots(' + u.user_id + ',\'' + esc(u.first_name || u.username || u.user_id) + '\')">🤖 ربات‌ها</button>';
    if (!sup) {
      btns +=
        '<button class="btn btn-ghost btn-sm" onclick="MX.lim(' + u.user_id + ',' + (Number(u.bot_limit)+1) + ')">➕ سقف</button>' +
        '<button class="btn btn-ghost btn-sm" onclick="MX.lim(' + u.user_id + ',' + Math.max(0,Number(u.bot_limit)-1) + ')">➖ سقف</button>' +
        '<button class="btn btn-ghost btn-sm" onclick="MX.adm(' + u.user_id + ',' + (u.is_admin?0:1) + ')">' + (u.is_admin?'ادمین‌زدایی':'ادمین‌سازی') + '</button>' +
        '<button class="btn btn-ghost btn-sm" onclick="MX.allow(' + u.user_id + ',' + (u.is_allowed?0:1) + ')">' + (u.is_allowed?'🚫 تعلیق':'✅ مجازسازی') + '</button>';
    }
    return '<div class="card"><div class="urow"><div class="avatar">' + (sup ? '👑' : '👤') + '</div>' +
      '<div style="flex:1"><b>' + esc(u.first_name || u.username || u.user_id) + '</b> <span class="copyable" style="color:var(--muted);font-size:12px" onclick="MX.copyUser(' + u.user_id + ')">(' + u.user_id + ')</span>' +
      limitLine + '</div>' + badge + '</div>' +
      '<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">' + btns + '</div></div>';
  }).join('') : '<div class="empty"><div class="em-ic">👥</div><div>کاربری نیست</div></div>';
}

/* ---------- قالب‌ها ---------- */
function vTemplates(){
  loading();
  call('templates').then(function(d){
    if (!d || !d.ok) return err((d && d.msg) || 'خطا');
    // بک‌اند لیست برمی‌گرداند؛ برای اطمینان، اگر آبجکت بود به لیست تبدیل می‌شود
    var list = d.templates || [];
    if (!Array.isArray(list)) {
      list = Object.keys(list).map(function(k){
        var v = list[k] || {};
        v.type = k;
        return v;
      });
    }
    if (!list.length) {
      $('#view').innerHTML = '<div class="empty"><div class="em-ic">🧩</div><div>هیچ قالبی روی سرور نصب نیست</div>' +
        '<div style="font-size:11px">از بخش «🔄 دریافت سورس بروز» قالب‌ها را نصب کنید</div></div>';
      return;
    }
    var ready = 0;
    var rows = list.map(function(t){
      var okk = t.installed !== false;
      if (okk) ready++;
      return '<div class="tpl"><div class="avatar">' + esc(t.icon || '🧩') + '</div>' +
        '<div style="flex:1;min-width:0"><b>' + esc(t.label || t.type) + '</b>' +
        '<div class="s" style="color:var(--muted);font-size:11px;margin-top:3px">' +
          esc(t.type) +
          (t.db ? ' • ' + esc(String(t.db).toUpperCase()) : '') +
          (t.min_php ? ' • PHP ' + esc(t.min_php) : '') +
          (t.cron ? ' • کرون' : '') +
        '</div></div>' +
        '<span class="badge ' + (okk ? 'b-ok' : 'b-bad') + '">' + (okk ? 'آماده' : 'نصب نشده') + '</span>' +
        '</div>';
    }).join('');
    $('#view').innerHTML =
      '<div class="card"><h3>🧩 قالب‌ها (' + fmt(ready) + ' آماده از ' + fmt(list.length) + ')</h3>' + rows + '</div>' +
      '<div class="card"><h3>ℹ️ راهنما</h3>' +
        '<div class="kv"><b>هر قالب</b><span>یک ربات کامل مستقل</span></div>' +
        '<div class="kv"><b>آیکون + نام</b><span>همان‌طور که در منوی ربات دیده می‌شود</span></div>' +
        '<div class="kv"><b>SQLite / MySQL</b><span>نوع دیتابیس قالب</span></div>' +
        '<div class="kv"><b>«نصب نشده»</b><span>پوشهٔ قالب روی سرور نیست</span></div>' +
      '</div>';
  }).catch(function(){ err('خطا در دریافت اطلاعات قالب‌ها'); });
}

/* ---------- بروزرسانی سورس قالب‌ها و ربات‌ها ---------- */
var srcRows = [], botRows = [], updBusy = false;

function vUpdate(){
  loading();
  $('#view').innerHTML =
    '<div class="card"><h3>🔄 سورس قالب‌ها</h3><div id="srcCard" style="color:var(--muted);font-size:12px">…</div></div>' +
    '<div class="card"><h3>🤖 ربات‌های ساخته‌شده</h3><div id="botCard" style="color:var(--muted);font-size:12px">…</div></div>';
  loadSrcStatus();
  loadBotPlan();
}

function loadSrcStatus(){
  call('src_status').then(function(d){
    var el = $('#srcCard');
    if (!el) return;
    if (!d || !d.ok) { el.innerHTML = '<div style="color:#f87171">' + esc((d && d.msg) || 'خطا') + '</div>'; return; }
    srcRows = d.rows || [];
    var changed = srcRows.filter(function(r){ return r.changed; });
    var html = '<div class="kv"><b>شاخهٔ ریپو</b><span>' + esc(d.branch || '?') + '</span></div>' +
      '<div class="kv"><b>قالب‌های دارای تغییر</b><span>' + (changed.length ? '<b style="color:#fbbf24">' + fmt(changed.length) + '</b>' : '✅ همه به‌روز') + '</span></div>';
    html += srcRows.length ? srcRows.map(function(r){
      return '<div class="tpl"><div class="avatar">📦</div>' +
        '<div style="flex:1;min-width:0"><b>' + esc(r.label || r.type) + '</b>' +
        '<div class="s" style="color:var(--muted);font-size:11px;margin-top:3px">' +
          esc(r.old || '—') + ' ← ' + esc(r.new || '—') +
          (r.files ? ' • ' + fmt(r.files) + ' فایل' : '') +
        '</div></div>' +
        '<span class="badge ' + (r.changed ? 'b-warn' : (r.ok ? 'b-ok' : 'b-bad')) + '">' +
          (r.changed ? 'بروزرسانی' : (r.ok ? 'به‌روز' : 'قطع')) + '</span></div>';
    }).join('') : '<div style="color:var(--muted);font-size:12px">قالبی با ریپو ثبت نشده</div>';
    html += '<button class="btn btn-acc" style="width:100%;margin-top:12px" id="srcGo" onclick="MX.updateSrc()">⬇️ دریافت سورس بروز قالب‌ها</button>';
    html += '<div id="srcOut" style="margin-top:10px"></div>';
    el.innerHTML = html;
  }).catch(function(){
    var el = $('#srcCard'); if (el) el.innerHTML = '<div style="color:#f87171">ارتباط برقرار نشد</div>';
  });
}

function loadBotPlan(){
  call('bot_plan').then(function(d){
    var el = $('#botCard');
    if (!el) return;
    if (!d || !d.ok) { el.innerHTML = '<div style="color:#f87171">' + esc((d && d.msg) || 'خطا') + '</div>'; return; }
    botRows = d.bots || [];
    if (!botRows.length) {
      el.innerHTML = '<div style="color:var(--muted);font-size:12px">هنوز رباتی ساخته نشده است</div>';
      return;
    }
    var outdated = botRows.filter(function(b){ return b.ok && (b.new || b.changed); });
    var html = '<div style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);border-radius:12px;padding:10px;margin-bottom:12px;font-size:11.5px;line-height:2">' +
      '<b>🛡 config.php و دیتابیس هرگز دست نمی‌خورند.</b><br>' +
      'تنها فایل‌های «کدِ قالب» کپی می‌شوند و پیش از هر تغییر، بکاپ گرفته می‌شود.' +
      '</div>';
    html += botRows.map(function(b){
      var pending = b.ok && (b.new || b.changed);
      return '<div class="card" style="margin-bottom:10px"><div style="display:flex;justify-content:space-between;align-items:center">' +
        '<div style="min-width:0"><b>@' + esc(b.username || b.folder) + '</b>' +
        '<div class="s" style="color:var(--muted);font-size:11px;margin-top:3px">' + esc(b.label || b.type) + '</div></div>' +
        '<span class="badge ' + (!b.ok ? 'b-bad' : (pending ? 'b-warn' : 'b-ok')) + '">' +
          (!b.ok ? 'خطا' : (pending ? 'دارای تغییر' : 'به‌روز')) + '</span></div>' +
        (b.ok
          ? '<div class="s" style="color:var(--muted);font-size:11px;margin-top:8px">➕ ' + fmt(b.new || 0) +
            ' جدید • ✏️ ' + fmt(b.changed || 0) + ' تغییر • 🔒 ' + fmt(b.skipped || 0) + ' محافظت‌شده</div>'
          : '<div class="s" style="color:#f87171;font-size:11px;margin-top:8px">' + esc(b.error || '') + '</div>') +
        (pending ? '<button class="btn btn-acc btn-sm" style="margin-top:10px" onclick="MX.updateBot(' + b.id + ')">⬇️ بروزرسانی این ربات</button>' : '') +
        '</div>';
    }).join('');
    if (outdated.length > 1) {
      html += '<button class="btn btn-acc" style="width:100%;margin-top:6px" onclick="MX.updateAllBots()">⬇️ بروزرسانی همه (' + fmt(outdated.length) + ' ربات)</button>';
    }
    html += '<div id="botOut" style="margin-top:10px"></div>';
    el.innerHTML = html;
  }).catch(function(){
    var el = $('#botCard'); if (el) el.innerHTML = '<div style="color:#f87171">ارتباط برقرار نشد</div>';
  });
}

/* ---------- تنظیمات ---------- */
function vSettings(){
  call('settings_get').then(function(d){
    if (!d.ok) return err(d.msg);
    logInit();
    var gw = d.gateways.map(function(g){
      return '<div class="row"><div class="t">'+esc(g.label)+'</div><div class="switch '+(g.enabled?'on':'')+'" onclick="MX.gw(\''+g.key+'\','+(g.enabled?0:1)+',this)"></div></div>';
    }).join('');
    call('prices_get').then(function(p){
      var el = $('#pricesCard'); if (!el || !p || !p.ok) return;
      var rows = (p.prices||[]).map(function(r){
        var isFree = Number(r.price) <= 0;
        return '<div class="price-row">' +
          '<div class="nm"><b>' + esc(r.label) + '</b><span style="font-size:10px;color:var(--muted)">' + esc(r.type) + '</span>' +
          (isFree ? ' <span class="price-free">رایگان</span>' : '') + '</div>' +
          '<input id="price_' + esc(r.type) + '" type="number" min="0" step="1000" inputmode="numeric" value="' + esc(r.price) + '">' +
          '<button class="btn btn-ghost btn-sm" onclick="MX.savePrice(\'' + esc(r.type) + '\')">💾</button></div>';
      }).join('');
      el.innerHTML =
        '<div class="daterange">' +
          '<button class="mini-chip" onclick="MX.bulkPrice(0)">همه رایگان</button>' +
          '<button class="mini-chip" onclick="MX.bulkPrice(50000)">۵۰ هزار</button>' +
          '<button class="mini-chip" onclick="MX.bulkPrice(100000)">۱۰۰ هزار</button>' +
          '<button class="mini-chip" onclick="MX.bulkPrice(200000)">۲۰۰ هزار</button>' +
        '</div>' + rows +
        '<div class="price-row" style="border-top:1px solid var(--line);margin-top:8px;padding-top:12px">' +
          '<div class="nm"><b>قیمت هر اسلات</b><span style="font-size:10px;color:var(--muted)">برای افزایش سقف ساخت</span></div>' +
          '<input id="price_limit" type="number" min="0" step="1000" inputmode="numeric" value="' + esc(p.limit_price) + '">' +
          '<button class="btn btn-ghost btn-sm" onclick="MX.saveLimitPrice()">💾</button></div>' +
        '<button class="btn btn-acc btn-sm" style="width:100%;margin-top:12px" onclick="MX.saveAllPrices()">💾 ذخیرهٔ همهٔ قیمت‌ها</button>';
    });
    var logPage = 1, logLines = [], logFile = '', logFilter = '';
function drawLogs(){
  var el = $('#logBox'); if (!el) return;
  var rows = logLines.filter(function(l){ return !logFilter || l.toUpperCase().indexOf(logFilter) >= 0; });
  el.innerHTML = rows.length ? rows.map(function(l, i){
    var m = String(l).match(/^\[?(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]?\s*\[?(INFO|WARN|WARNING|ERROR|FATAL|DEBUG)\]?/i);
    var lv = m ? m[2].toUpperCase() : 'INFO';
    var body = m ? String(l).slice(m[0].length) : String(l);
    return '<div class="logline lv-' + lv + '"><span class="ln">' + fmt(logPage * 60 - i) + '</span><span>' + esc(body.trim()) + '</span></div>';
  }).join('') : '<div style="padding:16px;text-align:center;color:var(--muted);font-size:12px">لاگی مطابق فیلتر یافت نشد</div>';
}
function loadLogs(page){
  logPage = page || 1;
  var el = $('#logBox'); if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:var(--muted);font-size:12px">…</div>';
  call('logs_page', {data:{page:logPage, per:60}}).then(function(l){
    if (!l || !l.ok) { if (el) el.innerHTML = '<div style="padding:16px;text-align:center;color:#f87171;font-size:12px">خطا در خواندن لاگ</div>'; return; }
    logLines = l.lines || [];
    logFile = l.file || '';
    drawLogs();
    var more = $('#logMore');
    if (more) more.style.display = l.hasMore ? '' : 'none';
    var f = $('#logFile'); if (f) f.textContent = logFile;
  }).catch(function(){ if (el) el.innerHTML = '<div style="padding:16px;text-align:center;color:#f87171;font-size:12px">ارتباط برقرار نشد</div>'; });
}
function vLogs_init(){
  return '<div class="daterange">' +
    '<button class="mini-chip" onclick="MX.logFilter(\'\')">همه</button>' +
    '<button class="mini-chip" onclick="MX.logFilter(\'ERROR\')">خطا</button>' +
    '<button class="mini-chip" onclick="MX.logFilter(\'WARN\')">هشدار</button>' +
    '<button class="mini-chip" onclick="MX.logFilter(\'pay\')">پرداخت</button>' +
    '<input id="logSearch" type="search" placeholder="جستجو در لاگ…" oninput="MX.logSearch(this.value)">' +
  '</div>' +
  '<div class="logbox" id="logBox"></div>' +
  '<div id="logMore" style="margin-top:10px"><button class="btn btn-ghost loadmore" onclick="MX.moreLogs()">⬇️ لاگ‌های قدیمی‌تر</button></div>';
}
function logInit(){
  var host = $('#logsCard'); if (!host) return;
  host.innerHTML = vLogs_init();
  loadLogs(1);
}
    call('child_owner_get').then(function(o){
      var el = $('#ownerCard'); if (!el || !o || !o.ok) return;
      var empty = !o.username;
      el.innerHTML =
        '<p style="margin:0 0 10px;line-height:1.9">' + (empty
          ? 'هنوز تنظیم نشده. اگر خالی بماند، هنگام ساخت هر ربات یک اکانت امن <b>خودکار</b> ساخته می‌شود و پیامش به شما نشان داده می‌شود.'
          : 'این اکانت در همهٔ ربات‌هایی که از این به بعد ساخته می‌شوند نوشته می‌شود. ربات‌های قبلاً ساخته‌شده دست‌نخورده می‌مانند.') + '</p>' +
        '<label class="lbl">نام کاربری</label><input id="in_own_u" type="text" value="' + esc(o.username || '') + '" placeholder="owner123">' +
        '<label class="lbl">رمز عبور</label><input id="in_own_p" type="text" value="' + esc(o.password || '') + '" placeholder="••••••••">' +
        '<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">' +
          '<button class="btn btn-acc btn-sm" onclick="MX.saveOwner()" style="flex:1">💾 ذخیره</button>' +
          '<button class="btn btn-ghost btn-sm" onclick="MX.genOwner()" style="flex:1">🎲 ساخت تصادفی</button>' +
          '<button class="btn btn-ghost btn-sm" onclick="MX.resetOwner()" style="flex:1">↺ خودکار</button>' +
        '</div>' +
        (empty ? '' : '<button class="btn btn-ghost btn-sm" style="margin-top:8px" onclick="MX.copyUserOwner()">📋 کپی نام کاربری</button>');
    });
    call('backup_get').then(function(b){
      if (!b || !b.ok) return;
      var on = !!b.settings.enabled;
      var runs = 0;
      if (b.state && typeof b.state === 'object') { for (var k in b.state) runs++; }
      var last = runs ? '<div class="s" style="color:var(--muted);font-size:11px;margin-top:8px">برای '+fmt(runs)+' ربات سابقهٔ بکاپ ثبت شده</div>' : '';
      var el = $('#backupCard'); if (!el) return;
      el.innerHTML =
        '<div class="row"><div><div class="t">فعال</div></div><div class="switch '+(on?'on':'')+'" id="bkSwitch" onclick="MX.bkToggle(this)"></div></div>' +
        '<label class="lbl">ساعت‌های اجرا (با کاما)</label><input id="bkTimes" type="text" value="'+esc((b.settings.times||[]).join(', '))+'" placeholder="03:00, 15:00">' +
        '<button class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="MX.saveBackup()">💾 ذخیره بکاپ</button>' + last;
    });
    call('fx_rate_get').then(function(f){
      var fxHtml = '';
      if (f && f.ok && f.rate) {
        fxHtml = '<div class="row"><div class="t">💱 نرخ دلار</div><b>' + fmt(f.rate) + ' تومان <span style="color:var(--muted);font-size:11px">(' + esc(f.source || '—') + ')</span></b></div>';
      }
      call('system_status').then(function(s){
        var sys = '';
        if (s && s.ok) {
          sys = '<div class="card"><h3>ℹ️ وضعیت سیستم</h3>' +
            '<div class="kv"><b>نسخهٔ ربات‌ساز</b><span>' + esc(s.version) + '</span></div>' +
            '<div class="kv"><b>PHP</b><span>' + esc(s.php) + '</span></div>' +
            '<div class="kv"><b>دیتابیس</b><span>' + esc(s.driver) + '</span></div>' +
            '<div class="kv"><b>ربات‌ها / کاربران</b><span>' + fmt(s.bots) + ' / ' + fmt(s.users) + '</span></div>' +
            (s.disk_free ? '<div class="kv"><b>فضای آزاد دیسک</b><span>' + humanSize(s.disk_free) + '</span></div>' : '') +
            fxHtml + '</div>';
        }
        var host = $('#settingsHost');
        if (host) host.innerHTML += sys;
      });
    });
    $('#view').innerHTML =
      '<div id="settingsHost"></div>' +
      '<div class="card"><h3>🔧 حالت کلی</h3>' +
        '<div class="row"><div><div class="t">حالت تعمیرات</div><div class="s" style="color:var(--muted)">ساخت ربات متوقف می‌شود</div></div>' +
          '<div class="switch '+(d.maintenance?'on':'')+'" onclick="MX.setS(\'maintenance\','+(d.maintenance?0:1)+',this)"></div></div>' +
        '<div class="row"><div><div class="t">نیاز به تأیید ادمین</div><div class="s" style="color:var(--muted)">درخواست ساخت باید تأیید شود</div></div>' +
          '<div class="switch '+(d.approval_required?'on':'')+'" onclick="MX.setS(\'approval\','+(d.approval_required?0:1)+',this)"></div></div>' +
        '<label class="lbl">زمان تقریبی تعمیرات (ETA)</label><input id="in_eta" type="text" value="'+esc(d.maintenance_eta||'')+'" placeholder="مثلاً: ۲ ساعت">' +
        '<button class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="MX.saveEta()">💾 ذخیره ETA</button>' +
      '</div>' +
      '<div class="card"><h3>🎨 نمایش</h3>' +
        '<div class="row"><div><div class="t">تم تیره</div><div class="s" style="color:var(--muted)">همیشه تیره</div></div>' +
          '<button class="btn ' + (curTheme()==='dark'?'btn-acc':'btn-ghost') + ' btn-sm" onclick="MX.setTheme(\'dark\')">🌙</button></div>' +
        '<div class="row"><div><div class="t">تم روشن</div><div class="s" style="color:var(--muted)">همیشه روشن</div></div>' +
          '<button class="btn ' + (curTheme()==='light'?'btn-acc':'btn-ghost') + ' btn-sm" onclick="MX.setTheme(\'light\')">☀️</button></div>' +
        '<div class="row"><div><div class="t">خودکار</div><div class="s" style="color:var(--muted)">هماهنگ با تلگرام/سیستم</div></div>' +
          '<button class="btn ' + (curTheme()==='auto'?'btn-acc':'btn-ghost') + ' btn-sm" onclick="MX.setTheme(\'auto\')">🔄</button></div>' +
      '</div>' +
      '<div class="card"><h3>🏷 قیمت قالب‌ها</h3><div id="pricesCard" style="color:var(--muted);font-size:12px">…</div></div>' +
      '<div class="card"><h3>📜 لاگ‌های اخیر <span id="logFile" style="font-size:10px;color:var(--muted);font-weight:400"></span></h3><div id="logsCard" style="color:var(--muted);font-size:12px">…</div></div>' +
      '<div class="card"><h3>💾 بکاپ خودکار دیتابیس</h3><div id="backupCard" style="color:var(--muted);font-size:12px">…</div></div>' +
      '<div class="card"><h3>💳 پرداخت</h3>' + gw +
        '<label class="lbl">شمارهٔ کارت</label><input id="in_card" type="text" value="'+esc(d.card_number)+'">' +
        '<label class="lbl">صاحب کارت</label><input id="in_owner" type="text" value="'+esc(d.card_owner)+'">' +
        '<label class="lbl">قیمت هر اسلات (تومان)</label><input id="in_price" type="number" value="'+esc(d.limit_price)+'">' +
        '<button class="btn btn-acc btn-sm" style="margin-top:14px" onclick="MX.savePayment()">💾 ذخیره تنظیمات پرداخت</button>' +
      '</div>' +
      '<div class="card"><h3>🏢 اکانت سازندهٔ پنل</h3><div id="ownerCard" style="color:var(--muted);font-size:12px">…</div></div>' +
      '<div class="card"><h3>🔑 کد پذیرندهٔ درگاه‌ها</h3>' +
        '<p class="hint" style="margin:0 0 10px">بدون ثبت این کدها، روش پرداخت به کاربر نشان داده نمی‌شود. اطلاعات فقط روی سرور شما ذخیره می‌شود.</p>' +
        '<label class="lbl">کد پذیرندهٔ زرین‌پال</label><input id="in_zarin" type="text" value="'+esc(d.zarin_merchant||'')+'" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">' +
        '<div class="row" style="padding:8px 0"><div class="t">سندباکس زرین‌پال</div>' +
          '<div class="switch '+(d.zarin_sandbox?'on':'')+'" onclick="MX.setS(\'zarin_sandbox\','+(d.zarin_sandbox?0:1)+',this)"></div></div>' +
        '<label class="lbl">PIN آقای پرداخت</label><input id="in_aqaye" type="text" value="'+esc(d.aqaye_pin||'')+'" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">' +
        '<div class="row" style="padding:8px 0"><div class="t">سندباکس آقای پرداخت</div>' +
          '<div class="switch '+(d.aqaye_sandbox?'on':'')+'" onclick="MX.setS(\'aqaye_sandbox\','+(d.aqaye_sandbox?0:1)+',this)"></div></div>' +
        '<label class="lbl">API Key نواپیمنتس</label><input id="in_nowpay" type="text" value="'+esc(d.nowpay_key||'')+'">' +
        '<label class="lbl">IPN Secret نواپیمنتس</label><input id="in_nowpay_ipn" type="text" value="'+esc(d.nowpay_ipn||'')+'">' +
        '<button class="btn btn-acc btn-sm" style="margin-top:14px" onclick="MX.saveCreds()">💾 ذخیره کدها</button>' +
      '</div>';
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- اکشن‌ها ---------- */
window.MX = {
  setStatus: function(id, s){ call('bot_status', {method:'POST', data:{id:id,status:s}}).then(function(d){ toast(d.ok?'انجام شد ✅':(d.msg||'خطا')); haptic(); render(); }); },
  delBot: function(id){
    askConfirm('حذف ربات؟', 'این عملیات پوشه و دیتابیس ربات را برای همیشه پاک می‌کند و قابل بازگشت نیست.', function(){
      call('bot_delete', {method:'POST', data:{id:id}}).then(function(d){
        if (d.ok) { ok('حذف شد 🗑'); render(); } else bad(d.msg || 'خطا');
      });
    });
  },
  goTab: function(name){ goTab(name); },
  req: function(id, a){ call(a==='approve'?'pending_approve':'pending_decline', {method:'POST', data:{id:id}}).then(function(d){ toast(d.ok?'انجام شد ✅':(d.msg||'خطا')); haptic('medium'); refreshDots(); render(); }); },
  setPayFilter: function(s){ payFilter = s; payPage = 1; payCache = []; haptic(); vPayments(); },
  payDetail: function(id){
    var p = payCache.find(function(x){ return Number(x.id) === Number(id); });
    if (!p) return toast('یافت نشد');
    markDetail('payDetail');
    $('#view').innerHTML =
      '<button class="btn btn-ghost btn-sm" onclick="MX.backToPayments()" style="margin-bottom:12px">⬅️ بازگشت</button>' +
      '<div class="card"><h3>🧾 جزئیات پرداخت</h3>' +
      '<div class="kv"><b>شناسه</b><span>#'+p.id+'</span></div>' +
      '<div class="kv"><b>کاربر</b><span>'+p.user_id+'</span></div>' +
      '<div class="kv"><b>نوع</b><span>'+esc(p.kind==='limit'?'اسلات':(p.template||'—'))+'</span></div>' +
      '<div class="kv"><b>مبلغ</b><span>'+fmt(p.amount)+' تومان</span></div>' +
      '<div class="kv"><b>وضعیت</b><span>'+esc(p.status)+'</span></div>' +
      '<div class="kv"><b>روش</b><span>'+esc(p.method||'—')+'</span></div>' +
      '<div class="kv"><b>شناسهٔ تراکنش</b><span>'+esc(p.ext_id||'—')+'</span></div>' +
      '<div class="kv"><b>اسلات</b><span>'+fmt(p.slots||0)+'</span></div>' +
      '<div class="kv"><b>ساخته‌شده</b><span>'+esc(p.created_at||'—')+'</span></div>' +
      '<div class="kv"><b>پرداخت‌شده</b><span>'+esc(p.paid_at||'—')+'</span></div>' +
      (p.receipt ? '<div class="kv" style="flex-direction:column;gap:6px"><b>رسید</b><span style="word-break:break-all">'+esc(p.receipt)+'</span></div>' : '') +
      (p.pay_url ? '<a class="btn btn-acc btn-sm" style="display:inline-block;margin-top:10px" href="'+esc(p.pay_url)+'">🔗 لینک پرداخت</a>' : '') +
      '</div>';
  },
  backToPayments: function(){ vPayments(); },
  exportCsv: function(){
    toast('در حال آماده‌سازی…');
    var data = {};
    if (payFilter) data.status = payFilter;
    if (payFrom) data.from = payFrom;
    if (payTo) data.to = payTo;
    call('payments_export', {data:data}).then(function(d){
      if (!d || !d.ok) return bad(d && d.msg ? d.msg : 'خطا در خروجی');
      var rows = d.rows || [];
      if (!rows.length) return bad('داده‌ای برای خروجی نیست');
      var head = ['id','user_id','kind','template','slots','amount','status','method','ext_id','created_at','paid_at'];
      var lines = [head.join(',')];
      rows.forEach(function(p){
        lines.push(head.map(function(k){
          var v = p[k] == null ? '' : String(p[k]).replace(/"/g, '""');
          return '"' + v + '"';
        }).join(','));
      });
      var name = 'payments-' + (payFrom||'all') + '-' + fmt(rows.length) + '.csv';
      var blob = new Blob(['\ufeff' + lines.join('\r\n')], {type: 'text/csv;charset=utf-8;'});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function(){ URL.revokeObjectURL(a.href); }, 1000);
      ok(fmt(rows.length) + ' ردیف خروجی گرفت ⬇️');
    }).catch(function(){ bad('خطا در ساخت فایل'); });
  },
  pay: function(id, a){ call(a==='approve'?'payment_approve':'payment_decline', {method:'POST', data:{id:id}}).then(function(d){ toast(d.ok?('انجام شد ✅'+(d.note?' — '+d.note:'')):(d.msg||'خطا')); haptic('medium'); refreshDots(); render(); }); },
  lim: function(u, n){ call('user_set_limit', {method:'POST', data:{user_id:u, limit:n}}).then(function(d){ toast(d.ok?'سقف جدید: '+fmt(n):(d.msg||'خطا')); render(); }); },
  adm: function(u, v){ call('user_toggle_admin', {method:'POST', data:{user_id:u, v:v}}).then(function(d){ toast(d.ok?'انجام شد ✅':(d.msg||'خطا')); render(); }); },
  allow: function(u, v){ call('user_toggle_allow', {method:'POST', data:{user_id:u, v:v}}).then(function(d){ toast(d.ok?'انجام شد ✅':(d.msg||'خطا')); render(); }); },
  gw: function(k, v, el){ el.classList.toggle('on'); call('settings_set', {method:'POST', data:{k:'gateway', key:k, v:v}}).then(function(d){ toast(d.ok?'ذخیره شد ✅':(d.msg||'خطا')); haptic(); }); },
  setS: function(k, v, el){ el.classList.toggle('on'); call('settings_set', {method:'POST', data:{k:k, v:String(v)}}).then(function(d){ toast(d.ok?'ذخیره شد ✅':(d.msg||'خطا')); haptic(); }); },
  userBots: function(uid, name){
    markDetail('userBots');
    loading();
    call('bots_list').then(function(d){
      if (!d.ok) return err(d.msg);
      var rows = d.bots.filter(function(b){ return Number(b.owner_id) === Number(uid); });
      $('#view').innerHTML =
        '<button class="btn btn-ghost btn-sm" onclick="MX.backToUsers()" style="margin-bottom:12px">⬅️ بازگشت</button>' +
        '<div class="card"><h3>🤖 ربات‌های '+esc(name)+' ('+fmt(rows.length)+')</h3>' +
        (rows.length ? rows.map(function(b){
          return '<div class="row"><div><div class="t">@'+esc(b.bot_username||b.folder)+'</div><div class="s">'+esc(b.type)+' • '+esc(b.created_at||'')+'</div></div>' +
            '<span class="badge '+(b.status==='active'?'b-ok':'b-bad')+'">'+(b.status==='active'?'فعال':'غیرفعال')+'</span></div>';
        }).join('') : '<div class="empty">رباتی ندارد</div>') + '</div>';
    });
  },
  backToUsers: function(){ vUsers(); },
  backToBots: function(){ vBots(); },
  botDetail: function(id){
    var b = botsCache.find(function(x){ return Number(x.id) === Number(id); });
    if (!b) return toast('یافت نشد');
    markDetail('botDetail');
    $('#view').innerHTML =
      '<button class="btn btn-ghost btn-sm" onclick="MX.backToBots()" style="margin-bottom:12px">⬅️ بازگشت</button>' +
      '<div class="card"><h3>🤖 جزئیات ربات</h3>' +
        '<div class="kv"><b>یوزرنیم</b><span>@'+esc(b.bot_username || '—')+'</span></div>' +
        '<div class="kv"><b>شناسهٔ پوشه</b><span>'+esc(b.folder)+'</span></div>' +
        '<div class="kv"><b>قالب</b><span>'+esc(b.type)+'</span></div>' +
        '<div class="kv"><b>وضعیت</b><span class="badge '+(b.status==='active'?'b-ok':'b-bad')+'">'+(b.status==='active'?'فعال':'غیرفعال')+'</span></div>' +
        '<div class="kv"><b>مالک</b><span>'+esc(b.first_name || b.username || b.owner_id)+' ('+b.owner_id+')</span></div>' +
        '<div class="kv"><b>ساخته‌شده</b><span>'+esc(b.created_at||'—')+'</span></div>' +
      '</div>' +
      '<div style="display:flex;gap:8px">' +
        (b.status==='active'
          ? '<button class="btn btn-ghost btn-sm" onclick="MX.setStatus('+b.id+',\'disabled\')">⏸ غیرفعال</button>'
          : '<button class="btn btn-ok btn-sm" onclick="MX.setStatus('+b.id+',\'active\')">▶️ فعال‌سازی</button>') +
        '<button class="btn btn-bad btn-sm" onclick="MX.delBot('+b.id+')">🗑 حذف ربات</button></div>';
  },
  botDetailBack: null,
  bkToggle: function(el){ el.classList.toggle('on'); haptic(); },
  saveBackup: function(){
    var on = $('#bkSwitch').classList.contains('on') ? '1' : '0';
    call('backup_set', {method:'POST', data:{enabled:on, times:$('#bkTimes').value}}).then(function(d){ toast(d.ok?'ذخیره شد ✅':(d.msg||'خطا')); });
  },
  setTheme: function(mode){
    try {
      if (mode === 'light') { document.body.classList.add('light'); localStorage.setItem('mx_theme','light'); }
      else if (mode === 'dark') { document.body.classList.remove('light'); localStorage.setItem('mx_theme','dark'); }
      else { localStorage.removeItem('mx_theme'); applyAutoTheme(); }
      render();
      ok('ذخیره شد ✅');
    } catch(e){}
  },
  applyDates: function(){
    payFrom = ($('#payFrom') && $('#payFrom').value) || '';
    payTo = ($('#payTo') && $('#payTo').value) || '';
    payPage = 1; payCache = [];
    vPayments();
  },
  quickDate: function(mode){
    if (mode === 'today'){ payFrom = isoDate(new Date()); payTo = isoDate(new Date()); }
    else if (mode === '7d'){ payFrom = daysAgo(7); payTo = isoDate(new Date()); }
    else if (mode === '30d'){ payFrom = daysAgo(30); payTo = isoDate(new Date()); }
    else if (mode === 'month'){
      var n = new Date();
      payFrom = isoDate(new Date(n.getFullYear(), n.getMonth(), 1));
      payTo = isoDate(new Date());
    }
    else { payFrom = ''; payTo = ''; }
    haptic();
    vPayments();
  },
  logFilter: function(f){ logFilter = f; drawLogs(); },
  logSearch: function(v){ logFilter = (v||'').trim(); drawLogs(); },
  moreLogs: function(){ loadLogs(logPage + 1); },
  bulkPrice: function(v){
    document.querySelectorAll('#pricesCard input[id^="price_"]').forEach(function(i){
      if (i.id === 'price_limit') return;
      i.value = v;
    });
    haptic();
    toast('مقادیر اعمال شد — برای ثبت، «ذخیرهٔ همه» را بزنید');
  },
  saveAllPrices: function(){
    var inputs = document.querySelectorAll('#pricesCard input[id^="price_"]');
    if (!inputs.length) return;
    toast('در حال ذخیره…');
    var chain = Promise.resolve();
    var n = 0;
    inputs.forEach(function(inp){
      var id = inp.id.replace('price_', '');
      var v = String(inp.value || '0').replace(/\D/g, '') || '0';
      chain = chain.then(function(){
        n++;
        return call('price_set', {method:'POST', data:{type:id, v:v}});
      });
    });
    chain.then(function(){ ok(fmt(n) + ' قیمت ذخیره شد ✅'); });
  },
  updateSrc: function(){
    if (updBusy) return;
    var b = $('#srcGo');
    if (b) { b.disabled = true; b.textContent = '⏳ در حال دریافت…'; }
    updBusy = true;
    toast('در حال همگام‌سازی سورس قالب‌ها…');
    call('src_update', {method:'POST'}).then(function(d){
      updBusy = false;
      if (b) { b.disabled = false; b.textContent = '⬇️ دریافت سورس بروز قالب‌ها'; }
      var o = $('#srcOut');
      if (o) {
        o.innerHTML = '<pre style="white-space:pre-wrap;font-size:10.5px;line-height:1.7;background:rgba(148,163,184,.08);padding:10px;border-radius:12px;max-height:220px;overflow:auto;direction:ltr;text-align:left">'
          + esc((d && d.out) || 'بدون خروجی') + '</pre>';
      }
      if (d && d.ok) { ok('سورس قالب‌ها بروزرسانی شد ✅'); loadSrcStatus(); loadBotPlan(); }
      else bad((d && d.msg) || 'بروزرسانی ناموفق');
    }).catch(function(){
      updBusy = false;
      var b2 = $('#srcGo');
      if (b2) { b2.disabled = false; b2.textContent = '⬇️ دریافت سورس بروز قالب‌ها'; }
      bad('ارتباط برقرار نشد');
    });
  },
  updateBot: function(id){
    var b = $('#srcGo'); // قفل موقت
    toast('در حال بروزرسانی… ⏳');
    call('bot_update', {method:'POST', data:{id:id}}).then(function(d){
      var o = $('#botOut');
      if (o) {
        o.innerHTML = '<div class="alert-box ' + (d && d.ok ? 'ok' : 'bad') + '">' +
          esc((d && d.msg) || (d && d.ok ? 'بروزرسانی شد ✅' : 'خطا')) + '</div>';
      }
      if (d && d.ok) { ok('✅ ' + fmt(d.applied || 0) + ' فایل بروزرسانی شد'); loadBotPlan(); }
      else bad((d && d.msg) || 'بروزرسانی ناموفق');
    }).catch(function(){ bad('ارتباط برقرار نشد'); });
  },
  updateAllBots: function(){
    askConfirm('بروزرسانی همهٔ ربات‌ها؟', 'فقط کدِ قالب کپی می‌شود. config.php و دیتابیس هر ربات دست‌نخورده می‌ماند و پیش از هر تغییر بکاپ گرفته می‌شود.', function(){
      toast('در حال بروزرسانی همه… ⏳');
      call('bot_update_all', {method:'POST'}).then(function(d){
        if (d && d.ok) { ok(fmt(d.updated) + ' ربات بروزرسانی شد، ' + fmt(d.skipped) + ' بدون تغییر'); loadBotPlan(); }
        else bad('ناموفق');
      }).catch(function(){ bad('ارتباط برقرار نشد'); });
    });
  },
  copyText: function(t){ copyText(t); },
  copyUserOwner: function(){ var el = $('#in_own_u'); if (el && el.value) copyText(el.value, 'نام کاربری کپی شد ✅'); },
  copyUser: function(uid){ copyText(String(uid), 'شناسهٔ کاربر کپی شد ✅'); },
  moreBots: function(){ botPage++; loadBotsPage(); },
  moreUsers: function(){ userPage++; loadUsersPage(); },
  morePayments: function(){ payPage++; loadPaymentsPage(); },
  savePrice: function(type){
    var v = ($('#price_' + type) || {}).value || '0';
    call('price_set', {method:'POST', data:{type:type, v:v}}).then(function(d){ toast(d.ok?'ذخیره شد ✅':(d.msg||'خطا')); });
  },
  saveLimitPrice: function(){
    var v = ($('#price_limit') || {}).value || '0';
    call('price_set', {method:'POST', data:{type:'', v:v}}).then(function(d){ toast(d.ok?'ذخیره شد ✅':(d.msg||'خطا')); });
  },
  saveCreds: function(){
    var z = $('#in_zarin').value, a = $('#in_aqaye').value, n = $('#in_nowpay').value, ni = $('#in_nowpay_ipn').value;
    call('settings_set', {method:'POST', data:{k:'zarin_merchant', v:z}})
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'aqaye_pin', v:a}}); })
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'nowpay_key', v:n}}); })
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'nowpay_ipn', v:ni}}); })
      .then(function(d){ toast(d.ok?'کدها ذخیره شد ✅':(d.msg||'خطا')); haptic(); });
  },
  saveOwner: function(){
    var u = $('#in_own_u').value, p = $('#in_own_p').value;
    call('settings_set', {method:'POST', data:{k:'child_owner_username', v:u}})
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'child_owner_password', v:p}}); })
      .then(function(d){ if (d.ok) { ok('ذخیره شد ✅'); render(); } else bad(d.msg || 'خطا'); });
  },
  genOwner: function(){
    var n = 'owner' + Math.floor(Math.random()*90000 + 10000);
    var pw = '';
    var chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for (var i = 0; i < 14; i++) pw += chars.charAt(Math.floor(Math.random()*chars.length));
    $('#in_own_u').value = n;
    $('#in_own_p').value = pw;
    haptic();
    ok('ساخته شد — «ذخیره» را بزنید');
  },
  resetOwner: function(){
    askConfirm('بازگشت به حالت خودکار؟', 'اکانت فعلی پاک می‌شود و ربات‌های بعدی اکانت تصادفی می‌گیرند.', function(){
      call('settings_set', {method:'POST', data:{k:'child_owner_reset', v:'1'}}).then(function(d){
        if (d.ok) { ok('به حالت خودکار برگشت ✅'); render(); } else bad(d.msg || 'خطا');
      });
    });
  },
  saveEta: function(){ var v = $('#in_eta').value; call('settings_set', {method:'POST', data:{k:'maintenance_eta', v:v}}).then(function(d){ toast(d.ok?'ذخیره شد ✅':(d.msg||'خطا')); }); },
  savePayment: function(){
    var card = $('#in_card').value, owner = $('#in_owner').value, price = $('#in_price').value;
    call('settings_set', {method:'POST', data:{k:'card_number', v:card}})
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'card_owner', v:owner}}); })
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'limit_price', v:price}}); })
      .then(function(){ toast('ذخیره شد ✅'); haptic(); render(); });
  }
};

/* دکمهٔ Back تلگرام در صفحه‌های جزئیات (ربات/پرداخت/کاربر) */
try {
  if (tg && tg.BackButton) {
    tg.BackButton.onClick(function(){ backNow(); });
  }
} catch(e){}
function backNow(){
  var back = { botDetail: 'bots', payDetail: 'payments', userBots: 'users' }[current + '_' + (lastDetail || '')];
  if (current === 'bots' && lastDetail === 'botDetail') { lastDetail = ''; vBots(); tg.BackButton.hide(); return; }
  if (current === 'payments' && lastDetail === 'payDetail') { lastDetail = ''; vPayments(); tg.BackButton.hide(); return; }
  if (current === 'users' && lastDetail === 'userBots') { lastDetail = ''; vUsers(); tg.BackButton.hide(); return; }
  goTab('dashboard');
  tg.BackButton.hide();
}
function markDetail(name){
  lastDetail = name;
  try { if (tg && tg.BackButton) tg.BackButton.show(); } catch(e){}
}

/* حالت خارج از تلگرام */
if (!initData) {
  document.querySelectorAll('.tabbar .tab').forEach(function(b){ b.style.opacity = .35; });
  var ph = $('#pageHead'); if (ph) ph.style.display = 'none';
  $('#view').innerHTML = '<div class="card" style="text-align:center;padding:34px 18px">' +
    '<div style="font-size:46px">🤖</div><h3 style="justify-content:center;margin:16px 0 10px">مینی‌اپ ربات‌ساز</h3>' +
    '<p style="color:var(--muted);font-size:13px;line-height:2.1">این پنل فقط داخل تلگرام کار می‌کند.<br>' +
    'از طریق دکمهٔ «🖥 پنل مدیریت» در منوی ربات اصلی آن را باز کنید.</p></div>';
} else {
  goTab('dashboard');
}
})();
