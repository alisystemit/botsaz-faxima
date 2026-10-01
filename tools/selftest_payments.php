<?php
// تست انتهایی سیستم پرداخت روی یک دیتابیس sqlite موقت (به دیتابیس واقعی دست نمی‌زند)
// اجرا:  php tools/selftest_payments.php
$root = dirname(__DIR__);
require_once $root . '/src/Store.php';
require_once $root . '/src/Manager.php';
require_once $root . '/src/BotApi.php';
require_once $root . '/src/Nav.php';
require_once $root . '/src/Payment/Payments.php';
require_once $root . '/src/Payment/Gateways.php';
require_once $root . '/src/Payment/Limits.php';
require_once $root . '/src/Payment/Pricing.php';
require_once $root . '/src/Payment/CardToCard.php';
require_once $root . '/src/Payment/NowPayments.php';
require_once $root . '/src/Payment/AdminPanel.php';

$tmpDb = sys_get_temp_dir() . '/botsaz_pay_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$cfg = ['manager_db' => $tmpDb, 'db_host' => '127.0.0.1', 'db_user' => 'root', 'db_pass' => '', 'db_port' => 3306, 'base_url' => 'https://example.com/botsaz-faxima'];
$store = new Store($tmpDb, $cfg);

$fail = 0;
function check(string $name, bool $ok): void {
    global $fail;
    if (!$ok) $fail++;
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . "\n";
}

Payments::ensureSchema($store);

// کاربر عادی
$uid = 900001;
$store->user($uid, 'Pay', 'paytest');
$store->setAllowed($uid, 1);
$user = $store->user($uid);
$supers = [111111];

// ===== مسدودی صریح (bot_limit=0) نباید با خرید اسلات دور زده شود =====
$bl = 900010;
$store->user($bl, 'Blocked', 'blocked1');
Payments::setUserLimit($store, $bl, 0);
$blUser = $store->user($bl);
PaymentPricing::setTemplatePrice($store, 'faxima', 100000);
check('مسدود: canBuild=false', !PaymentLimits::canBuild($store, $blUser, $supers));
check('مسدود: isBlocked=true', PaymentLimits::isBlocked($store, $blUser, $supers));
check('مسدود: remaining=0', PaymentLimits::remaining($store, $blUser, $supers) === 0);
$blReq = Payments::requiredForBuild($store, $blUser, 'faxima', $supers);
check('مسدود: requiredForBuild پرچم blocked دارد', !empty($blReq['blocked']));
check('مسدود: مبلغ فاکتور صفر است (نه پول بی‌حاصل)', (int)$blReq['amount'] === 0);
check('مسدود: نیاز به اسلات ندارد', empty($blReq['need_limit']));
check('مسدود: نیاز به ووچر قالب ندارد', empty($blReq['need_template']));
check('مسدود: فروشگاه دکمهٔ خرید اسلات ندارد', !str_contains(PaymentPanel::limitShopKb($store, $blUser, $supers), 'pay:buy:limit'));
check('مسدود: متن فروشگاه هشدار مسدودی دارد', str_contains(PaymentPanel::limitShopText($store, $blUser, $supers), 'مسدود'));
// لایهٔ گارد: amount صفر یعنی gateBuildPayment فاکتور نمی‌سازد، پس
// کاربر مسدود هرگز به ازای خریدِ اسلات سقفش از ۰ بالا نمی‌رود.
check('مسدود: گیت ساخت فاکتور نمی‌سازد', (int)$blReq['amount'] === 0 && !empty($blReq['blocked']));
// (پیام مسدودی یک منبع حقیقت است و کیبورد فروشگاه هم اسلات را ندارد)
check('مسدود: پیام مسدودی خالی نیست', mb_strlen(PaymentLimits::blockedNotice()) > 20);
$blKb = PaymentPanel::limitShopKb($store, $blUser, $supers);
check('مسدود: کیبورد بازهم اسلات ندارد (پاک‌سازی کاربر)', !str_contains($blKb, 'pay:buy:limit'));
// ادمینِ مسدود-لیمیت همیشه معاف است
check('ادمین مسدود-lیمیت هم مسدود محسوب نمی‌شود', !PaymentLimits::isBlocked($store, $blUser, [$bl]));
// درگاه لیمیت خاموش ⇒ مسدودی بی‌معنی
PaymentGateways::setEnabled($store, PaymentGateways::LIMIT, false);
check('لیمیت خاموش ⇒ مسدودی اعمال نمی‌شود', !PaymentLimits::isBlocked($store, $blUser, $supers));
PaymentGateways::setEnabled($store, PaymentGateways::LIMIT, true);
PaymentPricing::setTemplatePrice($store, 'faxima', 0);
$store->getPdo()->exec("DELETE FROM users WHERE user_id = " . (int)$bl);
check('پاک‌سازی: کاربر مسدود حذف شد', (int)$store->getPdo()->query("SELECT COUNT(*) FROM users WHERE user_id={$bl}")->fetchColumn() === 0);

// ===== پیش‌فرض: همه فعال =====
check('پیش‌فرض limit فعال', PaymentGateways::isEnabled($store, PaymentGateways::LIMIT));
check('پیش‌فرض card فعال', PaymentGateways::isEnabled($store, PaymentGateways::CARD));
check('پیش‌فرض nowpay فعال', PaymentGateways::isEnabled($store, PaymentGateways::NOWPAY));
check('پیش‌فرض template فعال', PaymentGateways::isEnabled($store, PaymentGateways::TEMPLATE));
check('کلید نامعتبر نادیده گرفته می‌شود', !PaymentGateways::isEnabled($store, 'hackerspace'));

// ===== لیمیت =====
Payments::setUserLimit($store, $uid, 1);
check('canBuild با limit=1 و بدون ربات', PaymentLimits::canBuild($store, $user, $supers));
$store->addBot(['owner_id'=>$uid,'type'=>'faxima','folder'=>'paytest1','token'=>'1:a','bot_username'=>'b1','bot_id'=>1,'admin_id'=>$uid,'db_name'=>'d','db_table_prefix'=>'','webhook_url'=>'u','status'=>'active']);
check('canBuild با limit=1 و یک ربات = false', !PaymentLimits::canBuild($store, $user, $supers));
check('remaining = 0', PaymentLimits::remaining($store, $user, $supers) === 0);

$req = Payments::requiredForBuild($store, $user, 'faxima', $supers);
check('requiredForBuild نیاز به لیمیت دارد', !empty($req['need_limit']));
check('requiredForBuild مبلغ = قیمت اسلات', (int)$req['amount'] === PaymentPricing::limitUnitPrice($store));

// ===== خاموش‌کردن درگاه لیمیت ⇒ سقف اعمال نمی‌شود =====
PaymentGateways::setEnabled($store, PaymentGateways::LIMIT, false);
check('لیمیت خاموش ⇒ canBuild true', PaymentLimits::canBuild($store, $user, $supers));
$req = Payments::requiredForBuild($store, $user, 'faxima', $supers);
check('لیمیت خاموش ⇒ مبلغ صفر', (int)$req['amount'] === 0);
check('لیمیت خاموش ⇒ remaining نامحدود', PaymentLimits::remaining($store, $user, $supers) === PaymentLimits::UNLIMITED);
check('toggle برمی‌گرداند true', PaymentGateways::toggle($store, PaymentGateways::LIMIT) === true);
check('toggle دوباره false می‌دهد', PaymentGateways::toggle($store, PaymentGateways::LIMIT) === false);
PaymentGateways::setEnabled($store, PaymentGateways::LIMIT, true);
check('لیمیت روشن ⇒ canBuild دوباره false', !PaymentLimits::canBuild($store, $user, $supers));

// ===== متن دلخواه =====
PaymentGateways::setCustomText($store, PaymentGateways::LIMIT, 'هزینهٔ شما ‹amount› برای ‹slots› اسلات است.');
$note = PaymentGateways::note($store, PaymentGateways::LIMIT, ['amount' => '۵۰٬۰۰۰ تومان', 'slots' => '۳']);
check('متن دلخواه جایگزینی می‌شود', str_contains($note, '۵۰٬۰۰۰ تومان') && str_contains($note, '۳ اسلات'));
check('نشانهٔ باقی‌مانده ندارد', !str_contains($note, '‹'));
PaymentGateways::resetText($store, PaymentGateways::LIMIT);
check('reset متن ⇒ پیش‌فرض برمی‌گردد', str_contains(PaymentGateways::note($store, PaymentGateways::LIMIT), 'اسلات'));

// ===== قالب پولی =====
PaymentPricing::setTemplatePrice($store, 'faxima', 120000);
check('قالب پولی است', PaymentPricing::isPaid($store, 'faxima'));
PaymentGateways::setEnabled($store, PaymentGateways::TEMPLATE, false);
check('قالب غیرفعال ⇒ رایگان', !PaymentPricing::isPaid($store, 'faxima'));
PaymentGateways::setEnabled($store, PaymentGateways::TEMPLATE, true);

// ===== گیت ترکیبی =====
$req = Payments::requiredForBuild($store, $user, 'faxima', $supers);
check('گیت: هم لیمیت هم قالب', !empty($req['need_limit']) && !empty($req['need_template']));
check('گیت: مبلغ = قیمت قالب + قیمت اسلات', (int)$req['amount'] === 120000 + PaymentPricing::limitUnitPrice($store));
$store->deleteBot((int)$store->botByFolder('paytest1')['id']);
check('بعد از حذف ربات فقط قالب لازم است', (function () use ($store, $user, $supers, $req) {
    $r = Payments::requiredForBuild($store, $user, 'faxima', $supers);
    return empty($r['need_limit']) && !empty($r['need_template']);
})());

// ===== چرخهٔ کارت‌به‌کارت =====
PaymentCard::setCard($store, '6037-9975-1234-5678', 'علی رضایی');
check('کارت ثبت شد', PaymentCard::isConfigured($store));
$methods = PaymentGateways::availableMethods($store, $cfg);
check('کارت در روش‌های فعال هست', in_array(Payments::METHOD_CARD, $methods, true));
check('nowpay هنوز تنظیم نشده ⇒ در لیست نیست', !in_array(Payments::METHOD_NOWPAY, $methods, true));

PaymentNowPay::setCredentials($store, 'TEST_API_KEY', 'TEST_IPN_SECRET');
check('nowpay در روش‌های فعال اضافه شد', in_array(Payments::METHOD_NOWPAY, PaymentGateways::availableMethods($store, $cfg), true));
PaymentGateways::setEnabled($store, PaymentGateways::NOWPAY, false);
check('nowpay خاموش ⇒ از روش‌ها حذف شد', !in_array(Payments::METHOD_NOWPAY, PaymentGateways::availableMethods($store, $cfg), true));
PaymentGateways::setEnabled($store, PaymentGateways::CARD, false);
check('کارت خاموش ⇒ روشی نماند', PaymentGateways::availableMethods($store, $cfg) === []);
check('noMethodText خالی نیست', trim(PaymentPanel::noMethodText($store)) !== '');
PaymentGateways::setEnabled($store, PaymentGateways::CARD, true);
PaymentGateways::setEnabled($store, PaymentGateways::NOWPAY, true);

// فاکتور کارت
Payments::setUserLimit($store, $uid, 0);
$pid = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 2, 100000, '');
check('پرداخت ساخته شد', $pid > 0);
check('وضعیت اولیه pending', Payments::getPayment($store, $pid)['status'] === Payments::ST_PENDING);
Payments::setMethod($store, $pid, Payments::METHOD_CARD, Payments::ST_AWAIT_RECEIPT);
Payments::setReceipt($store, $pid, json_encode(['text' => '123456789', 'has_attachment' => false]));
check('پس از رسید ⇒ await_admin', Payments::getPayment($store, $pid)['status'] === Payments::ST_AWAIT_ADMIN);
check('در فهرست ادمین هست', count(Payments::pendingAdminList($store, 10)) >= 1);
$approved = Payments::approveByAdmin($store, $pid);
check('تأیید ادمین کار کرد', $approved !== null);
check('لیمیت اعمال شد (0+2)', Payments::getUserLimit($store, $uid) === 2);
check('دوباره تأیید ⇒ null (idempotent)', Payments::approveByAdmin($store, $pid) === null);
check('متن اعلان اثر شامل سقف است', str_contains((string)($approved['grant_note'] ?? ''), 'سقف'));

// ووچر قالب
$pid2 = Payments::createPayment($store, $uid, Payments::KIND_TEMPLATE, 'faxima', 0, 120000, '');
Payments::setMethod($store, $pid2, Payments::METHOD_CARD, Payments::ST_AWAIT_RECEIPT);
Payments::approveByAdmin($store, $pid2);
check('ووچر قالب صادر شد', Payments::countUsableTemplateVoucher($store, $uid, 'faxima') === 1);
check('ووچر قالب ⇒ نیاز به پرداخت نیست', (function () use ($store, $user, $supers) {
    $r = Payments::requiredForBuild($store, $user, 'faxima', $supers);
    return empty($r['need_template']);
})());
check('consumeTemplateVoucher true', Payments::consumeTemplateVoucher($store, $uid, 'faxima') === true);
check('بعد از مصرف ووچری نمی‌ماند', Payments::countUsableTemplateVoucher($store, $uid, 'faxima') === 0);
check('consume دوباره false', Payments::consumeTemplateVoucher($store, $uid, 'faxima') === false);

// ===== کریپتو (IPN) =====
$pid3 = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 1, 50000, '');
Payments::setMethod($store, $pid3, Payments::METHOD_NOWPAY, Payments::ST_AWAIT_PAY, 'inv-123', 'https://nowpayments.io/pay/123');
$body = json_encode(['payment_id' => 999, 'order_id' => "PAY-{$pid3}", 'payment_status' => 'finished']);
$sig = hash_hmac('sha512', $body, 'TEST_IPN_SECRET');
check('امضای IPN معتبر است', PaymentNowPay::verifyIpn($body, $sig, 'TEST_IPN_SECRET'));
check('امضای غلط رد می‌شود', !PaymentNowPay::verifyIpn($body, 'deadbeef', 'TEST_IPN_SECRET'));
check('order_id استخراج شد', PaymentNowPay::extractOrderId(json_decode($body, true)) === "PAY-{$pid3}");
check('finished یعنی پرداخت‌شده', PaymentNowPay::isPaidStatus('finished'));
check('waiting پرداخت‌شده نیست', !PaymentNowPay::isPaidStatus('waiting'));
check('پرداخت با order_id پیدا شد', Payments::getPaymentByOrderId($store, "PAY-{$pid3}")['id'] === $pid3);
check('پرداخت با ext_id پیدا شد', Payments::getPaymentByExtId($store, 'inv-123')['id'] === $pid3);
$before = Payments::getUserLimit($store, $uid);
Payments::markCryptoPaid($store, $pid3);
check('IPN ⇒ paid', Payments::getPayment($store, $pid3)['status'] === Payments::ST_PAID);
check('IPN ⇒ grant اعمال شد', Payments::getUserLimit($store, $uid) === $before + 1);
Payments::markCryptoPaid($store, $pid3);
check('IPN تکراری ⇒ grant دوباره نمی‌دهد', Payments::getUserLimit($store, $uid) === $before + 1);

// ===== پنل ادمین =====
$kb = PaymentPanel::adminKb($store);
foreach (['payadmin:toggle:limit', 'payadmin:toggle:template', 'payadmin:toggle:card', 'payadmin:toggle:nowpay', 'payadmin:list', 'payadmin:text:limit', 'payadmin:setlimit'] as $needle) {
    check("کیبورد ادمین شامل {$needle}", str_contains($kb, $needle));
}
$shopKb = PaymentPanel::limitShopKb($store);
check('کیبورد فروشگاه شامل خرید اسلات', str_contains($shopKb, 'pay:buy:limit:1'));
check('کیبورد فروشگاه شامل خرید قالب', str_contains($shopKb, 'pay:buy:template:faxima'));
check('کیبورد فروشگاه دکمهٔ برگشت دارد', str_contains($shopKb, Nav::CB_BACK_MAIN));
check('متن فروشگاه ساخته شد', str_contains(PaymentPanel::limitShopText($store, $user, $supers), 'افزایش لیمیت'));
check('متن پرداخت‌های من ساخته شد', str_contains(PaymentPanel::myPaymentsText($store, $uid), 'پرداخت'));

// Nav: برگشت مرحله‌های پرداخت
check('Nav برگشت رسید ⇒ فروشگاه', Nav::backTarget('await_card_receipt')['kind'] === 'shop');
check('Nav برگشت تنظیم کارت ⇒ پنل پرداخت', Nav::backTarget('await_pay_card')['kind'] === 'payments');
check('Nav برگشت متن ⇒ پنل پرداخت', Nav::backTarget('await_pay_text')['kind'] === 'payments');

// ===== کیبورد فاکتور کریپتو (پشتیبانِ نبودِ IPN) =====
// json_encode به‌صورت پیش‌فرض «/» را به «\/» تبدیل می‌کند؛ برای مقایسه یکسان‌سازی می‌کنیم
$invKb = str_replace('\\/', '/', PaymentPanel::invoiceKb(7, 'https://nowpayments.io/pay/abc'));
check('invoiceKb دکمهٔ لینک پرداخت دارد', str_contains($invKb, 'https://nowpayments.io/pay/abc'));
check('invoiceKb دکمهٔ بررسی وضعیت دارد', str_contains($invKb, 'pay:check:7'));
check('invoiceKb دکمهٔ پرداخت‌های من دارد', str_contains($invKb, 'pay:mine'));
check('invoiceKb بدون لینک ⇒ بدون دکمهٔ url', !str_contains(PaymentPanel::invoiceKb(7), '"url"'));
check('invoiceKb بدون لینک ⇒ باز هم بررسی وضعیت دارد', str_contains(PaymentPanel::invoiceKb(7), 'pay:check:7'));

// ===== کریپتو: idempotent بودن markCryptoPaid =====
$cPid = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 1, 1000, '');
PaymentNowPay::setCredentials($store, 'testkey', 'testsecret');
Payments::setMethod($store, $cPid, Payments::METHOD_NOWPAY, Payments::ST_AWAIT_PAY, 'inv-123', 'https://nowpayments.io/pay/inv-123');
$done1 = Payments::markCryptoPaid($store, $cPid);
check('کریپتو: پرداخت تأیید و اعمال شد', $done1 !== null && $done1['status'] === Payments::ST_PAID);
$limitAfterFirst = PaymentLimits::getLimit($store, $store->user($uid), $supers);
$done2 = Payments::markCryptoPaid($store, $cPid);
$limitAfterSecond = PaymentLimits::getLimit($store, $store->user($uid), $supers);
check('کریپتو: فراخوانی دوباره idempotent است', $done2 !== null && $done2['status'] === Payments::ST_PAID);
check('کریپتو: لیمیت دوباره اضافه نشد', $limitAfterFirst === $limitAfterSecond);
check('کریپتو: لینک پرداخت ذخیره شد', (string)Payments::getPayment($store, $cPid)['pay_url'] === 'https://nowpayments.io/pay/inv-123');
check('nowpay: isPaidStatus فقط finished/confirmed', PaymentNowPay::isPaidStatus('finished') && PaymentNowPay::isPaidStatus('confirmed') && !PaymentNowPay::isPaidStatus('waiting') && !PaymentNowPay::isPaidStatus('failed'));
$sig = hash_hmac('sha512', '{"payment_status":"finished"}', 'testsecret');
check('nowpay: verifyIpn امضای درست را می‌پذیرد', PaymentNowPay::verifyIpn('{"payment_status":"finished"}', $sig, 'testsecret'));
check('nowpay: verifyIpn بدنهٔ دستکاری‌شده را رد می‌کند', !PaymentNowPay::verifyIpn('{"payment_status":"finished","x":1}', $sig, 'testsecret'));
check('nowpay: verifyIpn با secret غلط رد می‌کند', !PaymentNowPay::verifyIpn('{"payment_status":"finished"}', $sig, 'other'));
// امنیت: با secret خالی، HMAC با کلید تهی قابل حدس است ⇒ باید همیشه رد کند
$emptySig = hash_hmac('sha512', '{"payment_status":"finished"}', '');
check('nowpay: verifyIpn با secret خالی رد می‌کند (نه قابل جعل)', !PaymentNowPay::verifyIpn('{"payment_status":"finished"}', $emptySig, ''));
check('nowpay: verifyIpn با secret فقط-فاصله رد می‌کند', !PaymentNowPay::verifyIpn('{"payment_status":"finished"}', $emptySig, '   '));
check('nowpay: verifyIpn با sig خالی رد می‌کند', !PaymentNowPay::verifyIpn('{"payment_status":"finished"}', '', 'testsecret'));

// ---- R1: pendingAdminCount باید دقیقاً برابر شمار واقعی ردیف‌های صف باشد ----
$countProbe = 900020;
$store->user($countProbe, 'Cnt', 'cnt1');
$cA = Payments::createPayment($store, $countProbe, Payments::KIND_LIMIT, '', 0, 1000);
$cB = Payments::createPayment($store, $countProbe, Payments::KIND_LIMIT, '', 0, 1000);
Payments::setMethod($store, $cA, Payments::METHOD_CARD, Payments::ST_AWAIT_RECEIPT);
Payments::setMethod($store, $cB, Payments::METHOD_CARD, Payments::ST_AWAIT_ADMIN);
$sqlCount = (int)$store->getPdo()
    ->query("SELECT COUNT(*) FROM payments WHERE status IN ('await_admin','await_receipt','await_pay')")
    ->fetchColumn();
check('pendingAdminCount دقیقاً برابر COUNT(*) واقعی است', Payments::pendingAdminCount($store) === $sqlCount);
check('pendingAdminCount فاکتورهای await_receipt/await_admin را می‌شمارد', Payments::pendingAdminCount($store) >= 2);
check('pendingAdminCount فاکتور pending را نمی‌شمارد', (function () use ($store, $countProbe, $sqlCount) {
    $loose = Payments::createPayment($store, $countProbe, Payments::KIND_LIMIT, '', 0, 1000);
    return Payments::pendingAdminCount($store) === $sqlCount;
})());
Payments::approveByAdmin($store, $cA, $countProbe);
Payments::approveByAdmin($store, $cB, $countProbe);
$leftIds = array_column(Payments::pendingAdminList($store, 50), 'id');
check('بعد از تأیید، فاکتورها از صف ادمین حذف می‌شوند',
    !in_array($cA, $leftIds, true) && !in_array($cB, $leftIds, true));
check('pendingAdminCount با صف خالی صفر است', Payments::pendingAdminCount($store) === 0);

// ==========================================================================
// ===== تست‌های بازگشتی رفع باگ‌های بازبینی =====
// ==========================================================================

// ---- R2: نرمال‌سازی ارقام فارسی/عربی در ورودی‌های عددی ادمین ----
check('digitsOnly ارقام فارسی را می‌پذیرد', Payments::digitsOnly('۵۰۰۰۰') === '50000');
check('digitsOnly جداکنندهٔ فارسی را حذف می‌کند', Payments::digitsOnly('۱۰۰٬۰۰۰') === '100000');
check('digitsOnly ارقام عربی را می‌پذیرد', Payments::digitsOnly('٢٥٠٠٠') === '25000');
check('normalizeDigits علامت منفی فارسی را می‌پذیرد', Payments::normalizeDigits('−۱') === '-1');
check('parseIntLoose منفی را برمی‌گرداند', Payments::parseIntLoose('مقدار -۳ عدد') === -3);
check('parseIntLoose روی متن بدون عدد null می‌دهد', Payments::parseIntLoose('سلام') === null);

// ---- R6: گِرد کردن رو به بالا + کف حداقل NOWPayments ----
check('tomanToUsd رو به بالا گِرد می‌کند', PaymentPricing::tomanToUsd(50000, 100000) === 0.5);
check('tomanToUsd مبلغ کوچک را زیر کف سرویس نمی‌برد', PaymentPricing::tomanToUsd(5000, 100000) === PaymentPricing::NOWPAY_MIN_USD);
check('tomanToUsd مبلغ صفر ⇒ صفر', PaymentPricing::tomanToUsd(0, 100000) === 0.0);
check('tomanToUsd فروشنده را زیر قیمت نمی‌برد', PaymentPricing::tomanToUsd(199999, 100000) === 2.0);
check('toNumber جداکنندهٔ هزارگان را می‌پذیرد', Payments::toNumber('۱۰۰٬۰۰۰') === 100000.0);
check('toNumber اعشار را می‌پذیرد', Payments::toNumber('100,000.5') === 100000.5);

// ---- R6/R2: قیمت‌ها با ارقام فارسی در settings هم درست خوانده می‌شوند ----
PaymentPricing::setLimitUnitPrice($store, 75000);
$store->setSetting('pay_limit_price', '۹۰٬۰۰۰');
check('قیمت اسلات با ارقام فارسی در settings خوانده می‌شود', PaymentPricing::limitUnitPrice($store) === 90000);
PaymentPricing::setLimitUnitPrice($store, 50000);
$store->setSetting('pay_toman_per_usd', '۱۰۰٬۰۰۰');
check('نرخ دلار با ارقام فارسی خوانده می‌شود', PaymentPricing::tomanPerUsd($store) === 100000.0);
PaymentPricing::setTomanPerUsd($store, 100000.0);

// ---- R5: قیمت صفر ⇒ دکمهٔ خرید اسلات نمایش داده نمی‌شود ----
$kb = PaymentPanel::limitShopKb($store);
check('قیمت اسلات > 0 ⇒ دکمهٔ خرید اسلات هست', str_contains($kb, 'pay:buy:limit:1'));
PaymentPricing::setLimitUnitPrice($store, 0);
$kb = PaymentPanel::limitShopKb($store);
check('قیمت اسلات = 0 ⇒ دکمهٔ خرید اسلات حذف می‌شود', !str_contains($kb, 'pay:buy:limit'));
check('قیمت اسلات = 0 ⇒ دکمهٔ برگشت می‌ماند', str_contains($kb, Nav::CB_BACK_MAIN));
PaymentPricing::setLimitUnitPrice($store, 50000);

// ---- C8: isConfigured باید هر دو کلید را بخواهد ----
$savedKey = (string)($store->getSetting('pay_nowpay_api_key', '') ?? '');
$savedSecret = (string)($store->getSetting('pay_nowpay_ipn_secret', '') ?? '');
$store->setSetting('pay_nowpay_ipn_secret', '');
check('nowpay: فقط api_key ⇒ پیکربندی ناقص', !PaymentNowPay::isConfigured($store, $cfg));
check('nowpay: فقط api_key ⇒ از روش‌های فعال حذف می‌شود', !in_array(Payments::METHOD_NOWPAY, PaymentGateways::availableMethods($store, $cfg), true));
$store->setSetting('pay_nowpay_api_key', '');
$store->setSetting('pay_nowpay_ipn_secret', 'testsecret');
check('nowpay: فقط ipn_secret ⇒ پیکربندی ناقص', !PaymentNowPay::isConfigured($store, $cfg));
check('noMethodText کمبود API key را دقیق می‌گوید', str_contains(PaymentPanel::noMethodText($store), 'کلید API') && !str_contains(PaymentPanel::noMethodText($store), 'IPN'));
$store->setSetting('pay_nowpay_api_key', 'key-only');
$store->setSetting('pay_nowpay_ipn_secret', '');
check('noMethodText کمبود IPN Secret را دقیق می‌گوید', str_contains(PaymentPanel::noMethodText($store), 'IPN') && !str_contains(PaymentPanel::noMethodText($store), 'کلید API'));
$store->setSetting('pay_nowpay_api_key', $savedKey);
$store->setSetting('pay_nowpay_ipn_secret', $savedSecret);
check('nowpay: هر دو کلید ⇒ پیکربندی کامل', PaymentNowPay::isConfigured($store, $cfg));

// ---- C6: approveByAdmin نباید فاکتور رهاشدهٔ pending را تأیید کند ----
$abandoned = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 5, 250000, '');
$beforeLimit = Payments::getUserLimit($store, $uid);
check('approveByAdmin روی pending ⇒ null', Payments::approveByAdmin($store, $abandoned) === null);
check('approveByAdmin روی pending ⇒ لیمیت دست‌نخورده', Payments::getUserLimit($store, $uid) === $beforeLimit);
check('pending در صف بررسی ادمین نیست', !in_array($abandoned, array_column(Payments::pendingAdminList($store, 50), 'id'), true));

// ---- R11: setReceipt نباید پرداخت لغوشده را احیا کند ----
$cancelMe = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 1, 50000, Payments::METHOD_CARD);
Payments::setMethod($store, $cancelMe, Payments::METHOD_CARD, Payments::ST_AWAIT_RECEIPT);
Payments::setStatus($store, $cancelMe, Payments::ST_CANCELLED);
check('setReceipt روی پرداخت لغوشده false می‌دهد', Payments::setReceipt($store, $cancelMe, '{"text":"x"}') === false);
check('پرداخت لغوشده احیا نشد', Payments::getPayment($store, $cancelMe)['status'] === Payments::ST_CANCELLED);

// ---- R12: سقف فاکتور باز ----
$spammer = 900002;
$store->user($spammer, 'Spam', 'spam');
Payments::setUserLimit($store, $spammer, 0);
for ($i = 0; $i < Payments::MAX_OPEN_PAYMENTS; $i++) {
    Payments::createPayment($store, $spammer, Payments::KIND_LIMIT, '', 1, 50000, '');
}
check('openPaymentCount سقف را می‌شمارد', Payments::openPaymentCount($store, $spammer) === Payments::MAX_OPEN_PAYMENTS);
$store->getPdo()->exec("DELETE FROM payments WHERE user_id = " . (int)$spammer);
check('بعد از پاک‌سازی شمار صفر شد', Payments::openPaymentCount($store, $spammer) === 0);
$store->getPdo()->exec("DELETE FROM users WHERE user_id = " . (int)$spammer);

// ---- R4: صف بررسی ادمین شامل فاکتور کریپتوییِ باز است ولی pending نه ----
$cryptoOpen = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 1, 50000, Payments::METHOD_NOWPAY);
Payments::setMethod($store, $cryptoOpen, Payments::METHOD_NOWPAY, Payments::ST_AWAIT_PAY, 'inv-cr', 'https://nowpayments.io/pay/inv-cr');
$adminIds = array_column(Payments::pendingAdminList($store, 50), 'id');
check('فاکتور کریپتویی باز در صف ادمین هست', in_array($cryptoOpen, $adminIds, true));
check('reviewKb کریپتویی دکمهٔ استعلام می‌دهد', str_contains(PaymentPanel::reviewKb($cryptoOpen, Payments::METHOD_NOWPAY), 'payadmin:verify:' . $cryptoOpen));
check('reviewKb کریپتویی دکمهٔ تأیید ندارد', !str_contains(PaymentPanel::reviewKb($cryptoOpen, Payments::METHOD_NOWPAY), 'payadmin:approve:'));
check('reviewKb کارتی دکمهٔ تأیید دارد', str_contains(PaymentPanel::reviewKb($cryptoOpen, Payments::METHOD_CARD), 'payadmin:approve:'));
Payments::setStatus($store, $cryptoOpen, Payments::ST_CANCELLED);

// ---- C4: فاکتور کریپتوییِ باز قابل لغو است (و بعد از لغو دیگر پذیرفته نمی‌شود) ----
$canCancel = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 2, 100000, Payments::METHOD_NOWPAY);
Payments::setMethod($store, $canCancel, Payments::METHOD_NOWPAY, Payments::ST_AWAIT_PAY, 'inv-x', 'https://nowpayments.io/pay/inv-x');
$limitBeforeCancel = Payments::getUserLimit($store, $uid);
Payments::setStatus($store, $canCancel, Payments::ST_CANCELLED);
check('فاکتور لغوشده دیگر پرداخت‌شده نمی‌شود', Payments::markCryptoPaid($store, $canCancel) === null);
check('فاکتور لغوشده لیمیت اضافه نمی‌کند', Payments::getUserLimit($store, $uid) === $limitBeforeCancel);

// ---- C3: افزایش لیمیت اتمیک است و روی «نامحدود» اثر ندارد ----
$atomic = 900003;
$store->user($atomic, 'Atom', 'atom');
Payments::setUserLimit($store, $atomic, 5);
Payments::addUserLimit($store, $atomic, 3);
Payments::addUserLimit($store, $atomic, 4);
check('دو افزایش پشت‌سرهم جمع می‌شوند (نه گم‌شدن)', Payments::getUserLimit($store, $atomic) === 12);
Payments::setUserLimit($store, $atomic, PaymentLimits::UNLIMITED);
Payments::addUserLimit($store, $atomic, 5);
check('افزایش روی سقف نامحدود بی‌اثر است', Payments::getUserLimit($store, $atomic) === PaymentLimits::UNLIMITED);
Payments::setUserLimit($store, $atomic, 1);
Payments::addUserLimit($store, $atomic, -10);
check('کاهش زیر صفر، صفر می‌شود (نه منفی)', Payments::getUserLimit($store, $atomic) === 0);
$store->getPdo()->exec("DELETE FROM users WHERE user_id = " . (int)$atomic);

// ---- C2: دو تأیید پشت‌سرهم ⇒ یک بار grant ----
$double = Payments::createPayment($store, $uid, Payments::KIND_LIMIT, '', 3, 150000, Payments::METHOD_CARD);
Payments::setMethod($store, $double, Payments::METHOD_CARD, Payments::ST_AWAIT_ADMIN);
Payments::setUserLimit($store, $uid, 1);
$r1 = Payments::approveByAdmin($store, $double);
$r2 = Payments::approveByAdmin($store, $double);
check('تأیید اول ⇒ paid', $r1 !== null && $r1['status'] === Payments::ST_PAID);
check('تأیید دوم ⇒ null', $r2 === null);
check('تأیید دوم ⇒ لیمیت فقط یک بار اضافه شد', Payments::getUserLimit($store, $uid) === 4);

// ---- C10: ووچر فقط وقتی قالب پولی است مصرف می‌شود ----
PaymentPricing::setTemplatePrice($store, 'faxima', 100000);
$voucherUid = 900004;
$store->user($voucherUid, 'Vouch', 'vouch');
$vp = Payments::createPayment($store, $voucherUid, Payments::KIND_TEMPLATE, 'faxima', 0, 100000, Payments::METHOD_CARD);
Payments::setMethod($store, $vp, Payments::METHOD_CARD, Payments::ST_AWAIT_RECEIPT);
check('ووچر خریداری‌شده قابل تأیید است', Payments::approveByAdmin($store, $vp) !== null);
check('ووچر خریداری‌شده قابل مصرف است', Payments::countUsableTemplateVoucher($store, $voucherUid, 'faxima') === 1);
check('مصرف ووچر true', Payments::consumeTemplateVoucher($store, $voucherUid, 'faxima') === true);
check('بعد از مصرف ووچری نمی‌ماند', Payments::countUsableTemplateVoucher($store, $voucherUid, 'faxima') === 0);
PaymentPricing::setTemplatePrice($store, 'faxima', 0);

// ---- R1: کش اسکیما ----
Payments::resetSchemaCache();
$before = 0;
Payments::ensureSchema($store);
Payments::ensureSchema($store);
check('ensureSchema دوبار خطا نمی‌دهد', true); // اگر خطا می‌داد، تست این‌جا تمام می‌شد
unset($before);

// ---- R10: کیبورد «پرداخت‌های من» دکمهٔ بررسی وضعیت دارد وقتی فاکتور کریپتویی باز است ----
$openCryptoUid = 900005;
$store->user($openCryptoUid, 'Open', 'openp');
$oc = Payments::createPayment($store, $openCryptoUid, Payments::KIND_LIMIT, '', 1, 50000, Payments::METHOD_NOWPAY);
Payments::setMethod($store, $oc, Payments::METHOD_NOWPAY, Payments::ST_AWAIT_PAY, 'inv-999', 'https://nowpayments.io/pay/inv-999');
$mkb = PaymentPanel::myPaymentsKb($store, $openCryptoUid);
check('پرداخت‌های من ⇒ دکمهٔ بررسی وضعیت فاکتور کریپتویی', str_contains($mkb, 'pay:check:' . $oc));
check('متن پرداخت‌های من به بررسی وضعیت اشاره می‌کند', str_contains(PaymentPanel::myPaymentsText($store, $openCryptoUid), 'بررسی وضعیت'));
Payments::setStatus($store, $oc, Payments::ST_CANCELLED);
$mkb = PaymentPanel::myPaymentsKb($store, $openCryptoUid);
check('بعد از بسته‌شدن فاکتور ⇒ بدون دکمهٔ بررسی', !str_contains($mkb, 'pay:check:'));
$store->getPdo()->exec("DELETE FROM payments WHERE user_id = " . (int)$openCryptoUid);
$store->getPdo()->exec("DELETE FROM users WHERE user_id = " . (int)$openCryptoUid);

// ---- setExtId: جایگزینی شناسهٔ بیرونی ----
$extUid = 900006;
$store->user($extUid, 'Ext', 'extu');
$ep = Payments::createPayment($store, $extUid, Payments::KIND_LIMIT, '', 1, 50000, Payments::METHOD_NOWPAY);
Payments::setExtId($store, $ep, 'pay-777');
check('setExtId شناسه را ثبت می‌کند', (string)Payments::getPaymentByExtId($store, 'pay-777')['id'] === (string)$ep);
$store->getPdo()->exec("DELETE FROM payments WHERE user_id = " . (int)$extUid);
$store->getPdo()->exec("DELETE FROM users WHERE user_id = " . (int)$extUid);

// ---- describe خروجی HTML-امن دارد ----
$evil = Payments::describe(['id' => 1, 'kind' => 'template', 'template' => '<b>x', 'slots' => 1, 'amount' => 1000, 'status' => 'paid']);
check('describe نام قالب را escape می‌کند', !str_contains($evil, '<b>x'));
check('describe متن فارسی وضعیت را نگه می‌دارد', str_contains($evil, 'پرداخت‌شده'));
check('describe وضعیت ناشناخته را نشان می‌دهد', str_contains(Payments::describe(['id' => 2, 'status' => 'zzz']), 'zzz'));

// ===== پاک‌سازی =====
$store->getPdo()->exec("DELETE FROM payments");
$store->getPdo()->exec("DELETE FROM users WHERE user_id = " . (int)$uid);
@unlink($tmpDb);

echo ($fail === 0 ? "PAYMENT TESTS PASSED\n" : "PAYMENT TESTS FAILED: {$fail}\n");
exit($fail === 0 ? 0 : 1);
