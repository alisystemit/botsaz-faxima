<?php
// ===== بررسی ایستای فراخوانی‌های کلاسی =====
// اجرا:  php tools/static_check.php
//
// هدف: مطمئن شویم هر Class::method( که در وبهوک اصلی، endpoint کریپتو یا نصب صدا زده
// می‌شود واقعاً وجود دارد. کلاس‌ها ماژولار هستند و امکان دارد متدی جابه‌جا یا حذف شود؛
// این تست جلوی Fatal error («Call to undefined method») را قبل از رسیدن به سرور می‌گیرد.

$root = dirname(__DIR__);

$classes = [
    'Nav'             => '/src/Nav.php',
    'Store'           => '/src/Store.php',
    'Manager'         => '/src/Manager.php',
    'BotApi'          => '/src/BotApi.php',
    'Logger'          => '/src/Logger.php',
    'DbBackup'        => '/src/DbBackup.php',
    // SourceUpdate به Manager/Logger نیاز دارد ⇒ بعد از آن‌ها
    'SourceUpdate'    => '/src/SourceUpdate.php',
    'Payments'        => '/src/Payment/Payments.php',
    'PaymentGateways' => '/src/Payment/Gateways.php',
    'PaymentLimits'   => '/src/Payment/Limits.php',
    'PaymentPricing'  => '/src/Payment/Pricing.php',
    'PaymentCard'     => '/src/Payment/CardToCard.php',
    'PaymentNowPay'   => '/src/Payment/NowPayments.php',
    'PaymentPanel'    => '/src/Payment/AdminPanel.php',
    // Texts به Nav و PaymentGateways وابسته است ⇒ بعد از آن‌ها
    'Texts'           => '/src/Texts.php',
];

// ---- ۱) متدها و ثابت‌های هر کلاس ----
$known = [];
foreach ($classes as $cls => $rel) {
    $src = file_get_contents($root . $rel);
    if ($src === false) { fwrite(STDERR, "cannot read {$rel}\n"); exit(1); }
    $members = [];
    if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m)) $members = $m[1];
    if (preg_match_all('/const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/', $src, $c)) $members = array_merge($members, $c[1]);
    $known[$cls] = array_values(array_unique($members));
}

// ---- ۲) بررسی فراخوانی‌ها ----
$targets = ['/bot.php', '/nowpayments_ipn.php', '/tools/install.php', '/src/Migrator.php'];
$bad = 0;
$checked = 0;
foreach ($targets as $rel) {
    $lines = file($root . $rel);
    if ($lines === false) { fwrite(STDERR, "cannot read {$rel}\n"); exit(1); }
    foreach ($lines as $i => $line) {
        if (!preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $hit) {
            [, $cls, $member] = $hit;
            if (!isset($known[$cls])) continue;                 // کلاس بیرونی (PDO، Throwable، ...)
            $checked++;
            if (in_array($member, $known[$cls], true)) continue;
            echo "MISSING  {$rel}:" . ($i + 1) . "  {$cls}::{$member}()\n";
            echo "         " . trim($line) . "\n";
            $bad++;
        }
    }
}

// ---- ۳) هر callback_data که bot.php/Nav.php می‌فرستند باید در handleCallback پردازش شود ----
//
// دقیقاً همان باگی که دکمهٔ «🏠 منو» در پنل‌های ⬆️/🔄 را می‌شکست: callback_data
// برابر 'menu' بود ولی هیچ شاخه‌ای برایش نبود ⇒ کاربر «این دکمه دیگر معتبر نیست»
// می‌گرفت. این بررسی جلوی تکرارش را برای همهٔ دکمه‌ها می‌گیرد.
$botSrc  = (string)file_get_contents($root . '/bot.php');
$navSrc  = (string)file_get_contents($root . '/src/Nav.php');

$navConst = [];
if (preg_match_all("/const\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*'([^']*)'/", $navSrc, $m, PREG_SET_ORDER)) {
    foreach ($m as $c) { $navConst[$c[1]] = $c[2]; }
}

$emitted = [];
foreach ([$botSrc, $navSrc] as $src) {
    if (!preg_match_all("/callback_data'\\s*=>\\s*(?:'([^']*)'|Nav::([A-Za-z_][A-Za-z0-9_]*))/", $src, $m, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($m as $hit) {
        $v = (isset($hit[1]) && $hit[1] !== '') ? $hit[1] : ($navConst[$hit[2]] ?? null);
        if (is_string($v) && $v !== '') $emitted[] = $v;
    }
}
$emitted = array_values(array_unique($emitted));

$exact = [];
if (preg_match_all('/\$data\\s*===\\s*(?:\'([^\']*)\'|Nav::([A-Za-z_][A-Za-z0-9_]*))/', $botSrc, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
        $v = (isset($hit[1]) && $hit[1] !== '') ? $hit[1] : ($navConst[$hit[2]] ?? null);
        if (is_string($v) && $v !== '') $exact[] = $v;
    }
}
$prefixes = [];
if (preg_match_all('/str_starts_with\\(\\s*\\$data\\s*,\\s*\'([^\']*)\'\\s*\\)/', $botSrc, $m, PREG_OFFSET_CAPTURE)) {
    $hits = $m[1];
    $n = count($hits);
    for ($i = 0; $i < $n; $i++) {
        $p = $hits[$i][0];
        $from = $hits[$i][1];
        // بدنهٔ همان if تا شروعِ شرط بعدی؛ بعضی خانواده‌ها (pay:/texts:/newbot:)
        // کل $data را به تابع دیگر می‌سپارند و بعضی‌ها (su:/src:/backup:) با
        // $action = substr داخلی توزیع می‌کنند. فقط برای دستهٔ دوم پسوند مهم است.
        $block = (string)substr($botSrc, $from, 1500);
        if ($i + 1 < $n) {
            $len = $hits[$i + 1][1] - $from;
            if ($len > 0 && $len < 1500) $block = (string)substr($botSrc, $from, $len);
        }
        $prefixes[$p] = ($prefixes[$p] ?? false) || strpos($block, '$action = substr(') !== false;
    }
}
// مقادیر داخلیِ خانواده‌های پیشوندیِ توزیع‌کننده: if (str_starts_with($data,'src:')) { $action=substr(...); if ($action==='go') …
$actions = [];
if (preg_match_all('/\$action\\s*===\\s*\'([^\']*)\'/', $botSrc, $m)) {
    $actions = $m[1];
}

$unhandled = [];
foreach ($emitted as $cb) {
    $handled = in_array($cb, $exact, true);
    if (!$handled) {
        foreach ($prefixes as $p => $dispatched) {
            if ($p === '' || strncmp($cb, $p, strlen($p)) !== 0) continue;
            $suffix = substr($cb, strlen($p));
            // خانوادهٔ واگذارشده: هر پسوندی پردازش می‌شود. خانوادهٔ توزیع‌کننده:
            // یا پسوند خالی است (رشته با قطعات متغیر ساخته شده) یا شاخهٔ داخلی دارد.
            if (!$dispatched || $suffix === '' || in_array($suffix, $actions, true)) { $handled = true; break; }
        }
    }
    if (!$handled) { $unhandled[] = $cb; $bad++; echo "NO HANDLER  callback_data '{$cb}' ارسال می‌شود ولی در handleCallback پردازش نمی‌شود\n"; }
}

echo $bad === 0
    ? "STATIC CHECK CLEAN ({$checked} فراخوانی کلاسی + " . count($emitted) . " callback_data بررسی شد)\n"
    : "STATIC CHECK FAILED: {$bad} مورد نامعتبر\n";
exit($bad === 0 ? 0 : 1);