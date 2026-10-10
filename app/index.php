<?php
/**
 * صفحهٔ مینی‌اپ ربات‌ساز — اسکلت HTML.
 * احراز هویت واقعی در api.php با initData تلگرام انجام می‌شود.
 */
$cfg = @include __DIR__ . '/../config.php';
$baseUrl = '';
if (is_array($cfg) && !empty($cfg['base_url'])) {
    $baseUrl = rtrim((string)$cfg['base_url'], '/');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>پنل مدیریتی ربات‌ساز</title>
<link rel="stylesheet" href="assets/css/app.css?v=4.1.0">
<script src="js/telegram-web-app.js"></script>
</head>
<body>
<div id="ptr"><div class="ptr-icon">⟳</div></div>
<div id="app">
    <header class="topbar">
        <div class="brand">
            <div class="brand-logo">🤖</div>
            <div>
                <div class="brand-title">ربات‌ساز فاکسیما</div>
                <div class="brand-sub" id="brandSub">پنل مدیریتی</div>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <button class="icon-btn" id="btnHelp" title="راهنما">؟</button>
            <button class="icon-btn" id="btnRefresh" title="بروزرسانی">⟳</button>
        </div>
    </header>

    <div class="pagehead" id="pageHead"><h2 id="pageTitle">داشبورد</h2><span id="pageSub"></span></div>

    <main id="view" class="view">
        <div class="skel" style="height:76px"></div>
        <div class="skel"></div>
        <div class="skel"></div>
    </main>

    <nav class="tabbar">
        <button class="tab active" data-tab="dashboard">🏠<span>خانه</span></button>
        <button class="tab" data-tab="bots">🤖<span>ربات‌ها</span></button>
        <button class="tab" data-tab="pending" id="tabPending">📥<span>درخواست‌ها</span><i class="dot" id="dotPending"></i></button>
        <button class="tab" data-tab="payments" id="tabPay">💳<span>پرداخت‌ها</span><i class="dot" id="dotPay"></i></button>
        <button class="tab" data-tab="users">👥<span>کاربران</span></button>
        <button class="tab" data-tab="templates">🧩<span>قالب‌ها</span></button>
        <button class="tab" data-tab="settings">⚙️<span>تنظیمات</span></button>
    </nav>
</div>
<div id="helpModal" class="modal" style="display:none">
  <div class="modal-box">
    <div class="modal-head"><b>راهنمای پنل</b><button class="icon-btn" id="btnHelpClose">✕</button></div>
    <div class="modal-body">
      <p>🏠 <b>داشبورد:</b> آمار کل، درآمد و نمودارها</p>
      <p>🤖 <b>ربات‌ها:</b> فعال/غیرفعال، جستجو و حذف</p>
      <p>📥 <b>درخواست‌ها:</b> تأیید یا رد درخواست ساخت</p>
      <p>💳 <b>پرداخت‌ها:</b> تأیید/رد، فیلتر تاریخ و خروجی CSV</p>
      <p>👥 <b>کاربران:</b> سقف ساخت، ادمین و تعلیق</p>
      <p>🧩 <b>قالب‌ها و تنظیمات:</b> قیمت‌ها، بکاپ، تعمیرات</p>
      <p class="hint">بررسی کامل سرور از ترمینال: php tools/healthcheck.php</p>
    </div>
  </div>
</div>
<script>window.__MINIAPP_API__ = 'api.php';</script>
<script src="assets/js/app.js?v=4.3.0"></script>
</body>
</html>
