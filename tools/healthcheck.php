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

// حالت CI: --json باید «فقط» JSON خروجی بدهد؛ متن انسان‌خوان در بافر جمع و دور ریخته می‌شود
$jsonMode = isset($argv[1]) && $argv[1] === '--json';
if ($jsonMode) ob_start();

$store = new Store($cfg['manager_db'], $cfg);
$log = Logger::getInstance();

$errors = [];
$warnings = [];
$ok = [];

echo "=========================================\n";
echo "  🏥 ربات‌ساز سلامت‌رسان\n";
echo "=========================================\n\n";

// ===== ۰. بررسی دسترسی /root (مهم‌ترین مشکل رایج) =====
// وقتی پروژه در /root هست، Apache نمی‌تونه ازش رد بشه
// و همه درخواست‌ها 403 برگردونده می‌شن
echo "[0] بررسی دسترسی /root...\n";
if (is_dir('/root')) {
    $rootMode = fileperms('/root');
    $rootOctal = substr(sprintf('%o', $rootMode), -4);
    $rootOthers = decoct($rootMode & 0007);
    $isUnderRoot = (strpos(__DIR__, '/root/') === 0);
    
    // اگر /root هیچ execute برای others نداشته باشه، یا فقط execute-1 باشد و پروژه زیرش باشد
    if ($rootOthers == '0') {
        $errors[] = "/root دسترسی execute نداره (mode $rootOctal) - Apache نمی‌تونه ازش رد بشه";
        $errors[] = "  حل: chmod o+x /root  (یا bash tools/install.sh برای فیکس خودکار)";
    } elseif ($rootOthers == '1' && $isUnderRoot) {
        // execute-only بدون read، Apache ممکنه مشکل داشته باشه
        $warnings[] = "/root فقط execute داره (mode $rootOctal) - بهتره chmod o+x بزنید";
        $warnings[] = "  حل: chmod o+x /root  (یا bash tools/install.sh)";
    } else {
        $ok[] = "/root قابل دسترسیه (mode $rootOctal)";
    }
}

// ===== ۰.۱. بررسی دسترسی bots/ (ساخت ربات‌های فرزند) =====
// پوشه bots/ باید نوشتنی توسط www-data باشه تا ربات جدید ساخته بشه
$_bots_dir = dirname(__DIR__) . '/../bots';
if (is_dir($_bots_dir)) {
    $_bots_octal = substr(sprintf('%o', fileperms($_bots_dir)), -4);
    $_bots_uid = fileowner($_bots_dir);
    $_bots_owner = function_exists('posix_getpwuid') ? (posix_getpwuid($_bots_uid) ?: ['name' => $_bots_uid])['name'] : $_bots_uid;
    if ($_bots_owner !== 'www-data') {
        $warnings[] = "bots/ مال $_bots_owner هست (mode $_bots_octal) - ساخت ربات فرزند کار نمی‌کنه";
        $warnings[] = "  حل: chown www-data:www-data bots/ (یا bash tools/install.sh)";
    } else {
        $ok[] = "bots/ قابل نوشتنه (owner: www-data, mode $_bots_octal)";
    }
}

// ===== ۱. بررسی config.php =====
echo "[1] بررسی config.php...\n";
if (!empty($cfg['main_token']) && $cfg['main_token'] !== 'PUT_MAIN_BOT_TOKEN_HERE') {
    $ok[] = 'config.php: main_token set';
} else {
    $errors[] = 'config.php: main_token not set!';
}
if (!empty($cfg['super_admins']) && count($cfg['super_admins']) > 0) {
    $ok[] = 'config.php: super_admins set';
    // مقدار نمونهٔ config.example.php ⇒ عملاً هیچ ادمین واقعی‌ای شناخته نمی‌شود،
    // ولی قبلاً همین «set» گزارش می‌شد و چک‌لیست سبز دروغ می‌گفت.
    if (in_array(123456789, array_map('intval', $cfg['super_admins']), true)) {
        $warnings[] = 'config.php: super_admins still contains the example value 123456789 - replace it with YOUR real numeric Telegram ID';
    }
} else {
    $errors[] = 'config.php: super_admins not set!';
}
// قبلاً هیچ چکی برای secret_key نبود — کلید خالی/پیش‌فرض/تقلبی کار می‌کند ولی بین همه
// مشترک است و رمزگذاری توکن فرزندان را بی‌اثر می‌کند. هر دو شکل را می‌گیرد:
// هم مقدار دقیق Manager::DEFAULT_SECRET_KEY، هم هر مقداری که هنوز «change-this...» باشد.
$sk = trim((string)($cfg['secret_key'] ?? ''));
$skDefault = Manager::DEFAULT_SECRET_KEY;   // Manager.php بالا require شده است
if ($sk === '' || $sk === $skDefault || stripos($sk, 'change-this') === 0) {
    $warnings[] = 'config.php: secret_key is empty/default/placeholder - generate a random one BEFORE building bots';
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
        $tok = Manager::decryptChildToken($bot['token'] ?? '', Manager::secretKey($cfg));
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

// ===== ۱۱. بررسی فایل‌های اضافی در کپی ساخته‌شدهٔ ربات‌ها =====
// قبلاً کدی اجرا نمی‌شد (فقط پیام «check completed»). حالا واقعاً بررسی می‌کنیم
// که استثناهای copyDir و cleanupExtraFiles در پوشهٔ هیچ رباتی نشت نکرده باشد.
echo "[11] بررسی فایل‌های اضافی در کپی ربات‌ها...\n";
$extraPaths = ['docker', 'docker-compose.yml', '.env.example', 'install.sh', 'vpnbot', 'composer.json', 'composer.lock', 'images.jpeg'];
$leaked = 0;
foreach ($dbBots as $bot) {
    $botDir = Manager::childBotsDir() . '/' . $bot['folder'];
    if (!is_dir($botDir)) continue;
    foreach ($extraPaths as $p) {
        if (file_exists($botDir . '/' . $p)) {
            $errors[] = "Leaked excluded path '{$p}' in built bot {$bot['folder']}";
            $leaked++;
        }
    }
    // installer/ باید حذف شده باشد
    if (is_dir($botDir . '/installer')) {
        $errors[] = "installer/ not removed from built bot {$bot['folder']}";
        $leaked++;
    }
    // هیچ config.php دیگری (حتی تو در تو) نباید در کپی مانده باشد
    $rootConfig = realpath($botDir . '/config.php') ?: ($botDir . '/config.php');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($botDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if ($f->isDir() || $f->getFilename() !== 'config.php') continue;
        if ($f->getPathname() === $rootConfig) continue;
        $errors[] = "Nested config.php in built bot {$bot['folder']}: " . substr($f->getPathname(), strlen($botDir) + 1);
        $leaked++;
    }
}
if ($leaked === 0) {
    $ok[] = "No excluded paths leaked into built bots (" . count($dbBots) . " checked)";
}

// ===== ۱۲. بررسی کرون =====
echo "[12] بررسی تنظیمات کرون...\n";
$cronLine = "*/5 * * * * php " . __DIR__ . "/cron_dispatcher.php";
$ok[] = "Cron line: {$cronLine}";
$ok[] = "Add this line to your crontab: crontab -e";

// ===== ۱۳. بررسی زندهٔ توکن با getMe =====
// بدون این چک، توکن باطل/placeholder فقط با خطای 404 مبهم خودش را نشان می‌داد.
echo "[13] بررسی زندهٔ توکن (getMe)...\n";
$liveToken = (string)($cfg['main_token'] ?? '');
if ($liveToken === '' || $liveToken === 'PUT_MAIN_BOT_TOKEN_HERE') {
    $errors[] = 'getMe skipped: main_token is empty/placeholder (set a real token from @BotFather)';
} elseif (!function_exists('curl_init')) {
    $warnings[] = 'getMe skipped: curl not available';
} else {
    try {
        $me = BotApi::getMe($liveToken);
        if (!empty($me['ok'])) {
            $ok[] = 'Telegram getMe OK: @' . ($me['result']['username'] ?? '?');
        } else {
            $code = (int)($me['error_code'] ?? 0);
            if ($code === 404) {
                $errors[] = 'getMe 404: token is invalid or revoked - request a fresh /token from @BotFather';
            } else {
                $errors[] = 'getMe failed: ' . ($me['description'] ?? 'unknown');
            }
        }
    } catch (Throwable $e) {
        $warnings[] = 'getMe skipped (network error): ' . $e->getMessage();
    }
}

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

// خروجی JSON برای CI — در این حالت هیچ متن دیگری چاپ نمی‌شود
if ($jsonMode) {
    $payload = json_encode([
        'ok' => $ok,
        'warnings' => $warnings,
        'errors' => $errors,
        'healthy' => empty($errors),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    if (ob_get_level() > 0) ob_end_clean();
    echo $payload;
}

// کد خروج: 0 = سالم، 1 = خطا (برای CI / اسکریپت‌ها)
exit(empty($errors) ? 0 : 1);
