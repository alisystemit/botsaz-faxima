<?php
// ===== سیستم بک‌آپ خودکار دیتابیس ربات‌های فرزند =====
// استفاده: php tools/backup_dispatcher.php [--force] [--bot=folder]
// کرون لینک (هر روز ۲ بار): 0 3,15 * * * php /path/to/botsaz-faxima/tools/backup_dispatcher.php
//
// برای هر ربات فعال:
//   ۱. دیتابیس MySQL را دامپ می‌کند (mysqldump، و اگر نبود fallback داخلی PHP)
//   ۲. فایل را gzip می‌کند
//   ۳. با توکن خود همان ربات به ادمینش در تلگرام ارسال می‌کند (sendDocument)
//   ۴. فایل موقت را پاک می‌کند
//
// ساعت‌ها در config.php ('db_backup' => ['enabled'=>true, 'times'=>['03:00','15:00']])
// و ارسال تکراری نمی‌شود (وضعیت در data/db_backup.json) مگر با --force.
// نکته: cron_dispatcher (هر ۵ دقیقه) هم اسلات‌ها را چک می‌کند، پس حتی بدون
// این کرون جدا هم بکاپ سر وقت ارسال می‌شود.

require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/BotApi.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/DbBackup.php';

$cfgFile = __DIR__ . '/../config.php';
if (!file_exists($cfgFile)) {
    fwrite(STDERR, "config.php not found\n");
    exit(1);
}
$cfg = require $cfgFile;

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql not loaded\n");
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$only = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--bot=')) $only = substr($a, 6);
    if ($a === '-h' || $a === '--help') {
        echo "Usage: php tools/backup_dispatcher.php [--force] [--bot=folder]\n";
        exit(0);
    }
}

// ===== قفل اجرای همزمان =====
$lockFile = dirname(__DIR__) . '/data/backup_dispatcher.lock';
$lock = @fopen($lockFile, 'c');
if ($lock !== false && !@flock($lock, LOCK_EX | LOCK_NB)) {
    @fclose($lock);
    Logger::getInstance()->info('backup', 'Another backup instance running — skipped');
    echo "backup_dispatcher already running — skipped.\n";
    exit(0);
}

$store = new Store($cfg['manager_db'], $cfg);
$log = Logger::getInstance();
$log->info('backup', '=== Starting backup run ===');

$res = DbBackup::runDue($cfg, $store, $force, $only);

$okCount = count($res['sent']);
$failCount = count($res['failed']);
$skipCount = count($res['skipped']);
foreach ($res['sent'] as $s) echo "  [SENT] $s\n";
foreach ($res['skipped'] as $s) echo "  [SKIP] $s\n";
foreach ($res['failed'] as $folder => $err) echo "  [FAIL] $folder: $err\n";

@fclose($lock);

$log->info('backup', "=== Backup complete: {$okCount} sent, {$failCount} failed, {$skipCount} skipped ===");
echo "Backup: {$okCount} sent, {$failCount} failed, {$skipCount} skipped\n";

exit($failCount === 0 ? 0 : 1);
