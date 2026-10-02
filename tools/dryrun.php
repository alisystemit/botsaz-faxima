<?php
// ===== تست ساخت ربات بدون فراخوانی تلگرام =====
// استفاده: php tools/dryrun.php [type|--all] [slug]
// مثال: php tools/dryrun.php faxima testbot123
//        php tools/dryrun.php --all
//
// کاملاً از رجیستری Manager::templates() می‌خواند: پچ کانفیگ، فهرست فایل‌های
// لازم، فهرست استثناها و مسیر پوشهٔ قالب. قبلاً فقط faxima/mirza را می‌شناخت و
// همین‌طور که قالب تازه اضافه می‌شد، این ابزار بی‌صدا قالب را «نامعتبر» می‌گفت.

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

// ===== آرگومان‌ها =====
$args = array_slice($argv, 1);
$all = in_array('--all', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--all'));
$types = $all ? array_keys(Manager::templates()) : [$args[0] ?? 'faxima'];
$slugArg = $all ? ($args[0] ?? null) : ($args[1] ?? null);

$unknown = array_values(array_filter($types, fn($t) => Manager::templateSpec($t) === null));
if (!empty($unknown)) {
    echo "❌ Unknown bot type(s): " . implode(', ', $unknown) . "\n";
    echo "   Available: " . implode(', ', array_keys(Manager::templates())) . "\n";
    exit(1);
}

$failedAny = false;
foreach ($types as $type) {
    $exit = dryRunOne($cfg, $store, $type, $slugArg);
    if ($exit !== 0) $failedAny = true;
}

exit($failedAny ? 1 : 0);

// ===================================================================
function dryRunOne(array $cfg, Store $store, string $type, ?string $slugArg): int
{
    $spec = Manager::templateSpec($type);
    $label = Manager::templateLabel($type);
    $slug = Manager::slugify($slugArg !== null && $slugArg !== '' ? $slugArg : 'dryrun_' . $type . '_' . time());
    $failed = false;

    echo "=========================================\n";
    echo "  🧪 Dry Run Test — {$label} ({$type})\n";
    echo "=========================================\n\n";

    // ===== بررسی پیش‌نیازها (همان تابعی که ربات‌ساز قبل از ساخت صدا می‌زند) =====
    echo "[1] پیش‌نیازهای ساخت...\n";
    echo "   دیتابیس قالب: " . Manager::templateDb($type) . "\n";
    try {
        Manager::assertTemplatePhpCompatible($type);
    } catch (Throwable $e) {
        echo "   ❌ " . $e->getMessage() . "\n";
        return 1;
    }
    $prereqErr = Manager::checkBuildPrerequisites($type);
    if ($prereqErr !== '') {
        echo "   ❌ {$prereqErr}\n";
        return 1;
    }
    $minPhp = Manager::templateMinPhp($type);
    echo "   PHP: " . PHP_VERSION . ($minPhp !== null ? " (needs >= " . Manager::formatPhpVersionId($minPhp) . ")" : '') . " ✓\n";

    // ===== بررسی قالب =====
    echo "[2] بررسی قالب...\n";
    $tplDir = Manager::templateDir($type);
    if (!is_dir($tplDir)) {
        echo "❌ قالب پیدا نشد: {$tplDir}\n";
        return 1;
    }
    echo "   مسیر: {$tplDir}\n";

    // ===== فایل‌های ضروری (از رجیستری) =====
    echo "[3] بررسی فایل‌های قالب...\n";
    $requiredFiles = (array)($spec['required'] ?? []);
    $missing = [];
    foreach ($requiredFiles as $f) {
        if (!file_exists("{$tplDir}/{$f}")) $missing[] = $f;
    }
    if (!empty($missing)) {
        echo "   ❌ فایل‌های کسری: " . implode(', ', $missing) . "\n";
        $failed = true;
    } else {
        echo "   همهٔ " . count($requiredFiles) . " فایل لازم موجود ✓\n";
    }

    // ===== نام تکراری =====
    echo "[4] بررسی تکراری نبودن نام...\n";
    $botDir = Manager::childBotsDir() . '/' . $slug;
    if ($store->botByFolder($slug) || is_dir($botDir)) {
        echo "   ❌ پوشه/ردیف «{$slug}» از قبل وجود دارد!\n";
        return 1;
    }
    echo "   نام «{$slug}» آزاد ✓\n";

    // ===== شبیه‌سازی ساخت =====
    echo "[5] شبیه‌سازی ساخت ربات...\n";

    // همان فهرست استثناهای buildBot — از رجیستری، پس هرگز واگرا نمی‌شود
    $excludePaths = array_merge(
        (array)($spec['exclude'] ?? []),
        ['.git/', '.gitignore', '.gitattributes']
    );

    // مرجع زمان برای تشخیص «فایل کپی‌شده» از «فایل تازه‌ساخته» (بُعدها در [5g])
    $startedAt = time();

    echo "   [5a] کپی قالب... ";
    try {
        Manager::copyDir($tplDir, $botDir, $excludePaths);
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        if (is_dir($botDir)) Manager::removeDir($botDir);
        return 1;
    }

    echo "   [5b] پچ config.php... ";
    $fakeDb = 'botsaz_' . preg_replace('/[^a-z0-9_]/', '_', $slug) . '_test';
    $domainPath = 'example.com/bots/' . $slug;
    $baseUrl = 'https://example.com/bots/' . $slug;
    // قالبی که رمز تصادفی خودش می‌سازد (پاسارگاد) باید همان رمز تولیدشده را
    // بفرستد؛ وگرنه URL وبهوک بی‌رمز ساخته می‌شد و چک [5h] بی‌معنی می‌شد.
    $staticSecret = '';
    try {
        switch ($type) {
            case 'faxima':
                Manager::patchFaximaConfig($botDir, $cfg, $fakeDb, '123:FakeTokenFakeTokenFakeToken', 999001, 'testbot', $domainPath);
                break;
            case 'mirza':
                Manager::patchMirzaConfig($botDir, $cfg, $fakeDb, '123:FakeTokenFakeTokenFakeToken', 999001, 'testbot', $domainPath);
                break;
            case 'uptime':
                Manager::patchUptimeConfig($botDir, $cfg, $fakeDb, '123:FakeTokenFakeTokenFakeToken', 999001, 'testbot', $domainPath, $baseUrl);
                break;
            case 'pasargad':
                $res = Manager::writePasargadConfig($botDir, '123:FakeTokenFakeTokenFakeToken', 999001, 'testbot', $baseUrl);
                $staticSecret = (string)($res['secret'] ?? '');
                echo "\n";
                echo "        (secret ساخته‌شده: " . strlen($staticSecret) . " کاراکتر، crypto_key: " . strlen((string)($res['crypto'] ?? '')) . " کاراکتر)\n";
                echo "   [5b] ";
                break;
            default:
                throw new Exception("پچ کانفیگ برای «{$type}» پیاده‌سازی نشده");
        }
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        Manager::removeDir($botDir);
        return 1;
    }

    // پاسارگاد کانفیگش را از روی نمونه می‌سازد، پس فایل باید همان‌جا باشد
    $configPath = $botDir . '/config.php';
    echo "   [5c] بررسی کانفیگ پچ‌شده... ";
    $patchedConfig = @file_get_contents($configPath);
    if ($patchedConfig === false) {
        echo "FAILED: config.php پیدا نشد (در {$configPath})\n";
        Manager::removeDir($botDir);
        return 1;
    }
    $placeholders = ['{DATABASE_', '{DB_', '{BOT_TOKEN}', '{ADMIN_', '{DOMAIN.', '{BOT_USER', '{BASE_URL}', 'PUT_BOT_TOKEN_HERE', 'CHANGE-THIS-RANDOM-SECRET'];
    $leftover = [];
    foreach ($placeholders as $ph) {
        if (str_contains($patchedConfig, $ph)) $leftover[] = $ph;
    }
    if (!empty($leftover)) {
        echo "❌ جای‌گذار جا‌مانده: " . implode(', ', $leftover) . "\n";
        $failed = true;
    } else {
        echo "همهٔ جای‌گذارها جایگزین شدند ✓\n";
    }

    echo "   [5d] بررسی syntax کانفیگ... ";
    $phpBin = trim((string)($cfg['php_bin'] ?? ''));
    if ($phpBin === '' || !is_file($phpBin)) $phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    // shell_exec ممکن است در disable_functions باشد؛ آن‌وقت «فراخوانی»اش Error می‌دهد
    // (که @ هم ساکتش نمی‌کند) و کل dryrun را می‌کشد. در آن حالت lint را رد می‌کنیم.
    $lintResult = function_exists('shell_exec')
        ? (@shell_exec("\"{$phpBin}\" -l \"{$configPath}\" 2>&1") ?? '')
        : '';
    if ($lintResult === '' && !function_exists('shell_exec')) {
        echo "SKIPPED (shell_exec غیرفعال است)\n";
    } elseif (str_contains($lintResult, 'No syntax errors')) {
        echo "OK\n";
    } else {
        echo "FAILED: {$lintResult}\n";
        $failed = true;
    }

    // ===== [5e] نصب جدول‌ها — عیناً مثل گام ۴ buildBot =====
    // برای قالب‌های table.php این مرحله فقط یک اعلام است (ساخت جدول روی خود سرور و
    // با HTTP انجام می‌شود)، ولی برای قالب‌های SQLite خودِ مایگریشن اینجا اجرا
    // می‌شود — چون پرخطرترین بخشِ نصب است و بدون این، dryrun برای پاسارگاد
    // فقط کپی و پچ را می‌سنجید و نصب واقعی هرگز آزمایش نمی‌شد.
    echo "   [5e] نصب جدول‌ها... ";
    $schema = Manager::templateSchema($type);
    if ($schema === 'http') {
        echo "SKIPPED (جدول‌ها با table.php روی خودِ سرور ساخته می‌شوند)\n";
    } elseif ($schema === 'migrate') {
        try {
            $inst = Manager::installTemplateSchema($type, $botDir);
            if (empty($inst['migrated'])) {
                echo "FAILED: " . $inst['note'] . "\n";
                $failed = true;
            } else {
                echo "OK" . ($inst['note'] !== '' ? " ({$inst['note']})" : '') . "\n";
            }
        } catch (Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
            $failed = true;
        }
    } else {
        echo "SKIPPED (این قالب جدولی نمی‌سازد)\n";
    }

    // ===== [5f] پاکسازی — عیناً مثل گام ۵ buildBot =====
    // اول «cleanup» اجرا می‌شود، بعد نشت‌ها بررسی؛ وگرنه هر فایلی که باید
    // موقع کپی بماند و بعد پاک شود (مثل config.example.php پاسارگاد) اشتباهی
    // «نشتی» گزارش می‌شد و dryrun همیشه قرمز می‌داد.
    echo "   [5f] پاکسازی فایل‌های اضافی... ";
    try {
        Manager::cleanupExtraFiles($botDir, (array)($spec['cleanup'] ?? []));
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        $failed = true;
    }

    // ===== [5g] تأیید اینکه هیچ فایل غیرمجازی باقی نمانده =====
    echo "   [5g] بررسی نشت فایل‌های ممنوع... ";
    $leaked = [];
    $generated = array_map(static fn($p) => rtrim((string)$p, '/'), (array)($spec['generated'] ?? []));
    // exclude باید موقع کپی رد شده باشد ⇒ اگر هست یعنی copyDir درست کار نکرده.
    // استثنا: مسیرهایی که خودِ نصب می‌سازد (مثل دیتابیس SQLite پاسارگاد) — این‌ها
    // باید آنجا باشند، پس وجودشان نشتی نیست و پایین‌تر جداگانه بررسی می‌شوند.
    foreach ($excludePaths as $p) {
        if (in_array(rtrim($p, '/'), $generated, true)) continue;
        if (file_exists($botDir . '/' . rtrim($p, '/'))) $leaked[] = $p;
    }
    // cleanup باید بعد از کپی حذف شده باشد ⇒ اگر هست یعنی پاکسازی اجرا نشده
    foreach ((array)($spec['cleanup'] ?? []) as $p) {
        if (file_exists($botDir . '/' . rtrim($p, '/'))) $leaked[] = $p . ' (cleanup)';
    }
    // هیچ قالبی نباید قفل/لاگ توسعه را با خود بیاورد
    foreach (['data/.migrate.lock', 'data/worker.lock'] as $p) {
        if (in_array($p, $generated, true)) continue;
        if (file_exists($botDir . '/' . $p)) $leaked[] = $p . ' (runtime!)';
    }
    // فایل‌هایی که خودِ نصب می‌سازد: نبودنشان نصب ناقص، و کپی‌شدنشان (mtime قدیمی)
    // یعنی دیتابیس یا لاگِ مخزن به ربات تازه راه پیدا کرده.
    foreach ($generated as $p) {
        $full = $botDir . '/' . $p;
        if (!file_exists($full)) {
            $leaked[] = $p . ' (باید توسط نصب ساخته می‌شد ولی نیست!)';
        } elseif ((int)@filemtime($full) < $startedAt) {
            $leaked[] = $p . ' (کپی شد، ساخته نشد — runtime مخزن لو رفت!)';
        }
    }
    if ($leaked) {
        echo "LEAKED: " . implode(', ', array_unique($leaked)) . "\n";
        $failed = true;
    } else {
        echo "none ✓\n";
    }

    // ===== [5h] رمزهای وبهوک/جدول قابل بازتولید باشند =====
    echo "   [5h] بررسی رمزها... ";
    $tok = '123:FakeTokenFakeTokenFakeToken';
    $whSecret = Manager::webhookSecret($type, $tok, $staticSecret);
    $tableFile = (string)($spec['table'] ?? '');
    $tableSecret = $tableFile !== '' ? Manager::tableSecret($type, $tok) : '';
    $whUrl = Manager::webhookUrl($cfg, $slug, $type, $whSecret);
    $problems = [];
    if (str_contains($whUrl, 'FakeToken')) $problems[] = 'توکن نشت کرده در URL وبهوک';
    if ($tableFile !== '' && $tableSecret === '') $problems[] = 'رمز table.php خالی است ولی table.php دارد';
    // قالبی که رمز تصادفی می‌سازد باید همان رمز در URL باشد؛ رمز خالی یعنی
    // بعداً «ست مجدد وبهوک» رمز را از رکورد نمی‌خواند و ربات ۴۰۳ می‌دهد.
    if (Manager::templateSpec($type)['secret'] === 'static' && $whSecret === '') {
        $problems[] = 'رمز تصادفی قالب در URL وبهوک نیامد (بعداً قابل بازیابی نیست)';
    }
    // شکل URL: «https://host/path/entry.php» یا «…?secret=X». نه بیشتر،
    // نه فاصله (که تلگرام URL را رد می‌کند)، نه پارامتر جاافتاده.
    if (substr_count($whUrl, '?') > 1 || str_contains($whUrl, ' ')) {
        $problems[] = 'شکل URL وبهوک غلط است: ' . $whUrl;
    }
    if ($whSecret !== '' && !str_contains($whUrl, 'secret=' . $whSecret)) {
        $problems[] = 'رمز وبهوک در URL نیامده ⇒ بعداً بازیابی نمی‌شود (403)';
    }
    if ($whSecret === '' && str_contains($whUrl, 'secret=')) {
        $problems[] = 'URL پارامتر secret دارد ولی رمز خالی است';
    }
    if (!empty($problems)) {
        echo '❌ ' . implode(' | ', $problems) . "\n";
        $failed = true;
    } else {
        echo "وبهوک: " . Manager::entryFile($type)
            . ($whSecret !== '' ? ' + secret' : ' (بدون secret)')
            . ($tableFile !== '' ? ' | جدول: ' . $tableFile : ' | جدول: مایگریشن داخلی') . " ✓\n";
    }

    // ===== پاکسازی =====
    echo "[6] پاکسازی...\n";
    Manager::removeDir($botDir);
    echo "   پوشهٔ تست پاک شد ✓\n";

    echo "\n=========================================\n";
    if ($failed) {
        echo "  ❌ Dry run شکست خورد — مشکلات بالا را برطرف کن ({$type}).\n";
        echo "=========================================\n\n";
        return 1;
    }
    echo "  ✅ Dry run موفق ({$type})\n";
    echo "  ساخت ربات «{$slug}» از این قالب بی‌خطر است.\n";
    echo "=========================================\n\n";
    return 0;
}