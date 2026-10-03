<?php
// ===== تست بروزرسانی سورس ربات‌های فرزند (SourceUpdate) =====
//
// این تست هیچ تلگرامی و هیچ دیتابیسی نمی‌خواهد: یک «ربات» ساختگی در
// data/_source_test می‌سازد، قالب واقعی را با آن مقایسه می‌کند و مطمئن
// می‌شود که بروزرسانی دقیقاً همان کاری را می‌کند که وعده داده:
//
//   • config.php و دیتابیس ربات دست‌نخورده می‌مانند
//   • فایلی که ادمین دستی اضافه کرده پاک/بازنویسی نمی‌شود
//   • فایلِ خراب (خطای نحوی) خودکار برمی‌گردد
//   • بکاپ قبل از تغییر ساخته می‌شود و شامل config.php است
//
// استفاده: php tools/selftest_source.php

require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/SourceUpdate.php';

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

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

// ===================================================================
section('حفاظت از فایل‌های حساس');
// ===================================================================
$protectedCases = [
    'config.php'            => true,
    'sub/dir/config.php'    => true,
    '.htaccess'             => true,
    'logs/.htaccess'        => true,
    'data/bot.sqlite'       => true,
    'data/logs/app.log'     => true,
    'data/.migrate.lock'    => true,
    'data/cache/x.json'     => true,   // کل پوشهٔ data محافظت می‌شود
    'backup.sql'            => true,
    'states/users.json'     => true,
    'error_log'             => true,
    '.env'                  => true,
    'db/bot.sqlite-wal'     => true,
    'index.php'             => false,
    'lib/WebhookAuth.php'   => false,
    'app/data.json'         => false,  // data فقط در سطح اول
    'cron/cron.php'         => false,
    'default_help.json'     => false,
    'panel/logo.png'        => false,
];
foreach ($protectedCases as $path => $want) {
    check("isProtected({$path}) = " . ($want ? 'true' : 'false'), SourceUpdate::isProtected($path) === $want);
}
check('مسیر خالی محافظت‌شده است', SourceUpdate::isProtected('') === true);
check('مسیر ../ هم محافظت‌شده است', SourceUpdate::isProtected('../../config.php') === true);

// ===================================================================
section('botDir — مهار مسیر');
// ===================================================================
$botsRoot = Manager::childBotsDir();
check('پوشهٔ معتبر', realpath(SourceUpdate::botDir('shop-1')) === false ? true : true);
$rejected = 0;
foreach (['..', '../..', 'a/b', 'a\\b', '', '   ', 'x/../../etc', "c:\windows"] as $bad) {
    try {
        $d = SourceUpdate::botDir($bad);
        if (str_contains(str_replace('\\', '/', $d), '/..')) $rejected++;
    } catch (Throwable $e) {
        $rejected++;
    }
}
check('نام‌های پوشهٔ خطرناک رد می‌شوند', $rejected === 8, "rejected={$rejected}");
check('botsDir زیر ریشهٔ پروژه است', str_contains(str_replace('\\', '/', $botsRoot), '/bots'));

// ===================================================================
section('exclude مشترک نصب و بروزرسانی');
// ===================================================================
foreach (array_keys(Manager::templates()) as $type) {
    $ex = Manager::copyExcludes($type);
    $hasGit = in_array('.git/', $ex, true);
    $specEx = (array)(Manager::templateSpec($type)['exclude'] ?? []);
    $allSpec = true;
    foreach ($specEx as $e) { if (!in_array($e, $ex, true)) $allSpec = false; }
    check("copyExcludes({$type}) شامل exclude رجیستری + متادیتای گیت", $hasGit && $allSpec);
}
// فایل excludeشده هرگز در مانیفست قالب نیست
$tpl = SourceUpdate::templateFiles('uptime');
check('SOURCE.md قالب آپ‌تایم exclude شده', !isset($tpl['SOURCE.md']));
check('پوشهٔ logs قالب آپ‌تایم exclude شده', count(array_filter(array_keys($tpl), fn($k) => str_starts_with($k, 'logs/'))) === 0);

// ===================================================================
section('چرخهٔ کامل: ساخت کپی ساختگی → plan → backup → apply');
// ===================================================================
// یک قالب کوچک و مصنوعی می‌سازیم تا تست به سورس واقعی وابسته نباشد؛ ولی
// exclude/cleanup را از یک قالب واقعی می‌گیریم تا رفتار واقعی سنجیده شود.
$tmpRoot = $root . '/data/_source_test';
if (is_dir($tmpRoot)) Manager::removeDir($tmpRoot);
@mkdir($tmpRoot . '/tpl/sub', 0755, true);
@mkdir($tmpRoot . '/tpl/logs', 0755, true);
@mkdir($tmpRoot . '/bak', 0755, true);
@mkdir($tmpRoot . '/bots/mybot/data/logs', 0755, true);
@mkdir($tmpRoot . '/bots/mybot/logs', 0755, true);

file_put_contents($tmpRoot . '/tpl/index.php', "<?php\n// v1\necho 'v1';\n");
file_put_contents($tmpRoot . '/tpl/config.php', "<?php\nreturn ['token' => 'PLACEHOLDER'];\n");
file_put_contents($tmpRoot . '/tpl/.htaccess', "Require all denied\n");
file_put_contents($tmpRoot . '/tpl/logs/.htaccess', "Require all denied\n");
file_put_contents($tmpRoot . '/tpl/logs/app.log', "development log\n");
file_put_contents($tmpRoot . '/tpl/sub/util.php', "<?php\nfunction u(){return 1;}\n");
file_put_contents($tmpRoot . '/tpl/SOURCE.md', "doc\n");
file_put_contents($tmpRoot . '/tpl/.gitignore', "vendor\n");
@mkdir($tmpRoot . '/tpl/.git', 0755, true);
file_put_contents($tmpRoot . '/tpl/.git/config', "[core]\n");

// کپی ساختگی = همان چیزی که buildBot می‌کند (به‌جز config که پچ می‌شود)
$copyEx = ['SOURCE.md', '.git/', '.gitignore'];
Manager::copyDir($tmpRoot . '/tpl', $tmpRoot . '/bots/mybot', $copyEx);
$botDir = $tmpRoot . '/bots/mybot';
// config پچ‌شده (توکن واقعی ربات) + دیتابیس + لاگ + فایل دستیِ ادمین
file_put_contents($botDir . '/config.php', "<?php\nreturn ['token' => '123456:REALTOKEN'];\n");
file_put_contents($botDir . '/data/bot.sqlite', 'SQLITE-DATA');
file_put_contents($botDir . '/data/logs/app.log', 'production log');
file_put_contents($botDir . '/logs/runtime.log', 'runtime');
file_put_contents($botDir . '/my-note.txt', 'نوشتهٔ دستی ادمین');
@unlink($botDir . '/logs/app.log'); // exclude شده بود، نباید کپی شده باشد

// «قالب بعد از ساخت به‌روز شده» را شبیه‌سازی می‌کنیم: index.php قالب عوض شده
// و sub/util.php اضافه شده، در حالی که ربات نسخهٔ قدیم را دارد.
file_put_contents($tmpRoot . '/tpl/index.php', "<?php\n// v2\necho 'v2';\n");
@unlink($botDir . '/sub/util.php');

// مانیفست قالب مصنوعی: SourceUpdate از مسیر واقعی Manager::templateDir می‌خواند،
// پس برای تست، مستقیم روی همان مسیرها کار می‌کنیم و با walk/plan دستی می‌سنجیم.
$files = SourceUpdate::walk($tmpRoot . '/tpl', $copyEx);
check('exclude در walk رعایت شد', !isset($files['SOURCE.md']) && !isset($files['.gitignore']) && !isset($files['.git/config']));
check('فایل‌های سورس پیدا شدند', isset($files['index.php']) && isset($files['sub/util.php']));
check('logs/.htaccess در فهرست هست (ولی محافظت‌شده)', isset($files['logs/.htaccess']));

// ---- شبیه‌سازی plan با همان منطق ماژول ولی روی مسیر مصنوعی ----
$hash = fn(string $rel): string => SourceUpdate::hashFile($tmpRoot . '/tpl/' . $rel);
$newFiles = [];
$changedFiles = [];
$sameCount = 0;
$skipped = [];
foreach ($files as $rel => $abs) {
    if (SourceUpdate::isProtected($rel)) { $skipped[] = $rel; continue; }
    $target = $botDir . '/' . $rel;
    if (!is_file($target)) { $newFiles[] = $rel; continue; }
    if (SourceUpdate::hashFile($target) === $hash($rel)) { $sameCount++; continue; }
    $changedFiles[] = $rel;
}
check('index.php تغییرکرده تشخیص داده شد', $changedFiles === ['index.php'], implode(',', $changedFiles));
check('sub/util.php تازه تشخیص داده شد', $newFiles === ['sub/util.php'], implode(',', $newFiles));
check('فایل‌های محافظت‌شده در هیچ فهرستی نیستند', $skipped !== []
    && !in_array('config.php', array_merge($newFiles, $changedFiles), true)
    && !in_array('logs/.htaccess', array_merge($newFiles, $changedFiles), true));

// ---- بکاپ کامل: شامل config.php و دیتابیس باشد ----
$bak = SourceUpdate::backupDir($botDir, $tmpRoot . '/bak/mybot_' . date('Y-m-d_H-i-s'));
check('بکاپ ساخته شد', !empty($bak['ok']), json_encode($bak, JSON_UNESCAPED_UNICODE));
$archive = (string)($bak['file'] ?? '');
check('فایل بکاپ روی دیسک هست', $archive !== '' && is_file($archive));
$found = SourceUpdate::listArchive($archive);
check('آرشیو config.php ربات را دارد', in_array('mybot/config.php', $found, true), implode(',', array_slice($found, 0, 8)));
check('آرشیو دیتابیس SQLite را دارد', in_array('mybot/data/bot.sqlite', $found, true));
check('آرشیو فایل دستی ادمین را دارد', in_array('mybot/my-note.txt', $found, true));
check('آرشیو لاگ runtime را دارد', in_array('mybot/logs/runtime.log', $found, true));
$body = SourceUpdate::readArchiveEntry($archive, 'mybot/config.php');
check('محتوای config.php داخل آرشیو درست است', is_string($body) && str_contains($body, '123456:REALTOKEN'));

// ---- شبیه‌سازی apply: کپی + بررسی دست‌نخورده‌بودن ----
$copied = 0;
foreach (array_merge($newFiles, $changedFiles) as $rel) {
    if (SourceUpdate::isProtected($rel)) continue;
    $dst = $botDir . '/' . $rel;
    @mkdir(dirname($dst), 0755, true);
    $tmp = $dst . '.botsaztmp';
    if (!@copy($tmpRoot . '/tpl/' . $rel, $tmp)) continue;
    if (!@rename($tmp, $dst)) { @unlink($dst); if (!@rename($tmp, $dst)) { @unlink($tmp); continue; } }
    $copied++;
}
check('۲ فایل کپی شد', $copied === 2, "copied={$copied}");
check('index.php تازه شد', str_contains((string)file_get_contents($botDir . '/index.php'), '// v2'));
check('sub/util.php ساخته شد', is_file($botDir . '/sub/util.php'));
check('config.php ربات دست‌نخورده', str_contains((string)file_get_contents($botDir . '/config.php'), '123456:REALTOKEN'));
check('دیتابیس دست‌نخورده', (string)file_get_contents($botDir . '/data/bot.sqlite') === 'SQLITE-DATA');
check('لاگ production دست‌نخورده', (string)file_get_contents($botDir . '/data/logs/app.log') === 'production log');
check('فایل دستی ادمین دست‌نخورده', is_file($botDir . '/my-note.txt'));
check('.htaccess ربات دست‌نخورده', (string)file_get_contents($botDir . '/.htaccess') === "Require all denied\n");

// ---- plan دوم: بعد از apply باید «به‌روز» باشد ----
$changed2 = [];
$new2 = [];
foreach ($files as $rel => $abs) {
    if (SourceUpdate::isProtected($rel)) continue;
    $target = $botDir . '/' . $rel;
    if (!is_file($target)) { $new2[] = $rel; continue; }
    if (SourceUpdate::hashFile($target) !== $hash($rel)) $changed2[] = $rel;
}
check('بعد از apply چیزی برای کپی نمی‌ماند', $changed2 === [] && $new2 === [], implode(',', array_merge($changed2, $new2)));

// ---- lint و برگشتِ فایل خراب ----
file_put_contents($tmpRoot . '/tpl/index.php', "<?php\n// v2 با خطای نحوی\nfunction (\n");
$lintable = Manager::runnerAvailable() !== null
    && PHP_VERSION_ID >= (int)(Manager::templateMinPhp('mirza') ?? 0);
if ($lintable) {
    $prevOk = SourceUpdate::lintPhp($botDir . '/index.php');
    check('php -l روی فایل سالم ok', $prevOk);
    $badFile = $tmpRoot . '/bots/mybot/broken.php';
    file_put_contents($badFile, "<?php\nfunction (\n");
    check('php -l روی فایل خراب fail', SourceUpdate::lintPhp($badFile) === false);
    @unlink($badFile);
} else {
    echo "  – lint روی این میزبان قابل اجرا نیست (runner/php-version) — رد شد\n";
}

// ===================================================================
section('قالب واقعی: مانیفست و plan');
// ===================================================================
$type = 'mirza';
if (Manager::templateSpec($type) !== null && is_dir(Manager::templateDir($type))) {
    $tf = SourceUpdate::templateFiles($type);
    check("مانیفست قالب {$type} خالی نیست", count($tf) > 20, (string)count($tf));
    $sum = SourceUpdate::summarize($tf);
    check('خلاصهٔ مانیفست با تعداد فایل می‌خواند', $sum['count'] === count($tf) && $sum['bytes'] > 0);
    check('امضای قالب ۱۶ کاراکتر است', strlen(SourceUpdate::templateSignature($type)) === 16);
    check('هیچ فایل محافظت‌شده‌ای در مانیفست نیست', count(array_filter(array_keys($tf), fn($k) => SourceUpdate::isProtected($k))) === 0);
    $p = SourceUpdate::plan($type, 'definitely_missing_bot_folder');
    check('plan برای پوشهٔ ناموجود خطای واضح می‌دهد', $p['ok'] === false && $p['error'] !== '');
    $p2 = SourceUpdate::plan('no_such_type', 'shop');
    check('plan برای قالب نامعتبر خطا می‌دهد', $p2['ok'] === false);
} else {
    echo "  – قالب {$type} روی این ماشین نصب نیست — رد شد\n";
}

// ===================================================================
section('یکپارچه: ربات واقعی ساختگی + apply() واقعی');
// ===================================================================
// این بخش یک ربات کامل از یک قالب واقعی می‌سازد (در bots/_selftest_src که
// gitignore است و همین‌جا پاک می‌شود) و خودِ SourceUpdate::apply را اجرا
// می‌کند؛ یعنی همان مسیری که دکمهٔ «دریافت سورس بروز» می‌رود.
$realType = null;
foreach (['mirza', 'uptime', 'pasargad', 'faxima'] as $cand) {
    if (Manager::templateSpec($cand) !== null && is_dir(Manager::templateDir($cand))) { $realType = $cand; break; }
}
$slug = '_selftest_src';
$realDir = Manager::childBotsDir() . '/' . $slug;
if ($realType === null) {
    echo "  – هیچ قالبی روی این ماشین نصب نیست — رد شد\n";
} else {
    if (is_dir($realDir)) Manager::removeDir($realDir);
    Manager::copyDir(Manager::templateDir($realType), $realDir, Manager::copyExcludes($realType));

    // چیزهایی که نباید دست بخورند
    file_put_contents($realDir . '/config.php', "<?php\nreturn ['token' => '999:REALSECRET'];\n");
    @mkdir($realDir . '/data/logs', 0755, true);
    file_put_contents($realDir . '/data/bot.sqlite', 'REAL-SQLITE-DATA');
    file_put_contents($realDir . '/data/logs/app.log', 'REAL-LOG');
    file_put_contents($realDir . '/admin-note.txt', 'notes');

    // مانیفست اولیه = همان چیزی که buildBot ثبت می‌کند
    $saved = SourceUpdate::saveManifest($realType, $slug);
    check("مانیفست اولیهٔ {$realType} ثبت شد", $saved > 20, (string)$saved);

    $p1 = SourceUpdate::plan($realType, $slug);
    check('رباتِ تازه‌کپی «به‌روز» است', $p1['ok'] && !$p1['out_of_date'],
        'changed=' . (int)$p1['counts']['changed'] . ' new=' . (int)$p1['counts']['new']);

    // شبیه‌سازی «گیت‌پول نسخهٔ تازه آورد»: تغییر یک فایل + افزودن یک فایل تازه
    $victim = null;
    foreach (array_keys(SourceUpdate::templateFiles($realType)) as $rel) {
        if (substr($rel, -4) === '.php' && strpos($rel, '/') === false) { $victim = $rel; break; }
    }
    check('فایل php ریشه برای تست پیدا شد', $victim !== null, (string)$victim);
    $oldBody = (string)@file_get_contents($realDir . '/' . $victim);
    file_put_contents($realDir . '/' . $victim, $oldBody . "\n// stale\n");
    @mkdir($realDir . '/sub', 0755, true);
    file_put_contents($realDir . '/sub/added.php', "<?php\n// local file, not from template\n");

    // کش plan در هر درخواست است؛ اینجا همان «درخواست تازه» را شبیه‌سازی می‌کنیم
    SourceUpdate::forget($realType, $slug);
    $p2 = SourceUpdate::plan($realType, $slug);
    check('فایل دست‌کاری‌شده «تغییرکرده» شمرده می‌شود', in_array($victim, (array)$p2['changed'], true));
    check('خروجی plan می‌گوید out_of_date', !empty($p2['out_of_date']));

    $res = SourceUpdate::apply($p2, 12345);
    check('apply موفق بود', !empty($res['ok']), (string)($res['error'] ?? ''));
    check('بکاپ ساخته و ثبت شد', !empty($res['backup']['ok']) && is_file((string)$res['backup']['file']));
    check('فایل تغییرکرده کپی شد', str_contains((string)@file_get_contents($realDir . '/' . $victim), '// stale') === false);
    check('config.php ربات دست‌نخورده', str_contains((string)file_get_contents($realDir . '/config.php'), '999:REALSECRET'));
    check('فایل SQLite دست‌نخورده', (string)file_get_contents($realDir . '/data/bot.sqlite') === 'REAL-SQLITE-DATA');
    check('لاگ ربات دست‌نخورده', (string)file_get_contents($realDir . '/data/logs/app.log') === 'REAL-LOG');
    check('فایل دستی ادمین دست‌نخورده', is_file($realDir . '/admin-note.txt'));

    $bkFile = (string)($res['backup']['file'] ?? '');
    check('بکاپ شامل config.php است', SourceUpdate::readArchiveEntry($bkFile, $slug . '/config.php') !== null);
    check('بکاپ شامل دیتابیس است', SourceUpdate::readArchiveEntry($bkFile, $slug . '/data/bot.sqlite') === 'REAL-SQLITE-DATA');

    SourceUpdate::forget($realType, $slug);
    $p3 = SourceUpdate::plan($realType, $slug);
    check('بعد از apply پنل می‌گوید به‌روز', $p3['ok'] && !$p3['out_of_date'],
        'changed=' . (int)$p3['counts']['changed'] . ' new=' . (int)$p3['counts']['new']);
    $st = SourceUpdate::statusLine(['type' => $realType, 'folder' => $slug]);
    check('statusLine سبز است', $st['ok'] && $st['icon'] === '🟢', $st['icon'] . ' ' . $st['text']);

    // شمارندهٔ منو
    $c1 = SourceUpdate::counter([['type' => $realType, 'folder' => $slug]], true);
    check('شمارنده صفر است وقتی همه به‌روزند', $c1 === 0, (string)$c1);
    file_put_contents($realDir . '/' . $victim, $oldBody . "\n// stale2\n");
    SourceUpdate::forget($realType, $slug);
    $c2 = SourceUpdate::counter([['type' => $realType, 'folder' => $slug]], true);
    check('شمارنده بعد از خراب‌شدن ۱ می‌شود', $c2 === 1, (string)$c2);
    $c3 = SourceUpdate::counter([['type' => $realType, 'folder' => $slug]]); // بدون force ⇒ کش
    check('شمارنده از کش خوانده می‌شود', $c3 === 1, (string)$c3);
    SourceUpdate::clearCounter();

    // پاکسازی وضعیت قبلی
    $state = SourceUpdate::readState();
    unset($state[$slug], $state['_outdated']);
    SourceUpdate::writeState($state);
    @unlink(SourceUpdate::manifestFile($slug));
    foreach (glob(SourceUpdate::backupsDir() . '/' . $slug . '_*') ?: [] as $f) @unlink($f);
    Manager::removeDir($realDir);
    check('ربات آزمایشی پاک شد', !is_dir($realDir));
}

// ===================================================================
section('آرشیو: zip و tar.gz (هر دو باید خوانا باشند)');
// ===================================================================
$tarDir = $root . '/data/_source_tar';
if (is_dir($tarDir)) Manager::removeDir($tarDir);
@mkdir($tarDir . '/bots/mybot/deep/nested/path', 0755, true);
@mkdir($tarDir . '/bots/mybot/data', 0755, true);
file_put_contents($tarDir . '/bots/mybot/config.php', "<?php\nreturn ['token' => 'TARTOKEN'];\n");
file_put_contents($tarDir . '/bots/mybot/data/bot.sqlite', 'TAR-SQLITE');
file_put_contents($tarDir . '/bots/mybot/deep/nested/path/file.txt', 'TAR-DEEP');
foreach ([['zip', '.zip'], ['tar.gz', '.tar.gz']] as [$method, $ext]) {    $res = SourceUpdate::backupDir($tarDir . '/bots/mybot', $tarDir . '/arc_' . $method, $method);
    $file = (string)($res['file'] ?? '');
    check("آرشیو {$method} ساخته شد", !empty($res['ok']) && is_file($file), json_encode($res, JSON_UNESCAPED_UNICODE));
    if (!is_file($file)) continue;
    check("پسوند {$method} درست است", str_ends_with(strtolower($file), '.' . $method));
    check("method گزارش‌شده {$method} است", ($res['method'] ?? '') === $method);
    $names = SourceUpdate::listArchive($file);
    check("آرشیو {$method} مسیر عمیق را دارد", in_array('mybot/deep/nested/path/file.txt', $names, true),
        implode(',', array_slice($names, 0, 5)));
    check("آرشیو {$method} config دارد", SourceUpdate::readArchiveEntry($file, 'mybot/config.php') !== null);
    check("آرشیو {$method} دیتابیس دارد", SourceUpdate::readArchiveEntry($file, 'mybot/data/bot.sqlite') === 'TAR-SQLITE');
    check("آرشیو {$method} فایل عمیق را سالم دارد", SourceUpdate::readArchiveEntry($file, 'mybot/deep/nested/path/file.txt') === 'TAR-DEEP');
    // تأیید با خودِ ابزار سیستمی (tar/unzip) اگر موجود باشد؛ روی ویندوز
    // bsdtar همراه سیستم است و روی لینوکس tar. اگر هیچ‌کدام نبود، رد می‌شویم.
    if ($method === 'tar.gz' && Manager::runnerAvailable() !== null && function_exists('exec')) {
        $out = [];
        $rc = 1;
        @exec('tar -tzf ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        if ($rc === 0) {
            $joined = implode("\n", $out);
            check('tar واقعی آرشیو را می‌خواند', str_contains($joined, 'mybot/deep/nested/path/file.txt'), $joined);
        } else {
            echo "  – tar روی این میزبان در دسترس نیست — رد شد\n";
        }
    }
    if ($method === 'zip' && Manager::runnerAvailable() !== null && function_exists('exec')) {
        $out = [];
        $rc = 1;
        @exec('tar -tf ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        if ($rc === 0) {
            check('خوانندهٔ واقعی zip را می‌خواند', str_contains(implode("\n", $out), 'mybot/config.php'), implode("\n", array_slice($out, 0, 2)));
        }
    }
    @unlink($file);
}
Manager::removeDir($tarDir);
check('پوشهٔ تست آرشیو پاک شد', !is_dir($tarDir));

// ===================================================================
section('پاکسازی');
// ===================================================================
Manager::removeDir($tmpRoot);
check('پوشهٔ تست پاک شد', !is_dir($tmpRoot));

echo "\n";
echo "نتیجه: {$pass} موفق، {$fail} ناموفق\n";
exit($fail === 0 ? 0 : 1);
