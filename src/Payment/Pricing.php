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

    /** نرخ تبدیل تومان به دلار برای فاکتور NOWPayments */
    public static function tomanPerUsd(Store $store): float
    {
        $raw = Payments::toNumber((string)($store->getSetting('pay_toman_per_usd', '100000') ?? '100000'));
        return ($raw !== null && $raw > 0) ? $raw : 100000.0;
    }

    public static function setTomanPerUsd(Store $store, float $rate): void
    {
        $store->setSetting('pay_toman_per_usd', (string)($rate > 0 ? $rate : 100000));
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
        if ($rate <= 0) $rate = 100000.0;
        if ($toman <= 0) return 0.0;
        $usd = ceil(($toman / $rate) * 100) / 100;   // همیشه رو به بالا، دو رقم
        return max(self::NOWPAY_MIN_USD, $usd);
    }
}
