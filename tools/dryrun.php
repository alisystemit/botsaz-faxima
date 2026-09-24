<?php
// ===== تست ساخت ربات بدون فراخوانی تلگرام =====
// استفاده: php tools/dryrun.php [type] [slug]
// مثال: php tools/dryrun.php faxima testbot123

require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/BotApi.php';
require_once __DIR__ . '/../src/Logger.php';

$cfgFile = __DIR__ . '/../config.php';
if (!file_exists($cfgFile)) {
    echo "❌ config.php not found. Run: php tools/install.php\n";
    exit(1);
}
$cfg = require $cfgFile;

$store = new Store($cfg['manager_db'], $cfg);
$log = Logger::getInstance();

// ===== آرگومان‌ها =====
$type = $argv[1] ?? 'faxima';
$slug = $argv[2] ?? 'dryrun_' . time();

echo "=========================================\n";
echo "  🧪 Dry Run Test\n";
echo "=========================================\n\n";

// ===== بررسی پیش‌نیازها =====
echo "[1] بررسی پیش‌نیازها...\n";
if (!in_array($type, ['faxima', 'mirza'])) {
    echo "❌ Unknown bot type: {$type}\n";
    echo "   Available: faxima, mirza\n";
    exit(1);
}
echo "   Type: {$type}\n";

// نسخهٔ PHP باید به حداقل نیاز vendor خودِ قالب برسد — وگرنه ربات ساخته‌شده 500 می‌دهد.
// (الان فاکسیما ≥ 8.2 است؛ خودِ فایل platform_check قالب مرجع سنجش است.)
$minPhp = Manager::templateMinPhp($type);
if ($minPhp !== null) {
    $need = Manager::formatPhpVersionId($minPhp);
    if (PHP_VERSION_ID < $minPhp) {
        echo "❌ PHP version: running " . PHP_VERSION . ", template needs >= {$need}\n";
        echo "   ربات فرزند (index.php و table.php) با این نسخه خطای 500 می‌دهد.\n";
        echo "   ساخت متوقف شد تا ربات خراب تحویل داده نشود.\n";
        exit(1);
    }
    echo "   PHP: " . PHP_VERSION . " (needs >= {$need}) OK\n";
}

// ===== بررسی قالب =====
echo "[2] بررسی قالب...\n";
$tplDir = Manager::templateDir($type);
if (!is_dir($tplDir)) {
    echo "❌ Template directory missing: {$tplDir}\n";
    exit(1);
}
echo "   Template found: {$tplDir}\n";

// ===== بررسی فایل‌های ضروری =====
echo "[3] بررسی فایل‌های قالب...\n";
$requiredFiles = [
    'faxima' => ['index.php', 'config.php', 'table.php', 'botapi.php', 'function.php', 'lib/WebhookAuth.php'],
    'mirza' => ['index.php', 'config.php', 'table.php', 'botapi.php', 'functions.php'],
];
$missing = [];
foreach ($requiredFiles[$type] as $f) {
    if (!file_exists("{$tplDir}/{$f}")) {
        $missing[] = $f;
    }
}
$failed = false;
if (!empty($missing)) {
    echo "   ❌ Missing files: " . implode(', ', $missing) . "\n";
    $failed = true;
} else {
    echo "   All required files present ✓\n";
}

// ===== بررسی تکراری نبودن =====
echo "[4] بررسی تکراری نبودن نام...\n";
$slug = Manager::slugify($slug);
if ($store->botByFolder($slug) || is_dir(Manager::childBotsDir() . '/' . $slug)) {
    echo "   ❌ Folder '{$slug}' already exists!\n";
    exit(1);
}
echo "   Name '{$slug}' is available ✓\n";

// ===== شبیه‌سازی ساخت =====
echo "[5] شبیه‌سازی ساخت ربات...\n";
$botDir = Manager::childBotsDir() . '/' . $slug;

// همان فهرست استثناهای buildBot — باید دقیقاً یکی باشد تا dry-run واقعی باشد
$excludePaths = ['docker/', 'docker-compose.yml', '.env.example', 'vpnbot/', 'install.sh', 'images.jpeg', 'composer.json', 'composer.lock', 'installer/'];

echo "   [5a] کپی قالب... ";
try {
    // مثل buildBot واقعی: پوشه‌های حجیم/غیرضروری کپی نمی‌شوند (ولی vendor لازم است)
    Manager::copyDir($tplDir, $botDir, $excludePaths);
    echo "OK\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    // پاکسازی نسخهٔ نیمه‌کاره — وگرنه اجرای بعدی با همین نام «تکراری» می‌خورد
    if (is_dir($botDir)) Manager::removeDir($botDir);
    exit(1);
}

echo "   [5b] پچ config.php... ";
try {
    if ($type === 'faxima') {
        Manager::patchFaximaConfig($botDir, $cfg, "botsaz_{$slug}_test", '123:FakeToken', 999001, 'testbot', 'example.com/bots/');
    } else {
        Manager::patchMirzaConfig($botDir, $cfg, "botsaz_{$slug}_test", '123:FakeToken', 999001, 'testbot', 'example.com/bots/');
    }
    echo "OK\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    Manager::removeDir($botDir);
    exit(1);
}

echo "   [5c] بررسی کانفیگ پچ‌شده... ";
$patchedConfig = file_get_contents($botDir . '/config.php');
if ($patchedConfig === false) {
    echo "FAILED: config.php not found\n";
    Manager::removeDir($botDir);
    exit(1);
}
// بررسی placeholder باقی‌مانده
$placeholders = ['{DATABASE_', '{BOT_TOKEN}', '{ADMIN_', '{DOMAIN.', '{BOT_USER'];
$leftover = [];
foreach ($placeholders as $ph) {
    if (str_contains($patchedConfig, $ph)) {
        $leftover[] = $ph;
    }
}
if (!empty($leftover)) {
    echo "❌ Unreplaced placeholders: " . implode(', ', $leftover) . "\n";
    $failed = true;
} else {
    echo "All placeholders replaced ✓\n";
}

echo "   [5d] بررسی syntax کانفیگ... ";
$phpBin = trim((string)($cfg['php_bin'] ?? ''));
if ($phpBin === '' || !is_file($phpBin)) $phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
// shell_exec ممکن است در disable_functions باشد؛ آن‌وقت «فراخوانی»اش Error می‌دهد
// (که @ هم ساکتش نمی‌کند) و کل dryrun را می‌کشد. در آن حالت lint را رد می‌کنیم.
$lintResult = function_exists('shell_exec')
    ? (@shell_exec("\"{$phpBin}\" -l \"{$botDir}/config.php\" 2>&1") ?? '')
    : '';
if ($lintResult === '' && !function_exists('shell_exec')) {
    echo "SKIPPED (shell_exec disabled)\n";
} elseif (str_contains($lintResult, 'No syntax errors')) {
    echo "OK\n";
} else {
    echo "FAILED: {$lintResult}\n";
    $failed = true;
}

// ===== [5e] تأیید اینکه واقعاً هیچ فایل مستثنایی کپی نشده =====
echo "   [5e] بررسی نشت فایل‌های مستثنی... ";
$leaked = [];
foreach ($excludePaths as $p) {
    if (file_exists($botDir . '/' . $p)) $leaked[] = $p;
}
if (is_dir($botDir . '/installer')) $leaked[] = 'installer/';
if ($leaked) {
    echo "LEAKED: " . implode(', ', $leaked) . "\n";
    $failed = true;
} else {
    echo "none ✓\n";
}

// ===== پاکسازی =====
echo "[6] پاکسازی...\n";
Manager::removeDir($botDir);
echo "   Test directory cleaned ✓\n";

// ===== نتیجه =====
echo "\n=========================================\n";
if ($failed) {
    echo "  ❌ Dry run FAILED — مشکلات بالا را برطرف کن.\n";
    echo "=========================================\n";
    exit(1);
}
echo "  ✅ Dry run completed successfully!\n";
echo "  Bot '{$slug}' can be safely created.\n";
echo "=========================================\n";

// ===== بررسی فایل‌های اضافی =====
echo "\nفایل‌هایی که در ساخت واقعی کپی نمی‌شوند (بررسی‌شده در [5e]):\n";
echo "  docker/, docker-compose.yml, .env.example, vpnbot/, install.sh, images.jpeg,\n";
echo "  composer.json, composer.lock, installer/\n";
echo "  توجه: vendor/ حتماً کپی می‌شود (بدون آن ربات فرزند کار نمی‌کند).\n";

echo "\nنکته: برای اجرای واقعی بدون dry-run:\n";
echo "  php tools/dryrun.php {$type} ACTUAL_NAME\n";
echo "\n";
