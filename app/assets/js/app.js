(function(){
'use strict';
var tg = window.Telegram && window.Telegram.WebApp;
if (tg) { tg.ready(); tg.expand(); try { tg.setHeaderColor('#0b1220'); } catch(e){} }
var API = window.__MINIAPP_API__ || 'api.php';
var initData = (tg && tg.initData) ? tg.initData
  : (new URLSearchParams(location.search).get('initData') || '');

function $(s){ return document.querySelector(s); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function fmt(n){ try { return Number(n||0).toLocaleString('fa-IR'); } catch(e){ return n; } }

function call(action, opts){
  opts = opts || {};
  var method = opts.method || 'GET';
  var url = API + '?action=' + encodeURIComponent(action);
  var body = null, headers = {};
  if (method === 'POST') {
    body = new URLSearchParams(opts.data || {});
    body.append('initData', initData);
    url += '&initData=' + encodeURIComponent(initData);
  } else {
    url += '&initData=' + encodeURIComponent(initData);
    if (opts.data) { for (var k in opts.data) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.data[k]); }
  }
  return fetch(url, { method: method, body: body }).then(function(r){ return r.json(); });
}

function toast(msg){
  var t = document.createElement('div'); t.className = 'toast'; t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function(){ t.remove(); }, 2200);
  if (tg && tg.showPopup) {} // نگه‌داشته‌شده برای آینده
}

/* ---------- تب‌ها ---------- */
var current = 'dashboard';
document.querySelectorAll('.tabbar .tab').forEach(function(b){
  b.addEventListener('click', function(){
    document.querySelectorAll('.tabbar .tab').forEach(function(x){x.classList.remove('active');});
    b.classList.add('active');
    current = b.dataset.tab;
    render();
  });
});
$('#btnRefresh').addEventListener('click', render);

function loading(){ $('#view').innerHTML = '<div class="loader"><div class="spinner"></div><p>در حال بارگذاری…</p></div>'; }
function err(m){ $('#view').innerHTML = '<div class="card" style="text-align:center;color:#f87171">❌ ' + esc(m) + '</div>'; }

function render(){
  loading();
  if (current === 'dashboard') return vDashboard();
  if (current === 'bots') return vBots();
  if (current === 'pending') return vPending();
  if (current === 'payments') return vPayments();
  if (current === 'users') return vUsers();
  if (current === 'settings') return vSettings();
}

/* ---------- داشبورد ---------- */
function vDashboard(){
  call('dashboard').then(function(d){
    if (!d.ok) return err(d.msg || 'خطا');
    var byType = (d.by_type||[]).map(function(r){ return '<div class="kv"><b>'+esc(r.type)+'</b><span>'+fmt(r.c)+'</span></div>'; }).join('') || '<div class="s" style="color:var(--muted)">—</div>';
    var bots = (d.recent_bots||[]).map(function(b){
      return '<div class="row"><div><div class="t">@'+esc(b.bot_username||b.folder)+'</div><div class="s">'+esc(b.type)+' • '+esc(b.created_at||'')+'</div></div>' +
             '<span class="badge '+(b.status==='active'?'b-ok':'b-bad')+'">'+(b.status==='active'?'فعال':'غیرفعال')+'</span></div>';
    }).join('') || '<div class="empty">هنوز رباتی ساخته نشده</div>';
    $('#view').innerHTML =
      '<div class="grid2">' +
        stat('🤖','ربات‌ها', d.bots) + stat('👥','کاربران', d.users) +
        stat('📥','درخواست‌ها', d.pending) + stat('🧾','پرداخت باز', d.open_payments) +
      '</div>' +
      '<div class="card" style="margin-top:12px"><h3>💰 مجموع درآمد (تومان)</h3><b style="font-size:20px">'+fmt(d.revenue)+'</b></div>' +
      '<div class="card"><h3>📊 توزیع قالب‌ها</h3>'+byType+'</div>' +
      '<div class="card"><h3>🆕 آخرین ربات‌ها</h3>'+bots+'</div>';
  }).catch(function(){ err('ارتباط برقرار نشد — آیا داخل تلگرام باز کردی؟'); });
}
function stat(ic,label,val){ return '<div class="stat"><div style="font-size:18px">'+ic+'</div><b>'+fmt(val)+'</b><span>'+label+'</span></div>'; }

/* ---------- ربات‌ها ---------- */
function vBots(){
  call('bots_list').then(function(d){
    if (!d.ok) return err(d.msg);
    if (!d.bots.length) return $('#view').innerHTML = '<div class="empty">رباتی وجود ندارد</div>';
    $('#view').innerHTML = d.bots.map(function(b){
      return '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center">' +
        '<div><b>@'+esc(b.bot_username || b.folder)+'</b><div class="s" style="color:var(--muted);font-size:11px">'+esc(b.type)+' • مالک: '+esc(b.first_name||b.username||b.owner_id)+'</div></div>' +
        '<span class="badge '+(b.status==='active'?'b-ok':'b-bad')+'">'+(b.status==='active'?'فعال':'غیرفعال')+'</span></div>' +
        '<div style="display:flex;gap:8px;margin-top:12px">' +
          (b.status==='active'
            ? '<button class="btn btn-ghost btn-sm" onclick="MX.setStatus('+b.id+',\'disabled\')">⏸ غیرفعال</button>'
            : '<button class="btn btn-ok btn-sm" onclick="MX.setStatus('+b.id+',\'active\')">▶️ فعال‌سازی</button>') +
          '<button class="btn btn-bad btn-sm" onclick="MX.delBot('+b.id+')">🗑 حذف</button></div></div>';
    }).join('');
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- درخواست‌ها ---------- */
function vPending(){
  call('pending_list').then(function(d){
    if (!d.ok) return err(d.msg);
    var pend = d.requests.filter(function(r){return r.status==='pending';});
    if (!pend.length) return $('#view').innerHTML = '<div class="empty">درخواست معلقی نیست ✅</div>';
    $('#view').innerHTML = pend.map(function(r){
      return '<div class="card"><div class="t">کاربر <b>'+r.user_id+'</b> • قالب: <b>'+esc(r.type)+'</b></div>' +
        '<div class="s" style="color:var(--muted);font-size:11px;margin-top:4px">'+esc(r.created_at)+'</div>' +
        '<div style="display:flex;gap:8px;margin-top:12px">' +
        '<button class="btn btn-ok btn-sm" onclick="MX.req('+r.id+',\'approve\')">✅ تأیید</button>' +
        '<button class="btn btn-bad btn-sm" onclick="MX.req('+r.id+',\'decline\')">❌ رد</button></div></div>';
    }).join('');
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- پرداخت‌ها ---------- */
var payFilter = '';
function vPayments(){
  var tabs = '<div class="pill-tabs">' +
    ['', 'pending', 'await_admin', 'await_receipt', 'paid', 'declined'].map(function(s){
      var label = s==='' ? 'همه' : ({pending:'در انتظار',await_admin:'منتظر تأیید',await_receipt:'منتظر فیش',paid:'موفق',declined:'ردشده'}[s]);
      return '<button class="'+(payFilter===s?'active':'')+'" onclick="MX.setPayFilter(\''+s+'\')">'+label+'</button>';
    }).join('') + '</div>';
  call('payments_list', {data: payFilter ? {status: payFilter} : {}}).then(function(d){
    if (!d.ok) return err(d.msg);
    var rows = d.payments.map(function(p){
      var canAct = (p.status==='await_admin' || p.status==='await_receipt');
      return '<div class="card"><div style="display:flex;justify-content:space-between"><b>#'+p.id+' • '+esc(p.kind==='limit'?'اسلات':p.template||'—')+'</b>' +
        '<span class="badge b-info">'+esc(p.status)+'</span></div>' +
        '<div class="s" style="color:var(--muted);font-size:12px;margin-top:6px">کاربر '+p.user_id+' • '+fmt(p.amount)+' تومان • '+esc(p.method||'—')+'</div>' +
        (canAct ? '<div style="display:flex;gap:8px;margin-top:10px"><button class="btn btn-ok btn-sm" onclick="MX.pay('+p.id+',\'approve\')">✅ تأیید</button>' +
        '<button class="btn btn-bad btn-sm" onclick="MX.pay('+p.id+',\'decline\')">❌ رد</button></div>' : '') + '</div>';
    }).join('') || '<div class="empty">موردی یافت نشد</div>';
    $('#view').innerHTML = tabs + rows;
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- کاربران ---------- */
function vUsers(){
  call('users_list').then(function(d){
    if (!d.ok) return err(d.msg);
    $('#view').innerHTML = d.users.map(function(u){
      return '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center">' +
        '<div><b>'+esc(u.first_name || u.username || u.user_id)+'</b> <span class="s" style="color:var(--muted)">('+u.user_id+')</span></div>' +
        '<span class="badge '+(u.is_allowed?'b-ok':'b-warn')+'">'+(u.is_allowed?'مجاز':'معلق')+'</span></div>' +
        '<div class="s" style="color:var(--muted);font-size:12px;margin-top:6px">ساخته: '+u.build_count+' • سقف: '+u.bot_limit+' • ادمین: '+(u.is_admin?'✅':'—')+'</div>' +
        '<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">' +
          '<button class="btn btn-ghost btn-sm" onclick="MX.lim('+u.user_id+','+(Number(u.bot_limit)+1)+')">➕ سقف</button>' +
          '<button class="btn btn-ghost btn-sm" onclick="MX.lim('+u.user_id+','+Math.max(0,Number(u.bot_limit)-1)+')">➖ سقف</button>' +
          '<button class="btn btn-ghost btn-sm" onclick="MX.adm('+u.user_id+','+(u.is_admin?0:1)+')">'+(u.is_admin?'ادمین‌زدایی':'ادمین‌سازی')+'</button>' +
        '</div></div>';
    }).join('') || '<div class="empty">کاربری نیست</div>';
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- تنظیمات ---------- */
function vSettings(){
  call('settings_get').then(function(d){
    if (!d.ok) return err(d.msg);
    var gw = d.gateways.map(function(g){
      return '<div class="row"><div class="t">'+esc(g.label)+'</div><div class="switch '+(g.enabled?'on':'')+'" onclick="MX.gw(\''+g.key+'\','+(g.enabled?0:1)+',this)"></div></div>';
    }).join('');
    $('#view').innerHTML =
      '<div class="card"><h3>🔧 حالت کلی</h3>' +
        '<div class="row"><div><div class="t">حالت تعمیرات</div><div class="s" style="color:var(--muted)">ساخت ربات متوقف می‌شود</div></div>' +
          '<div class="switch '+(d.maintenance?'on':'')+'" onclick="MX.setS(\'maintenance\','+(d.maintenance?0:1)+',this)"></div></div>' +
        '<div class="row"><div><div class="t">نیاز به تأیید ادمین</div><div class="s" style="color:var(--muted)">درخواست ساخت باید تأیید شود</div></div>' +
          '<div class="switch '+(d.approval_required?'on':'')+'" onclick="MX.setS(\'approval\','+(d.approval_required?0:1)+',this)"></div></div>' +
      '</div>' +
      '<div class="card"><h3>💳 پرداخت</h3>' + gw +
        '<label class="lbl">شمارهٔ کارت</label><input id="in_card" type="text" value="'+esc(d.card_number)+'">' +
        '<label class="lbl">صاحب کارت</label><input id="in_owner" type="text" value="'+esc(d.card_owner)+'">' +
        '<label class="lbl">قیمت هر اسلات (تومان)</label><input id="in_price" type="number" value="'+esc(d.limit_price)+'">' +
        '<button class="btn btn-acc btn-sm" style="margin-top:12px" onclick="MX.savePayment()">💾 ذخیره</button>' +
      '</div>';
  }).catch(function(){ err('خطا در دریافت'); });
}

/* ---------- اکشن‌ها ---------- */
window.MX = {
  setStatus: function(id, s){ call('bot_status', {method:'POST', data:{id:id,status:s}}).then(function(d){ toast(d.ok?'انجام شد':(d.msg||'خطا')); render(); }); },
  delBot: function(id){ if (tg && tg.showConfirm) { tg.showConfirm('ربات حذف شود؟', function(ok){ if(ok) doDel(); }); } else if (confirm('ربات حذف شود؟')) doDel();
    function doDel(){ call('bot_delete', {method:'POST', data:{id:id}}).then(function(d){ toast(d.ok?'حذف شد':(d.msg||'خطا')); render(); }); } },
  req: function(id, a){ call(a==='approve'?'pending_approve':'pending_decline', {method:'POST', data:{id:id}}).then(function(d){ toast(d.ok?'انجام شد':(d.msg||'خطا')); render(); }); },
  setPayFilter: function(s){ payFilter = s; vPayments(); },
  pay: function(id, a){ call(a==='approve'?'payment_approve':'payment_decline', {method:'POST', data:{id:id}}).then(function(d){ toast(d.ok?('انجام شد'+(d.note?': '+d.note:'')):(d.msg||'خطا')); render(); }); },
  lim: function(u, n){ call('user_set_limit', {method:'POST', data:{user_id:u, limit:n}}).then(function(d){ toast(d.ok?'سقف: '+n:(d.msg||'خطا')); render(); }); },
  adm: function(u, v){ call('user_toggle_admin', {method:'POST', data:{user_id:u, v:v}}).then(function(d){ toast(d.ok?'انجام شد':(d.msg||'خطا')); render(); }); },
  gw: function(k, v, el){ el.classList.toggle('on'); call('settings_set', {method:'POST', data:{k:'gateway', key:k, v:v}}).then(function(d){ toast(d.ok?'ذخیره شد':(d.msg||'خطا')); }); },
  setS: function(k, v, el){ el.classList.toggle('on'); call('settings_set', {method:'POST', data:{k:k, v:String(v)}}).then(function(d){ toast(d.ok?'ذخیره شد':(d.msg||'خطا')); }); },
  savePayment: function(){
    var card = $('#in_card').value, owner = $('#in_owner').value, price = $('#in_price').value;
    call('settings_set', {method:'POST', data:{k:'card_number', v:card}})
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'card_owner', v:owner}}); })
      .then(function(){ return call('settings_set', {method:'POST', data:{k:'limit_price', v:price}}); })
      .then(function(){ toast('ذخیره شد ✅'); render(); });
  }
};

/* initData نبود → حالت نمایشی (بیرون تلگرام) */
if (!initData) {
  $('#brandSub').textContent = 'حالت پیش‌نمایش (خارج از تلگرام)';
  $('#view').innerHTML = '<div class="card" style="text-align:center">برای استفادهٔ واقعی، مینی‌اپ را از داخل تلگرام باز کنید.<br><br>' +
    '<a class="btn btn-acc" href="index.php" onclick="location.reload();return false;">تلاش مجدد</a></div>';
} else {
  render();
}
})();
