<?php
// ===== ماژول قیمت‌گذاری قالب‌ها =====
// قیمت هر قالب به تومان؛ 0 یعنی رایگان.
// کلیدها در جدول settings ذخیره می‌شوند تا ادمین از داخل ربات عوضشان کند.

class PaymentPricing
{
    public static function priceKey(string $type): string
    {
        return 'pay_price_' . strtolower(trim($type));
    }

    /** قیمت یک قالب (تومان)؛ ناشناخته => 0 */
    public static function templatePrice(Store $store, string $type): int
    {
        $raw = (string)($store->getSetting(self::priceKey($type), '0') ?? '0');
        return (int)Payments::digitsOnly($raw);
    }

    public static function setTemplatePrice(Store $store, string $type, int $toman): void
    {
        $store->setSetting(self::priceKey($type), (string)max(0, $toman));
    }

    /** قیمت هر اسلات اضافه لیمیت (تومان) */
    public static function limitUnitPrice(Store $store): int
    {
        $raw = (string)($store->getSetting('pay_limit_price', '50000') ?? '50000');
        return (int)Payments::digitsOnly($raw);
    }

    public static function setLimitUnitPrice(Store $store, int $toman): void
    {
        $store->setSetting('pay_limit_price', (string)max(0, $toman));
    }

    /** آیا درگاه «پولی‌کردن قالب‌ها» فعال است؟ */
    public static function isActive(Store $store): bool
    {
        return PaymentGateways::isEnabled($store, PaymentGateways::TEMPLATE);
    }

    /** آیا قالب پولی است؟ (درگاه غیرفعال ⇒ همه قالب‌ها رایگان) */
    public static function isPaid(Store $store, string $type): bool
    {
        if (!self::isActive($store)) return false;
        return self::templatePrice($store, $type) > 0;
    }

    public static function formatToman(int $toman): string
    {
        if ($toman <= 0) return 'رایگان';
        return number_format($toman) . ' تومان';
    }

    /**
     * نرخ تبدیل تومان به دلار — مقدار «پایه/دستی» (بدون تماس شبکه).
     * این همان رفتار قبلی است و برای نمایشِ پنل و تست‌ها استفاده می‌شود؛
     * مسیرِ واقعیِ ساخت فاکتور از effectiveUsdRate() می‌گذرد که نرخ API را هم می‌آورد.
     */
    public static function tomanPerUsd(Store $store): float
    {
        $raw = Payments::toNumber((string)($store->getSetting(FxRate::K_MANUAL, '100000') ?? '100000'));
        return ($raw !== null && $raw > 0) ? $raw : FxRate::FALLBACK_RATE;
    }

    /**
     * گذاشتن نرخ دستی = «قفل کردن» نرخ.
     * تا وقتی ادمین دکمهٔ «🔄 نرخ خودکار» را نزده، تازه‌سازیِ خودکار دستِ او را عوض نمی‌کند.
     */
    public static function setTomanPerUsd(Store $store, float $rate): void
    {
        $rate = $rate > 0 ? $rate : FxRate::FALLBACK_RATE;
        $store->setSetting(FxRate::K_MANUAL, (string)$rate);
        FxRate::setManual($store, $rate);
    }

    /** برگرداندن نرخ به حالت «خودکار» (از API خوانده شود) */
    public static function setUsdRateAuto(Store $store): void
    {
        FxRate::enableAuto($store);
    }

    /**
     * نرخِ مؤثر برای ساخت فاکتور: اول نرخ تازهٔ API، وگرنه نرخ دستی/پیش‌فرض.
     * هیچ‌وقت خطا نمی‌دهد — اگر شبکه باشد نبود، فاکتور با نرخ قبلی ساخته می‌شود.
     */
    public static function effectiveUsdRate(Store $store): float
    {
        try {
            return FxRate::forInvoice($store);
        } catch (Throwable $e) {
            return self::tomanPerUsd($store);
        }
    }

    /**
     * کمترین مبلغ قابل قبول NOWPayments (سرویس زیر این مقدار را رد می‌کند).
     * اگر مبلغ تومانی کاربر از این کمتر شود، به این حد می‌رسد تا فاکتور ساخته شود.
     */
    public const NOWPAY_MIN_USD = 0.5;

    /**
     * تبدیل تومان به دلار برای فاکتور.
     * گِرد کردن به بالا (نه پایین): با round پایین، هر ۵۰٬۰۰۰ تومان = ۰٫۴۹ دلار
     * می‌شد و فروشنده از هر فاکتور ضرر می‌کرد. همچنین مبالغ کوچک به صفر
     * گِرد می‌شدند و سرویس خطا می‌داد.
     */
    public static function tomanToUsd(int $toman, float $rate): float
    {
        if ($rate <= 0) $rate = FxRate::FALLBACK_RATE;
        if ($toman <= 0) return 0.0;
        $usd = ceil(($toman / $rate) * 100) / 100;   // همیشه رو به بالا، دو رقم
        return max(self::NOWPAY_MIN_USD, $usd);
    }
}
