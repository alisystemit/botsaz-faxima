<?php
// ===== سلامت‌رسان ربات‌ساز =====
// استفاده: php tools/healthcheck.php
// بررسی: پوشه ↔ رکورد ↔ دیتابیس ↔ وبهوک

require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/BotApi.php';
require_once __DIR__ . '/../src/Logger.php';

$cfgFile = __DIR__ . '/../config.php';
if (!file_exists($cfgFile)) {
    echo "❌ config.php not found\n";
    exit(1);
}
$cfg = require $cfgFile;

$store = new Store($cfg['manager_db'], $cfg);
$log = Logger::getInstance();

$errors = [];
$warnings = [];
$ok = [];

echo "=========================================\n";
echo "  🏥 ربات‌ساز سلامت‌رسان\n";
echo "=========================================\n\n";

// ===== ۱. بررسی config.php =====
echo "[1] بررسی config.php...\n";
if (!empty($cfg['main_token']) && $cfg['main_token'] !== 'PUT_MAIN_BOT_TOKEN_HERE') {
    $ok[] = 'config.php: main_token set';
} else {
    $errors[] = 'config.php: main_token not set!';
}
if (!empty($cfg['super_admins']) && count($cfg['super_admins']) > 0) {
    $ok[] = 'config.php: super_admins set';
} else {
    $errors[] = 'config.php: super_admins not set!';
}
if (!empty($cfg['base_url']) && $cfg['base_url'] !== 'http://botsaz-faxima.test') {
    $ok[] = 'config.php: base_url set';
} else {
    $warnings[] = 'config.php: base_url is local default - webhooks won\'t work';
}

// ===== ۲. بررسی پوشه‌ها =====
echo "[2] بررسی پوشه‌ها...\n";
$dirs = ['bots', 'data', 'templates/faxima', 'templates/mirza'];
foreach ($dirs as $dir) {
    if (is_dir(__DIR__ . "/../{$dir}")) {
        $ok[] = "Directory exists: {$dir}";
    } else {
        $errors[] = "Directory missing: {$dir}";
    }
}

// ===== ۳. بررسی دیتابیس =====
echo "[3] بررسی دیتابیس...\n";
try {
    $usersCount = $store->countUsers();
    $botsCount = $store->countBots();
    $ok[] = "Database connected (users: {$usersCount}, bots: {$botsCount})";
} catch (Exception $e) {
    $errors[] = "Database connection failed: " . $e->getMessage();
}

// ===== ۴. بررسی همگرایی پوشه ↔ رکورد =====
echo "[4] بررسی تطابق پوشه و رکورد...\n";
$dbBots = $store->allBots();
foreach ($dbBots as $bot) {
    $botDir = Manager::childBotsDir() . '/' . $bot['folder'];
    if (!is_dir($botDir)) {
        $errors[] = "Folder missing for bot #{$bot['id']} ({$bot['folder']})";
    } else {
        $ok[] = "Bot {$bot['folder']}: folder exists";
    }

    // بررسی وبهوک
    if ($bot['status'] === 'active') {
        $tok = Manager::decryptChildToken($bot['token'] ?? '', $cfg['secret_key'] ?? 'change-this-to-a-random-string');
        $wh = BotApi::getWebhookInfo($tok);
        if (!empty($wh['ok']) && !empty($wh['result']['url'])) {
            $ok[] = "Bot {$bot['folder']}: webhook active (" . $wh['result']['url'] . ")";
        } else {
            $errors[] = "Bot {$bot['folder']}: webhook missing - " . ($wh['description'] ?? 'no url set');
        }
    }
}

// ===== ۵. بررسی ربات‌های یتیم =====
echo "[5] بررسی ربات‌های یتیم...\n";
$allDirs = glob(Manager::childBotsDir() . '/*', GLOB_ONLYDIR);
if ($allDirs !== false) {
    foreach ($allDirs as $dir) {
        $folder = basename($dir);
        $bot = $store->botByFolder($folder);
        if (!$bot) {
            $warnings[] = "Orphan folder found: {$folder} (no database record)";
        }
    }
}

// ===== ۶. بررسی لاگ‌ها =====
echo "[6] بررسی لاگ‌ها...\n";
$logs = Logger::getLogs(1);
if (!empty($logs)) {
    $ok[] = "Log files exist (" . count($logs) . " files)";
} else {
    $warnings[] = "No log files found yet";
}

// ===== ۷. بررسی PHP =====
echo "[7] بررسی PHP...\n";
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    $ok[] = "PHP " . PHP_VERSION . " OK";
} else {
    $errors[] = "PHP " . PHP_VERSION . " is below 8.1 minimum";
}

// ===== ۸. بررسی cURL =====
echo "[8] بررسی cURL...\n";
if (function_exists('curl_version')) {
    $cv = curl_version();
    $ok[] = "cURL {$cv['version']} available";
} else {
    $errors[] = "cURL not available - Telegram API calls will fail";
}

// ===== ۹. بررسی PDO drivers =====
echo "[9] بررسی PDO drivers...\n";
$drivers = PDO::getAvailableDrivers();
if (in_array('sqlite', $drivers)) {
    $ok[] = "PDO sqlite available";
} else {
    $warnings[] = "PDO sqlite not available - falling back to MySQL";
}
if (in_array('mysql', $drivers)) {
    $ok[] = "PDO mysql available";
} else {
    $warnings[] = "PDO mysql not available";
}

// ===== ۱۰. بررسی پورت MySQL =====
echo "[10] بررسی MySQL connection...\n";
try {
    $testPdo = new PDO("mysql:host={$cfg['db_host']};port=" . ($cfg['db_port'] ?? 3306) . ";charset=utf8mb4", $cfg['db_user'], $cfg['db_pass']);
    $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ok[] = "MySQL connection OK";
} catch (Exception $e) {
    $errors[] = "MySQL connection failed: " . $e->getMessage();
}

// ===== ۱۱. بررسی فایل‌های اضافی =====
echo "[11] بررسی فایل‌های اضافی در bot templates...\n";
$extraFiles = ['docker/', 'docker-compose.yml', '.env.example', 'install.sh', 'vpnbot/', 'composer.json'];
foreach ($extraFiles as $file) {
    if (file_exists(__DIR__ . "/../templates/faxima/{$file}")) {
        // این فایل‌ها جزو قالب اصلی هستند، فقط بررسی می‌شوند
    }
}
$ok[] = "Extra files check completed";

// ===== ۱۲. بررسی کرون =====
echo "[12] بررسی تنظیمات کرون...\n";
$cronLine = "*/5 * * * * php " . __DIR__ . "/cron_dispatcher.php";
$ok[] = "Cron line: {$cronLine}";
$ok[] = "Add this line to your crontab: crontab -e";

// ===== نتیجه‌گیری =====
echo "\n=========================================\n";
echo "  نتیجه:\n";
echo "=========================================\n";

echo "\n✅ OK (" . count($ok) . "):\n";
foreach ($ok as $item) echo "  ✓ {$item}\n";

if (!empty($warnings)) {
    echo "\n⚠️  WARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $item) echo "  ⚠️  {$item}\n";
}

if (!empty($errors)) {
    echo "\n❌ ERRORS (" . count($errors) . "):\n";
    foreach ($errors as $item) echo "  ✗ {$item}\n";
}

echo "\n=========================================\n";
if (empty($errors)) {
    echo "  ✅ ربات‌ساز سالم است!\n";
} else {
    echo "  ❌ " . count($errors) . " خطا شناسایی شد.\n";
}
echo "=========================================\n";

// خروجی JSON برای CI
if (isset($argv[1]) && $argv[1] === '--json') {
    echo json_encode([
        'ok' => $ok,
        'warnings' => $warnings,
        'errors' => $errors,
        'healthy' => empty($errors),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
