<?php
// ===== پنل و کیبوردهای پرداخت (متن‌ها و دکمه‌ها) =====
// فقط ساختار پیام/دکمه اینجاست؛ منطق در Payments/Limits/Pricing/Gateways است.
// هر متن و دکمه از PaymentGateways می‌خواند تا فعال/غیرفعال‌سازی و متن دلخواه
// بدون دست‌زدن به این فایل اعمال شود.

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

        $t = "💳 <b>افزایش لیمیت ساخت ربات</b>\n\n";
        $t .= "سقف شما: <b>" . PaymentLimits::formatLimit($limit) . "</b>\n";
        if ($limit >= 0) $t .= "ربات‌های فعلی: <b>{$count}</b>\n";
        $t .= "قیمت هر اسلات اضافه: <b>" . PaymentPricing::formatToman($unit) . "</b>\n\n";

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

        // وضعیت هر قالب (فقط اگر درگاه قالب فعال باشد قیمت‌ها معنا دارد)
        foreach (Manager::validTypes() as $type => $label) {
            $p = PaymentPricing::templatePrice($store, $type);
            if (!$tplOn || $p <= 0) {
                $t .= "🆓 {$label}: رایگان\n";
            } else {
                $t .= "💰 {$label}: <b>" . number_format($p) . " تومان</b>\n";
            }
        }

        if (!$limitOn && !$tplOn) {
            $t .= "\nℹ️ در حال حاضر فروش لیمیت و قالب غیرفعال است.";
        } elseif (!self::methodsAvailable($store)) {
            $t .= "\n⚠️ در حال حاضر هیچ روش پرداختی فعال نیست. با ادمین تماس بگیرید.";
        }
        return $t;
    }

    public static function limitShopKb(Store $store): string
    {
        $rows = [];
        if (PaymentGateways::isEnabled($store, PaymentGateways::LIMIT)) {
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
            } else {
                $rows[] = [['text' => '🪙 کریپتو (NOWPayments)', 'callback_data' => "pay:method:nowpay:{$paymentId}"]];
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
        elseif (!PaymentNowPay::isConfigured($store)) $why[] = 'API key ثبت نشده';
        if ($why !== []) $t .= implode(' • ', $why) . "\n";
        $t .= "لطفاً بعداً تلاش کنید یا با ادمین در میان بگذارید.";
        return $t;
    }

    /** آیا حداقل یک روش پرداخت قابل استفاده وجود دارد؟ */
    public static function methodsAvailable(Store $store, ?array $cfg = null): bool
    {
        return PaymentGateways::availableMethods($store, $cfg) !== [];
    }

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

    /** فهرست پرداخت‌های کاربر */
    public static function myPaymentsText(Store $store, int $uid): string
    {
        $list = Payments::userPayments($store, $uid, 10);
        if ($list === []) return "🧾 <b>پرداخت‌های من</b>\n\nهنوز پرداختی ثبت نشده.";
        $t = "🧾 <b>پرداخت‌های من</b>\n\n";
        foreach ($list as $p) $t .= Payments::describe($p) . "\n";
        return $t . "\nبا «🔄 بررسی» وضعیت پرداخت کارتی به‌روز می‌شود.";
    }

    // ---- ادمین ----

    public static function adminText(Store $store): string
    {
        $unit = PaymentPricing::limitUnitPrice($store);
        $card = PaymentCard::getCardNumber($store);
        $nowOk = PaymentNowPay::isConfigured($store) ? '✅' : '❌';
        $t = "💳 <b>مدیریت پرداخت‌ها</b>\n\n";
        $t .= "<b>درگاه‌ها</b>\n";
        foreach (PaymentGateways::keys() as $k) $t .= "• " . PaymentGateways::statusLine($store, $k) . "\n";
        $t .= "\n<b>قیمت‌ها</b>\n";
        $t .= "هر اسلات لیمیت: <b>" . PaymentPricing::formatToman($unit) . "</b>\n";
        foreach (Manager::validTypes() as $type => $label) {
            $t .= "«{$label}»: <b>" . PaymentPricing::formatToman(PaymentPricing::templatePrice($store, $type)) . "</b>\n";
        }
        $t .= "نرخ دلار: <b>" . number_format(PaymentPricing::tomanPerUsd($store)) . "</b> تومان\n";
        $t .= "\n<b>پیکربندی</b>\n";
        $t .= "کارت: <code>" . htmlspecialchars($card !== '' ? $card : 'ثبت نشده') . "</code>\n";
        $t .= "NOWPayments: {$nowOk}\n";
        $t .= "در انتظار بررسی: <b>" . count(Payments::pendingAdminList($store, 50)) . "</b>";
        return $t;
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
        $rows[] = [['text' => '🧾 بررسی پرداخت‌ها', 'callback_data' => 'payadmin:list']];
        $rows[] = [
            ['text' => '💰 قیمت فاکسیما', 'callback_data' => 'payadmin:setprice:faxima'],
            ['text' => '💰 قیمت میرزا', 'callback_data' => 'payadmin:setprice:mirza'],
        ];
        $rows[] = [
            ['text' => '📈 قیمت اسلات', 'callback_data' => 'payadmin:limitprice'],
            ['text' => '💵 نرخ دلار', 'callback_data' => 'payadmin:usdrate'],
        ];
        $rows[] = [
            ['text' => '💳 تنظیم کارت', 'callback_data' => 'payadmin:card'],
            ['text' => '🪙 تنظیم NOWPayments', 'callback_data' => 'payadmin:nowpay'],
        ];
        $rows[] = [['text' => '👤 تعیین لیمیت کاربر', 'callback_data' => 'payadmin:setlimit']];
        $rows[] = [['text' => Nav::BACK, 'callback_data' => Nav::CB_BACK_MAIN]];
        return BotApi::ikb($rows);
    }

    public static function reviewKb(int $paymentId): string
    {
        return BotApi::ikb([
            [['text' => '✅ تأیید', 'callback_data' => "payadmin:approve:{$paymentId}"], ['text' => '❌ رد', 'callback_data' => "payadmin:decline:{$paymentId}"]],
            [['text' => '🔄 بررسی دوباره', 'callback_data' => 'payadmin:list'], ['text' => Nav::BACK, 'callback_data' => 'payadmin:panel']],
        ]);
    }

    /** پنل ادمین (callback_data = payadmin:panel) */
    public const CB_ADMIN_PANEL = 'payadmin:panel';
}
