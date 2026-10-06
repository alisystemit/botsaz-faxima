<?php
// ===== پنل و کیبوردهای پرداخت (متن‌ها و دکمه‌ها) =====
// فقط ساختار پیام/دکمه اینجاست؛ منطق در Payments/Limits/Pricing/Gateways است.
// هر متن و دکمه از PaymentGateways می‌خواند تا فعال/غیرفعال‌سازی و متن دلخواه
// بدون دست‌زدن به این فایل اعمال شود.

// وابستگی‌ها: این فایل پیام‌ها را می‌سازد پس به لایهٔ زیباسازیِ متن و ماژولِ
// نرخ دلار نیاز دارد. اینجا require می‌شوند تا هر نقطهٔ ورودی (bot.php،
// endpointهای پرداخت، تست‌ها) با لودِ همین فایل کار کند.
require_once __DIR__ . '/../Ui.php';
require_once __DIR__ . '/../FxRate.php';
require_once __DIR__ . '/ZarinPal.php';
require_once __DIR__ . '/AqaPay.php';

class PaymentPanel
{
    // ---- کاربر ----

    public static function limitShopText(Store $store, array $user, array $supers = []): string
    {
        $uid = (int)($user['user_id'] ?? 0);
        $limitOn = PaymentGateways::isEnabled($store, PaymentGateways::LIMIT);
        $tplOn = PaymentGateways::isEnabled($store, PaymentGateways::TEMPLATE);
        $limit = PaymentLimits::getLimit($store, $user, $supers);
        $count = PaymentLimits::botCount($store, $uid);
        $unit = PaymentPricing::limitUnitPrice($store);
        $rem = PaymentLimits::remaining($store, $user, $supers);

        $t = "💳 <b>فروشگاه ساخت ربات</b>\n\n";
        $t .= Ui::kv('🎯', 'سقف ساخت شما', PaymentLimits::formatLimit($limit));
        if ($limit >= 0) $t .= "\n" . Ui::kv('🤖', 'ربات‌های ساخته‌شده', (string)$count);
        $t .= "\n" . Ui::kv('➕', 'قیمت هر اسلات', PaymentPricing::formatToman($unit)) . "\n\n";

        // مسدودی اول از همه گفته شود؛ وگرنه کاربر فکر می‌کند با خرید اسلات باز می‌شود
        if (PaymentLimits::isBlocked($store, $user, $supers)) {
            $t .= "⛔️ " . PaymentLimits::blockedNotice() . "\n\n";
        }

        $vars = [
            'amount' => PaymentPricing::formatToman($unit),
            'slots' => '۱',
            'count' => (string)$count,
            'limit' => PaymentLimits::formatLimit($limit),
            'remaining' => $rem < 0 ? 'نامحدود' : (string)$rem,
        ];

        // متن دلخواه ادمین برای لیمیت
        $note = PaymentGateways::note($store, PaymentGateways::LIMIT, $vars);
        if ($note !== '') $t .= $note . "\n\n";

        $t .= "🧩 <b>قیمت قالب‌ها</b>\n";
        // وضعیت هر قالب (فقط اگر درگاه قالب فعال باشد قیمت‌ها معنا دارد)
        foreach (Manager::validTypes() as $type => $label) {
            $p = PaymentPricing::templatePrice($store, $type);
            if (!$tplOn || $p <= 0) {
                $t .= Ui::bullet('🆓', Ui::e($label) . ": رایگان");
            } else {
                $t .= Ui::bullet('💰', Ui::e($label) . ": <b>" . number_format($p) . " تومان</b>");
            }
        }
        $t .= "\n";

        if (!$limitOn && !$tplOn) {
            $t .= "ℹ️ <i>در حال حاضر فروش لیمیت و قالب غیرفعال است.</i>";
        } elseif (!self::methodsAvailable($store)) {
            $t .= "⚠️ <i>در حال حاضر هیچ روش پرداختی فعال نیست؛ با ادمین تماس بگیرید.</i>";
        }
        return Ui::out($t);
    }

    public static function limitShopKb(Store $store, ?array $user = null, array $supers = []): string
    {
        $rows = [];
        // دکمهٔ خرید اسلات فقط وقتی نشان داده می‌شود که قیمتش واقعاً تعیین شده باشد؛
        // با قیمت صفر، دکمه‌ها «مرده» بودند و کاربر با خطا مواجه می‌شد.
        // و اگر کاربر مسدود است اصلاً دکمهٔ خرید اسلات نباید باشد (خرید ⇒ بازشدن مسدودی).
        $buyable = PaymentGateways::isEnabled($store, PaymentGateways::LIMIT)
            && PaymentPricing::limitUnitPrice($store) > 0
            && !($user !== null && PaymentLimits::isBlocked($store, $user, $supers));
        if ($buyable) {
            $rows[] = [['text' => '➕ خرید ۱ اسلات', 'callback_data' => 'pay:buy:limit:1']];
            $rows[] = [['text' => '➕ خرید ۳ اسلات', 'callback_data' => 'pay:buy:limit:3']];
            $rows[] = [['text' => '➕ خرید ۵ اسلات', 'callback_data' => 'pay:buy:limit:5']];
        }
        if (PaymentGateways::isEnabled($store, PaymentGateways::TEMPLATE)) {
            foreach (Manager::validTypes() as $type => $label) {
                if (PaymentPricing::isPaid($store, $type)) {
                    $rows[] = [['text' => "💰 خرید مجوز {$label}", 'callback_data' => "pay:buy:template:{$type}"]];
                }
            }
        }
        $rows[] = [['text' => '🧾 پرداخت‌های من', 'callback_data' => 'pay:mine']];
        $rows[] = [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]];
        return BotApi::ikb($rows);
    }

    /**
     * کیبورد انتخاب روش پرداخت. فقط روش‌هایی که هم فعال‌اند و هم پیکربندی کامل دارند
     * نمایش داده می‌شوند؛ اگر هیچ‌کدام نبود، کاربر به فروشگاه لیمیت برمی‌گردد.
     */
    public static function methodKb(Store $store, int $paymentId, ?array $cfg = null): string
    {
        $rows = [];
        foreach (PaymentGateways::availableMethods($store, $cfg) as $m) {
            if ($m === Payments::METHOD_CARD) {
                $rows[] = [['text' => '💳 کارت‌به‌کارت', 'callback_data' => "pay:method:card:{$paymentId}"]];
            } elseif ($m === Payments::METHOD_NOWPAY) {
                $rows[] = [['text' => '🪙 کریپتو (NOWPayments)', 'callback_data' => "pay:method:nowpay:{$paymentId}"]];
            } elseif ($m === Payments::METHOD_ZARIN) {
                $rows[] = [['text' => '🟣 درگاه زرین‌پال', 'callback_data' => "pay:method:zarin:{$paymentId}"]];
            } else {
                $rows[] = [['text' => '🧿 درگاه آقای پرداخت', 'callback_data' => "pay:method:aqaye:{$paymentId}"]];
            }
        }
        if ($rows === []) {
            $rows[] = [['text' => '↩️ بازگشت', 'callback_data' => 'pay:shop']];
            return BotApi::ikb($rows);
        }
        $rows[] = [['text' => '🚫 لغو', 'callback_data' => "pay:cancel:{$paymentId}"]];
        return BotApi::ikb($rows);
    }

    /** پیام نبودِ روش پرداخت (وقتی همهٔ درگاه‌ها خاموش یا ناپیکربندی‌اند) */
    public static function noMethodText(Store $store): string
    {
        $t = "⛔️ <b>فعلاً روش پرداختی فعال نیست.</b>\n";
        $why = [];
        if (!PaymentGateways::isEnabled($store, PaymentGateways::CARD)) $why[] = 'کارت‌به‌کارت غیرفعال است';
        elseif (!PaymentCard::isConfigured($store)) $why[] = 'شماره کارت ثبت نشده';
        if (!PaymentGateways::isEnabled($store, PaymentGateways::NOWPAY)) $why[] = 'NOWPayments غیرفعال است';
        else {
            // دقیق بگو کدام کلید کم است؛ «API key ثبت نشده» وقتی مشکل ipn_secret است گمراه‌کننده بود
            if (!PaymentNowPay::hasApiKey($store)) $why[] = 'کلید API ناقص است';
            if (!PaymentNowPay::hasIpnSecret($store)) $why[] = 'IPN Secret ثبت نشده (تأیید خودکار ممکن نیست)';
        }
        if (!PaymentGateways::isEnabled($store, PaymentGateways::ZARIN)) $why[] = 'زرین‌پال غیرفعال است';
        elseif (!PaymentZarin::hasMerchantId($store)) $why[] = 'کد پذیرندهٔ زرین‌پال ثبت نشده';
        if (!PaymentGateways::isEnabled($store, PaymentGateways::AQAYE)) $why[] = 'آقای پرداخت غیرفعال است';
        elseif (!PaymentAqaye::hasPin($store)) $why[] = 'کد پین آقای پرداخت ثبت نشده';
        if ($why !== []) $t .= implode(' • ', $why) . "\n";
        $t .= "لطفاً بعداً تلاش کنید یا با ادمین در میان بگذارید.";
        return Ui::out($t);
    }

    /** آیا حداقل یک روش پرداخت قابل استفاده وجود دارد؟ */
    public static function methodsAvailable(Store $store, ?array $cfg = null): bool
    {
        return PaymentGateways::availableMethods($store, $cfg) !== [];
    }

    /**
     * کیبورد بعد از ثبت رسید کارتی.
     * «پرداخت‌های من» به‌جای برگشت مستقیم به منو، وضعیت را جلوی چشم کاربر می‌آورد
     * (وگرنه رسید ثبت‌شده بود ولی کاربر هیچ راهی برای دیدن نتیجه نداشت).
     */
    public static function receiptSentKb(int $paymentId): string
    {
        return BotApi::ikb([
            [['text' => '🧾 پرداخت‌های من', 'callback_data' => 'pay:mine']],
            [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]],
        ]);
    }

    /**
     * کیبورد فاکتور کریپتو: لینک پرداخت + دکمهٔ «بررسی وضعیت».
     * «بررسی وضعیت» پشتیبانِ وقتی است که IPN سرویس به سرور نمی‌رسد
     * (فایروال/پراکسی) و کاربر باید دستی از خودش تأیید را بگیرد.
     */
    public static function invoiceKb(int $paymentId, string $payUrl = ''): string
    {
        $rows = [];
        if (trim($payUrl) !== '') {
            $rows[] = [['text' => '💳 باز کردن لینک پرداخت', 'url' => $payUrl]];
        }
        $rows[] = [['text' => '🔄 بررسی وضعیت', 'callback_data' => "pay:check:{$paymentId}"]];
        $rows[] = [['text' => '🧾 پرداخت‌های من', 'callback_data' => 'pay:mine']];
        $rows[] = [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]];
        return BotApi::ikb($rows);
    }

    /** فهرست پرداخت‌های کاربر (یک کوئری؛ راهنما از همان فهرست مشتق می‌شود) */
    public static function myPaymentsText(Store $store, int $uid): string
    {
        $list = Payments::userPayments($store, $uid, 20);
        if ($list === []) return Ui::out("🧾 <b>پرداخت‌های من</b>\n\nهنوز هیچ پرداختی ثبت نشده است.\n"
            . "برای خرید اسلات یا مجوز قالب، از «💳 افزایش لیمیت» استفاده کنید.");
        $t = "🧾 <b>پرداخت‌های من</b>\n" . Ui::sep() . "\n";
        foreach (array_slice($list, 0, 10) as $p) {
            $t .= "\n" . Ui::bullet('▪️', Payments::describe($p));
        }
        $hint = self::myPaymentsHint($list);
        if ($hint !== '') $t .= "\n\n" . Ui::quote($hint);
        return Ui::out($t);
    }

    /** راهنمای پایین فهرست پرداخت‌ها — اگر فاکتورِ آنلاینِ باز باشد، «بررسی وضعیت» معنی دارد */
    private static function myPaymentsHint(array $list): string
    {
        if (self::openCryptoPayments($list) !== []) {
            return "با «🔄 بررسی وضعیت» می‌توانید پرداخت آنلاین را دستی هم تأیید کنید (اگر کال‌بک یا IPN به سرور نرسد).";
        }
        if (self::openCardPayments($list) !== []) return "رسید کارتی شما ثبت شده و در صف بررسی ادمین است.";
        return "";
    }

    /** فهرست فاکتورهای آنلاینِ باز (در انتظار پرداخت) — هر درگاهِ «تأیید خودکار» */
    private static function openCryptoPayments(array $list): array
    {
        return array_values(array_filter($list, static function ($p) {
            return PaymentGateways::isAutoConfirmed((string)($p['method'] ?? ''))
                && ($p['status'] ?? '') === Payments::ST_AWAIT_PAY;
        }));
    }

    /** فهرست فاکتورهای کارتیِ باز (رسید ثبت‌شده و در انتظار تأیید) */
    private static function openCardPayments(array $list): array
    {
        return array_values(array_filter($list,
            fn($p) => $p['method'] === Payments::METHOD_CARD && in_array($p['status'], [Payments::ST_AWAIT_RECEIPT, Payments::ST_AWAIT_ADMIN], true)
        ));
    }

    /**
     * کیبورد «پرداخت‌های من».
     * برای هر فاکتورِ آنلاینِ باز (کریپتو/زرین‌پال/آقای پرداخت) یک «🔄 بررسی وضعیت»
     * می‌گذارد تا متن راهنما دروغ نگوید (قبلاً متن «🔄 بررسی» را می‌گفت ولی
     * چنین دکمه‌ای نبود). یک کوئری گرفته می‌شود و بین دو دسته پخش می‌شود.
     */
    public static function myPaymentsKb(Store $store, int $uid): string
    {
        $rows = [];
        // شرط pay_url غیرخالی حذف شد: بعضی فاکتورها بدون لینک ساخته می‌شوند ولی
        // ext_id (شناسهٔ پرداختِ سرویس) دارند؛ بدون دکمه، متن راهنما دروغ
        // می‌گفت و کاربر «کجا دکمهٔ بررسی است؟» می‌پرسید.
        // سقف ۵ دکمه تا کیبورد از حد مجاز تلگرام درنیاید.
        $shown = 0;
        $all = Payments::userPayments($store, $uid, 20);
        foreach (self::openCryptoPayments($all) as $p) {
            if ($shown >= 5) break;
            $id = (int)$p['id'];
            $rows[] = [['text' => '🔄 بررسی وضعیت #' . $id, 'callback_data' => "pay:check:{$id}"]];
            $shown++;
        }
        foreach (self::openCardPayments($all) as $p) {
            if ($shown >= 5) break;
            $id = (int)$p['id'];
            $rows[] = [['text' => '🧾 پیگیری رسید #' . $id, 'callback_data' => "pay:check:{$id}"]];
            $shown++;
        }
        $rows[] = [['text' => '💳 فروشگاه لیمیت', 'callback_data' => 'pay:shop']];
        $rows[] = [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]];
        return BotApi::ikb($rows);
    }

    // ---- ادمین ----

    public static function adminText(Store $store): string
    {
        $unit = PaymentPricing::limitUnitPrice($store);
        $card = PaymentCard::getCardNumber($store);
        $nowOk = PaymentNowPay::isConfigured($store) ? '✅' : '❌';
        $zarinOk = PaymentZarin::isConfigured($store) ? '✅' : '❌';
        $aqayeOk = PaymentAqaye::isConfigured($store) ? '✅' : '❌';

        $t = "💳 <b>مدیریت پرداخت‌ها</b>\n\n";
        $t .= "🚦 <b>درگاه‌ها</b>\n";
        foreach (PaymentGateways::keys() as $k) $t .= "• " . PaymentGateways::statusLine($store, $k) . "\n";
        $t .= "\n💰 <b>قیمت‌ها</b>\n";
        $t .= "هر اسلات لیمیت: <b>" . PaymentPricing::formatToman($unit) . "</b>\n";
        foreach (Manager::validTypes() as $type => $label) {
            $t .= "«{$label}»: <b>" . PaymentPricing::formatToman(PaymentPricing::templatePrice($store, $type)) . "</b>\n";
        }
        $t = rtrim($t, "\n") . "\n\n";
        $t .= "💵 <b>نرخ دلار</b>\n" . implode("\n", FxRate::statusLines($store)) . "\n";

        $t .= "\n🔧 <b>پیکربندی درگاه‌ها</b>\n";
        $t .= Ui::kv('💳', 'شماره کارت', $card !== '' ? $card : 'ثبت نشده', true) . "\n";
        if (PaymentCard::getCardOwner($store) !== '') {
            $t .= Ui::kv('👤', 'صاحب کارت', PaymentCard::getCardOwner($store)) . "\n";
        }
        $t .= Ui::kv('🪙', 'NOWPayments', $nowOk) . "\n";
        // کدام کلید کم است تا ادمین بداند دقیقاً چه چیزی را باید وارد کند
        if ($nowOk === '❌') {
            $miss = [];
            if (!PaymentNowPay::hasApiKey($store)) $miss[] = 'API Key';
            if (!PaymentNowPay::hasIpnSecret($store)) $miss[] = 'IPN Secret';
            if ($miss !== []) $t .= "   ↳ ⚠️ ناقص: <b>" . Ui::e(implode(' + ', $miss)) . "</b>\n";
        }
        $t .= Ui::kv('🟣', 'زرین‌پال', $zarinOk . ($zarinOk === '✅' ? ' — ' . (PaymentZarin::isSandbox($store) ? '🧪 تست (sandbox)' : 'واقعی') : '')) . "\n";
        if ($zarinOk === '❌') $t .= "   ↳ ⚠️ کد ۳۶ کاراکتری پذیرنده ثبت نشده است\n";
        $t .= Ui::kv('🧿', 'آقای پرداخت', $aqayeOk . ($aqayeOk === '✅' ? ' — pin ثبت شد' : '')) . "\n";
        if ($aqayeOk === '❌') $t .= "   ↳ ⚠️ کد پین درگاه ثبت نشده است\n";

        if ($unit <= 0) $t .= "\n⚠️ قیمت اسلات صفر است ⇒ دکمهٔ خرید اسلات نمایش داده نمی‌شود.";
        $t .= "\n\n🧾 در انتظار بررسی: <b>" . Payments::pendingAdminCount($store) . "</b>";

        return Ui::out($t);
    }

    public static function adminKb(Store $store): string
    {
        $rows = [];
        foreach (PaymentGateways::keys() as $k) {
            $on = PaymentGateways::isEnabled($store, $k);
            $mark = $on ? '🟢' : '🔴';
            $rows[] = [['text' => "{$mark} " . PaymentGateways::label($k), 'callback_data' => "payadmin:toggle:{$k}"]];
        }
        $rows[] = [['text' => '📝 متن لیمیت', 'callback_data' => 'payadmin:text:limit'], ['text' => '📝 متن قالب', 'callback_data' => 'payadmin:text:template']];
        $rows[] = [['text' => '📝 متن کارت', 'callback_data' => 'payadmin:text:card'], ['text' => '📝 متن کریپتو', 'callback_data' => 'payadmin:text:nowpay']];
        $rows[] = [['text' => '📝 متن زرین‌پال', 'callback_data' => 'payadmin:text:zarin'], ['text' => '📝 متن آقای پرداخت', 'callback_data' => 'payadmin:text:aqaye']];
        $rows[] = [['text' => '🧾 بررسی پرداخت‌ها', 'callback_data' => 'payadmin:list']];
        // دکمهٔ قیمتِ هر قالب — پویا از رجیستری قالب‌ها.
        // قبلاً فقط «فاکسیما» و «میرزا» هاردکد شده بودند؛ قالب‌های تازه
        // (آپ‌تایم / پاسارگاد) اصلاً دکمهٔ تعیین قیمت نداشتند و از پنل قیمت‌گذاری
        // نمی‌شد فروششان کرد.
        $priceChunk = [];
        foreach (array_keys(Manager::validTypes()) as $ptype) {
            $plabel = preg_replace('/\s*\(.*$/u', '', Manager::templateLabel($ptype));
            if ($plabel === null || $plabel === '') $plabel = $ptype;
            $priceChunk[] = ['text' => '💰 ' . $plabel, 'callback_data' => 'payadmin:setprice:' . $ptype];
            if (count($priceChunk) === 2) { $rows[] = $priceChunk; $priceChunk = []; }
        }
        if ($priceChunk !== []) $rows[] = $priceChunk;
        $rows[] = [['text' => '📈 قیمت اسلات', 'callback_data' => 'payadmin:limitprice']];
        // نرخ دلار: هم دستی، هم تازه‌سازی خودکار از API
        $rows[] = [['text' => '💵 نرخ دلار (دستی)', 'callback_data' => 'payadmin:usdrate']];
        $rows[] = [['text' => '🔄 تازه‌سازی نرخ از API', 'callback_data' => 'payadmin:fxrefresh']];
        $rows[] = [['text' => '🔄 نرخ خودکار (API)', 'callback_data' => 'payadmin:fxauto']];
        $rows[] = [
            ['text' => '💳 تنظیم کارت', 'callback_data' => 'payadmin:card'],
            ['text' => '🪙 تنظیم NOWPayments', 'callback_data' => 'payadmin:nowpay'],
        ];
        $rows[] = [['text' => '🟣 تنظیم زرین‌پال', 'callback_data' => 'payadmin:zarin']];
        $rows[] = [['text' => '🧿 تنظیم آقای پرداخت', 'callback_data' => 'payadmin:aqaye']];
        $rows[] = [['text' => '👤 تعیین لیمیت کاربر', 'callback_data' => 'payadmin:setlimit']];
        $rows[] = [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]];
        return BotApi::ikb($rows);
    }

    /**
     * کیبورد بررسی یک پرداخت.
     * برای فاکتورهای «تأیید خودکار» (کریپتو/زرین‌پال/آقای پرداخت) دکمهٔ
     * «تأیید» معنا ندارد ⇒ می‌شود «🔄 استعلام وضعیت» تا ادمین بتواند دستی هم بپرسد.
     */
    public static function reviewKb(int $paymentId, string $method = ''): string
    {
        $first = PaymentGateways::isAutoConfirmed($method)
            ? ['text' => '🔄 استعلام وضعیت', 'callback_data' => "payadmin:verify:{$paymentId}"]
            : ['text' => '✅ تأیید', 'callback_data' => "payadmin:approve:{$paymentId}"];
        return BotApi::ikb([
            [$first, ['text' => '❌ رد', 'callback_data' => "payadmin:decline:{$paymentId}"]],
            [['text' => '🔄 بررسی دوباره', 'callback_data' => 'payadmin:list'], ['text' => Nav::BACK, 'callback_data' => 'payadmin:panel']],
        ]);
    }

    /** پنل ادمین (callback_data = payadmin:panel) */
    public const CB_ADMIN_PANEL = 'payadmin:panel';
}
