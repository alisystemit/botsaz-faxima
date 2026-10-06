<?php
// ===== کال‌بک/تأیید پرداخت «آقای پرداخت» (aqayepardakht.ir) =====
//
// این آدرس را در پنل آقای پرداخت (یا داخل کد) به‌عنوان callback ثبت کنید:
//   https://yourdomain/botsaz-faxima/aqayepardakht_ipn.php
//
// بازگشت کاربر (با GET چون callback_method=GET فرستاده می‌شود):
//   ?transid=…&status=1&tracking_number=…&cardnumber=6037…&bank=…&invoice_id=…
//
// نکته‌های امنیتی که رعایت شده:
//  ۱) «status=1» به‌تنهایی کافی نیست؛ باید POST verify روی سرویس هم انجام شود
//     (وگرنه یک کاربر می‌توانست لینک را دستی ویرایش کند و پول نداده «پرداخت» جا بزند).
//  ۲) مبلغ و pin از دیتابیس خودمان خوانده می‌شود، نه از پارامتر آدرس.
//  ۳) Payments::markAutoPaid اتمیک است ⇒ بازکردن چندبارهٔ صفحه فقط یک بار اعمال می‌کند.
//  ۴) اگر ادمین درگاه را خاموش کرده باشد، هیچ تأییدی انجام نمی‌شود.
//  ۵) «status=0» ⇒ کاربر پرداخت را نکرده/لغو کرده ⇒ فاکتور بسته می‌شود.

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
function ap_page(string $title, string $body, string $emoji = '✅'): void
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
if (!is_file($cfgFile)) { http_response_code(500); ap_page('پیکربندی یافت نشد', '<p>فایل <code>config.php</code> روی سرور نیست.</p>', '⚠️'); }
$cfg = require $cfgFile;
$TOKEN = (string)($cfg['main_token'] ?? '');

try {
    $store = new Store($cfg['manager_db'], $cfg);
    Payments::ensureSchema($store);
} catch (Throwable $e) {
    Logger::getInstance()->error('aqaye_ipn', 'bootstrap failed: ' . $e->getMessage());
    ap_page('خطای داخلی', '<p>اتصال به دیتابیس برقرار نشد. لطفاً کمی بعد تلاش کنید.</p>', '⚠️');
}

// سرویس می‌تواند GET یا POST بفرستد؛ هر دو را می‌خوانیم (fallback به POST)
$in = array_merge($_GET, $_POST);
$transid = trim((string)($in['transid'] ?? ''));
$status  = trim((string)($in['status'] ?? ''));
$tracking = trim((string)($in['tracking_number'] ?? ''));
$bank      = trim((string)($in['bank'] ?? ''));
$cardPan   = trim((string)($in['cardnumber'] ?? ''));

if ($transid === '') {
    ap_page('اطلاعات ناقص', '<p>کد تراکنش (transid) در آدرس نیست.</p>', '⚠️');
}

// درگاه خاموش ⇒ چیزی تأیید نمی‌شود
if (!PaymentGateways::isEnabled($store, PaymentGateways::AQAYE)) {
    Logger::getInstance()->warning('aqaye_ipn', 'gateway disabled — ignored');
    ap_page('درگاه غیرفعال است', '<p>درگاه آقای پرداخت فعلاً غیرفعال است؛ پرداخت شما تأیید نشد.<br>با پشتیبانی تماس بگیرید.</p>', '⛔️');
}

$pay = Payments::getPaymentByExtId($store, $transid);
if (!$pay) {
    Logger::getInstance()->warning('aqaye_ipn', 'payment not found for transid ' . mb_substr($transid, 0, 12));
    ap_page('پرداخت یافت نشد', '<p>این فاکتور در سیستم ما ثبت نشده است.<br>با پشتیبانی تماس بگیرید.</p>', '❓');
}

// قبلاً نهایی شده ⇒ همان نتیجه را دوباره نشان بده
if (in_array($pay['status'], [Payments::ST_PAID, Payments::ST_USED], true)) {
    ap_page('این پرداخت قبلاً تأیید شده بود',
        '<p>سفارش شما ثبت شده است ✅</p><p>' . Payments::describe($pay) . '</p>', '✅');
}

// وضعیتِ خودِ درگاه: ۱ یعنی پرداخت انجام شده، هرچیزِ دیگر یعنی نه
$gatewaySaidOk = in_array($status, ['1', 'ok', 'success', 'true'], true);
if (!$gatewaySaidOk) {
    try {
        if (in_array($pay['status'], [Payments::ST_AWAIT_PAY, Payments::ST_PENDING], true)) {
            Payments::setStatus($store, (int)$pay['id'], Payments::ST_CANCELLED);
        }
    } catch (Throwable $e) {
        Logger::getInstance()->warning('aqaye_ipn', 'cancel failed: ' . $e->getMessage());
    }
    Logger::getInstance()->info('aqaye_ipn', 'gateway status=' . ($status !== '' ? $status : '?') . ' transid=' . mb_substr($transid, 0, 12));
    ap_page('پرداخت انجام نشد',
        '<p>درگاه پرداخت را ناموفق اعلام کرد؛ چیزی از حساب شما کم نشد.</p>'
        . '<p>می‌توانید دوباره از ربات تلاش کنید.</p>', '🚫');
}

$amountToman = (int)($pay['amount'] ?? 0);
$pin = PaymentAqaye::pin($store, $cfg);
if ($pin === '') {
    Logger::getInstance()->error('aqaye_ipn', 'pin not configured');
    ap_page('پیکربندی ناقص', '<p>کد پین درگاه آقای پرداخت ثبت نشده است.<br>با ادمین تماس بگیرید.</p>', '⚠️');
}

// تأیید واقعی روی سرویس: code=1 (موفق) یا 2 (قبلاً وریفا) ⇒ پول رسیده
$vr = PaymentAqaye::verify($pin, $amountToman, $transid);
if (empty($vr['ok'])) {
    Logger::getInstance()->error('aqaye_ipn', 'verify transport failed: ' . (string)($vr['error'] ?? ''));
    ap_page('تأیید ممکن نشد',
        '<p>ارتباط با درگاه آقای پرداخت برقرار نشد.<br>چند دقیقه بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.</p>'
        . '<p><code>' . Ui::e(mb_substr((string)($vr['error'] ?? ''), 0, 160)) . '</code></p>', '⚠️');
}
if (empty($vr['paid'])) {
    $why = PaymentAqaye::errorText((string)($vr['code'] ?? ''));
    Logger::getInstance()->warning('aqaye_ipn', 'verify says not paid: code=' . (string)($vr['code'] ?? '') . ' ' . $why);
    try {
        if ($pay['status'] === Payments::ST_AWAIT_PAY) {
            Payments::setStatus($store, (int)$pay['id'], Payments::ST_DECLINED);
        }
    } catch (Throwable $e) { /* بی‌اهمیت */ }
    ap_page('پرداخت تأیید نشد',
        '<p>درگاه آقای پرداخت این تراکنش را تسویه‌شده اعلام نکرد.</p>'
        . '<p><code>' . Ui::e($why) . '</code></p>'
        . '<p>اگر مبلغی از حساب شما کم شده، فیش را برای ادمین بفرستید.</p>', '❌');
}

$done = Payments::markAutoPaid($store, (int)$pay['id']);
if (!$done) {
    $again = Payments::getPayment($store, (int)$pay['id']);
    if (!$again || !in_array($again['status'], [Payments::ST_PAID, Payments::ST_USED], true)) {
        ap_page('وضعیت نامشخص', '<p>لطفاً چند دقیقه بعد پیام ربات را چک کنید.</p>', '⏳');
    }
    $done = $again;
}

$note = (string)($done['grant_note'] ?? '');
Logger::getInstance()->info('aqaye_ipn', 'paid #'.$done['id'].' tracking=' . $tracking);

if ($TOKEN !== '' && $TOKEN !== 'PUT_MAIN_BOT_TOKEN_HERE') {
    try {
        BotApi::send($TOKEN, (int)$done['user_id'],
            "✅ <b>پرداخت شما (آقای پرداخت) تأیید شد!</b>\n"
            . Payments::describe($done)
            . ($note !== '' ? "\n🎁 " . Ui::e($note) : '')
            . ($tracking !== '' ? "\n🧾 شمارهٔ پیگیری بانکی: <code>" . Ui::e($tracking) . "</code>" : '')
            . "\n\n🚀 حالا «🤖 ساخت ربات جدید» را بزنید.");
    } catch (Throwable $e) { /* اطلاع به کاربر نباید تأیید را خراب کند */ }
}

$body = '<p>مبلغ پرداختی به حساب شما اضافه شد.</p>'
    . '<p>' . Payments::describe($done) . '</p>'
    . ($note !== '' ? '<p>🎁 ' . Ui::e($note) . '</p>' : '')
    . ($tracking !== '' ? '<p>شمارهٔ پیگیری: <code>' . Ui::e($tracking) . '</code></p>' : '')
    . ($bank !== '' ? '<p>بانک: ' . Ui::e($bank) . '</p>' : '')
    . ($cardPan !== '' ? '<p>کارت: <code>' . Ui::e($cardPan) . '</code></p>' : '')
    . '<p style="margin-top:1.5rem;color:#94a3b8;font-size:.9rem">همین حالا به ربات برگشته‌اید؛ نتیجه در تلگرام هم به شما اطلاع داده شد ✅</p>';
ap_page('پرداخت با موفقیت انجام شد', $body, '🎉');