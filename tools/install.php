<?php
// نصب اولیه: php tools/install.php
// کارها: ساخت پوشه‌ها، ساخت config.php از روی example، ساخت دیتابیس مدیریتی، تنظیم دسترسی‌ها

$root = dirname(__DIR__);
foreach (['bots', 'data', 'templates/faxima', 'templates/mirza'] as $d) {
    if (!is_dir($root.'/'.$d)) { mkdir($root.'/'.$d, 0777, true); echo "mkdir $d\n"; }
}

// ===== تنظیم دسترسی‌ها (755 برای پوشه‌ها، 644 برای فایل‌ها) =====
require_once $root.'/src/PermissionManager.php';
echo "\n🔧 تنظیم دسترسی‌ها...\n";
$permResults = PermissionManager::fixAll($root);
if ($permResults['success']) {
    echo "✅ دسترسی‌ها تنظیم شدند:\n";
    echo "  • پوشه‌های تنظیم‌شده: " . $permResults['fixed_dirs'] . "\n";
    echo "  • فایل‌های تنظیم‌شده: " . $permResults['fixed_files'] . "\n";
} else {
    echo "⚠️  بعضی دسترسی‌ها تنظیم نشدند:\n";
    foreach ($permResults['errors'] as $err) {
        echo "  • $err\n";
    }
}
if (!empty($permResults['errors'])) {
    echo "\n💡 نکته: اگر از هاست اشتراکی استفاده می‌کنید، برخی دسترسی‌ها ممکن است محدود باشند.\n";
}

if (!file_exists($root.'/config.php')) {
    copy($root.'/config.example.php', $root.'/config.php');
    echo "config.php ساخته شد — آن را ویرایش کن (توکن، ادمین‌ها، base_url، MySQL).\n";
} else {
    echo "config.php وجود دارد.\n";
}

$cfg = require $root.'/config.php';
require_once $root.'/src/Store.php';
require_once $root.'/src/Manager.php';
$store = new Store($cfg['manager_db'], $cfg);
echo "manager DB OK (driver: {$store->getDriver()})\n";

// ===== پیش‌فرض‌های سیستم پرداخت (فقط اگر قبلاً ست نشده‌اند) =====
// مقادیر از config.php خوانده می‌شوند تا هاست و ربات از اول هماهنگ باشند.
require_once $root.'/src/Payment/Payments.php';
require_once $root.'/src/Payment/Gateways.php';
require_once $root.'/src/Payment/Limits.php';
require_once $root.'/src/Payment/Pricing.php';
require_once $root.'/src/Payment/NowPayments.php';
require_once $root.'/src/Payment/CardToCard.php';
// درگاه‌های اینترنتیِ تازه و ماژول نرخ دلار. وجودشان اینجا لازم است چون
// پیش‌فرض‌های اولیه از config.php خوانده و در settings کاشته می‌شوند.
require_once $root.'/src/Payment/ZarinPal.php';
require_once $root.'/src/Payment/AqaPay.php';
require_once $root.'/src/FxRate.php';
Payments::ensureSchema($store);
$payCfg = $cfg['payment'] ?? [];
if ($store->getSetting('pay_limit_price') === null) {
    $store->setSetting('pay_limit_price', (string)(int)($payCfg['limit_price'] ?? 50000));
}
// از رجیستری قالب‌ها خوانده می‌شود، نه از آرایهٔ ثابت — تا قالب تازه خودکار
// کلید قیمتش ساخته شود و در پنل «قیمت قالب» قابل تنظیم باشد.
foreach (array_keys(Manager::templates()) as $_t) {
    if ($store->getSetting(PaymentPricing::priceKey($_t)) === null) {
        $store->setSetting(PaymentPricing::priceKey($_t), (string)(int)(($payCfg['template_prices'][$_t] ?? 0)));
    }
}
if ($store->getSetting('pay_card_number') === null) $store->setSetting('pay_card_number', (string)($payCfg['card_number'] ?? ''));
if ($store->getSetting('pay_card_owner') === null) $store->setSetting('pay_card_owner', (string)($payCfg['card_owner'] ?? ''));
if ($store->getSetting('pay_toman_per_usd') === null) $store->setSetting('pay_toman_per_usd', (string)($payCfg['toman_per_usd'] ?? 100000));
if ($store->getSetting('pay_nowpay_api_key') === null) $store->setSetting('pay_nowpay_api_key', (string)($cfg['nowpayments']['api_key'] ?? ''));
if ($store->getSetting('pay_nowpay_ipn_secret') === null) $store->setSetting('pay_nowpay_ipn_secret', (string)($cfg['nowpayments']['ipn_secret'] ?? ''));
// کد پذیرنده/پینِ درگاه‌های اینترنتیِ تازه — از config.php خوانده و یک‌بار کاشته می‌شوند
if ($store->getSetting('pay_zarin_merchant_id') === null) $store->setSetting('pay_zarin_merchant_id', (string)($cfg['zarinpal']['merchant_id'] ?? ''));
if ($store->getSetting('pay_aqaye_pin') === null) $store->setSetting('pay_aqaye_pin', (string)($cfg['aqayepardakht']['pin'] ?? ''));

// ===== نرخ دلار: یک بار از API گرفته و کش می‌شود =====
// فقط اگر هنوز هیچ نرخی ثبت نشده باشد؛ اگر API در دسترس نبود همان
// پیش‌فرض config.php می‌ماند و فاکتورها خراب نمی‌شوند.
if ($store->getSetting(FxRate::K_RATE) === null || trim((string)$store->getSetting(FxRate::K_RATE, '')) === '') {
    $store->setSetting(FxRate::K_MANUAL, (string)($payCfg['toman_per_usd'] ?? 100000));
    $fxOk = false;
    try {
        $fx = FxRate::fetch(6);
        if (!empty($fx['ok'])) {
            FxRate::seed($store, (float)$fx['rate'], (string)$fx['source']);
            $fxOk = true;
        } else {
            $store->setSetting(FxRate::K_FETCHED, (string)$fx['error']);
        }
    } catch (Throwable $e) {
        $store->setSetting(FxRate::K_FETCHED, 'خطای شبکه: ' . $e->getMessage());
    }
    echo 'usd rate: ' . ($fxOk ? 'fetched (' . FxRate::source($store) . ')' : 'fallback (manual)') . "\n";
}

// وضعیت فعال/غیرفعال درگاه‌ها — فقط اگر قبلاً ست نشده باشند.
// نکتهٔ مهم: config.php قدیمی اصلاً کلید payment.enabled را ندارد؛ در آن حالت
// همه فعال می‌شوند (همان رفتار «غیبت کلید = فعال») وگرنه کل پرداخت بی‌دلیل خاموش می‌شد.
$enabledCfg = is_array($payCfg['enabled'] ?? null) ? $payCfg['enabled'] : [];
foreach (PaymentGateways::keys() as $_k) {
    if ($store->getSetting(PaymentGateways::enabledKey($_k)) === null) {
        $on = array_key_exists($_k, $enabledCfg) ? (bool)$enabledCfg[$_k] : true;
        $store->setSetting(PaymentGateways::enabledKey($_k), $on ? '1' : '0');
    }
}
echo "payment defaults OK\n";

// محافظت از پوشه data (Apache 2.4 — سینتکس قدیمی Deny from all فقط با mod_access_compat کار می‌کند)
// محتوا باید «دقیقاً» با نسخهٔ tracked در مخزن یکی باشد؛ قبلاً فقط «Require all denied» نوشته
// می‌شد و کامنت فارسی حذف می‌شد؛ در نتیجه هر بار اجرای install.php فایل را dirty می‌کرد.
file_put_contents(
    $root.'/data/.htaccess',
    "# دسترسی مستقیم به دیتابیس/لاگ مدیریتی ممنوع (Apache 2.4)\nRequire all denied\n"
);

// نکته: bots/.htaccess دستی نوشته نمی‌شود چون باید index.php / table.php / cron/ را
// برای وبهوک ربات‌های فرزند باز بگذارد (فایل .htaccess این پوشه در مخزن نگه‌داری می‌شود).
if (!file_exists($root.'/bots/.htaccess')) {
    echo "⚠️  bots/.htaccess پیدا نشد — از نسخه موجود در مخزن کپی کن.\n";
}
echo "done.\n";
