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
        $cronFile = Manager::templateDir($type) . '/cron/';

        if (!is_dir($cronFile)) {
            // میرزا cron از ربات خودش اجرا می‌شود
            $log->debug('cron', "No cron directory for {$type}: {$bot['folder']}");
            continue;
        }

        // اجرای هر فایل کرون
        $cronFiles = glob($cronFile . '*.php');
        foreach ($cronFiles as $cronFile) {
            $fileName = basename($cronFile);

            // رد کردن فایل‌های غیررسمی
            if (in_array($fileName, ['index.php', 'cron.php', '.htaccess'])) continue;

            $cmd = "\"{$cfg['php_bin'] ?? 'php'}\" \"{$cronFile}\" \"{$bot['token']}\" \"{$bot['folder']}\"";
            exec($cmd . ' > /dev/null 2>&1 &');

            $log->debug('cron', "Executed {$fileName} for {$bot['folder']}");
        }

        // برای فاکسیما: cron/cron.php با php CLI اجرا می‌شود
        $faximaCron = Manager::childBotsDir() . '/' . $bot['folder'] . '/cron/cron.php';
        if (file_exists($faximaCron)) {
            $cmd = "\"{$cfg['php_bin'] ?? 'php'}\" \"{$faximaCron}\"";
            exec($cmd . ' > /dev/null 2>&1 &');
            $log->debug('cron', "Executed faxima cron.php for {$bot['folder']}");
        }

    } catch (Exception $e) {
        $log->error('cron', "Error processing bot {$bot['folder']}: " . $e->getMessage());
    }
}

// ===== پاکسازی ربات‌های مرده =====
cleanupDeadBots($store, $log);

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
