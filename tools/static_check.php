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
    'Payments'        => '/src/Payment/Payments.php',
    'PaymentGateways' => '/src/Payment/Gateways.php',
    'PaymentLimits'   => '/src/Payment/Limits.php',
    'PaymentPricing'  => '/src/Payment/Pricing.php',
    'PaymentCard'     => '/src/Payment/CardToCard.php',
    'PaymentNowPay'   => '/src/Payment/NowPayments.php',
    'PaymentPanel'    => '/src/Payment/AdminPanel.php',
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

echo $bad === 0
    ? "STATIC CHECK CLEAN ({$checked} فراخوانی کلاسی بررسی شد)\n"
    : "STATIC CHECK FAILED: {$bad} مورد نامعتبر\n";
exit($bad === 0 ? 0 : 1);