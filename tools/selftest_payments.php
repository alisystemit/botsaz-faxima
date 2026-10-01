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

// ===== پاک‌سازی =====
$store->getPdo()->exec("DELETE FROM payments");
$store->getPdo()->exec("DELETE FROM users WHERE user_id = " . (int)$uid);
@unlink($tmpDb);

echo ($fail === 0 ? "PAYMENT TESTS PASSED\n" : "PAYMENT TESTS FAILED: {$fail}\n");
exit($fail === 0 ? 0 : 1);
