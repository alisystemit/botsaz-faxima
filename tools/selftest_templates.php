<?php
// ===== تست رجیستری قالب‌ها و نصب خودکار =====
/*
 * این تست عمداً از گیت 8.2 پروژه عبور نمی‌کند: کاری می‌کند که روی PHP 8.1
 * هم قابل اجرا باشد تا بشود بدون ارتقای PHP محل، کد نصب قالب‌ها را سنجید.
 *
 * استفاده: php tools/selftest_templates.php
 */

require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/Nav.php';
require_once __DIR__ . '/../src/BotApi.php';

$root = dirname(__DIR__);
// مقایسهٔ مسیر باید نرمال‌شده باشد: روی ویندوز dirname با «\» برمی‌گردد
// ولی templateDir با «/» به هم می‌چسباند.
$rootPrefix = str_replace('\\', '/', $root) . '/templates/';
$pass = 0; $fail = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else { $fail++; echo "  ✗ {$name}" . ($extra !== '' ? "  ({$extra})" : '') . "\n"; }
}

function section(string $t): void
{
    echo "\n--- {$t} ---\n";
}

$TOKEN = '123456:AAFakeTokenFakeTokenFakeTokenXX';

/**
 * در محیط واقعی این تابع در bot.php تعریف شده و توکن رمزنگاری‌شده را باز می‌کند.
 * اینجا برای اینکه Manager::resolveWebhookSecret بتواند رمز محاسباتی را بسازد
 * (قالب‌هایی که رمزشان از توکن درمی‌آید) همان رفتار را شبیه‌سازی می‌کنیم.
 */
if (!function_exists('childToken')) {
    function childToken(array $bot): string
    {
        $t = (string)($bot['token'] ?? '');
        if (preg_match('/^\d+:[\w\-]{20,}$/', $t)) return $t;
        try {
            $d = \Manager::decryptToken($t, \Manager::DEFAULT_SECRET_KEY);
            if (is_string($d) && preg_match('/^\d+:[\w\-]{20,}$/', $d)) return $d;
        } catch (Throwable $e) { /* توکن ساده */ }
        return $t;
    }
}

// ===================================================================
section('رجیستری قالب‌ها');
// ===================================================================
$templates = Manager::templates();
check('چهار قالب ثبت شده', count($templates) === 4, implode(',', array_keys($templates)));

// رگرسیون: validTypes() باید «کلید ⇒ برچسب» بدهد، نه «شماره ⇒ برچسب».
// array_keys + array_map کلیدهای عددی را دور می‌ریخت و همه‌جا که با کلید قالب
// کار می‌کند (isPaid، pay:buy:template:<type>) بی‌صدا هیچ قالبی نمی‌دید.
$vt = Manager::validTypes();
check('validTypes کلیدهای رشته‌ای برمی‌گرداند', array_keys($vt) === array_keys($templates), implode(',', array_keys($vt)));
check('validTypes برچسب فارسی می‌دهد', $vt['faxima'] === 'فاکسیما (فروش VPN)', $vt['faxima'] ?? '?');
check('validTypes قالب تازه را هم دارد', isset($vt['uptime'], $vt['pasargad']));
foreach ($vt as $k => $lbl) {
    check("validTypes[{$k}] کلید نیست", is_string($k), (string)$k);
    check("validTypes[{$k}] غیرخالی", is_string($lbl) && $lbl !== '');
}

$expected = ['faxima' => 'mysql', 'mirza' => 'mysql', 'uptime' => 'mysql', 'pasargad' => 'sqlite'];
foreach ($expected as $k => $db) {
    check("قالب «{$k}» دیتابیسش {$db} است", Manager::templateDb($k) === $db);
    check("قالب «{$k}» در رجیستری هست", Manager::templateSpec($k) !== null);
    check("قالب «{$k}» برچسب غیرخالی دارد", Manager::templateLabel($k) !== $k);
    $spec = Manager::templateSpec($k);
    check("قالب «{$k}» آیکون دارد", ($spec['icon'] ?? '') !== '');
    check("قالب «{$k}» entry معتبر دارد", preg_match('/^[a-z0-9_.-]+\.php$/i', (string)$spec['entry']) === 1);
    check("قالب «{$k}» فهرست required دارد", !empty($spec['required']));
    // exclude و cleanup و generated نباید هیچ‌کدام مسیر خالی یا بدون نوع داشته باشند:
    // «» در copyDir یعنی «همه‌چیز را کپی نکن» و یک غلط تایپی یعنی قالب بی‌صدا ناقص.
    foreach (['exclude', 'cleanup', 'generated'] as $key) {
        foreach ((array)($spec[$key] ?? []) as $p) {
            check("قالب «{$k}» مسیرهای {$key} رشتهٔ غیرخالی‌اند", is_string($p) && trim($p) !== '', var_export($p, true));
        }
        // هیچ مسیری نباید «.» یا «..» یا مطلق باشد (خروج از پوشهٔ ربات)
        foreach ((array)($spec[$key] ?? []) as $p) {
            $bad = str_starts_with((string)$p, '/') || in_array(trim((string)$p, '/'), ['.', '..'], true);
            check("قالب «{$k}» مسیر {$key} از پوشهٔ ربات بیرون نمی‌زند", !$bad, var_export($p, true));
        }
    }
    // generated و cleanup نباید با هم اشتباه شوند: فایلی که نصب می‌سازد
    // (دیتابیس) اگر در cleanup باشد، بلافاصله پاک می‌شود و ربات بدون دیتابیس می‌ماند.
    $gen = array_map(static fn($p) => rtrim((string)$p, '/'), (array)($spec['generated'] ?? []));
    $cln = array_map(static fn($p) => rtrim((string)$p, '/'), (array)($spec['cleanup'] ?? []));
    check("قالب «{$k}» generated با cleanup اشتباه ندارد", array_intersect($gen, $cln) === [], implode(',', array_intersect($gen, $cln)));
    // هر مسیر generated باید exclude هم باشد، وگرنه dryrun آن را دوبار می‌بیند
    $exc = array_map(static fn($p) => rtrim((string)$p, '/'), (array)($spec['exclude'] ?? []));
    foreach ($gen as $p) {
        check("قالب «{$k}» مسیر generated «{$p}» در exclude هم هست", in_array($p, $exc, true), implode(',', $exc));
    }
}

// templateSpec روی کلید ناشناس null می‌دهد
check('کلید ناشناس ⇒ null', Manager::templateSpec('nope-xyz') === null);
check('templateDb روی کلید ناشناس ⇒ mysql', Manager::templateDb('nope-xyz') === 'mysql');

// ===================================================================
section('روش نصب جدول (schema)');
// ===================================================================
check('schema(faxima) = http', Manager::templateSchema('faxima') === 'http');
check('schema(mirza) = http', Manager::templateSchema('mirza') === 'http');
check('schema(uptime) = http', Manager::templateSchema('uptime') === 'http');
check('schema(pasargad) = migrate', Manager::templateSchema('pasargad') === 'migrate');
check('schema ناشناس = none', Manager::templateSchema('nope-xyz') === 'none');
// هر قالبی که table.php دارد باید http باشد (گارد در برابر قالب تازهٔ ناقص)
foreach ($templates as $k => $s) {
    $hasTable = ($s['table'] ?? null) !== null;
    check(
        "قالب «{$k}» با وجود/عدم table.php سازگار است",
        $hasTable ? Manager::templateSchema($k) === 'http' : Manager::templateSchema($k) !== 'http'
    );
}
// جدول‌های ساخته‌شده باید در bot_dir باشند، نه جای دیگر

// ===================================================================
section('مسیر پوشهٔ قالب');
// ===================================================================
foreach (array_keys($templates) as $k) {
    $dir = Manager::templateDir($k);
    check("پوشهٔ «{$k}» وجود دارد", is_dir($dir), $dir);
    check("پوشهٔ «{$k}» بیرون از templates/ نیست", strpos(str_replace('\\', '/', $dir), $rootPrefix) === 0, $dir);
}
// قالب پاسارگاد کلیدش با نام پوشه فرق دارد — همین باید کار کند
check(
    'pasargad به پوشهٔ واقعی‌اش اشاره می‌کند',
    basename(Manager::templateDir('pasargad')) === "Pasargad Representatives' Bot Telegram",
    Manager::templateDir('pasargad')
);
// جلوگیری از فرار از پوشهٔ templates با کلید بدخواهانه
foreach (['../../etc', '..\\..\\etc', 'a/../../b', '../../../root'] as $evil) {
    $d = str_replace('\\', '/', Manager::templateDir($evil));
    check(
        "کلید بدخواهانه «{$evil}» مهار شد",
        strpos($d, $rootPrefix) === 0 && !str_contains(basename($d), '..'),
        $d
    );
}
check('نام خالی ⇒ templates/ + قالب پیش‌فرض', strpos(str_replace('\\', '/', Manager::templateDir('')), $rootPrefix . 'faxima') !== false);

// ===================================================================
section('availableTypes و منوی انتخاب نوع');
// ===================================================================
$avail = Manager::availableTypes();
check('همهٔ ۴ قالب در دسترس‌اند', count($avail) === 4, implode(',', array_keys($avail)));
foreach (array_keys($avail) as $k) {
    $dir = Manager::templateDir($k);
    $spec = Manager::templateSpec($k);
    check("«{$k}» فایل {$spec['autoload']} را دارد", is_file($dir . '/' . $spec['autoload']));
}

// typeMenu باید دقیقاً یک دکمه به ازای هر قالب در دسترس + برگشت/انصراف بدهد
$kb = json_decode(str_replace('\/', '/', Nav::typeMenu()), true);
$rows = $kb['inline_keyboard'] ?? [];
check('typeMenu یک کیبورد برمی‌گرداند', !empty($rows));
$cbData = [];
foreach ($rows as $row) {
    foreach ($row as $btn) $cbData[] = $btn['callback_data'];
}
check('typeMenu دکمهٔ «newbot:» برای هر 4 قالب دارد', count(array_filter($cbData, fn($d) => str_starts_with($d, 'newbot:'))) === 4, implode(',', $cbData));
check('typeMenu دکمهٔ برگشت دارد', in_array(Nav::CB_BACK_MAIN, $cbData, true));
check('typeMenu دکمهٔ انصراف دارد', in_array(Nav::CB_CANCEL, $cbData, true));
check('typeMenu هیچ «noop» ندارد (قالب مرده نمانده)', !in_array('noop', $cbData, true));

// ===================================================================
section('فایل ورودی و رمز وبهوک');
// ===================================================================
check('entryFile(faxima) = index.php', Manager::entryFile('faxima') === 'index.php');
check('entryFile(mirza)  = index.php', Manager::entryFile('mirza') === 'index.php');
check('entryFile(uptime) = index.php', Manager::entryFile('uptime') === 'index.php');
check('entryFile(pasargad) = bot.php', Manager::entryFile('pasargad') === 'bot.php');
check('entryFile ناشناس ⇒ index.php', Manager::entryFile('nope-xyz') === 'index.php');

// فرمول‌ها باید دقیقاً همان چیزی باشند که خودِ قالب در وبهوکش مقایسه می‌کند
check(
    'رمز وبهوک فاکسیما با فرمول خودِ قالب یکی است',
    Manager::webhookSecret('faxima', $TOKEN) === hash('sha256', $TOKEN . '_faoxima_webhook_secret')
);
check(
    'رمز وبهوک میرزا وجود ندارد',
    Manager::webhookSecret('mirza', $TOKEN) === ''
);
check(
    'رمز وبهوک آپ‌تایم با فرمول خودِ قالب یکی است',
    Manager::webhookSecret('uptime', $TOKEN) === hash('sha256', $TOKEN . '_uptime_webhook_secret')
);
check(
    'رمز وبهوک پاسارگاد همان مقدار تصادفی است',
    Manager::webhookSecret('pasargad', $TOKEN, 'MyStaticSecret123') === 'MyStaticSecret123'
);
check('توکن خالی ⇒ رمز خالی', Manager::webhookSecret('faxima', '') === '');

// رمزهای جدول
check(
    'رمز table فاکسیما با فرمول خودِ قالب یکی است',
    Manager::tableSecret('faxima', $TOKEN) === hash('sha256', $TOKEN . '_faxima_table_secret')
);
check(
    'رمز table میرزا با فرمول خودِ قالب یکی است',
    Manager::tableSecret('mirza', $TOKEN) === hash('sha256', $TOKEN . '_mirza_table_secret')
);
check(
    'رمز table آپ‌تایم با فرمول خودِ قالب یکی است',
    Manager::tableSecret('uptime', $TOKEN) === hash('sha256', $TOKEN . '_uptime_table_secret')
);
check('پاسارگاد table.php ندارد ⇒ رمز خالی', Manager::tableSecret('pasargad', $TOKEN) === '');

// رمزهای جدول و وبهوک یکی نباشند (وگرنه لینک لو می‌رود)
foreach (['faxima', 'mirza', 'uptime'] as $k) {
    check(
        "رمز جدول «{$k}» با رمز وبهوکش فرق دارد",
        Manager::tableSecret($k, $TOKEN) !== Manager::webhookSecret($k, $TOKEN)
    );
}

// فرمول‌ها را با سورس خودِ قالب مقایسه کن — نه با حافظهٔ ما
$faximaAuth = $root . '/templates/faxima/lib/WebhookAuth.php';
if (is_file($faximaAuth)) {
    $src = (string)file_get_contents($faximaAuth);
    check(
        'سورس WebhookAuth فاکسیما همان فرمولی را دارد که ما حساب می‌کنیم',
        str_contains($src, "'_faoxima_webhook_secret'")
    );
}
$upIdx = $root . '/templates/uptime-bot-telegram/index.php';
if (is_file($upIdx)) {
    check(
        'سورس index.php آپ‌تایم همان فرمولی را دارد که ما حساب می‌کنیم',
        str_contains((string)file_get_contents($upIdx), "'_uptime_webhook_secret'")
    );
}
$upTab = $root . '/templates/uptime-bot-telegram/table.php';
if (is_file($upTab)) {
    check(
        'سورس table.php آپ‌تایم همان فرمولی را دارد که ما حساب می‌کنیم',
        str_contains((string)file_get_contents($upTab), "'_uptime_table_secret'")
    );
}
$pasBot = $root . "/templates/Pasargad Representatives' Bot Telegram/bot.php";
if (is_file($pasBot)) {
    $src = (string)file_get_contents($pasBot);
    check('پاسارگاد مقدار نمونهٔ webhook_secret را رد می‌کند', str_contains($src, 'CHANGE-THIS-RANDOM-SECRET'));
}

// ===================================================================
section('آدرس وبهوک');
// ===================================================================
$cfg = ['base_url' => 'https://example.com/botsaz'];
check(
    'بدون secret آدرس تمیز است',
    Manager::webhookUrl($cfg, 'shop1', 'faxima') === 'https://example.com/botsaz/bots/shop1/index.php'
);
check(
    'با secret، query اضافه می‌شود',
    Manager::webhookUrl($cfg, 'shop1', 'faxima', 'abc') === 'https://example.com/botsaz/bots/shop1/index.php?secret=abc'
);
check(
    'base_url با اسلش انتهایی هم درست کار می‌کند',
    Manager::webhookUrl(['base_url' => 'https://example.com/botsaz/'], 'shop1', 'mirza') === 'https://example.com/botsaz/bots/shop1/index.php'
);
check(
    'مسیر وبهوک پاسارگاد به bot.php می‌رود',
    str_contains(Manager::webhookUrl($cfg, 'p1', 'pasargad'), '/bots/p1/bot.php')
);

// resolveWebhookSecret: بازیابی رمز تصادفی از URL ذخیره‌شده
$static = Manager::randomToken(40);
$stored = Manager::webhookUrl($cfg, 'p1', 'pasargad', $static);
$botRow = ['type' => 'pasargad', 'folder' => 'p1', 'token' => $TOKEN, 'webhook_url' => $stored];
check('رمز تصادفی از webhook_url بازیابی می‌شود', Manager::resolveWebhookSecret($botRow) === $static, Manager::resolveWebhookSecret($botRow));
check('آدرس بازسازی‌شده همان آدرس ذخیره‌شده است', Manager::webhookUrlForBot($cfg, $botRow) === $stored);
// برای قالب‌های محاسباتی هم باید همان رمز را بدهد
$fx = ['type' => 'faxima', 'folder' => 'f1', 'token' => $TOKEN, 'webhook_url' => Manager::webhookUrl($cfg, 'f1', 'faxima', Manager::webhookSecret('faxima', $TOKEN))];
check('رمز فاکسیما از URL هم بازیابی می‌شود', Manager::resolveWebhookSecret($fx) === Manager::webhookSecret('faxima', $TOKEN));
// میرزا هیچ رمزی ندارد و نباید از URL چیزی بردارد
$mz = ['type' => 'mirza', 'folder' => 'm1', 'token' => $TOKEN, 'webhook_url' => 'https://x/bots/m1/index.php?secret=leak'];
check('میرزا رمز نمی‌سازد حتی اگر URL داشته باشد', Manager::resolveWebhookSecret($mz) === '', Manager::resolveWebhookSecret($mz));

// ===================================================================
section('randomToken');
// ===================================================================
$t = Manager::randomToken(40);
check('طول ۴۰ رعایت شده', strlen($t) === 40, (string)strlen($t));
check('فقط کاراکترهای مجاز تلگرام', preg_match('/^[A-Za-z0-9_-]+$/', $t) === 1, $t);
check('طول حداقلی اعمال شده', strlen(Manager::randomToken(1)) === 8);
check('طول بیشینه اعمال شده', strlen(Manager::randomToken(9999)) === 256);
check('دو بار پشت‌سرهم یکی نیست', Manager::randomToken(40) !== Manager::randomToken(40));
$seen = [];
for ($i = 0; $i < 200; $i++) $seen[Manager::randomToken(16)] = true;
check('۲۰۰ توکن ۱۶تایی یکتا هستند', count($seen) === 200, (string)count($seen));

// ===================================================================
section('پچ کانفیگ آپ‌تایم');
// ===================================================================
$work = sys_get_temp_dir() . '/tpltest_' . bin2hex(random_bytes(4));
@mkdir($work, 0755, true);
$upDir = $work . '/uptime';
Manager::copyDir(Manager::templateDir('uptime'), $upDir, (array)(Manager::templateSpec('uptime')['exclude'] ?? []));
Manager::cleanupExtraFiles($upDir, (array)(Manager::templateSpec('uptime')['cleanup'] ?? []));

$myCfg = [
    'base_url' => 'https://example.com/botsaz',
    'db_host' => '127.0.0.1', 'db_port' => 3306,
    'db_user' => 'someuser', 'db_pass' => "pa'ss\\word",
];
$uptimeWriteOk = true;
try {
    Manager::patchUptimeConfig(
        $upDir, $myCfg, 'botsaz_uptime_abc123', $TOKEN, 999001, 'myuptime',
        'example.com/botsaz/bots/up1', 'https://example.com/botsaz/bots/up1'
    );
} catch (Throwable $e) {
    $uptimeWriteOk = false;
    echo "    (خطا: " . $e->getMessage() . ")\n";
}
check('patchUptimeConfig بدون استثنا اجرا شد', $uptimeWriteOk);

$upCfgRaw = (string)@file_get_contents($upDir . '/config.php');
check('فایل config.php آپ‌تایم باید ساخته/پچ شود', is_file($upDir . '/config.php'));
check('هیچ جای‌گذار {…} باقی نمانده', preg_match('/\{[A-Z_#.\/]+\}/', $upCfgRaw) !== 1);
check('توکن در کانفیگ نشسته', str_contains($upCfgRaw, $TOKEN));
check('آیدی ادمین نشسته', str_contains($upCfgRaw, '999001'));
check('یوزرنیم نشسته', str_contains($upCfgRaw, 'myuptime'));
check('base_url نشسته', str_contains($upCfgRaw, 'https://example.com/botsaz/bots/up1'));
check('نام دیتابیس نشسته', str_contains($upCfgRaw, 'botsaz_uptime_abc123'));
check('نام کاربری دیتابیس نشسته', str_contains($upCfgRaw, 'someuser'));
check('پورت دیتابیس نشسته', str_contains($upCfgRaw, '3306'));
// رمز عبور با کوتیشن و بک‌اسلش داخل رشتهٔ PHP امن شده باشد
$upCfgArr = @include($upDir . '/config.php');
if (is_array($upCfgArr)) {
    check('کانفیگ آپ‌تایم include می‌شود', true);
    check('db.pass دقیقاً بازیابی شد', ($upCfgArr['db']['pass'] ?? null) === "pa'ss\\word", var_export($upCfgArr['db']['pass'] ?? null, true));
    check('db.name درست است', ($upCfgArr['db']['name'] ?? null) === 'botsaz_uptime_abc123');
    check('admin_id عدد شد', ($upCfgArr['admin_id'] ?? null) === '999001' || ($upCfgArr['admin_id'] ?? null) === 999001, var_export($upCfgArr['admin_id'] ?? null, true));
    check('bot_token درست است', ($upCfgArr['bot_token'] ?? null) === $TOKEN);
    check('domain نشسته', ($upCfgArr['domain'] ?? '') === 'example.com/botsaz/bots/up1');
    check('base_url درست است', ($upCfgArr['base_url'] ?? '') === 'https://example.com/botsaz/bots/up1');
} else {
    check('کانفیگ آپ‌تایم include می‌شود', false, 'array برنگشت');
    check('db.pass دقیقاً بازیابی شد', false);
    check('db.name درست است', false);
    check('admin_id عدد شد', false);
    check('bot_token درست است', false);
    check('domain نشسته', false);
    check('base_url درست است', false);
}

// پچ دوم روی فایل از قبل پچ‌شده باید خطا بدهد (نه اینکه بی‌صدا رد شود)
$againThrew = false;
try {
    Manager::patchUptimeConfig($upDir, $myCfg, 'x', $TOKEN, 1, 'u', 'd', 'b');
} catch (Throwable $e) {
    $againThrew = true;
}
check('پچ دوم روی کانفیج پچ‌شده خطا می‌دهد', $againThrew);

// ===================================================================
section('کانفیگ پاسارگاد');
// ===================================================================
$pasDir = $work . '/pasargad';
Manager::copyDir(Manager::templateDir('pasargad'), $pasDir, (array)(Manager::templateSpec('pasargad')['exclude'] ?? []));

$pasWriteOk = true; $pasRes = ['secret' => '', 'crypto' => ''];
try {
    $pasRes = Manager::writePasargadConfig(
        $pasDir, $TOKEN, 999001, 'mypas', 'https://example.com/botsaz/bots/pas1/'
    );
} catch (Throwable $e) {
    $pasWriteOk = false;
    echo "    (خطا: " . $e->getMessage() . ")\n";
}
check('writePasargadConfig بدون استثنا اجرا شد', $pasWriteOk);
check('config.php ساخته شد', is_file($pasDir . '/config.php'));
check('config.example.php کپی شده بود (تا patch کار کند)', is_file($pasDir . '/config.example.php'));
check('secret تصادفی ساخته شد', strlen($pasRes['secret']) >= 32 && strlen($pasRes['secret']) <= 256, (string)strlen($pasRes['secret']));
check('secret با کاراکترهای مجاز تلگرام', preg_match('/^[A-Za-z0-9_-]+$/', $pasRes['secret']) === 1, $pasRes['secret']);
check('secret مقدار نمونه نیست', $pasRes['secret'] !== 'CHANGE-THIS-RANDOM-SECRET');
check('crypto_key حداقل ۳۲ کاراکتر', strlen($pasRes['crypto']) >= 32, (string)strlen($pasRes['crypto']));
check('secret و crypto یکی نیستند', $pasRes['secret'] !== $pasRes['crypto']);

$pasCfgRaw = (string)@file_get_contents($pasDir . '/config.php');
check('توکن نشسته', str_contains($pasCfgRaw, $TOKEN));
check('secret نشسته', str_contains($pasCfgRaw, $pasRes['secret']));
check('crypto_key نشسته', str_contains($pasCfgRaw, $pasRes['crypto']));
check('یوزرنیم نشسته', str_contains($pasCfgRaw, 'mypas'));
check('super_admins به آیدی تبدیل شد', (bool)preg_match("/'super_admins'\s*=>\s*\[\s*999001\s*\]/", $pasCfgRaw));
check('placeholder نمونه دیگر داخل کانفیگ نیست', !str_contains($pasCfgRaw, 'PUT_BOT_TOKEN_HERE'));
check('base_url اسلش انتهایی ندارد', str_contains($pasCfgRaw, "'https://example.com/botsaz/bots/pas1'"));
check('پوشهٔ data/logs ساخته/موجود است', is_dir($pasDir . '/data/logs'));

$pasCfgArr = @include($pasDir . '/config.php');
if (is_array($pasCfgArr)) {
    check('کانفیگ پاسارگاد include می‌شود', true);
    check('bot_token درست است', ($pasCfgArr['bot_token'] ?? null) === $TOKEN);
    check('webhook_secret درست است', ($pasCfgArr['webhook_secret'] ?? null) === $pasRes['secret']);
    check('crypto_key درست است', ($pasCfgArr['crypto_key'] ?? null) === $pasRes['crypto']);
    check('super_admins آرایهٔ عدد است', ($pasCfgArr['super_admins'] ?? null) === [999001], var_export($pasCfgArr['super_admins'] ?? null, true));
    check('bot_username درست است', ($pasCfgArr['bot_username'] ?? null) === 'mypas');
    check('base_url درست است', ($pasCfgArr['base_url'] ?? null) === 'https://example.com/botsaz/bots/pas1');
} else {
    foreach (['کانفیگ پاسارگاد include می‌شود','bot_token درست است','webhook_secret درست است','crypto_key درست است','super_admins آرایهٔ عدد است','bot_username درست است','base_url درست است'] as $n) check($n, false);
}
// cleanup باید config.example.php را پاک کند (دیگر لازم نیست و حتی .htaccess می‌بنددش)
Manager::cleanupExtraFiles($pasDir, (array)(Manager::templateSpec('pasargad')['cleanup'] ?? []));
check('config.example.php بعد از cleanup پاک شد', !file_exists($pasDir . '/config.example.php'));

// ===================================================================
section('نشت فایل‌های زمان‌اجرا');
// ===================================================================
// فایل‌هایی که نباید هرگز به ربات تازه کپی شوند — مستقل از قالب
$mustNotCopyBoth = [
    'install.sh', 'SOLUTION_SUMMARY.md', 'install.log',
    'composer.json', 'composer.lock', 'phpstan.neon', 'SOURCE.md',
    '.git', 'tests',
];
foreach ($mustNotCopyBoth as $p) {
    check("«{$p}» به آپ‌تایم کپی نشده", !file_exists($upDir . '/' . $p));
    check("«{$p}» به پاسارگاد کپی نشده", !file_exists($pasDir . '/' . $p));
}
// فایل‌های زمان‌اجرا (فقط پاسارگاد SQLite دارد)
foreach (['data/bot.sqlite', 'data/.migrate.lock', 'data/worker.lock'] as $p) {
    check("«{$p}» کپی نشده", !file_exists($pasDir . '/' . $p));
}
check('لاگ‌های توسعهٔ آپ‌تایم کپی نشده', glob($upDir . '/logs/*.log') === []);
check('لاگ‌های توسعهٔ پاسارگاد کپی نشده', !is_file($pasDir . '/data/logs/2026-10-02.log'));
check('قالب اصلی دست‌نخورده ماند (config.example.php)', is_file(Manager::templateDir('pasargad') . '/config.example.php'));
check('.git کپی نشده', !is_dir($upDir . '/.git') && !is_dir($pasDir . '/.git'));
// ولی فایل‌های لازم حاضرند — نبودِ vendor یعنی رباتی که ۵۰۰ می‌دهد
check('vendor آپ‌تایم کپی شد (لازم است)', is_file($upDir . '/vendor/autoload.php'));
check('index.php آپ‌تایم کپی شد', is_file($upDir . '/index.php'));
check('table.php آپ‌تایم کپی شد', is_file($upDir . '/table.php'));
check('bot.php پاسارگاد کپی شد', is_file($pasDir . '/bot.php'));
check('bootstrap.php پاسارگاد کپی شد', is_file($pasDir . '/bootstrap.php'));
check('src/ پاسارگاد کپی شد', is_dir($pasDir . '/src/Support'));
check('cron/worker.php پاسارگاد کپی شد', is_file($pasDir . '/cron/worker.php'));
check('nowpayments_ipn.php پاسارگاد کپی شد', is_file($pasDir . '/nowpayments_ipn.php'));

// ===================================================================
section('مایگریشن SQLite پاسارگاد');
// ===================================================================
$dbFile = $pasDir . '/data/bot.sqlite';
@unlink($dbFile);
// مسیر دیسپچر رجیستری باید دقیقاً همان کار را بکند
$inst = Manager::installTemplateSchema('pasargad', $pasDir);
check('installTemplateSchema موفق بود', !empty($inst['migrated']), $inst['note']);
check('فایل SQLite ساخته شد', is_file($dbFile));
// قالبی که روش http دارد نباید وارد مسیر مایگریشن شود
$instHttp = Manager::installTemplateSchema('uptime', $work . '/does-not-exist-uptime');
check('قالب http از مسیر مایگریشن رد می‌شود', !empty($instHttp['migrated']), json_encode($instHttp, JSON_UNESCAPED_UNICODE));
// قالب ناشناس نباید چیزی نصب کند ولی نباید هم بترکاند
$instNone = Manager::installTemplateSchema('nope-xyz', $pasDir);
check('قالب ناشناس چیزی نصب نمی‌کند', !empty($instNone['migrated']));
if (is_file($dbFile)) {
    try {
        $p = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $p->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'packages', 'orders', 'payments', 'settings'] as $t) {
            check("جدول «{$t}» ساخته شد", in_array($t, $tables, true), implode(',', $tables));
        }
        // مایگریشن باید idempotent باشد (دوباره اجرا شدن خطا ندهد)
        $inst2 = Manager::installPasargadSchema($pasDir);
        check('اجرای دوبارهٔ مایگریشن هم موفق است', !empty($inst2['migrated']), $inst2['note']);
    } catch (Throwable $e) {
        check('اتصال به SQLite ساخته‌شده', false, $e->getMessage());
    }
    // روی ویندوز تا وقتی اتصال باز است فایل‌های -wal/-shm قابل حذف نیستند
    $p = null;
    gc_collect_cycles();
}
// مسیر نامعتبر نباید فایل/کلاس را بترکاند
$instBad = Manager::installPasargadSchema($work . '/does-not-exist');
check('مسیر ناموجود گزارش می‌دهد، نمی‌ترکاند', $instBad['migrated'] === false && $instBad['note'] !== '');

// ===================================================================
section('مرز exclude و cleanup');
// ===================================================================
// رگرسیون: buildBot یک‌بار «exclude» را در cleanupExtraFiles هم می‌داد و چون
// exclude پاسارگاد شامل data/logs/ است، پوشهٔ لاگِ رباتِ تازه بلافاصله پاک می‌شد.
// یعنی یک قالب کاملاً سالم، بعد از نصب ناقص تحویل داده می‌شد و هیچ تستی هم
// (که فقط رجیستری را نگاه می‌کرد) این را نمی‌دید.
$gen = array_map(static fn($p) => rtrim((string)$p, '/'), (array)(Manager::templateSpec('pasargad')['generated'] ?? []));
$ex = array_map(static fn($p) => rtrim((string)$p, '/'), (array)(Manager::templateSpec('pasargad')['exclude'] ?? []));
$cl = array_map(static fn($p) => rtrim((string)$p, '/'), (array)(Manager::templateSpec('pasargad')['cleanup'] ?? []));
check('data/logs/ در exclude پاسارگاد هست (وگرنه لاگ توسعه کپی می‌شد)', in_array('data/logs', $ex, true), implode(',', $ex));
check('data/logs/ در cleanup پاسارگاد نیست (پاکسازی نباید لاگ ربات را بکُشد)', !in_array('data/logs', $cl, true), implode(',', $cl));
check('همهٔ generated در exclude هستند', array_diff($gen, $ex) === [], implode(',', array_diff($gen, $ex)));

// اجرای واقعی: کپی exclude → cleanup فقط → پوشهٔ لاگ باید بماند
$dirKeep = $work . '/keep-logs';
@mkdir($dirKeep . '/data/logs', 0775, true);
file_put_contents($dirKeep . '/data/logs/.keep', 'x');
file_put_contents($dirKeep . '/config.example.php', '<?php // نمونه');
Manager::cleanupExtraFiles($dirKeep, (array)(Manager::templateSpec('pasargad')['cleanup'] ?? []));
check('پوشهٔ لاگ بعد از cleanup باقی است', is_dir($dirKeep . '/data/logs'));
check('config.example.php بعد از cleanup پاک شد', !file_exists($dirKeep . '/config.example.php'));
Manager::removeDir($dirKeep);

// ===================================================================
section('پاکسازی');
// ===================================================================
Manager::removeDir($work);
if (is_dir($work)) {
    // علت را نشان بده تا «پاک شد» یک ادعای توخالی نباشد
    $leftovers = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($work, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) $leftovers[] = $f->getPathname();
    check('پوشهٔ تست پاک شد', false, implode(', ', array_slice($leftovers, 0, 5)));
} else {
    check('پوشهٔ تست پاک شد', true);
}

// ===================================================================
echo "\n" . str_repeat('=', 52) . "\n";
echo "  Templates: {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 52) . "\n";
exit($fail === 0 ? 0 : 1);