<?php
// ===== IPN endpoint نقدشونده NOWPayments =====
// در پنل NOWPayments این آدرس را بگذارید:
//   https://yourdomain/botsaz-faxima/nowpayments_ipn.php
// راستی‌آزمایی: HMAC_SHA512(raw_body, ipn_secret) == header x-nowpayments-sig
// بعد از تأیید: پرداخت paid + grant (افزایش لیمیت/ووچر قالب) + اطلاع به کاربر.

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

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
if (!is_file($cfgFile)) { http_response_code(500); echo json_encode(['ok' => false]); exit; }
$cfg = require $cfgFile;
$TOKEN = (string)($cfg['main_token'] ?? '');

$store = new Store($cfg['manager_db'], $cfg);
Payments::ensureSchema($store);

// اگر ادمین درگاه کریپتو را خاموش کرده، IPN نباید چیزی تأیید کند.
// (پرداخت‌هایی که قبلاً قطعی شده‌اند دست‌نخورده می‌مانند چون markCryptoPaid تکراری‌ها را رد می‌کند.)
if (!PaymentGateways::isEnabled($store, PaymentGateways::NOWPAY)) {
    Logger::getInstance()->warning('nowpay_ipn', 'gateway disabled — ignored');
    echo json_encode(['ok' => true, 'ack' => true, 'disabled' => true]);
    exit;
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
if (!PaymentNowPay::verifyIpn($raw, (string)$sig, $secret)) {
    Logger::getInstance()->warning('nowpay_ipn', 'bad signature');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad signature']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad json']);
    exit;
}

$status = PaymentNowPay::extractStatus($data);
$orderId = PaymentNowPay::extractOrderId($data);
Logger::getInstance()->info('nowpay_ipn', "order={$orderId} status={$status}");

if (!PaymentNowPay::isPaidStatus($status)) {
    // waiting/failed/... فقط ثبت می‌شود؛ 200 برمی‌گردد تا NOWPayments دوباره بفرستد
    echo json_encode(['ok' => true, 'ack' => true, 'status' => $status]);
    exit;
}

$pay = Payments::getPaymentByOrderId($store, $orderId);
// fallback: بعضی IPNها فقط payment_id دارند
if (!$pay && !empty($data['payment_id'])) $pay = Payments::getPaymentByExtId($store, (string)$data['payment_id']);
if (!$pay && !empty($data['invoice_id'])) $pay = Payments::getPaymentByExtId($store, (string)$data['invoice_id']);
if (!$pay) {
    Logger::getInstance()->warning('nowpay_ipn', 'payment not found for order=' . $orderId);
    echo json_encode(['ok' => true, 'ack' => true, 'unknown' => true]);
    exit;
}

$done = Payments::markCryptoPaid($store, (int)$pay['id']);
if ($done && $TOKEN !== '' && $TOKEN !== 'PUT_MAIN_BOT_TOKEN_HERE') {
    $note = $done['grant_note'] ?? '';
    BotApi::send($TOKEN, (int)$pay['user_id'],
        "✅ <b>پرداخت کریپتویی تأیید شد!</b>\n" . Payments::describe($done)
        . ($note !== '' ? "\n🎁 {$note}" : '')
        . "\n\nحالا «🤖 ساخت ربات جدید» را بزنید.");
}
echo json_encode(['ok' => true]);
