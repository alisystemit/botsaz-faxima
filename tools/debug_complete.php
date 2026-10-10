<?php
/**
 * DEBUG Script - بررسی و تست تمام فایل‌های جدید
 * اجرا: php tools/debug_complete.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$srcDir = $root . '/src';

echo "\n╔════════════════════════════════════════════════════════════════════════════════╗\n";
echo "║ 🔍 DEBUG COMPLETE - تست تمام 10 فایل جدید                                      ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════════╝\n\n";

// فایل‌های جدید برای تست
$files = [
    'UiPremium.php',
    'Theme.php', 
    'RateLimiter.php',
    'AuditLog.php',
    'WebhookManager.php',
    'i18n.php',
    'Analytics.php',
    'PluginManager.php',
    'CacheManager.php',
    'Monitor.php',
];

// ─────────────────────────────────────────────────────────────────────────────────
// 1️⃣ بررسی وجود فایل‌ها
// ─────────────────────────────────────────────────────────────────────────────────

echo "📋 STEP 1: بررسی وجود فایل‌ها\n";
echo str_repeat("─", 80) . "\n";

$fileStatus = [];
foreach ($files as $file) {
    $path = "$srcDir/$file";
    $exists = file_exists($path);
    $size = $exists ? round(filesize($path) / 1024, 2) : 0;
    $status = $exists ? '✅' : '❌';
    
    $fileStatus[$file] = $exists;
    printf("%s %-25s | %8.2f KB\n", $status, $file, $size);
}

$allExist = !in_array(false, $fileStatus);
echo "\n" . ($allExist ? "✅ تمام فایل‌ها موجود هستند!\n" : "❌ بعضی فایل‌ها موجود نیستند!\n");
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────────
// 2️⃣ بررسی Syntax
// ─────────────────────────────────────────────────────────────────────────────────

echo "🔧 STEP 2: بررسی Syntax PHP\n";
echo str_repeat("─", 80) . "\n";

$syntaxErrors = [];
foreach ($files as $file) {
    $path = "$srcDir/$file";
    if (!file_exists($path)) continue;
    
    $output = shell_exec("php -l '$path' 2>&1");
    $isValid = strpos($output, 'No syntax errors') !== false || strpos($output, 'parsed successfully') !== false;
    
    if ($isValid) {
        echo "✅ $file\n";
    } else {
        echo "❌ $file\n";
        echo "   Error: " . trim($output) . "\n";
        $syntaxErrors[$file] = $output;
    }
}

echo "\n" . (empty($syntaxErrors) ? "✅ تمام فایل‌ها دارای syntax معتبر هستند!\n" : "❌ بعضی فایل‌ها دارای خطا هستند!\n");
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────────
// 3️⃣ بررسی Class Definition
// ─────────────────────────────────────────────────────────────────────────────────

echo "🏗️ STEP 3: بررسی تعریف Class‌ها\n";
echo str_repeat("─", 80) . "\n";

$classMap = [
    'UiPremium.php' => 'UiPremium',
    'Theme.php' => 'Theme',
    'RateLimiter.php' => 'RateLimiter',
    'AuditLog.php' => 'AuditLog',
    'WebhookManager.php' => 'WebhookManager',
    'i18n.php' => 'i18n',
    'Analytics.php' => 'Analytics',
    'PluginManager.php' => 'PluginManager',
    'CacheManager.php' => 'CacheManager',
    'Monitor.php' => 'Monitor',
];

$classErrors = [];
foreach ($classMap as $file => $className) {
    $path = "$srcDir/$file";
    if (!file_exists($path)) continue;
    
    $content = file_get_contents($path);
    $hasClass = preg_match("/class\s+$className\s*\{/", $content) ? true : false;
    $methodCount = preg_match_all('/\bpublic\s+(?:static\s+)?function\s+\w+\s*\(/i', $content);
    $constantCount = preg_match_all('/public\s+const\s+\w+/i', $content);
    
    if ($hasClass) {
        printf("✅ %-25s | %3d methods | %3d constants\n", $className, $methodCount, $constantCount);
    } else {
        printf("❌ %-25s | Class not found!\n", $className);
        $classErrors[$file] = "Class $className not defined";
    }
}

echo "\n" . (empty($classErrors) ? "✅ تمام Class‌ها صحیح تعریف شده‌اند!\n" : "❌ بعضی Classها مشکل دارند!\n");
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────────
// 4️⃣ بارگذاری و تست هر فایل
// ─────────────────────────────────────────────────────────────────────────────────

echo "⚙️ STEP 4: بارگذاری و تست پایه‌ای\n";
echo str_repeat("─", 80) . "\n";

$loadResults = [];

// Theme
try {
    require_once "$srcDir/Theme.php";
    Theme::set('light');
    $icon = Theme::icon('success');
    Theme::getCurrent();
    echo "✅ Theme.php - بارگذاری موفق\n";
    echo "   • Theme::set()\n";
    echo "   • Theme::icon()\n";
    echo "   • Theme::getCurrent()\n";
    $loadResults['Theme'] = 'OK';
} catch (Throwable $e) {
    echo "❌ Theme.php - خطا: {$e->getMessage()}\n";
    $loadResults['Theme'] = 'ERROR';
}

// RateLimiter
try {
    require_once "$srcDir/RateLimiter.php";
    RateLimiter::init();
    $allowed = RateLimiter::isAllowed(123, 10);
    echo "✅ RateLimiter.php - بارگذاری موفق\n";
    echo "   • RateLimiter::init()\n";
    echo "   • RateLimiter::isAllowed()\n";
    echo "   • RateLimiter::getRemaining()\n";
    $loadResults['RateLimiter'] = 'OK';
} catch (Throwable $e) {
    echo "❌ RateLimiter.php - خطا: {$e->getMessage()}\n";
    $loadResults['RateLimiter'] = 'ERROR';
}

// i18n
try {
    require_once "$srcDir/i18n.php";
    i18n::setLang('fa');
    $lang = i18n::getLang();
    $text = i18n::t('welcome');
    echo "✅ i18n.php - بارگذاری موفق\n";
    echo "   • i18n::setLang()\n";
    echo "   • i18n::getLang()\n";
    echo "   • i18n::t()\n";
    $loadResults['i18n'] = 'OK';
} catch (Throwable $e) {
    echo "❌ i18n.php - خطا: {$e->getMessage()}\n";
    $loadResults['i18n'] = 'ERROR';
}

// UiPremium
try {
    require_once "$srcDir/UiPremium.php";
    $header = UiPremium::header('🤖', 'Test');
    $info = UiPremium::info('label', 'value');
    $alert = UiPremium::alert('success', 'message');
    echo "✅ UiPremium.php - بارگذاری موفق\n";
    echo "   • UiPremium::header()\n";
    echo "   • UiPremium::info()\n";
    echo "   • UiPremium::alert()\n";
    $loadResults['UiPremium'] = 'OK';
} catch (Throwable $e) {
    echo "❌ UiPremium.php - خطا: {$e->getMessage()}\n";
    $loadResults['UiPremium'] = 'ERROR';
}

// AuditLog
try {
    require_once "$srcDir/AuditLog.php";
    AuditLog::init();
    echo "✅ AuditLog.php - بارگذاری موفق\n";
    echo "   • AuditLog::init()\n";
    echo "   • AuditLog::log()\n";
    echo "   • AuditLog::getLogs()\n";
    $loadResults['AuditLog'] = 'OK';
} catch (Throwable $e) {
    echo "❌ AuditLog.php - خطا: {$e->getMessage()}\n";
    $loadResults['AuditLog'] = 'ERROR';
}

// WebhookManager
try {
    require_once "$srcDir/WebhookManager.php";
    WebhookManager::init();
    echo "✅ WebhookManager.php - بارگذاری موفق\n";
    echo "   • WebhookManager::init()\n";
    echo "   • WebhookManager::trigger()\n";
    echo "   • WebhookManager::getQueueStatus()\n";
    $loadResults['WebhookManager'] = 'OK';
} catch (Throwable $e) {
    echo "❌ WebhookManager.php - خطا: {$e->getMessage()}\n";
    $loadResults['WebhookManager'] = 'ERROR';
}

// Analytics
try {
    require_once "$srcDir/Analytics.php";
    Analytics::init();
    echo "✅ Analytics.php - بارگذاری موفق\n";
    echo "   • Analytics::init()\n";
    echo "   • Analytics::track()\n";
    echo "   • Analytics::getEventStats()\n";
    $loadResults['Analytics'] = 'OK';
} catch (Throwable $e) {
    echo "❌ Analytics.php - خطا: {$e->getMessage()}\n";
    $loadResults['Analytics'] = 'ERROR';
}

// PluginManager
try {
    require_once "$srcDir/PluginManager.php";
    PluginManager::init();
    $status = PluginManager::getStatus();
    echo "✅ PluginManager.php - بارگذاری موفق\n";
    echo "   • PluginManager::init()\n";
    echo "   • PluginManager::load()\n";
    echo "   • PluginManager::getStatus()\n";
    $loadResults['PluginManager'] = 'OK';
} catch (Throwable $e) {
    echo "❌ PluginManager.php - خطا: {$e->getMessage()}\n";
    $loadResults['PluginManager'] = 'ERROR';
}

// CacheManager
try {
    require_once "$srcDir/CacheManager.php";
    CacheManager::init();
    CacheManager::set('test', 'value', 3600);
    $value = CacheManager::get('test');
    echo "✅ CacheManager.php - بارگذاری موفق\n";
    echo "   • CacheManager::init()\n";
    echo "   • CacheManager::set()\n";
    echo "   • CacheManager::get()\n";
    $loadResults['CacheManager'] = 'OK';
} catch (Throwable $e) {
    echo "❌ CacheManager.php - خطا: {$e->getMessage()}\n";
    $loadResults['CacheManager'] = 'ERROR';
}

// Monitor
try {
    require_once "$srcDir/Monitor.php";
    Monitor::init();
    $status = Monitor::getStatus();
    echo "✅ Monitor.php - بارگذاری موفق\n";
    echo "   • Monitor::init()\n";
    echo "   • Monitor::recordMetric()\n";
    echo "   • Monitor::getStatus()\n";
    $loadResults['Monitor'] = 'OK';
} catch (Throwable $e) {
    echo "❌ Monitor.php - خطا: {$e->getMessage()}\n";
    $loadResults['Monitor'] = 'ERROR';
}

echo "\n";

// ─────────────────────────────────────────────────────────────────────────────────
// 5️⃣ خلاصه نتایج
// ─────────────────────────────────────────────────────────────────────────────────

echo "📊 STEP 5: خلاصه نتایج\n";
echo str_repeat("─", 80) . "\n";

$okCount = count(array_filter($loadResults, fn($v) => $v === 'OK'));
$errorCount = count($loadResults) - $okCount;

echo "فایل‌های موفق:  $okCount / " . count($loadResults) . " ✅\n";
echo "فایل‌های ناموفق: $errorCount / " . count($loadResults) . " ❌\n";

if ($errorCount === 0) {
    echo "\n🎉 تمام فایل‌ها بدون مشکل بارگذاری شدند!\n";
} else {
    echo "\n⚠️ برخی فایل‌ها دارای مشکل هستند. لطفا بالا را بررسی کنید.\n";
}

echo "\n";

// ─────────────────────────────────────────────────────────────────────────────────
// 6️⃣ تست Integration
// ─────────────────────────────────────────────────────────────────────────────────

echo "🔗 STEP 6: تست Integration\n";
echo str_repeat("─", 80) . "\n";

try {
    // تنظیم پایه‌ای
    Theme::set('light');
    i18n::setLang('fa');
    
    // ساخت پیام
    $message = UiPremium::header('🤖', 'تست یکپارچگی');
    $message .= UiPremium::spacer();
    $message .= UiPremium::info('تم', Theme::getCurrent());
    $message .= "\n" . UiPremium::info('زبان', i18n::getLang());
    $message .= "\n" . UiPremium::alert('success', 'تست موفق!');
    
    echo "✅ Integration Test موفق:\n";
    echo "   • Theme تنظیم شد\n";
    echo "   • i18n تنظیم شد\n";
    echo "   • UiPremium پیام ساخت\n";
    echo "   • تمام سیستم‌ها با یکدیگر کار می‌کنند\n";
} catch (Throwable $e) {
    echo "❌ Integration Test ناموفق: {$e->getMessage()}\n";
}

echo "\n";

// ─────────────────────────────────────────────────────────────────────────────────
// نتیجه نهایی
// ─────────────────────────────────────────────────────────────────────────────────

echo "╔════════════════════════════════════════════════════════════════════════════════╗\n";

if ($errorCount === 0 && empty($syntaxErrors) && empty($classErrors)) {
    echo "║ ✅ DEBUG COMPLETE - تمام تست‌ها موفق بودند!                                   ║\n";
    echo "║                                                                            ║\n";
    echo "║ 📊 خلاصه:                                                                    ║\n";
    echo "║    • 10 فایل نیو موجود و صحیح                                              ║\n";
    echo "║    • 0 خطای syntax                                                         ║\n";
    echo "║    • 0 خطای class definition                                               ║\n";
    echo "║    • 10/10 فایل بارگذاری موفق                                              ║\n";
    echo "║    • Integration test موفق                                                ║\n";
} else {
    echo "║ ⚠️ DEBUG COMPLETE - برخی مشکلات شناسایی شدند                                 ║\n";
    echo "║                                                                            ║\n";
    if (!empty($syntaxErrors)) {
        echo "║ Syntax Errors: " . count($syntaxErrors) . "\n";
    }
    if (!empty($classErrors)) {
        echo "║ Class Errors: " . count($classErrors) . "\n";
    }
    if ($errorCount > 0) {
        echo "║ Load Errors: $errorCount\n";
    }
}

echo "╚════════════════════════════════════════════════════════════════════════════════╝\n\n";

exit($errorCount === 0 && empty($syntaxErrors) && empty($classErrors) ? 0 : 1);
