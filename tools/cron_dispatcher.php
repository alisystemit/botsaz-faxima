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
    if ($isWindows) {
        // start /B بدون معطلی، خروجی را هم دور می‌ریزد
        exec('start /B "" ' . $cmd . ' > NUL 2>&1');
    } else {
        exec($cmd . ' > /dev/null 2>&1 &');
    }
}

$log->info('cron', 'Cron dispatcher started');

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
        $plainToken = Manager::decryptChildToken($bot['token'] ?? '', $cfg['secret_key'] ?? 'change-this-to-a-random-string');

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

    } catch (Exception $e) {
        $log->error('cron', "Error processing bot {$bot['folder']}: " . $e->getMessage());
    }
}

// ===== پاکسازی ربات‌های مرده =====
cleanupDeadBots($store, $log);

// ===== پاکسازی قدیمی‌ترین رد update_id های پردازش‌شده (جلوگیری از رشد بی‌انتها) =====
try {
    $store->pruneProcessedUpdates(5000);
    $log->debug('cron', 'processed_updates pruned');
} catch (Exception $e) {
    $log->warning('cron', 'prune failed: ' . $e->getMessage());
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
