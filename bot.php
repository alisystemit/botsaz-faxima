<?php
// ===== ربات اصلی ربات‌ساز (وبهوک) =====
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/src/BotApi.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Manager.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/DbBackup.php';
require_once __DIR__ . '/src/Nav.php';

$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'config.php missing']); exit; }
$cfg  = require $cfgFile;
$TOKEN = $cfg['main_token'];
$SUPERS = $cfg['super_admins'] ?? [];

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

$store = new Store($cfg['manager_db'], $cfg);

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
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}
$provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

// پاسخ فقط بعد از پردازش موفق ارسال می‌شود؛ عمداً fastcgi_finish_request زودهنگام نداریم:
// اگر وسط کار استثنا رخ دهد status 500 برمی‌گردد و تلگرام همان آپدیت را دوباره می‌فرستد
// (علامت‌گذاری update هم فقط بعد از موفقیت انجام می‌شود). قبلاً اتصال زود آزاد می‌شد و
// خطاهای mid-flight با پاسخ 200 بی‌صدا گم می‌شدند.

// ===== Dedup با update_id =====
$update = json_decode(file_get_contents('php://input'), true) ?: [];
if (!$update) { echo json_encode(['ok'=>true]); exit; }

$updateId = $update['update_id'] ?? null;
if ($updateId !== null && $store->isUpdateProcessed($updateId)) {
    echo json_encode(['ok'=>true,'duplicate'=>true]); exit;
}
// «علامت‌گذاری بعد از پردازش موفق» — اگر وسط کار خطا بود، تلگرام دوباره می‌فرستد

// ===== عمق لینک عمیق /start <param> =====
$deepLinkParam = null;
if (isset($update['message']['text']) && preg_match('/^\/start\s+(.+)$/', trim($update['message']['text']), $m)) {
    $deepLinkParam = trim($m[1]);
}

$msg  = $update['message'] ?? $update['channel_post'] ?? null;
$cb   = $update['callback_query'] ?? null;

if ($cb) {
    if (!isset($cb['message'])) { if ($updateId !== null) $store->markUpdateProcessed($updateId); echo json_encode(['ok'=>true]); exit; }
    $from = $cb['from'];
    $uid = (int)$from['id'];
    $user = $store->user($uid, $from['first_name'] ?? '', $from['username'] ?? '');
    handleCallback($cfg, $store, $TOKEN, $SUPERS, $user, $cb);
    if ($updateId !== null) $store->markUpdateProcessed($updateId);
    echo json_encode(['ok'=>true]); exit;
}

if ($msg) {
    // ===== فقط چت خصوصی =====
    $chatType = $msg['chat']['type'] ?? 'private';
    if ($chatType !== 'private') {
        if ($updateId !== null) $store->markUpdateProcessed($updateId);
        echo json_encode(['ok'=>true]); exit;
    }
    $from = $msg['from'] ?? null;
    if (!$from) { if ($updateId !== null) $store->markUpdateProcessed($updateId); echo json_encode(['ok'=>true]); exit; }
    $uid = (int)$from['id'];
    $user = $store->user($uid, $from['first_name'] ?? '', $from['username'] ?? '');
    $text = trim($msg['text'] ?? '');
    $chatId = $msg['chat']['id'];

    // ===== ورودی غیرمتنی =====
    if ($text === '' && !isset($msg['text'])) {
        if ($updateId !== null) $store->markUpdateProcessed($updateId);
        echo json_encode(['ok'=>true]); exit;
    }

    handleMessage($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $text, $msg, $deepLinkParam);
    if ($updateId !== null) $store->markUpdateProcessed($updateId);
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>true]);

// ================= helpers =================
// رشته/عدد بودن مقدار config فرقی نکند — قبلاً in_array سخت‌گیرانه روی رشته‌های config هیچ‌وقت match نمی‌کرد
function isSuper(array $supers, int $uid): bool { return in_array((string)$uid, array_map('strval', $supers), true); }
function isAdmin(array $u, array $supers): bool { return isSuper($supers, (int)$u['user_id']) || (int)$u['is_admin'] === 1; }
function canUse(array $u, array $supers): bool { return isAdmin($u, $supers) || (int)$u['is_allowed'] === 1; }

function mainMenu(array $u, array $supers, Store $store = null): string {
    if (isAdmin($u, $supers)) {
        $pendingCount = $store ? $store->countPendingRequests() : 0;
        $pendingText = $pendingCount > 0 ? " ({$pendingCount})" : "";
        return BotApi::kb([
            [['text' => '🤖 ساخت ربات جدید'], ['text' => '📦 ربات‌های من']],
            [['text' => '📊 آمار'], ['text' => '📣 همگانی']],
            [['text' => '⏰ کرون'], ['text' => '👥 کاربران مجاز']],
            [['text' => '💾 بکاپ دیتابیس'], ['text' => "📋 درخواست‌های جدید{$pendingText}"]],
            [['text' => '📋 همه ربات‌ها'], ['text' => 'ℹ️ راهنما']],
        ]);
    }
    return BotApi::kb([
        [['text' => '🤖 ساخت ربات جدید'], ['text' => '📦 ربات‌های من']],
        [['text' => 'ℹ️ راهنما']],
    ]);
}

function typeMenu(): string {
    return Nav::typeMenu();
}

// ===== helpers ناوبری (ماژولار — همه از Nav تغذیه می‌شوند) =====

/** لیست «ربات‌های من» + دکمه برگشت — مشترک بین پیام و کال‌بک */
function sendMyBotsList(Store $store, string $TOKEN, $chatId, int $uid, int $msgId = 0): void
{
    if (!$store->hasBot($uid)) {
        $t = $store->hasPendingRequest($uid)
            ? "⏳ هنوز رباتی ندارید. درخواست شما در انتظار تأیید ادمین است."
            : "هنوز رباتی نساخته‌ای.\nبرای شروع، «🤖 ساخت ربات جدید» را بزنید.\nتوجه: هر کاربر فقط یک ربات می‌تواند بسازد.";
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, $t);
        else BotApi::send($TOKEN, $chatId, $t);
        return;
    }
    $bots = $store->myBots($uid);
    if (!$bots) {
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "هنوز رباتی نساخته‌ای.");
        else BotApi::send($TOKEN, $chatId, "هنوز رباتی نساخته‌ای.");
        return;
    }
    $rows = [];
    foreach ($bots as $b) {
        $st = ($b['status'] ?? '') === 'active' ? '🟢' : '🔴';
        $rows[] = [['text' => "{$st} {$b['folder']} (@{$b['bot_username']})", 'callback_data' => "mybot:{$b['id']}"]];
    }
    $kb = Nav::myBotsKb($rows);
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "📦 ربات‌های شما:", ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, "📦 ربات‌های شما:", ['reply_markup' => $kb]);
}

/** پنل مدیریت کاربران مجاز — مشترک بین پیام و کال‌بک (edit یا send) */
function showUsersPanel(string $TOKEN, $chatId, int $msgId = 0): void
{
    $kb = Nav::usersPanelKb();
    if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "مدیریت کاربران مجاز 👇", ['reply_markup' => $kb]);
    else BotApi::send($TOKEN, $chatId, "مدیریت کاربران مجاز 👇", ['reply_markup' => $kb]);
}

/** متن پنل یک ربات (مشترک بین edit و send) */
function botPanelText(array $cfg, array $bot): string
{
    $pdo = childPdo($cfg, $bot);
    $count = $pdo ? childCount($pdo, $bot) : -1;
    $countTxt = $count >= 0 ? $count : 'نامشخص';
    $st = ($bot['status'] ?? '') === 'active' ? '🟢 فعال' : '🔴 غیرفعال';
    return "🤖 <b>{$bot['folder']}</b> ({$bot['type']})\n\n"
        . "🔹 یوزرنیم: @{$bot['bot_username']}\n"
        . "🔹 وضعیت: {$st}\n"
        . "🔹 ادمین: <code>{$bot['admin_id']}</code>\n"
        . "🔹 دیتابیس: <code>{$bot['db_name']}</code>\n"
        . "👥 کاربران: {$countTxt}";
}

/**
 * برگشت یک مرحله‌ای داخل stepها — قبل از switch اصلی handleStep صدا زده می‌شود.
 * هرگز استثنا نمی‌دهد و هرگز کاربر را در مرحله گیر نمی‌اندازد (fallback: منوی اصلی).
 */
function handleBack(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, string $step, array $temp): void
{
    $uid = (int)$user['user_id'];
    try {
        $target = Nav::backTarget($step);
        switch ($target['kind']) {
            case 'type':
                $store->clearStep($uid);
                BotApi::send($TOKEN, $chatId, "نوع ربات را انتخاب کن 👇", ['reply_markup' => Nav::typeMenu()]);
                return;
            case 'step': {
                $prev = (string)($target['step'] ?? 'idle');
                if ($prev === 'await_bot_token') {
                    $type = (string)($temp['type'] ?? '');
                    if ($type === '') { // temp گم شده (مثلاً ری‌استارت) → انتخاب نوع
                        $store->clearStep($uid);
                        BotApi::send($TOKEN, $chatId, "نوع ربات را انتخاب کن 👇", ['reply_markup' => Nav::typeMenu()]);
                        return;
                    }
                    $store->setStep($uid, 'await_bot_token', ['type' => $type]);
                    $names = Manager::validTypes();
                    $label = $names[$type] ?? $type;
                    BotApi::send($TOKEN, $chatId, "توکن ربات <b>{$label}</b> را بفرست.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                    return;
                }
                // برگشت به await_admin_id — بدون توکن معتبر نمی‌شود ادامه داد
                $hasToken = !empty($temp['token']);
                if (!$hasToken) {
                    $store->clearStep($uid);
                    BotApi::send($TOKEN, $chatId, "نوع ربات را انتخاب کن 👇", ['reply_markup' => Nav::typeMenu()]);
                    return;
                }
                $store->setStep($uid, 'await_admin_id');
                BotApi::send($TOKEN, $chatId, "آیدی عددی ادمین را بفرست:\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
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
            case 'bot': {
                $botId = (int)($temp['bot_id'] ?? 0);
                $store->clearStep($uid);
                $bot = $botId > 0 ? $store->botById($botId) : null;
                if (!$bot || ((int)$bot['owner_id'] !== $uid && !isAdmin($store->user($uid), $SUPERS))) {
                    BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
                    return;
                }
                BotApi::send($TOKEN, $chatId, botPanelText($cfg, $bot), ['reply_markup' => Nav::botPanelKb($bot)]);
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
        BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
    }
}

function childPdo(array $cfg, array $bot): ?PDO {
    try {
        $port = $cfg['db_port'] ?? 3306;
        $dsn = "mysql:host={$cfg['db_host']};port={$port};dbname={$bot['db_name']};charset=utf8mb4";
        return new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Exception $e) { return null; }
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
            ['command' => 'mybots', 'description' => '📦 ربات‌های من'],
            ['command' => 'stats', 'description' => '📊 آمار ربات‌ساز'],
            ['command' => 'cron', 'description' => '⏰ راه‌اندازی کرون'],
            ['command' => 'diagnose', 'description' => '🔍 بررسی سیستم (ادمین)'],
            ['command' => 'help', 'description' => 'ℹ️ راهنما'],
        ]);
        $role = $admin ? "مدیر 👑" : "کاربر مجاز ✅";
        $deepNote = $deepLink !== null
            ? "🔗 لینک شما: <code>" . htmlspecialchars($deepLink) . "</code>\n\n"
            : '';
        BotApi::send($TOKEN, $chatId,
            $deepNote . "👋 سلام! به <b>ربات‌ساز</b> خوش آمدی.\nنقش شما: {$role}\n\nبا دکمه «🤖 ساخت ربات جدید» در چند ثانیه ربات فاکسیما یا میرزا بساز.\nفقط توکن ربات + آیدی ادمین + یک نام لازم است.",
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
    }

    // دکمه «📋 درخواست‌های جدید» شمارنده پویا دارد: «📋 درخواست‌های جدید (N)»
    if ($admin && str_starts_with($text, '📋 درخواست‌های جدید')) {
        sendPendingRequests($store, $TOKEN, $chatId);
        return;
    }

    switch ($text) {
        case '🤖 ساخت ربات جدید':
            if ($store->hasBot($uid)) {
                BotApi::send($TOKEN, $chatId, "⛔️ شما قبلاً ربات دارید. هر کاربر فقط می‌تواند یک ربات بسازد.");
                return;
            }
            if ($store->hasPendingRequest($uid)) {
                BotApi::send($TOKEN, $chatId, "⏳ درخواست شما قبلاً ثبت شده و در انتظار تأیید ادمین است.\nلطفاً صبر کنید.");
                return;
            }
            // ادمین یا کاربر تأییدشده: مستقیم انتخاب نوع ربات
            if ($admin || $store->hasApprovedRequest($uid)) {
                BotApi::send($TOKEN, $chatId, "نوع ربات را انتخاب کن 👇", ['reply_markup' => typeMenu()]);
                return;
            }
            $store->addPendingRequest($uid, 'bot');
            BotApi::send($TOKEN, $chatId, "📝 درخواست شما ثبت شد.\nلطفاً منتظر تأیید ادمین بمانید.");
            return;

        case '📦 ربات‌های من':
            sendMyBotsList($store, $TOKEN, $chatId, $uid);
            return;

        case 'ℹ️ راهنما':
            BotApi::send($TOKEN, $chatId,
                "📖 <b>راهنما</b>\n\n1️⃣ از @BotFather با /newbot یک ربات بساز و توکن را کپی کن.\n2️⃣ در ربات‌ساز «🤖 ساخت ربات جدید» → انتخاب فاکسیما/میرزا.\n3️⃣ توکن، آیدی عددی ادمین (@userinfobot) و یک نام انگلیسی بده.\n4️⃣ ربات‌ساز خودش: پوشه + دیتابیس + کانفیگ + وبهوک.\n\n⚠️ توکن را به کسی نده.");
            return;

        case '⏰ کرون':
            if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
            BotApi::send($TOKEN, $chatId, "⏰ برای راه‌اندازی کرون، خط زیر را به crontab اضافه کنید:\n\n*/5 * * * * php /path/to/botsaz-faxima/tools/cron_dispatcher.php\n\nیا از «📋 درخواست‌ها» وضعیت کرون را ببینید.",
                ['reply_markup' => BotApi::kb([[['text' => '🏠 منو'], ['text' => 'ℹ️ راهنما']]])]);
            return;

        case '💾 بکاپ دیتابیس':
            if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
            showBackupPanel($cfg, $store, $TOKEN, $chatId);
            return;
    }

    if ($admin) {
        // دکمهٔ کیبورد «📋 درخواست‌های جدید (N)» — شمارش داخل متن است، پس starts_with
        if (str_starts_with($text, '📋 درخواست‌های جدید')) {
            sendPendingRequests($store, $TOKEN, $chatId);
            return;
        }
        switch ($text) {
            // ===== 🔍 دیاگنوز سیستم (فقط ادمین) =====
            case '/diagnose':
            case '🔍 دیاگنوز': {
                if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
                $_diag = [];
                $_diag[] = "🖥️ <b>سیستم</b>\n"
                    . "PHP: " . PHP_VERSION . "\n"
                    . "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n"
                    . "OS: " . (PHP_OS ?: 'unknown');
                // بررسی پوشه‌ها
                $_dirs = [
                    'ROOT_DIR' => __DIR__,
                    'bots/' => __DIR__ . '/bots',
                    'data/' => __DIR__ . '/data',
                    'data/logs/' => __DIR__ . '/data/logs',
                ];
                $_dir_msg = "📁 <b>پوشه‌ها</b>\n";
                foreach ($_dirs as $_name => $_path) {
                    if (!is_dir($_path)) {
                        $_dir_msg .= "❌ {$_name}: وجود ندارد\n";
                    } elseif (!is_writable($_path)) {
                        $_p = substr(sprintf('%o', @fileperms($_path)), -4);
                        $_dir_msg .= "❌ {$_name}: write protected ({$_p})\n";
                    } else {
                        $_p = substr(sprintf('%o', @fileperms($_path)), -4);
                        $_dir_msg .= "✅ {$_name}: OK ({$_p})\n";
                    }
                }
                // بررسی لاگ
                $_log_file = __DIR__ . '/data/logs/' . date('Y-m-d') . '.log';
                $_log_status = is_writable($_log_file) ? "✅" : "❌";
                $_dir_msg .= "📝 لاگ امروز: {$_log_status} {$_log_file}\n";
                // بررسی دیتابیس
                try {
                    $_pdo = new PDO("mysql:host={$cfg['db_host']};port=" . ($cfg['db_port'] ?? 3306) . ";charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
                    $_db_test = $_pdo->query("SELECT 1")->fetchColumn();
                    $_dir_msg .= "🗄️ MySQL: ✅ Connected\n";
                } catch (Exception $e) {
                    $_dir_msg .= "🗄️ MySQL: ❌ " . htmlspecialchars($e->getMessage()) . "\n";
                }
                // دیسک
                $_disk = function_exists('disk_free_space') ? round(disk_free_space(__DIR__) / 1024 / 1024, 1) : '?';
                $_dir_msg .= "💾 فضای دیسک آزاد: {$_disk} MB\n";
                // وبهوک
                $_wh = "❓ unknown";
                if (!empty($cfg['main_token']) && $cfg['main_token'] !== 'PUT_MAIN_BOT_TOKEN_HERE') {
                    $_wh_info = @json_decode(@file_get_contents("https://api.telegram.org/bot{$cfg['main_token']}/getWebhookInfo"), true);
                    if (!empty($_wh_info['ok'])) {
                        $_wh = $_wh_info['result']['url'] ?? 'no url';
                        $_wh .= " (pending: " . ($_wh_info['result']['pending_update_count'] ?? 0) . ")";
                    }
                }
                $_dir_msg .= "🔗 وبهوک: {$_wh}\n";
                BotApi::send($TOKEN, $chatId, implode("\n", $_diag) . "\n\n" . $_dir_msg);
                return;
            }
            
            case '📊 آمار':
                $bots = $store->allBots();
                $totalChildUsers = 0;
                foreach ($bots as $b) {
                    if ($b['status'] !== 'active') continue;
                    $pdo = childPdo($cfg, $b);
                    if ($pdo) {
                        $c = childCount($pdo, $b);
                        if ($c > 0) $totalChildUsers += $c;
                        // آزادسازی اتصال قبل از ربات بعدی (جلوگیری از انباشت اتصال همزمان)
                        $pdo = null;
                    }
                }
                BotApi::send($TOKEN, $chatId,
                    "📊 <b>آمار ربات‌ساز</b>\n\n👥 کاربران: {$store->countUsers()}\n🤖 ربات‌ها: {$store->countBots()}\n👤 مجموع کاربران ربات‌ها: {$totalChildUsers}");
                return;

            case '📣 همگانی':
                $store->setStep($uid, 'await_broadcast');
                BotApi::send($TOKEN, $chatId, "متن پیام همگانی را بفرست.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;

            case '👥 کاربران مجاز':
                showUsersPanel($TOKEN, $chatId);
                return;

            case '📋 همه ربات‌ها':
                $bots = $store->allBots();
                if (!$bots) { BotApi::send($TOKEN, $chatId, "رباتی ثبت نشده."); return; }
                $t = "📋 <b>همه ربات‌ها</b>\n\n";
                foreach (array_slice($bots, 0, 30) as $b) {
                    $st = $b['status'] === 'active' ? '🟢' : '🔴';
                    $t .= "{$st} #{$b['id']} <b>{$b['folder']}</b> ({$b['type']}) — مالک: <code>{$b['owner_id']}</code> — @{$b['bot_username']}\n";
                }
                BotApi::send($TOKEN, $chatId, $t);
                return;
        }
    }

    BotApi::send($TOKEN, $chatId, "دستور نامعتبر است.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
}

// ================= steps =================
function handleStep(array $cfg, Store $store, string $TOKEN, array $SUPERS, array $user, $chatId, string $text, array $msg, string $step, array $temp): void
{
    $uid = (int)$user['user_id'];
    $admin = isAdmin($user, $SUPERS);

    // دکمه‌های ناوبری همیشه باید کار کنند — حتی وسط یک مرحلهٔ ورودی.
    // ترتیب مهم است: این چک‌ها قبل از switch اصلی هستند تا متن «برگشت» در
    // stepهای همگانی (await_broadcast/await_child_broadcast) به‌اشتباه برای همه ارسال نشود.
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
        BotApi::send($TOKEN, $chatId, "انصراف داده شد. 🏠", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
        return;
    }

    switch ($step) {
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
            BotApi::send($TOKEN, $chatId, "✅ ربات شناسایی شد: @" . ($me['result']['username'] ?? '?') . "\n\nحالا آیدی عددی ادمین را بفرست:\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
            return;
        }

        case 'await_admin_id': {
            if (!preg_match('/^\d{5,}$/', $text)) {
                BotApi::send($TOKEN, $chatId, "⛔️ آیدی عددی بفرست.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
                return;
            }
            $store->setStep($uid, 'await_folder', ['admin_id' => (int)$text]);
            BotApi::send($TOKEN, $chatId, "حالا یک نام انگلیسی کوتاه بفرست (مثلا: <code>shop1</code>)\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
            return;
        }

        case 'await_folder': {
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
            $type = $temp['type'] ?? 'faxima';
            if (!in_array($type, ['faxima', 'mirza'], true)) {
                $type = 'faxima';
            }
            BotApi::send($TOKEN, $chatId, "⏳ در حال ساخت ربات <b>{$slug}</b> ...");
            // ===== بررسی پیش‌نیازها قبل از ساخت =====
            $_prereq_err = Manager::checkBuildPrerequisites($type);
            if ($_prereq_err !== '') {
                Logger::getInstance()->error('build', "Prerequisites failed for {$slug} ({$type}): {$_prereq_err}");
                BotApi::send($TOKEN, $chatId, "❌ خطا در ساخت ربات:\n{$_prereq_err}\nنام دیگری بفرست، یا برگرد / انصراف بده.", ['reply_markup' => Nav::stepKb()]);
                return;
            }
            try {
                // ===== پردازش غیرهمزمان =====
                @set_time_limit(0);
                @ignore_user_abort(true);

                $result = buildBot($cfg, $store, $TOKEN, $uid, $type, $temp, $slug);
                $store->clearStep($uid);
                $doneMsg = $result['custom_message']
                    ?? "🎉 <b>ربات آماده شد!</b>\n\n🤖 @{$result['bot_username']}\n📁 پوشه: <code>{$slug}</code>\n🗄 دیتابیس: <code>{$result['db']}</code>\n🔗 وبهوک: ست شد ✅";
                BotApi::send($TOKEN, $chatId, $doneMsg,
                    ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
            } catch (Throwable $e) {
                // Throwable: خطاهای Error/TypeError هم باید به کاربر پیام بدهند نه اینکه
                // استثناي uncaught ⇒ 500 ⇒ حلقهٔ retry تلگرام شوند.
                Logger::getInstance()->error('build', "Build failed ({$slug}): " . $e->getMessage());
                // مرحله عمداً باقی می‌ماند تا کاربر بتواند همان‌جا نام دیگری بفرستد
                // («دوباره تلاش کن» یعنی همین). انصراف با ❌ انصراف / 🏠 منو.
                BotApi::send($TOKEN, $chatId, "❌ خطا در ساخت ربات: " . htmlspecialchars(Manager::sanitizeDbError($e->getMessage())) . "\nنام دیگری بفرست، یا برگرد / انصراف بده.", ['reply_markup' => Nav::stepKb()]);
            }
            return;
        }

        case 'await_broadcast': {
            if (!$admin) { $store->clearStep($uid); return; }
            @set_time_limit(0);
            @ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

            $ids = $store->allUserIds();
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
            BotApi::send($TOKEN, $chatId, "✅ همگانی تمام شد: {$ok}/{$total}", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
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
            $pdo = childPdo($cfg, $bot);
            if (!$pdo) { $store->clearStep($uid); BotApi::send($TOKEN, $chatId, "⛔️ اتصال دیتابیس ناموفق."); return; }
            $ids = childUsers($pdo, $bot);
            if (empty($ids)) { $store->clearStep($uid); BotApi::send($TOKEN, $chatId, "⛔️ کاربری یافت نشد."); return; }
            $ok = 0;
            $total = count($ids);
            $tok = childToken($bot);
            @set_time_limit(0);
            @ignore_user_abort(true);
            foreach (array_chunk($ids, 10) as $chunk) {
                foreach ($chunk as $id) {
                    $r = BotApi::call($tok, 'copyMessage', ['chat_id' => $id, 'from_chat_id' => $msg['chat']['id'], 'message_id' => $msg['message_id']]);
                    if (!empty($r['ok'])) $ok++;
                    usleep(50000);
                }
            }
            $store->clearStep($uid);
            BotApi::send($TOKEN, $chatId, "✅ همگانی {$bot['folder']}: {$ok}/{$total}", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }
    }

    $store->clearStep($uid);
    BotApi::send($TOKEN, $chatId, "مرحله نامشخص.", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
}

// ================= build bot =================
function buildBot(array $cfg, Store $store, string $TOKEN, int $owner, string $type, array $temp, string $slug): array
{
    $types = Manager::validTypes();
    if (!isset($types[$type])) throw new Exception("نوع ربات نامعتبر است");

    // پیش‌بررسی نسخهٔ PHP — قبل از mkdir/دیتابیس تا هیچ منبعی ساخته نشود.
    // بدون این، ربات «موفقیت‌آمیز» ساخته می‌شود ولی index.php و table.php اش 500 می‌دهند.
    Manager::assertTemplatePhpCompatible($type);

    $tplDir = Manager::templateDir($type);
    $botDir = Manager::childBotsDir() . '/' . $slug;

    // توکن در مرحله قبل رمزنگاری‌شده ذخیره شده؛ برای استفاده واقعی رمزگشایی کن
    $plainToken = childToken(['token' => $temp['token'] ?? '']);
    if (!preg_match('/^\d+:[\w\-]{20,}$/', $plainToken)) throw new Exception("توکن نامعتبر است؛ از اول شروع کن.");

    $dbCreated = false;
    $dbName = '';
    $dirCreated = false;

    // ===== ادعای اتمیک پوشه =====
    // mkdir در صورت وجود از قبل شکست می‌خورد؛ بنابراین دو ساخت همزمان با یک نام،
    // هرگز روی یک پوشه کار نمی‌کنند. قبلاً usleep+is_dir بود که مسابقه را فقط «کم‌احتمال» می‌کرد
    // و rollbackِ ساختِ دومی پوشهٔ اول را پاک می‌کرد.
    if (!@mkdir($botDir, 0755, true)) {
        if (file_exists($botDir)) throw new Exception("⛔️ این نام قبلاً استفاده شده.");
        // Detailed diagnostic: show path, parent perms, owner
        $_parent = dirname($botDir);
        $_perm = @stat($_parent) ? substr(sprintf('%o', @fileperms($_parent)), -4) : '?';
        $_owner = @stat($_parent) ? (function_exists('posix_getpwuid') ? (posix_getpwuid(@fileowner($_parent)) ?: ['name'=>$_owner_uid])['name'] : '?') : '?';
        $_owner_uid = @fileowner($_parent) ?? '?';
        throw new Exception("ساخت پوشه «{$slug}» ممکن نشد ❌\n"
            ."مسیر: {$botDir}\n"
            ."پوشه والد: {$_parent}\n"
            ."پرمیشن: {$_perm} (owner: {$_owner_uid}:{$_owner})\n"
            ."علت احتمالی: پوشه bots/ مال www-data نیست\n"
            ."حل: bash tools/install.sh یا chown www-data:www-data bots/");
    }
    $dirCreated = true;

    try {
        // توجه: vendor/ حتماً کپی می‌شود — هر دو سورس به vendor/autoload.php نیاز حیاتی دارند
        Manager::copyDir($tplDir, $botDir, ['docker/', 'docker-compose.yml', '.env.example', 'vpnbot/', 'install.sh', 'images.jpeg', 'composer.json', 'composer.lock', 'installer/']);

        if ($type === 'mirza') {
            [$dbName, $tablePrefix] = Manager::createDatabase($cfg, $slug, null);
            $dbCreated = true;
            $parts = parse_url(rtrim($cfg['base_url'], '/'));
            $domainPath = ($parts['host'] ?? '') . ($parts['path'] ?? '') . '/bots/' . $slug;

            Manager::patchMirzaConfig($botDir, $cfg, $dbName, $plainToken, (int)($temp['admin_id'] ?? $owner), $temp['bot_username'] ?? '', $domainPath);
            Manager::removeDir($botDir . '/installer');

            // ===== پاکسازی فایل‌های اضافی (بدون دست‌زدن به vendor/) =====
            Manager::cleanupExtraFiles($botDir, ['docker/', 'docker-compose.yml', '.env.example', 'vpnbot/', 'install.sh', 'images.jpeg', 'composer.json', 'composer.lock', 'installer/']);

            $webhook = Manager::webhookUrl($cfg, $slug, 'mirza');
            $set = BotApi::setWebhook($plainToken, $webhook);
            $webhookSet = true;
            $webhookNote = empty($set['ok']) ? ' (خطای وبهوک)' : '';
            $tableOk = Manager::runMirzaTable(
                dirname($webhook) . '/table.php',
                Manager::mirzaTableSecret($plainToken)
            );

            // ===== رمزنگاری توکن =====
            $secretKey = $GLOBALS['secretKey'] ?? Manager::DEFAULT_SECRET_KEY;
            $encToken = encryptToken($plainToken, $secretKey);

            $store->addBot([
                'owner_id' => $owner, 'type' => 'mirza', 'folder' => $slug,
                'token' => $encToken, 'bot_username' => $temp['bot_username'] ?? '',
                'bot_id' => $temp['bot_id'] ?? 0, 'admin_id' => (int)($temp['admin_id'] ?? $owner),
                'db_name' => $dbName, 'db_table_prefix' => '', 'webhook_url' => $webhook,
                'status' => 'active',
            ]);
            $store->incrementBuildCount($owner);
            $approved = $store->getApprovedRequestByUser($owner);
            if ($approved) $store->markRequestUsed((int)$approved['id']);

            $msg = "🎉 <b>ربات میرزا آماده شد!</b>\n🤖 @{$temp['bot_username']}\n📁 پوشه: <code>{$slug}</code>\n🗄 دیتابیس: <code>{$dbName}</code>\n🔗 وبهوک: " . ($webhookNote !== '' ? '⚠️ خطا' : 'ست شد ✅') . "\n🗂 جدول‌ها: " . ($tableOk ? '✅' : '⚠️ دستی بازش کن');
            return ['bot_username' => $temp['bot_username'] ?? '', 'db' => $dbName, 'custom_message' => $msg];
        }

        // فاکسیما
        [$dbName, $tablePrefix] = Manager::createDatabase($cfg, $slug, null);
        $dbCreated = true;
        $parts = parse_url(rtrim($cfg['base_url'], '/'));
        $domainPath = ($parts['host'] ?? '') . ($parts['path'] ?? '') . '/bots/' . $slug;

        Manager::patchFaximaConfig($botDir, $cfg, $dbName, $plainToken, (int)($temp['admin_id'] ?? $owner), $temp['bot_username'] ?? '', $domainPath);
        Manager::removeDir($botDir . '/installer');

        // ===== پاکسازی فایل‌های اضافی (بدون دست‌زدن به vendor/) =====
        Manager::cleanupExtraFiles($botDir, ['docker/', 'docker-compose.yml', '.env.example', 'vpnbot/', 'install.sh', 'images.jpeg', 'composer.json', 'composer.lock', 'installer/']);

        $webhook = Manager::webhookUrl($cfg, $slug, 'faxima');
        $secret = Manager::faximaWebhookSecret($plainToken);
        $set = BotApi::setWebhook($plainToken, $webhook, $secret);
        $webhookSet = true;
        $webhookNote = empty($set['ok']) ? ' (خطای وبهوک: ' . htmlspecialchars($set['description'] ?? 'unknown') . ')' : '';
        $tableOk = Manager::triggerTable(
            dirname($webhook) . '/table.php',
            Manager::faximaTableSecret($plainToken)
        );

        // ===== رمزنگاری توکن =====
        $encToken = encryptToken($plainToken, $GLOBALS['secretKey'] ?? Manager::DEFAULT_SECRET_KEY);

        $store->addBot([
            'owner_id' => $owner, 'type' => 'faxima', 'folder' => $slug,
            'token' => $encToken, 'bot_username' => $temp['bot_username'] ?? '',
            'bot_id' => $temp['bot_id'] ?? 0, 'admin_id' => (int)($temp['admin_id'] ?? $owner),
            'db_name' => $dbName, 'db_table_prefix' => '', 'webhook_url' => $webhook,
            'status' => 'active',
        ]);
        $store->incrementBuildCount($owner);
        $approved = $store->getApprovedRequestByUser($owner);
        if ($approved) $store->markRequestUsed((int)$approved['id']);

        $msg = "🎉 <b>ربات فاکسیما آماده شد!</b>\n🤖 @{$temp['bot_username']}\n📁 پوشه: <code>{$slug}</code>\n🗄 دیتابیس: <code>{$dbName}</code>\n🔗 وبهوک: " . ($webhookNote !== '' ? '⚠️ خطا' : 'ست شد ✅') . "\n🗂 جدول‌ها: " . ($tableOk ? '✅' : '⚠️ دستی بازش کن') . $webhookNote;
        return ['bot_username' => $temp['bot_username'] ?? '', 'db' => $dbName, 'custom_message' => $msg];
    } catch (Throwable $e) {
        // ===== ROLLBACK =====
        // Throwable (نه فقط Exception) تا TypeErrorها و خطاهای هسته هم rollback شوند؛
        // وگرنه پوشه/دیتابیس/وبهوک نیمه‌کاره می‌ماند.
        // ۱) وبهوک ثبت‌شده روی تلگرام باید برداشته شود تا ربات حذف‌شده دیگر پینگ نگیرد
        if (!empty($webhookSet) && $plainToken !== '') {
            try {
                $whR = BotApi::deleteWebhook($plainToken);
                // نتیجه قبلاً دور ریخته می‌شد؛ حالا شکست rollback هم دیده می‌شود
                if (!is_array($whR) || empty($whR['ok'])) {
                    error_log("Rollback webhook FAILED: " . (($whR['description'] ?? '') ?: 'no response'));
                }
            }
            catch (Throwable $whErr) { error_log("Rollback webhook: " . $whErr->getMessage()); }
        }
        // ۲) دیتابیس ساخته‌شده حذف شود
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
    if (!canUse($user, $SUPERS) && !$store->hasApprovedRequest($uid)) {
        if ($data === 'cancel' || $data === Nav::CB_BACK_MAIN || $data === Nav::CB_BACK_TYPE) {
            $store->clearStep($uid);
            if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "❌ انصراف داده شد.");
            BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($user, $SUPERS, $store)]);
            return;
        }
        BotApi::send($TOKEN, $chatId, "⛔️ دسترسی ندارید.");
        return;
    }

    if ($data === 'cancel') {
        $store->clearStep($uid);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "❌ انصراف داده شد.");
        BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
        return;
    }

    // ===== برگشت‌های اینلاین (همیشه بدون خطا) =====
    if ($data === Nav::CB_BACK_MAIN) {
        $store->clearStep($uid);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "🏠 منوی اصلی");
        BotApi::send($TOKEN, $chatId, "🏠 منوی اصلی", ['reply_markup' => mainMenu($store->user($uid), $SUPERS, $store)]);
        return;
    }
    if ($data === Nav::CB_BACK_TYPE) {
        $store->clearStep($uid);
        if ($msgId > 0) BotApi::edit($TOKEN, $chatId, $msgId, "نوع ربات را انتخاب کن 👇");
        BotApi::send($TOKEN, $chatId, "نوع ربات را انتخاب کن 👇", ['reply_markup' => Nav::typeMenu()]);
        return;
    }
    if ($data === Nav::CB_MY_BOTS) {
        sendMyBotsList($store, $TOKEN, $chatId, $uid, $msgId);
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

    if (str_starts_with($data, 'newbot:')) {
        $type = substr($data, 7);
        $names = Manager::validTypes();
        if (!isset($names[$type])) return;
        // ===== بازبینی مجدد سقف و مجوز (همان چک‌های handleMessage) =====
        if ($store->hasBot($uid)) {
            BotApi::send($TOKEN, $chatId, "⛔️ شما قبلاً ربات دارید. هر کاربر فقط می‌تواند یک ربات بسازد.");
            return;
        }
        if (!$admin && !$store->hasApprovedRequest($uid)) {
            if ($store->hasPendingRequest($uid)) {
                BotApi::send($TOKEN, $chatId, "⏳ درخواست شما در انتظار تأیید ادمین است.");
            } else {
                $store->addPendingRequest($uid, 'bot');
                BotApi::send($TOKEN, $chatId, "📝 درخواست شما ثبت شد.\nلطفاً منتظر تأیید ادمین بمانید.");
            }
            return;
        }
        $store->setStep($uid, 'await_bot_token', ['type' => $type]);
        BotApi::send($TOKEN, $chatId, "توکن ربات <b>{$names[$type]}</b> را بفرست.\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL,
            ['reply_markup' => Nav::stepKb()]);
        return;
    }

    if (str_starts_with($data, 'mybot:')) {
        $id = (int)substr($data, 6);
        $bot = $store->botById($id);
        if (!$bot || ((int)$bot['owner_id'] !== $uid && !$admin)) { BotApi::send($TOKEN, $chatId, "⛔️ دسترسی نداری."); return; }
        showBotPanel($cfg, $store, $TOKEN, $chatId, $msgId, $bot, $data);
        return;
    }

    // ادمین: رد درخواست
    if (str_starts_with($data, 'act:decline:')) {
        if (!$admin) return;
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
        if (!$admin) return;
        $requestId = (int)substr($data, 12);
        $req = $store->getPendingRequestById($requestId);
        if (!$req) { BotApi::send($TOKEN, $chatId, "⛔️ درخواست یافت نشد."); return; }
        $store->approveRequest($requestId);
        BotApi::send($TOKEN, $chatId, "✅ درخواست تأیید شد!");
        BotApi::send($TOKEN, (int)$req['user_id'], "🎉 درخواستت تأیید شد! حالا «🤖 ساخت ربات جدید» را بزن و نوع ربات را انتخاب کن.");
        return;
    }

    if (str_starts_with($data, 'act:')) {
        $parts = explode(':', $data);
        $action = $parts[1] ?? '';
        $botId = (int)($parts[2] ?? 0);
        $bot = $store->botById($botId);
        if (!$bot || ((int)$bot['owner_id'] !== $uid && !$admin)) return;
        botAction($cfg, $store, $TOKEN, $SUPERS, $user, $chatId, $msgId, $bot, $action);
        return;
    }

    // ===== canUse برای users:add/remove/list =====
    if ($data === 'users:add') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        $store->setStep($uid, 'await_user_add');
        BotApi::send($TOKEN, $chatId, "آیدی عددی کاربر جدید را بفرست:\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($data === 'users:remove') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        $store->setStep($uid, 'await_user_remove');
        BotApi::send($TOKEN, $chatId, "آیدی عددی کاربر برای حذف دسترسی:\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
        return;
    }
    if ($data === 'users:list') {
        if (!$admin) { BotApi::send($TOKEN, $chatId, "⛔️ فقط ادمین."); return; }
        $ids = $store->allowedIds();
        if (!$ids) { BotApi::send($TOKEN, $chatId, "👥 کاربران مجاز (0):\n—"); return; }
        // پیام تلگرام سقف ۴۰۹۶ کاراکتر دارد → چندتکه ارسال می‌شود
        $lines = array_map(fn($i) => "<code>$i</code>", $ids);
        $chunks = array_chunk($lines, 40);
        foreach ($chunks as $i => $chunk) {
            $head = $i === 0
                ? "👥 کاربران مجاز (" . count($ids) . "):\n"
                : "👥 کاربران مجاز — ادامه (" . ($i * 40 + 1) . "–" . min(count($ids), ($i + 1) * 40) . "):\n";
            BotApi::send($TOKEN, $chatId, $head . implode("\n", $chunk));
            if (count($chunks) > 1) usleep(80000);
        }
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
    if (!$requests) { BotApi::send($TOKEN, $chatId, "✅ هیچ درخواستی نیست."); return; }
    $total = count($requests);
    $slice = array_slice($requests, 0, 10);
    $note = $total > count($slice)
        ? "\nنمایش " . count($slice) . " از {$total} — با تأیید/رد هر درخواست، بعدی‌ها نمایان می‌شوند."
        : '';
    BotApi::send($TOKEN, $chatId, "📋 <b>درخواست‌ها</b> ({$total}):{$note}");
    foreach ($slice as $r) {
        $name = htmlspecialchars($r['first_name'] ?? 'نامشخص');
        $username = $r['username'] ? "@" . htmlspecialchars($r['username']) : '';
        BotApi::send($TOKEN, $chatId,
            "👤 <b>{$name}</b> {$username} (ID: <code>{$r['user_id']}</code>)",
            ['reply_markup' => BotApi::ikb([
                [['text' => '✅ تأیید', 'callback_data' => "act:approve:{$r['id']}"], ['text' => '❌ رد', 'callback_data' => "act:decline:{$r['id']}"]],
            ])]);
        usleep(100000);
    }
}

function showBotPanel(array $cfg, Store $store, string $TOKEN, $chatId, $msgId, array $bot, string $from = ''): void
{
    $t = botPanelText($cfg, $bot);
    $kb = Nav::botPanelKb($bot);
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
            $pdo = childPdo($cfg, $bot);
            $c = $pdo ? childCount($pdo, $bot) : -1;
            BotApi::send($TOKEN, $chatId, "📊 آمار <b>{$bot['folder']}</b>: " . ($c >= 0 ? $c : 'نامشخص') . " کاربر");
            return;
        }
        case 'broadcast': {
            $store->setStep($uid, 'await_child_broadcast', ['bot_id' => $bot['id']]);
            BotApi::send($TOKEN, $chatId, "پیام همگانی برای ربات <b>{$bot['folder']}</b> را بفرست:\nبرای برگشت: " . Nav::BACK . " | برای انصراف: " . Nav::CANCEL, ['reply_markup' => Nav::stepKb()]);
            return;
        }
        case 'webhook': {
            $tok = childToken($bot);
            $secret = $bot['type'] === 'faxima' ? Manager::faximaWebhookSecret($tok) : null;
            $url = Manager::webhookUrl($cfg, $bot['folder'], $bot['type']);
            $r = BotApi::setWebhook($tok, $url, $secret ?? null);
            BotApi::send($TOKEN, $chatId, !empty($r['ok']) ? "✅ وبهوک مجدد ست شد:\n<code>{$url}</code>" : "❌ خطا: " . htmlspecialchars($r['description'] ?? 'unknown'));
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
                $secret = $bot['type'] === 'faxima' ? Manager::faximaWebhookSecret($tok) : null;
                $url = Manager::webhookUrl($cfg, $bot['folder'], $bot['type']);
                $setR = BotApi::setWebhook($tok, $url, $secret);
                if (!is_array($setR) || empty($setR['ok'])) {
                    Logger::getInstance()->warning('toggle', "setWebhook برای {$bot['folder']} ناموفق: " . (($setR['description'] ?? '') ?: 'no response'));
                }
            }
            $bot['status'] = $new;
            showBotPanel($cfg, $store, $TOKEN, $chatId, $msgId, $bot);
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
    }
}
