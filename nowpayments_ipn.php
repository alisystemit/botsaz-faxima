<?php
// ===== IPN endpoint نقدشونده NOWPayments =====
// در پنل NOWPayments این آدرس را بگذارید:
//   https://yourdomain/botsaz-faxima/nowpayments_ipn.php
// راستی‌آزمایی: HMAC_SHA512(raw_body, ipn_secret) == header x-nowpayments-sig
// بعد از تأیید: پرداخت paid + grant (افزایش لیمیت/ووچر قالب) + اطلاع به کاربر.

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// خطای PHP نباید قالب JSON را خراب کند؛ NOWPayments پاسخ نامعتبر را خطا
// حساب می‌کند و IPN را بارها و بارها دوباره می‌فرستد.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$GLOBALS['__ipn_json_sent'] = false;

function ipnDone(array $out): void
{
    $GLOBALS['__ipn_json_sent'] = true;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    if (!empty($GLOBALS['__ipn_json_sent'])) return;
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)$err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    try {
        Logger::getInstance()->error('nowpay_ipn_fatal',
            ($err['message'] ?? '?') . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? 0));
    } catch (Throwable $e) {}
    // ack معتبر ⇒ سرویس بی‌نهایت تلاش نمی‌کند؛ خطا در لاگ ثبت شده
    echo json_encode(['ok' => true, 'ack' => true]);
});

require_once __DIR__ . '/src/BotApi.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Manager.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Payment/Payments.php';
require_once __DIR__ . '/src/Payment/Gateways.php';
require_once __DIR__ . '/src/Payment/Limits.php';
require_once __DIR__ . '/src/Payment/Pricing.php';
require_once __DIR__ . '/src/Payment/NowPayments.php';
require_once __DIR__ . '/src/Payment/CardToCard.php';

header('Content-Type: application/json; charset=utf-8');

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) { http_response_code(500); ipnDone(['ok' => false]); }
$cfg = require $cfgFile;
$TOKEN = (string)($cfg['main_token'] ?? '');

$store = new Store($cfg['manager_db'], $cfg);
try { Payments::ensureSchema($store); } catch (Throwable $e) {
    Logger::getInstance()->error('nowpay_ipn', 'ensureSchema failed: ' . $e->getMessage());
    ipnDone(['ok' => false, 'error' => 'schema']);
}

// اگر ادمین درگاه کریپتو را خاموش کرده، IPN نباید چیزی تأیید کند.
// (پرداخت‌هایی که قبلاً قطعی شده‌اند دست‌نخورده می‌مانند چون markCryptoPaid تکراری‌ها را رد می‌کند.)
if (!PaymentGateways::isEnabled($store, PaymentGateways::NOWPAY)) {
    Logger::getInstance()->warning('nowpay_ipn', 'gateway disabled — ignored');
    ipnDone(['ok' => true, 'ack' => true, 'disabled' => true]);
}

$raw = file_get_contents('php://input') ?: '';
// هدر در Apache/Nginx/FPM به شکل‌های مختلف می‌آید
$sig = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? $_SERVER['HTTP_X_NOWPAYMENT_SIG'] ?? '';
if ($sig === '' && function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $k => $v) {
        if (strtolower($k) === 'x-nowpayments-sig') { $sig = (string)$v; break; }
    }
}

$secret = PaymentNowPay::ipnSecret($store, $cfg);
// secret خالی یعنی تأیید خودکار پیکربندی نشده ⇒ fail-closed.
// (اگر راه می‌افتاد، هر کسی با یک HMAC دلخواه لیمیت رایگان می‌گرفت.)
if (trim((string)$secret) === '') {
    Logger::getInstance()->error('nowpay_ipn', 'IPN secret not configured — rejected');
    http_response_code(403);
    ipnDone(['ok' => false, 'error' => 'ipn secret missing']);
}
if (!PaymentNowPay::verifyIpn($raw, (string)$sig, $secret)) {
    Logger::getInstance()->warning('nowpay_ipn', 'bad signature');
    http_response_code(403);
    ipnDone(['ok' => false, 'error' => 'bad signature']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    ipnDone(['ok' => false, 'error' => 'bad json']);
}

$status = PaymentNowPay::extractStatus($data);
$orderId = PaymentNowPay::extractOrderId($data);
Logger::getInstance()->info('nowpay_ipn', "order={$orderId} status={$status}");

if (!PaymentNowPay::isPaidStatus($status)) {
    // waiting/failed/... فقط ثبت می‌شود؛ 200 برمی‌گردد تا NOWPayments دوباره بفرستد
    ipnDone(['ok' => true, 'ack' => true, 'status' => $status]);
}

$pay = Payments::getPaymentByOrderId($store, $orderId);
// fallback: بعضی IPNها فقط payment_id دارند.
// ما «invoice_id» را در ext_id ذخیره کرده‌ایم نه payment_id، پس این مسیر
// فقط وقتی کار می‌کند که payment_id قبلاً جایی ثبت شده باشد (setExtId در
// مسیر «🔄 بررسی وضعیت» و همین‌جا انجام می‌شود).
if (!$pay && !empty($data['payment_id'])) $pay = Payments::getPaymentByExtId($store, (string)$data['payment_id']);
if (!$pay && !empty($data['invoice_id'])) $pay = Payments::getPaymentByExtId($store, (string)$data['invoice_id']);
if (!$pay) {
    Logger::getInstance()->warning('nowpay_ipn', 'payment not found for order=' . $orderId);
    ipnDone(['ok' => true, 'ack' => true, 'unknown' => true]);
}

// payment_id را هم نگه دار تا fallbackهای بعدی (و «🔄 بررسی وضعیت»)
// بتوانند فاکتور را مستقیم استعلام کنند.
if (!empty($data['payment_id']) && (string)($pay['ext_id'] ?? '') !== (string)$data['payment_id']) {
    try { Payments::setExtId($store, (int)$pay['id'], (string)$data['payment_id']); } catch (Throwable $e) {}
}

$done = Payments::markCryptoPaid($store, (int)$pay['id']);
if ($done && $TOKEN !== '' && $TOKEN !== 'PUT_MAIN_BOT_TOKEN_HERE') {
    $note = (string)($done['grant_note'] ?? '');
    BotApi::send($TOKEN, (int)$pay['user_id'],
        "✅ <b>پرداخت کریپتویی تأیید شد!</b>\n" . Payments::describe($done)
        . ($note !== '' ? "\n🎁 {$note}" : '')
        . "\n\nحالا «🤖 ساخت ربات جدید» را بزنید.");
}
ipnDone(['ok' => true]);
