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
<link rel="stylesheet" href="assets/css/app.css?v=1.0.0">
<script src="js/telegram-web-app.js"></script>
</head>
<body>
<div id="app">
    <header class="topbar">
        <div class="brand">
            <div class="brand-logo">🤖</div>
            <div>
                <div class="brand-title">ربات‌ساز فاکسیما</div>
                <div class="brand-sub" id="brandSub">پنل مدیریتی</div>
            </div>
        </div>
        <button class="icon-btn" id="btnRefresh" title="بروزرسانی">⟳</button>
    </header>

    <main id="view" class="view">
        <div class="loader"><div class="spinner"></div><p>در حال بارگذاری…</p></div>
    </main>

    <nav class="tabbar">
        <button class="tab active" data-tab="dashboard">🏠<span>خانه</span></button>
        <button class="tab" data-tab="bots">🤖<span>ربات‌ها</span></button>
        <button class="tab" data-tab="pending">📥<span>درخواست‌ها</span></button>
        <button class="tab" data-tab="payments">💳<span>پرداخت‌ها</span></button>
        <button class="tab" data-tab="users">👥<span>کاربران</span></button>
        <button class="tab" data-tab="settings">⚙️<span>تنظیمات</span></button>
    </nav>
</div>
<script>window.__MINIAPP_API__ = 'api.php';</script>
<script src="assets/js/app.js?v=1.0.0"></script>
</body>
</html>
