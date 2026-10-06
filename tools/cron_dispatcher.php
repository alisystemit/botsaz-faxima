<?php
// ===== کرون دیسبچر مرکزی برای ربات‌های فرزند =====
// استفاده: php tools/cron_dispatcher.php
// کرون لینک: */5 * * * * php /path/to/botsaz-faxima/tools/cron_dispatcher.php

require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/BotApi.php';
require_once __DIR__ . '/../src/Logger.php';

$cfgFile = __DIR__ . '/../config.php';
if (!file_exists($cfgFile)) {
    Logger::getInstance()->error('cron', 'config.php not found');
    exit(1);
}
$cfg = require $cfgFile;
$store = new Store($cfg['manager_db'], $cfg);
$log = Logger::getInstance();

// ===== قفل اجرای همزمان =====
// دو نوبت کرونِ همزمان (مثلاً اجرای دستی هنگام کرون سیستمی) نباید روی هم بنویسند/تکرار شوند.
$dispatchLock = @fopen(dirname(__DIR__) . '/data/cron_dispatcher.lock', 'c');
if ($dispatchLock !== false && !@flock($dispatchLock, LOCK_EX | LOCK_NB)) {
    @fclose($dispatchLock);
    $log->info('cron', 'Another cron_dispatcher instance is running — skipped');
    echo "cron_dispatcher already running — skipped.\n";
    exit(0);
}
// قفل تا پایان اجرا نگه داشته می‌شود (بسته‌شدن پروسه خودش آزادش می‌کند)؛
// اگر قفل در دسترس نبود، بی‌قفل ادامه بده — بهتر از این است که کرون اصلاً اجرا نشود.

$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

/** مسیر باینری PHP — از config، وگرنه خود همین PHP که الان دارد اجرا می‌شود */
function phpBin(array $cfg): string
{
    $b = trim((string)($cfg['php_bin'] ?? ''));
    if ($b !== '' && (is_file($b) || strpos($b, DIRECTORY_SEPARATOR) !== false || strpos($b, '/') !== false)) return $b;
    return defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
}

/** ساخت خط فرمان اجرای اسکریپت کرون؛ توکن از طریق env (نه argv) */
function cronCmd(array $cfg, string $script, array $env = []): string
{
    $cmd = '"' . phpBin($cfg) . '" "' . $script . '"';
    foreach ($env as $k => $v) {
        // ویندوز: set K=V && … — لینوکس: K='V' …
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'set "' . $k . '=' . $v . '" && ' . $cmd;
        } else {
            $cmd = escapeshellarg($k) . '=' . escapeshellarg($v) . ' ' . $cmd;
        }
    }
    return $cmd;
}

/** اجرای غیرهمزمان بدون وابستگی به /dev/null (لینوکس) یا start (ویندوز) */
function runAsync(string $cmd, bool $isWindows): void
{
    $line = $isWindows
        // start /B بدون معطلی، خروجی را هم دور می‌ریزد
        ? 'start /B "" ' . $cmd . ' > NUL 2>&1'
        : $cmd . ' > /dev/null 2>&1 &';

    // خیلی از هاست‌های اشتراکی exec/popen/shell_exec را در disable_functions می‌گذارند؛
    // در آن حالت «فراخوانی» exec() خطای Call to undefined function می‌دهد که Error است،
    // نه Exception — پس در catch (Exception) پایین‌تر گرفته نمی‌شد و کل دیسبچر با
    // «اولین» رباتِ دارای کرون Fatal می‌شد؛ یعنی کرون هیچ رباتی اجرا نمی‌شد بی‌آنکه
    // دلیلی در لاگ بیفتد. حالا اول runner موجود پیدا می‌شود؛ اگر هیچ‌کدام نبود فقط
    // هشدار داده می‌شود تا ادمین به روش ۲ (کرون مستقیم هر ربات) برود.
    static $runnerChecked = false;
    static $runner = null;
    if (!$runnerChecked) {
        $runnerChecked = true;
        foreach (['exec', 'popen', 'shell_exec'] as $fn) {
            if (function_exists($fn)) { $runner = $fn; break; }
        }
        if ($runner === null) {
            Logger::getInstance()->warning(
                'cron',
                'exec/popen/shell_exec همگی غیرفعال‌اند (disable_functions)؛ کرون فرزندان اجرا نشد. '
                . 'از روش ۲ استفاده کن: */5 * * * * php <root>/bots/<slug>/cron/cron.php'
            );
            echo "⚠️  exec is disabled by this host — child crons were NOT dispatched (see data/logs).\n";
        }
    }
    if ($runner === null) return;

    if ($runner === 'exec') { @exec($line); return; }
    if ($runner === 'popen') {
        $h = @popen($line, 'r');
        if (is_resource($h)) @pclose($h);
        return;
    }
    @shell_exec($line);
}

$log->info('cron', 'Cron dispatcher started');
// برای دکمهٔ «وضعیت کرون» داخل ربات — در پایان در data/cron_state.json می‌نویسیم
$__cronStart = microtime(true);

// ===== اجرای pending_update از دیسپچر کرون =====
// دکمهٔ «آپدیت ربات‌ساز» یا «دریافت سورس بروز» وقتی وب‌کاربر روی پوشهٔ سورس
// دسترسیِ نوشتن ندارد، فقط یک نشانه در data/pending_update می‌گذارد. اینجا
// آن نشانه مصرف می‌شود و همان `bash tools/update.sh` اجرا می‌شود — دقیقاً مثل
// اجرای دستی، ولی با مالکِ فایلِ کرون (معمولاً ادمینِ واقعیِ درخت).
// نکته: pending از نوع 'templates' دیگر update.sh --templates-only را صدا نمی‌زند؛
// همان اسکریپتِ همگام‌سازیِ PHP اجرا می‌شود. update.sh --templates-only فقط
// همین اسکریپت را صدا می‌زد و قبلاً git reset اجرا می‌کرد که بروزرسانی‌های
// templates/ را برمی‌گرداند.
foreach (['full' => ''] as $_pendingKind => $_pendingArg) {
    $_flag = __DIR__ . '/../data/pending_update/' . $_pendingKind . '.json';
    if (!is_file($_flag)) continue;
    @unlink($_flag);
    $log->info('cron', "running pending '{$_pendingKind}' update");
    $_root = dirname(__DIR__);
    $_bash = is_executable('/usr/bin/bash') ? '/usr/bin/bash' : (is_executable('/bin/bash') ? '/bin/bash' : 'bash');
    $_env = 'GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=' . escapeshellarg($_root);
    $_cmd = 'cd ' . escapeshellarg($_root) . ' && ' . $_env . ' ' . $_bash . ' tools/update.sh ' . $_pendingArg . ' 2>&1';
    $_out = [];
    $_rc = 1;
    @exec($_cmd, $_out, $_rc);
    $_tail = implode("\n", array_slice($_out, -30));
    $log->info('cron', "pending '{$_pendingKind}' update finished rc={$_rc}");
    @file_put_contents(__DIR__ . '/../data/logs/selfupdate.log', "\n========== pending {$_pendingKind} " . date('Y-m-d H:i:s') . " ==========\nrc={$_rc}\n" . $_tail . "\n", FILE_APPEND | LOCK_EX);
}
$_flagT = __DIR__ . '/../data/pending_update/templates.json';
if (is_file($_flagT)) {
    @unlink($_flagT);
    $log->info('cron', "running pending 'templates' sync");
    $_root = dirname(__DIR__);
    $_out = [];
    $_rc = 1;
    @exec('cd ' . escapeshellarg($_root) . ' && ' . escapeshellarg(phpBin($cfg)) . ' tools/sync_templates.php 2>&1', $_out, $_rc);
    $_tail = implode("\n", array_slice($_out, -30));
    $log->info('cron', "pending 'templates' sync finished rc={$_rc}");
    @file_put_contents(__DIR__ . '/../data/logs/selfupdate.log', "\n========== pending templates " . date('Y-m-d H:i:s') . " ==========\nrc={$_rc}\n" . $_tail . "\n", FILE_APPEND | LOCK_EX);
}

// ===== بررسی تمام ربات‌های فعال =====
$bots = $store->allBots();
$activeBots = array_filter($bots, fn($b) => $b['status'] === 'active');

$log->info('cron', 'Found ' . count($activeBots) . ' active bots');

foreach ($activeBots as $bot) {
    try {
        $botDir = Manager::childBotsDir() . '/' . $bot['folder'];
        if (!is_dir($botDir)) {
            $log->warning('cron', "Bot directory missing: {$bot['folder']}");
            continue;
        }

        $type = $bot['type'];
        // کرون‌های هر ربات از پوشه خود همان ربات اجرا می‌شوند (نه قالب)
        $cronDir = $botDir . '/cron/';

        if (!is_dir($cronDir)) {
            // کرون ندارد
            $log->debug('cron', "No cron directory for {$type}: {$bot['folder']}");
            continue;
        }

        // توکن ذخیره‌شده رمزنگاری است؛ برای اسکریپت فرزند رمزگشایی کن
        $plainToken = Manager::decryptChildToken($bot['token'] ?? '', Manager::secretKey($cfg));

        // اجرای هر فایل کرون (توکن از طریق env داده می‌شود، نه argv — در لوگ/ps لو نمی‌رود)
        $cronFiles = glob($cronDir . '*.php');
        foreach ($cronFiles as $cronFile) {
            $fileName = basename($cronFile);

            // رد کردن فایل‌های غیررسمی و فایل‌های کمکی (مثل _guard.php)
            if (in_array($fileName, ['index.php', 'cron.php', '.htaccess']) || str_starts_with($fileName, '_')) continue;

            $cmd = cronCmd($cfg, $cronFile, ['BOT_TOKEN' => $plainToken, 'BOT_FOLDER' => $bot['folder']]);
            runAsync($cmd, $isWindows);

            $log->debug('cron', "Executed {$fileName} for {$bot['folder']}");
        }

        // برای فاکسیما: cron/cron.php با php CLI اجرا می‌شود
        $faximaCron = Manager::childBotsDir() . '/' . $bot['folder'] . '/cron/cron.php';
        if (file_exists($faximaCron)) {
            $cmd = cronCmd($cfg, $faximaCron, ['BOT_FOLDER' => $bot['folder']]);
            runAsync($cmd, $isWindows);
            $log->debug('cron', "Executed faxima cron.php for {$bot['folder']}");
        }

    } catch (Throwable $e) {
        // Throwable نه Exception: خطای Error (مثل exec غیرفعال یا ارور بارگذاری کلاس)
        // نباید ربات‌های بعدی در همین حلقه را از دست بدهد.
        $log->error('cron', "Error processing bot {$bot['folder']}: " . $e->getMessage());
    }
}

// ===== پاکسازی ربات‌های مرده =====
cleanupDeadBots($store, $log);

// ===== پاکسازی قدیمی‌ترین رد update_id های پردازش‌شده (جلوگیری از رشد بی‌انتها) =====
try {
    $store->pruneProcessedUpdates(5000);
    $log->debug('cron', 'processed_updates pruned');
} catch (Throwable $e) {
    $log->warning('cron', 'prune failed: ' . $e->getMessage());
}

// ===== بکاپ دوره‌ای دیتابیس ربات‌ها (روزی ۱-۲ بار طبق config) =====
// اسلات‌ها در config.php: 'db_backup' => ['enabled' => true, 'times' => ['03:00','15:00']]
// خود DbBackup چک می‌کند اسلات رسیده یا نه؛ پس اجرای هر ۵ دقیقه‌ای بی‌خطر است
// و ارسال تکراری نمی‌شود. جزئیات در data/db_backup.json.
try {
    require_once __DIR__ . '/../src/DbBackup.php';
    $backupRes = DbBackup::runDue($cfg, $store);
    foreach ($backupRes['sent'] as $_s) $log->info('cron', 'backup: ' . $_s);
    foreach ($backupRes['failed'] as $_f => $_e) $log->warning('cron', "backup failed for {$_f}: {$_e}");
    unset($backupRes, $_s, $_f, $_e);
} catch (Throwable $e) {
    $log->warning('cron', 'backup slot check failed: ' . $e->getMessage());
}

// ===== ثبت وضعیت اجرا برای دکمهٔ «⏰ وضعیت کرون» داخل ربات =====
// ربات این فایل را می‌خواند و اعلام می‌کند کرون «سالم» است یا خاموش.
// عمداً بعد از همهٔ مراحل نوشته می‌شود؛ یعنی «last_run» فقط وقتی به‌روز می‌شود
// که اجرا واقعاً به پایان رسیده باشد (در lock-skip هم اصلاً وارد اینجا نمی‌شویم).
try {
    $__stateFile = dirname(__DIR__) . '/data/cron_state.json';
    $__state = [
        'last_run'    => date('c'),
        'duration_ms' => (int)round((microtime(true) - $__cronStart) * 1000),
        'bots'        => count($activeBots),
        'pid'         => (int)(function_exists('getmypid') ? getmypid() : 0),
        'error'       => '',
    ];
    @file_put_contents($__stateFile, json_encode($__state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
} catch (Throwable $__e) {
    $log->warning('cron', 'state write failed: ' . $__e->getMessage());
}

$log->info('cron', 'Cron dispatcher finished');

/**
  * پاکسازی ربات‌هایی که پوشه‌شان نیست ولی در دیتابیس هست
  */
function cleanupDeadBots(Store $store, Logger $log): void
{
    $bots = $store->allBots();
    foreach ($bots as $bot) {
        $botDir = Manager::childBotsDir() . '/' . $bot['folder'];
        if (!is_dir($botDir)) {
            // پوشه وجود ندارد - بررسی اینکه هنوز فعال است
            if ($bot['status'] === 'active') {
                $log->warning('cron', "Marking dead bot as disabled: {$bot['folder']}");
                $store->setBotStatus($bot['id'], 'disabled');
            }
        }
    }
}

echo "Cron dispatcher completed.\n";
