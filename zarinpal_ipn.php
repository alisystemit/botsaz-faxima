<?php
// ===== کال‌بک/تأیید پرداخت زرین‌پال =====
//
// در پنل زرین‌پال این آدرس را به‌عنوان «آدرس بازگشت» (callback_url) ثبت کنید:
//   https://yourdomain/botsaz-faxima/zarinpal_ipn.php
//
// زرین‌پال کاربر را با این پارامترها برمی‌گرداند:
//   ?Authority=A00000…&Status=OK|NOK
//
// نکته‌های امنیتی که رعایت شده:
//  ۱) هیچ پارامتری «به‌تنهایی» پرداخت را paid نمی‌کند؛ اول باید در وضعیتِ
//     «در انتظار پرداخت» (await_pay) باشد و بعد verify روی زرین‌پال انجام شود.
//  ۲) verify خودش idempotent است (کد ۱۰۰/۱۰۱ هر دو یعنی پول رسیده) و
//     Payments::markAutoPaid هم اتمیک ⇒ بازکردن چندبارهٔ این صفحه بی‌اثر است.
//  ۳) اگر ادمین درگاه را خاموش کرده باشد، هیچ تأییدی انجام نمی‌شود.
//  ۴) مبلغِ ورودی کاربر هیچ‌وقت مبنا نیست؛ مبلغ از دیتابیس خودمان خوانده می‌شود
//     (وگرنه کسی با Authority دزدیده‌شدهٔ یک فاکتور ارزان، اسلات گران می‌گرفت).

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/src/BotApi.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Manager.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Ui.php';
require_once __DIR__ . '/src/FxRate.php';
require_once __DIR__ . '/src/Payment/Payments.php';
require_once __DIR__ . '/src/Payment/Gateways.php';
require_once __DIR__ . '/src/Payment/Limits.php';
require_once __DIR__ . '/src/Payment/Pricing.php';
require_once __DIR__ . '/src/Payment/CardToCard.php';
require_once __DIR__ . '/src/Payment/NowPayments.php';
require_once __DIR__ . '/src/Payment/ZarinPal.php';
require_once __DIR__ . '/src/Payment/AqaPay.php';

/** پاسخ HTML به کاربر (درگاه کاربر را در مرورگر باز می‌کند، نه JSON) */
function zp_page(string $title, string $body, string $emoji = '✅'): void
{
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(200);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $safeTitle . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#0f172a;color:#e2e8f0;font-family:system-ui,Tahoma,sans-serif}'
        . '.c{max-width:32rem;padding:2rem;border-radius:1rem;background:#1e293b;border:1px solid #334155;'
        . 'text-align:center;line-height:2}.e{font-size:3rem}code{background:#0f172a;padding:.2rem .5rem;'
        . 'border-radius:.35rem;color:#fbbf24}</style></head><body><div class="c">'
        . '<div class="e">' . $emoji . '</div>'
        . '<h2>' . $safeTitle . '</h2>'
        . $body
        . '</div></body></html>';
    exit;
}

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) { http_response_code(500); zp_page('پیکربندی یافت نشد', '<p>فایل <code>config.php</code> روی سرور نیست.</p>', '⚠️'); }
$cfg = require $cfgFile;
$TOKEN = (string)($cfg['main_token'] ?? '');

try {
    $store = new Store($cfg['manager_db'], $cfg);
    Payments::ensureSchema($store);
} catch (Throwable $e) {
    Logger::getInstance()->error('zarin_ipn', 'bootstrap failed: ' . $e->getMessage());
    zp_page('خطای داخلی', '<p>اتصال به دیتابیس برقرار نشد. لطفاً کمی بعد تلاش کنید.</p>', '⚠️');
}

$authority = trim((string)($_GET['Authority'] ?? $_GET['authority'] ?? ''));
$status    = strtoupper(trim((string)($_GET['Status'] ?? $_GET['status'] ?? '')));

if ($authority === '') {
    zp_page('اطلاعات ناقص', '<p>شناسهٔ پرداخت (Authority) در آدرس نیست.</p>', '⚠️');
}

// درگاه خاموش ⇒ چیزی تأیید نمی‌شود (فاکتورهای قطعیِ قبلی دست‌نخورده می‌مانند)
if (!PaymentGateways::isEnabled($store, PaymentGateways::ZARIN)) {
    Logger::getInstance()->warning('zarin_ipn', 'gateway disabled — ignored');
    zp_page('درگاه غیرفعال است', '<p>درگاه زرین‌پال فعلاً غیرفعال است؛ پرداخت شما تأیید نشد.<br>با پشتیبانی تماس بگیرید.</p>', '⛔️');
}

$pay = Payments::getPaymentByExtId($store, $authority);
if (!$pay) {
    Logger::getInstance()->warning('zarin_ipn', 'payment not found for authority ' . mb_substr($authority, 0, 12));
    zp_page('پرداخت یافت نشد', '<p>این فاکتور در سیستم ما ثبت نشده است.<br>با پشتیبانی تماس بگیرید.</p>', '❓');
}

// قبلاً نهایی شده ⇒ همان نتیجه را دوباره نشان بده (کاربر دکمه را دوباره زده)
if (in_array($pay['status'], [Payments::ST_PAID, Payments::ST_USED], true)) {
    zp_page('این پرداخت قبلاً تأیید شده بود',
        '<p>سفارش شما ثبت شده است ✅</p><p>' . Payments::describe($pay) . '</p>', '✅');
}

// مبلغِ درست را از دیتابیس خودمان می‌خوانیم، نه از آدرس بازگشت
$amountToman = (int)($pay['amount'] ?? 0);
$merchantId = PaymentZarin::merchantId($store, $cfg);
if ($merchantId === '') {
    Logger::getInstance()->error('zarin_ipn', 'merchant_id not configured');
    zp_page('پیکربندی ناقص', '<p>کد پذیرندهٔ زرین‌پال ثبت نشده است.<br>با ادمین تماس بگیرید.</p>', '⚠️');
}

if ($status === 'NOK' || $status === 'CANCEL' || $status === 'CANCELED') {
    // کاربر در درگاه پرداخت را لغو کرده ⇒ پول جابه‌جا نشده، فاکتور را می‌بندیم
    try {
        if ($pay['status'] === Payments::ST_AWAIT_PAY) {
            Payments::setStatus($store, (int)$pay['id'], Payments::ST_CANCELLED);
        }
    } catch (Throwable $e) {
        Logger::getInstance()->warning('zarin_ipn', 'cancel failed: ' . $e->getMessage());
    }
    Logger::getInstance()->info('zarin_ipn', 'user cancelled, authority=' . mb_substr($authority, 0, 12));
    zp_page('پرداخت لغو شد',
        '<p>شما پرداخت را در درگاه لغو کردید؛ چیزی از حساب شما کم نشد.</p>'
        . '<p>می‌توانید دوباره از ربات تلاش کنید.</p>', '🚫');
}

// تأیید واقعی: اینجاست که پول واقعاً تأیید می‌شود (نه با پارامتر آدرس)
$vr = PaymentZarin::verify($merchantId, $amountToman, $authority, PaymentZarin::isSandbox($store));
if (empty($vr['ok'])) {
    Logger::getInstance()->error('zarin_ipn', 'verify transport failed: ' . (string)($vr['error'] ?? ''));
    zp_page('تأیید ممکن نشد',
        '<p>ارتباط با درگاه زرین‌پال برقرار نشد.<br>چند دقیقه بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.</p>'
        . '<p><code>' . Ui::e(mb_substr((string)($vr['error'] ?? ''), 0, 160)) . '</code></p>', '⚠️');
}
if (empty($vr['paid'])) {
    // ۱۰۱ = قبلاً تأیید شده (پول رسیده) ولی باز هم paid است؛ اینجا خطای واقعی است
    $msg = (string)($vr['message'] !== '' ? $vr['message'] : 'پرداخت ناموفق');
    Logger::getInstance()->warning('zarin_ipn', 'verify says not paid: code=' . $vr['code'] . ' msg=' . $msg);
    try {
        if ($pay['status'] === Payments::ST_AWAIT_PAY) {
            Payments::setStatus($store, (int)$pay['id'], Payments::ST_DECLINED);
        }
    } catch (Throwable $e) { /* بی‌اهمیت */ }
    zp_page('پرداخت تأیید نشد',
        '<p>درگاه زرین‌پال این تراکنش را پرداخت‌شده اعلام نکرد.</p>'
        . '<p><code>' . Ui::e($msg) . '</code></p>'
        . '<p>اگر مبلغی از حساب شما کم شده، فیش را برای ادمین بفرستید.</p>', '❌');
}

$done = Payments::markAutoPaid($store, (int)$pay['id']);
if (!$done) {
    // بین خواندن رکورد و این لحظه، گذار انجام شده (مثلاً IPN همزمان) ⇒ موفقیت است
    $again = Payments::getPayment($store, (int)$pay['id']);
    if (!$again || !in_array($again['status'], [Payments::ST_PAID, Payments::ST_USED], true)) {
        zp_page('وضعیت نامشخص', '<p>لطفاً چند دقیقه بعد پیام ربات را چک کنید.</p>', '⏳');
    }
    $done = $again;
}

$note = (string)($done['grant_note'] ?? '');
Logger::getInstance()->info('zarin_ipn', 'paid #'.$done['id'].' ref_id=' . (int)($vr['ref_id'] ?? 0));

if ($TOKEN !== '' && $TOKEN !== 'PUT_MAIN_BOT_TOKEN_HERE') {
    try {
        BotApi::send($TOKEN, (int)$done['user_id'],
            "✅ <b>پرداخت زرین‌پال شما تأیید شد!</b>\n"
            . Payments::describe($done)
            . ($note !== '' ? "\n🎁 " . Ui::e($note) : '')
            . (!empty($vr['ref_id']) ? "\n🧾 کد رهگیری درگاه: <code>" . (int)$vr['ref_id'] . "</code>" : '')
            . "\n\n🚀 حالا «🤖 ساخت ربات جدید» را بزنید.");
    } catch (Throwable $e) { /* اطلاع به کاربر نباید تأیید را خراب کند */ }
}

$body = '<p>مبلغ پرداختی به حساب شما اضافه شد.</p>'
    . '<p>' . Payments::describe($done) . '</p>'
    . ($note !== '' ? '<p>🎁 ' . Ui::e($note) . '</p>' : '')
    . (!empty($vr['ref_id']) ? '<p>کد رهگیری: <code>' . (int)$vr['ref_id'] . '</code></p>' : '')
    . '<p style="margin-top:1.5rem;color:#94a3b8;font-size:.9rem">همین حالا به ربات برگشته‌اید؛ نتیجه در تلگرام هم به شما اطلاع داده شد ✅</p>';
zp_page('پرداخت با موفقیت انجام شد', $body, '🎉');