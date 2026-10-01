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
        $raw = $store->getSetting(self::priceKey($type), '0');
        $v = (int)preg_replace('/[^0-9]/', '', (string)$raw);
        return max(0, $v);
    }

    public static function setTemplatePrice(Store $store, string $type, int $toman): void
    {
        $store->setSetting(self::priceKey($type), (string)max(0, $toman));
    }

    /** قیمت هر اسلات اضافه لیمیت (تومان) */
    public static function limitUnitPrice(Store $store): int
    {
        $raw = $store->getSetting('pay_limit_price', '50000');
        return max(0, (int)preg_replace('/[^0-9]/', '', (string)$raw));
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
        $raw = (float)($store->getSetting('pay_toman_per_usd', '100000') ?? '100000');
        return $raw > 0 ? $raw : 100000.0;
    }

    public static function setTomanPerUsd(Store $store, float $rate): void
    {
        $store->setSetting('pay_toman_per_usd', (string)($rate > 0 ? $rate : 100000));
    }

    public static function tomanToUsd(int $toman, float $rate): float
    {
        if ($rate <= 0) $rate = 100000.0;
        return round($toman / $rate, 2);
    }
}
