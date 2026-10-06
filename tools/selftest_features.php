<?php
// تست دودیِ قابلیت‌های تازه (بدون تماس با تلگرام، بدون دست‌زدن به دیتابیسِ اصلی)
// اجرا:  php tools/selftest_features.php
$root = dirname(__DIR__);
require_once $root . '/src/BotApi.php';
require_once $root . '/src/Store.php';
require_once $root . '/src/Manager.php';
require_once $root . '/src/Ui.php';
require_once $root . '/src/Nav.php';
require_once $root . '/src/Texts.php';
require_once $root . '/src/FxRate.php';
require_once $root . '/src/BuildSettings.php';
require_once $root . '/src/Payment/Payments.php';
require_once $root . '/src/Payment/Gateways.php';
require_once $root . '/src/Payment/Limits.php';
require_once $root . '/src/Payment/Pricing.php';
require_once $root . '/src/Payment/CardToCard.php';
require_once $root . '/src/Payment/NowPayments.php';
require_once $root . '/src/Payment/ZarinPal.php';
require_once $root . '/src/Payment/AqaPay.php';
require_once $root . '/src/Payment/AdminPanel.php';

$tmpDb = sys_get_temp_dir() . '/botsaz_feat_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$cfg = [
    'manager_db' => $tmpDb, 'db_host' => '127.0.0.1', 'db_user' => 'root',
    'db_pass' => '', 'db_port' => 3306, 'base_url' => 'https://example.com/botsaz-faxima',
];
$store = new Store($tmpDb, $cfg);
Payments::ensureSchema($store);

$fail = 0;
function check(string $name, bool $ok, string $note = ''): void {
    global $fail;
    if (!$ok) $fail++;
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($ok || $note === '' ? '' : '  ← ' . $note) . "\n";
}

echo "--- Ui (زیباسازی متن) ---\n";
check('escape درست است', Ui::e('<b>x</b> & "y"') === '&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;');
check('link ساخته می‌شود', str_contains(Ui::link('https://a.test/p?q=1'), '<a href="https://a.test/p?q=1">a.test/p?q=1</a>'));
check('link اسکیمای خطرناک رد می‌شود', !str_contains(Ui::link('javascript:alert(1)'), '<a href'));
check('www به https اضافه می‌شود', str_contains(Ui::link('www.tgju.org'), 'href="https://www.tgju.org"'));
check('autoLink متن آزاد را لینک می‌کند', str_contains(Ui::autoLink('برو به https://t.me/x_bot'), '<a href="https://t.me/x_bot">'));
check('autoLink داخل تگ را دست نمی‌زند', substr_count(Ui::autoLink('<a href="https://t.me/a">a</a>'), '<a href') === 1);
// دو بارِ پشت‌سرهم نباید لینکِ تو‌در‌تو بسازد (وگرنه تلگرام خطای parse می‌دهد و پیام از بین می‌رود)
$twice = Ui::out(Ui::out('سایت: https://www.example.org/a/b و https://example.net/c'));
check('autoLink idempotent است (لینکِ تو‌در‌تو نمی‌سازد)', substr_count($twice, '<a href') === 2, $twice);
check('autoLink idempotent: برچسب داخل <a> دست‌نخورده',
    str_contains($twice, '<a href="https://www.example.org/a/b">www.example.org/a/b</a>'), $twice);
$doubled = Ui::out(Ui::out(Ui::out('https://a.test/x')));
check('سه بار پردازش ⇒ باز هم یک لینک', substr_count($doubled, '<a href') === 1, $doubled);
check('code درون‌خطی', Ui::code('a<b') === '<code>a&lt;b</code>');
check('quote بلوک', Ui::quote('سلام') === '<blockquote>سلام</blockquote>');
check('tidy فاصله‌های چندگانه را یکی می‌کند', Ui::tidy("a   b") === 'a b');
check('tidy خطوط خالیِ پشت‌سرهم', Ui::tidy("a\n\n\n\nb") === "a\n\nb");
check('tidy فاصلهٔ قبل از «:» را می‌برد', Ui::tidy('سلام :') === 'سلام:');
check('tidy داخل <code> را خراب نمی‌کند', Ui::tidy("a\n<code>1   2</code>") === "a\n<code>1   2</code>");
check('kv ساختار درست', Ui::kv('🔹', 'نام', 'x') === '🔹 <b>نام:</b> x');
check('kv با mono', str_contains(Ui::kv('🔹', 'نام', 'a b', true), '<code>a b</code>'));
check('num جداکنندهٔ هزارگان', Ui::num(1234567) === '1,234,567');
check('toman', Ui::toman(50000) === '50,000 تومان');

echo "\n--- BuildSettings (کلیدهای روشن/خاموش) ---\n";
check('پیش‌فرض: نیاز به تأیید روشن است', BuildSettings::approvalRequired($store) === true);
check('پیش‌فرض: تعمیرات خاموش است', BuildSettings::maintenanceOn($store) === false);
check('skipApproval در حالت پیش‌فرض false', BuildSettings::skipApproval($store) === false);
BuildSettings::toggleApproval($store);
check('بعد از toggle، تأیید غیرفعال شد', BuildSettings::approvalRequired($store) === false);
check('skipApproval بعد از toggle درست است', BuildSettings::skipApproval($store) === true);
BuildSettings::toggleApproval($store);
check('toggle دوم برمی‌گرداند', BuildSettings::approvalRequired($store) === true);
BuildSettings::setMaintenance($store, true);
check('حالت تعمیرات روشن شد', BuildSettings::maintenanceOn($store) === true);
BuildSettings::setMaintenanceEta($store, 'تا فردا صبح');
$notice = BuildSettings::maintenanceNotice($store);
check('پیام تعمیرات عنوان درست دارد', str_contains($notice, 'قسمت ربات در حال تعمیر است'), $notice);
check('پیام تعمیرات زمان تقریبی را دارد', str_contains($notice, 'تا فردا صبح'));
check('پیام تعمیرات HTML معتبر (بدون جای‌نگهدار)', !str_contains($notice, '‹'));
BuildSettings::setMaintenance($store, false);
Texts::set($store, 'maintenance', "🛠 تعمیرِ <b>کوتاه</b> ‹eta›");
check('متن دلخواه تعمیرات استفاده می‌شود', str_contains(BuildSettings::maintenanceNotice($store), 'تعمیرِ'));
Texts::reset($store, 'maintenance');

echo "\n--- Gateways (درگاه‌های تازه) ---\n";
$keys = PaymentGateways::keys();
foreach (['limit', 'template', 'card', 'nowpay', 'zarin', 'aqaye'] as $k) {
    check("کلید «{$k}» معتبر است", PaymentGateways::isValidKey($k));
}
check('متن پیش‌فرض زرین‌پال موجود', PaymentGateways::defaultText('zarin') !== '');
check('متن پیش‌فرض آقای پرداخت موجود', PaymentGateways::defaultText('aqaye') !== '');
check('کلید ناشناخته رد می‌شود', !PaymentGateways::isValidKey('zarinpal'));
check('زرین‌پال بدون merchant_id در روش‌ها نیست',
    !in_array(Payments::METHOD_ZARIN, PaymentGateways::availableMethods($store, $cfg), true));
PaymentZarin::setCredentials($store, '00000000-0000-0000-0000-000000000000', false);
check('زرین‌پال با merchant_id اضافه شد',
    in_array(Payments::METHOD_ZARIN, PaymentGateways::availableMethods($store, $cfg), true));
check('آقای پرداخت بدون pin در روش‌ها نیست',
    !in_array(Payments::METHOD_AQAYE, PaymentGateways::availableMethods($store, $cfg), true));
PaymentAqaye::setPin($store, 'test-pin-123');
check('آقای پرداخت با pin اضافه شد',
    in_array(Payments::METHOD_AQAYE, PaymentGateways::availableMethods($store, $cfg), true));
check('زرین‌پال «تأیید خودکار» دارد', PaymentGateways::isAutoConfirmed(Payments::METHOD_ZARIN));
check('آقای پرداخت «تأیید خودکار» دارد', PaymentGateways::isAutoConfirmed(Payments::METHOD_AQAYE));
check('کارت‌به‌کارت تأیید خودکار ندارد', !PaymentGateways::isAutoConfirmed(Payments::METHOD_CARD));

echo "\n--- کیبوردِ روش پرداخت ---\n";
$pid = Payments::createPayment($store, 555001, Payments::KIND_LIMIT, '', 1, 50000, '');
$kb = PaymentPanel::methodKb($store, $pid, $cfg);
check('دکمهٔ زرین‌پال هست', str_contains($kb, 'pay:method:zarin'));
check('دکمهٔ آقای پرداخت هست', str_contains($kb, 'pay:method:aqaye'));
$kbReview = PaymentPanel::reviewKb($pid, Payments::METHOD_ZARIN);
check('برای زرین‌پال «استعلام» می‌آید نه «تأیید»', str_contains($kbReview, 'payadmin:verify'));
check('برای زرین‌پال «رد» هم هست', str_contains($kbReview, 'payadmin:decline'));
$kbCard = PaymentPanel::reviewKb($pid, Payments::METHOD_CARD);
check('برای کارت «تأیید» می‌آید', str_contains($kbCard, 'payadmin:approve'));
$kbAdmin = PaymentPanel::adminKb($store);
foreach (['payadmin:fxrefresh', 'payadmin:fxauto', 'payadmin:zarin', 'payadmin:aqaye',
          'payadmin:text:zarin', 'payadmin:text:aqaye'] as $needle) {
    check("دکمهٔ «{$needle}» در پنل ادمین هست", str_contains($kbAdmin, $needle));
}
$adminText = PaymentPanel::adminText($store);
check('پنل ادمین زرین‌پال را نشان می‌دهد', str_contains($adminText, 'زرین'));
check('پنل ادمین آقای پرداخت را نشان می‌دهد', str_contains($adminText, 'آقای پرداخت'));
check('پنل ادمین نرخ دلار را نشان می‌دهد', str_contains($adminText, 'نرخ دلار'));

echo "\n--- describe پرداخت ---\n";
Payments::setMethod($store, $pid, Payments::METHOD_ZARIN, Payments::ST_AWAIT_PAY, 'A0000', 'https://payment.zarinpal.com/pg/StartPay/A0000');
$p = Payments::getPayment($store, $pid);
$desc = Payments::describe($p);
check('describe نام روش را دارد', str_contains($desc, 'زرین'), $desc);
check('describe HTML را escape می‌کند', !str_contains($desc, '<script'));

echo "\n--- FxRate (نرخ دلار) ---\n";
check('پیش‌فرضِ نرخ معتبر است', FxRate::stored($store) > 0);
check('حالت خودکار پیش‌فرض روشن است', FxRate::isAuto($store));
FxRate::setManual($store, 123456.0);
check('نرخ دستی ذخیره شد', FxRate::stored($store) === 123456.0);
check('نرخ دستی حالت خودکار را خاموش کرد', !FxRate::isAuto($store));
check('source دستی ثبت شد', str_contains(FxRate::source($store), 'دستی'));
FxRate::enableAuto($store);
check('enableAuto حالت خودکار را برگرداند', FxRate::isAuto($store));
$lines = FxRate::statusLines($store);
check('statusLines چند سطر دارد', count($lines) >= 3);
$net = getenv('SELFTEST_FX_LIVE') === '1';
if ($net) {
    $res = FxRate::fetch(10);
    check('گرفتن نرخ از سرویس‌ها موفق بود', !empty($res['ok']), (string)($res['error'] ?? ''));
    check('نرخ در بازهٔ معقول است', !empty($res['ok']) && $res['rate'] > 10000 && $res['rate'] < 500000000,
        !empty($res['rate']) ? (string)$res['rate'] : '-');
    check('منبع نرخ نام‌دار است', !empty($res['ok']) && $res['source'] !== '');
    if (!empty($res['ok'])) {
        FxRate::refresh($store, 10);
        $stored = FxRate::stored($store);
        // نرخِ بازار هر ثانیه تیک می‌خورد ⇒ دو درخواستِ پشت‌سرهم الزاماً یکی نیستند
        check('نرخ تازه ذخیره شد', $stored > 10000 && abs($stored - (float)$res['rate']) / (float)$res['rate'] < 0.05,
            'fetched=' . (string)$res['rate'] . ' stored=' . (string)$stored);
    }
} else {
    echo "SKIP  تست شبکهٔ نرخ دلار (با SELFTEST_FX_LIVE=1 اجرا می‌شود)\n";
}

echo "\n--- Nav ---\n";
check('برگشتِ ویرایش توکن ⇒ پنل ربات', Nav::backTarget('await_edit_bot_token')['kind'] === 'bot');
check('برگشتِ متن تعمیرات ⇒ تنظیمات', Nav::backTarget('await_maintenance_text')['kind'] === 'settings');
check('برگشتِ زرین‌پال ⇒ پنل پرداخت', Nav::backTarget('await_pay_zarin_merchant')['kind'] === 'payments');
check('برگشتِ آقای پرداخت ⇒ پنل پرداخت', Nav::backTarget('await_pay_aqaye_pin')['kind'] === 'payments');
$kbSettings = Nav::settingsPanelKb($store);
foreach (['set:maintenance', 'set:approval', 'set:mainttext', 'set:maintaineta'] as $needle) {
    check("دکمهٔ «{$needle}» در پنل تنظیمات هست", str_contains($kbSettings, $needle));
}
check('پنل تنظیمات با تعمیرات روشن «روشن» نشان می‌دهد', (function () use ($store) {
    BuildSettings::setMaintenance($store, true);
    $decoded = json_decode(Nav::settingsPanelKb($store), true);
    BuildSettings::setMaintenance($store, false);
    return isset($decoded['inline_keyboard'][0][0]['text'])
        && str_contains($decoded['inline_keyboard'][0][0]['text'], 'روشن — حالت تعمیرات');
})(), 'json_encode ایموجی/خط تیره را escape می‌کند ⇒ باید decode شود');
$kbBot = Nav::botPanelKb(['id' => 7, 'status' => 'active']);
check('دکمهٔ ویرایش توکن در پنل ربات هست', str_contains($kbBot, 'act:edittoken:7'));
check('دکمهٔ ویرایش آیدی ادمین در پنل ربات هست', str_contains($kbBot, 'act:editadmin:7'));
check('دکمهٔ آمار در پنل ربات هست', str_contains($kbBot, 'act:stats:7'));

echo "\n--- Store::updateBot ---\n";
$bid = $store->addBot([
    'owner_id' => 555001, 'type' => 'faxima', 'folder' => 'smokebot',
    'token' => 'enc', 'bot_username' => 'smokebot', 'admin_id' => 111, 'status' => 'active',
]);
$store->updateBot($bid, ['admin_id' => 222, 'bot_username' => 'newsmoke']);
$bot = $store->botById($bid);
check('آیدی ادمین به‌روز شد', (int)$bot['admin_id'] === 222);
check('یوزرنیم به‌روز شد', $bot['bot_username'] === 'newsmoke');
try {
    $store->updateBot($bid, ['drop_table' => 'x']);
    check('ستونِ ناشناخته رد می‌شود', false);
} catch (Throwable $e) {
    check('ستونِ ناشناخته رد می‌شود', true);
}

echo "\n--- Manager::updateChildIdentity ---\n";
$tmpBot = sys_get_temp_dir() . '/botsaz_feattest_' . getmypid();
@mkdir($tmpBot, 0755, true);
// فاکسیما: متغیر $APIKEY / $adminnumber / $usernamebot
file_put_contents($tmpBot . '/config.php', "<?php\n\$dbname='x';\n\$APIKEY = \"OLD:TOKENVALUE_1234567890\";\n\$adminnumber = \"111\";\n\$usernamebot = \"oldbot\";\n");
$r = Manager::updateChildIdentity('faxima', $tmpBot, 'NEW:TOKENVALUE_0987654321', 999, 'newbot');
$out = (string)file_get_contents($tmpBot . '/config.php');
check('فاکسیما: ۳ فیلد عوض شد', (int)$r['changed'] === 3, 'changed=' . (string)$r['changed']);
check('فاکسیما: توکن نو', str_contains($out, 'NEW:TOKENVALUE_0987654321'));
check('فاکسیما: ادمین نو', str_contains($out, '$adminnumber = "999"'));
check('فاکسیما: یوزرنیم نو', str_contains($out, '$usernamebot = "newbot"'));
check('فاکسیما: بکاپ ساخته شد', is_string($r['backup']) && is_file((string)$r['backup']));
// آپ‌تایم: کلیدهای آرایه‌ای
@unlink($tmpBot . '/config.php');
file_put_contents($tmpBot . '/config.php', "<?php\nreturn ['bot_token' => 'OLD:TOK_1234567890', 'admin_id' => '111', 'bot_username' => 'oldbot'];\n");
$r2 = Manager::updateChildIdentity('uptime', $tmpBot, 'NEW:TOK_0987654321', 888, 'newbot');
$out2 = (string)file_get_contents($tmpBot . '/config.php');
check('آپ‌تایم: ۳ فیلد عوض شد', (int)$r2['changed'] === 3);
check('آپ‌تایم: کلیدها نو', str_contains($out2, "'NEW:TOK_0987654321'") && str_contains($out2, "'888'") && str_contains($out2, "'newbot'"));
// پاسارگاد: super_admins آرایه است
@unlink($tmpBot . '/config.php');
file_put_contents($tmpBot . '/config.php', "<?php\nreturn ['bot_token' => 'OLD:TOK_1234567890', 'bot_username' => 'oldbot', 'super_admins' => [111]];\n");
$r3 = Manager::updateChildIdentity('pasargad', $tmpBot, 'NEW:TOK_0987654321', 777, 'newbot');
$out3 = (string)file_get_contents($tmpBot . '/config.php');
check('پاسارگاد: ۳ فیلد عوض شد', (int)$r3['changed'] === 3);
check('پاسارگاد: super_admins نو', str_contains($out3, "'super_admins' => [777]"), $out3);
// ناسازگاری ⇒ هیچ چیز نوشته نمی‌شود
@unlink($tmpBot . '/config.php');
file_put_contents($tmpBot . '/config.php', "<?php\n// فایلِ ناسازگار\n");
try {
    Manager::updateChildIdentity('faxima', $tmpBot, 'NEW:TOK_0987654321', 5, 'x');
    check('قالبِ ناسازگار ⇒ استثنا', false);
} catch (Throwable $e) {
    check('قالبِ ناسازگار ⇒ استثنا', true);
}
check('فایلِ ناسازگار دست‌نخورده ماند',
    trim((string)file_get_contents($tmpBot . '/config.php')) === '<?php' . "\n" . '// فایلِ ناسازگار');
@unlink($tmpBot . '/config.php');
foreach (glob($tmpBot . '/*') ?: [] as $g) @unlink($g);
@rmdir($tmpBot);

echo "\n--- ZarinPal / AqaPay (اعتبارسنجی، بدون شبکه) ---\n";
$store->setSetting('pay_zarin_merchant_id', '');
$store->setSetting('pay_aqaye_pin', '');
check('زرین‌پال: بدون merchant_id ناقص است', !PaymentZarin::isConfigured($store, $cfg));
check('آقای پرداخت: بدون pin ناقص است', !PaymentAqaye::isConfigured($store, $cfg));
PaymentZarin::setCredentials($store, '00000000-0000-0000-0000-000000000000', false);
PaymentAqaye::setPin($store, 'test-pin-123');
check('زرین‌پال: با merchant_id کامل است', PaymentZarin::isConfigured($store, $cfg));
check('زرین‌پال: verify با ورودی ناقص «ناموفق» می‌دهد', PaymentZarin::verify('', 0, '')['ok'] === false);
check('زرین‌پال: request با پارامتر ناقص «ناموفق» می‌دهد', PaymentZarin::request('', 0, '')['ok'] === false);
check('زرین‌پال: StartPay درست ساخته می‌شود',
    PaymentZarin::startPayUrl('A0000', false) === 'https://payment.zarinpal.com/pg/StartPay/A0000');
check('زرین‌پال: لینک sandbox', str_contains(PaymentZarin::startPayUrl('A0000', true), 'sandbox.zarinpal.com'));
check('آقای پرداخت: مبلغ زیر کف به کف می‌رسد', PaymentAqaye::clampAmount(500) === PaymentAqaye::MIN_TOMAN);
check('آقای پرداخت: مبلغ بالای سقف کلمه می‌شود', PaymentAqaye::clampAmount(999999999) === PaymentAqaye::MAX_TOMAN);
check('آقای پرداخت: مبلغ صفر null می‌دهد', PaymentAqaye::clampAmount(0) === null);
check('آقای پرداخت: لینک پرداخت درست است',
    PaymentAqaye::startPayUrl('T1', false) === 'https://panel.aqayepardakht.ir/startpay/T1');
check('آقای پرداخت: خطای -15 متن فارسی دارد', str_contains(PaymentAqaye::errorText('-15'), 'دامنه'));
check('آقای پرداخت: خطای 0 متن فارسی دارد', str_contains(PaymentAqaye::errorText('0'), 'انجام نشد'));
check('آقای پرداخت: verify با ورودی ناقص «ناموفق» می‌دهد', PaymentAqaye::verify('', 0, '')['ok'] === false);

echo "\n--- Pricing / نرخ مؤثر ---\n";
check('tomanPerUsd پیش‌فرض معتبر است', PaymentPricing::tomanPerUsd($store) > 0);
PaymentPricing::setTomanPerUsd($store, 100000.0);
check('نرخ دستی ۱۰۰٬۰۰۰ ثبت شد', PaymentPricing::tomanPerUsd($store) === 100000.0);
check('ثبت دستی ⇒ حالت خودکار خاموش', !FxRate::isAuto($store));
check('effectiveUsdRate نرخِ دستی را برمی‌گرداند (بدون شبکه)', PaymentPricing::effectiveUsdRate($store) === 100000.0);
PaymentPricing::setUsdRateAuto($store);
check('setUsdRateAuto حالت خودکار را روشن کرد', FxRate::isAuto($store));

// پاکسازی
@unlink($tmpDb);
echo "\n" . str_repeat('=', 55) . "\n";
echo $fail === 0 ? "FEATURES SELFTEST PASSED\n" : "FEATURES SELFTEST FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);