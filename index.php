<?php
/**
 * صفحهٔ ریشه — فقط برای اینکه «/» به‌جای 403 جواب بدهد.
 *
 * قبلاً پوشهٔ ریشه index.php نداشت و اگر نمایش پوشه بسته بود، / با 403 برمی‌گشت.
 * این صفحه عمداً هیچ اطلاعات داخلی (توکن/دیتابیس/مسیر) را لو نمی‌دهد؛
 * تشخیص کامل از طریق CLI است: php tools/healthcheck.php
 */
header('Content-Type: text/html; charset=utf-8');
http_response_code(200);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ربات‌ساز</title>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
             background:#0f172a;color:#e2e8f0;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}
        .card{max-width:32rem;padding:2rem 2.5rem;background:#1e293b;border:1px solid #334155;
              border-radius:1rem;text-align:center;line-height:1.9}
        h1{margin:0 0 .5rem;font-size:1.6rem;color:#38bdf8}
        p{margin:.4rem 0;color:#94a3b8;font-size:.95rem}
        code{background:#0f172a;padding:.15rem .5rem;border-radius:.35rem;color:#fbbf24}
    </style>
</head>
<body>
<div class="card">
    <h1>ربات‌ساز فعال است</h1>
    <p>این صفحه فقط نشان می‌دهد وب‌سرور درست کار می‌کند.</p>
    <p>نقطهٔ اتصال وبهوک: <code>bot.php</code></p>
    <p>برای وضعیت کامل از ترمینال اجرا کنید: <code>php tools/healthcheck.php</code></p>
</div>
</body>
</html>
