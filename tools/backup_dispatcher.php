<?php
// ===== سیستم بک‌آپ خودکار دیتابیس ربات‌های فرزند =====
// استفاده: php tools/backup_dispatcher.php
// کرون لینک (هر روز ۲ بار): 0 3,15 * * * php /path/to/botsaz-faxima/tools/backup_dispatcher.php
//
// برای هر ربات فعال:
//   ۱. دیتابیس MySQL را mysqldump می‌کند
//   ۲. فایل gzip می‌کند
//   ۳. به ادمین ربات (از طریق تلگرام) ارسال می‌کند
//   ۴. فایل‌های موقت را پاک می‌کند

require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Logger.php';

$cfgFile = __DIR__ . '/../config.php';
if (!file_exists($cfgFile)) {
    error_log("[backup] config.php not found");
    exit(1);
}
$cfg = require $cfgFile;

// ===== پیش‌نیازها =====
if (!extension_loaded('pdo_mysql')) {
    error_log("[backup] pdo_mysql not loaded");
    exit(1);
}
if (!function_exists('curl_init') || !class_exists('CURLFile')) {
    error_log("[backup] curl/CURLFile not available");
    exit(1);
}

// ===== پیدا کردن مسیر mysqldump =====
function findMysqldump(): string {
    // اول از همه بررسی کن آیا توی PATH هست
    $which = @exec('command -v mysqldump 2>/dev/null');
    if ($which && file_exists($which)) return $which;
    // مسیرهای استاندارد
    foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/sbin/mysqldump'] as $p) {
        if (file_exists($p)) return $p;
    }
    return '';
}

$MYSQLDUMP = findMysqldump();
if (!$MYSQLDUMP) {
    Logger::getInstance()->error('backup', 'mysqldump not found');
    echo "ERROR: mysqldump not found\n";
    exit(1);
}

$store = new Store($cfg['manager_db'], $cfg);
$log = Logger::getInstance();
$log->info('backup', '=== Starting daily backup ===');

// ===== قفل اجرای همزمان =====
$lockFile = dirname(__DIR__) . '/data/backup_dispatcher.lock';
$lock = @fopen($lockFile, 'c');
if ($lock !== false && !@flock($lock, LOCK_EX | LOCK_NB)) {
    @fclose($lock);
    $log->info('backup', 'Another backup instance running — skipped');
    echo "backup_dispatcher already running — skipped.\n";
    exit(0);
}

// ===== دریافت همه ربات‌های فعال =====
$bots = $store->allBots();
$activeBots = array_filter($bots, fn($b) => ($b['status'] ?? '') === 'active');

$total = count($activeBots);
$success = 0;
$failed = 0;
$skipped = 0;

$tempDir = dirname(__DIR__) . '/data/backup_tmp';
@mkdir($tempDir, 0755, true);

// ===== پیکربندی دیتابیس =====
$cfgDbHost = $cfg['db_host'] ?? '127.0.0.1';
$cfgDbPort = $cfg['db_port'] ?? '3306';
$cfgDbUser = $cfg['db_user'] ?? 'botsaz';
$cfgDbPass = $cfg['db_pass'] ?? '';

foreach ($activeBots as $bot) {
    $folder = $bot['folder'] ?? '';
    $dbName = $bot['db_name'] ?? '';
    $adminId = $bot['admin_id'] ?? 0;
    $botToken = $bot['token'] ?? '';
    $botUsername = $bot['bot_username'] ?? '';
    $type = $bot['type'] ?? '';

    if (!$dbName || !$adminId || !$botToken) {
        $log->warning('backup', "Skipping bot {$folder}: missing db_name/admin_id/token");
        $skipped++;
        continue;
    }

    $log->info('backup', "Processing bot {$folder} (db: {$dbName}, admin: {$adminId})...");

    // ===== مرحله ۱: mysqldump =====
    $timestamp = date('Y-m-d_H-i-s');
    $sqlFile = $tempDir . "/{$folder}_{$timestamp}.sql";
    $gzipFile = $sqlFile . '.gz';

    // استفاده از پسورد از config.php اما امن
    $dumpCmd = sprintf(
        '%s --host=%s --port=%s --user=%s --password=%s --default-character-set=utf8mb4 --single-transaction --quick %s > %s 2>&1',
        escapeshellcmd($MYSQLDUMP),
        escapeshellarg($cfgDbHost),
        escapeshellarg($cfgDbPort),
        escapeshellarg($cfgDbUser),
        escapeshellarg($cfgDbPass),
        escapeshellarg($dbName),
        escapeshellarg($sqlFile)
    );

    exec($dumpCmd, $dumpOutput, $dumpReturn);
    if ($dumpReturn !== 0 || !file_exists($sqlFile) || filesize($sqlFile) == 0) {
        $log->error('backup', "mysqldump failed for {$folder}: " . implode("\n", $dumpOutput));
        @unlink($sqlFile);
        $failed++;
        continue;
    }

    $sqlSize = round(filesize($sqlFile) / 1024, 1);
    $log->info('backup', "mysqldump OK for {$folder} ({$sqlSize} KB)");

    // ===== مرحله ۲: gzip =====
    exec("gzip -9 {$sqlFile} 2>&1", $gzOutput, $gzReturn);
    if ($gzReturn !== 0 || !file_exists($gzipFile)) {
        $log->error('backup', "gzip failed for {$folder}: " . implode("\n", $gzOutput));
        @unlink($sqlFile);
        $failed++;
        continue;
    }

    $gzSize = round(filesize($gzipFile) / 1024, 1);
    $log->info('backup', "gzip OK for {$folder} ({$gzSize} KB)");

    // ===== مرحله ۳: ارسال به ادمین تلگرام =====
    $sent = sendBackupToAdmin($botToken, $adminId, $gzipFile, $folder, $botUsername, $log);

    if ($sent) {
        $success++;
        $log->info('backup', "Backup sent to admin {$adminId} for bot {$folder} ✔");
    } else {
        $failed++;
        $log->error('backup', "Failed to send backup to admin {$adminId} for bot {$folder}");
    }

    // ===== پاک‌سازی =====
    @unlink($gzipFile);
    @unlink($sqlFile); // ممکن است حذف نشده باشد
}

// ===== پاک‌سازی فایل‌های قدیمی =====
$gracePeriod = time() - 3600; // 1 hour
foreach (glob($tempDir . '/*.gz') as $oldFile) {
    if (filemtime($oldFile) < $gracePeriod) @unlink($oldFile);
}

@fclose($lock);

$log->info('backup', "=== Backup complete: {$success} succeeded, {$failed} failed, {$skipped} skipped, {$total} total ===");
echo "Backup: {$success} OK, {$failed} failed, {$skipped} skipped, {$total} total\n";

// ===== تابع ارسال بک‌آپ به تلگرام =====
function sendBackupToAdmin(string $botToken, int $chatId, string $filePath, string $folder, string $botUsername, Logger $log): bool {
    $url = "https://api.telegram.org/bot{$botToken}/sendDocument";

    $postData = new CURLFile($filePath, 'application/gzip', "backup_{$folder}_" . date('Y-m-d') . ".sql.gz");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => (string)$chatId,
            'document' => $postData,
            'caption' => "🔒 بک‌آپ دیتابیس ربات {$botUsername}\n📅 " . date('Y-m-d H:i:s') . "\n📦 پوشه: {$folder} 📝 نوع: {$type}",
            'disable_notification' => false,
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        $log->error('backup', "curl failed: HTTP={$httpCode}, error={$curlError}, response={$response}");
        return false;
    }

    $result = json_decode($response, true);
    if (!is_array($result) || ($result['ok'] ?? false) === false) {
        $log->error('backup', "Telegram API error: " . json_encode($result));
        return false;
    }

    return true;
}
