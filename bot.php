<?php
// ===== ربات اصلی ربات‌ساز (وبهوک) =====
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Content-Type: application/json; charset=utf-8');

// ===== تضمین پاسخ JSON معتبر، حتی روی خطای مرگبار =====
// اگر display_errors روشن باشد، یک warning یا fatal متن PHP را قبل از JSON
// چاپ می‌کند؛ تلگرام پاسخ را نامعتبر می‌بیند و همان آپدیت را بی‌نهایت
// دوباره می‌فرستد (و هر بار دوباره خطا می‌دهد). پس خطا فقط لاگ می‌شود.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$GLOBALS['__webhook_json_sent'] = false;

/**
 * پاسخ نهایی JSON + پایان اسکریپت (پرچم پاسخ‌دادن را می‌زند).
 *
 * کلید "v" نسخهٔ در حال اجرا را لو می‌دهد. همین یک کلید تنها چیزی است که
 * «فایل روی دیسک تازه است ولی کدِ اجراشده کهنه است» را از بیرون قابل‌تشخیص
 * می‌کند (opcache، `--no-restart`، یا اجرای یک کپیِ دیگرِ پروژه مثل
 * ‎/var/www/... به‌جای ‎/root/...)؛ `tools/install.sh --check` همین را با نسخهٔ
 * روی دیسک مقایسه می‌کند. چیزی جز شمارهٔ نسخه لو نمی‌رود.
 */
function webhookDone(array $out = null): void
{
    $GLOBALS['__webhook_json_sent'] = true;
    $res = $out ?? ['ok' => true];
    if (!isset($res['v'])) $res['v'] = Manager::APP_VERSION;
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    if (!empty($GLOBALS['__webhook_json_sent'])) return;
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)$err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    try {
        Logger::getInstance()->error('fatal',
            ($err['message'] ?? '?') . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? 0));
    } catch (Throwable $e) { /* لاگر هم در دسترس نیست */ }
    // پاسخ 200 معتبر ⇒ تلگرام بی‌نهایت تکرار نمی‌کند؛ خطا در لاگ ثبت شده است
    echo json_encode(['ok' => true]);
});

require_once __DIR__ . '/src/BotApi.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Manager.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/DbBackup.php';
require_once __DIR__ . '/src/AdminNotify.php';
require_once __DIR__ . '/src/Nav.php';
require_once __DIR__ . '/src/Ui.php';
require_once __DIR__ . '/src/BuildSettings.php';
require_once __DIR__ . '/src/FxRate.php';
require_once __DIR__ . '/src/SourceUpdate.php';
// SelfUpdate هنوز برای «🔄 دریافت سورس بروز» لازم است (git fetch + تازه‌کردنِ templates/).
// پنل «⬆️ آپدیت ربات‌ساز» حذف شده ولی همین کلاس پشتِ بخش سورس است.
require_once __DIR__ . '/src/SelfUpdate.php';
require_once __DIR__ . '/src/Payment/Payments.php';
require_once __DIR__ . '/src/Payment/Gateways.php';
require_once __DIR__ . '/src/Payment/Limits.php';
require_once __DIR__ . '/src/Payment/Pricing.php';
require_once __DIR__ . '/src/Payment/CardToCard.php';
require_once __DIR__ . '/src/Payment/NowPayments.php';
require_once __DIR__ . '/src/Payment/ZarinPal.php';
require_once __DIR__ . '/src/Payment/AqaPay.php';
require_once __DIR__ . '/src/Payment/AdminPanel.php';
require_once __DIR__ . '/src/Texts.php';

$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); webhookDone(['ok' => false, 'error' => 'config.php missing']); }
$cfg  = require $cfgFile;
$TOKEN = $cfg['main_token'];
$SUPERS = $cfg['super_admins'] ?? [];
// پیکربندی برای توابعِ کمکیِ سراسری (مثل خلاصهٔ آمارِ «ربات‌های من») لازم است
$GLOBALS['__cfg'] = $cfg;

// ===== گاردهای پیکربندی (فقط لاگ — هیچ رفتاری را عوض نمی‌کنند) =====
// هدف: به‌جای سکوت، دلیل «کار نکردن ربات» واضح در data/logs/ نوشته شود.
// برای اینکه هر درخواست لاگ را پر نکند، هر بار فقط هر ۱۰ دقیقه یک‌بار ثبت می‌شود.
$cfgWarnFile = __DIR__ . '/data/config_warn.lock';   // توسط .gitignore (/data/*.lock) نادیده گرفته می‌شود
$cfgWarnBlocked = false;
if (is_file($cfgWarnFile)) {
    $cfgLastWarn = (int)@file_get_contents($cfgWarnFile);
    if ($cfgLastWarn > 0 && (time() - $cfgLastWarn) < 600) $cfgWarnBlocked = true;
}
if (!$cfgWarnBlocked) {
    $cfgProblems = [];
    if ($TOKEN === '' || $TOKEN === 'PUT_MAIN_BOT_TOKEN_HERE') {
        $cfgProblems[] = 'main_token خالی یا هنوز placeholder است — تلگرام هرگز آپدیتی نمی‌فرستد؛ توکن واقعی @BotFather را در config.php بگذار';
    } elseif (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', (string)$TOKEN)) {
        $cfgProblems[] = 'main_token فرمت استاندارد تلگرام (number:token) را ندارد — getMe احتمالاً 404 می‌دهد';
    }
    $cfgBase = (string)($cfg['base_url'] ?? '');
    if ($cfgBase !== '' && stripos($cfgBase, 'https://') !== 0) {
        $cfgProblems[] = "base_url ({$cfgBase}) عمومی و https نیست — تلگرام setWebhook را رد می‌کند";
    }
    if (count($SUPERS) === 0) {
        $cfgProblems[] = 'super_admins خالی است — هیچ ادمینی شناخته نمی‌شود';
    }
    if (count($cfgProblems) > 0) {
        @file_put_contents($cfgWarnFile, (string)time());
        Logger::getInstance()->error('config', implode(' | ', $cfgProblems));
    }
}

// ===== رمزنگاری =====
// منبع واحد secret_key → Manager::secretKey (همه‌جا یک مقدار)
$secretKey = Manager::secretKey($cfg);
function encryptToken(string $token, string $key): string
{
    $iv = openssl_random_pseudo_bytes(16);
    // کلید ۳۲ بایت خام (SHA-256)؛ قبلاً رشتهٔ hex بریده‌شده (۱۲۸ بیت مؤثر) استفاده می‌شد
    $encrypted = openssl_encrypt($token, 'AES-256-CBC', Manager::encryptionKey($key), 0, $iv);
    return base64_encode($iv . $encrypted);
}
function decryptToken(string $encrypted, string $key): string
{
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) <= 16) return '';
    $iv = substr($data, 0, 16);
    $enc = substr($data, 16);
    // ابتدا کلید جدید، سپس کلید قدیمی → توکن‌های ساخته‌شده با نسخه‌های قبلی هم باز می‌شوند
    foreach ([Manager::encryptionKey($key), Manager::legacyEncryptionKey($key)] as $k) {
        $d = openssl_decrypt($enc, 'AES-256-CBC', $k, 0, $iv);
        if (is_string($d) && $d !== '') return $d;
    }
    return '';
}

// ===== ساخت دیتابیس مدیریتی =====
// اگر MySQL/SQLite در دسترس نباشد، اینجا استثنا می‌دهد. بدون این try، fatal
// ⇒ shutdown handler با status 200 پاسخ می‌داد ⇒ تلگرام هرگز دوباره نمی‌فرستاد
// و آپدیت‌ها بی‌صدا گم می‌شدند. با 500، تلگرام دوباره می‌فرستد (درست برای خطای
// گذرای دیتابیس) و علت دقیق (فایل:خطا + پیام) در پاسخ و لاگ ثبت می‌شود.
try {
    $store = new Store($cfg['manager_db'], $cfg);
} catch (Throwable $e) {
    $bootErr = [
        'ok'    => false,
        'error' => 'store_init_failed',
        'cause' => Manager::sanitizeDbError($e->getMessage()),
        'at'    => basename($e->getFile()) . ':' . $e->getLine(),
        'hint'  => 'اتصال به دیتابیس مدیریتی برقرار نشد؛ لاگ کامل در data/logs/',
    ];
    try { Logger::getInstance()->error('boot', $bootErr['cause'] . ' @ ' . $bootErr['at']); } catch (Throwable $ignored) {}
    http_response_code(500);
    webhookDone($bootErr);
}

// ===== پردازش غیرهمزمان =====
@set_time_limit(0);
@ignore_user_abort(true);

// ===== احراز هویت وبهوک (قبل از هر پردازش/خروج زودهنگام) =====
// توکنِ خالی یعنی پیکربندی خراب — وبهوک بدون احراز هویت هرگز باز نمی‌شود (fail-closed).
// قبلاً secret خالی ⇒ شرط رد نمی‌شد ⇒ هر کسی می‌توانست update جعلی بفرستد.
$secret = Manager::faximaWebhookSecret($TOKEN);
if ($secret === '') {
    Logger::getInstance()->error('webhook', 'Empty bot token/secret — webhook rejected (fail-closed)');
    http_response_code(403);
    webhookDone(['ok' => false, 'error' => 'Unauthorized']);
}
$provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($secret, $provided)) {
    http_response_code(403);
    webhookDone(['ok' => false, 'error' => 'Unauthorized']);
}

// پاسخ فقط بعد از پردازش موفق ارسال می‌شود؛ عمداً fastcgi_finish_request زودهنگام نداریم:
// اگر وسط کار استثنا رخ دهد status 500 برمی‌گردد و تلگرام همان آپدیت را دوباره می‌فرستد
// (علامت‌گذاری update هم فقط بعد از موفقیت انجام می‌شود). قبلاً اتصال زود آزاد می‌شد و
// خطاهای mid-flight با پاسخ 200 بی‌صدا گم می‌شدند.

// ===== Dedup با update_id =====
$update = json_decode(file_get_contents('php://input'), true) ?: [];
if (!$update) { webhookDone(); }

$updateId = $update['update_id'] ?? null;
if ($updateId !== null && $store->isUpdateProcessed($updateId)) {
    webhookDone(['ok' => true, 'duplicate' => true]);
}
// «علامت‌گذاری بعد از پردازش موفق» — اگر وسط کار خطا بود، تلگرام دوباره می‌فرستد

// ===== عمق لینک عمیق /start <param> =====
// دستور ممکن است «/start@MyBot» باشد؛ بدون نرمال‌سازی، پارامتر لینک عمیق هیچ‌وقت
// خوانده نمی‌شد و کاربر به‌جای خوش‌آمدگویی، «دستور نامعتبر» می‌گرفت.
$deepLinkParam = null;
$rawText = $update['message']['text'] ?? null;
// is_string: اگر تلگرام (یا یک کلاینت جعلی) مقدار غیرمتنی بفرستد، trim() در PHP 8
// TypeError می‌دهد و کل وبهوک را می‌کشد — اینجا بی‌خطر رد می‌شود.
if (is_string($rawText) && preg_match('/^\/start(?:@\w+)?\s+(.+)$/', trim($rawText), $m)) {
    $deepLinkParam = trim($m[1]);
}

$msg  = $update['message'] ?? $update['channel_post'] ?? null;
$cb   = $update['callback_query'] ?? null;

if ($cb) {
    // ===== گاردهای ساختار آپدیت =====
    // «callback_query بدون from/id» در حالت آفلاین/نسخه‌های قدیمی تلگرام دیده می‌شود؛
    // قبلاً $cb['from'] بدون چک خوانده می‌شد ⇒ «Trying to access array offset on null»
    // و 500 ⇒ تلگرام همان آپدیت را بی‌نهایت دوباره می‌فرستاد.
    $cbFrom = $cb['from'] ?? null;
    if (!is_array($cbFrom) || !isset($cbFrom['id']) || !isset($cb['id'])) {
        Logger::getInstance()->warning('webhook', 'callback_query ناقص (بدون id/from) نادیده گرفته شد');
        if ($updateId !== null) $store->markUpdateProcessed($updateId);
        webhookDone();
    }
    if (!isset($cb['message'])) { if ($updateId !== null) $store->markUpdateProcessed($updateId); webhookDone(); }
    $uid = (int)$cbFrom['id'];
    $user = $store->user($uid, $cbFrom['first_name'] ?? '', $cbFrom['username'] ?? '');
    $cbChatId = $cb['message']['chat']['id'] ?? $uid;
    $cbData = (string)($cb['data'] ?? '');
    // ===== گارد خطای هندلر =====
    // استثناي خارج‌شده از switch ⇒ 500 ⇒ حلقهٔ retry تلگرام. حالا دقیقاً همان‌جا
    // به کاربر گفته می‌شود کدام دکمه و کدام نقطه از کد شکسته (فایل:خط).
    try {
        handleCallback($cfg, $store, $TOKEN, $SUPERS, $user, $cb);
    } catch (Throwable $e) {
        reportHandlerError($TOKEN, $cbChatId, 'callback', $cbData, $e, mainMenu($user, $SUPERS, $store));
    }
    if ($updateId !== null) $store->markUpdateProcessed($updateId);
    webhookDone();
}

if ($msg) {
    // ===== فقط چت خصوصی =====
    if (!isset($msg['chat']) || !is_array($msg['chat'])) {
        // آپدیت ناقص (کلاینت غیرمعمول/جعلی) نباید به index روی null بخورد
        Logger::getInstance()->warning('webhook', 'message بدون chat نادیده گرفته شد');
        if ($updateId !== null) $store->markUpdateProcessed($updateId);
        webhookDone();
    }
    $chatType = $msg['chat']['type'] ?? 'private';
    if ($chatType !== 'private') {
        if ($updateId !== null) $store->markUpdateProcessed($updateId);
        webhookDone();
    }
    $from = $msg['from'] ?? null;
    if (!is_array($from) || !isset($from['id'])) {
        Logger::getInstance()->warning('webhook', 'message بدون from نادیده گرفته شد');
        if ($updateId !== null) $store->markUpdateProcessed($updateId);
        webhookDone();
    }
    $uid = (int)$from['id'];
    $user = $store->user($uid, $from['first_name'] ?? '', $from['username'] ?? '');
    $text = is_string($msg['text'] ?? null) ? trim($msg['text']) : '';
    $chatId = $msg['chat']['id'] ?? $uid;
    $step = (string)($user['step'] ?? 'idle');

    // ===== ورودی غیرمتنی =====
    // هیچ ارسالی از طرف کاربر نباید بی‌جواب بماند. قبلاً هر چیزی غیر از «متن»
    // (عکس، ویدیو، ویس، استیکر، مکان، فایل، نظرسنجی...) بی‌صدا دور ریخته می‌شد؛
    // کاربر فکر می‌کرد ربات هنگ کرده. حالا هر مرحله‌ای که ورودی غیرمتنی را
    // نمی‌پذیرد، با پیام دقیقِ همان مرحله جواب می‌گیرد.
    // مراحلی که واقعاً ورودی غیرمتنی بخشی از کارشان است استثنا هستند:
    //   await_card_receipt  → عکس/فایل فیش
    //   await_broadcast     → پیام همگانی می‌تواند عکس/ویدیو باشد (copyMessage)
    //   await_child_broadcast → همان‌طور
    $mediaFriendlySteps = ['await_card_receipt', 'await_broadcast', 'await_child_broadcast'];
    if (!isset($msg['text']) && !in_array($step, $mediaFriendlySteps, true)) {
        BotApi::send($TOKEN, $chatId, nonTextInputHint($step), $step === 'idle'
            ? ['reply_markup' => mainMenu($user, $SUPERS, $store)]
            : ['reply_markup' => Nav::stepKb()]);
        if ($updateId !== null) $store->markUpdateProcessed($updateId);
        webhookDone();
    }

    // ===== گارد خطای هندلر (مثل بخش کال‌بک) =====
    try {
        handleMessage($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $text, $msg, $deepLinkParam);
    } catch (Throwable $e) {
        reportHandlerError($TOKEN, $chatId, 'message',
            mb_substr($text !== '' ? $text : mediaLabel($msg), 0, 80), $e,
            mainMenu($user, $SUPERS, $store));
    }
    if ($updateId !== null) $store->markUpdateProcessed($updateId);
    webhookDone();
}

// انواع آپدیتی که عمداً نادیده گرفته می‌شوند (edited_message، my_chat_member،
// message_reaction، …) هم باید علامت بخورند؛ وگرنه اگر تلگرام همان update_id را
// دوباره بفرستد دوباره پردازش می‌شود و جدول processed_updates هم بی‌دلیل رشد می‌کند.
if ($updateId !== null) $store->markUpdateProcessed($updateId);
webhookDone();

// ================= helpers =================
// رشته/عدد بودن مقدار config فرقی نکند — قبلاً in_array سخت‌گیرانه روی رشته‌های config هیچ‌وقت match نمی‌کرد
function isSuper(array $supers, int $uid): bool { return in_array((string)$uid, array_map('strval', $supers), true); }
function isAdmin(array $u, array $supers): bool { return isSuper($supers, (int)$u['user_id']) || (int)$u['is_admin'] === 1; }
function canUse(array $u, array $supers): bool { return isAdmin($u, $supers) || (int)$u['is_allowed'] === 1; }

/**
 * نرمال‌سازی دستور «/start@ربات» ⇒ «/start».
 * تلگرام وقتی کاربر دستور را از منوی گروه/جست‌وجو انتخاب می‌کند یا نام ربات را
 * تایپ می‌کند، دستور را با پسوند @username می‌فرستد. بدون این تبدیل،
 * همهٔ چک‌های «/start» و «/help» شکست می‌خوردند و کاربر «دستور نامعتبر» می‌گرفت.
 */
function normalizeCommandText(string $text): string
{
    if ($text === '' || $text[0] !== '/') return $text;
    if (preg_match('/^\/([A-Za-z0-9_]+)@[\w\d_]+/', $text, $m)) {
        return '/' . $m[1] . substr($text, strlen($m[0]));
    }
    return $text;
}

/** عنوان فارسی خوانای هر مرحله — برای پیام‌های خطای دقیق */
function stepLabel(string $step): string
{
    $map = [
        'await_bot_token'     => 'دریافت توکن ربات',
        'await_admin_id'      => 'دریافت آیدی ادمین',
        'await_folder'        => 'نام ربات',
        'await_broadcast'     => 'پیام همگانی',
        'await_child_broadcast' => 'پیام همگانی ربات فرزند',
        'await_user_add'      => 'افزودن کاربر مجاز',
        'await_user_remove'   => 'حذف کاربر مجاز',
        'await_backup_times'  => 'ساعت بکاپ',
        'await_card_receipt'  => 'ارسال رسید کارت‌به‌کارت',
        'await_pay_text'      => 'متن دلخواه پرداخت',
        'await_pay_price'     => 'قیمت قالب',
        'await_pay_limit_price' => 'قیمت اسلات',
        'await_pay_usdrate'   => 'نرخ دلار',
        'await_pay_card'      => 'شماره کارت',
        'await_pay_card_owner'=> 'نام صاحب کارت',
        'await_pay_nowpay_key'   => 'کلید API نوب‌پیمنت',
        'await_pay_nowpay_secret'=> 'IPN Secret نوب‌پیمنت',
        'await_pay_zarin_merchant' => 'کد پذیرندهٔ زرین‌پال',
        'await_pay_aqaye_pin'  => 'کد پین آقای پرداخت',
        'await_pay_setlimit'  => 'لیمیت کاربر',
        'await_text_edit'     => 'ویرایش متن پویا',
        'await_edit_bot_token'=> 'ویرایش توکن ربات',
        'await_edit_admin_id' => 'ویرایش آیدی ادمین ربات',
        'await_maintenance_text' => 'متن پیام تعمیرات',
        'await_maintenance_eta'  => 'زمان تقریبی بازگشت',
        'await_child_owner'      => 'اکانت سازندهٔ پنل',
    ];
    return $map[$step] ?? $step;
}

/** پیام دقیق برای ورودی غیرمتنی در یک مرحله — هیچ ارسالی بی‌جواب نمی‌ماند */
function nonTextInputHint(string $step): string
{
    if ($step === '' || $step === 'idle') {
        return "🖼 فقط <b>متن</b> و دکمه پذیرفته می‌شود.\n"
            . "برای شروع «🤖 ساخت ربات جدید» را بزنید یا از دکمه‌های زیر استفاده کنید.";
    }
    $accepts = $step === 'await_card_receipt'
        ? "در این مرحله فقط <b>عکس فیش</b>، <b>فایل رسید</b> یا <b>متنِ شماره پیگیری</b> پذیرفته می‌شود."
        : "در این مرحله فقط <b>متن</b> پذیرفته می‌شود؛ عکس/ویدیو/استیکر پردازش نمی‌شود.";
    return "⛔️ ورودی نامعتبر برای مرحلهٔ «" . stepLabel($step) . "».\n{$accepts}\n"
        . "متن مورد نیاز را بفرستید یا انصراف بدهید.";
}

/** نام فارسی نوع پیام غیرمتنی — برای گزارش دقیق خطای هندلر */
function mediaLabel(array $msg): string
{
    foreach (['photo' => 'عکس', 'video' => 'ویدیو', 'animation' => 'GIF', 'audio' => 'فایل صوتی',
              'voice' => 'ویس', 'video_note' => 'ویدیوی دایره‌ای', 'document' => 'فایل',
              'sticker' => 'استیکر', 'location' => 'موقعیت مکانی', 'contact' => 'مخاطب',
              'poll' => 'نظرسنجی', 'dice' => 'تاس', 'game' => 'بازی', 'venue' => 'مکان'] as $k => $fa) {
        if (!empty($msg[$k])) return $fa;
    }
    return 'پیام غیرمتنی';
}

/**
 * گزارش خطای داخلی هندلر: هم به کاربر همان‌جا دقیق گفته می‌شود کجا شکسته
 * (بخش + ورودی + پیام استثنا + فایل:خطا)، هم کامل در لاگ می‌رود.
 *
 * نکتهٔ کلیدی: استثنا نباید به صورت 500 بیرون برود — تلگرام همان آپدیت را
 * بی‌نهایت دوباره می‌فرستد و هر بار هم خطا تکرار می‌شود (حلقهٔ مرگ).
 * پس خطا به کاربر اعلام و آپدیت علامت‌گذاری می‌شود.
 */
function reportHandlerError(string $TOKEN, $chatId, string $stage, string $input, Throwable $e, ?string $kb = null): void
{
    $file = basename((string)$e->getFile());
    $line = (int)$e->getLine();
    $msg  = Manager::sanitizeDbError($e->getMessage());
    try {
        Logger::getInstance()->error('handler', "[{$stage}] input={$input} | {$msg} @ {$file}:{$line}", [
            'exception' => get_class($e),
            'trace'     => $e->getTraceAsString(),
        ]);
    } catch (Throwable $ignored) { /* لاگر هم خراب باشد، پیام کاربر نباید گم شود */ }

    $text = "⚠️ <b>خطای داخلی ربات — عملیات متوقف شد</b>\n\n"
        . "🧩 بخش: " . Ui::code($stage) . "\n"
        . "⌨️ ورودی شما: " . Ui::code(mb_substr($input, 0, 120)) . "\n"
        . "❗️ علت: " . Ui::e($msg) . "\n"
        . "📍 محل دقیق خطا: " . Ui::code("{$file}:{$line}") . "\n\n"
        . Ui::sep() . "\n"
        . "🛡 آپدیت علامت‌گذاری شد، پس تکرار بی‌جهت نمی‌شود.\n"
        . "دوباره تلاش کنید یا «🏠 منو» را بزنید.";
    try {
        $extra = ($kb !== null) ? ['reply_markup' => $kb] : [];
        $r = BotApi::send($TOKEN, $chatId, $text, $extra);
        if (empty($r['ok'])) {
            // پیام خطا هم نرسید ⇒ حداقل در لاگ دقیق ثبت شود
            Logger::getInstance()->error('handler', "error notice not delivered: " . ($r['description'] ?? '?'));
        }
    } catch (Throwable $ignored) { /* ارسال خطا هرگز نباید خودش خطا بدهد */ }
}

function mainMenu(array $u, array $supers, Store $store = null): string {
    if (isAdmin($u, $supers)) {
        $pendingCount = $store ? $store->countPendingRequests() : 0;
        $pendingText = $pendingCount > 0 ? " ({$pendingCount})" : "";
        $payPending = 0;
        if ($store) { try { $payPending = Payments::pendingAdminCount($store); } catch (Throwable $e) { $payPending = 0; } }
        $payText = $payPending > 0 ? " (🧾{$payPending})" : "";
        $rows = [
            [['text' => '🤖 ساخت ربات جدید'], ['text' => '📦 ربات‌های من']],
            [['text' => '📊 آمار'], ['text' => '⏰ کرون']],
            [['text' => '👥 کاربران مجاز'], ['text' => '📣 همگانی']],
            [['text' => "💳 پرداخت‌ها{$payText}"], ['text' => '💾 بکاپ دیتابیس']],
            [['text' => '⚙️ تنظیمات'], ['text' => '📝 متن‌ها']],
            [['text' => 'ℹ️ راهنما'], ['text' => '🔍 دیاگنوز']],
            [['text' => '📋 همه ربات‌ها'], ['text' => "📋 درخواست‌های جدید{$pendingText}"]],
        ];
        // بروزرسانیِ قالب‌ها فقط در دسترسِ «سوپرادمین» است، نه هر ادمینی.
        // (پنل «⬆️ آپدیت ربات‌ساز» حذف شد؛ فقط تازه‌کردنِ templates/ باقی مانده.)
        if (isSuper($supers, (int)($u['user_id'] ?? 0))) {
            $rows[] = [['text' => '🔄 دریافت سورس بروز']];
        }
        $rows[] = [['text' => '💳 افزایش لیمیت']];
        return BotApi::kb($rows);
    }
    // وقتی ادمین هر دو درگاه «لیمیت» و «قالب» را خاموش کرده، دکمهٔ خرید اصلاً نمایش داده نمی‌شود
    if ($store && !PaymentGateways::isAnythingEnabled($store)) {
        return BotApi::kb([
            [['text' => '🤖 ساخت ربات جدید'], ['text' => '📦 ربات‌های من']],
            [['text' => '🧾 پرداخت‌های من'], ['text' => 'ℹ️ راهنما']],
        ]);
    }
    return BotApi::kb([
        [['text' => '🤖 ساخت ربات جدید'], ['text' => '📦 ربات‌های من']],
        [['text' => '💳 افزایش لیمیت'], ['text' => '🧾 پرداخت‌های من']],
        [['text' => 'ℹ️ راهنما']],
    ]);
}

/** فروشگاه لیمیت کاربر (متن + دکمه) — مشترک بین پیام و کال‌بک */
function showLimitShop(Store $store, string $TOKEN, $chatId, array $user, array $SUPERS, int $msgId = 0): void
{
    try { Payments::ensureSchema($store); } catch (Throwable $e) {}
    $t = PaymentPanel::limitShopText($store, $user, $SUPERS);
    $kb = PaymentPanel::limitShopKb($store, $user, $SUPERS);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

/** پنل پرداخت ادمین — مشترک بین پیام و کال‌بک */
function showPaymentsAdmin(Store $store, string $TOKEN, $chatId, int $msgId = 0, string $note = ''): void
{
    try { Payments::ensureSchema($store); } catch (Throwable $e) {}
    $t = PaymentPanel::adminText($store);
    if ($note !== '') $t = $note . "\n\n" . Ui::sep() . "\n\n" . $t;
    $kb = PaymentPanel::adminKb($store);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

// ================= متن‌های پویا (ویرایش توسط مدیر ربات) =================
//
// رابط کامل: فهرست گروه‌ها → فهرست متن‌های گروه → صفحهٔ هر متن (ویرایش /
// بازگشت به پیش‌فرض). منطق ذخیره در src/Texts.php است؛ اینجا فقط نمایش است.

/**
 * آیا خطای تلگرام دقیقاً به‌خاطر تگ‌های HTML نامتوازنِ همین متن است؟
 * فقط 400 + «parse entities» تقصیر متن است؛ بقیه (توکن نامعتبر، شبکه، چت)
 * ربطی به متن ندارند و نباید باعث رفتار عجیب شوند.
 */
function isHtmlParseError(array $r): bool
{
    if (!is_array($r) || !empty($r['ok'])) return false;
    if ((int)($r['error_code'] ?? 0) !== 400) return false;
    return stripos((string)($r['description'] ?? ''), 'parse entities') !== false;
}

/**
 * ارسال پیامی که ممکن است HTML خراب داشته باشد (متن دلخواه ادمین).
 * اول با parse_mode تلگرام می‌رود؛ اگر رد شد، همان پیام بدون تگ به‌همراه علت
 * دقیق API فرستاده می‌شود تا ادمین هیچ‌وقت صفحهٔ خالی نبیند و بتواند متن
 * خراب را ببیند و اصلاح کند.
 */
function sendHtmlSafe(string $TOKEN, $chatId, string $text, array $extra = []): void
{
    $r = BotApi::send($TOKEN, $chatId, $text, $extra);
    if (!isHtmlParseError($r)) return;
    $plain = "⚠️ نمایش این متن با تگ‌های HTML ممکن نبود؛ نسخهٔ بدون تگ آمد.\n"
        . "علت دقیق تلگرام: " . (string)$r['description'] . "\n"
        . "برای اصلاح، متن را از «✏️ ویرایش» دوباره بفرست.\n\n"
        . strip_tags($text);
    BotApi::send($TOKEN, $chatId, $plain, $extra + ['parse_mode' => null]);
}

/** کوتاه‌کردن متنِ بلند برای نمایش در پیام (بدون بریدن وسط کاراکتر) */
function textsTrim(string $s, int $max = 1200): string
{
    $s = trim($s);
    if ($s === '') return '— (خالی — از متن پیش‌فرض استفاده می‌شود)';
    if (mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max) . "\n… (ادامه حذف شد)";
}

/** کیبورد فهرست گروه‌ها */
function textsGroupsKb(): string
{
    $rows = [];
    $groups = Texts::groups();
    for ($i = 0; $i < count($groups); $i += 2) {
        $row = [];
        for ($j = $i; $j < count($groups) && $j < $i + 2; $j++) {
            $g = $groups[$j];
            $row[] = ['text' => $g['icon'] . ' ' . $g['label'] . ' (' . $g['count'] . ')',
                      'callback_data' => 'texts:g:' . $g['key']];
        }
        $rows[] = $row;
    }
    $rows[] = [['text' => Nav::MENU, 'callback_data' => Nav::CB_BACK_MAIN]];
    return BotApi::ikb($rows);
}

/** نمایش فهرست گروه‌ها (صفحهٔ اول پنل) */
function showTextsGroups(Store $store, string $TOKEN, $chatId, int $msgId = 0): void
{
    $t = "📝 <b>ویرایش متن‌های ربات</b>\n\n"
        . "هر بخشِ ربات متنِ پیش‌فرض خودش را دارد؛ اینجا می‌توانی هر کدام را عوض کنی"
        . " یا به حالت پیش‌فرض برگردانی.\n"
        . "جایگذین‌ها مثل ‹role› هنگام نمایش با مقدار واقعی عوض می‌شوند.\n\n";
    foreach (Texts::groups() as $g) {
        $t .= $g['icon'] . ' <b>' . $g['label'] . '</b> — ' . $g['count'] . " متن\n";
    }
    $t .= "\n✍️ یعنی متن دلخواه ذخیره شده • 📌 یعنی متن پیش‌فرض.";
    $kb = textsGroupsKb();
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

/** کیبورد فهرست متن‌های یک گروه */
function textsListKb(string $group): string
{
    $rows = [];
    foreach (Texts::keys($group) as $key) {
        $rows[] = [['text' => Texts::label($key), 'callback_data' => 'texts:show:' . $key]];
    }
    $rows[] = [['text' => '↩️ گروه‌ها', 'callback_data' => 'texts:list'],
               ['text' => Nav::MENU, 'callback_data' => Nav::CB_BACK_MAIN]];
    return BotApi::ikb($rows);
}

/** نمایش متن‌های یک گروه با وضعیت هر کدام */
function showTextsList(Store $store, string $TOKEN, $chatId, string $group, int $msgId = 0): void
{
    $icon = '•';
    $label = $group;
    foreach (Texts::groups() as $g) { if ($g['key'] === $group) { $icon = $g['icon']; $label = $g['label']; } }
    $t = "📝 <b>متن‌های «{$icon} {$label}»</b>\n\n";
    $keys = Texts::keys($group);
    if (!$keys) {
        $t .= "در این گروه متنی ثبت نشده است.\n";
    } else {
        foreach ($keys as $key) {
            $t .= Texts::statusIcon($store, $key) . ' ' . Texts::label($key) . "\n";
        }
    }
    $t .= "\n✍️ متن دلخواه • 📌 متن پیش‌فرض\nروی هر متن بزن تا متن و پیش‌نمایشش را ببینی.";
    $kb = textsListKb($group);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

/**
 * صفحهٔ یک متن: وضعیت + جایگذین‌ها + متن فعلی + پیش‌نمایش با مقدار نمونه.
 * $notice پیام کوتاهی است که بالای صفحه می‌آید (مثلاً «به پیش‌فرض برگشت»).
 */
function showTextItem(Store $store, string $TOKEN, $chatId, string $key, int $msgId = 0, string $notice = ''): void
{
    if (!Texts::isValid($key)) {
        BotApi::send($TOKEN, $chatId,
            "⛔️ کلید متن نامعتبر است: <code>" . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . "</code>\n"
            . "محل دقیق: bot.php → showTextItem()");
        showTextsGroups($store, $TOKEN, $chatId);
        return;
    }
    $custom = Texts::hasCustom($store, $key);
    $raw = Texts::text($store, $key);
    $unknown = Texts::unknownVars($key, $raw);

    $t = ($notice !== '' ? $notice . "\n\n" : '')
        . Texts::statusIcon($store, $key) . ' <b>' . Texts::label($key) . "</b>\n"
        . 'گروه: ' . Texts::groupLabel($key) . " • وضعیت: "
        . ($custom ? '✍️ متن دلخواه' : '📌 متن پیش‌فرض') . "\n";

    $vars = Texts::vars($key);
    if ($vars) {
        $parts = [];
        foreach ($vars as $n => $d) $parts[] = '‹' . $n . '› (' . $d . ')';
        $parts[] = '‹nav› (خط برگشت/انصراف)';
        $t .= "\n🧩 جایگذین‌ها:\n" . implode("\n", $parts) . "\n";
    } else {
        $t .= "\n🧩 بدون جایگذین (متن ثابت)\n";
    }
    if ($unknown) {
        $esc = [];
        foreach ($unknown as $n) $esc[] = '‹' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '›';
        $t .= "\n⚠️ جایگذینِ ناشناخته: " . implode('، ', $esc)
            . "\nاین‌ها هنگام نمایش حذف می‌شوند؛ املایشان را درست کن.\n";
    }

    $t .= "\n— <b>متن فعلی</b> —\n" . textsTrim($raw) . "\n";
    $t .= "\n— <b>پیش‌نمایش با مقدار نمونه</b> —\n" . textsTrim(Texts::preview($store, $key));

    $rows = [];
    $row = [['text' => '✏️ ویرایش', 'callback_data' => 'texts:edit:' . $key]];
    if ($custom) $row[] = ['text' => '📌 بازگشت به پیش‌فرض', 'callback_data' => 'texts:reset:' . $key];
    $rows[] = $row;
    $rows[] = [['text' => '↩️ گروه‌ها', 'callback_data' => 'texts:list'],
               ['text' => Nav::MENU, 'callback_data' => Nav::CB_BACK_MAIN]];
    $kb = BotApi::ikb($rows);

    if ($msgId > 0) {
        $r = BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
        if (isHtmlParseError($r)) {
            // متن ذخیره‌شده تگ خراب دارد (مثلاً از پنل قدیمی) ⇒ پیام تازهٔ بدون تگ
            sendHtmlSafe($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
        }
    } else {
        sendHtmlSafe($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
    }
}

/** متن راهنمای مرحلهٔ «متن جدید را بفرست» */
function textsEditPrompt(string $key): string
{
    $vars = Texts::vars($key);
    $names = [];
    foreach ($vars as $n => $d) $names[] = '‹' . $n . '›';
    $placeholderLine = $vars
        ? "🧩 جایگذین‌ها: " . implode('، ', $names) . " و ‹nav› (خط برگشت/انصراف)\n"
        : "🧩 بدون جایگذین؛ در صورت نیاز ‹nav› (خط برگشت/انصراف) را می‌توانی در متن بگذاری.\n";
    return "✏️ <b>متن جدید برای «" . Texts::label($key) . "» را بفرست.</b>\n\n"
        . $placeholderLine
        . "✳️ برای خط جدید <code>\\n</code> را بنویس (یا متن را چندخطی بفرست).\n"
        . "✳️ اگر متن دقیقاً یکی از دکمه‌های ناوبری است (مثل «" . Nav::BACK
        . "» یا «" . Nav::MENU . "») با <code>=</code> شروعش کن؛ مثلاً <code>=منو</code>.\n"
        . "✳️ متنِ خالی ذخیره نمی‌شود؛ برای بازگشت به حالت پیش‌فرض از دکمهٔ «📌 بازگشت به پیش‌فرض» استفاده کن.\n\n"
        . "برای انصراف از ویرایش: " . Nav::CANCEL
        . " • برای خروج کامل به منو: " . Nav::MENU . " یا <code>/start</code>";
}

/**
 * پردازش متن دریافت‌شده در مرحلهٔ ویرایش یک متن.
 * این تابع عمداً قبل از چک‌های ناوبریِ handleStep اجرا می‌شود تا ادمین بتواند
 * متنی شبیه دکمه‌ها هم ذخیره کند (با پیشوند =).
 */
function handleTextEditStep(Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, string $text, array $temp): void
{
    $uid = (int)$user['user_id'];
    $key = (string)($temp['key'] ?? '');

    if (!Texts::isValid($key)) {
        $store->clearStep($uid);
        BotApi::send($TOKEN, $chatId,
            "⛔️ کلید متن پیدا نشد: <code>" . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . "</code>\n"
            . "محل دقیق: bot.php → handleTextEditStep() — از فهرست دوباره انتخاب کن.",
            ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
        showTextsGroups($store, $TOKEN, $chatId);
        return;
    }

    // پیشوند = یعنی «دقیقاً همین متن را ذخیره کن»؛ بدون آن، دکمه‌های ناوبری برنده‌اند
    $literal = false;
    if (str_starts_with($text, '=')) {
        $literal = true;
        $text = substr($text, 1);
    }

    if (!$literal) {
        if (Nav::isMenu($text) || $text === '/start' || str_starts_with($text, '/start ')) {
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }
        if (Nav::isBack($text)) {
            $store->clearStep($uid);
            showTextsGroups($store, $TOKEN, $chatId);
            return;
        }
        if (Nav::isCancel($text)) {
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId,
                "✏️ ویرایش «" . Texts::label($key) . "» لغو شد؛ متن قبلی دست‌نخورده ماند.",
                ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }
    }

    // \n داخل متن ⇐ خط جدید (در موبایل تایپ چندخطی سخت است)
    $value = str_replace(['\\n', '\\t'], ["\n", "\t"], $text);

    if (trim($value) === '') {
        BotApi::send($TOKEN, $chatId,
            "⛔️ متن خالی ذخیره نمی‌شود.\n"
            . "یک متن بفرست، یا برای بازگرداندن متن پیش‌فرض از «📌 بازگشت به پیش‌فرض» استفاده کن.\n"
            . "برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    if (mb_strlen($value) > Texts::MAX_LEN) {
        BotApi::send($TOKEN, $chatId,
            "⚠️ متن بیش از " . Texts::MAX_LEN . " کاراکتر است؛ فقط همین مقدار ذخیره می‌شود.",
            ['reply_markup' => Nav::stepKb()]);
    }

    // اگر تلگرام HTML متن را نپذیرد باید بتوانیم به حالت قبل برگردیم
    $hadCustom = Texts::hasCustom($store, $key);
    $before = Texts::text($store, $key);

    if (!Texts::set($store, $key, $value)) {
        BotApi::send($TOKEN, $chatId,
            "⛔️ ذخیرهٔ متن انجام نشد (کلید: <code>" . htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
            . "</code>) — متن خالی یا کلید نامعتبر؛ دوباره تلاش کن.",
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    $unknown = Texts::unknownVars($key, $value);
    $msg = "✅ ذخیره شد: «" . Texts::label($key) . "»";
    if ($unknown) {
        $esc = [];
        foreach ($unknown as $n) $esc[] = '‹' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '›';
        $msg .= "\n\n⚠️ جایگذینِ ناشناخته: " . implode('، ', $esc)
            . "\nاین‌ها هنگام نمایش به کاربر حذف می‌شوند؛ اگر منظورت جایگذین بود، املایش را درست کن.";
    }
    $msg .= "\n\n— <b>پیش‌نمایش</b> —\n" . textsTrim(Texts::preview($store, $key));

    $rows = [
        [['text' => '✏️ دوباره ویرایش', 'callback_data' => 'texts:edit:' . $key]],
        [['text' => '📌 بازگشت به پیش‌فرض', 'callback_data' => 'texts:reset:' . $key]],
        [['text' => '↩️ گروه‌ها', 'callback_data' => 'texts:list'],
         ['text' => Nav::MENU, 'callback_data' => Nav::CB_BACK_MAIN]],
    ];
    $r = BotApi::send($TOKEN, $chatId, $msg, ['reply_markup' => BotApi::ikb($rows)]);

    // ===== تگ‌های HTML نامتوازن =====
    // تلگرام چنین متنی را رد می‌کند؛ اگر ذخیره بماند، همهٔ پیام‌های بعدیِ ربات
    // هم خراب می‌شود. پس متن برمی‌گردد و ادمین همان‌جا علت دقیق API را می‌گیرد.
    if (!isHtmlParseError($r)) {
        $store->clearStep($uid);
        return;
    }
    $errDesc = (string)($r['description'] ?? '');
    if ($hadCustom) Texts::set($store, $key, $before); else Texts::reset($store, $key);
    // step عمداً باقی می‌ماند تا ادمین همان‌جا اصلاح کند
    BotApi::send($TOKEN, $chatId,
        "⛔️ متن ذخیره نشد: تلگرام تگ‌های HTML را نپذیرفت.\n"
        . "علت دقیق تلگرام: <code>" . htmlspecialchars($errDesc, ENT_QUOTES, 'UTF-8') . "</code>\n"
        . "محل: bot.php → handleTextEditStep() (کلید <code>"
        . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . "</code>)\n\n"
        . "• تگ‌های باز و بسته را جفت کن: <code>&lt;b&gt;…&lt;/b&gt;</code>\n"
        . "• کاراکترهای <b>غیرتگی</b>ِ <code>&lt;</code> و <code>&amp;</code> را "
        . "<code>&amp;lt;</code> و <code>&amp;amp;</code> کن.\n"
        . "• متن قبلی دست‌نخورده ماند؛ نسخهٔ درست را دوباره بفرست.\n\n"
        . "— متنِ فرستاده‌شده (بدون تگ) —\n" . mb_substr(strip_tags($value), 0, 800),
        ['reply_markup' => Nav::stepKb()]);
}

/** کال‌بک‌های پنل «📝 متن‌ها» — فقط ادمین */
function handleTextsCallback(Store $store, string $TOKEN, $chatId, int $msgId, bool $admin, string $data, array $user, array $SUPERS): void
{
    if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
    $uid = (int)$user['user_id'];
    $parts = explode(':', $data);
    $cmd = (string)($parts[1] ?? '');
    $arg = (string)($parts[2] ?? '');

    switch ($cmd) {
        case 'list':
            $store->clearStep($uid);
            showTextsGroups($store, $TOKEN, $chatId, $msgId);
            return;

        case 'g':
            if (!Texts::isValidGroup($arg)) {
                BotApi::send($TOKEN, $chatId,
                    "⛔️ گروه نامعتبر: <code>" . htmlspecialchars($arg, ENT_QUOTES, 'UTF-8') . "</code>\n"
                    . "محل دقیق: bot.php → handleTextsCallback() — یکی از گروه‌های فهرست را بزن.");
                showTextsGroups($store, $TOKEN, $chatId);
                return;
            }
            $store->clearStep($uid);
            showTextsList($store, $TOKEN, $chatId, $arg, $msgId);
            return;

        case 'show':
            if (!Texts::isValid($arg)) {
                BotApi::send($TOKEN, $chatId,
                    "⛔️ کلید متن نامعتبر: <code>" . htmlspecialchars($arg, ENT_QUOTES, 'UTF-8') . "</code>\n"
                    . "محل دقیق: bot.php → handleTextsCallback()");
                showTextsGroups($store, $TOKEN, $chatId);
                return;
            }
            $store->clearStep($uid);
            showTextItem($store, $TOKEN, $chatId, $arg, $msgId);
            return;

        case 'edit':
            if (!Texts::isValid($arg)) {
                BotApi::send($TOKEN, $chatId,
                    "⛔️ کلید متن نامعتبر: <code>" . htmlspecialchars($arg, ENT_QUOTES, 'UTF-8') . "</code>\n"
                    . "محل دقیق: bot.php → handleTextsCallback()");
                showTextsGroups($store, $TOKEN, $chatId);
                return;
            }
            $store->setStep($uid, 'await_text_edit', ['key' => $arg, 'group' => Texts::groupOf($arg)]);
            BotApi::send($TOKEN, $chatId, textsEditPrompt($arg), ['reply_markup' => Nav::stepKb()]);
            return;

        case 'reset':
            if (!Texts::isValid($arg)) {
                BotApi::send($TOKEN, $chatId,
                    "⛔️ کلید متن نامعتبر: <code>" . htmlspecialchars($arg, ENT_QUOTES, 'UTF-8') . "</code>\n"
                    . "محل دقیق: bot.php → handleTextsCallback()");
                showTextsGroups($store, $TOKEN, $chatId);
                return;
            }
            $store->clearStep($uid);
            Texts::reset($store, $arg);
            showTextItem($store, $TOKEN, $chatId, $arg, $msgId,
                "↩️ «" . Texts::label($arg) . "» به متن پیش‌فرض برگردانده شد.");
            return;

        default:
            BotApi::send($TOKEN, $chatId,
                "⛔️ دستور ناشناختهٔ پنل متن‌ها: <code>" . htmlspecialchars($data, ENT_QUOTES, 'UTF-8') . "</code>\n"
                . "محل دقیق: bot.php → handleTextsCallback() → default");
            return;
    }
}

/**
 * گیت پرداخت قبل از ساخت ربات.
 * برمی‌گرداند true یعنی «پرداخت لازم بود و منوی پرداخت نمایش داده شد، ساخت متوقف شود».
 * false یعنی «پرداخت لازم نیست، ادامه بده».
 */
function gateBuildPayment(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, string $type): bool
{
    try { Payments::ensureSchema($store); } catch (Throwable $e) {}
    // ادمین نامحدود و معاف از پرداخت است
    if (PaymentLimits::isAdminUnlimited($user, $SUPERS)) return false;
    // اگر هر دو درگاه خاموش باشند، اصلاً پرداختی وجود ندارد
    if (!PaymentGateways::isAnythingEnabled($store)) return false;
    $req = Payments::requiredForBuild($store, $user, $type, $SUPERS);
    // کاربر مسدود نباید فاکتور ببیند: پرداختش سقف را از ۰ به ۱ می‌برد و مسدودی ادمین را دور می‌زند
    if (!empty($req['blocked'])) {
        BotApi::send($TOKEN, $chatId, PaymentLimits::blockedNotice(),
            ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
        return true;
    }
    if ((int)$req['amount'] <= 0) return false;

    $amount = (int)$req['amount'];
    $parts = [];
    $vars = [
        'amount' => PaymentPricing::formatToman($amount),
        'slots' => (string)(int)$req['slots'],
        'count' => (string)PaymentLimits::botCount($store, (int)$user['user_id']),
        'limit' => PaymentLimits::formatLimit(PaymentLimits::getLimit($store, $user, $SUPERS)),
        'type' => $type,
    ];
    if (!empty($req['need_limit'])) $parts[] = '🎯 سقف تعداد ربات پر است';
    if (!empty($req['need_template'])) {
        $parts[] = '🧩 قالب «' . Ui::e($type) . '»: ' . PaymentPricing::formatToman(PaymentPricing::templatePrice($store, $type));
    }

    // متن دلخواه ادمین (اگر برای قالب یا لیمیت ثبت شده باشد)
    $note = '';
    if (!empty($req['need_template'])) {
        $note = PaymentGateways::note($store, PaymentGateways::TEMPLATE, $vars);
    } elseif (!empty($req['need_limit'])) {
        $note = PaymentGateways::note($store, PaymentGateways::LIMIT, $vars);
    }

    $hasMethods = PaymentPanel::methodsAvailable($store, $cfg);
    // ردیف فاکتور فقط وقتی ساخته می‌شود که روش پرداختی هم باشد؛ قبلاً وقتی
    // همهٔ درگاه‌ها خاموش بود، هر تلاش کاربر یک ردیف pending یتیم می‌ساخت.
    //
    // ===== گارد اسپمِ فاکتور =====
    // قبلاً هر بار که کاربر «ساخت ربات جدید» را می‌زد یک ردیف تازه ساخته می‌شد؛
    // با چند کلیک متوالی، جدول payments پر از فاکتورهای رهاشده می‌شد و سهمیهٔ
    // ۵ فاکتور بازِ کاربر هم پر می‌شد. حالا اول فاکتور بازِ هم‌مورد پیدا می‌شود
    // و فقط در صورت نبودن، ردیف تازه ساخته (یا با پیام دقیق متوقف) می‌شود.
    $uidP = (int)$user['user_id'];
    $pid = 0;
    if ($hasMethods) {
        $existing = Payments::findOpenBuildPayment($store, $uidP, $type, $amount);
        if ($existing !== null) {
            $exStatus = (string)$existing['status'];
            if ($exStatus === Payments::ST_AWAIT_PAY) {
                // فاکتور آنلاین صادر شده و هنوز پرداخت نشده ⇒ همان را ادامه بده
                $exUrl = (string)($existing['pay_url'] ?? '');
                BotApi::send($TOKEN, $chatId,
                    "🔗 <b>شما یک فاکتورِ بازِ پرداخت آنلاین دارید.</b>\n\n"
                    . "لطفاً اول همان را پرداخت کنید:\n" . Payments::describe($existing)
                    . ($exUrl !== '' ? "\n\n🔗 " . Ui::link($exUrl) : ''),
                    ['reply_markup' => PaymentPanel::invoiceKb((int)$existing['id'], $exUrl)]);
                return true;
            }
            if (in_array($exStatus, [Payments::ST_AWAIT_RECEIPT, Payments::ST_AWAIT_ADMIN], true)) {
                // رسید فرستاده و منتظر ادمین است؛ دوباره فاکتور نساز
                BotApi::send($TOKEN, $chatId,
                    "🧾 <b>پرداختِ در انتظارِ بررسی دارید.</b>\n\n"
                    . "اول همان را تمام کنید (یا لغو کنید):\n" . Payments::describe($existing),
                    ['reply_markup' => PaymentPanel::myPaymentsKb($store, $uidP)]);
                return true;
            }
            $pid = (int)$existing['id']; // pending ⇒ همان فاکتور، روش پرداخت را دوباره انتخاب کن
        } elseif (Payments::openPaymentCount($store, $uidP) >= Payments::MAX_OPEN_PAYMENTS) {
            BotApi::send($TOKEN, $chatId,
                "⛔️ <b>سقفِ فاکتورِ باز پر شده است.</b>\n\n"
                . "شما " . Payments::MAX_OPEN_PAYMENTS . " فاکتور باز دارید و فاکتور تازه ساخته نمی‌شود.\n"
                . "از «🧾 پرداخت‌های من» هر کدام را پرداخت یا لغو کنید، بعد دوباره تلاش کنید.",
                ['reply_markup' => PaymentPanel::myPaymentsKb($store, $uidP)]);
            return true;
        } else {
            $pid = Payments::createBuildPayment($store, $uidP, $type, $req, '');
            AdminNotify::notify($cfg, "🧾 فاکتور جدید\nکاربر: {$uidP}\nقالب: {$type}\nمبلغ: {$req['amount']} تومان");
        }
    }
    $t = "💰 <b>برای ساخت این ربات پرداخت لازم است</b>\n" . Ui::sep() . "\n\n"
        . implode("\n", array_map(static fn(string $p): string => Ui::bullet('•', $p), $parts))
        . "\n" . Ui::kv('💵', 'مبلغ قابل پرداخت', Ui::toman($amount));
    if ($note !== '') $t .= "\n\n" . $note;
    if (!$hasMethods) {
        $t .= "\n\n" . PaymentPanel::noMethodText($store);
        BotApi::send($TOKEN, $chatId, Ui::out($t), ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
        return true;
    }
    $t .= "\n\n" . Ui::sep() . "\n" . "👇 <b>روش پرداخت را انتخاب کن:</b>";
    BotApi::send($TOKEN, $chatId, Ui::out($t), ['reply_markup' => PaymentPanel::methodKb($store, (int)$pid, $cfg)]);
    return true;
}

// ===== helpers ناوبری (ماژولار — همه از Nav تغذیه می‌شوند) =====

/** لیست «ربات‌های من» + دکمه برگشت — مشترک بین پیام و کال‌بک */
/**
 * «📦 ربات‌های من» — لیست ربات‌ها + یک خلاصهٔ آمارِ کامل بالای آن.
 *
 * قبلاً فقط یک لیست خشکِ نام ربات بود و کاربر هیچ آماری نمی‌دید؛ حالا
 * تعداد ربات، چندتا فعال است، مجموع کاربران ربات‌ها، و وضعیت سقف/پرداخت
 * همه در یک نگاه معلوم است.
 */
function sendMyBotsList(Store $store, string $TOKEN, $chatId, int $uid, int $msgId = 0): void
{
    if (!$store->hasBot($uid)) {
        $t = $store->hasPendingRequest($uid)
            ? "⏳ <b>هنوز رباتی ندارید</b>\n\nدرخواست شما ثبت شده و در انتظار تأیید ادمین است.\n"
                . "به‌محض تأیید، همین‌جا خبردار می‌شوید ✅"
            : "🤖 <b>هنوز رباتی نساخته‌اید</b>\n\n"
                . "با یک کلیک می‌توانید اولین رباتتان را بسازید؛ کمتر از یک دقیقه طول می‌کشد. 🚀\n"
                . "دکمهٔ «🤖 ساخت ربات جدید» را بزنید.";
        $kb = BotApi::kb([
            [['text' => '🤖 ساخت ربات جدید']],
            [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]],
        ]);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
        else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
        return;
    }
    $bots = $store->myBots($uid);
    if (!$bots) {
        $t = "🤖 <b>هنوز رباتی نساخته‌اید</b>\n\nدکمهٔ «🤖 ساخت ربات جدید» را بزنید تا شروع کنیم. 🚀";
        $kb = BotApi::kb([
            [['text' => '🤖 ساخت ربات جدید']],
            [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]],
        ]);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
        else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
        return;
    }

    $t = myBotsSummaryText($bots, $uid, $store);
    $rows = [];
    foreach ($bots as $b) {
        $st = ($b['status'] ?? '') === 'active' ? '🟢' : '🔴';
        $un = trim((string)($b['bot_username'] ?? ''));
        $rows[] = [['text' => "{$st} " . $b['folder'] . ($un !== '' ? " (@{$un})" : ''), 'callback_data' => "mybot:{$b['id']}"]];
    }
    $rows[] = [['text' => '➕ ساخت ربات جدید', 'callback_data' => Nav::CB_BACK_TYPE]];
    $kb = Nav::myBotsKb($rows);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

/**
 * خلاصهٔ آمارِ «ربات‌های من» — مشترک بین لیست و پنلِ آمارِ کلی.
 * دیتابیسِ هر ربات جدا باز می‌شود و بعد بسته می‌شود تا اتصال‌ها انباشت نکنند.
 */
function myBotsSummaryText(array $bots, int $uid, Store $store): string
{
    $total = count($bots);
    $active = 0; $disabled = 0; $withUsers = 0; $usersTotal = 0; $best = null;
    $types = [];
    $dbSqlite = 0; $dbMysql = 0;
    $cfg = $GLOBALS['__cfg'] ?? [];

    foreach ($bots as $b) {
        if (($b['status'] ?? '') === 'active') $active++; else $disabled++;
        $t = (string)($b['type'] ?? '?');
        $types[$t] = ($types[$t] ?? 0) + 1;
        if (trim((string)($b['db_name'] ?? '')) !== '') $dbMysql++; else $dbSqlite++;
        if (!botHasUserTable($b)) continue;
        $withUsers++;
        try {
            $pdo = childPdo((array)$cfg, $b);
            if (!$pdo) continue;
            $c = childCount($pdo, $b);
            if ($c > 0) $usersTotal += $c;
            if ($best === null || $c > $best['n']) {
                $best = ['n' => $c, 'name' => (string)($b['folder'] ?? '?')];
            }
            $pdo = null;   // آزادسازی اتصال قبل از ربات بعدی
        } catch (Throwable $e) { /* دیتابیس یک ربات خراب است ⇒ بقیه را نشان بده */ }
    }

    $limitTxt = '—';
    $payOpen = 0;
    try { Payments::ensureSchema($store); $payOpen = Payments::openPaymentCount($store, $uid); } catch (Throwable $e) {}

    $t = "📦 <b>ربات‌های من</b>\n" . Ui::sep() . "\n\n";
    $t .= Ui::kv('🤖', 'تعداد کل ربات‌ها', (string)$total);
    $t .= "\n" . Ui::kv('🟢', 'فعال', (string)$active) . "  |  " . Ui::kv('🔴', 'غیرفعال', (string)$disabled);
    if ($withUsers > 0) {
        $t .= "\n" . Ui::kv('👥', 'مجموع کاربران ربات‌ها', number_format($usersTotal));
        if ($best !== null && $best['n'] > 0) {
            $t .= "\n" . Ui::kv('🏆', 'پرکاربرترین', $best['name'] . ' — ' . number_format((int)$best['n']) . ' کاربر');
        }
    }
    if ($types !== []) {
        $parts = [];
        foreach ($types as $k => $n) $parts[] = Ui::e(Manager::templateLabel((string)$k)) . " (×{$n})";
        $t .= "\n" . Ui::kv('🧩', 'بر حسب قالب', implode(' • ', $parts));
    }
    $t .= "\n" . Ui::kv('🗄', 'دیتابیس', "MySQL: {$dbMysql} | SQLite: {$dbSqlite}");
    if ($payOpen > 0) {
        $t .= "\n" . Ui::kv('🧾', 'پرداخت‌های باز', (string)$payOpen) . " — از «🧾 پرداخت‌های من» ببینید";
    }
    $t .= "\n\n👇 <b>روی هر ربات بزنید</b> تا پنل مدیریتش (آمار، وبهوک، ویرایش توکن…) باز شود.";
    return Ui::out($t);
}

/** پنل مدیریت کاربران مجاز — مشترک بین پیام و کال‌بک (edit یا send) */
function showUsersPanel(string $TOKEN, $chatId, int $msgId = 0): void
{
    $kb = Nav::usersPanelKb();
    $t = "👥 <b>مدیریت کاربران مجاز</b>\n" . Ui::sep() . "\n\n"
        . "با «➕ افزودن کاربر» آیدی عددی بدهید تا بدون درخواست بتواند ربات بسازد.\n"
        . "با «📃 لیست» همهٔ کاربرانِ مجاز را می‌بینید.\n"
        . "با «📋 درخواست‌های جدید» در انتظارِ تأیید را می‌بینید. 👇";
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

/**
 * پنل «📋 همه ربات‌ها» — زیبا و پویا:
 * برای هر ربات دو دکمه: یکی باز کردنِ پنل، یکی حذف.
 * در بالا خلاصهٔ کل/فعال/غیرفعال نمایش داده می‌شود.
 */
function showAllBotsPanel(Store $store, string $TOKEN, $chatId, int $msgId = 0): void
{
    $bots = $store->allBots();
    if (!$bots) {
        $t = "🤖 <b>هنوز هیچ رباتی ثبت نشده است</b>\n\n"
            . "برای شروع، «🤖 ساخت ربات جدید» را بزنید.";
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => Nav::botPanelKb(['id' => 0])]);
        else BotApi::send($TOKEN, $chatId, $t);
        return;
    }
    $total = count($bots);
    $active = 0;
    $disabled = 0;
    foreach ($bots as $b) { if (($b['status'] ?? '') === 'active') $active++; else $disabled++; }

    $t = "📋 <b>همه ربات‌ها</b>\n" . Ui::sep() . "\n\n"
        . Ui::kv('🤖', 'مجموع', (string)$total)
        . "  |  " . Ui::kv('🟢', 'فعال', (string)$active)
        . "  |  " . Ui::kv('🔴', 'غیرفعال', (string)$disabled)
        . "\n\n👇 <b>از دکمه‌های زیر</b> پنل هر ربات را باز کنید، پیام همگانی بفرستید یا حذفش کنید.";

    $rows = [];
    $limit = 15;
    foreach (array_slice($bots, 0, $limit) as $b) {
        $st = ($b['status'] ?? '') === 'active' ? '🟢' : '🔴';
        $owner = (int)($b['owner_id'] ?? 0);
        $label = "{$st} #{$b['id']} " . $b['folder'] . " — " . Manager::templateLabel((string)($b['type'] ?? '')) . " | مالک: {$owner}";
        $rows[] = [['text' => $label, 'callback_data' => "mybot:{$b['id']}"]];
        $rows[] = [
            ['text' => '📣 پیام فقط به کاربران همین ربات', 'callback_data' => "act:broadcast:{$b['id']}"],
            ['text' => '🗑 حذف', 'callback_data' => "act:delask:{$b['id']}"],
        ];
    }
    if ($total > $limit) {
        $t .= "\n\nℹ️ " . ($total - $limit) . " ربات دیگر در این صفحه نیست؛ "
            . "با «🔄 تازه‌سازی» صفحهٔ بعدی را ببینید.";
    }
    $rows[] = [['text' => '🔄 تازه‌سازی', 'callback_data' => 'allbots:refresh']];
    $rows[] = [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]];
    $kb = BotApi::ikb($rows);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

/**
 * متن دیتابیس یک ربات فرزند برای نمایش — قالب SQLite دیتابیس جدا ندارد
 * و نام خالی («<code></code>») بد به نظر می‌رسید.
 */
function botDbLabel(array $bot): string
{
    $name = (string)($bot['db_name'] ?? '');
    if ($name !== '') return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return 'SQLite (داخل پوشه)';
}

/**
 * آیا این قالب جدول کاربر قابل‌خواندن دارد؟
 *
 * «پیام همگانی» و «👥 کاربران» فقط برای قالب‌هایی معنا دارد که جدول `user`
 * با ستون `id` تلگرامی دارند (فاکسیما/میرزا). قالب SQLite پاسارگاد جدول
 * `users` دارد ولی آن جدول نمایندگان پنل است، نه کاربران تلگرام — پس خواندنش
 * یعنی پیام همگانی به آدم‌های اشتباه.
 */
function botHasUserTable(array $bot): bool
{
    $type = (string)($bot['type'] ?? '');
    return $type === 'faxima' || $type === 'mirza';
}

/** متن پنل یک ربات (مشترک بین edit و send) */
function botPanelText(array $cfg, array $bot): string
{
    $pdo = childPdo($cfg, $bot);
    $hasUsers = botHasUserTable($bot);
    $count = ($pdo && $hasUsers) ? childCount($pdo, $bot) : -1;
    $countTxt = $count >= 0 ? number_format($count) . ' کاربر' : 'قالبِ بدون فهرست کاربر';
    $pdo = null;
    $st = (string)($bot['status'] ?? '') === 'active'
        ? '🟢 <b>فعال</b>' : '🔴 <b>غیرفعال</b>';
    $name = htmlspecialchars((string)($bot['bot_username'] ?? ''), ENT_QUOTES, 'UTF-8');
    $folder = htmlspecialchars((string)($bot['folder'] ?? ''), ENT_QUOTES, 'UTF-8');
    $label = Manager::templateLabel((string)($bot['type'] ?? ''));
    $dbLabel = htmlspecialchars(botDbLabel($bot), ENT_QUOTES, 'UTF-8');

    $t = "🤖 <b>{$folder}</b>\n";
    $t .= "<i>قالب: " . Ui::e($label) . "</i>\n\n" . Ui::sep() . "\n\n";

    if ($name !== '') {
        $t .= Ui::bullet('🔹', "<b>یوزرنیم:</b> " . Ui::link('https://t.me/' . ltrim($name, '@'), '@' . $name));
    } else {
        $t .= Ui::bullet('🔹', "<b>یوزرنیم:</b> <i>تنظیم نشده</i>");
    }
    $t .= "\n" . Ui::bullet('🔹', "<b>وضعیت:</b> {$st}");
    $t .= "\n" . Ui::bullet('🔹', '<b>آیدی ادمین:</b> ' . Ui::code((string)($bot['admin_id'] ?? '')));
    $t .= "\n" . Ui::bullet('🔹', '<b>صاحب ربات (در ربات‌ساز):</b> ' . Ui::code((string)(int)($bot['owner_id'] ?? 0)));
    $t .= "\n" . Ui::bullet('🔹', '<b>دیتابیس:</b> ' . Ui::code($dbLabel));
    $t .= "\n" . Ui::bullet('🔹', '<b>کاربران ربات:</b> ' . Ui::e($countTxt));

    // تاریخ ساخت (ستون ممکن است در اسکیمای قدیمی نباشد ⇒ بدون هشدار چک می‌شود)
    if (isset($bot['created_at']) && trim((string)$bot['created_at']) !== '') {
        $ts = strtotime((string)$bot['created_at']);
        $t .= "\n" . Ui::bullet('📅', '<b>تاریخ ساخت:</b> ' . Ui::e(
            $ts ? date('Y-m-d H:i', $ts) : (string)$bot['created_at']
        ));
    }

    // وبهوک: آدرس واقعی ثبت‌شده (اگر ستون خالی بود از استراتژی استاندارد محاسبه می‌شود)
    $wh = trim((string)($bot['webhook_url'] ?? ''));
    if ($wh === '') {
        try { $wh = trim((string)Manager::webhookUrlForBot($cfg, $bot)); } catch (Throwable $e) { $wh = ''; }
    }
    if ($wh !== '') {
        // آدرس به‌صورت لینکِ کلیک‌پذیر نمایش داده می‌شود (و نسخهٔ کامل هم در <code>)
        $t .= "\n\n" . Ui::bullet('🔗', '<b>وبهوک:</b> ' . Ui::link($wh));
        $t .= "\n" . Ui::bullet('📋', '<i>کپی کامل:</i> ' . Ui::code($wh));
    } else {
        $t .= "\n\n" . Ui::bullet('🔗', '<b>وبهوک:</b> <i>ست نشده</i>');
    }
    if (isset($bot['webhook_secret']) && trim((string)$bot['webhook_secret']) !== '') {
        $t .= "\n" . Ui::bullet('🔒', '<b>رمز وبهوک</b> ‎(X-Telegram-Bot-Api-Secret-Token): <b>فعال</b> ✅');
    }
    $t .= "\n\n" . Ui::sep() . "\n";
    $t .= "✏️ <b>تغییر توکن یا ادمین؟</b> از دکمهٔ «✏️ ویرایش توکن» / «✏️ ویرایش آیدی ادمین»\n"
        . "استفاده کنید؛ ربات و کاربرانش حذف نمی‌شوند.";
    return Ui::out($t);
}

/**
 * برگشت یک مرحله‌ای داخل stepها — قبل از switch اصلی handleStep صدا زده می‌شود.
 * هرگز استثنا نمی‌دهد و هرگز کاربر را در مرحله گیر نمی‌اندازد (fallback: منوی اصلی).
 */
/**
 * بستن فاکتور «باز» کاربر وقتی از مرحلهٔ پرداخت خارج می‌شود (انصراف/برگشت).
 * بدون این، فاکتورِ کارت‌به‌کارت برای همیشه در وضعیت «در انتظار فیش» می‌ماند
 * و یکی از ۵ سهمیهٔ فاکتور باز کاربر را بی‌دلیل مصرف می‌کند.
 */
function cancelOpenPayment(Store $store, int $uid, array $temp): void
{
    $pid = (int)($temp['payment_id'] ?? 0);
    if ($pid <= 0) return;
    try {
        $p = Payments::getPayment($store, $pid);
        if (!$p || (int)$p['user_id'] !== $uid) return;
        if (in_array($p['status'], [Payments::ST_PENDING, Payments::ST_AWAIT_RECEIPT], true)) {
            Payments::setStatus($store, $pid, Payments::ST_CANCELLED);
        }
    } catch (Throwable $e) {
        Logger::getInstance()->warning('payment', "cancelOpenPayment failed ({$pid}): " . $e->getMessage());
    }
}

/**
 * آیا این کاربر اجازهٔ ویرایش هویتِ (توکن/ادمینِ) این ربات را دارد؟
 * صاحب ربات یا هر ادمین — دقیقاً همان قانونِ بقیهٔ پنلِ ربات.
 */
function botEditableBy(array $bot, array $user, array $supers): bool
{
    if (isAdmin($user, $supers)) return true;
    return (int)($bot['owner_id'] ?? 0) === (int)($user['user_id'] ?? 0);
}

/**
 * اعمال توکنِ تازه روی یک رباتِ ساخته‌شده.
 *
 * ترتیب کارها و دلیلش:
 *  ۱) config.php ربات فرزند با توکن جدید پچ می‌شود (وگرنه ربات آپدیت می‌گیرد
 *     ولی توکنِ خودش را رد می‌کند و هیچ پیامی نمی‌کند).
 *  ۲) رکورد دیتابیس به‌روز می‌شود (توکن رمزنگاری‌شده + یوزرنیم + bot_id).
 *  ۳) وبهوک دوباره ست می‌شود — چون فرمول secret و آدرس وبهوک به توکن وابسته‌اند
 *     و با توکنِ کهنه، تلگرام هر درخواستی را دور می‌ریخت.
 *
 * اگر مرحلهٔ ۱ یا ۲ شکست بخورد، استثنا بالا می‌رود و caller پیام خطای دقیق
 * می‌دهد؛ چون توکن در DB هنوز عوض نشده، ربات در وضعیت سالمِ قبلی می‌ماند.
 */
function applyBotTokenChange(array $cfg, Store $store, string $TOKEN, array $bot, string $newToken, string $newUsername, int $newBotId): void
{
    $botDir = Manager::childBotsDir() . '/' . $bot['folder'];
    if (!is_dir($botDir)) {
        throw new Exception("پوشهٔ این ربات پیدا نشد: <code>bots/" . Ui::e((string)$bot['folder']) . "</code>");
    }
    // توکنِ کهنه لازم است تا ادمینِ فعلی و یوزرنیمِ قبلی داخل config عوض شوند
    $oldToken = childToken($bot);
    $res = Manager::updateChildIdentity(
        (string)($bot['type'] ?? ''),
        $botDir,
        $newToken,
        (int)($bot['admin_id'] ?? 0),
        $newUsername !== '' ? $newUsername : (string)($bot['bot_username'] ?? '')
    );
    if ($res['changed'] < 2) {
        throw new Exception("پچ کانفیگ ناقص بود (فقط {$res['changed']} فیلد عوض شد).");
    }
    $enc = encryptToken($newToken, $GLOBALS['secretKey'] ?? Manager::DEFAULT_SECRET_KEY);
    $store->updateBot((int)$bot['id'], [
        'token'         => $enc,
        'bot_username'  => $newUsername !== '' ? $newUsername : (string)($bot['bot_username'] ?? ''),
        'bot_id'        => $newBotId > 0 ? $newBotId : (int)($bot['bot_id'] ?? 0),
    ]);
    // وبهوک را روی توکن تازه دوباره ست می‌کنیم
    $botFresh = $store->botById((int)$bot['id']);
    $secret = Manager::webhookSecret((string)($bot['type'] ?? ''), $newToken);
    $url = Manager::webhookUrl($cfg, (string)$bot['folder'], (string)($bot['type'] ?? ''), $secret);
    $setR = BotApi::setWebhook($newToken, $url, $secret);
    if (!is_array($setR) || empty($setR['ok'])) {
        Logger::getInstance()->warning('botedit', "setWebhook after token change failed: " . (($setR['description'] ?? '') ?: 'no response'));
    }
    if ($botFresh) $store->updateBot((int)$bot['id'], ['webhook_url' => $url]);
    unset($oldToken);
}

/**
 * اعمال آیدی ادمینِ تازه روی یک رباتِ ساخته‌شده.
 * هم دیتابیسِ ربات‌ساز و هم config.php خودِ ربات به‌روز می‌شوند؛ وگرنه ربات
 * هنوز به ادمینِ قبلی پیام می‌دهد و به ادمینِ جدید هیچ.
 */
function applyBotAdminChange(array $cfg, Store $store, array $bot, int $newAdmin): void
{
    $botDir = Manager::childBotsDir() . '/' . $bot['folder'];
    if (!is_dir($botDir)) {
        throw new Exception("پوشهٔ این ربات پیدا نشد: <code>bots/" . Ui::e((string)$bot['folder']) . "</code>");
    }
    $res = Manager::updateChildIdentity(
        (string)($bot['type'] ?? ''),
        $botDir,
        childToken($bot),
        $newAdmin,
        (string)($bot['bot_username'] ?? '')
    );
    if ($res['changed'] < 1) {
        throw new Exception("پچ کانفیگ ناقص بود (هیچ فیلدی عوض نشد).");
    }
    $store->updateBot((int)$bot['id'], ['admin_id' => $newAdmin]);
}

/**
 * پنل «⚙️ تنظیمات» — همهٔ کلیدهای روشن/خاموش و وضعیت نرخ دلار یک‌جا.
 * (ساخت بدون درخواست، حالت تعمیرات، متن تعمیرات، زمان بازگشت، نرخ دلار، درگاه‌ها)
 */
function showSettingsPanel(Store $store, string $TOKEN, $chatId, int $msgId = 0, string $note = ''): void
{
    $maint = BuildSettings::maintenanceOn($store);
    $approval = BuildSettings::approvalRequired($store);
    $eta = BuildSettings::maintenanceEta($store);

    $t = "⚙️ <b>تنظیمات ربات‌ساز</b>\n" . Ui::sep() . "\n\n";

    $t .= BuildSettings::statusLine($store, $maint, 'حالت تعمیرات',
            $maint
                ? 'ساخت ربات برای همه (حتی ادمین) بسته است و پیام «در حال تعمیر» نشان داده می‌شود.'
                : 'ساخت ربات باز است.'
        ) . "\n\n";

    $t .= BuildSettings::statusLine($store, $approval, 'نیاز به تأیید ادمین (ساخت بدون درخواست)',
            $approval
                ? 'کاربر باید درخواست بدهد و منتظر تأیید شما بماند.'
                : 'کاربر مستقیم وارد انتخاب قالب می‌شود؛ درخواستی ثبت نمی‌شود.'
        ) . "\n\n";

    $t .= "⏳ <b>زمان تقریبی بازگشت:</b> " . ($eta !== '' ? Ui::e($eta) : '<i>تعیین نشده</i>') . "\n";
    $t .= Ui::kv('📝', 'متن تعمیرات', Texts::hasCustom($store, 'maintenance') ? 'دلخواه (✍️)' : 'پیش‌فرض (📌)') . "\n\n";

    $t .= Ui::sep() . "\n";
    $t .= "💵 <b>نرخ دلار</b>\n" . implode("\n", FxRate::statusLines($store)) . "\n\n";

    // اکانت سازندهٔ پنل — فقط قالب‌هایی که «پنل نمایندگی» دارند به آن نیاز
    // دارند. اگر خالی باشد خودکار ساخته می‌شود، ولی بهتر است ادمین بداند.
    $ownerU = trim((string)($store->getSetting('child_owner_username', '') ?? ''));
    if ($ownerU !== '') {
        $t .= "🏢 <b>اکانت سازندهٔ پنل:</b> " . Ui::e($ownerU) . " ✅\n";
        $t .= "<i>در ربات‌های تازه‌ساخته نوشته می‌شود.</i>\n\n";
    } else {
        $t .= "🏢 <b>اکانت سازندهٔ پنل:</b> <i>تنظیم نشده — هنگام ساخت ربات خودکار ساخته می‌شود</i>\n\n";
    }

    if ($note !== '') $t = $note . "\n\n" . $t;

    $t = Ui::out($t);
    $kb = Nav::settingsPanelKb($store);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
}

function handleBack(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, string $step, array $temp): void
{
    $uid = (int)$user['user_id'];
    try {
        $target = Nav::backTarget($step);
        switch ($target['kind']) {
            case 'type':
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'type_prompt'), ['reply_markup' => Nav::typeMenu()]);
                return;
            case 'step': {
                $prev = (string)($target['step'] ?? 'idle');
                if ($prev === 'await_bot_token') {
                    $type = (string)($temp['type'] ?? '');
                    if ($type === '') { // temp گم شده (مثلاً ری‌استارت) → انتخاب نوع
                        $store->clearStep($uid);
                        BotApi::send($TOKEN, $chatId, Texts::get($store, 'type_prompt'), ['reply_markup' => Nav::typeMenu()]);
                        return;
                    }
                    $store->setStep($uid, 'await_bot_token', ['type' => $type]);
                    $names = Manager::validTypes();
                    $label = $names[$type] ?? $type;
                    BotApi::send($TOKEN, $chatId, Texts::get($store, 'token_prompt', ['type' => $label]), ['reply_markup' => Nav::stepKb()]);
                    return;
                }
                // برگشت به await_admin_id — بدون توکن معتبر نمی‌شود ادامه داد
                $hasToken = !empty($temp['token']);
                if (!$hasToken) {
                    $store->clearStep($uid);
                    BotApi::send($TOKEN, $chatId, Texts::get($store, 'type_prompt'), ['reply_markup' => Nav::typeMenu()]);
                    return;
                }
                $store->setStep($uid, 'await_admin_id');
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'admin_prompt'), ['reply_markup' => Nav::stepKb()]);
                return;
            }
            case 'users':
                $store->clearStep($uid);
                showUsersPanel($TOKEN, $chatId);
                return;
            case 'backup':
                $store->clearStep($uid);
                showBackupPanel($cfg, $store, $TOKEN, $chatId);
                return;
            case 'shop':
                // برگشت از مرحلهٔ رسید ⇒ فاکتور نیمه‌کاره بسته می‌شود
                if ($step === 'await_card_receipt') cancelOpenPayment($store, $uid, $temp);
                $store->clearStep($uid);
                showLimitShop($store, $TOKEN, $chatId, $user, $SUPERS);
                return;
            case 'payments':
                $store->clearStep($uid);
                if (!isAdmin($user, $SUPERS)) {
                    BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                    return;
                }
                showPaymentsAdmin($store, $TOKEN, $chatId);
                return;
            case 'texts':
                $store->clearStep($uid);
                if (!isAdmin($user, $SUPERS)) {
                    BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                    return;
                }
                showTextsGroups($store, $TOKEN, $chatId);
                return;
            case 'settings':
                $store->clearStep($uid);
                if (!isAdmin($user, $SUPERS)) {
                    BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                    return;
                }
                showSettingsPanel($store, $TOKEN, $chatId);
                return;
            case 'bot': {
                $botId = (int)($temp['bot_id'] ?? 0);
                $store->clearStep($uid);
                $bot = $botId > 0 ? $store->botById($botId) : null;
                if (!$bot || ((int)$bot['owner_id'] !== $uid && !isAdmin($store->user($uid), $SUPERS))) {
                    BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
                    return;
                }
                BotApi::send($TOKEN, $chatId, botPanelText($cfg, $bot), ['reply_markup' => Nav::botPanelKb($bot, isAdmin($user, $SUPERS))]);
                return;
            }
            case 'menu':
            default:
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
                return;
        }
    } catch (Throwable $e) {
        try { $store->clearStep($uid); } catch (Throwable $ignored) {}
        // قبلاً اینجا خطا بی‌صدا بلعیده می‌شد و کاربر فقط منوی اصلی می‌دید؛
        // حالا علت دقیق + محل (فایل:خطا) + مرحله‌ای که شکسته گفته می‌شود.
        reportHandlerError($TOKEN, $chatId, 'back: ' . stepLabel($step), $step, $e,
            mainMenu($user, $SUPERS, $store));
    }
}

/**
 * اتصال به دیتابیس ربات فرزند.
 *
 * قالب‌های SQLite (پاسارگاد) دیتابیس جدا ندارند؛ فایلشان داخل پوشهٔ خودشان است.
 * قبلاً همیشه DSN از نوع MySQL ساخته می‌شد که برای این قالب یعنی «اتصال به
 * دیتابیسی به نام تهی» ⇒ همیشه null ⇒ شمارش کاربر و پیام همگانی بی‌صدا از کار
 * می‌افتاد. حالا اول db_name خالی را می‌بیند و مسیر فایل SQLite را می‌سازد.
 */
function childPdo(array $cfg, array $bot): ?PDO {
    $dbName = (string)($bot['db_name'] ?? '');
    try {
        if ($dbName === '') {
            // قالب SQLite ⇒ فایل دیتابیس داخل پوشهٔ ربات
            $folder = (string)($bot['folder'] ?? '');
            if ($folder === '') return null;
            $sqlitePath = Manager::childBotsDir() . '/' . $folder . '/data/bot.sqlite';
            if (!is_file($sqlitePath)) return null;
            return new PDO('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        $port = $cfg['db_port'] ?? 3306;
        $dsn = "mysql:host={$cfg['db_host']};port={$port};dbname={$dbName};charset=utf8mb4";
        // ATTR_TIMEOUT: بدون آن، اگر میزبان MySQL از دسترس خارج باشد هر فراخوانی
        // childPdo (پنل ربات، آمار، همگانی) تا ۶۰ ثانیه معطل connect می‌ماند؛
        // چند ربات با هم یعنی وبهوک عملاً «هنگ» می‌کند. سقف ۵ ثانیه کافی است.
        return new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (Throwable $e) { return null; }
}

// ===== جدول کاربران ربات فرزند (با پیشوند احتمالی db_table_prefix) =====
function childUserTable(array $bot): array {
    $prefix = $bot['db_table_prefix'] ?? '';
    $table = $prefix . 'user';
    return [$table, 'id'];
}

function childUsers(PDO $pdo, array $bot): array {
    [$t, $c] = childUserTable($bot);
    try { return $pdo->query("SELECT `{$c}` FROM `{$t}`")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) { return []; }
}

function childCount(PDO $pdo, array $bot): int {
    [$t, $c] = childUserTable($bot);
    try { return (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
    catch (Exception $e) { return -1; }
}

// توکن ذخیره‌شده ممکن است رمزنگاری‌شده باشد؛ برای استفاده در API تلگرام رمزگشایی کن
// (با توکن‌های ساده قدیمی هم سازگار است)
function childToken(array $bot): string {
    $t = $bot['token'] ?? '';
    if ($t === '') return '';
    $key = $GLOBALS['secretKey'] ?? Manager::DEFAULT_SECRET_KEY;
    try {
        $d = decryptToken($t, $key);
        if (is_string($d) && preg_match('/^\d+:[\w\-]{20,}$/', $d)) return $d;
    } catch (Throwable $e) {
        // لاگ کن تا خرابی رمزگشایی توکن فرزند بی‌صدا گم نشود
        Logger::getInstance()->warning('childToken', 'decrypt failed: ' . $e->getMessage());
    }
    return $t;
}

// ================= message handler =================
function handleMessage(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, string $text, array $msg, string $deepLink = null): void
{
    $uid = (int)$user['user_id'];
    $admin = isAdmin($user, $SUPERS);
    // «/start@ربات من» باید مثل «/start» رفتار کند — وگرنه همهٔ دستورات شکست می‌خوردند
    $text = normalizeCommandText($text);

    // کاربر «مجاز» یا ادمین از قبل دسترسی دارد.
    // کاربر غیرمجاز هم باید بتواند «🤖 ساخت ربات جدید» بزند تا درخواستش ثبت شود —
    // طبق README: «کاربر ساخت ربات جدید می‌زند → درخواست ثبت می‌شود → تو تأیید می‌کنی».
    // قبلاً اینجا deny می‌شد و کل زیرسیستم addPendingRequest/تأیید عملاً برای کاربران
    // غیرمجاز غیرقابل‌دسترس بود. دستورات حساس داخل switch با $admin محافظت می‌شوند.

    $step = $user['step'];
    $temp = json_decode($user['temp'] ?? '{}', true) ?: [];

    // لینک عمیق /start <param> هر مرحلهٔ نیمه‌کاره را می‌شکند؛
    // قبلاً clearStep می‌شد ولی $step قدیمی می‌ماند و کاربر به‌جای پیام خوش‌آمد،
    // داخل handleStep می‌رفت و پیام «مرحله نامشخص» می‌گرفت.
    if ($deepLink !== null) {
        Logger::getInstance()->info('deep_link', "User {$uid} deep link: {$deepLink}");
        $store->clearStep($uid);
        $step = 'idle';
        $temp = [];
    }

    if ($step !== 'idle') {
        handleStep($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $text, $msg, $step, $temp);
        return;
    }

    if ($text === '/start' || str_starts_with($text, '/start ') || Nav::isMenu($text) || Nav::isCancel($text) || Nav::isBack($text)) {
        $store->clearStep($uid);
        // ===== ست دستورات ربات (فقط هنگام /start — نه هر آپدیت) =====
        BotApi::setMyCommands($TOKEN, [
            ['command' => 'start', 'description' => '🏠 منوی اصلی'],
            ['command' => 'menu', 'description' => '🏠 منوی اصلی'],
            ['command' => 'mybots', 'description' => '📦 ربات‌های من'],
            ['command' => 'stats', 'description' => '📊 آمار ربات‌ساز'],
            ['command' => 'cron', 'description' => '⏰ وضعیت کرون (ادمین)'],
            ['command' => 'diagnose', 'description' => '🔍 بررسی سیستم (ادمین)'],
            ['command' => 'texts', 'description' => '📝 ویرایش متن‌های ربات (ادمین)'],
            ['command' => 'cancel', 'description' => '❌ انصراف از مرحلهٔ فعلی'],
            ['command' => 'help', 'description' => 'ℹ️ راهنما'],
        ]);
        // ===== دکمهٔ مینی‌اپ پنل مدیریت (فقط برای ادمین‌ها) =====
        if ($admin && !empty($cfg['base_url'])) {
            BotApi::setChatMenuButton($TOKEN, rtrim((string)$cfg['base_url'], '/') . '/app/', '🖥 پنل مدیریت', $chatId);
        }
        $role = $admin ? "مدیر 👑" : "کاربر مجاز ✅";
        $deepNote = $deepLink !== null
            ? "🔗 <b>لینک شما:</b> " . Ui::code($deepLink) . "\n\n"
            : '';
        $types = implode('، ', array_values(Manager::availableTypes()));
        if ($types === '') $types = 'هیچ قالبی روی سرور نصب نیست';
        $welcome = Texts::get($store, 'welcome', ['role' => $role, 'types' => $types]);
        // اگر حالت تعمیرات روشن است، بالای پیام خوش‌آمد همان هشدارِ روشن می‌آید
        if (BuildSettings::maintenanceOn($store)) {
            $welcome .= "\n\n" . Ui::sep() . "\n" . BuildSettings::maintenanceNotice($store);
        }
        BotApi::send($TOKEN, $chatId, $deepNote . $welcome,
            ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
        return;
    }

    // ===== هندلرهای دستور (نام‌های مستعار منو) =====
    switch ($text) {
        case '/mybots': $text = '📦 ربات‌های من'; break;
        case '/help':   $text = 'ℹ️ راهنما'; break;
        case '/stats':  $text = $admin ? '📊 آمار' : 'ℹ️ راهنما'; break;
        case '/cron':   $text = $admin ? '⏰ کرون' : 'ℹ️ راهنما'; break;
        case '/diagnose': $text = $admin ? '🔍 دیاگنوز' : 'ℹ️ راهنما'; break;
        case '/source': $text = '🔄 دریافت سورس بروز'; break;
        case '/texts':    $text = $admin ? '📝 متن‌ها' : 'ℹ️ راهنما'; break;
        case '/settings': $text = $admin ? '⚙️ تنظیمات' : 'ℹ️ راهنما'; break;
    }

    // دکمه «📋 درخواست‌های جدید» شمارنده پویا دارد: «📋 درخواست‌های جدید (N)»
    if ($admin && str_starts_with($text, '📋 درخواست‌های جدید')) {
        sendPendingRequests($store, $TOKEN, $chatId);
        return;
    }

    // دکمه «💳 پرداخت‌ها» شمارنده پویا دارد: «💳 پرداخت‌ها (🧾N)» — فقط ادمین
    if ($admin && str_starts_with($text, '💳 پرداخت‌ها')) {
        showPaymentsAdmin($store, $TOKEN, $chatId);
        return;
    }

    // دکمهٔ «🔄 دریافت سورس بروز» فقط برای سوپرادمین (بدون شمارندهٔ ربات‌ها؛
    // این بخش دیگر رباتی را تغییر نمی‌دهد، فقط templates/ را تازه می‌کند)
    if (str_starts_with($text, '🔄 دریافت سورس بروز')) {
        if (!isSuper($SUPERS, $uid)) { BotApi::send($TOKEN, $chatId, "⛔️ فقط سوپرادمین اجازهٔ بروزرسانی سورس را دارد."); return; }
        showSourcePanel($cfg, $store, $TOKEN, $chatId);
        return;
    }

    if ($text === '⬆️ آپدیت ربات‌ساز' || $text === '/update') {
        // پنل «⬆️ آپدیت ربات‌ساز» حذف شد؛ فقط تازه‌کردنِ templates/ باقی مانده است.
        // کیبوردهای قدیمیِ کاربران نباید «دستور نامعتبر» بگیرند.
        BotApi::send($TOKEN, $chatId,
            "ℹ️ <b>پنل «آپدیت ربات‌ساز» حذف شد.</b>\n\n"
            . "برای به‌روزرسانی، از «🔄 دریافت سورس بروز» استفاده کنید\n"
            . "(همان پنل فقط پوشهٔ ‎<code>templates/</code> را تازه می‌کند).",
            ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
        return;
    }

    switch ($text) {
        case '🤖 ساخت ربات جدید':
            // ===== حالت تعمیرات: هیچ‌کس ربات تازه نمی‌سازد (حتی ادمین) =====
            // پیامِ تعمیرات عمداً واضح و کامل است: کاربر باید بداند مشکلی هست
            // و کارهای قبلی‌اش (ربات‌های موجود) سالم‌اند.
            if (BuildSettings::maintenanceOn($store)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, BuildSettings::maintenanceNotice($store),
                    ['reply_markup' => BotApi::kb([
                        [['text' => '📦 ربات‌های من']],
                        [['text' => 'ℹ️ راهنما']],
                    ])]);
                return;
            }
            // گیت لیمیت ماژولار: ادمین نامحدود؛ بقیه طبق bot_limit
            try { Payments::ensureSchema($store); } catch (Throwable $e) {}
            // مسدودی صریح ادمین: فروشگاه نشان داده نمی‌شود چون خرید اسلات مسدودی را دور می‌زند
            if (!$admin && PaymentLimits::isBlocked($store, $user, $SUPERS)) {
                BotApi::send($TOKEN, $chatId, PaymentLimits::blockedNotice(),
                    ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
                return;
            }
            if (!PaymentLimits::canBuild($store, $user, $SUPERS) && !$admin) {
                showLimitShop($store, $TOKEN, $chatId, $user, $SUPERS);
                return;
            }
            // ===== «ساخت بدون درخواست» =====
            // کلید «نیاز به تأیید» خاموش ⇒ کاربر مستقیم وارد انتخاب قالب می‌شود
            // و اصلاً ردیف درخواست ساخته نمی‌شود (همان چیزی که ادمین خواسته).
            if (!$admin && BuildSettings::skipApproval($store)) {
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'type_prompt'), ['reply_markup' => Nav::typeMenu()]);
                return;
            }
            if ($store->hasPendingRequest($uid)) {
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'request_pending_again'));
                return;
            }
            // ادمین یا کاربر تأییدشده: مستقیم انتخاب نوع ربات
            if ($admin || $store->hasApprovedRequest($uid)) {
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'type_prompt'), ['reply_markup' => Nav::typeMenu()]);
                return;
            }
            $store->addPendingRequest($uid, 'bot');
            AdminNotify::notify($cfg, "📥 درخواست جدید ساخت ربات\nکاربر: {$uid}");
            BotApi::send($TOKEN, $chatId, Texts::get($store, 'request_pending'));
            return;

        case '💳 افزایش لیمیت':
            try { Payments::ensureSchema($store); } catch (Throwable $e) {}
            if (!PaymentGateways::isAnythingEnabled($store)) {
                // منوی اصلی دکمهٔ «🧾 پرداخت‌های من» هم دارد تا کاربر بتواند
                // پرداخت‌های قبلی‌اش را ببیند (قبلاً stepKb می‌داد که «برگشت»
                // آن وضعیت اینجا کاربر را به منوی اصلی می‌پراند و گیج‌کننده بود).
                BotApi::send($TOKEN, $chatId,
                    "ℹ️ <b>فعلاً فروش لیمیت و قالب غیرفعال است.</b>\n"
                    . "پرداخت‌های قبلی خود را از «🧾 پرداخت‌های من» ببینید.\n"
                    . "برای اطلاعات بیشتر با ادمین در میان بگذارید.",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            showLimitShop($store, $TOKEN, $chatId, $user, $SUPERS);
            return;

        case '📦 ربات‌های من':
            sendMyBotsList($store, $TOKEN, $chatId, $uid);
            return;

        // دکمهٔ کیبورد «🧾 پرداخت‌های من» — قبلاً فقط کال‌بک داشت و فشاردادنش
        // روی کیبورد با «دستور نامعتبر» جواب می‌گرفت.
        case '🧾 پرداخت‌های من': {
            try { Payments::ensureSchema($store); } catch (Throwable $e) {}
            BotApi::send($TOKEN, $chatId,
                PaymentPanel::myPaymentsText($store, $uid),
                ['reply_markup' => PaymentPanel::myPaymentsKb($store, $uid)]);
            return;
        }

        case 'ℹ️ راهنما': {
            // بخش‌های پویای راهنما: پرداخت (فقط اگر درگاهی فعال باشد)، اشاره به
            // لاگ/دیاگنوز (فقط برای ادمین) و راه‌های ارتباطی (اگر ادمین پر کرده باشد).
            $helpPay = '';
            try {
                if (PaymentGateways::isAnythingEnabled($store)) {
                    $methods = PaymentGateways::availableMethods($store, $cfg);
                    $names = [];
                    foreach ($methods as $m) $names[] = Ui::e(Payments::methodLabel($m));
                    $helpPay = "\n\n" . Ui::sep() . "\n"
                        . "💳 <b>پرداخت</b>\n"
                        . "سقف ساخت ربات و بعضی قالب‌ها پولی است.\n\n"
                        . "• 🛒 خرید اسلات/مجوز: «💳 افزایش لیمیت»\n"
                        . "• 🧾 پیگیری: «🧾 پرداخت‌های من»\n"
                        . "• 🟣🌐 روش‌های آنلاین: " . ($names !== [] ? implode(' • ', $names) : 'فعلاً هیچ‌کدام فعال نیست') . "\n"
                        . "• 💳 کارت‌به‌کارت: عکس فیش یا شمارهٔ پیگیری بفرستید تا ادمین بررسی کند.\n"
                        . "• 🔄 اگر تأیید خودکار انجام نشد، «🔄 بررسی وضعیت» را بزنید.";
                }
            } catch (Throwable $e) {}
            $helpContact = trim(Texts::get($store, 'contact'));
            $helpContact = $helpContact !== '' ? "\n\n" . Ui::sep() . "\n📞 <b>ارتباط با ادمین</b>\n" . $helpContact : '';
            $help = Texts::get($store, 'help', [
                'payment' => $helpPay,
                'diag'    => $admin ? "لاگ کامل هم در <code>data/logs/</code> و «🔍 دیاگنوز» است." : '',
                'contact' => $helpContact,
            ]);
            // نسخهٔ در حال اجرا همیشه پای پیام می‌آید: اولین چیزی که برای
            // «آپدیت گرفتم ولی ربات همان نسخهٔ قبلی است» باید دیده شود.
            $help .= "\n\n" . Manager::versionLine();
            $diskHelp = Manager::diskVersion(__DIR__);
            if ($diskHelp !== '' && $diskHelp !== Manager::APP_VERSION) {
                $help .= " (⚠️ نسخهٔ روی دیسک: <code>{$diskHelp}</code> — کدِ در حال اجرا کهنه است؛ سرویس‌ها را ری‌استارت کنید)";
            }
            BotApi::send($TOKEN, $chatId, $help, ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }

        case '⏰ کرون':
            if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
            showCronPanel($cfg, $TOKEN, $chatId);
            return;

        case '💾 بکاپ دیتابیس':
            if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
            showBackupPanel($cfg, $store, $TOKEN, $chatId);
            return;

        // ===== ⚙️ تنظیمات: کلیدهای روشن/خاموش + متن تعمیرات + نرخ دلار =====
        case '/settings':
        case '⚙️ تنظیمات':
            if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]); return; }
            showSettingsPanel($store, $TOKEN, $chatId);
            return;
    }

    if ($admin) {
        // دکمهٔ کیبورد «📋 درخواست‌های جدید (N)» — شمارش داخل متن است، پس starts_with
        if (str_starts_with($text, '📋 درخواست‌های جدید')) {
            sendPendingRequests($store, $TOKEN, $chatId);
            return;
        }
        switch ($text) {
            // ===== 📝 ویرایش متن‌های پویای ربات (فقط ادمین) =====
            case '/texts':
            case '📝 متن‌ها': {
                showTextsGroups($store, $TOKEN, $chatId);
                return;
            }

            // ===== 🔍 دیاگنوز سیستم (فقط ادمین) =====
            case '/diagnose':
            case '🔍 دیاگنوز': {
                showDiagnostics($store, $cfg, $TOKEN, $chatId);
                return;
            }
            
            case '📊 آمار':
                $bots = $store->allBots();
                $totalChildUsers = 0;
                $active = 0; $disabled = 0;
                $byType = [];
                $ownerIds = [];
                foreach ($bots as $b) {
                    if (($b['status'] ?? '') === 'active') $active++; else $disabled++;
                    $t = (string)($b['type'] ?? '?');
                    $byType[$t] = ($byType[$t] ?? 0) + 1;
                    $oid = (int)($b['owner_id'] ?? 0);
                    if ($oid > 0) $ownerIds[$oid] = ($ownerIds[$oid] ?? 0) + 1;
                    if (($b['status'] ?? '') !== 'active') continue;
                    // فقط قالب‌هایی که جدول کاربر تلگرامی دارند شمرده می‌شوند
                    if (!botHasUserTable($b)) continue;
                    $pdo = childPdo($cfg, $b);
                    if ($pdo) {
                        $c = childCount($pdo, $b);
                        if ($c > 0) $totalChildUsers += $c;
                        // آزادسازی اتصال قبل از ربات بعدی (جلوگیری از انباشت اتصال همزمان)
                        $pdo = null;
                    }
                }
                try { $pendingReqs = $store->countPendingRequests(); } catch (Throwable $e) { $pendingReqs = 0; }
                try { $payPending = Payments::pendingAdminCount($store); } catch (Throwable $e) { $payPending = 0; }

                $msg = "📊 <b>آمار ربات‌ساز</b>\n" . Ui::sep() . "\n\n"
                    . Ui::kv('👥', 'کاربران ثبت‌شده', number_format($store->countUsers()))
                    . "\n" . Ui::kv('🤖', 'ربات‌ها', number_format(count($bots)))
                        . " — 🟢 فعال: <b>{$active}</b> | 🔴 غیرفعال: <b>{$disabled}</b>"
                    . "\n" . Ui::kv('👤', 'مجموع کاربرانِ ربات‌ها', number_format($totalChildUsers))
                    . "\n" . Ui::kv('⏳', 'درخواست‌های در انتظار', (string)$pendingReqs)
                    . "\n" . Ui::kv('🧾', 'پرداخت‌های در انتظار', (string)$payPending)
                    . "\n\n" . Ui::sep() . "\n";
                if ($byType !== []) {
                    $parts = [];
                    foreach ($byType as $k => $n) {
                        $parts[] = Ui::bullet('🧩', Ui::e(Manager::templateLabel((string)$k)) . ": <b>{$n}</b>");
                    }
                    $msg .= "🧩 <b>بر حسب قالب</b>\n" . implode("\n", $parts) . "\n";
                }
                $msg .= "\n" . Ui::sep() . "\n";
                $msg .= "⚙️ <b>وضعیت کلیدها</b>\n";
                $msg .= Ui::bullet(BuildSettings::maintenanceOn($store) ? '🔧' : '🟢',
                        'حالت تعمیرات: ' . (BuildSettings::maintenanceOn($store) ? '🔴 روشن' : 'خاموش')) . "\n";
                $msg .= Ui::bullet('📝', 'نیاز به تأیید ادمین: ' . (BuildSettings::approvalRequired($store) ? '🟢 روشن' : '🔴 خاموش (ساخت بدون درخواست)')) . "\n";
                $msg .= Ui::bullet('💵', 'نرخ دلار: ' . FxRate::format(FxRate::stored($store)) . ' — ' . Ui::e(FxRate::source($store) ?: 'ثبت‌نشده'));
                if (isSuper($SUPERS, $uid) && $ownerIds !== []) {
                    $msg .= "\n\n" . Ui::sep() . "\n👑 <b>صاحبانِ ربات‌ها</b>\n";
                    foreach ($ownerIds as $oid => $cnt) {
                        $label = $oid === $uid ? 'شما' : Ui::code((string)$oid);
                        $msg .= Ui::bullet('👤', $label . " — <b>{$cnt}</b> ربات") . "\n";
                    }
                }
                BotApi::send($TOKEN, $chatId, Ui::out(rtrim($msg, "\n")));
                return;

            case '📣 همگانی':
                $store->setStep($uid, 'await_broadcast');
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'broadcast_prompt'), ['reply_markup' => Nav::stepKb()]);
                return;

            case '👥 کاربران مجاز':
                showUsersPanel($TOKEN, $chatId);
                return;

            case '📋 همه ربات‌ها':
                // در هندلر پیام (کیبورد ثابت) $msg['message_id'] شناسهٔ پیامِ
                // خودِ کاربر است و ربات نمی‌تواند آن را ویرایش کند (۴۰۰:
                // message can't be edited). مثل بقیهٔ پنل‌ها، پیام تازه
                // ارسال می‌شود؛ ویرایش فقط در هندلر کال‌بک (allbots:refresh)
                // مجاز است چون آن‌جا $msgId پیام خود ربات است.
                showAllBotsPanel($store, $TOKEN, $chatId);
                return;
        }
    }

    BotApi::send($TOKEN, $chatId,
        Texts::get($store, 'invalid', [
            'input' => $text !== ''
                ? htmlspecialchars(mb_substr($text, 0, 80), ENT_QUOTES, 'UTF-8')
                : '—',
        ]),
        ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
}

// ================= steps =================
function handleStep(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, string $text, array $msg, string $step, array $temp): void
{
    $uid = (int)$user['user_id'];
    $admin = isAdmin($user, $SUPERS);

    // دکمه‌های ناوبری همیشه باید کار کنند — حتی وسط یک مرحلهٔ ورودی.
    // ترتیب مهم است: این چک‌ها قبل از switch اصلی هستند تا متن «برگشت» در
    // stepهای همگانی (await_broadcast/await_child_broadcast) به‌اشتباه برای همه ارسال نشود.
    //
    // استثنا: مرحلهٔ «ویرایش متن» عمداً قبل از همه بررسی می‌شود چون ادمین ممکن
    // است متنی دقیقاً شبیه یک دکمه («منو»/«برگشت») بفرستد؛ با پیشوند = ذخیره
    // می‌شود و بدون آن، ناوبری برنده است تا هیچ‌کس وسط کار گیر نکند.
    if ($step === 'await_text_edit') {
        handleTextEditStep($store, $TOKEN, $SUPERS, $user, $chatId, $text, $temp);
        return;
    }

    if (Nav::isMenu($text)) {
        $store->clearStep($uid);
        BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
        return;
    }

    if (Nav::isBack($text)) {
        handleBack($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $step, $temp);
        return;
    }

    if (Nav::isCancel($text) || $text === '/start' || str_starts_with($text, '/start ')) {
        $store->clearStep($uid);
        // انصراف در مرحلهٔ رسید ⇒ فاکتور هم بسته شود؛ وگرنه یک پرداختِ «باز»
        // برای همیشه می‌ماند و یکی از ۵ سهمیهٔ فاکتور باز کاربر را می‌بلعد.
        if ($step === 'await_card_receipt') cancelOpenPayment($store, $uid, $temp);
        BotApi::send($TOKEN, $chatId, "انصراف داده شد. 🏠", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
        return;
    }

    switch ($step) {
    // ===== پرداخت: رسید کارت‌به‌کارت =====
        case 'await_card_receipt': {
            $pid = (int)($temp['payment_id'] ?? 0);
            $p = $pid > 0 ? Payments::getPayment($store, $pid) : null;
            if (!$p || (int)$p['user_id'] !== $uid) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "⛔️ پرداخت یافت نشد. دوباره از فروشگاه لیمیت شروع کنید.",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            if ($p['status'] !== Payments::ST_AWAIT_RECEIPT) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "ℹ️ وضعیت این پرداخت: " . Payments::describe($p),
                    ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
                return;
            }
            // ===== ابتدا file_id پیوست، بعد قضاوت دربارهٔ رسید =====
            // ترتیب مهم است: قبلاً اول «رسید معتبر است؟» پرسیده می‌شد و بعد file_id
            // خوانده می‌شد؛ اگر پیوست file_id نمی‌داشت، رسید قبول می‌شد ولی چیزی
            // برای نمایش ادمین ذخیره نمی‌شد (ادمین «رسید ثبت شد» می‌دید بی‌آنکه فیشی ببیند).
            $receiptFileId = '';
            $receiptKind = '';
            if (!empty($msg['photo'])) {
                $sizes = (array)($msg['photo']);
                $largest = end($sizes); // تلگرام آرایه را از کوچک به بزرگ می‌فرستد
                if (is_array($largest) && !empty($largest['file_id'])) {
                    $receiptFileId = (string)$largest['file_id'];
                    $receiptKind = 'photo';
                }
            } elseif (!empty($msg['document'])) {
                $receiptFileId = (string)($msg['document']['file_id'] ?? '');
                $receiptKind = $receiptFileId !== '' ? 'document' : '';
            }
            // فایلِ بدون file_id یعنی چیزی برای نمایش ادمین نداریم ⇒ مثل متن خام رفتار کن
            $hasAttachment = ($receiptFileId !== '');
            if (!$hasAttachment && !PaymentCard::isValidReceipt($text, false)) {
                $got = mediaLabel($msg);
                $gotTxt = ($got !== 'پیام غیرمتنی') ? "\nدریافت‌شده: <b>" . htmlspecialchars($got, ENT_QUOTES, 'UTF-8') . "</b>" : '';
                BotApi::send($TOKEN, $chatId,
                    "⛔️ رسید نامعتبر است.{$gotTxt}\n"
                    . "چیزی که پذیرفته می‌شود:\n"
                    . "• 📷 عکس فیش (photo)\n"
                    . "• 📎 فایل رسید (PDF یا تصویر)\n"
                    . "• 🔢 شماره پیگیری به‌صورت متن (حداقل ۴ کاراکتر)\n\n"
                    . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                    ['reply_markup' => Nav::stepKb()]);
                return;
            }
            if (!Payments::setReceipt($store, $pid, json_encode([
                'text' => mb_substr(trim((string)$text), 0, 900),
                'has_attachment' => $hasAttachment,
                'file_id' => $receiptFileId,
                'kind' => $receiptKind,
                'user_id' => $uid,
            ], JSON_UNESCAPED_UNICODE))) {
                // پرداخت در این فاصله لغو/رد شده بود؛ رسید نباید آن را احیا کند
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "ℹ️ این پرداخت پیش‌تر بسته شده بود:\n" . Payments::describe($p),
                    ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
                return;
            }
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId,
                "✅ <b>رسید شما ثبت شد.</b>\nادمین در حال بررسی است؛ به‌محض تأیید به شما اطلاع می‌دهیم.",
                ['reply_markup' => PaymentPanel::receiptSentKb($pid)]);
            // ===== ادمین باید باخبر شود =====
            $who = trim((string)($user['first_name'] ?? ''));
            $label = $who !== '' ? $who . " (@" . (string)($user['username'] ?? '-') . ")" : (string)$uid;
            $kindLabel = $p['kind'] === Payments::KIND_TEMPLATE
                ? 'مجوز قالب «' . htmlspecialchars((string)$p['template']) . '»'
                : ((int)$p['slots'] . ' اسلات لیمیت');
            $body = "🧾 <b>رسید جدید</b>\n\n"
                . "کاربر: <code>" . htmlspecialchars($label) . "</code>\n"
                . "مورد: " . $kindLabel . "\n"
                . "مبلغ: <b>" . number_format((int)$p['amount']) . " تومان</b>\n"
                . "شماره پرداخت: <code>#{$pid}</code>\n\n"
                . "برای تأیید/رد: 💳 پرداخت‌ها ← 🧾 بررسی پرداخت‌ها";
            $admins = notifySupers($TOKEN, $SUPERS, $body,
                ['reply_markup' => PaymentPanel::reviewKb($pid)]);
            // ===== خودِ عکس فیش هم باید به ادمین برسد =====
            // بدون این، ادمین فقط متن را می‌دید و هیچ چیزی برای تطبیق نداشت.
            if ($receiptFileId !== '' && $hasAttachment) {
                $photoCaption = "🧾 فیش پرداخت #{$pid}\n" . $kindLabel
                    . "\nمبلغ: " . number_format((int)$p['amount']) . " تومان"
                    . "\nکاربر: " . htmlspecialchars($label);
                $photoSent = 0;
                foreach ($SUPERS as $sid) {
                    $sid = (int)$sid;
                    if ($sid <= 0) continue;
                    try {
                        $r = $receiptKind === 'document'
                            ? BotApi::sendDocumentById($TOKEN, $sid, $receiptFileId, $photoCaption)
                            : BotApi::sendPhoto($TOKEN, $sid, $receiptFileId, $photoCaption);
                        if (!empty($r['ok'])) $photoSent++;
                    } catch (Throwable $e) { /* یک ادمین در دسترس نیست */ }
                }
                if ($photoSent === 0) {
                    Logger::getInstance()->warning('payment', "receipt #{$pid}: attachment not delivered");
                }
            }
            if ($admins === 0) {
                Logger::getInstance()->warning('payment', "receipt #{$pid}: no admin notified");
            }
            return;
        }

        // ===== پرداخت: ورودی‌های ادمین =====
        case 'await_pay_text': {
            if (!$admin) { $store->clearStep($uid); return; }
            $key = (string)($temp['key'] ?? '');
            if (!PaymentGateways::isValidKey($key)) { $store->clearStep($uid); return; }
            if (strtolower(trim($text)) === 'reset') {
                PaymentGateways::resetText($store, $key);
                BotApi::send($TOKEN, $chatId, "✅ متن «" . PaymentGateways::label($key) . "» به پیش‌فرض برگشت.");
                showPaymentsAdmin($store, $TOKEN, $chatId);
                return;
            }
            PaymentGateways::setCustomText($store, $key, $text);
            BotApi::send($TOKEN, $chatId, "✅ متن «" . PaymentGateways::label($key) . "» ذخیره شد.");
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        case 'await_pay_price': {
            if (!$admin) { $store->clearStep($uid); return; }
            $type = (string)($temp['type'] ?? '');
            if (!isset(Manager::validTypes()[$type])) { $store->clearStep($uid); return; }
            // normalizeDigits: «۵۰۰۰۰» و «۵۰٬۰۰۰» هم پذیرفته می‌شوند
            $digits = Payments::digitsOnly($text);
            if ($digits === '' || strlen($digits) > 12) {
                BotApi::send($TOKEN, $chatId, "⛔️ فقط عدد تومان را بفرستید (0 = رایگان).\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            PaymentPricing::setTemplatePrice($store, $type, (int)$digits);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ قیمت قالب «{$type}» = " . PaymentPricing::formatToman((int)$digits));
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        case 'await_pay_limit_price': {
            if (!$admin) { $store->clearStep($uid); return; }
            $digits = Payments::digitsOnly($text);
            if ($digits === '' || strlen($digits) > 12) {
                BotApi::send($TOKEN, $chatId, "⛔️ فقط عدد تومان را بفرستید.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            PaymentPricing::setLimitUnitPrice($store, (int)$digits);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ قیمت هر اسلات = " . PaymentPricing::formatToman((int)$digits));
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        case 'await_pay_usdrate': {
            if (!$admin) { $store->clearStep($uid); return; }
            $digits = Payments::digitsOnly($text);
            // سقف ۱۲ رقم: نرخ غیرواقعی فقط ضرر می‌کند و جلوی اشتباه تایپی را می‌گیرد
            if ($digits === '' || strlen($digits) > 12) {
                BotApi::send($TOKEN, $chatId, "⛔️ <b>فقط عدد را بفرستید.</b>\n\n"
                    . "مثال: ‎<code>100000</code> یعنی هر دلار = ۱۰۰٬۰۰۰ تومان.\n"
                    . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $rate = (float)$digits;
            PaymentPricing::setTomanPerUsd($store, $rate);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId,
                "✅ <b>نرخ دلار دستی ثبت و قفل شد.</b>\n\n"
                . Ui::kv('💵', 'هر دلار', FxRate::format($rate))
                . "\n" . Ui::bullet('🔒', 'حالت: دستی (دکمهٔ «🔄 نرخ خودکار» آن را برمی‌گرداند)')
                . "\n\n💡 اگر می‌خواهید نرخ زندهٔ بازار استفاده شود، «🔄 نرخ خودکار (API)» را بزنید.");
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        case 'await_pay_card': {
            if (!$admin) { $store->clearStep($uid); return; }
            // کارت ایرانی دقیقاً ۱۶ رقم است؛ قبلاً ۱۲ تا ۲۰ رقم پذیرفته می‌شد
            // درحالی‌که پیام می‌گفت «باید ۱۶ رقم باشد».
            $digits = preg_replace('/\D/', '', Payments::normalizeDigits($text));
            if (strlen($digits) !== 16) {
                BotApi::send($TOKEN, $chatId, "⛔️ شماره کارت معتبر نیست (باید دقیقاً ۱۶ رقم باشد).\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            PaymentCard::setCard($store, $digits, PaymentCard::getCardOwner($store));
            $store->setStep($uid, 'await_pay_card_owner');
            BotApi::send($TOKEN, $chatId,
                "👤 <b>نام صاحب کارت</b>\n\nنام و نام خانوادگی صاحب حساب را بفرستید.\n"
                . "برای انصراف (بدون تغییر نام): " . Nav::CANCEL,
                ['reply_markup' => Nav::stepKb()]);
            return;
        }

        case 'await_pay_card_owner': {
            if (!$admin) { $store->clearStep($uid); return; }
            PaymentCard::setCard($store, PaymentCard::getCardNumber($store), $text);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ کارت‌به‌کارت تنظیم شد.");
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        case 'await_pay_nowpay_key': {
            if (!$admin) { $store->clearStep($uid); return; }
            $key = trim($text);
            if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
                BotApi::send($TOKEN, $chatId, "⛔️ کلید نامعتبر است.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $store->setSetting('pay_nowpay_api_key', $key);
            $store->setStep($uid, 'await_pay_nowpay_secret');
            BotApi::send($TOKEN, $chatId,
                "🔐 <b>IPN Secret</b>\n\nکلید IPN Secret نوب‌پیمنت را بفرستید.\n"
                . "آدرس IPN: <code>" . htmlspecialchars(rtrim((string)($cfg['base_url'] ?? ''), '/') . '/nowpayments_ipn.php') . "</code>\n"
                . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                ['reply_markup' => Nav::stepKb()]);
            return;
        }

        case 'await_pay_nowpay_secret': {
            if (!$admin) { $store->clearStep($uid); return; }
            $secret = trim($text);
            // بدون این گارد، یک «انصراف» یا ورودی خالی، کلید را ذخیره می‌کرد و
            // درگاه کریپتو نیمه‌پیکربندیده می‌شد (API هست، تأیید خودکار نیست).
            if ($secret === '' || mb_strlen($secret) < 16 || mb_strlen($secret) > 200) {
                BotApi::send($TOKEN, $chatId,
                    "⛔️ IPN Secret نامعتبر است (باید بین ۱۶ تا ۲۰۰ کاراکتر باشد).\n"
                    . "در پنل NOWPayments از بخش Settings کپی کنید.\n"
                    . "برای بازگشت: " . Nav::BACK,
                    ['reply_markup' => Nav::stepKb()]);
                return;
            }
            PaymentNowPay::setCredentials($store, PaymentNowPay::apiKey($store, $cfg), $secret);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ NOWPayments تنظیم شد.\nتأیید خودکار کریپتو فعال شد.");
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        case 'await_pay_setlimit': {
            if (!$admin) { $store->clearStep($uid); return; }
            // لنگردار و روی ارقام نرمال‌شده: قبلاً «abc 123 junk 456» هم می‌خواند
            // و «−۱» با علامت منفی فارسی رد می‌شد.
            $norm = ' ' . trim(Payments::normalizeDigits($text)) . ' ';
            if (!preg_match('/^\s*(-?\d+)\s+(-?\d+)\s*$/u', $norm, $m)) {
                BotApi::send($TOKEN, $chatId, "⛔️ قالب اشتباه است. مثال: <code>1234567 3</code>\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $target = (int)$m[1];
            $limit = (int)$m[2];
            if ($target <= 0) {
                BotApi::send($TOKEN, $chatId, "⛔️ آیدی کاربر نامعتبر است.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            if ($limit < -1 || $limit > 100000) {
                BotApi::send($TOKEN, $chatId, "⛔️ لیمیت باید -۱ (نامحدود)، ۰ (مسدود) یا عدد مثبت تا ۱۰۰۰۰۰ باشد.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            Payments::setUserLimit($store, $target, $limit);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ لیمیت کاربر <code>{$target}</code> = " . PaymentLimits::formatLimit($limit));
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        // ===== درگاه زرین‌پال: کد پذیرنده =====
        case 'await_pay_zarin_merchant': {
            if (!$admin) { $store->clearStep($uid); return; }
            $mid = trim($text);
            // کد پذیرندهٔ زرین‌پال یک UUID است؛ حداقل طول ۲۰ جلوی «ی» یا «reset» را می‌گیرد
            if (mb_strlen($mid) < 20 || mb_strlen($mid) > 50) {
                BotApi::send($TOKEN, $chatId,
                    "⛔️ کد پذیرنده نامعتبر است.\n"
                    . "در پنل زرین‌پال بخش «درگاه‌ها» کد ۳۶ کاراکتری را کپی کنید.\n\n"
                    . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                    ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $sandbox = PaymentZarin::isSandbox($store);
            PaymentZarin::setCredentials($store, $mid, $sandbox);
            $cb = rtrim((string)($cfg['base_url'] ?? ''), '/') . PaymentZarin::callbackPath();
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId,
                "✅ <b>درگاه زرین‌پال ثبت شد.</b>\n\n"
                . Ui::kv('🟣', 'کد پذیرنده', mb_substr($mid, 0, 8) . '…' . mb_substr($mid, -4), true)
                . "\n" . Ui::kv('🌐', 'حالت', $sandbox ? '🧪 تست (sandbox)' : 'واقعی')
                . "\n\n⚠️ این آدرس را در پنل زرین‌پال به‌عنوان «آدرس بازگشت» ثبت کنید:\n"
                . "   " . Ui::link($cb) . "\n"
                . "   " . Ui::code($cb) . "\n\n"
                . "برای فعال‌شدن، درگاه زرین‌پال را از پنل پرداخت‌ها روشن کنید. 🟢");
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        // ===== درگاه آقای پرداخت: کد پین =====
        case 'await_pay_aqaye_pin': {
            if (!$admin) { $store->clearStep($uid); return; }
            $pin = trim($text);
            if (mb_strlen($pin) < 6 || mb_strlen($pin) > 80) {
                BotApi::send($TOKEN, $chatId,
                    "⛔️ کد پین نامعتبر است.\n"
                    . "کد پین درگاه را از پنل آقای پرداخت کپی کنید.\n\n"
                    . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                    ['reply_markup' => Nav::stepKb()]);
                return;
            }
            PaymentAqaye::setPin($store, $pin);
            $cb = rtrim((string)($cfg['base_url'] ?? ''), '/') . PaymentAqaye::callbackPath();
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId,
                "✅ <b>درگاه آقای پرداخت ثبت شد.</b>\n\n"
                . "⚠️ این آدرس باید با دامنهٔ تأییدشدهٔ درگاه شما یکی باشد:\n"
                . "   " . Ui::link($cb) . "\n"
                . "   " . Ui::code($cb) . "\n\n"
                . "برای فعال‌شدن، درگاه را از پنل پرداخت‌ها روشن کنید. 🟢");
            showPaymentsAdmin($store, $TOKEN, $chatId);
            return;
        }

        // ===== ⚙️ متن پیام تعمیرات =====
        case 'await_maintenance_text': {
            if (!$admin) { $store->clearStep($uid); return; }
            if (strtolower(trim($text)) === 'reset') {
                Texts::reset($store, 'maintenance');
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "✅ متن تعمیرات به حالت پیش‌فرض برگشت.\n\n" . Ui::quote(Texts::defaultText('maintenance')));
                showSettingsPanel($store, $TOKEN, $chatId);
                return;
            }
            if (trim($text) === '') {
                BotApi::send($TOKEN, $chatId, "⛔️ متن خالی است. یا متن تازه بفرستید، یا کلمهٔ <code>reset</code> را برای برگشت به پیش‌فرض.\nبرای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $unknown = Texts::unknownVars('maintenance', $text);
            if ($unknown !== []) {
                BotApi::send($TOKEN, $chatId, "⚠️ این جای‌نگهدارها در متن تعمیرات شناخته نمی‌شوند و موقع نمایش حذف می‌شوند:\n"
                    . Ui::code(implode(' ', $unknown)) . "\n\nدوباره بفرستید یا برای انصراف " . Nav::CANCEL . " را بزنید.", ['reply_markup' => Nav::stepKb()]);
                return;
            }
            Texts::set($store, 'maintenance', $text);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ متن پیام تعمیرات ذخیره شد.\n\n" . Ui::quote(Texts::get($store, 'maintenance', ['eta' => BuildSettings::maintenanceEta($store) ?: 'به‌زودی'])));
            showSettingsPanel($store, $TOKEN, $chatId);
            return;
        }

        // ===== ⏳ زمان تقریبی بازگشت =====
        case 'await_maintenance_eta': {
            if (!$admin) { $store->clearStep($uid); return; }
            $eta = trim($text);
            if (strtolower($eta) === 'reset' || $eta === '0') $eta = '';
            BuildSettings::setMaintenanceEta($store, $eta);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, $eta === ''
                ? "✅ زمان تقریبی پاک شد؛ در متن تعمیرات «به‌زودی» نمایش داده می‌شود."
                : "✅ زمان تقریبی بازگشت = <b>" . Ui::e($eta) . "</b>");
            showSettingsPanel($store, $TOKEN, $chatId);
            return;
        }

        // ===== ✏️ ویرایش توکنِ یک رباتِ ساخته‌شده =====
        case 'await_edit_bot_token': {
            $botId = (int)($temp['bot_id'] ?? 0);
            $bot = $botId > 0 ? $store->botById($botId) : null;
            if (!$bot || !botEditableBy($bot, $user, $SUPERS)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "⛔️ این ربات در دسترس شما نیست.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            $token = trim($text);
            if (!preg_match('/^\d+:[\w\-]{20,}$/', $token)) {
                BotApi::send($TOKEN, $chatId, "⛔️ <b>فرمت توکن اشتباه است.</b>\n\n"
                    . "توکن باید شبیه ‎<code>1234567890:AAH…</code> باشد.\n"
                    . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $me = BotApi::getMe($token);
            if (empty($me['ok'])) {
                BotApi::send($TOKEN, $chatId, "⛔️ <b>توکن نامعتبر است.</b>\n\n"
                    . "تلگرام این توکن را نپذیرفت: " . Ui::code(mb_substr((string)($me['description'] ?? 'پاسخی نرسید'), 0, 120)) . "\n\n"
                    . "توکن را دوباره بررسی کنید. اگر ربات را از @BotFather حذف کرده‌اید،\n"
                    . "باید ربات تازه‌ای بسازید و توکنِ همان را بفرستید.\n\n"
                    . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            try {
                applyBotTokenChange($cfg, $store, $TOKEN, $bot, $token, (string)($me['result']['username'] ?? ''), (int)($me['result']['id'] ?? 0));
            } catch (Throwable $e) {
                Logger::getInstance()->error('botedit', "token change failed for #{$botId}: " . $e->getMessage());
                BotApi::send($TOKEN, $chatId, "⚠️ <b>توکن معتبر است ولی اعمال نشد:</b>\n" . Ui::quote(Ui::e(Manager::sanitizeDbError($e->getMessage())))
                    . "\n\nهیچ تغییری ذخیره نشد. برای برگشت: " . Nav::BACK, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $store->clearStep($uid);
            $fresh = $store->botById($botId) ?: $bot;
            BotApi::send($TOKEN, $chatId,
                "✅ <b>توکن با موفقیت عوض شد!</b>\n\n"
                . Ui::kv('🤖', 'ربات جدید', '@' . (string)($fresh['bot_username'] ?? '-'), true) . "\n"
                . "🔄 وبهوک هم روی توکن تازه ست شد.\n\n"
                . "⚠️ ربات قبلی دیگر به این ربات‌ساز وصل نیست.",
                ['reply_markup' => Nav::botPanelKb($fresh, $admin)]);
            return;
        }

        // ===== ✏️ ویرایش آیدی ادمینِ یک رباتِ ساخته‌شده =====
        case 'await_edit_admin_id': {
            $botId = (int)($temp['bot_id'] ?? 0);
            $bot = $botId > 0 ? $store->botById($botId) : null;
            if (!$bot || !botEditableBy($bot, $user, $SUPERS)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "⛔️ این ربات در دسترس شما نیست.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            $newAdmin = Payments::parseIntLoose(trim($text));
            if ($newAdmin === null || $newAdmin <= 0) {
                BotApi::send($TOKEN, $chatId, "⛔️ <b>آیدی عددی معتبر بفرستید.</b>\n\n"
                    . "مثال: ‎<code>123456789</code> — از ‎<code>@userinfobot</code> بگیرید.\n"
                    . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            if ($newAdmin === (int)($bot['admin_id'] ?? 0)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "ℹ️ همین آیدی از قبل ثبت شده بود؛ تغییری لازم نیست.",
                    ['reply_markup' => Nav::botPanelKb($bot, $admin)]);
                return;
            }
            try {
                applyBotAdminChange($cfg, $store, $bot, $newAdmin);
            } catch (Throwable $e) {
                Logger::getInstance()->error('botedit', "admin change failed for #{$botId}: " . $e->getMessage());
                BotApi::send($TOKEN, $chatId, "⚠️ <b>آیدی معتبر است ولی اعمال نشد:</b>\n" . Ui::quote(Ui::e(Manager::sanitizeDbError($e->getMessage())))
                    . "\n\nهیچ تغییری ذخیره نشد. برای برگشت: " . Nav::BACK, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $store->clearStep($uid);
            $fresh = $store->botById($botId) ?: $bot;
            BotApi::send($TOKEN, $chatId,
                "✅ <b>آیدی ادمین عوض شد!</b>\n\n"
                . Ui::kv('🆔', 'آیدی قبلی', (string)(int)($bot['admin_id'] ?? 0), true)
                . "\n" . Ui::kv('🆕', 'آیدی جدید', (string)$newAdmin, true)
                . "\n" . Ui::kv('📁', 'فایل کانفیگ', 'bots/' . $fresh['folder'] . '/config.php', true),
                ['reply_markup' => Nav::botPanelKb($fresh, $admin)]);
            return;
        }
    case 'await_child_owner': {
            if (!$admin) { $store->clearStep($uid); return; }
            $name = trim($text);
            if ($name === '') {
                BotApi::send($TOKEN, $chatId, "⛔️ نام کاربری خالی است.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            if (mb_strtolower($name) === 'auto') {
                $store->setSetting('child_owner_username', '');
                $store->setSetting('child_owner_password', '');
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId,
                    "✅ <b>به حالت خودکار برگشت.</b>\n\n"
                    . "از این به بعد هر ربات، هنگام نصب یک اکانت تصادفیِ امن می‌گیرد\n"
                    . "و نام کاربری + رمزش یک‌بار در همین گفتگو به شما نشان داده می‌شود.",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            if (!preg_match('/^[A-Za-z0-9_.@\-]{3,64}$/', $name)) {
                BotApi::send($TOKEN, $chatId, "⛔️ نام کاربری معتبر نیست.\nفقط حروف انگلیسی، عدد و <code>_ . - @</code> (۳ تا ۶۴ کاراکتر).\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $store->setSetting('child_owner_username', $name);
            // رمز را خودمان می‌سازیم تا کاربر مجبور نباشد یکی را از پیش داشته باشد
            $store->setSetting('child_owner_password', Manager::randomToken(16));
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId,
                "✅ <b>اکانت سازندهٔ پنل ثبت شد.</b>\n\n"
                . Ui::kv('👤', 'نام کاربری', Ui::code($name), true)
                . "\n" . Ui::kv('🔒', 'رمز عبور', Ui::code((string)$store->getSetting('child_owner_password', '')), true)
                . "\n\nℹ️ این‌ها در هر رباتِ تازه‌ساخته نوشته می‌شود.\n"
                . "⚠️ ربات‌هایی که قبلاً ساخته شده‌اند تغییر نمی‌کنند.",
                ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }

        case 'await_backup_times': {
            if (!$admin) { $store->clearStep($uid); return; }
            $parsed = DbBackup::parseTimes($text);
            if (!$parsed['ok']) {
                BotApi::send($TOKEN, $chatId, "⛔️ " . $parsed['error'] . "\nدوباره بفرست (مثلاً: <code>3,15</code>).\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $cur = DbBackup::settings($cfg, $store);
            DbBackup::saveSettings($store, $cur['enabled'], $parsed['times']);
            $store->clearStep($uid);
            Logger::getInstance()->info('backup', "Admin {$uid} set backup times: " . implode(',', $parsed['times']));
            showBackupPanel($cfg, $store, $TOKEN, $chatId);
            return;
        }

        case 'await_bot_token': {
            $token = trim($text);
            if (!preg_match('/^\d+:[\w\-]{20,}$/', $token)) {
                BotApi::send($TOKEN, $chatId, "⛔️ فرمت توکن اشتباه است.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $me = BotApi::getMe($token);
            if (empty($me['ok'])) {
                BotApi::send($TOKEN, $chatId, "⛔️ توکن نامعتبر است.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            // ===== رمزنگاری توکن =====
            $secretKey = $GLOBALS['secretKey'] ?? Manager::DEFAULT_SECRET_KEY;
            $encToken = encryptToken($token, $secretKey);
            $store->setStep($uid, 'await_admin_id', [
                'token' => $encToken,
                'bot_username' => $me['result']['username'] ?? '',
                'bot_id' => $me['result']['id'] ?? 0,
            ]);
            BotApi::send($TOKEN, $chatId, Texts::get($store, 'token_ok', ['username' => (string)($me['result']['username'] ?? '?')]), ['reply_markup' => Nav::stepKb()]);
            return;
        }

        case 'await_admin_id': {
            if (!preg_match('/^\d{5,}$/', $text)) {
                BotApi::send($TOKEN, $chatId, "⛔️ آیدی عددی بفرست.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $store->setStep($uid, 'await_folder', ['admin_id' => (int)$text]);
            BotApi::send($TOKEN, $chatId, Texts::get($store, 'folder_prompt'), ['reply_markup' => Nav::stepKb()]);
            return;
        }

        case 'await_folder': {
            // گیتِ حالت تعمیرات، اینجا هم لازم است: ممکن است ادمین کلید را
            // وقتی روزنامه‌ای کاربر در میانهٔ ساخت است روشن کند.
            if (BuildSettings::maintenanceOn($store)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, BuildSettings::maintenanceNotice($store),
                    ['reply_markup' => BotApi::kb([
                        [['text' => '📦 ربات‌های من']],
                        [['text' => 'ℹ️ راهنما']],
                    ])]);
                return;
            }
            if ($text === '') {
                BotApi::send($TOKEN, $chatId, "⛔️ لطفاً یک نام انگلیسی بفرستید.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            // فقط نامی که حداقل یک حرف/عدد لاتین داشته باشد قبول می‌شود.
            // قبلاً slugify هر متنی (فارسی/ایموجی/دستور ادمین مثل «⏰ کرون») را بی‌صدا
            // به نام تصادفی مثل bot-a1b2c3 تبدیل می‌کرد و ساخت همان لحظه شروع می‌شد؛
            // کاربر هیچ شانسی برای اعتراض به نام نداشت.
            if (!preg_match('/[A-Za-z0-9]/', $text)) {
                BotApi::send($TOKEN, $chatId, "⛔️ لطفاً یک نام انگلیسی بفرستید (حروف و اعداد لاتین).\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $slug = Manager::slugify($text);
            if ($store->botByFolder($slug) || is_dir(Manager::childBotsDir() . '/' . $slug)) {
                BotApi::send($TOKEN, $chatId, "⛔️ این نام قبلا استفاده شده.\nنام دیگری بفرست.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            // نام‌های رزرو: bots/backups (ریشهٔ بکاپ‌ها) و bots/states —
            // ساخت ربات با این نام‌ها هم با بکاپ تداخل می‌کند و هم با قواعد مسدودسازی
            // .htaccess (states/ و backups/) وبهوکش 403 می‌شود.
            if (in_array($slug, ['backups', 'states'], true)) {
                BotApi::send($TOKEN, $chatId, "⛔️ این نام رزرو شده است؛ نام دیگری بفرست.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            // ===== نوع قالب را قبل از پیش‌نیازها تعیین کن =====
            // اینجا پایین‌تر بود و بعد از checkBuildPrerequisites() خوانده
            // می‌شد؛ ولی همان چک باید بداند کدام قالب دارد ساخته می‌شود تا
            // vendor همان قالب را بسنجد، نه هر دو را با هم.
            $type = (string)($temp['type'] ?? 'faxima');
            if (Manager::templateSpec($type) === null) {
                // رجیستری، نه یک آرایهٔ ثابت: قالب تازه نباید اینجا جا بیفتد.
                // fallback به فاکسیما فقط برای temp خراب/قدیمی است.
                Logger::getInstance()->warning('build', "unknown template type in step for uid {$uid}: {$type}");
                $type = 'faxima';
            }
            // ===== گیت پرداخت دوباره (دفاعی) =====
            // ممکن است بین انتخاب نوع و اینجا سقف کاربر پر شده باشد یا ادمین
            // قالب را پولی کند؛ اگر الان پرداخت لازم است، ساخت متوقف می‌شود.
            if (!isAdmin($user, $SUPERS) && gateBuildPayment($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $type)) {
                $store->clearStep($uid);
                return;
            }
            BotApi::send($TOKEN, $chatId, Texts::get($store, 'build_running', ['slug' => $slug]));
            // ===== بررسی پیش‌نیازها قبل از ساخت =====
            $_prereq_err = Manager::checkBuildPrerequisites($type);
            if ($_prereq_err !== '') {
                Logger::getInstance()->error('build', "Prerequisites failed for {$slug} ({$type}): {$_prereq_err}");
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'build_failed', ['error' => $_prereq_err]), ['reply_markup' => Nav::stepKb()]);
                return;
            }
            try {
                // ===== پردازش غیرهمزمان =====
                @set_time_limit(0);
                @ignore_user_abort(true);

                $result = buildBot($cfg, $store, $TOKEN, $uid, $type, $temp, $slug);
                $store->clearStep($uid);
                // ===== ووچر قالب مصرف شود =====
                // فقط وقتی قالب واقعاً پولی است. قبلاً مصرف بی‌قیدوشرط بود و اگر
                // ادمین بین خرید و ساخت قالب را رایگان می‌کرد، ووچر کاربر بی‌دلیل می‌سوخت.
                if (PaymentPricing::isPaid($store, $type)) {
                    try { Payments::consumeTemplateVoucher($store, $uid, $type); } catch (Throwable $ve) {
                        Logger::getInstance()->warning('payment', "consume voucher failed (uid {$uid}, {$type}): " . $ve->getMessage());
                    }
                }
                $doneMsg = $result['custom_message']
                    ?? Texts::get($store, 'build_done', [
                        'username' => (string)($result['bot_username'] ?? ''),
                        'slug'     => $slug,
                        'db'       => (string)($result['db'] ?? ''),
                    ]);
                // اگر قالب اکانت owner را خودش ساخته، یک‌بار نشانش می‌دهیم:
                // «پنل نمایندگی» با همین اکانت کار می‌کند و رمزش جای دیگری نیست.
                if (!empty($result['owner_cred']['generated'])) {
                    $oc = $result['owner_cred'];
                    $doneMsg .= "\n\n" . Ui::sep()
                        . "\n🔑 <b>اکانت سازندهٔ پنل (خودکار ساخته شد)</b>"
                        . "\n" . Ui::kv('👤', 'نام کاربری', $oc['username'], true)
                        . "\n" . Ui::kv('🔒', 'رمز عبور', $oc['password'], true)
                        . "\n\nℹ️ این اکانت برای فروش «پنل نمایندگی» لازم است و در تنظیمات هم ذخیره شد.";
                }
                BotApi::send($TOKEN, $chatId, $doneMsg,
                    ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
            } catch (Throwable $e) {
                // Throwable: خطاهای Error/TypeError هم باید به کاربر پیام بدهند نه اینکه
                // استثناي uncaught ⇒ 500 ⇒ حلقهٔ retry تلگرام شوند.
                Logger::getInstance()->error('build', "Build failed ({$slug}): " . $e->getMessage());
                // مرحله عمداً باقی می‌ماند تا کاربر بتواند همان‌جا نام دیگری بفرستد
                // («دوباره تلاش کن» یعنی همین). انصراف با ❌ انصراف / 🏠 منو.
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'build_failed', [
                    'error' => htmlspecialchars(Manager::sanitizeDbError($e->getMessage()), ENT_QUOTES, 'UTF-8'),
                ]), ['reply_markup' => Nav::stepKb()]);
            }
            return;
        }

        case 'await_broadcast': {
            if (!$admin) { $store->clearStep($uid); return; }
            $ids = $store->allUserIds();
            if (empty($ids)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "⛔️ هیچ کاربری برای ارسال ثبت نشده است (تعداد: 0).",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            if (!isset($msg['message_id']) || !isset($msg['chat']['id'])) {
                // بدون این گارد، copyMessage با index خالی فراخوانی می‌شد و کل ارسال بی‌صدا می‌مرد
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "⛔️ پیام مبدأ برای ارسال یافت نشد (message_id نامعتبر)؛ دوباره بفرست.",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            @set_time_limit(0);
            @ignore_user_abort(true);
            // بدون پاسخ زودهنگام، تلگرام بعد از ~۶۰ ثانیه timeout می‌کند و همان آپدیت را
            // دوباره می‌فرستد ⇒ همگانی چندبار ارسال می‌شد.
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

            $ok = 0;
            $total = count($ids);
            foreach (array_chunk($ids, 10) as $chunk) {
                foreach ($chunk as $id) {
                    $r = BotApi::call($TOKEN, 'copyMessage', ['chat_id' => $id, 'from_chat_id' => $msg['chat']['id'], 'message_id' => $msg['message_id']]);
                    if (!empty($r['ok'])) $ok++;
                    usleep(50000);
                }
            }
            $store->clearStep($uid);
            $skipped = $total - $ok;
            BotApi::send($TOKEN, $chatId,
                Texts::get($store, 'broadcast_done', [
                    'ok'    => $ok,
                    'total' => $total,
                    'skipped_note' => $skipped > 0
                        ? "\n⚠️ {$skipped} نفر دریافت نکردند (ربات را بلاک کرده‌اند یا آیدیشان نامعتبر است)."
                        : '',
                ]),
                ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }

        case 'await_user_add': {
            if (!preg_match('/^\d{5,}$/', $text)) { BotApi::send($TOKEN, $chatId, "آیدی عددی بفرست:\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]); return; }
            $currentUser = $store->user((int)$text);
            $currentIsAdmin = (int)$currentUser['is_admin'];
            $store->setAllowed((int)$text, 1, $currentIsAdmin);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ کاربر <code>{$text}</code> مجاز شد.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }

        case 'await_user_remove': {
            if (!preg_match('/^\d{5,}$/', $text)) { BotApi::send($TOKEN, $chatId, "آیدی عددی بفرست:\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]); return; }
            // is_admin هم صفر می‌شود؛ وگرنه حذف دسترسیِ یک ادمین بی‌اثر می‌ماند
            // (canUse تا زمانی که is_admin=1 باشد true برمی‌گرداند)
            $store->setAllowed((int)$text, 0, 0);
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ دسترسی کاربر <code>{$text}</code> حذف شد.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }

        case 'await_child_broadcast': {
            $botId = (int)($temp['bot_id'] ?? 0);
            $bot = $store->botById($botId);
            if (!$bot || ((int)$bot['owner_id'] !== $uid && !$admin)) { $store->clearStep($uid); return; }
            // قالب‌هایی مثل پاسارگاد اصلاً جدول کاربر تلگرامی ندارند؛
            // بدون این گارد کاربر «⛔️ کاربری یافت نشد» می‌گرفت و فکر می‌کرد خراب است.
            if (!botHasUserTable($bot)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "ℹ️ قالب «" . Manager::templateLabel((string)($bot['type'] ?? '')) . "» فهرست کاربر تلگرامی ندارد؛ پیام همگانی برایش ممکن نیست.");
                return;
            }
            $pdo = childPdo($cfg, $bot);
            if (!$pdo) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId,
                    "⛔️ اتصال به دیتابیس ربات «{$bot['folder']}» ناموفق بود.\n"
                    . "علت: DSN/فایل ساخته نشده یا خواندنی نیست؛ از «🔍 دیاگنوز» وضعیت پوشه را ببین.",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            $ids = childUsers($pdo, $bot);
            if (empty($ids)) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "⛔️ هیچ کاربری در جدول کاربران این ربات یافت نشد (تعداد: 0).",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            $tok = trim((string)childToken($bot));
            if ($tok === '') {
                // بدون این گارد، کل حلقه بی‌اثر اجرا می‌شد و فقط گزارش ۰/N می‌داد
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId,
                    "⛔️ توکن ربات «{$bot['folder']}» ذخیره نشده است؛ امکان ارسال وجود ندارد.\n"
                    . "ربات را دوباره با «🔗 ست‌کردن مجدد وبهوک» راه‌اندازی کن.",
                    ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            $ok = 0;
            $total = count($ids);
            @set_time_limit(0);
            @ignore_user_abort(true);
            // ===== زودهنگام پاسخ HTTP =====
            // بدون این، تلگرام بعد از ~۶۰ ثانیه وبهوک را timeout می‌کند و همان
            // آپدیت را دوباره می‌فرستد ⇒ همگانی دوبار ارسال می‌شد (یا حلقهٔ تکرار).
            // پاسخ ۲۰۰ زود می‌رود و markUpdateProcessed بعد از پایان حلقه اجرا می‌شود.
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            foreach (array_chunk($ids, 10) as $chunk) {
                foreach ($chunk as $id) {
                    $r = BotApi::call($tok, 'copyMessage', ['chat_id' => $id, 'from_chat_id' => $msg['chat']['id'], 'message_id' => $msg['message_id']]);
                    if (!empty($r['ok'])) $ok++;
                    usleep(50000);
                }
            }
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, Texts::get($store, 'broadcast_child_done', [
                'folder' => (string)($bot['folder'] ?? ''),
                'ok'     => $ok,
                'total'  => $total,
            ]), ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }
    }

    // ناشناخته/کهنه: مرحله باید ریست شود تا کاربر برای همیشه گیر نکند، ولی علت
    // باید دقیق گفته شود (کدام مرحله؟) نه فقط «نامشخص».
    Logger::getInstance()->warning('step', "unknown step '{$step}' for uid {$uid} — reset to idle");
    $store->clearStep($uid);
    BotApi::send($TOKEN, $chatId,
        "⚠️ مرحلهٔ نامعتبر یا منقضی: <code>" . htmlspecialchars($step, ENT_QUOTES, 'UTF-8') . "</code> ("
        . htmlspecialchars(stepLabel($step), ENT_QUOTES, 'UTF-8') . ")\n"
        . "مرحله ریست شد؛ از منو دوباره شروع کنید.",
        ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
}

// ================= build bot =================
// ساخت ربات فرزند کاملاً از روی رجیستری Manager::templates() انجام می‌شود.
// قبلاً این تابع با if ($type === 'mirza') و «else = فاکسیما» نوشته شده بود؛
// یعنی هر قالب تازه یعنی کپی‌کردن ۴۰ خط و خطر فراموشی یکی از مراحل نصب.
// الان تفاوت‌ها در همان رجیستری تعریف شده‌اند و این تابع برای همه یکسان است.
function buildBot(array $cfg, Store $store, string $TOKEN, int $owner, string $type, array $temp, string $slug): array
{
    $spec = Manager::templateSpec($type);
    if ($spec === null) throw new Exception("نوع ربات نامعتبر است");

    // پیش‌بررسی نسخهٔ PHP — قبل از mkdir/دیتابیس تا هیچ منبعی ساخته نشود.
    // بدون این، ربات «موفقیت‌آمیز» ساخته می‌شود ولی صفحه‌هایش 500 می‌دهند.
    Manager::assertTemplatePhpCompatible($type);

    $tplDir = Manager::templateDir($type);
    $botDir = Manager::childBotsDir() . '/' . $slug;
    $sqlite = Manager::templateDb($type) === 'sqlite';

    // توکن در مرحله قبل رمزنگاری‌شده ذخیره شده؛ برای استفاده واقعی رمزگشایی کن
    $plainToken = childToken(['token' => $temp['token'] ?? '']);
    if (!preg_match('/^\d+:[\w\-]{20,}$/', $plainToken)) throw new Exception("توکن نامعتبر است؛ از اول شروع کن.");

    $dbCreated = false;
    $dbName = '';
    $dirCreated = false;
    $webhookSet = false;
    $webhookNote = '';
    $tableOk = true;
    $notes = [];

    // ===== ادعای اتمیک پوشه =====
    // mkdir در صورت وجود از قبل شکست می‌خورد؛ بنابراین دو ساخت همزمان با یک نام،
    // هرگز روی یک پوشه کار نمی‌کنند. قبلاً usleep+is_dir بود که مسابقه را فقط «کم‌احتمال» می‌کرد
    // و rollbackِ ساختِ دومی پوشهٔ اول را پاک می‌کرد.
    if (!@mkdir($botDir, 0755, true)) {
        if (file_exists($botDir)) throw new Exception("⛔️ این نام قبلاً استفاده شده.");
        // Detailed diagnostic: show path, parent perms, owner
        $_parent = dirname($botDir);
        $_uid = @fileowner($_parent);
        $_perm = @stat($_parent) ? substr(sprintf('%o', @fileperms($_parent)), -4) : '?';
        // اسم کاربری فقط اگر posix موجود باشد؛ بدون آن همان uid نمایش داده می‌شود
        $_owner = '?';
        if ($_uid !== false) {
            $_owner = (string)$_uid;
            if (function_exists('posix_getpwuid')) {
                $pw = posix_getpwuid($_uid);
                if (is_array($pw) && !empty($pw['name'])) $_owner = (string)$pw['name'];
            }
        }
        $_owner_uid = $_uid === false ? '?' : (string)$_uid;
        throw new Exception("ساخت پوشه «{$slug}» ممکن نشد ❌\n"
            ."مسار: {$botDir}\n"
            ."پوشه والد: {$_parent}\n"
            ."پرمیشن: {$_perm} (owner: {$_owner_uid}:{$_owner})\n"
            ."علت احتمالی: پوشه bots/ مال www-data نیست\n"
            ."حل: bash tools/install.sh یا chown www-data:www-data bots/");
    }
    $dirCreated = true;

    try {
        // ===== ۱) کپی سورس قالب =====
        // exclude از رجیستری می‌آید (Manager::copyExcludes) و SourceUpdate
        // (templateFiles) هم همان را می‌خواند تا «فهرستِ کپی‌شده در نصب» با
        // «فهرستی که ماژول سورس می‌بیند» یکی باشد؛ دو فهرستِ جدا یعنی فایلی
        // که موقع ساخت کپی نشد ولی در دیاگنوز «در انتظار» دیده می‌شود.
        // نکتهٔ مهم اینکه پوشهٔ کاربری/داده هم نباید کپی شود (یک SQLite خالی که
        // بعداً فایل واقعی را بازنویسی می‌کند، یا یک .sqlite ناقص).
        $exclude = Manager::copyExcludes($type);
        Manager::copyDir($tplDir, $botDir, $exclude);

        // ===== ۲) دیتابیس =====
        $parts = parse_url(rtrim($cfg['base_url'] ?? '', '/'));
        $domainPath = ($parts['host'] ?? '') . ($parts['path'] ?? '') . '/bots/' . $slug;
        $baseUrl = rtrim($cfg['base_url'] ?? '', '/') . '/bots/' . $slug;
        $adminId = (int)($temp['admin_id'] ?? $owner);
        $botUsername = (string)($temp['bot_username'] ?? '');

        if ($sqlite) {
            // قالب SQLite دیتابیس جدا نمی‌خواهد؛ فایلش داخل data/ ربات خودش ساخته می‌شود.
            $dbName = '';
        } else {
            [$dbName, $tablePrefix] = Manager::createDatabase($cfg, $slug, null);
            $dbCreated = true;
        }

        // ===== ۳) پچ config.php =====
        $staticSecret = '';
        $ownerCred = [];   // اگر قالب پاسارگاد اکانت owner را خودش ساخت
        switch ($type) {
            case 'faxima':
                Manager::patchFaximaConfig($botDir, $cfg, $dbName, $plainToken, $adminId, $botUsername, $domainPath);
                break;
            case 'mirza':
                Manager::patchMirzaConfig($botDir, $cfg, $dbName, $plainToken, $adminId, $botUsername, $domainPath);
                break;
            case 'uptime':
                Manager::patchUptimeConfig($botDir, $cfg, $dbName, $plainToken, $adminId, $botUsername, $domainPath, $baseUrl);
                break;
            case 'pasargad':
                $res = Manager::writePasargadConfig(
                    $botDir,
                    $plainToken,
                    $adminId,
                    $botUsername,
                    $baseUrl,
                    (string)($store->getSetting('child_owner_username', '') ?? ''),
                    (string)($store->getSetting('child_owner_password', '') ?? '')
                );
                $staticSecret = $res['secret'];
                if (!empty($res['owner_generated'])) {
                    // ادمین اکانتی نداده ⇒ یک اکانت امن ساخته شد. هم ذخیره‌اش
                    // می‌کنیم (برای ربات‌های بعدی) و هم به ادمین می‌گوییم، چون
                    // بدون این اطلاع، «پنل نمایندگی» با اکانتی ساخته می‌شود
                    // که خودش رمزش را نمی‌داند.
                    $store->setSetting('child_owner_username', $res['owner_username']);
                    $store->setSetting('child_owner_password', $res['owner_password']);
                    $ownerCred = [
                        'generated' => true,
                        'username' => $res['owner_username'],
                        'password' => $res['owner_password'],
                    ];
                }
                break;
            default:
                throw new Exception("نصب قالب «{$type}» پیاده‌سازی نشده است");
        }

        // ===== ۴) نصب جدول‌ها =====
        // روش از رجیستری خوانده می‌شود:
        //   table.php ⇒ فراخوانی HTTP (فاکسیما/میرزا/آپ‌تایم)
        //   migrate   ⇒ مایگریشن درون‌فرایندی (پاسارگاد، چون SQLite است و table.php ندارد)
        $tableFile = (string)($spec['table'] ?? '');
        $schema = Manager::templateSchema($type);
        if ($schema === 'http' && $tableFile !== '') {
            $tableOk = Manager::triggerTable(
                $baseUrl . '/' . $tableFile,
                Manager::tableSecret($type, $plainToken)
            );
        } elseif ($schema === 'migrate') {
            $inst = Manager::installTemplateSchema($type, $botDir);
            if (!$inst['migrated']) {
                throw new Exception("نصب دیتابیس قالب ناموفق بود: " . $inst['note']);
            }
            if ($inst['note'] !== '') $notes[] = $inst['note'];
        } elseif ($schema !== 'none') {
            throw new Exception("روش نصب جدول برای قالب «{$type}» ناشناخته است: {$schema}");
        }

        // ===== ۵) پاکسازی فایل‌های اضافی (بدون دست‌زدن به vendor/) =====
        // فقط «cleanup» — نه exclude. این دو کلید فرق دارند: exclude یعنی «کپی نشود»
        // (که خودِ copyDir رعایت می‌کند) و cleanup یعنی «کپی لازم بود ولی نباید تحویل شود».
        // اگر exclude را اینجا هم می‌دادیم، مثلاً data/logs/ پاسارگاد که قالب خودش
        // لاگ می‌نویسد، بعد از کپی حذف می‌شد؛ tools/dryrun.php دقیقاً همین را گرفت.
        Manager::cleanupExtraFiles($botDir, (array)($spec['cleanup'] ?? []));

        // ===== ۵ب) ثبت مانیفست سورس =====
        // «چه چیزی از قالب کپی شد» ثبت می‌شود تا بعداً بتوان تشخیص داد کدام فایل‌ها
        // از قالب آمده‌اند و کدام را ادمین دستی اضافه/ویرایش کرده (دیاگنوز، بکاپ،
        // بررسی سلامت نصب). دکمهٔ «🔄 دریافت سورس بروز» حالا فقط templates/ را
        // تازه می‌کند و به کدِ ربات‌های ساخته‌شده کاری ندارد.
        try { SourceUpdate::saveManifest($type, $slug); }
        catch (Throwable $e) { Logger::getInstance()->warning('source', "manifest for {$slug}: " . $e->getMessage()); }

        // ===== ۶) وبهوک =====
        $secret = Manager::webhookSecret($type, $plainToken, $staticSecret);
        $webhook = Manager::webhookUrl($cfg, $slug, $type, $secret);
        $set = BotApi::setWebhook($plainToken, $webhook, $secret);
        $webhookSet = true;
        if (empty($set['ok'])) {
            $webhookNote = '⚠️ خطای وبهوک: ' . htmlspecialchars((string)($set['description'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
        }

        // ===== ۷) ثبت در دیتابیس =====
        $encToken = encryptToken($plainToken, $GLOBALS['secretKey'] ?? Manager::DEFAULT_SECRET_KEY);

        $store->addBot([
            'owner_id' => $owner, 'type' => $type, 'folder' => $slug,
            'token' => $encToken, 'bot_username' => $botUsername,
            'bot_id' => $temp['bot_id'] ?? 0, 'admin_id' => $adminId,
            'db_name' => $dbName, 'db_table_prefix' => '', 'webhook_url' => $webhook,
            'status' => 'active',
        ]);
        $store->incrementBuildCount($owner);
        $approved = $store->getApprovedRequestByUser($owner);
        if ($approved) $store->markRequestUsed((int)$approved['id']);

        // ===== ۸) پیام نتیجه =====
        $label = Manager::templateLabel($type);
        $lines = ["🎉 <b>ربات {$label} آماده شد!</b>"];
        if ($botUsername !== '') $lines[] = "🤖 @" . htmlspecialchars($botUsername, ENT_QUOTES, 'UTF-8');
        $lines[] = "📁 پوشه: <code>{$slug}</code>";
        if ($sqlite) {
            $lines[] = "🗄 دیتابیس: SQLite (داخل پوشه)";
        } else {
            $lines[] = "🗄 دیتابیس: <code>{$dbName}</code>";
        }
        $lines[] = "🔗 وبهوک: " . ($webhookNote === '' ? 'ست شد ✅' : $webhookNote);
        if ($tableFile !== '') {
            $lines[] = "🗂 جدول‌ها: " . ($tableOk ? '✅' : '⚠️ دستی بازش کن');
        }
        $cronFile = (string)($spec['cron'] ?? '');
        if ($cronFile !== '') {
            $lines[] = "⏰ کرون: با کرون‌دیسپچر مرکزی اجرا می‌شود ✅ (<code>{$cronFile}</code>)";
        }
        foreach ($notes as $n) $lines[] = 'ℹ️ ' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8');

        if (!empty($ownerCred)) {
            $lines[] = '🔑 اکانت سازندهٔ پنل: ' . $ownerCred['username'] . ' (خودکار ساخته شد)';
        }
        return [
            'bot_username' => $botUsername,
            'db' => $dbName,
            'custom_message' => implode("\n", $lines),
            'owner_cred' => $ownerCred,
        ];
    } catch (Throwable $e) {
        // ===== ROLLBACK =====
        // Throwable (نه فقط Exception) تا TypeErrorها و خطاهای هسته هم rollback شوند؛
        // وگرنه پوشه/دیتابیس/وبهوک نیمه‌کاره می‌ماند.
        // ۱) وبهوک ثبت‌شده روی تلگرام باید برداشته شود تا ربات حذف‌شده دیگر پینگ نگیرد
        if ($webhookSet && $plainToken !== '') {
            try {
                $whR = BotApi::deleteWebhook($plainToken);
                // نتیجه قبلاً دور ریخته می‌شد؛ حالا شکست rollback هم دیده می‌شود
                if (!is_array($whR) || empty($whR['ok'])) {
                    error_log("Rollback webhook FAILED: " . (($whR['description'] ?? '') ?: 'no response'));
                }
            }
            catch (Throwable $whErr) { error_log("Rollback webhook: " . $whErr->getMessage()); }
        }
        // ۲) دیتابیس ساخته‌شده حذف شود (قالب SQLite فایلش با حذف پوشه می‌رود)
        if ($dbCreated && $dbName !== '') {
            try {
                $port = $cfg['db_port'] ?? 3306;
                $serverPdo = new PDO("mysql:host={$cfg['db_host']};port={$port};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $serverPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            } catch (Throwable $dbErr) { error_log("Rollback DB: " . $dbErr->getMessage()); }
        }
        // ۳) فقط پوشه‌ای که «همین ساخت» ایجاد کرده پاک شود؛
        //    در صورت مسابقه، پوشهٔ ساخت برنده دست‌نخورده می‌ماند.
        if ($dirCreated && is_dir($botDir)) {
            try { Manager::removeDir($botDir); } catch (Throwable $dirErr) { error_log("Rollback DIR: " . $dirErr->getMessage()); }
        }
        throw new Exception("ساخت ناموفق: " . Manager::sanitizeDbError($e->getMessage()) . " — منابع آزاد شدند.");
    }
}

// ================= callbacks =================
function handleCallback(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, array $cb): void
{
    $uid = (int)$user['user_id'];
    $chatId = $cb['message']['chat']['id'] ?? $uid;
    $msgId = $cb['message']['message_id'] ?? 0;
    $data = $cb['data'] ?? '';
    $admin = isAdmin($user, $SUPERS);
    BotApi::answerCb($TOKEN, $cb['id']);

    // ===== canUse چک =====
    // کاربری که درخواستش توسط ادمین تأیید شده باید بتواند ادامه دهد (newbot:…)
    // و انصراف/برگشت/منو همیشه باید کار کنند تا کاربر در مرحله گیر نکند.
    if (!canUse($user, $SUPERS)
        && !$store->hasApprovedRequest($uid)
        // صاحبِ یک ربات همیشه به پنلِ رباتِ خودش دسترسی دارد. بدون این، بعد از
        // «مصرف شدن»ِ درخواستِ تأییدشده کاربر دیگر حتی پنلِ رباتی که خودش
        // ساخته بود را نمی‌دیدست — یعنی «📊 آمار»، «✏️ ویرایش توکن» و
        // «✏️ ویرایش آیدی ادمین» عملاً برای هیچ‌کس قابل استفاده نبودند.
        && !$store->hasBot($uid)
        && !BuildSettings::skipApproval($store)) {
        // باز بودنِ همیشگی: انصراف/برگشت/منو + همهٔ مسیرهای پرداخت + انتخاب نوع
        // (که خودش مجوز می‌سازد). قبلاً «pay:» هم داخل همین لیست بود ولی بعداً
        // به‌اشتباه مثل «انصراف» رفتار می‌شد؛ یعنی کاربر دکمهٔ «فروشگاه/پرداخت‌های من»
        // را می‌زد و پیام «انصراف داده شد» می‌گرفت.
        $openForUser = $data === 'cancel'
            || $data === Nav::CB_BACK_MAIN
            || $data === 'menu'   // کیبورد کهنهٔ پنل‌های ⬆️/🔄
            || $data === Nav::CB_BACK_TYPE
            || $data === Nav::CB_MY_BOTS
            || str_starts_with($data, 'pay:')
            || str_starts_with($data, 'newbot:');
        if ($openForUser) {
            if ($data === 'cancel' || $data === Nav::CB_BACK_MAIN || $data === 'menu') {
                $store->clearStep($uid);
                if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "❌ انصراف داده شد.");
                BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
                return;
            }
            // بقیهٔ مسیرها باید با منطق خودشان پردازش شوند (پرداخت/فروشگاه/ساخت)
            if ($data === Nav::CB_BACK_TYPE) {
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'type_prompt'), ['reply_markup' => Nav::typeMenu()]);
                return;
            }
            if ($data === Nav::CB_MY_BOTS) {
                sendMyBotsList($store, $TOKEN, $chatId, $uid, $msgId);
                return;
            }
            // پرداخت یا انتخاب نوع ⇒ ادامهٔ پردازش عادی پایین‌تر (بدون ردشدن از این گیت)
        } else {
            BotApi::send($TOKEN, $chatId, Texts::get($store, 'denied'),
                ['reply_markup' => BotApi::kb([[['text' => '🤖 ساخت ربات جدید'], ['text' => 'ℹ️ راهنما']]])]);
            return;
        }
    }

    if ($data === 'cancel') {
        $store->clearStep($uid);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "❌ انصراف داده شد.");
        BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
        return;
    }

    // ===== برگشت‌های اینلاین (همیشه بدون خطا) =====
    // «menu» callback_dataِ کیبوردهای کهنهٔ پنل‌های ⬆️/🔄 است؛ اگر قبولش نکنیم
    // کاربر پس از زدن «🏠 منو» پیام «این دکمه دیگر معتبر نیست» می‌گیرد.
    if ($data === Nav::CB_BACK_MAIN || $data === 'menu') {
        $store->clearStep($uid);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "🏠 منوی اصلی");
        BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
        return;
    }
    if ($data === Nav::CB_BACK_TYPE) {
        $store->clearStep($uid);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, Texts::get($store, 'type_prompt'));
        BotApi::send($TOKEN, $chatId, Texts::get($store, 'type_prompt'), ['reply_markup' => Nav::typeMenu()]);
        return;
    }
    if ($data === Nav::CB_MY_BOTS) {
        sendMyBotsList($store, $TOKEN, $chatId, $uid, $msgId);
        return;
    }
// ===== کاربر: مسیرهای پرداخت (فروشگاه/خرید/روش پرداخت) =====
    if (str_starts_with($data, 'pay:')) {
        handlePayCallback($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $msgId, $data);
        return;
    }

    // ===== ادمین: مدیریت پرداخت‌ها (قیمت/درگاه/متن/تأیید) =====
    if (str_starts_with($data, 'payadmin:')) {
        handlePayAdminCallback($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $msgId, $data);
        return;
    }
    if ($data === Nav::CB_BACK_USERS) {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        showUsersPanel($TOKEN, $chatId, $msgId);
        return;
    }
    if ($data === Nav::CB_BACK_BACKUP) {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        showBackupPanel($cfg, $store, $TOKEN, $chatId, $msgId);
        return;
    }

    // ===== ادمین: وضعیت کرون و دیاگنوز (دکمه‌های درون‌خطی تازه) =====
    if ($data === 'cron:status') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        showCronPanel($cfg, $TOKEN, $chatId, $msgId);
        return;
    }
    if ($data === 'diag:run') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        showDiagnostics($store, $cfg, $TOKEN, $chatId, $msgId);
        return;
    }

    // ===== ادمین: ویرایش متن‌های پویای ربات =====
    if (str_starts_with($data, 'texts:')) {
        handleTextsCallback($store, $TOKEN, $chatId, $msgId, $admin, $data, $user, $SUPERS);
        return;
    }

    if (str_starts_with($data, 'newbot:')) {
        $type = substr($data, 7);
        // ===== حالت تعمیرات: حتی اگر کاربر از یک کیبوردِ قدیمی «انتخاب قالب» را
        // بزند، نباید رباتی ساخته شود. گیت اینجا حیاتی است.
        if (BuildSettings::maintenanceOn($store)) {
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, BuildSettings::maintenanceNotice($store),
                ['reply_markup' => BotApi::kb([
                    [['text' => '📦 ربات‌های من']],
                    [['text' => 'ℹ️ راهنما']],
                ])]);
            return;
        }
        // availableTypes نه validTypes: دکمه‌های منوی قبلی ممکن است «مرده» باشند
        // (مثلاً پس از deploy قالبی حذف شده، یا vendor ناقص روی سرور است).
        // کاربر نباید بتواند قالبی را انتخاب کند که نصب نمی‌شود و وسط کار خطا بگیرد.
        $names = Manager::availableTypes();
        if (!isset($names[$type])) return;
        // ===== مسدودی صریح ادمین ===== 
        // جدا از «سقف پر» است: خرید اسلات سقف را از ۰ به ۱ می‌برد و مسدودی را دور می‌زند،
        // پس فروشگاه اصلاً نمایش داده نمی‌شود.
        if (!$admin && PaymentLimits::isBlocked($store, $user, $SUPERS)) {
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, PaymentLimits::blockedNotice(),
                ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
            return;
        }
        // ===== بازبینی مجدد سقف و مجوز (همان چک‌های handleMessage) =====
        // قانون قدیمی «هر کاربر فقط یک ربات» با سیستم لیمیت جایگزین شده است.
        if (!$admin && !PaymentLimits::canBuild($store, $user, $SUPERS)) {
            showLimitShop($store, $TOKEN, $chatId, $user, $SUPERS);
            return;
        }
        // ===== مجوز ساخت، قبل از پرداخت =====
        // ترتیب مهم است: قبلاً گیت پرداخت قبل از این بررسی بود و کاربری که
        // مجوز نداشت پول می‌داد بی‌آنکه بتواند بسازد. اول مجوز، بعد پول.
        // «ساخت بدون درخواست» روشن ⇒ همین گیت کلاً رد می‌شود.
        if (!$admin && !BuildSettings::skipApproval($store) && !$store->hasApprovedRequest($uid)) {
            if ($store->hasPendingRequest($uid)) {
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'request_pending_again'));
            } else {
                $store->addPendingRequest($uid, 'bot');
                AdminNotify::notify($cfg, "📥 درخواست جدید ساخت ربات\nکاربر: {$uid}");
                BotApi::send($TOKEN, $chatId, Texts::get($store, 'request_pending'));
            }
            return;
        }
        // ===== گیت پرداخت: سقف پر یا قالب پولی ⇒ اول فاکتور، بعد ادامهٔ ساخت =====
        if (!$admin && gateBuildPayment($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $type)) {
            return;
        }
        $store->setStep($uid, 'await_bot_token', ['type' => $type]);
        BotApi::send($TOKEN, $chatId, Texts::get($store, 'token_prompt', ['type' => (string)($names[$type] ?? $type)]),
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    if (str_starts_with($data, 'mybot:')) {
        $id = (int)substr($data, 6);
        $bot = $store->botById($id);
        if (!$bot || ((int)$bot['owner_id'] !== $uid && !$admin)) { BotApi::send($TOKEN, $chatId, "⛔️ دسترسی نداری."); return; }
        showBotPanel($cfg, $store, $TOKEN, $chatId, $msgId, $bot, $data, $admin);
        return;
    }

    // ادمین: رد درخواست
    if (str_starts_with($data, 'act:decline:')) {
        // قبلاً بی‌صدا return می‌شد ⇒ کاربر فقط چرخاندن انگشت روی دکمه را می‌دید
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین می‌تواند درخواست را رد کند."); return; }
        $requestId = (int)substr($data, 12);
        $req = $store->getPendingRequestById($requestId);
        if (!$req) { BotApi::send($TOKEN, $chatId, "⛔️ درخواست یافت نشد."); return; }
        $store->declineRequest($requestId);
        BotApi::send($TOKEN, $chatId, "❌ درخواست رد شد.");
        BotApi::send($TOKEN, (int)$req['user_id'], "❌ درخواست شما رد شد.");
        return;
    }

    // ادمین: تأیید درخواست (متقاضی با «ساخت ربات جدید» ادامه می‌دهد)
    if (str_starts_with($data, 'act:approve:')) {
        // قبلاً بی‌صدا return می‌شد ⇒ تأیید برای غیرادمین هیچ بازخوردی نداشت
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین می‌تواند درخواست را تأیید کند."); return; }
        $requestId = (int)substr($data, 12);
        $req = $store->getPendingRequestById($requestId);
        if (!$req) { BotApi::send($TOKEN, $chatId, "⛔️ درخواست یافت نشد."); return; }
        $store->approveRequest($requestId);
        BotApi::send($TOKEN, $chatId, "✅ درخواست تأیید شد!");
        BotApi::send($TOKEN, (int)$req['user_id'], Texts::get($store, 'request_approved'));
        return;
    }

    if ($data === 'allbots:refresh') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        showAllBotsPanel($store, $TOKEN, $chatId, $msgId);
        return;
    }

    if (str_starts_with($data, 'act:')) {
        $parts = explode(':', $data);
        $action = $parts[1] ?? '';
        $botId = (int)($parts[2] ?? 0);
        $bot = $store->botById($botId);
        if (!$bot) {
            // شناسهٔ ربات دیگر نیست (حذف شده / کال‌بک کهنه) — نباید بی‌صدا گم شود
            Logger::getInstance()->warning('action', "bot #{$botId} not found (action '{$action}', uid {$uid})");
            BotApi::send($TOKEN, $chatId, "⛔️ رباتی با شناسهٔ <code>{$botId}</code> پیدا نشد (احتمالاً حذف شده است).");
            return;
        }
        if ((int)$bot['owner_id'] !== $uid && !$admin) {
            BotApi::send($TOKEN, $chatId, "⛔️ این ربات مال شما نیست؛ فقط صاحب ربات یا ادمین می‌تواند آن را مدیریت کند.");
            return;
        }
        botAction($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $msgId, $bot, $action);
        return;
    }

    // ===== canUse برای users:add/remove/list =====
    if ($data === 'users:add') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        $store->setStep($uid, 'await_user_add');
        BotApi::send($TOKEN, $chatId,
            "➕ <b>افزودن کاربر مجاز</b>\n\n"
            . "🆔 آیدی عددی کاربر را بفرستید (از ‎<code>@userinfobot</code>).\n"
            . "او بلافاصله می‌تواند بدون درخواست ربات بسازد.\n\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($data === 'users:remove') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        $store->setStep($uid, 'await_user_remove');
        BotApi::send($TOKEN, $chatId,
            "➖ <b>حذف دسترسی کاربر</b>\n\n"
            . "🆔 آیدی عددی کاربری را بفرستید که می‌خواهید دسترسی‌اش برداشته شود.\n\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($data === 'users:list') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        $ids = $store->allowedIds();
        if (!$ids) {
            BotApi::send($TOKEN, $chatId, "📃 <b>کاربران مجاز</b>\n\nهنوز هیچ کاربری مجاز نشده است.\nبا «➕ افزودن کاربر» شروع کنید.",
                ['reply_markup' => Nav::usersPanelKb()]);
            return;
        }
        // پیام تلگرام سقف ۴۰۹۶ کاراکتر دارد → چندتکه ارسال می‌شود
        $lines = array_map(fn($i) => Ui::code((string)$i), $ids);
        $chunks = array_chunk($lines, 40);
        foreach ($chunks as $i => $chunk) {
            $head = $i === 0
                ? "📃 <b>کاربران مجاز</b> (" . count($ids) . ")\n" . Ui::sep() . "\n\n"
                : "📃 <b>کاربران مجاز — ادامه</b> (" . ($i * 40 + 1) . "–" . min(count($ids), ($i + 1) * 40) . ")\n";
            BotApi::send($TOKEN, $chatId, $head . implode("\n", $chunk));
            if (count($chunks) > 1) usleep(80000);
        }
        return;
    }

    // ===== ⚙️ تنظیمات: کلیدهای روشن/خاموش (فقط ادمین) =====
    if (str_starts_with($data, 'set:')) {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]); return; }
        $action = substr($data, 4);

        if ($action === 'maintenance') {
            $now = BuildSettings::toggleMaintenance($store);
            Logger::getInstance()->info('settings', "Admin {$uid} " . ($now ? 'ENABLED' : 'DISABLED') . ' maintenance mode');
            BotApi::edit($TOKEN, $chatId, $msgId, ($now
                    ? "🔧 <b>حالت تعمیرات روشن شد.</b>\n\n"
                        . "از این لحظه هیچ‌کس نمی‌تواند ربات تازه بسازد و کاربرها\n"
                        . "پیام «قسمت ربات در حال تعمیر است» را می‌بینند.\n"
                        . "ربات‌های موجود دست‌نخورده کار می‌کنند ✅"
                    : "🟢 <b>حالت تعمیرات خاموش شد.</b>\n\nساخت ربات دوباره برای همه باز شد. 🚀"),
                ['reply_markup' => Nav::settingsPanelKb($store)]);
            return;
        }

        if ($action === 'approval') {
            $need = BuildSettings::toggleApproval($store);
            Logger::getInstance()->info('settings', "Admin {$uid} " . ($need ? 'REQUIRED' : 'SKIPPED') . ' approval for new bots');
            BotApi::edit($TOKEN, $chatId, $msgId, ($need
                    ? "📝 <b>نیاز به تأیید ادمین روشن شد.</b>\n\n"
                        . "کاربر باید «🤖 ساخت ربات جدید» را بزند تا درخواستش ثبت شود،\n"
                        . "و تا تأیید شما نمی‌تواند وارد ساخت شود."
                    : "🚀 <b>ساخت بدون درخواست روشن شد.</b>\n\n"
                        . "هر کاربرِ مجاز مستقیم وارد انتخاب قالب می‌شود و\n"
                        . "دیگر هیچ درخواستی ثبت نمی‌شود.\n"
                        . "سقفِ ساخت و پرداخت‌ها همچنان برقرار است ✅"),
                ['reply_markup' => Nav::settingsPanelKb($store)]);
            return;
        }

        if ($action === 'mainttext') {
            $store->setStep($uid, 'await_maintenance_text');
            $cur = Texts::text($store, 'maintenance');
            BotApi::send($TOKEN, $chatId,
                "📝 <b>متن پیام تعمیرات</b>\n\n"
                . "این متن وقتی «حالت تعمیرات» روشن است به کاربر نشان داده می‌شود.\n\n"
                . "📌 <b>متن فعلی:</b>\n" . Ui::quote($cur)
                . "\n\n<b>متن تازه را بفرستید.</b>\n"
                . "جای‌نگهدارِ مجاز: " . Ui::code('‹eta›') . " (زمان تقریبی بازگشت)\n"
                . "برای برگشت به پیش‌فرض کلمهٔ " . Ui::code('reset') . " را بفرستید.\n"
                . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                ['reply_markup' => Nav::stepKb()]);
            return;
        }

        if ($action === 'maintaineta') {
            $store->setStep($uid, 'await_maintenance_eta');
            $eta = BuildSettings::maintenanceEta($store);
            BotApi::send($TOKEN, $chatId,
                "⏳ <b>زمان تقریبی بازگشت</b>\n\n"
                . "یک متن کوتاه بنویسید؛ داخل پیام تعمیرات نشان داده می‌شود.\n"
                . "مثال: ‎<code>تا پایان امروز</code> یا ‎<code>فردا صبح</code>\n\n"
                . "زمان فعلی: " . ($eta !== '' ? '<b>' . Ui::e($eta) . '</b>' : '<i>تعیین نشده</i>') . "\n"
                . "برای پاک‌کردن، کلمهٔ " . Ui::code('reset') . " را بفرستید.\n"
                . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                ['reply_markup' => Nav::stepKb()]);
            return;
        }

        if ($action === 'childowner') {
            $store->setStep($uid, 'await_child_owner');
            $cu = trim((string)($store->getSetting('child_owner_username', '') ?? ''));
            BotApi::send($TOKEN, $chatId,
                "🏢 <b>اکانت سازندهٔ پنل</b>\n\n"
                . "این اکانت داخل <code>config.php</code> هر ربات تازه نوشته می‌شود\n"
                . "و برای فروش «پنل نمایندگی» لازم است.\n\n"
                . "📌 <b>متن فعلی:</b> " . ($cu !== '' ? Ui::code($cu) : '<i>تنظیم نشده (خودکار ساخته می‌شود)</i>') . "\n\n"
                . "<b>حالا نام کاربری را بفرستید.</b>\n"
                . "برای ساخت خودکارِ یک اکانت تصادفی، کلمهٔ " . Ui::code('auto') . " را بفرستید.\n"
                . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                ['reply_markup' => Nav::stepKb()]);
            return;
        }

        if ($action === 'panel') { showSettingsPanel($store, $TOKEN, $chatId, $msgId); return; }
        showSettingsPanel($store, $TOKEN, $chatId, $msgId);
        return;
    }

    // ===== 🔄 دریافت سورس بروز (فقط سوپرادمین) =====
    //
    // این بخش فقط «سورسِ قالب‌ها» را تازه می‌کند (templates/)؛ هیچ رباتِ ساخته‌شده‌ای
    // تغییر نمی‌کند. ترتیب: اول «تأیید» (src:ask) بعد «اجرا» (src:go).
    if (str_starts_with($data, 'src:')) {
        if (!isSuper($SUPERS, $uid)) {
            BotApi::send($TOKEN, $chatId, "⛔️ فقط سوپرادمین اجازهٔ بروزرسانی سورس را دارد.");
            return;
        }
        $action = substr($data, 4);

        if ($action === 'refresh') {
            showSourcePanel($cfg, $store, $TOKEN, $chatId, $msgId);
            return;
        }

        if ($action === 'check') {
            BotApi::edit($TOKEN, $chatId, $msgId, "🔄 در حال fetch از گیت…");
            $f = SelfUpdate::fetchSource();
            showSourcePanel($cfg, $store, $TOKEN, $chatId, $msgId,
                $f['ok'] ? '🔄 وضعیت از گیت تازه شد.'
                         : ('⚠️ fetch ناموفق بود: ' . mb_substr(trim((string)$f['out']), 0, 300)));
            return;
        }

        if ($action === 'log') {
            $tail = SelfUpdate::logTail(25);
            $t = $tail !== '' ? htmlspecialchars($tail, ENT_QUOTES, 'UTF-8') : '(هنوز لاگی نیست)';
            BotApi::edit($TOKEN, $chatId, $msgId, "📜 <b>آخرین لاگ بروزرسانی سورس</b>\n<pre>{$t}</pre>",
                ['reply_markup' => BotApi::ikb([[['text' => '↩️ بازگشت', 'callback_data' => 'src:refresh']]])]);
            return;
        }

        if ($action === 'ask') {
            showSourceConfirm($cfg, $store, $TOKEN, $chatId, $msgId);
            return;
        }

        if ($action === 'apply') {
            $bots = $store->allBots();
            $pending = 0;
            foreach ($bots as $b) {
                $plan = SourceUpdate::plan((string)($b['type'] ?? ''), (string)($b['folder'] ?? ''));
                if ($plan['ok'] && !empty($plan['out_of_date'])) $pending++;
            }
            if ($pending === 0) {
                $t = "✅ همهٔ ربات‌های ساخته‌شده با قالب‌های فعلی هم‌ترازند؛ چیزی برای اعمال نیست.";
                $kb = BotApi::ikb([[['text' => '🔄 پنل سورس', 'callback_data' => 'src:refresh']]]);
                if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
                else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
                return;
            }
            $text = "🚀 <b>اعمالِ سورسِ تازه روی ربات‌های ساخته‌شده</b>\n\n"
                . "تعداد ربات‌هایی که سورسشان از قالب عقب است: <b>{$pending}</b>\n\n"
                . "🛡 حفاظت‌ها:\n"
                . "• <code>config.php</code> و دیتابیسِ هر ربات هرگز دست نمی‌خورد\n"
                . "• فقط فایل‌های سورسِ عوض‌شده/تازه کپی می‌شوند\n"
                . "• برای هر ربات قبل از اعمال بکاپِ کامل گرفته می‌شود\n"
                . "• در صورت خطای نحوی فایل‌های بازنویسی‌شده برگردانده می‌شوند\n\n"
                . "ممکن است چند دقیقه طول بکشد. ادامه بدهم؟";
            $kb = BotApi::ikb([
                [['text' => '✅ بله، اعمال کن', 'callback_data' => 'src:applygo']],
                [['text' => '❌ انصراف', 'callback_data' => 'src:refresh']],
            ]);
            if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $text, ['reply_markup' => $kb]);
            else BotApi::send($TOKEN, $chatId, $text, ['reply_markup' => $kb]);
            return;
        }

        if ($action === 'applygo') {
            applySourceToAllBots($cfg, $store, $TOKEN, $chatId, $uid);
            return;
        }

        if ($action === 'go') {
            runTemplatesUpdate($TOKEN, $chatId);
            return;
        }

        // کال‌بک‌های کهنهٔ «بروزرسانیِ یک/همهٔ ربات‌ها» حذف شده‌اند (دیگر هیچ رباتی را تغییر نمی‌دهیم)
        Logger::getInstance()->info('source', "legacy source callback '{$action}' from uid {$uid} – ignored");
        BotApi::send($TOKEN, $chatId,
            "ℹ️ بروزرسانی روی ربات‌های ساخته‌شده حذف شد؛ اکنون فقط <code>templates/</code> تازه می‌شود تا کاربران بتوانند نسخهٔ جدید را نصب کنند.",
            ['reply_markup' => BotApi::ikb([[['text' => '🔄 پنل بروزرسانی سورس', 'callback_data' => 'src:refresh']]])]);
        return;
    }

    // ===== بکاپ دیتابیس (فقط ادمین) =====
    if (str_starts_with($data, 'backup:')) {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        $action = substr($data, 7);
        $cur = DbBackup::settings($cfg, $store);
        if ($action === 'toggle') {
            $set = DbBackup::saveSettings($store, !$cur['enabled'], $cur['times']);
            Logger::getInstance()->info('backup', "Admin {$uid} " . ($set['enabled'] ? 'enabled' : 'disabled') . " backups");
            showBackupPanel($cfg, $store, $TOKEN, $chatId, $msgId);
            return;
        }
        if ($action === 'preset2') {
            DbBackup::saveSettings($store, true, ['03:00', '15:00']);
            Logger::getInstance()->info('backup', "Admin {$uid} set backup preset: twice daily");
            showBackupPanel($cfg, $store, $TOKEN, $chatId, $msgId);
            return;
        }
        if ($action === 'preset1') {
            DbBackup::saveSettings($store, true, ['03:00']);
            Logger::getInstance()->info('backup', "Admin {$uid} set backup preset: once daily");
            showBackupPanel($cfg, $store, $TOKEN, $chatId, $msgId);
            return;
        }
        if ($action === 'custom') {
            $store->setStep($uid, 'await_backup_times');
            BotApi::send($TOKEN, $chatId, "🕐 ساعت‌های بکاپ را بفرست (به وقت سرور، حداکثر ۴ ساعت).\nمثلاً: <code>3,15</code> یعنی ۰۳:۰۰ و ۱۵:۰۰\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
            return;
        }
        if ($action === 'now') {
            BotApi::send($TOKEN, $chatId, "⏳ بکاپ همه ربات‌ها شروع شد؛ بعد از اتمام همین‌جا نتیجه می‌آید...");
            @set_time_limit(0);
            $res = DbBackup::runDue($cfg, $store, true);
            $lines = [];
            foreach ($res['sent'] as $s) $lines[] = "✅ {$s}";
            foreach ($res['failed'] as $f => $e) $lines[] = "❌ {$f}: " . htmlspecialchars($e);
            if ($lines === []) $lines[] = "ربات فعالی با دیتابیس پیدا نشد.";
            BotApi::send($TOKEN, $chatId, "💾 <b>نتیجه بکاپ دستی</b>\n" . implode("\n", $lines));
            showBackupPanel($cfg, $store, $TOKEN, $chatId, $msgId);
            return;
        }
        // refresh یا هر چیز دیگر → نمایش دوباره پنل
        showBackupPanel($cfg, $store, $TOKEN, $chatId, $msgId);
        return;
    }

    if ($data === 'users:requests') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        sendPendingRequests($store, $TOKEN, $chatId);
        return;
    }

    // ===== دکمهٔ ناشناخته / منقضی =====
    // کیبورد کهنه (بعد از تغییر منو یا حذف قالب) یا «noop» منوی خالی قالب‌ها.
    // بدون این گارد، کاربر فقط چرخاندن انگشت روی دکمه را می‌دید و هیچ جوابی نمی‌گرفت
    // («ربات هنگ کرد»)؛ حالا هم علت دقیق گفته می‌شود هم منوی تازه داده می‌شود.
    if ($data === 'noop') {
        BotApi::send($TOKEN, $chatId,
            "⚠️ <b>هیچ قالبی روی سرور نصب نیست.</b>\n"
            . "قالب‌ها در <code>templates/</code> قرار می‌گیرند؛ با ادمین تماس بگیرید.",
            ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
        return;
    }
    Logger::getInstance()->warning('callback', "unknown callback data (uid {$uid}): {$data}");
    BotApi::send($TOKEN, $chatId,
        "⚠️ این دکمه دیگر معتبر نیست (احتمالاً کیبورد قدیمی است) — منوی تازه 👇",
        ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
}

// ================= پرداخت: مسیر کاربر =================

/**
 * اطلاع‌رسانی به همهٔ ادمین‌ها/سوپرها.
 * پرداخت دستی بدون این اطلاع‌رسانی بی‌اثر بود: ادمین باید خودش مدام
 * «🧾 بررسی پرداخت‌ها» را باز می‌کرد تا بفهمد رسیدی رسیده است.
 * هر خطا اینجا عمداً بلعیده می‌شود تا یک ادمین قطع‌شده، رسید را از بین نبرد.
 */
function notifySupers(string $TOKEN, array $SUPERS, string $text, array $extra = []): int
{
    $sent = 0;
    foreach ($SUPERS as $sid) {
        $sid = (int)$sid;
        if ($sid <= 0) continue;
        try {
            $r = BotApi::send($TOKEN, $sid, $text, $extra);
            if (!empty($r['ok'])) $sent++;
        } catch (Throwable $e) { /* ادمین در دسترس نیست */ }
    }
    return $sent;
}

/**
 * همهٔ کال‌بک‌های سمت کاربر (پیشوند pay:).
 * فقط منطق «نمایش و ساخت فاکتور» اینجاست؛ دیتابیس در Payments و متن/کیبورد در PaymentPanel.
 */
/**
 * استعلام وضعیت واقعیِ یک فاکتور «تأیید خودکار» از هر درگاهِ پشتیبانی‌شده.
 *
 * یک تابع به‌جای سه شاخهٔ تکراری؛ خروجیِ یکسان برای همه:
 *   ['ok'=>bool پاسخ رسید؟, 'paid'=>bool پرداخت شده؟, 'status'=>string, 'error'=>string, 'payment_id'=>string]
 *
 * چه چیزی «paid» را تعیین می‌کند (و نه چیز دیگری):
 *   • NOWPayments → payment_status ∈ {finished, confirmed}
 *   • زرین‌پال      → کد verify ∈ {100, 101}
 *   • آقای پرداخت  → code ∈ {1, 2}
 * مبلغ همیشه از رکوردِ خودمان خوانده می‌شود، نه از پارامترِ آدرس.
 */
function verifyAutoPayment(array $cfg, Store $store, array $p): array
{
    $ext = trim((string)($p['ext_id'] ?? ''));
    $amount = (int)($p['amount'] ?? 0);
    $fail = static fn(string $why, string $status = ''): array => [
        'ok' => false, 'paid' => false, 'status' => $status, 'error' => $why, 'payment_id' => '',
    ];
    if ($amount <= 0) return $fail('مبلغ فاکتور نامعتبر است');
    if ($ext === '')  return $fail('شناسهٔ فاکتور در سرویس ثبت نشده است');

    switch ((string)($p['method'] ?? '')) {
        case Payments::METHOD_NOWPAY: {
            $apiKey = PaymentNowPay::apiKey($store, $cfg);
            if ($apiKey === '') return $fail('کلید API نوب‌پیمنت تنظیم نشده است');
            $st = PaymentNowPay::fetchStatus($apiKey, $ext);
            if (empty($st['ok'])) return $fail((string)($st['error'] ?? 'خطای نامشخص'));
            $status = PaymentNowPay::extractStatus((array)($st['data'] ?? []));
            return [
                'ok' => true, 'paid' => PaymentNowPay::isPaidStatus($status),
                'status' => $status, 'error' => '',
                'payment_id' => (string)($st['payment_id'] ?? ''),
            ];
        }
        case Payments::METHOD_ZARIN: {
            $mid = PaymentZarin::merchantId($store, $cfg);
            if ($mid === '') return $fail('کد پذیرندهٔ زرین‌پال تنظیم نشده است');
            $v = PaymentZarin::verify($mid, $amount, $ext, PaymentZarin::isSandbox($store));
            if (empty($v['ok'])) return $fail((string)($v['error'] ?? 'خطای نامشخص'));
            return [
                'ok' => true, 'paid' => (bool)$v['paid'],
                'status' => $v['message'] !== '' ? (string)$v['message'] : ('کد ' . (int)$v['code']),
                'error' => '',
                'payment_id' => (int)($v['ref_id'] ?? 0) > 0 ? (string)(int)$v['ref_id'] : '',
            ];
        }
        case Payments::METHOD_AQAYE: {
            $pin = PaymentAqaye::pin($store, $cfg);
            if ($pin === '') return $fail('کد پین آقای پرداخت تنظیم نشده است');
            $v = PaymentAqaye::verify($pin, $amount, $ext);
            if (empty($v['ok'])) return $fail((string)($v['error'] ?? 'خطای نامشخص'));
            return [
                'ok' => true, 'paid' => (bool)$v['paid'],
                'status' => (string)$v['code'] !== '' ? ('کد ' . (string)$v['code']) : 'نامشخص',
                'error' => '',
                'payment_id' => '',
            ];
        }
    }
    return $fail('این روش پرداخت پشتیبانی نمی‌شود');
}

function handlePayCallback(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, int $msgId, string $data): void
{
    $uid = (int)$user['user_id'];
    try { Payments::ensureSchema($store); } catch (Throwable $e) {}
    $parts = explode(':', $data);
    $sub = $parts[1] ?? '';
    // خطای فروشگاه: دکمهٔ «برگشت» باید به فروشگاه برگردد، نه اینکه کاربر
    // وسط پرداخت با دکمهٔ برگشت از کل جریان بیرون پرت شود (باگ قبلی: stepKb
    // یعنی «برگشت → منوی اصلی»، در حالی که کاربر هنوز نیمه‌کاره است).
    $shopKb = BotApi::ikb([
        [['text' => '🔄 فروشگاه', 'callback_data' => 'pay:shop']],
        [['text' => '🧾 پرداخت‌های من', 'callback_data' => 'pay:mine']],
        [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]],
    ]);
    $fail = function (string $why) use ($TOKEN, $chatId, $msgId, $shopKb) {
        $t = "⛔️ {$why}\n\nاگر پرداخت نیمه‌کاره دارید از «🧾 پرداخت‌های من» ادامه/لغوش کنید.";
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $shopKb]);
        else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $shopKb]);
    };

    // فروشگاه لیمیت
    if ($sub === 'shop' || $sub === '') {
        showLimitShop($store, $TOKEN, $chatId, $user, $SUPERS);
        return;
    }

    // پرداخت‌های من
    if ($sub === 'mine') {
        $t = PaymentPanel::myPaymentsText($store, $uid);
        $kb = PaymentPanel::myPaymentsKb($store, $uid);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
        else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
        return;
    }

    // خرید: اسلات لیمیت یا مجوز قالب
    if ($sub === 'buy') {
        // ===== گارد مشترک خرید =====
        // ۱) جلوگیری از اسپم دیتابیس: کاربری که فاکتور می‌سازد و رها می‌کند
        //    می‌توانست با یک کلیک هزاران ردیف pending بسازد.
        // ۲) بررسی وجود روش پرداخت «قبل» از ساخت ردیف؛ وگرنه یک فاکتور یتیم
        //    pending ساخته می‌شد که هیچ‌وقت قابل پرداخت نبود.
        if (Payments::openPaymentCount($store, $uid) >= Payments::MAX_OPEN_PAYMENTS) {
            $fail("شما " . Payments::MAX_OPEN_PAYMENTS . " پرداخت باز دارید. ابتدا تکلیفشان را روشن کنید (پرداخت یا «🚫 لغو»).");
            return;
        }
        if (!PaymentPanel::methodsAvailable($store, $cfg)) {
            BotApi::send($TOKEN, $chatId, PaymentPanel::noMethodText($store), ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
            return;
        }
        $what = $parts[2] ?? '';
        if ($what === 'limit') {
            $slots = (int)($parts[3] ?? 0);
            if ($slots < 1 || $slots > 20) { $fail("تعداد اسلات نامعتبر است."); return; }
            if (!PaymentGateways::isEnabled($store, PaymentGateways::LIMIT)) { $fail("فروش لیمیت غیرفعال است."); return; }
            // بدون این گارد، کاربر مسدود با یک خریدِ ۱ اسلاتی سقفش را از ۰ به ۱ می‌برد
            if (PaymentLimits::isBlocked($store, $user, $SUPERS)) { $fail(PaymentLimits::blockedNotice()); return; }
            $unit = PaymentPricing::limitUnitPrice($store);
            if ($unit <= 0) { $fail("قیمت اسلات توسط ادمین تعیین نشده است."); return; }
            $amount = $unit * $slots;
            $pid = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', $slots, $amount, '');
            AdminNotify::notify($cfg, "🧾 خرید اسلات\nکاربر: {$uid}\nتعداد: {$slots}\nمبلغ: {$amount} تومان");
            $t = "➕ <b>خرید {$slots} اسلات</b>\n\n"
                . "قیمت هر اسلات: " . PaymentPricing::formatToman($unit) . "\n"
                . "مبلغ کل: <b>" . number_format($amount) . " تومان</b>\n\n"
                . PaymentGateways::note($store, PaymentGateways::LIMIT, [
                    'amount' => PaymentPricing::formatToman($amount),
                    'slots' => (string)$slots,
                    'limit' => PaymentLimits::formatLimit(PaymentLimits::getLimit($store, $user, $SUPERS)),
                    'count' => (string)PaymentLimits::botCount($store, $uid),
                ]) . "\n\nروش پرداخت را انتخاب کن:";
            BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => PaymentPanel::methodKb($store, $pid, $cfg)]);
            return;
        }
        if ($what === 'template') {
            $type = (string)($parts[3] ?? '');
            if (!isset(Manager::validTypes()[$type])) { $fail("قالب نامعتبر است."); return; }
            if (!PaymentGateways::isEnabled($store, PaymentGateways::TEMPLATE)) { $fail("پولی‌کردن قالب‌ها غیرفعال است."); return; }
            if (!PaymentPricing::isPaid($store, $type)) { $fail("این قالب رایگان است."); return; }
            if (Payments::countUsableTemplateVoucher($store, $uid, $type) > 0) {
                $fail("شما هم‌اکنون مجوز ساخت این قالب را دارید."); return;
            }
            $amount = PaymentPricing::templatePrice($store, $type);
            $pid = Payments::createPayment($store, $uid, Payments::KIND_TEMPLATE, $type, 0, $amount, '');
            AdminNotify::notify($cfg, "🧾 خرید قالب\nکاربر: {$uid}\nقالب: {$type}\nمبلغ: {$amount} تومان");
            $note = PaymentGateways::note($store, PaymentGateways::TEMPLATE, [
                'amount' => PaymentPricing::formatToman($amount),
                'type' => $type,
            ]);
            $t = "💰 <b>خرید مجوز قالب «{$type}»</b>\n\nمبلغ: <b>" . number_format($amount) . " تومان</b>";
            if ($note !== '') $t .= "\n\n" . $note;
            $t .= "\n\nروش پرداخت را انتخاب کن:";
            BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => PaymentPanel::methodKb($store, $pid, $cfg)]);
            return;
        }
        $fail("درخواست خرید نامعتبر است.");
        return;
    }

    // بررسی دستی وضعیت پرداخت کریپتویی
    // پشتیبانِ وقتی است که IPN سرویس به سرور نمی‌رسد (فایروال/پراکسی/اینترنت قطعی).
    // markCryptoPaid خودش idempotent است، پس فشار دادن این دکمه بی‌خطر است.
    if ($sub === 'check') {
        $pid = (int)($parts[2] ?? 0);
        $p = $pid > 0 ? Payments::getPayment($store, $pid) : null;
        if (!$p || (int)$p['user_id'] !== $uid) { $fail("پرداخت یافت نشد."); return; }

        if (in_array($p['status'], [Payments::ST_PAID, Payments::ST_USED], true)) {
            BotApi::edit($TOKEN, $chatId, $msgId, "✅ " . Payments::describe($p)
                . "\n🎁 این پرداخت قبلاً تأیید و اعمال شده است.", ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
            return;
        }
        if ($p['method'] !== Payments::METHOD_NOWPAY && $p['method'] !== Payments::METHOD_ZARIN
            && $p['method'] !== Payments::METHOD_AQAYE) {
            BotApi::edit($TOKEN, $chatId, $msgId, "ℹ️ <b>این فاکتور «در انتظار پرداخت آنلاین» نیست.</b>\n"
                . Payments::describe($p) . "\n\n"
                . "اگر کارت‌به‌کارت پرداخت کرده‌اید، فیش را بفرستید تا ادمین بررسی کند.",
                ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
            return;
        }
        if ($p['status'] !== Payments::ST_AWAIT_PAY) {
            BotApi::edit($TOKEN, $chatId, $msgId, "ℹ️ این فاکتور در انتظار پرداخت آنلاین نیست:\n"
                . Payments::describe($p), ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
            return;
        }
        if (trim((string)($p['ext_id'] ?? '')) === '') {
            BotApi::edit($TOKEN, $chatId, $msgId, "⚠️ <b>فاکتوری برای این پرداخت ساخته نشده است.</b>\n\n"
                . "از «🧾 پرداخت‌های من» دوباره روش پرداخت را انتخاب کنید.",
                ['reply_markup' => PaymentPanel::myPaymentsKb($store, $uid)]);
            return;
        }

        // استعلام یکپارچه: هر سه درگاهِ «تأیید خودکار» از یک مسیر بررسی می‌شوند
        $chk = verifyAutoPayment($cfg, $store, $p);
        if (!$chk['ok']) {
            BotApi::edit($TOKEN, $chatId, $msgId, "⚠️ <b>استعلام از سرویس ناموفق بود:</b>\n"
                . Ui::quote(Ui::e((string)$chk['error'])),
                ['reply_markup' => PaymentPanel::invoiceKb($pid, (string)($p['pay_url'] ?? ''))]);
            return;
        }
        $status = (string)$chk['status'];
        // شناسهٔ واقعی (payment_id / ref_id) نگه داشته شود تا دفعهٔ بعد مستقیم بپرسیم
        $extReal = (string)($chk['payment_id'] ?? '');
        if ($extReal !== '' && $extReal !== (string)($p['ext_id'] ?? '')) {
            try { Payments::setExtId($store, $pid, $extReal); } catch (Throwable $e) {}
        }
        if (!$chk['paid']) {
            BotApi::edit($TOKEN, $chatId, $msgId, "🕐 <b>هنوز پرداخت نشده است.</b>\n\n"
                . "وضعیت در سرویس: " . Ui::code($status !== '' ? $status : 'نامشخص') . "\n"
                . "چند دقیقهٔ بعد دوباره بزنید یا از لینک پرداخت استفاده کنید.",
                ['reply_markup' => PaymentPanel::invoiceKb($pid, (string)($p['pay_url'] ?? ''))]);
            return;
        }
        $done = Payments::markAutoPaid($store, $pid);
        if (!$done) { $fail("پرداخت قابل اعمال نبود."); return; }
        $note = (string)($done['grant_note'] ?? '');
        BotApi::edit($TOKEN, $chatId, $msgId,
            "✅ <b>پرداخت شما تأیید شد!</b>\n\n"
            . Payments::describe($done)
            . ($note !== '' ? "\n🎁 " . Ui::e($note) : "")
            . "\n\n🚀 حالا «🤖 ساخت ربات جدید» را بزنید.",
            ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
        return;
    }

    // لغو فاکتور توسط خود کاربر
    if ($sub === 'cancel') {
        $pid = (int)($parts[2] ?? 0);
        $p = $pid > 0 ? Payments::getPayment($store, $pid) : null;
        if (!$p || (int)$p['user_id'] !== $uid) { $fail("پرداخت یافت نشد."); return; }
        // «await_pay» هم قابل لغو است: قبلاً نبود و کاربرِ فاکتور کریپتوییِ
        // بلااستفاده هیچ راه فراری نداشت. فاکتور سمت NOWPayments خودش منقضی می‌شود.
        $cancellable = [Payments::ST_PENDING, Payments::ST_AWAIT_RECEIPT, Payments::ST_AWAIT_PAY];
        if (in_array($p['status'], $cancellable, true)) {
            Payments::setStatus($store, $pid, Payments::ST_CANCELLED);
            // اگر کاربر وسط ارسال رسید بود، استپ معلق پاک شود
            $tempNow = json_decode((string)($user['temp'] ?? '{}'), true) ?: [];
            if (($user['step'] ?? '') === 'await_card_receipt' && (int)($tempNow['payment_id'] ?? 0) === $pid) {
                $store->clearStep($uid);
            }
        }
        $msg = in_array($p['status'], $cancellable, true)
            ? "🚫 پرداخت لغو شد."
            : "ℹ️ این پرداخت در وضعیت دیگری است و قابل لغو نیست:\n" . Payments::describe($p);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $msg, ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
        else BotApi::send($TOKEN, $chatId, $msg, ['reply_markup' => PaymentPanel::limitShopKb($store, $user, $SUPERS)]);
        return;
    }

    // انتخاب روش پرداخت
    if ($sub === 'method') {
        $method = (string)($parts[2] ?? '');
        $pid = (int)($parts[3] ?? 0);
        $p = $pid > 0 ? Payments::getPayment($store, $pid) : null;
        if (!$p || (int)$p['user_id'] !== $uid) { $fail("پرداخت یافت نشد."); return; }
        if (!in_array($p['status'], [Payments::ST_PENDING, Payments::ST_AWAIT_RECEIPT], true)) {
            $fail("این پرداخت قبلاً ثبت/لغو شده است."); return;
        }
        $amount = (int)$p['amount'];

        if ($method === Payments::METHOD_CARD) {
            if (!PaymentGateways::isEnabled($store, PaymentGateways::CARD)) { $fail("درگاه کارت‌به‌کارت غیرفعال است."); return; }
            if (!PaymentCard::isConfigured($store)) { $fail("شماره کارت ثبت نشده است؛ با ادمین تماس بگیرید."); return; }
            Payments::setMethod($store, $pid, Payments::METHOD_CARD, Payments::ST_AWAIT_RECEIPT);
            $store->setStep($uid, 'await_card_receipt', ['payment_id' => $pid]);
            $t = PaymentCard::payInstructions($store, $amount, (string)$pid);
            $t .= "\n\nبرای انصراف: " . Nav::CANCEL;
            BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => Nav::stepKb()]);
            return;
        }

        if ($method === Payments::METHOD_NOWPAY) {
            if (!PaymentGateways::isEnabled($store, PaymentGateways::NOWPAY)) { $fail("درگاه کریپتو غیرفعال است."); return; }
            // کلید کامل (api + ipn_secret) لازم است؛ وگرنه فاکتوری ساخته می‌شد که
            // هرگز خودکار تأیید نمی‌شد. یک کال‌بک کهنه/جعلی هم از این گارد رد می‌شود.
            if (!PaymentNowPay::isConfigured($store, $cfg)) {
                $fail("درگاه کریپتو کامل پیکربندی نشده (کلید API یا IPN Secret).");
                return;
            }
            $apiKey = PaymentNowPay::apiKey($store, $cfg);
            if ($apiKey === '') { $fail("کلید API ثبت نشده است؛ با ادمین تماس بگیرید."); return; }
            // نرخِ مؤثر: اول نرخ تازهٔ API (قابل تازه‌سازی خودکار)، وگرنه نرخ دستی
            $rate = PaymentPricing::effectiveUsdRate($store);
            $usd = PaymentPricing::tomanToUsd($amount, $rate);
            if ($usd <= 0) { $fail("مبلغ پرداخت معتبر نیست."); return; }
            $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
            $ipnUrl = $base !== '' ? $base . '/nowpayments_ipn.php' : '';
            $res = PaymentNowPay::createInvoice($apiKey, $usd, "PAY-{$pid}", '', '', $ipnUrl);
            if (empty($res['ok'])) {
                Logger::getInstance()->error('payment', "NOWPayments invoice failed for #{$pid}: " . ($res['error'] ?? '?'));
                $fail("ساخت فاکتور کریپتو ناموفق بود:\n" . ($res['error'] ?? '') . "\nلطفاً دوباره تلاش کنید.");
                return;
            }
            Payments::setMethod($store, $pid, Payments::METHOD_NOWPAY, Payments::ST_AWAIT_PAY,
                (string)($res['invoice_id'] ?? ''), (string)($res['pay_url'] ?? ''));
            $note = PaymentGateways::note($store, PaymentGateways::NOWPAY, [
                'amount' => number_format($amount) . ' تومان (~' . $usd . ' USD)',
            ]);
            $payUrl = (string)($res['pay_url'] ?? '');
            $t = "🪙 <b>پرداخت کریپتویی (NOWPayments)</b>\n\n"
                . Ui::kv('💰', 'مبلغ', Ui::toman($amount)) . "\n"
                . Ui::kv('💱', 'معادل', '≈ ' . $usd . ' USD')
                . "\n" . Ui::kv('📈', 'نرخ استفاده‌شده', number_format($rate) . ' تومان برای هر دلار')
                . "\n" . Ui::kv('🔢', 'شمارهٔ فاکتور', 'PAY-' . $pid, true);
            if ($payUrl !== '') {
                $t .= "\n\n🔗 " . Ui::link($payUrl);
            } else {
                $t .= "\n\n⚠️ لینک پرداخت از سرویس دریافت نشد؛ با «🔄 بررسی وضعیت» دوباره چک کنید.";
            }
            if ($note !== '') $t .= "\n\n" . $note;
            $t .= "\n\n👇 روی دکمهٔ «💳 پرداخت» بزنید تا به درگاه هدایت شوید.";
            $t .= "\nپس از پرداخت، خودکار تأیید می‌شود و همین‌جا خبرش را می‌دهیم. ✅";
            BotApi::send($TOKEN, $chatId, Ui::out($t), ['reply_markup' => PaymentPanel::invoiceKb($pid, $payUrl)]);
            return;
        }

        if ($method === Payments::METHOD_ZARIN) {
            if (!PaymentGateways::isEnabled($store, PaymentGateways::ZARIN)) { $fail("درگاه زرین‌پال غیرفعال است."); return; }
            if (!PaymentZarin::isConfigured($store, $cfg)) {
                $fail("درگاه زرین‌پال کامل پیکربندی نشده (کد پذیرنده ثبت نشده).");
                return;
            }
            $merchant = PaymentZarin::merchantId($store, $cfg);
            $sandbox = PaymentZarin::isSandbox($store);
            $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
            $cb = $base . PaymentZarin::callbackPath();
            if ($base === '') { $fail("آدرس پایهٔ پروژه (base_url) در config.php تنظیم نشده است."); return; }
            $res = PaymentZarin::request(
                $merchant,
                $amount,
                $cb,
                'پرداخت ربات‌ساز — #' . $pid,
                'PAY-' . $pid,
                $sandbox
            );
            if (empty($res['ok'])) {
                Logger::getInstance()->error('payment', "zarinpal request failed for #{$pid}: " . ($res['error'] ?? '?'));
                $fail("ساخت فاکتور زرین‌پال ناموفق بود:\n" . ($res['error'] ?? '') . "\nلطفاً دوباره تلاش کنید.");
                return;
            }
            $authority = (string)$res['authority'];
            $payUrl = PaymentZarin::startPayUrl($authority, $sandbox);
            Payments::setMethod($store, $pid, Payments::METHOD_ZARIN, Payments::ST_AWAIT_PAY, $authority, $payUrl);
            $note = PaymentGateways::note($store, PaymentGateways::ZARIN, [
                'amount' => number_format($amount) . ' تومان',
            ]);
            $t = "🟣 <b>پرداخت اینترنتی — درگاه زرین‌پال</b>\n\n"
                . Ui::kv('💰', 'مبلغ', Ui::toman($amount)) . "\n"
                . Ui::kv('🔢', 'شمارهٔ فاکتور', 'PAY-' . $pid, true);
            if ($sandbox) $t .= "\n" . Ui::kv('🧪', 'حالت', 'تست (sandbox) — پول واقعی جابه‌جا نمی‌شود');
            if ($note !== '') $t .= "\n\n" . $note;
            $t .= "\n\n👇 روی دکمهٔ «💳 پرداخت» بزنید تا به درگاه هدایت شوید.";
            $t .= "\n🔗 " . Ui::link($payUrl) . "\n\n"
                . "پس از پرداخت، خودکار تأیید می‌شود و همین‌جا خبرش را می‌دهیم. ✅";
            BotApi::send($TOKEN, $chatId, Ui::out($t), ['reply_markup' => PaymentPanel::invoiceKb($pid, $payUrl)]);
            return;
        }

        if ($method === Payments::METHOD_AQAYE) {
            if (!PaymentGateways::isEnabled($store, PaymentGateways::AQAYE)) { $fail("درگاه آقای پرداخت غیرفعال است."); return; }
            if (!PaymentAqaye::isConfigured($store, $cfg)) {
                $fail("درگاه آقای پرداخت کامل پیکربندی نشده (کد پین ثبت نشده).");
                return;
            }
            $pin = PaymentAqaye::pin($store, $cfg);
            $sandbox = PaymentAqaye::isSandbox($store);
            $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
            if ($base === '') { $fail("آدرس پایهٔ پروژه (base_url) در config.php تنظیم نشده است."); return; }
            $cb = $base . PaymentAqaye::callbackPath();
            $res = PaymentAqaye::create($pin, $amount, $cb, 'پرداخت ربات‌ساز', 'PAY-' . $pid);
            if (empty($res['ok'])) {
                Logger::getInstance()->error('payment', "aqaye create failed for #{$pid}: " . ($res['error'] ?? '?'));
                $fail("ساخت فاکتور آقای پرداخت ناموفق بود:\n" . ($res['error'] ?? '') . "\nلطفاً دوباره تلاش کنید.");
                return;
            }
            $transid = (string)$res['transid'];
            $payUrl = PaymentAqaye::startPayUrl($transid, $sandbox);
            Payments::setMethod($store, $pid, Payments::METHOD_AQAYE, Payments::ST_AWAIT_PAY, $transid, $payUrl);
            $note = PaymentGateways::note($store, PaymentGateways::AQAYE, [
                'amount' => number_format($amount) . ' تومان',
            ]);
            $t = "🧿 <b>پرداخت اینترنتی — درگاه آقای پرداخت</b>\n\n"
                . Ui::kv('💰', 'مبلغ', Ui::toman($amount)) . "\n"
                . Ui::kv('🔢', 'کد تراکنش', $transid, true);
            if ($sandbox) $t .= "\n" . Ui::kv('🧪', 'حالت', 'تست (sandbox) — پول واقعی جابه‌جا نمی‌شود');
            if ($note !== '') $t .= "\n\n" . $note;
            $t .= "\n\n👇 روی دکمهٔ «💳 پرداخت» بزنید تا به درگاه هدایت شوید.";
            $t .= "\n🔗 " . Ui::link($payUrl) . "\n\n"
                . "پس از پرداخت، خودکار تأیید می‌شود و همین‌جا خبرش را می‌دهیم. ✅";
            BotApi::send($TOKEN, $chatId, Ui::out($t), ['reply_markup' => PaymentPanel::invoiceKb($pid, $payUrl)]);
            return;
        }

        $fail("روش پرداخت نامعتبر است.");
        return;
    }

    $fail("درخواست نامعتبر است.");
}

// ================= پرداخت: پنل ادمین =================
// استعلامِ وضعیتِ فاکتورهای «تأیید خودکار» در تابع یکپارچهٔ verifyAutoPayment
// (بالاتر در همین فایل) انجام می‌شود؛ اینجا فقط کال‌بک‌های ادمین است.

/** همهٔ کال‌بک‌های ادمین (پیشوند payadmin:) — فعال/غیرفعال درگاه، متن دلخواه، قیمت، تأیید */
function handlePayAdminCallback(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, int $msgId, string $data): void
{
    $uid = (int)$user['user_id'];
    if (!isAdmin($user, $SUPERS)) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
    try { Payments::ensureSchema($store); } catch (Throwable $e) {}
    $parts = explode(':', $data);
    $sub = $parts[1] ?? '';

    // پنل اصلی
    if ($sub === 'panel' || $sub === '') { showPaymentsAdmin($store, $TOKEN, $chatId, $msgId); return; }

    // فعال/غیرفعال کردن درگاه
    if ($sub === 'toggle') {
        $key = (string)($parts[2] ?? '');
        if (!PaymentGateways::isValidKey($key)) { BotApi::send($TOKEN, $chatId, "⛔️ درگاه نامعتبر است."); return; }
        $now = PaymentGateways::toggle($store, $key);
        Logger::getInstance()->info('payment', "Admin {$uid} " . ($now ? 'enabled' : 'disabled') . " gateway {$key}");
        showPaymentsAdmin($store, $TOKEN, $chatId, $msgId);
        return;
    }

    // متن دلخواهٔ یک بخش
    if ($sub === 'text') {
        $key = (string)($parts[2] ?? '');
        if (!PaymentGateways::isValidKey($key)) { BotApi::send($TOKEN, $chatId, "⛔️ بخش نامعتبر است."); return; }
        $cur = PaymentGateways::hasCustomText($store, $key) ? PaymentGateways::customText($store, $key) : PaymentGateways::defaultText($key);
        $store->setStep($uid, 'await_pay_text', ['key' => $key]);
        BotApi::send($TOKEN, $chatId,
            "📝 <b>متن دلخواه — " . PaymentGateways::label($key) . "</b>\n\n"
            . "متن فعلی (پیش‌فرض):\n<code>" . htmlspecialchars($cur) . "</code>\n\n"
            . "متن جدید را بفرستید.\nمی‌توانید از این جای‌نگهدارها استفاده کنید: "
            . "<code>‹amount› ‹slots› ‹count› ‹limit› ‹remaining› ‹type›</code>\n"
            . "برای حذف متن و برگشت به پیش‌فرض، کلمهٔ <code>reset</code> را بفرستید.\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    // بازگردانی متن پیش‌فرض
    if ($sub === 'resettxt') {
        $key = (string)($parts[2] ?? '');
        if (!PaymentGateways::isValidKey($key)) { BotApi::send($TOKEN, $chatId, "⛔️ بخش نامعتبر است."); return; }
        PaymentGateways::resetText($store, $key);
        showPaymentsAdmin($store, $TOKEN, $chatId, $msgId);
        return;
    }

    // فهرست پرداخت‌های در انتظار بررسی + دکمهٔ تأیید/رد
    if ($sub === 'list') {
        $pms = Payments::pendingAdminList($store, 20);
        if (!$pms) { BotApi::send($TOKEN, $chatId, "✅ هیچ پرداختی در انتظار بررسی نیست.", ['reply_markup' => PaymentPanel::adminKb($store)]); return; }
        BotApi::send($TOKEN, $chatId, "🧾 <b>پرداخت‌های در انتظار</b> (" . count($pms) . "):");
        foreach ($pms as $p) {
            $ownerId = (int)$p['user_id'];
            $u = $store->user($ownerId);
            $name = trim((string)($u['first_name'] ?? ''));
            $t = Payments::describe($p) . "\n";
            $t .= "کاربر: <code>{$ownerId}</code>" . ($name !== '' ? " — " . htmlspecialchars($name) : "") . "\n";
            if (!empty($p['template'])) $t .= "قالب: <code>" . htmlspecialchars((string)$p['template']) . "</code>\n";
            $receipt = (string)($p['receipt'] ?? '');
            $decoded = null;
            if ($receipt !== '') {
                $decoded = json_decode($receipt, true);
                // متن رسید را کاربر می‌فرستد؛ بدون escape می‌توانست تگ HTML تزریق کند
                // (مثلاً لینک فیشینگ در پیام ادمین) یا کل پیام را خراب کند.
                $rtext = is_array($decoded) ? (string)($decoded['text'] ?? '') : $receipt;
                if ($rtext === '') $rtext = '(تصویر/فایل پیوست)';
                $t .= "رسید: " . htmlspecialchars($rtext) . "\n";
                if (is_array($decoded) && !empty($decoded['has_attachment'])) $t .= "📎 پیوست دارد (عکس/فایل)\n";
            }
            // پیوست را اینجا دوباره می‌فرستیم تا ادمین لازم نباشد به لاگ پیام قبلی برگردد
            $attId = is_array($decoded) ? trim((string)($decoded['file_id'] ?? '')) : '';
            $attKind = is_array($decoded) ? (string)($decoded['kind'] ?? '') : '';
            if (!empty($p['pay_url'])) $t .= "لینک پرداخت: " . htmlspecialchars((string)$p['pay_url']) . "\n";
            BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => PaymentPanel::reviewKb((int)$p['id'], (string)$p['method'])]);
            if ($attId !== '') {
                try {
                    if ($attKind === 'document') {
                        BotApi::sendDocumentById($TOKEN, $chatId, $attId, "🧾 فیش پرداخت #{$p['id']}");
                    } else {
                        BotApi::sendPhoto($TOKEN, $chatId, $attId, "🧾 فیش پرداخت #{$p['id']}");
                    }
                } catch (Throwable $e) {
                    Logger::getInstance()->warning('payment', "receipt #{$p['id']}: attachment resend failed");
                }
            }
            usleep(80000);
        }
        return;
    }

    // تأیید دستی (کارت‌به‌کارت)
    if ($sub === 'approve') {
        $pid = (int)($parts[2] ?? 0);
        $p = $pid > 0 ? Payments::approveByAdmin($store, $pid) : null;
        if (!$p) { BotApi::send($TOKEN, $chatId, "⛔️ پرداخت یافت نشد یا قبلاً نهایی شده است."); return; }
        $ownerId = (int)$p['user_id'];
        $note = (string)($p['grant_note'] ?? '');
        BotApi::send($TOKEN, $chatId, "✅ پرداخت #{$pid} تأیید شد.\n{$note}", ['reply_markup' => PaymentPanel::adminKb($store)]);
        BotApi::send($TOKEN, $ownerId,
            "✅ <b>پرداخت شما تأیید شد!</b>\n{$note}\n\nحالا می‌توانید «🤖 ساخت ربات جدید» را بزنید.",
            ['reply_markup' => mainMenu($store->user($ownerId), $SUPERS, $store)]);
        return;
    }

    // رد دستی
    if ($sub === 'decline') {
        $pid = (int)($parts[2] ?? 0);
        $p = $pid > 0 ? Payments::getPayment($store, $pid) : null;
        if (!$p) { BotApi::send($TOKEN, $chatId, "⛔️ پرداخت یافت نشد."); return; }
        if (in_array($p['status'], [Payments::ST_PAID, Payments::ST_USED], true)) {
            BotApi::send($TOKEN, $chatId, "⛔️ این پرداخت قبلاً نهایی شده و قابل رد نیست."); return;
        }
        if (in_array($p['status'], [Payments::ST_CANCELLED, Payments::ST_EXPIRED], true)) {
            BotApi::send($TOKEN, $chatId, "ℹ️ این پرداخت قبلاً بسته شده است.", ['reply_markup' => PaymentPanel::adminKb($store)]); return;
        }
        $ownerId = (int)$p['user_id'];

        // ===== فاکتور «تأیید خودکار»: قبل از رد، وضعیت واقعی از سرویس پرسیده شود =====
        // بدون این بررسی، «رد» روی فاکتوری که کاربر واقعاً پرداخت کرده، پولش را می‌بلعید:
        // بعد از declined شدن ردیف، markAutoPaid دیگر آن را نمی‌پذیرد.
        if (PaymentGateways::isAutoConfirmed((string)$p['method']) && $p['status'] === Payments::ST_AWAIT_PAY) {
            $chk = verifyAutoPayment($cfg, $store, $p);
            if (!$chk['ok']) {
                // سرویس در دسترس نیست ⇒ خطر بلعیدن پول؛ اجازهٔ رد نمی‌دهیم
                BotApi::send($TOKEN, $chatId,
                    "⛔️ <b>استعلام از درگاه ناموفق بود</b>؛ برای جلوگیری از ردِ اشتباه، عملیات متوقف شد.\n"
                    . Ui::quote(Ui::e((string)$chk['error']))
                    . "\n\nلطفاً چند دقیقه بعد دوباره تلاش کنید.",
                    ['reply_markup' => PaymentPanel::reviewKb($pid, (string)$p['method'])]);
                return;
            }
            if ($chk['paid']) {
                // پول رفته ⇒ تأییدش کن، نه ردش
                if (!empty($chk['payment_id'])) {
                    try { Payments::setExtId($store, $pid, $chk['payment_id']); } catch (Throwable $e) {}
                }
                $done = Payments::markAutoPaid($store, $pid);
                if (!$done) { BotApi::send($TOKEN, $chatId, "⛔️ وضعیت پرداخت تغییر کرد؛ دوباره بررسی کنید."); return; }
                $note = (string)($done['grant_note'] ?? '');
                BotApi::send($TOKEN, $chatId,
                    "⚠️ این فاکتور در سرویس <b>پرداخت‌شده</b> بود، بنابراین رد نشد و تأیید شد.\n" . $note,
                    ['reply_markup' => PaymentPanel::adminKb($store)]);
                BotApi::send($TOKEN, $ownerId,
                    "✅ <b>پرداخت شما تأیید شد!</b>\n{$note}\n\n🚀 حالا می‌توانید «🤖 ساخت ربات جدید» را بزنید.",
                    ['reply_markup' => mainMenu($store->user($ownerId), $SUPERS, $store)]);
                return;
            }
            BotApi::send($TOKEN, $chatId,
                "ℹ️ وضعیت این فاکتور در سرویس: " . Ui::code($chk['status'] !== '' ? $chk['status'] : 'نامشخص') . " (پرداخت نشده)\n\n"
                . "اگر مطمئنید کاربر پول کسر نکرده، دوباره «❌ رد» را بزنید.",
                ['reply_markup' => PaymentPanel::reviewKb($pid, (string)$p['method'])]);
            return;
        }

        Payments::setStatus($store, $pid, Payments::ST_DECLINED);
        BotApi::send($TOKEN, $chatId, "❌ پرداخت #{$pid} رد شد.", ['reply_markup' => PaymentPanel::adminKb($store)]);
        BotApi::send($TOKEN, $ownerId, "❌ پرداخت شما رد شد. اگر پول کسر شده، با ادمین تماس بگیرید.");
        return;
    }

    // ===== استعلام دستی فاکتور «تأیید خودکار» (پشتیبانِ نرسیدن کال‌بک) =====
    if ($sub === 'verify') {
        $pid = (int)($parts[2] ?? 0);
        $p = $pid > 0 ? Payments::getPayment($store, $pid) : null;
        if (!$p) { BotApi::send($TOKEN, $chatId, "⛔️ پرداخت یافت نشد."); return; }
        if (in_array($p['status'], [Payments::ST_PAID, Payments::ST_USED], true)) {
            BotApi::send($TOKEN, $chatId, "✅ این پرداخت قبلاً تأیید شده است:\n" . Payments::describe($p),
                ['reply_markup' => PaymentPanel::adminKb($store)]);
            return;
        }
        if (!PaymentGateways::isAutoConfirmed((string)$p['method'])) {
            BotApi::send($TOKEN, $chatId, "ℹ️ این پرداخت کارت‌به‌کارت است و استعلام خودکار ندارد؛ باید دستی بررسی شود.\n\n"
                . Payments::describe($p), ['reply_markup' => PaymentPanel::reviewKb($pid, (string)$p['method'])]);
            return;
        }
        $chk = verifyAutoPayment($cfg, $store, $p);
        if (!$chk['ok']) {
            BotApi::send($TOKEN, $chatId, "⛔️ استعلام ناموفق بود:\n" . Ui::quote(Ui::e((string)$chk['error'])),
                ['reply_markup' => PaymentPanel::reviewKb($pid, (string)$p['method'])]);
            return;
        }
        if (!empty($chk['payment_id']) && $chk['payment_id'] !== (string)($p['ext_id'] ?? '')) {
            try { Payments::setExtId($store, $pid, $chk['payment_id']); } catch (Throwable $e) {}
        }
        if (!$chk['paid']) {
            BotApi::send($TOKEN, $chatId,
                "🕐 وضعیت در سرویس: " . Ui::code($chk['status'] !== '' ? $chk['status'] : 'نامشخص') . " — هنوز پرداخت نشده.",
                ['reply_markup' => PaymentPanel::reviewKb($pid, (string)$p['method'])]);
            return;
        }
        $done = Payments::markAutoPaid($store, $pid);
        if (!$done) { BotApi::send($TOKEN, $chatId, "⛔️ پرداخت قابل اعمال نبود (وضعیت تغییر کرده)."); return; }
        $note = (string)($done['grant_note'] ?? '');
        $ownerId = (int)$done['user_id'];
        BotApi::send($TOKEN, $chatId, "✅ پرداخت #{$pid} تأیید شد.\n{$note}",
            ['reply_markup' => PaymentPanel::adminKb($store)]);
        BotApi::send($TOKEN, $ownerId,
            "✅ <b>پرداخت شما تأیید شد!</b>\n{$note}\n\n🚀 حالا می‌توانید «🤖 ساخت ربات جدید» را بزنید.",
            ['reply_markup' => mainMenu($store->user($ownerId), $SUPERS, $store)]);
        return;
    }

    // ===== 💵 نرخ دلار: تازه‌سازی از API / بازگشت به حالت خودکار =====
    if ($sub === 'fxrefresh') {
        BotApi::edit($TOKEN, $chatId, $msgId, "🌍 <b>در حال گرفتن نرخ دلار…</b>\n\nلطفاً چند ثانیه صبر کنید.");
        $res = FxRate::refresh($store, 8);
        if (!empty($res['ok'])) {
            Logger::getInstance()->info('fx', "rate refreshed: " . $res['rate'] . ' from ' . $res['source']);
            $tried = '';
            foreach ((array)($res['tried'] ?? []) as $tr) {
                if (empty($tr['ok']) && !empty($tr['error'])) $tried .= Ui::bullet('⚠️', Ui::e((string)$tr['name']) . ' — ' . Ui::e((string)$tr['error']));
            }
            $note = "✅ <b>نرخ دلار به‌روز شد!</b>\n\n"
                . Ui::kv('💵', 'هر دلار', FxRate::format((float)$res['rate']))
                . "\n" . Ui::kv('📡', 'سرویس', (string)$res['source'])
                . "\n" . Ui::kv('🔄', 'حالت', 'خودکار (از API)')
                . ($tried !== '' ? "\n\n" . Ui::quote("سرویس‌های دیگر:\n" . $tried) : '');
        } else {
            $detail = '';
            foreach ((array)($res['tried'] ?? []) as $tr) {
                $detail .= Ui::bullet('⚠️', Ui::e((string)$tr['name']) . ' — ' . Ui::e((string)($tr['error'] ?: '?')));
            }
            $note = "⚠️ <b>نرخ دلار از هیچ سرویسی گرفته نشد.</b>\n\n"
                . "نرخِ ذخیره‌شدهٔ فعلی همچنان استفاده می‌شود: <b>"
                . FxRate::format(FxRate::stored($store)) . "</b>\n\n"
                . ($detail !== '' ? Ui::quote("سرویس‌های امتحان‌شده:\n" . $detail) : '')
                . "\n💡 می‌توانید نرخ را دستی هم بگذارید: «💵 نرخ دلار (دستی)».";
        }
        showPaymentsAdmin($store, $TOKEN, $chatId, $msgId, $note);
        return;
    }
    if ($sub === 'fxauto') {
        PaymentPricing::setUsdRateAuto($store);
        $res = FxRate::refresh($store, 8);
        $note = !empty($res['ok'])
            ? "🔄 <b>نرخ دلار خودکار روشن شد.</b>\n\n"
                . Ui::kv('💵', 'هر دلار', FxRate::format((float)$res['rate']))
                . "\n" . Ui::kv('📡', 'سرویس', (string)$res['source'])
                . "\n\nاز این به بعد نرخ هر ۶ ساعت خودکار تازه می‌شود."
            : "🔄 <b>نرخ دلار خودکار روشن شد</b> ولی سرویسی پاسخ نداد.\n\n"
                . "نرخ فعلی: <b>" . FxRate::format(FxRate::stored($store)) . "</b>\n"
                . "با دکمهٔ «🔄 تازه‌سازی نرخ» دوباره تلاش کنید.";
        showPaymentsAdmin($store, $TOKEN, $chatId, $msgId, $note);
        return;
    }

    // ===== 🟣 تنظیم درگاه زرین‌پال =====
    if ($sub === 'zarin') {
        $store->setStep($uid, 'await_pay_zarin_merchant');
        $mid = PaymentZarin::merchantId($store, $cfg);
        $cb = rtrim((string)($cfg['base_url'] ?? ''), '/') . PaymentZarin::callbackPath();
        BotApi::send($TOKEN, $chatId,
            "🟣 <b>تنظیم درگاه زرین‌پال</b>\n\n"
            . "کد ۳۶ کاراکتری پذیرنده را از پنل زرین‌پال کپی کنید و بفرستید.\n\n"
            . "کد فعلی: " . ($mid !== '' ? Ui::code(mb_substr($mid, 0, 10) . '…' . mb_substr($mid, -6)) : '<i>ثبت نشده</i>') . "\n"
            . "حالت: " . (PaymentZarin::isSandbox($store) ? '🧪 تست (sandbox)' : 'واقعی') . "\n\n"
            . "📍 <b>آدرس بازگشت</b> که باید در پنل زرین‌پال ثبت کنید:\n"
            . "   " . Ui::link($cb) . "\n   " . Ui::code($cb) . "\n\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    // ===== 🧿 تنظیم درگاه آقای پرداخت =====
    if ($sub === 'aqaye') {
        $store->setStep($uid, 'await_pay_aqaye_pin');
        $pin = PaymentAqaye::pin($store, $cfg);
        $cb = rtrim((string)($cfg['base_url'] ?? ''), '/') . PaymentAqaye::callbackPath();
        BotApi::send($TOKEN, $chatId,
            "🧿 <b>تنظیم درگاه آقای پرداخت</b>\n\n"
            . "کد پین درگاه را از پنل آقای پرداخت کپی کنید و بفرستید.\n\n"
            . "کد فعلی: " . ($pin !== '' ? Ui::code(mb_substr($pin, 0, 4) . '…' . mb_substr($pin, -3)) : '<i>ثبت نشده</i>') . "\n\n"
            . "📍 <b>دامنهٔ مجاز بازگشت</b> که باید در پنل درگاه ثبت شده باشد:\n"
            . "   " . Ui::link($cb) . "\n   " . Ui::code($cb) . "\n\n"
            . "ℹ️ اگر درگاه شما روی دامنهٔ دیگری تأیید شده، آدرس بالا باید همان باشد.\n\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    // ورودی‌های عددی/متنی ⇒ مرحلهٔ انتظار
    if ($sub === 'setprice') {
        $type = (string)($parts[2] ?? '');
        if (!isset(Manager::validTypes()[$type])) { BotApi::send($TOKEN, $chatId, "⛔️ قالب نامعتبر است."); return; }
        $store->setStep($uid, 'await_pay_price', ['type' => $type]);
        BotApi::send($TOKEN, $chatId,
            "💰 <b>قیمت قالب «{$type}»</b>\n\nمبلغ به تومان را بفرستید (0 = رایگان).\n"
            . "قیمت فعلی: <b>" . PaymentPricing::formatToman(PaymentPricing::templatePrice($store, $type)) . "</b>\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($sub === 'limitprice') {
        $store->setStep($uid, 'await_pay_limit_price');
        BotApi::send($TOKEN, $chatId,
            "📈 <b>قیمت هر اسلات لیمیت</b>\n\nمبلغ به تومان را بفرستید.\n"
            . "قیمت فعلی: <b>" . PaymentPricing::formatToman(PaymentPricing::limitUnitPrice($store)) . "</b>\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($sub === 'usdrate') {
        $store->setStep($uid, 'await_pay_usdrate');
        $rate = FxRate::stored($store);
        $auto = FxRate::isAuto($store);
        BotApi::send($TOKEN, $chatId,
            "💵 <b>نرخ تومان به دلار (دستی)</b>\n\n"
            . "عدد را بفرستید؛ یعنی هر دلار چند تومان است. مثال: <code>100000</code>\n\n"
            . "⚠️ با ذخیرهٔ دستی، حالت خودکار <b>خاموش</b> می‌شود و همین نرخ قفل می‌ماند.\n"
            . "برای برگشتن به نرخِ زندهٔ بازار، از «🔄 نرخ خودکار (API)» استفاده کنید.\n\n"
            . "💵 نرخ فعلی: <b>" . number_format($rate) . "</b> تومان\n"
            . Ui::bullet(FxRate::isAuto($store) ? '🔄' : '🔒', 'حالت: ' . ($auto ? 'خودکار (API)' : 'دستی / قفل‌شده'))
            . "\n" . Ui::bullet('📡', 'سرویس: ' . Ui::e(FxRate::source($store) ?: '—'))
            . "\n" . Ui::bullet('🕒', 'به‌روزرسانی: ' . Ui::e(FxRate::ageText($store)))
            . "\n\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($sub === 'card') {
        $store->setStep($uid, 'await_pay_card');
        BotApi::send($TOKEN, $chatId,
            "💳 <b>شماره کارت</b>\n\nشماره کارت را بفرستید (اعداد، خط‌تیره یا فاصله).\n"
            . "کارت فعلی: <code>" . htmlspecialchars(PaymentCard::getCardNumber($store) ?: 'ثبت نشده') . "</code>\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($sub === 'nowpay') {
        $store->setStep($uid, 'await_pay_nowpay_key');
        BotApi::send($TOKEN, $chatId,
            "🪙 <b>کلید API نوب‌پیمنت</b>\n\nکلید API را بفرستید.\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($sub === 'setlimit') {
        $store->setStep($uid, 'await_pay_setlimit');
        BotApi::send($TOKEN, $chatId,
            "👤 <b>تعیین لیمیت کاربر</b>\n\nقالب ارسال: <code>ID_KARBAR 5</code>\n"
            . "مثال: <code>1234567 3</code> یعنی حداکثر ۳ ربات.\n"
            . "مقدار <code>-1</code> یعنی نامحدود و <code>0</code> یعنی مسدود.\n"
            . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    BotApi::send($TOKEN, $chatId,
        Texts::get($store, 'invalid', ['input' => htmlspecialchars(mb_substr((string)$data, 0, 80), ENT_QUOTES, 'UTF-8')]),
        ['reply_markup' => PaymentPanel::adminKb($store)]);
}
/**
 * گزارش عیب‌یابی سیستم (فقط ادمین).
 * خروجی دقیق و قابل‌اکشن است: پوشه‌ها + پرمیشن، پسوندهای PHP، دیتابیس،
 * دیسک، وبهوک (با timeout کوتاه تا خودِ دیاگنوز هنگ نکند) و وضعیت کرون/پرداخت.
 */
function showDiagnostics(Store $store, array $cfg, string $TOKEN, $chatId, int $msgId = 0): void
{
    $lines = [];
    $lines[] = "🖥️ <b>سیستم</b>\n"
        . Manager::versionLine() . "\n"
        . "PHP: " . PHP_VERSION . "\n"
        . "SAPI: " . PHP_SAPI . "\n"
        . "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n"
        . "OS: " . (PHP_OS ?: 'unknown');

    // اگر opcache یا «کپی دیگرِ پروژه» باعث اجرای کد کهنه شده باشد اینجا دیده می‌شود:
    // نسخهٔ اجراشده (این کد) با نسخهٔ فایل‌های روی دیسک فرق دارد.
    $diskVer = Manager::diskVersion(__DIR__);
    if ($diskVer !== '' && $diskVer !== Manager::APP_VERSION) {
        $lines[] = "⚠️ <b>نسخهٔ روی دیسک با نسخهٔ در حال اجرا فرق دارد</b>\n"
            . "روی دیسک: <code>" . htmlspecialchars($diskVer, ENT_QUOTES, 'UTF-8') . "</code>\n"
            . "یعنی یک کپی دیگرِ پروژه اجرا می‌شود یا opcache کهنه مانده ⇒ "
            . "سرویس‌ها را ری‌استارت کنید و مسیر اجرا را چک کنید.";
    }

    // ---- پسوندهای لازم ----
    $exts = ['curl' => 'curl', 'pdo_sqlite' => 'pdo_sqlite', 'pdo_mysql' => 'pdo_mysql',
             'openssl' => 'openssl', 'mbstring' => 'mbstring', 'json' => 'json'];
    $eLine = "🧩 <b>پسوندها</b>\n";
    foreach ($exts as $ext => $label) {
        $eLine .= (extension_loaded($ext) ? "✅" : "❌") . " {$label}\n";
    }
    if (!extension_loaded('pdo_sqlite') && !extension_loaded('pdo_mysql')) {
        $eLine .= "⚠️ هیچ درایور PDO وجود ندارد ⇒ دیتابیس مدیریتی کار نمی‌کند.\n";
    }
    $lines[] = $eLine;

    // ---- پوشه‌ها + پرمیشن ----
    $dirs = [
        'ROOT_DIR' => __DIR__,
        'bots/' => __DIR__ . '/bots',
        'data/' => __DIR__ . '/data',
        'data/logs/' => __DIR__ . '/data/logs',
    ];
    $dLine = "📁 <b>پوشه‌ها</b>\n";
    foreach ($dirs as $name => $path) {
        if (!is_dir($path)) {
            $dLine .= "❌ {$name}: وجود ندارد (<code>" . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . "</code>)\n";
        } elseif (!is_writable($path)) {
            $p = substr(sprintf('%o', @fileperms($path)), -4);
            $dLine .= "❌ {$name}: فقط‌خواندنی ({$p}) — ساخت ربات/لاگ شکست می‌خورد\n";
        } else {
            $p = substr(sprintf('%o', @fileperms($path)), -4);
            $dLine .= "✅ {$name}: OK ({$p})\n";
        }
    }
    $lines[] = $dLine;

    // ---- لاگ امروز (فایل ممکن است هنوز ساخته نشده باشد؛ معیار، پوشه است) ----
    $logFile = __DIR__ . '/data/logs/' . date('Y-m-d') . '.log';
    $logDir  = dirname($logFile);
    $logOk = is_file($logFile) ? is_writable($logFile) : (is_dir($logDir) && is_writable($logDir));
    $logSize = is_file($logFile) ? round(filesize($logFile) / 1024, 1) . ' KB' : '—';
    $lines[] = "📝 <b>لاگ امروز</b>: " . ($logOk ? '✅' : '❌')
        . " {$logFile} ({$logSize})"
        . ($logOk ? '' : "\n⚠️ لاگ قابل نوشتن نیست ⇒ خطاهای ربات دیده نمی‌شود.");

    // ---- دیتابیس مدیریتی ----
    try {
        $pdo = new PDO(
            "mysql:host={$cfg['db_host']};port=" . ($cfg['db_port'] ?? 3306) . ";charset=utf8mb4",
            $cfg['db_user'], $cfg['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $pdo->query("SELECT 1")->fetchColumn();
        $lines[] = "🗄️ MySQL (" . htmlspecialchars((string)$cfg['db_host'], ENT_QUOTES, 'UTF-8')
            . ":" . (int)($cfg['db_port'] ?? 3306) . "): ✅ Connected";
    } catch (Throwable $e) {
        $lines[] = "🗄️ MySQL: ❌ " . htmlspecialchars(Manager::sanitizeDbError($e->getMessage()), ENT_QUOTES, 'UTF-8');
    }
    $mdb = (string)($cfg['manager_db'] ?? '');
    $lines[] = "🗃 دیتابیس مدیریتی: " . ($mdb !== '' && is_file($mdb)
        ? '✅ ' . round(filesize($mdb) / 1024, 1) . ' KB — <code>' . htmlspecialchars($mdb, ENT_QUOTES, 'UTF-8') . '</code>'
        : '⚠️ فایل یافت نشد: <code>' . htmlspecialchars($mdb, ENT_QUOTES, 'UTF-8') . '</code>');

    // ---- دیسک ----
    $disk = @disk_free_space(__DIR__);
    $lines[] = "💾 فضای دیسک آزاد: " . ($disk !== false ? round($disk / 1048576, 1) . ' MB' : 'نامشخص');

    // ---- وبهوک (timeout کوتاه؛ خودِ دیاگنوز نباید معطل شود) ----
    $wh = "❓ نامشخص";
    if (!empty($cfg['main_token']) && $cfg['main_token'] !== 'PUT_MAIN_BOT_TOKEN_HERE') {
        $info = quickTelegramJson((string)$cfg['main_token'], 'getWebhookInfo', 8);
        if (!empty($info['ok'])) {
            $url = (string)($info['result']['url'] ?? '');
            $pend = (int)($info['result']['pending_update_count'] ?? 0);
            $err  = (string)($info['result']['last_error_message'] ?? '');
            $wh = ($url !== '' ? $url : '❌ ست نشده')
                . " (در انتظار: {$pend})"
                . ($err !== '' ? "\n⚠️ آخرین خطای تلگرام: {$err}" : '');
        } else {
            $wh = '❌ ' . htmlspecialchars((string)($info['error'] ?? 'در دسترس نیست'), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $wh = '❌ توکن اصلی خالی/placeholder است';
    }
    $lines[] = "🔗 <b>وبهوک</b>: {$wh}";

    // ---- کرون ----
    $cs = __DIR__ . '/data/cron_state.json';
    if (is_file($cs)) {
        $st = json_decode((string)@file_get_contents($cs), true) ?: [];
        $last = strtotime((string)($st['last_run'] ?? '') ?: '');
        $age = $last ? (int)round((time() - $last) / 60) : -1;
        $lines[] = "⏰ کرون: " . ($age >= 0
            ? (($age <= 15 ? '✅' : '⚠️') . " آخرین اجرا {$age} دقیقه پیش")
            : '⚠️ وضعیت نامشخص');
    } else {
        $lines[] = "⏰ کرون: ⚠️ هنوز اجرا نشده (<code>data/cron_state.json</code> نیست)";
    }

    // ---- پرداخت ----
    try {
        Payments::ensureSchema($store);
        $lines[] = "💳 پرداخت در انتظار بررسی: <b>" . Payments::pendingAdminCount($store) . "</b>"
            . " • سقف‌های کاربران: <b>" . count($store->allowedIds()) . "</b> مجاز";
    } catch (Throwable $e) {
        $lines[] = "💳 پرداخت: ❌ " . htmlspecialchars(Manager::sanitizeDbError($e->getMessage()), ENT_QUOTES, 'UTF-8');
    }

    $text = implode("\n", $lines);
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000) . "\n…";
    $kb = BotApi::ikb([
        [['text' => '🔄 بررسی دوباره', 'callback_data' => 'diag:run']],
        [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]],
    ]);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $text, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $text, ['reply_markup' => $kb]);
}

/**
 * درخواست GET کوتاه‌مدت به API تلگرام — فقط برای دیاگنوز.
 * BotApi::call تا ۳ بار retry با backoff دارد (ممکن است ~۹۰ ثانیه طول بکشد)؛
 * دکمهٔ دیاگنوز نباید وبهوک را معطل کند، پس اینجا یک curl ساده با timeout کوتاه.
 */
function quickTelegramJson(string $token, string $method, int $timeout = 8): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl در دسترس نیست'];
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
    ]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) return ['ok' => false, 'error' => 'شبکه: ' . $err];
    $j = json_decode((string)$out, true);
    return is_array($j) ? $j : ['ok' => false, 'error' => 'پاسخ نامعتبر از تلگرام'];
}

/** پنل وضعیت کرون مرکزی — با دادهٔ واقعی، نه متن ثابت.
 * tools/cron_dispatcher.php بعد از هر اجرا data/cron_state.json را به‌روزرسانی می‌کند؛
 * اینجا خوانده می‌شود و «سالم / خاموش» با دلیل اعلام می‌گردد.
 * قبلاً این دکمه فقط یک متن ثابت (و با مسیر جعلی /path/to/...) نشان می‌داد
 * و ادعای دروغی «از درخواست‌ها وضعیت کرون را ببین» داشت.
 */
function showCronPanel(array $cfg, string $TOKEN, $chatId, int $msgId = 0): void
{
    $script    = __DIR__ . '/tools/cron_dispatcher.php';
    $stateFile = __DIR__ . '/data/cron_state.json';
    $lines     = ["⏰ <b>کرون مرکزی (ربات‌های فرزند + بکاپ + پاکسازی)</b>\n"];

    if (!is_file($script)) {
        $lines[] = "❌ اسکریپت کرون پیدا نشد: <code>" . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . "</code>";
    } elseif (!is_readable($script)) {
        $lines[] = "❌ اسکریپت کرون قابل خواندن نیست (پرمیشن): <code>" . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . "</code>";
    } else {
        $lines[] = "✅ اسکریپت: <code>" . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . "</code>";
    }

    $state = [];
    if (is_file($stateFile)) {
        $raw = @file_get_contents($stateFile);
        $state = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    if (empty($state['last_run'])) {
        $lines[] = "🔴 هنوز هیچ اجرایی ثبت نشده است؛ کرون سیستم را راه‌اندازی کنید.";
        $lines[] = "ℹ️ اگر کرون را تازه نصب کرده‌اید، اولین اجرا تا ۵ دقیقهٔ دیگر رخ می‌دهد.";
    } else {
        $last = strtotime((string)$state['last_run']);
        $age  = ($last !== false) ? max(0, time() - $last) : -1;
        $ageTxt = $age < 0 ? 'زمان نامشخص'
            : ($age < 90 ? $age . ' ثانیه' : (int)round($age / 60) . ' دقیقه');
        // کرون پیشنهادی هر ۵ دقیقه است ⇒ بیش از ۱۵ دقیقه وقفه یعنی احتمالاً خاموش
        $fresh = ($age >= 0 && $age <= 900);
        $lines[] = ($fresh ? '🟢' : '🔴') . " آخرین اجرا: <b>{$ageTxt} پیش</b> — <code>"
            . htmlspecialchars((string)$state['last_run'], ENT_QUOTES, 'UTF-8') . "</code>";
        if (isset($state['duration_ms'])) $lines[] = "⏳ مدت آخرین اجرا: " . (int)$state['duration_ms'] . " ms";
        if (isset($state['bots']))        $lines[] = "🤖 ربات‌های پردازش‌شده: " . (int)$state['bots'];
        if (!empty($state['error'])) {
            $lines[] = "⚠️ آخرین خطا: " . htmlspecialchars((string)$state['error'], ENT_QUOTES, 'UTF-8');
        }
        if (!$fresh) {
            $lines[] = "⚠️ بیش از ۱۵ دقیقه است کرون اجرا نشده؛ crontab را بررسی کنید یا لاگ را ببینید.";
        }
    }

    $phpBin = trim((string)($cfg['php_bin'] ?? 'php'));
    if ($phpBin === '') $phpBin = 'php';
    $lines[] = "\n🧾 <b>نصب crontab</b> (هر ۵ دقیقه):\n"
        . "<code>*/5 * * * * " . htmlspecialchars($phpBin, ENT_QUOTES, 'UTF-8')
        . " " . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . " >> /dev/null 2>&1</code>";
    $lines[] = "\n📁 وضعیت در: <code>data/cron_state.json</code> — لاگ کامل در <code>data/logs/</code>";

    $kb = BotApi::ikb([
        [['text' => '🔄 بررسی دوباره', 'callback_data' => 'cron:status']],
        [['text' => '🔍 دیاگنوز سیستم', 'callback_data' => 'diag:run']],
        [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]],
    ]);
    $text = implode("\n", $lines);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $text, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $text, ['reply_markup' => $kb]);
}

/** پنل بکاپ دیتابیس — وضعیت + دکمه‌های کنترل ساعت (فقط ادمین) */
function showBackupPanel(array $cfg, Store $store, string $TOKEN, $chatId, int $msgId = 0): void
{
    $set = DbBackup::settings($cfg, $store);
    $state = DbBackup::readState();
    $status = $set['enabled'] ? '🟢 فعال' : '🔴 غیرفعال';
    $times = implode(' و ', $set['times']);
    $t = "💾 <b>بکاپ خودکار دیتابیس</b>\n\nوضعیت: {$status}\nساعت‌ها (به وقت سرور): <code>{$times}</code>\n";
    $t .= "مقصد: دیتابیس هر ربات → ادمین همان ربات\n\n";
    $bots = array_filter($store->allBots(), fn($b) => ($b['status'] ?? '') === 'active' && !empty($b['db_name']));
    if ($bots === []) {
        $t .= "ربات فعالی با دیتابیس ثبت نشده.\n";
    } else {
        foreach (array_slice($bots, 0, 20) as $b) {
            $f = (string)$b['folder'];
            $last = (string)($state[$f]['last_sent'] ?? '');
            $t .= ($last === '' ? "⏳ {$f}: هنوز ارسال نشده\n" : "✅ {$f}: {$last}\n");
        }
    }
    $t .= "\nکرون: <code>0 3,15 * * * php .../tools/backup_dispatcher.php</code>\n(کرون ۵دقیقه‌ای هم اسلات‌ها را خودش چک می‌کند)";
    $kb = Nav::backupPanelKb((bool)$set['enabled']);
    if ($msgId > 0) {
        BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb, 'parse_mode' => 'HTML']);
    } else {
        BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
    }
}

/** نمایش لیست درخواست‌های در انتظار تأیید — مشترک بین callback و دکمهٔ کیبورد */
function sendPendingRequests(Store $store, string $TOKEN, $chatId): void
{
    $requests = $store->getPendingRequests();
    if (!$requests) {
        BotApi::send($TOKEN, $chatId,
            "✅ <b>هیچ درخواستِ در انتظاری وجود ندارد.</b>\n\nهمه‌چیز مرتب است 🌿",
            ['reply_markup' => BotApi::ikb([[['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]]])]);
        return;
    }
    $total = count($requests);
    $slice = array_slice($requests, 0, 10);
    $note = $total > count($slice)
        ? "\n\nℹ️ نمایش " . count($slice) . " درخواست از {$total}؛ بعدی‌ها با تأیید/رد کردن همین‌ها ظاهر می‌شوند."
        : '';
    BotApi::send($TOKEN, $chatId,
        "📋 <b>درخواست‌های ساخت ربات</b> ({$total})\n" . Ui::sep()
        . "\n\nبا «✅ تأیید» کاربر مستقیم می‌تواند ربات بسازد؛ با «❌ رد» درخواست بسته می‌شود." . $note);
    foreach ($slice as $r) {
        $name = htmlspecialchars((string)($r['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (trim($name) === '') $name = '<i>بدون نام</i>';
        $username = trim((string)($r['username'] ?? '')) !== '' ? '@' . (string)$r['username'] : '';
        $t = "👤 <b>{$name}</b>\n"
            . Ui::kv('🆔', 'آیدی کاربر', (string)$r['user_id'], true)
            . ($username !== '' ? "\n" . Ui::kv('📛', 'یوزرنیم', $username) : '')
            . "\n" . Ui::kv('🤖', 'نوع درخواست', (string)($r['type'] ?? '—'))
            . "\n" . Ui::kv('📅', 'زمان ثبت', (string)($r['created_at'] ?? '—'));
        BotApi::send($TOKEN, $chatId, $t,
            ['reply_markup' => BotApi::ikb([
                [['text' => '✅ تأیید', 'callback_data' => "act:approve:{$r['id']}"], ['text' => '❌ رد', 'callback_data' => "act:decline:{$r['id']}"]],
                [['text' => '🔄 صف بعدی', 'callback_data' => 'users:requests']],
            ])]);
        usleep(100000);
    }
}

// ============ 🔄 دریافت سورس بروز (فقط سوپرادمین) ============
//
// این بخش دیگر هیچ رباتِ ساخته‌شده‌ای را تغییر نمی‌دهد. ربات‌های فرزند «کپی»
// قالب‌اند و کدشان همان نسخه‌ای می‌ماند که در لحظهٔ ساخت کپی شده؛ به‌روزرسانیِ
// کدِ ربات‌های موجود عمداً حذف شده تا هم حریم خصوصی کاربران (config و
// دیتابیس‌شان) دست‌نخورده بماند و هم خرابیِ احتمالیِ یک آپدیت به ربات آنها
// نرسد.
//
// کارِ این بخش فقط یک چیز است: کشیدنِ آخرین سورس از گیت و کپیِ «templates/»
// روی زنده، تا هرکس بعداً ربات بسازد نسخهٔ تازهٔ قالب را بگیرد. هر قالب با
// لینکِ گیتهابِ خودش (کلید repo در رجیستری) چک و از همان‌جا هم تازه می‌شود:
//
//   • دسترسی: فقط سوپرادمین (نه ادمین معمولی) و همیشه با تأیید صریح.
//   • بکاپِ قالب‌هایی که قرار است عوض شوند قبل از اجرا ساخته و «فقط» برای همان
//     سوپرادمینی که دکمه را زده فرستاده می‌شود.
//   • بدون دست‌زدن به کدِ ربات‌ساز، دیتابیس، وبهوک و وی‌هوست و بدون ری‌استارت سرویس.
//   • config.php و دیتابیس‌های قالب‌ها هرگز بازنویسی نمی‌شوند.
//   • ربات‌های ساخته‌شده، bots/*/config.php و دیتابیس‌ها هرگز تغییر نمی‌کنند.

/**
 * متنِ «چیزی برای دریافت نیست».
 *
 * باید بین «۲ کامیت تازه ولی هیچ‌کدام templates/ را عوض نکرده» و «همه‌چیز
 * هم‌تراز است» فرق بگذارد؛ وگرنه ادمین می‌بیند «۲ کامیت عقب» ولی پیام
 * «همه‌چیز با origin/main هم‌تراز است» → فکر می‌کند دکمه کار نکرده است.
 */
function sourceNothingText(array $st): string
{
    $behind = (int)($st['behind'] ?? 0);
    if ($behind > 0) {
        return "🟡 {$behind} قالب از مخازن گیتهابِ خودشان عقب است؛ روی «بررسی (git fetch)» بزن تا فهرست دقیق فایل‌ها دوباره محاسبه شود.";
    }
    return "✅ همهٔ قالب‌ها با مخازن گیتهابِ خودشان هم‌ترازند؛ چیزی برای دریافت نیست.";
}

/**
 * پنل «🔄 دریافت سورس بروز» — تازه‌کردنِ templates/ از گیت.
 *
 * این بخش دیگر هیچ رباتِ ساخته‌شده‌ای را تغییر نمی‌دهد: کدِ تازه فقط وارد
 * templates/ می‌شود تا کاربران بعداً نسخهٔ جدید را نصب کنند. اجرا فقط در دسترس
 * سوپرادمین است و بکاپ هم فقط برای همان کسی که دکمه را زده فرستاده می‌شود.
 */
function showSourcePanel(array $cfg, Store $store, string $TOKEN, $chatId, int $msgId = 0, string $note = ''): void
{
    $st = SelfUpdate::templatesStatus();
    $outdated = count((array)$st['templates']);
    $behind = $outdated > 0
        ? "🟡 {$outdated} قالب نیاز به بروزرسانی"
        : '🟢 همهٔ قالب‌ها هم‌ترازند';

    $txt = "🔄 <b>دریافت سورس بروز (قالب‌ها)</b>\n"
        . Manager::versionLine() . "\n\n"
        . "شاخهٔ ربات‌ساز: <code>{$st['branch']}</code> | کامیت: <code>{$st['commit']}</code>\n"
        . "وضعیت: {$behind}\n\n"
        . "📥 با این دکمه فقط <code>templates/</code> تازه می‌شود:\n"
        . "• ربات‌های ساخته‌شده هیچ تغییری نمی‌بینند؛ <code>config.php</code> و دیتابیس‌شان دست‌نخورده می‌ماند\n"
        . "• کدِ ربات‌ساز، دیتابیسِ مدیریتی، وبهوک و وی‌هوست تغییر نمی‌کنند\n"
        . "• بعد از آن، کاربران می‌توانند نسخهٔ جدید را نصب کنند\n"
        . "• 🗂 بکاپِ قالب‌های در حال تغییر فقط برای شما (سوپرادمین) فرستاده می‌شود\n";

    $files = (array)$st['files'];
    if ($files !== []) {
        $txt .= "\n🟡 در راه است (" . count($files) . " قالب):\n";
        $i = 0;
        foreach ((array)$st['templates'] as $name => $n) {
            $txt .= "• <code>" . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . "</code> — " . ($n > 1 ? "{$n} فایل" : 'نیاز به بروزرسانی') . "\n";
            if (++$i >= 8) { $txt .= "• …\n"; break; }
        }
    } elseif ($st['behind'] === 0 && SelfUpdate::sourceDir() !== null) {
        $txt .= "\n✅ همه‌چیز به‌روز است؛ چیزی برای دریافت نیست.\n";
    } elseif ($st['behind'] > 0 && SelfUpdate::sourceDir() !== null) {
        // قالب‌های عقب‌مانده هست ولی فایل‌های دقیق محاسبه نشد: صریح گفته شود
        $txt .= "\n" . sourceNothingText($st) . "\n";
    }

    $probs = SelfUpdate::problems();
    if ($probs !== []) {
        $txt .= "\n⛔️ برای اجرا لازم است:\n• "
            . htmlspecialchars(implode("\n• ", $probs), ENT_QUOTES, 'UTF-8') . "\n";
    }
    if ($note !== '') $txt .= "\n" . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . "\n";

    $rows = [];
    if ($probs === []) $rows[] = [['text' => '⬇️ دریافت سورس تازه', 'callback_data' => 'src:ask']];
    $rows[] = [['text' => '🔄 بررسی (git fetch)', 'callback_data' => 'src:check']];
    $rows[] = [['text' => '📜 آخرین لاگ', 'callback_data' => 'src:log']];
    if ((int)$outdated > 0) $rows[] = [['text' => '🚀 اعمال سورس بروز روی ربات‌های ساخته‌شده', 'callback_data' => 'src:apply']];
    $rows[] = [['text' => '🏠 منو', 'callback_data' => Nav::CB_BACK_MAIN]];
    $kb = BotApi::ikb($rows);
    if ($msgId > 0) {
        BotApi::edit($TOKEN, $chatId, $msgId, $txt, ['reply_markup' => $kb]);
        return;
    }
    BotApi::send($TOKEN, $chatId, $txt, ['reply_markup' => $kb]);
}

/** صفحهٔ تأییدِ «سورسِ قالب‌ها تازه شود؟» — اجرا بدون تأیید هیچ راهی ندارد */
function showSourceConfirm(array $cfg, Store $store, string $TOKEN, $chatId, int $msgId): void
{
    $st = SelfUpdate::templatesStatus();
    $files = (array)$st['files'];
    if ($files === []) {
        $t = sourceNothingText($st);
        $kb = BotApi::ikb([[['text' => '🔄 پنل سورس', 'callback_data' => 'src:refresh']]]);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
        else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
        return;
    }
    $list = '';
    $i = 0;
    foreach ((array)$st['templates'] as $name => $n) {
        $list .= "• <code>" . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . "</code> — " . ($n > 1 ? "{$n} فایل" : 'نیاز به بروزرسانی') . "\n";
        if (++$i >= 10) { $list .= "• …\n"; break; }
    }
    $text = "🔄 <b>دریافت سورس تازهٔ قالب‌ها</b>\n\n"
        . "تعداد قالب‌های منتظر: " . count((array)$st['templates']) . "\n"
        . $list . "\n"
        . "🛡 ربات‌های ساخته‌شده، <code>config.php</code>‌ها و دیتابیس‌ها دست‌نخورده می‌مانند.\n"
        . "🗂 پیش از تغییر، از هر قالبِ در حال عوض‌شدن بکاپ گرفته و فقط برای شما فرستاده می‌شود.\n"
        . "⏱ ممکن است چند ثانیه تا یک دقیقه طول بکشد.\n\nشروع شود؟";
    $kb = BotApi::ikb([
        [['text' => '✅ بله، دریافت کن', 'callback_data' => 'src:go']],
        [['text' => '❌ انصراف', 'callback_data' => 'src:refresh']],
    ]);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $text, ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, $text, ['reply_markup' => $kb]);
}

/**
 * ارسال آرشیوِ بکاپِ قالب — فقط به سوپرادمینی که دکمه را زده.
 * اجرای این بخش از پیش فقط برای سوپرادمین ممکن است؛ پس هیچ کاربر دیگری
 * (حتی ادمینِ غیرسوپر) هرگز به این فایل نمی‌رسد.
 */
function sendTemplatesBackup(string $TOKEN, $chatId, array $backup, string $tplName): void
{
    if (empty($backup['file']) || !is_file((string)$backup['file'])) return;
    $size = (int)($backup['size'] ?? 0);
    $path = (string)$backup['file'];
    $safe = htmlspecialchars($tplName, ENT_QUOTES, 'UTF-8');
    $caption = "🗂 <b>بکاپ قالب پیش از بروزرسانی سورس</b>\n"
        . "قالب: <code>templates/{$safe}</code>\n"
        . "زمان: " . date('Y-m-d H:i') . "\n"
        . "فایل‌ها: " . (int)($backup['files'] ?? 0) . "\n"
        . "حجم: " . SourceUpdate::fmtSize($size) . "\n\n"
        . "🔒 فقط برای سوپرادمین فرستاده شد.\n"
        . "بازگردانی: محتویات آرشیو را روی <code>templates/{$safe}</code> کپی کنید.";
    if ($size > SourceUpdate::MAX_SEND_BYTES) {
        BotApi::send($TOKEN, $chatId,
            "⚠️ بکاپ " . SourceUpdate::fmtSize($size) . " است و در تلگرام جا نمی‌شود؛ روی سرور نگه داشته شد:\n<code>"
            . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . "</code>");
        return;
    }
    $r = BotApi::sendDocument($TOKEN, $chatId, $path, $caption);
    if (empty($r['ok'])) {
        BotApi::send($TOKEN, $chatId,
            "⚠️ ارسال بکاپ ناموفق بود (" . htmlspecialchars((string)($r['description'] ?? '?'), ENT_QUOTES, 'UTF-8')
            . "). نسخهٔ روی سرور:\n<code>" . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . "</code>");
    }
}

/**
 * اجرای واقعیِ «دریافت سورس بروز»:
 *   fetch (هر قالب از ریپوی خودش) → قالب‌های در حال تغییر → بکاپ (فقط برای همین
 *   سوپرادمین) → SelfUpdate::updateTemplates (کپیِ امن؛ config/دیتابیس دست‌نخورده) → گزارش.
 */
function runTemplatesUpdate(string $TOKEN, $chatId): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    if (SelfUpdate::isRunning()) {
        BotApi::send($TOKEN, $chatId,
            "⏳ یک بروزرسانی دیگر همین الان در حال اجراست؛ چند لحظه بعد دوباره تلاش کن.",
            ['reply_markup' => BotApi::ikb([[['text' => '🔄 پنل سورس', 'callback_data' => 'src:refresh']]])]);
        return;
    }
    $probs = SelfUpdate::problems();
    if ($probs !== []) {
        BotApi::send($TOKEN, $chatId,
            "⛔️ امکان اجرا نیست:\n• " . htmlspecialchars(implode("\n• ", $probs), ENT_QUOTES, 'UTF-8'));
        return;
    }

    $waiting = BotApi::send($TOKEN, $chatId, "⏳ در حال دریافت سورس تازهٔ <code>templates/</code>…");
    $msgId = !empty($waiting['result']['message_id']) ? (int)$waiting['result']['message_id'] : 0;
    $report = function (string $t) use ($TOKEN, $chatId, $msgId): void {
        $kb = BotApi::ikb([
            [['text' => '🔄 پنل سورس', 'callback_data' => 'src:refresh']],
            [['text' => '📜 لاگ کامل', 'callback_data' => 'src:log']],
        ]);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
        else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
    };

    $t0 = microtime(true);
    $fetchWarn = '';
    $fetch = SelfUpdate::fetchSource();
    if (!$fetch['ok']) {
        $fetchWarn = "⚠️ fetch از برخی مخازن کامل نشد (با نسخهٔ کش‌شده ادامه می‌دم):\n<pre>"
            . htmlspecialchars(mb_substr((string)$fetch['out'], -700), ENT_QUOTES, 'UTF-8') . "</pre>\n";
        // اگر هیچ مخزنی به‌روز نشد و اصلاً کلنی نداریم، ادامه معنی ندارد
        $anyClone = false;
        foreach (array_keys(Manager::templates()) as $k) {
            if (is_dir(SelfUpdate::templateCloneDir($k))) { $anyClone = true; break; }
        }
        if (!$anyClone && trim((string)$fetch['out']) !== '') {
            BotApi::send($TOKEN, $chatId, "❌ دسترسی به گیتهاب ندارم و کلون محلی هم نیست؛ بروزرسانی امکان‌پذیر نیست.");
            return;
        }
    }

    $st = SelfUpdate::templatesStatus();
    $files = (array)$st['files'];
    if ($files === []) {
        $report(sourceNothingText($st));
        return;
    }

    // بکاپِ قالب‌هایی که قرار است عوض شوند (فقط برای همین سوپرادمین فرستاده می‌شود)
    $bakDir = SourceUpdate::backupsDir();
    if (!@mkdir($bakDir, 0755, true) && !is_dir($bakDir)) $bakDir = '';
    $baks = [];
    $bakErr = [];
    foreach (array_keys((array)$st['templates']) as $tkey) {
        $tkey = (string)$tkey;
        $dir = Manager::templateDir($tkey);
        if (!is_dir($dir)) continue;
        $name = basename(rtrim(str_replace('\\', '/', $dir), '/'));
        if ($bakDir === '') { $bakErr[] = $name . ': پوشهٔ بکاپ ساخته نشد'; continue; }
        // نامِ فایلِ آرشیو باید با همان قانونِ safeName ساخته شود تا pruneBackups
        // (که templates_<key>_* را جست‌وجو می‌کند) بتواند نسخه‌های قدیمی را پاک کند
        $key = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: '_';
        $prefixKey = 'templates_' . $key;
        $note = "botsaz templates backup\ntemplate: {$name}\ndate: " . date('Y-m-d H:i:s') . "\n\n"
            . "بکاپِ «templates/{$name}» پیش از بروزرسانی سورس است (فقط برای سوپرادمین).\n"
            . "بازگردانی: محتویات آرشیو را روی templates/{$name} کپی کنید.\n";
        $res = SourceUpdate::backupDir($dir, $bakDir . '/' . $prefixKey . '_' . date('Y-m-d_H-i-s'), null, $note);
        if (!empty($res['ok'])) {
            $baks[] = ['name' => $name, 'res' => (array)$res];
            try { SourceUpdate::pruneBackups($prefixKey); } catch (Throwable $e) { /* بی‌اهمیت */ }
        } else {
            $bakErr[] = $name . ': ' . (string)($res['error'] ?? 'خطای نامشخص');
        }
    }
    foreach ($baks as $b) sendTemplatesBackup($TOKEN, $chatId, (array)$b['res'], (string)$b['name']);

    // همگام‌سازیِ هر قالب از مخزنِ گیتهابِ خودش (بدون لمس config/دیتابیس)
    $run = SelfUpdate::updateTemplates(600);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if (!empty($run['queued'])) {
        $report("ℹ️ بروزرسانی مستقیم ممکن نبود؛ درخواست به کرون واگذار شد:\n\n" . htmlspecialchars((string)($run['out'] ?? ''), ENT_QUOTES, 'UTF-8'));
        return;
    }

    $failedTpl = [];
    $doneTpl = [];
    foreach ((array)$run['results'] as $key => $res) {
        if (empty($res['ok'])) { $failedTpl[] = $key . ': ' . (string)($res['error'] ?? '?'); continue; }
        if (!empty($res['skipped'])) continue;
        $doneTpl[] = ['key' => (string)$key, 'applied' => (int)($res['applied'] ?? 0)];
    }

    if ($failedTpl !== [] && $doneTpl === []) {
        $txt = "❌ <b>بروزرسانی سورس انجام نشد</b>\n• "
            . htmlspecialchars(implode("\n• ", $failedTpl), ENT_QUOTES, 'UTF-8');
        $txt .= "\n⏱ " . SourceUpdate::fmtMs($ms);
        $report($txt);
        try { Logger::getInstance()->warning('source', 'templates update failed: ' . implode(' | ', $failedTpl)); } catch (Throwable $e) { /* لاگر خاموش */ }
        return;
    }

    $lines = [];
    $totalApplied = 0;
    foreach ($doneTpl as $d) {
        $totalApplied += $d['applied'];
        $lines[] = "• <code>" . htmlspecialchars($d['key'], ENT_QUOTES, 'UTF-8') . "</code> — {$d['applied']} فایل";
    }
    $txt = $fetchWarn . "✅ <b>سورس تازه شد</b>\n\n"
        . "📥 " . $totalApplied . " فایل در " . count($doneTpl) . " قالب به‌روز شد:\n"
        . implode("\n", $lines) . "\n\n"
        . "🛡 ربات‌های ساخته‌شده تغییری نکردند؛ <code>config.php</code>‌ها و دیتابیس‌ها دست‌نخورده ماندند.\n"
        . "🧩 کدِ ربات‌ساز، وبهوک و وی‌هوست هم تغییر نکردند (فقط <code>templates/</code>).\n"
        . "👥 کاربران اکنون می‌توانند نسخهٔ جدید را نصب کنند.\n";
    if ($baks !== []) $txt .= "\n🗂 بکاپِ " . count($baks) . " قالب فقط برای شما فرستاده شد.\n";
    if ($bakErr !== []) {
        $txt .= "\n⚠️ بکاپ ساخته نشد (بروزرسانی ادامه یافت):\n• "
            . htmlspecialchars(implode("\n• ", $bakErr), ENT_QUOTES, 'UTF-8') . "\n";
    }
    if ($failedTpl !== []) {
        $txt .= "\n⚠️ این قالب‌ها آپدیت نشدند:\n• "
            . htmlspecialchars(implode("\n• ", $failedTpl), ENT_QUOTES, 'UTF-8') . "\n";
    }
    $txt .= "\n⏱ " . SourceUpdate::fmtMs($ms);
    $report($txt);
    try {
        Logger::getInstance()->info('source', 'templates updated from own repos: ' . $totalApplied
            . ' file(s) in ' . count($doneTpl) . ' template(s)');
    } catch (Throwable $e) { /* لاگر خاموش */ }
}

function applySourceToAllBots(array $cfg, Store $store, string $TOKEN, $chatId, int $uid): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    $waiting = BotApi::send($TOKEN, $chatId, "⏳ در حال بررسی و اعمالِ سورسِ تازه روی ربات‌های ساخته‌شده…");
    $msgId = !empty($waiting['result']['message_id']) ? (int)$waiting['result']['message_id'] : 0;
    $report = function (string $t) use ($TOKEN, $chatId, $msgId): void {
        $kb = BotApi::ikb([
            [['text' => '🔄 پنل سورس', 'callback_data' => 'src:refresh']],
            [['text' => '📜 لاگ کامل', 'callback_data' => 'src:log']],
        ]);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
        else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
    };

    $bots = $store->allBots();
    $updated = 0;
    $skipped = 0;
    $failed = 0;
    $lines = [];
    foreach ($bots as $b) {
        $type = (string)($b['type'] ?? '');
        $folder = (string)($b['folder'] ?? '');
        $plan = SourceUpdate::plan($type, $folder);
        if (!$plan['ok']) {
            $failed++;
            $lines[] = "• <code>{$folder}</code> — ⚠️ " . htmlspecialchars(mb_substr((string)$plan['error'], 0, 120), ENT_QUOTES, 'UTF-8');
            continue;
        }
        if (empty($plan['out_of_date'])) {
            $skipped++;
            continue;
        }
        $res = SourceUpdate::apply($plan, $uid);
        if (!empty($res['ok'])) {
            $updated++;
            $appliedN = (int)($res['applied'] ?? 0);
            $revertedN = (int)($res['reverted'] ?? 0);
            $lines[] = "• <code>{$folder}</code> — ✅ {$appliedN} فایل" . ($revertedN > 0 ? " ({$revertedN} برگردانده شد)" : "");
        } else {
            $failed++;
            $lines[] = "• <code>{$folder}</code> — ❌ " . htmlspecialchars(mb_substr((string)($res['error'] ?? '?'), 0, 120), ENT_QUOTES, 'UTF-8');
        }
    }

    $txt = "🚀 <b>تغییراتِ سورس اعمال شد</b>\n\n"
        . "✅ بروزشده: {$updated} | 🟢 از قبل به‌روز: {$skipped} | ❌ ناموفق: {$failed}\n\n";
    $txt .= $lines !== [] ? implode("\n", array_slice($lines, 0, 30)) . (count($lines) > 30 ? "\n• …" : "") : "هیچ فایلی تغییر نکرد.";
    $txt .= "\n\n🛡 config.php و دیتابیس‌ها هرگز لمس نشدند؛ برای هر اپدیت بکاپ گرفته شد.";
    $report($txt);
    try {
        Logger::getInstance()->info('source', "apply-to-bots: updated={$updated}, skipped={$skipped}, failed={$failed}");
    } catch (Throwable $e) { /* لاگر خاموش */ }
}

function showBotPanel(array $cfg, Store $store, string $TOKEN, $chatId, $msgId, array $bot, string $from = '', bool $isAdmin = false): void
{
    $t = botPanelText($cfg, $bot);
    $kb = Nav::botPanelKb($bot, $isAdmin);
    if ($msgId > 0) {
        BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
    } else {
        BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
    }
}

function botAction(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, $msgId, array $bot, string $action): void
{
    $uid = (int)$user['user_id'];
    switch ($action) {
        case 'stats': {
            $folder = htmlspecialchars((string)($bot['folder'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label  = Ui::e(Manager::templateLabel((string)($bot['type'] ?? '')));
            $st     = ($bot['status'] ?? '') === 'active' ? '🟢 فعال' : '🔴 غیرفعال';

            // آمارِ کاربران فقط برای قالب‌هایی معنا دارد که جدول کاربر تلگرامی دارند
            $c = -1;
            try {
                if (botHasUserTable($bot)) {
                    $pdo = childPdo($cfg, $bot);
                    if ($pdo) { $c = childCount($pdo, $bot); $pdo = null; }
                }
            } catch (Throwable $e) {
                Logger::getInstance()->warning('stats', "count failed for {$folder}: " . $e->getMessage());
            }

            $t = "📊 <b>آمار ربات «{$folder}»</b>\n" . Ui::sep() . "\n\n";
            $t .= Ui::kv('🧩', 'قالب', $label);
            $t .= "\n" . Ui::kvRaw('🔹', 'وضعیت', $st);
            if ($c >= 0) {
                $t .= "\n" . Ui::kv('👥', 'تعداد کاربران', number_format($c));
            } else {
                $t .= "\n" . Ui::kvRaw('👥', 'تعداد کاربران', '<i>این قالب فهرست کاربر تلگرامی ندارد</i>');
            }
            $t .= "\n" . Ui::kv('🗄', 'دیتابیس', botDbLabel($bot), true);
            $t .= "\n" . Ui::kv('🆔', 'آیدی ادمین', (string)($bot['admin_id'] ?? ''), true);
            $t .= "\n" . Ui::kv('👤', 'صاحب ربات (ربات‌ساز)', (string)(int)($bot['owner_id'] ?? 0), true);
            if (isset($bot['created_at']) && trim((string)$bot['created_at']) !== '') {
                $ts = strtotime((string)$bot['created_at']);
                $t .= "\n" . Ui::kv('📅', 'تاریخ ساخت', $ts ? date('Y-m-d H:i', $ts) : (string)$bot['created_at']);
            }
            $t .= "\n\n" . Ui::sep() . "\n";
            $t .= "💡 برای دیدن آمارِ همهٔ ربات‌های خودت، «📦 ربات‌های من» را بزن.";
            BotApi::send($TOKEN, $chatId, Ui::out($t), ['reply_markup' => Nav::botPanelKb($bot, isAdmin($user, $SUPERS))]);
            return;
        }
        case 'edittoken': {
            if (!botEditableBy($bot, $user, $SUPERS)) {
                BotApi::send($TOKEN, $chatId, "⛔️ فقط صاحب این ربات یا ادمین می‌تواند توکنش را عوض کند.");
                return;
            }
            $store->setStep($uid, 'await_edit_bot_token', ['bot_id' => (int)$bot['id']]);
            $old = htmlspecialchars((string)($bot['bot_username'] ?? ''), ENT_QUOTES, 'UTF-8');
            BotApi::send($TOKEN, $chatId,
                "🔑 <b>ویرایش توکن ربات</b>\n\n"
                . "ربات فعلی: " . ($old !== '' ? Ui::code('@' . $old) : '<i>نامشخص</i>')
                . "\n\n🆕 <b>توکن تازه را بفرستید</b>\n"
                . "توکن را از ‎<code>@BotFather</code> کپی کنید. نمونه: ‎<code>1234567890:AAH…</code>\n\n"
                . "ℹ️ با این کار:\n"
                . "• توکن داخل ‎<code>config.php</code> ربات عوض می‌شود\n"
                . "• وبهوک دوباره ست می‌شود\n"
                . "• کاربران و تنظیمات ربات <b>حفظ می‌شوند</b> ✅\n\n"
                . "⚠️ اگر توکنِ رباتِ دیگری را بفرستید، آن ربات به این پنل وصل می‌شود.\n\n"
                . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                ['reply_markup' => Nav::stepKb()]);
            return;
        }
        case 'editadmin': {
            if (!botEditableBy($bot, $user, $SUPERS)) {
                BotApi::send($TOKEN, $chatId, "⛔️ فقط صاحب این ربات یا ادمین می‌تواند آیدی ادمین را عوض کند.");
                return;
            }
            $store->setStep($uid, 'await_edit_admin_id', ['bot_id' => (int)$bot['id']]);
            $folder = htmlspecialchars((string)($bot['folder'] ?? ''), ENT_QUOTES, 'UTF-8');
            BotApi::send($TOKEN, $chatId,
                "🆔 <b>ویرایش آیدی ادمین</b>\n\n"
                . "ربات: " . Ui::code($folder) . "\n"
                . "آیدی فعلی: " . Ui::code((string)($bot['admin_id'] ?? '')) . "\n\n"
                . "🆕 <b>آیدی عددی تازه را بفرستید</b>\n"
                . "از ‎<code>@userinfobot</code> بگیرید (عدد بزرگ، بدون ‎<code>@</code>).\n\n"
                . "ℹ️ با این کار فایل ‎" . Ui::code('bots/' . $bot['folder'] . '/config.php') . " به‌روز می‌شود؛\n"
                . "ربات از این پس به آیدی تازه پیام می‌دهد. ✅\n\n"
                . "برای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
                ['reply_markup' => Nav::stepKb()]);
            return;
        }
        case 'broadcast': {
            if (!botHasUserTable($bot)) {
                BotApi::send($TOKEN, $chatId, "ℹ️ قالب «" . Manager::templateLabel((string)($bot['type'] ?? '')) . "» فهرست کاربر تلگرامی ندارد؛ پیام همگانی برایش ممکن نیست.");
                return;
            }
            $store->setStep($uid, 'await_child_broadcast', ['bot_id' => $bot['id']]);
            BotApi::send($TOKEN, $chatId, Texts::get($store, 'broadcast_child_prompt', [
                'folder' => (string)($bot['folder'] ?? ''),
            ]), ['reply_markup' => Nav::stepKb()]);
            return;
        }
        case 'webhook': {
            $tok = childToken($bot);
            $url = Manager::webhookUrlForBot($cfg, $bot);
            $r = BotApi::setWebhook($tok, $url, Manager::resolveWebhookSecret($bot));
            if (!is_array($r) || empty($r['ok'])) {
                BotApi::send($TOKEN, $chatId, "❌ <b>ست وبهوک ناموفق بود:</b>\n"
                    . Ui::quote(Ui::e((string)($r['description'] ?? 'پاسخی از تلگرام نرسید'))));
                return;
            }
            BotApi::send($TOKEN, $chatId,
                "✅ <b>وبهوک با موفقیت ست شد.</b>\n\n"
                . "🔗 " . Ui::link($url) . "\n"
                . "📋 " . Ui::code($url));
            return;
        }
        case 'toggle': {
            $new = $bot['status'] === 'active' ? 'disabled' : 'active';
            $store->setBotStatus($bot['id'], $new);
            if ($new === 'disabled') {
                $delR = BotApi::deleteWebhook(childToken($bot));
                // قبلاً نتیجه دور ریخته می‌شد؛ وبهوک که برنداشته شود رباتِ غیرفعال همچنان پینگ می‌گیرد
                if (!is_array($delR) || empty($delR['ok'])) {
                    Logger::getInstance()->warning('toggle', "deleteWebhook برای {$bot['folder']} ناموفق: " . (($delR['description'] ?? '') ?: 'no response'));
                }
            } else {
                $tok = childToken($bot);
                $url = Manager::webhookUrlForBot($cfg, $bot);
                $setR = BotApi::setWebhook($tok, $url, Manager::resolveWebhookSecret($bot));
                if (!is_array($setR) || empty($setR['ok'])) {
                    Logger::getInstance()->warning('toggle', "setWebhook برای {$bot['folder']} ناموفق: " . (($setR['description'] ?? '') ?: 'no response'));
                }
            }
            $bot['status'] = $new;
            showBotPanel($cfg, $store, $TOKEN, $chatId, $msgId, $bot, '', isAdmin($user, $SUPERS));
            return;
        }
        case 'srcask': {
            // دکمهٔ کهنهٔ «بروزرسانی سورسِ همین ربات» حذف شد: دیگر هیچ رباتِ
            // ساخته‌شده‌ای را تغییر نمی‌دهیم؛ فقط templates/ تازه می‌شود.
            if (!isSuper($SUPERS, $uid)) {
                BotApi::send($TOKEN, $chatId, "⛔️ فقط سوپرادمین اجازهٔ بروزرسانی سورس را دارد.");
                return;
            }
            $t = "ℹ️ بروزرسانی روی ربات‌های ساخته‌شده حذف شد؛ اکنون فقط <code>templates/</code> تازه می‌شود تا کاربران بتوانند نسخهٔ جدید را نصب کنند.";
            $kb = BotApi::ikb([
                [['text' => '🔄 پنل بروزرسانی سورس', 'callback_data' => 'src:refresh']],
                [['text' => '↩️ پنل همین ربات', 'callback_data' => "mybot:{$bot['id']}"]],
            ]);
            if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t, ['reply_markup' => $kb]);
            else BotApi::send($TOKEN, $chatId, $t, ['reply_markup' => $kb]);
            return;
        }
        case 'delask': {
            BotApi::edit($TOKEN, $chatId, $msgId,
                "⚠️ حذف ربات <b>{$bot['folder']}</b>؟",
                ['reply_markup' => BotApi::ikb([
                    [['text' => '✅ بله', 'callback_data' => "act:delyes:{$bot['id']}"]],
                    [['text' => '↩️ بازگشت', 'callback_data' => "mybot:{$bot['id']}"]],
                ])]);
            return;
        }
        case 'delyes': {
            $delR = BotApi::deleteWebhook(childToken($bot));
            // قبلاً نتیجه دور ریخته می‌شد؛ وبهوک باقی‌مانده بعد از حذف، پینگ بی‌جهت می‌فرستد
            if (!is_array($delR) || empty($delR['ok'])) {
                Logger::getInstance()->warning('delete', "deleteWebhook برای {$bot['folder']} ناموفق: " . (($delR['description'] ?? '') ?: 'no response'));
            }
            $dir = Manager::childBotsDir() . '/' . $bot['folder'];
            // ===== بکاپ قبل از حذف =====
            $backupDir = dirname($dir) . '/backups/' . $bot['folder'] . '_' . time();
            $backedUp = false;
            if (is_dir($dir)) {
                @mkdir($backupDir, 0755, true);
                try {
                    Manager::copyDir($dir, $backupDir . '/data', ['config.php', '.htaccess']);
                    // config.php/.htaccess در پوشه‌های تو در تو هم نباید در بکاپ بمانند
                    Manager::stripSensitiveFiles($backupDir . '/data', ['config.php', '.htaccess']);
                    $backedUp = true;
                } catch (Exception $e) { Logger::getInstance()->error('delete', "Backup failed for {$bot['folder']}: " . $e->getMessage()); }
            }
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                @rmdir($dir);
            }
            // ===== حذف کامل دیتابیس =====
            // اگر DROP ناموفق شود نباید بی‌صدا رکورد حذف شود — دیتابیس یتیم و نامرئی می‌ماند.
            $dbDropFailed = false;
            try {
                if (!empty($bot['db_name'])) {
                    $host = $cfg['db_host']; $port = $cfg['db_port'] ?? 3306;
                    $serverPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $serverPdo->exec("DROP DATABASE IF EXISTS `{$bot['db_name']}`");
                }
            } catch (Exception $e) {
                $dbDropFailed = true;
                Logger::getInstance()->error('delete', "Failed to drop DB for {$bot['folder']}: " . $e->getMessage());
            }
            $store->deleteBot($bot['id']);
            // ===== جلوگیری از رشد بی‌انتهای bots/backups/ =====
            try { Manager::pruneBackups(dirname($dir) . '/backups', $bot['folder'], 5); }
            catch (Exception $e) { Logger::getInstance()->warning('delete', "Backup prune failed: " . $e->getMessage()); }
            $dbNote = ($dbDropFailed && !empty($bot['db_name']))
                ? "\n⚠️ حذف دیتابیس ناموفق بود — دستی حذفش کنید: <code>{$bot['db_name']}</code>"
                : '';
            BotApi::edit($TOKEN, $chatId, $msgId, "🗑 ربات حذف شد." . $dbNote . ($backedUp ? "\n💾 بکاپ: <code>{$backupDir}</code>" : ""));
            return;
        }

        default:
            // دکمهٔ کهنه/کال‌بک قطع‌شده نباید بی‌صدا گم شود (کاربر فکر می‌کند ربات هنگ کرده)
            Logger::getInstance()->warning('action', "unknown bot action '{$action}' (bot #{$bot['id']})");
            BotApi::send($TOKEN, $chatId,
                "⚠️ عملیات ناشناخته: <code>" . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . "</code>\n"
                . "احتمالاً کیبورد قدیمی است؛ منوی این ربات دوباره باز شد.",
                ['reply_markup' => Nav::botPanelKb($bot, isAdmin($user, $SUPERS))]);
            return;
    }
}
