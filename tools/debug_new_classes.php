#!/usr/bin/env php
<?php
// ===== DEBUG Script - بررسی و تست تمام فایل‌های جدید =====

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$srcDir = $root . '/src';

echo "\n" . str_repeat("═", 80) . "\n";
echo "🔍 DEBUG COMPLETE - تمام فایل‌های جدید\n";
echo str_repeat("═", 80) . "\n\n";

// 1. فایل‌های جدید
$newFiles = [
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

echo "📋 بررسی وجود فایل‌ها:\n";
echo str_repeat("─", 80) . "\n";

$allExist = true;
foreach ($newFiles as $file) {
    $path = "$srcDir/$file";
    $exists = file_exists($path);
    $status = $exists ? '✅' : '❌';
    $size = $exists ? (filesize($path) / 1024) . ' KB' : 'N/A';
    
    echo "$status $file ($size)\n";
    if (!$exists) $allExist = false;
}

echo "\n";

// 2. Syntax Check
echo "🔧 Syntax Check:\n";
echo str_repeat("─", 80) . "\n";

foreach ($newFiles as $file) {
    $path = "$srcDir/$file";
    if (!file_exists($path)) continue;
    
    $output = shell_exec("php -l '$path' 2>&1");
    $hasError = strpos($output, 'Parse error') !== false || strpos($output, 'error') !== false;
    
    $status = $hasError ? '❌' : '✅';
    $message = $hasError ? trim($output) : 'No syntax errors';
    
    echo "$status $file: $message\n";
}

echo "\n";

// 3. Class Definition Check
echo "🏗️ Class Definitions:\n";
echo str_repeat("─", 80) . "\n";

$classes = [
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

foreach ($classes as $file => $className) {
    $path = "$srcDir/$file";
    if (!file_exists($path)) continue;
    
    $content = file_get_contents($path);
    $hasClass = preg_match("/class\s+$className\s*\{/", $content) ? true : false;
    
    $status = $hasClass ? '✅' : '❌';
    $methods = preg_match_all('/public\s+(?:static\s+)?function\s+\w+/i', $content, $m);
    
    echo "$status $className: $methods public methods\n";
}

echo "\n";

// 4. Load و Test
echo "⚙️ Loading Classes:\n";
echo str_repeat("─", 80) . "\n";

$testResults = [];

try {
    require_once "$srcDir/Theme.php";
    echo "✅ Theme.php loaded\n";
    
    // Test Theme
    Theme::set('dark');
    $icon = Theme::icon('success');
    echo "   ✓ Theme::set() و Theme::icon() کار می‌کند\n";
    $testResults['Theme'] = 'OK';
} catch (Exception $e) {
    echo "❌ Theme.php error: {$e->getMessage()}\n";
    $testResults['Theme'] = 'ERROR';
}

try {
    require_once "$srcDir/RateLimiter.php";
    echo "✅ RateLimiter.php loaded\n";
    
    // Test RateLimiter
    $allowed = RateLimiter::isAllowed(123, 10);
    echo "   ✓ RateLimiter::isAllowed() کار می‌کند\n";
    $testResults['RateLimiter'] = 'OK';
} catch (Exception $e) {
    echo "❌ RateLimiter.php error: {$e->getMessage()}\n";
    $testResults['RateLimiter'] = 'ERROR';
}

try {
    require_once "$srcDir/i18n.php";
    echo "✅ i18n.php loaded\n";
    
    // Test i18n
    i18n::setLang('fa');
    $text = i18n::t('welcome');
    echo "   ✓ i18n::setLang() و i18n::t() کار می‌کند\n";
    $testResults['i18n'] = 'OK';
} catch (Exception $e) {
    echo "❌ i18n.php error: {$e->getMessage()}\n";
    $testResults['i18n'] = 'ERROR';
}

try {
    require_once "$srcDir/UiPremium.php";
    echo "✅ UiPremium.php loaded\n";
    
    // Test UiPremium
    $header = UiPremium::header('🤖', 'Test');
    echo "   ✓ UiPremium::header() کار می‌کند\n";
    $testResults['UiPremium'] = 'OK';
} catch (Exception $e) {
    echo "❌ UiPremium.php error: {$e->getMessage()}\n";
    $testResults['UiPremium'] = 'ERROR';
}

try {
    require_once "$srcDir/AuditLog.php";
    echo "✅ AuditLog.php loaded\n";
    
    // Test AuditLog
    AuditLog::init();
    echo "   ✓ AuditLog::init() کار می‌کند\n";
    $testResults['AuditLog'] = 'OK';
} catch (Exception $e) {
    echo "❌ AuditLog.php error: {$e->getMessage()}\n";
    $testResults['AuditLog'] = 'ERROR';
}

try {
    require_once "$srcDir/WebhookManager.php";
    echo "✅ WebhookManager.php loaded\n";
    
    // Test WebhookManager
    WebhookManager::init();
    echo "   ✓ WebhookManager::init() کار می‌کند\n";
    $testResults['WebhookManager'] = 'OK';
} catch (Exception $e) {
    echo "❌ WebhookManager.php error: {$e->getMessage()}\n";
    $testResults['WebhookManager'] = 'ERROR';
}

try {
    require_once "$srcDir/Analytics.php";
    echo "✅ Analytics.php loaded\n";
    
    // Test Analytics
    Analytics::init();
    echo "   ✓ Analytics::init() کار می‌کند\n";
    $testResults['Analytics'] = 'OK';
} catch (Exception $e) {
    echo "❌ Analytics.php error: {$e->getMessage()}\n";
    $testResults['Analytics'] = 'ERROR';
}

try {
    require_once "$srcDir/PluginManager.php";
    echo "✅ PluginManager.php loaded\n";
    
    // Test PluginManager
    PluginManager::init();
    echo "   ✓ PluginManager::init() کار می‌کند\n";
    $testResults['PluginManager'] = 'OK';
} catch (Exception $e) {
    echo "❌ PluginManager.php error: {$e->getMessage()}\n";
    $testResults['PluginManager'] = 'ERROR';
}

try {
    require_once "$srcDir/CacheManager.php";
    echo "✅ CacheManager.php loaded\n";
    
    // Test CacheManager
    CacheManager::init();
    echo "   ✓ CacheManager::init() کار می‌کند\n";
    $testResults['CacheManager'] = 'OK';
} catch (Exception $e) {
    echo "❌ CacheManager.php error: {$e->getMessage()}\n";
    $testResults['CacheManager'] = 'ERROR';
}

try {
    require_once "$srcDir/Monitor.php";
    echo "✅ Monitor.php loaded\n";
    
    // Test Monitor
    Monitor::init();
    echo "   ✓ Monitor::init() کار می‌کند\n";
    $testResults['Monitor'] = 'OK';
} catch (Exception $e) {
    echo "❌ Monitor.php error: {$e->getMessage()}\n";
    $testResults['Monitor'] = 'ERROR';
}

echo "\n";

// 5. خلاصه
echo "📊 خلاصه نتایج:\n";
echo str_repeat("─", 80) . "\n";

$okCount = count(array_filter($testResults, fn($v) => $v === 'OK'));
$errorCount = count($testResults) - $okCount;

foreach ($testResults as $class => $status) {
    $icon = $status === 'OK' ? '✅' : '❌';
    echo "$icon $class: $status\n";
}

echo "\n";
echo "📈 آمار:\n";
echo "  • کل فایل‌ها: " . count($newFiles) . "\n";
echo "  • موفق: $okCount ✅\n";
echo "  • ناموفق: $errorCount ❌\n";

echo "\n";

if ($errorCount === 0) {
    echo "🎉 تمام فایل‌ها بدون مشکل بارگذاری شدند!\n";
} else {
    echo "⚠️ برخی فایل‌ها دارای مشکل هستند.\n";
}

echo "\n" . str_repeat("═", 80) . "\n\n";

// 6. Integration Test
echo "🔗 Integration Test:\n";
echo str_repeat("─", 80) . "\n";

try {
    // Test integration
    Theme::set('light');
    i18n::setLang('fa');
    
    $message = UiPremium::header('🤖', 'تست یکپارچگی');
    $message .= UiPremium::spacer();
    $message .= UiPremium::info('تم', Theme::getCurrent());
    $message .= "\n" . UiPremium::info('زبان', i18n::getLang());
    
    echo "✅ Integration test موفق:\n";
    echo "   Theme: " . Theme::getCurrent() . "\n";
    echo "   Language: " . i18n::getLang() . "\n";
    echo "   UI Message generated successfully\n";
} catch (Exception $e) {
    echo "❌ Integration test failed: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("═", 80) . "\n";
echo "✅ DEBUG تکمیل شد!\n";
echo str_repeat("═", 80) . "\n\n";
