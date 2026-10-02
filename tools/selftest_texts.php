<?php
// تست ماژول متن‌های پویا (src/Texts.php) روی یک دیتابیس sqlite موقت
// اجرا:  php tools/selftest_texts.php
$root = dirname(__DIR__);
require_once $root . '/src/Store.php';
require_once $root . '/src/BotApi.php';
require_once $root . '/src/Nav.php';
require_once $root . '/src/Manager.php';
require_once $root . '/src/Payment/Payments.php';
require_once $root . '/src/Payment/Gateways.php';
require_once $root . '/src/Texts.php';

$tmpDb = sys_get_temp_dir() . '/botsaz_texts_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$cfg = ['manager_db' => $tmpDb, 'db_host' => '127.0.0.1', 'db_user' => 'root', 'db_pass' => '', 'db_port' => 3306, 'base_url' => 'https://example.com/botsaz-faxima'];
$store = new Store($tmpDb, $cfg);

$fail = 0;
function check(string $name, bool $ok, string $note = ''): void {
    global $fail;
    if (!$ok) $fail++;
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($ok || $note === '' ? '' : '  ← ' . $note) . "\n";
}

$keys = Texts::keys();
$groups = Texts::groups();

// ===== سلامت رجیستری =====
check('تعداد متن‌ها منطقی است (>= 15)', count($keys) >= 15, 'count=' . count($keys));
check('گروه‌ها جمع‌شان با تعداد کلیدها می‌خواند',
    array_sum(array_map(fn($g) => $g['count'], $groups)) === count($keys));

$validGroups = ['general', 'build', 'manage', 'payment'];
foreach ($keys as $key) {
    $item = Texts::item($key);
    check("رجیستری: کلید «{$key}» شکل استاندارد دارد", (bool)preg_match('/^[a-z][a-z0-9_]*$/', $key));
    check("رجیستری: کلید «{$key}» گروه معتبر دارد", in_array($item['group'] ?? '', $validGroups, true));
    check("رجیستری: کلید «{$key}» برچسب غیرخالی دارد", trim($item['label'] ?? '') !== '');
    check("رجیستری: کلید «{$key}» یکتا است (برچسب گروه)",
        count(array_filter(Texts::keys($item['group']), fn($k) => Texts::label($k) === $item['label'])) === 1);
    check("رجیستری: برچسب «{$key}» برای دکمهٔ تلگرام کوتاه است",
        mb_strlen($item['label']) <= 60, mb_strlen($item['label']));
    // پیش‌فرض فقط contact می‌تواند خالی باشد (بخش اختیاری راهنما)
    if ($key !== 'contact') {
        check("رجیستری: پیش‌فرض «{$key}» غیرخالی است", trim(Texts::defaultText($key)) !== '');
    }
    check("رجیستری: پیش‌فرض «{$key}» از سقف طول رد نشده",
        mb_strlen(Texts::defaultText($key)) <= Texts::MAX_LEN);
    // هیچ جایگذینِ ناشناخته‌ای نباید در متن پیش‌فرض باشد (وگرنه به کاربر می‌رسد)
    $unknown = Texts::unknownVars($key, Texts::defaultText($key));
    check("رجیستری: پیش‌فرض «{$key}» جایگذینِ ناشناخته ندارد", $unknown === [], implode(',', $unknown));
    // برای هر جایگذین باید مقدار نمونه تعریف شده باشد تا پیش‌نمایش بی‌معنا نشود
    foreach (Texts::varNames($key) as $vn) {
        $sample = Texts::sampleVars($key)[$vn] ?? null;
        check("رجیستری: مقدار نمونهٔ «{$vn}» در «{$key}» هست", $sample !== null && $sample !== '…');
    }
    // کال‌بک دکمه‌ها باید زیر سقف ۶۴ بایت تلگرام بماند
    foreach (['texts:show:' . $key, 'texts:edit:' . $key, 'texts:reset:' . $key] as $cb) {
        check("رجیستری: کال‌بک «{$cb}» کوتاه است", strlen($cb) <= 64, strlen($cb) . ' bytes');
    }
}
foreach ($groups as $g) {
    $cb = 'texts:g:' . $g['key'];
    check("رجیستری: کال‌بک گروه «{$cb}» کوتاه است", strlen($cb) <= 64);
}

// ===== رفتار بدون متن دلخواه: عین پیش‌فرض =====
check('بدون متن دلخواه، خروجی = پیش‌فرض',
    Texts::get($store, 'type_prompt') === Texts::defaultText('type_prompt'));
check('وضعیت اولیه 📌 (پیش‌فرض) است', Texts::statusIcon($store, 'type_prompt') === '📌');
check('پیش‌فرض توکن، خط ناوبری درست دارد',
    str_contains(Texts::get($store, 'token_prompt', ['type' => 'فاکسیما']), Nav::BACK)
    && str_contains(Texts::get($store, 'token_prompt', ['type' => 'فاکسیما']), 'فاکسیما'));
check('جایگذین‌ها عوض می‌شوند',
    !str_contains(Texts::get($store, 'welcome', ['role' => 'X', 'types' => 'Y']), '‹role›'));

// ===== ذخیره / خواندن / بازگردانی =====
check('ذخیرهٔ موفق true می‌دهد', Texts::set($store, 'type_prompt', 'نوع ربات را انتخاب کن (متن من) 👇'));
check('بعد از ذخیره، وضعیت ✍️ است', Texts::statusIcon($store, 'type_prompt') === '✍️');
check('متن دلخواه خوانده می‌شود', str_contains(Texts::get($store, 'type_prompt'), 'متن من'));
check('پس از ذخیره، متن خام هم دلخواه است', Texts::text($store, 'type_prompt') === 'نوع ربات را انتخاب کن (متن من) 👇');
check('پیش‌نمایش بعد از ذخیره ساخته می‌شود', mb_strlen(Texts::preview($store, 'type_prompt')) > 0);

// متن خالی پذیرفته نمی‌شود
check('متن خالی رد می‌شود', Texts::set($store, 'type_prompt', "   \n  ") === false);
check('کلید ناشناخته رد می‌شود', Texts::set($store, 'nope_key', 'x') === false);
check('خواندن کلید ناشناخته خالی نیست (پیام خطا)',
    str_contains(Texts::get($store, 'nope_key'), 'nope_key'));

// جایگذینِ ناشناخته باید هم هشدار بگیرد هم از خروجی حذف شود
check('جایگذینِ ناشناخته شناسایی می‌شود',
    Texts::unknownVars('type_prompt', 'سلام ‹foo› ‹bar›') === ['foo', 'bar']);
Texts::set($store, 'type_prompt', 'متن با ‹foo› ناشناخته');
check('جایگذینِ ناشناخته از خروجی حذف می‌شود',
    !str_contains(Texts::get($store, 'type_prompt'), '‹'));
Texts::reset($store, 'type_prompt');

// جایگذینِ اعلام‌نشده (فراخوانی بدون متغیر) هم نباید به کاربر برسد
$helpNoVars = Texts::get($store, 'help');
check('راهنما بدون متغیر، جایگذینِ جای‌مانده ندارد',
    !str_contains($helpNoVars, '‹') && !str_contains($helpNoVars, 'payment'));

// بازگشت به پیش‌فرض
check('reset true می‌دهد', Texts::reset($store, 'type_prompt'));
check('بعد از reset، وضعیت 📌 است', Texts::statusIcon($store, 'type_prompt') === '📌');
check('بعد از reset، خروجی = پیش‌فرض', Texts::get($store, 'type_prompt') === Texts::defaultText('type_prompt'));
check('reset کلید ناشناخته false می‌دهد', Texts::reset($store, 'nope_key') === false);

// ===== متن‌های گروه پرداخت: همان ساز و کار قدیمی (PaymentGateways) =====
check('متن دلخواه کارت‌به‌کارت از PaymentGateways خوانده می‌شود',
    Texts::defaultText('card') === PaymentGateways::defaultText('card'));
check('ذخیرهٔ متن پرداخت در همان کلید قدیمی می‌رود',
    Texts::set($store, 'card', 'شماره کارت جدید من 6037-0000')
    && PaymentGateways::customText($store, 'card') === 'شماره کارت جدید من 6037-0000');
check('وضعیت متن پرداخت هم ✍️ می‌شود', Texts::statusIcon($store, 'card') === '✍️');
check('خروجی متن پرداخت همان متن دلخواه است',
    Texts::get($store, 'card') === 'شماره کارت جدید من 6037-0000');
check('بازگشت متن پرداخت به پیش‌فرض', Texts::reset($store, 'card')
    && PaymentGateways::customText($store, 'card') === '');
check('متن لیمیت جایگذین‌هایش را پر می‌کند',
    !str_contains(Texts::get($store, 'limit', ['amount' => '1000', 'slots' => '3']), '‹amount›'));

// ===== مرزها =====
$long = str_repeat('الف', Texts::MAX_LEN + 500);
Texts::set($store, 'broadcast_done', $long);
check('متن بلند به سقف کوتاه می‌شود',
    mb_strlen(Texts::text($store, 'broadcast_done')) <= Texts::MAX_LEN);
Texts::reset($store, 'broadcast_done');

$groupsKeys = Texts::keys('build');
check('کلیدهای گروه build خالی نیست', count($groupsKeys) >= 5);
check('isValidGroup درست کار می‌کند',
    Texts::isValidGroup('general') && !Texts::isValidGroup('nope'));
check('isValid درست کار می‌کند',
    Texts::isValid('welcome') && !Texts::isValid('welcome2'));

@unlink($tmpDb);

echo "\n" . ($fail === 0 ? "TEXTS SELFTEST PASSED\n" : "TEXTS SELFTEST FAILED ({$fail})\n");
exit($fail === 0 ? 0 : 1);
