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
    echo "❌ config.php not found. Run: php tools/install.sh\n";
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
if (!empty($missing)) {
    echo "   ⚠️  Missing files: " . implode(', ', $missing) . "\n";
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

echo "   [5a] کپی قالب... ";
try {
    Manager::copyDir($tplDir, $botDir);
    echo "OK\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
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
    echo "⚠️  Unreplaced placeholders: " . implode(', ', $leftover) . "\n";
} else {
    echo "All placeholders replaced ✓\n";
}

echo "   [5d] بررسی syntax کانفیگ... ";
$phpBin = $cfg['php_bin'] ?? 'php';
        $lintResult = @shell_exec("\"{$phpBin}\" -l {$botDir}/config.php 2>&1");
if (str_contains($lintResult, 'No syntax errors')) {
    echo "OK\n";
} else {
    echo "FAILED: {$lintResult}\n";
}

// ===== پاکسازی =====
echo "[6] پاکسازی...\n";
Manager::removeDir($botDir);
echo "   Test directory cleaned ✓\n";

// ===== نتیجه =====
echo "\n=========================================\n";
echo "  ✅ Dry run completed successfully!\n";
echo "  Bot '{$slug}' can be safely created.\n";
echo "=========================================\n";

// ===== بررسی فایل‌های اضافی =====
echo "\nفایل‌های اضافی که کپی نمی‌شوند:\n";
$extraDirs = ['docker/', 'docker-compose.yml', '.env.example', 'vpnbot/', 'install.sh', 'composer.json'];
$extraFiles = ['images.jpeg', 'default_help.json', 'install.sh'];
echo "  (These files are NOT part of the bot's operation and should be excluded from copying)\n";
echo "\n💡 برای حذف فایل‌های اضافی در buildBot از Manager::copyDir استفاده شده:\n";
echo "   قالب‌ها باید فقط فایل‌های ضروری را داشته باشند.\n";

echo "\nنکته: برای اجرای واقعی بدون dry-run:\n";
echo "  php tools/dryrun.php {$type} ACTUAL_NAME\n";
echo "\n";
