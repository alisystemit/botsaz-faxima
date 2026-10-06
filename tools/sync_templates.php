<?php
// ===== همگام‌سازی templates/ با ریپوی هر قالب =====
//
// تنها منبع حقیقتِ «سورسِ قالب‌ها» همین اسکریپت است. هرجا که لازم باشد
// templates/ تازه شود — دکمهٔ تلگرام، کرونِ دیسپچر (pending_update)، یا
// اجرای دستی — همه از اینجا می‌گذرند تا رفتار یک‌دست باشد:
//
//   ۱) هر قالب از ریپوی گیتهابِ خودش (Manager::templates()['repo']) fetch می‌شود
//   ۲) محتوای templates/<dir> دقیقاً با کامیتِ تازه بازنویسی می‌شود
//   ۳) config.php، .htaccess، data/ و دیتابیس‌ها هرگز لمس نمی‌شوند
//   ۴) شمارندهٔ «ربات‌های عقب‌افتاده» پاک می‌شود
//
// نکتهٔ مهم: این اسکریپت templates/ را با GitHub هم‌تراز می‌کند و به‌همین دلیل
// باید بعد از هر `git reset --hard` روی ریپوی ربات‌ساز دوباره اجرا شود — وگرنه
// بروزرسانیِ سورسِ قالب‌ها برگشته می‌شود و «رباتِ جدید باز همان سورسِ قدیمی»
// تحویل می‌دهد (همان باگِ گزارش‌شده).
//
// استفاده: php tools/sync_templates.php [timeout-seconds]

require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/SourceUpdate.php';
require_once __DIR__ . '/../src/SelfUpdate.php';

$timeout = isset($argv[1]) && is_numeric($argv[1]) ? max(60, (int)$argv[1]) : 600;
$log = Logger::getInstance();

echo "▶ fetch مخازن قالب‌ها…\n";
$fetch = SelfUpdate::fetchSource();
if (!$fetch['ok']) {
    echo "⚠️ fetch از همهٔ مخازن کامل نشد: " . trim((string)$fetch['out']) . "\n";
    $log->warning('source', 'sync_templates fetch warning: ' . trim((string)$fetch['out']));
    // کلون محلی اگر باشد ادامه می‌دهیم؛ updateTemplates خودش خطای هر قالب را گزارش می‌کند
}

echo "▶ همگام‌سازی templates/…\n";
$run = SelfUpdate::updateTemplates($timeout);

try { SourceUpdate::clearCounter(); } catch (Throwable $e) { /* شمارندهٔ کش‌دار است؛ سقف ۱۰ دقیقه هم هست */ }

$failed = 0;
foreach ((array)($run['results'] ?? []) as $key => $res) {
    if (empty($res['ok'])) {
        $failed++;
        echo "✘ {$key}: " . (string)($res['error'] ?? '?') . "\n";
    } elseif (!empty($res['skipped'])) {
        echo "• {$key}: از قبل به‌روز بود\n";
    } else {
        echo "✔ {$key}: " . (int)($res['applied'] ?? 0) . " فایل\n";
    }
}

if (!empty($run['queued'])) {
    echo "ℹ️ دسترسی مستقیم وجود نداشت؛ به کرون واگذار شد: " . (string)($run['out'] ?? '') . "\n";
    exit(0);
}

if ($failed > 0) {
    $log->error('source', "sync_templates finished with {$failed} failed template(s)");
    echo "✘ پایان با {$failed} خطا\n";
    exit(1);
}
$log->info('source', 'sync_templates ok');
echo "✔ templates/ با ریپوی هر قالب هم‌تراز شد.\n";
exit(0);
