<?php
// ===== ماژول فعال/غیرفعال‌سازی درگاه‌ها و متن دلخواه =====
// تنها منبع حقیقت برای اینکه «کدام بخش از پرداخت فعال است» و «چه متنی به کاربر نشان داده شود».
//
// چهار کلید مستقل:
//   limit    → فروش/اجرای سقف تعداد ربات (لیمیت)
//   template → پولی‌کردن قالب‌ها (فاکسیما/میرزا)
//   card     → درگاه کارت‌به‌کارت (تأیید دستی ادمین)
//   nowpay   → درگاه کریپتو NOWPayments (IPN خودکار)
//   zarin    → درگاه زرین‌پال (IPN/کال‌بک خودکار)
//   aqaye    → درگاه آقای پرداخت (کال‌بک خودکار)
//
// قاعدهٔ رفتاری:
//   limit غیرفعال ⇒ سقف اصلاً اعمال نمی‌شود و خرید اسلات هم غیرفعال است.
//   template غیرفعال ⇒ قالب‌ها رایگان می‌شوند و ووچر خریداری‌شده‌ی قبلی هم مصرف نمی‌شود.
//   card/nowpay/zarin/aqaye غیرفعال ⇒ آن دکمه در من انتخاب روش پرداخت نمایش داده نمی‌شود.
//
// مقدارها در جدول settings ذخیره می‌شوند ('1' فعال، '0' غیرفعال، خالی = پیش‌فرض فعال)
// تا ادمین از داخل ربات بدون دسترسی به فایل/دیتابیس همه‌چیز را عوض کند.

class PaymentGateways
{
    public const LIMIT = 'limit';
    public const TEMPLATE = 'template';
    public const CARD = 'card';
    public const NOWPAY = 'nowpay';
    public const ZARIN = 'zarin';
    public const AQAYE = 'aqaye';

/** متن پیش‌فرض هر بخش؛ «‹نام›» جای‌نگهدار است و با مقدار واقعی عوض می‌شود */
    private const DEFAULT_TEXT = [
        self::LIMIT => "برای افزایش سقف ساخت ربات باید هر اسلات را جداگانه تهیه کنید.\nهر اسلات: ‹amount› — سقف فعلی شما: ‹limit›\nساخته‌شده: ‹count› | باقی‌مانده: ‹remaining›",
        self::TEMPLATE => "ساخت این قالب رایگان نیست.\nقیمت: ‹amount›\nبعد از پرداخت، مجوز ساخت «‹type›» برای شما صادر می‌شود.",
        self::CARD => "بعد از واریز، فیش (عکس) یا شماره پیگیری را بفرستید تا ادمین بررسی کند.",
        self::NOWPAY => "پرداخت کریپتویی از طریق NOWPayments:\nمبلغ: ‹amount›\nپس از پرداخت، خودکار تأیید می‌شود.",
        self::ZARIN => "پرداخت اینترنتی از طریق درگاه زرین‌پال:\nمبلغ: ‹amount›\nپس از پرداخت، خودکار تأیید می‌شود.",
        self::AQAYE => "پرداخت اینترنتی از طریق درگاه «آقای پرداخت»:\nمبلغ: ‹amount›\nپس از پرداخت، خودکار تأیید می‌شود.",
    ];
    // ---------- کلیدها ----------

    /** همهٔ کلیدهای معتبر */
    public static function keys(): array
    {
        return [self::LIMIT, self::TEMPLATE, self::CARD, self::NOWPAY, self::ZARIN, self::AQAYE];
    }

    public static function isValidKey(string $key): bool
    {
        return in_array(self::normalize($key), self::keys(), true);
    }

    /** نرمال‌سازی کلید (کوچک + بدون فاصله) تا ورودی دکمه‌ها همیشه معتبر باشد */
    public static function normalize(string $key): string
    {
        return strtolower(trim($key));
    }

    public static function label(string $key): string
    {
        $map = [
            self::LIMIT => 'لیمیت ساخت ربات',
            self::TEMPLATE => 'پولی‌کردن قالب‌ها',
            self::CARD => 'درگاه کارت‌به‌کارت',
            self::NOWPAY => 'درگاه کریپتو (NOWPayments)',
            self::ZARIN => 'درگاه زرین‌پال',
            self::AQAYE => 'درگاه آقای پرداخت',
        ];
        $k = self::normalize($key);
        return $map[$k] ?? $k;
    }

    public static function enabledKey(string $key): string
    {
        return 'pay_on_' . self::normalize($key);
    }

    public static function textKey(string $key): string
    {
        return 'pay_text_' . self::normalize($key);
    }

    // ---------- فعال / غیرفعال ----------

    /**
     * وضعیت یک درگاه. غیبت کلید در settings یعنی «پیش‌فرض» که فعال است؛
     * هر مقدار غیر از 0/empty/false/off ⇒ فعال.
     */
    public static function isEnabled(Store $store, string $key): bool
    {
        $k = self::normalize($key);
        if (!self::isValidKey($k)) return false;
        $raw = $store->getSetting(self::enabledKey($k), '1');
        $raw = strtolower(trim((string)$raw));
        if ($raw === '') return true;                       // نانویس = پیش‌فرض فعال
        return !in_array($raw, ['0', 'off', 'false', 'no', 'disabled'], true);
    }

    public static function setEnabled(Store $store, string $key, bool $on): void
    {
        $k = self::normalize($key);
        if (!self::isValidKey($k)) return;
        $store->setSetting(self::enabledKey($k), $on ? '1' : '0');
    }

    /** وضعیت را برعکس می‌کند و وضعیت جدید را برمی‌گرداند */
    public static function toggle(Store $store, string $key): bool
    {
        $k = self::normalize($key);
        if (!self::isValidKey($k)) return false;
        $new = !self::isEnabled($store, $k);
        self::setEnabled($store, $k, $new);
        return $new;
    }

    // ---------- متن دلخواه ----------

    /** متن ذخیره‌شده (بدون پیش‌فرض)؛ '' یعنی ادمین متنی نگذاشته */
    public static function customText(Store $store, string $key): string
    {
        $k = self::normalize($key);
        if (!self::isValidKey($k)) return '';
        return trim((string)($store->getSetting(self::textKey($k), '') ?? ''));
    }

    public static function hasCustomText(Store $store, string $key): bool
    {
        return self::customText($store, $key) !== '';
    }

    public static function setCustomText(Store $store, string $key, string $text): void
    {
        $k = self::normalize($key);
        if (!self::isValidKey($k)) return;
        $text = trim($text);
        if ($text === '') { self::resetText($store, $k); return; }
        // طول متن تلگرام محدود است؛ بیش از ۹۰۰ کاراکتر نگه داشته نمی‌شود
        if (mb_strlen($text) > 900) $text = mb_substr($text, 0, 900);
        $store->setSetting(self::textKey($k), $text);
    }

    /** حذف متن دلخواه و برگشت به متن پیش‌فرض */
    public static function resetText(Store $store, string $key): void
    {
        $k = self::normalize($key);
        if (!self::isValidKey($k)) return;
        $store->setSetting(self::textKey($k), '');
    }

    public static function defaultText(string $key): string
    {
        $k = self::normalize($key);
        return self::DEFAULT_TEXT[$k] ?? '';
    }

    /**
     * متن آمادهٔ نمایش برای یک بخش (متن دلخواه یا پیش‌فرض).
     * $vars متغیرهای جایگزین‌شونده: amount / slots / count / limit / remaining / type
     */
    public static function note(Store $store, string $key, array $vars = []): string
    {
        $k = self::normalize($key);
        $text = self::hasCustomText($store, $k) ? self::customText($store, $k) : self::defaultText($k);
        if ($text === '') return '';
        $vars = array_merge([
            'amount' => '—',
            'slots' => '—',
            'count' => '—',
            'limit' => '—',
            'remaining' => '—',
            'type' => '—',
        ], $vars);
        foreach ($vars as $name => $value) {
            $text = str_replace('‹' . $name . '›', (string)$value, $text);
        }
        return $text;
    }

    // ---------- وضعیت ترکیبی برای نمایش ----------

    /** ایموجی + عنوان برای نمایش در پنل ادمین */
    public static function statusLine(Store $store, string $key): string
    {
        $on = self::isEnabled($store, $key);
        $mark = $on ? '🟢 فعال' : '🔴 غیرفعال';
        $txt = self::hasCustomText($store, $key) ? ' 📝متن‌دار' : '';
        return $mark . ' — ' . self::label($key) . $txt;
    }

    /**
     * روش‌های پرداختی که واقعاً قابل استفاده‌اند:
     * درگاه فعال باشد و پیکربندی‌اش هم کامل باشد (کارت ست شده / کلید موجود).
     */
    public static function availableMethods(Store $store, ?array $cfg = null): array
    {
        $out = [];
        if (self::isEnabled($store, self::CARD) && PaymentCard::isConfigured($store)) {
            $out[] = Payments::METHOD_CARD;
        }
        if (self::isEnabled($store, self::NOWPAY) && PaymentNowPay::isConfigured($store, $cfg)) {
            $out[] = Payments::METHOD_NOWPAY;
        }
        if (self::isEnabled($store, self::ZARIN) && PaymentZarin::isConfigured($store, $cfg)) {
            $out[] = Payments::METHOD_ZARIN;
        }
        if (self::isEnabled($store, self::AQAYE) && PaymentAqaye::isConfigured($store, $cfg)) {
            $out[] = Payments::METHOD_AQAYE;
        }
        return $out;
    }

    /**
     * روش‌هایی که «تأیید خودکار» دارند (یعنی لازم نیست ادمین رسید را ببیند).
     * بقیه (کارت‌به‌کارت) تأیید دستی می‌خواهند.
     */
    public static function isAutoConfirmed(string $method): bool
    {
        return in_array($method, [
            Payments::METHOD_NOWPAY, Payments::METHOD_ZARIN, Payments::METHOD_AQAYE,
        ], true);
    }

    /** آیا اصلاً چیزی برای فروش هست؟ (برای پنهان‌کردن دکمهٔ منو) */
    public static function isAnythingEnabled(Store $store): bool
    {
        return self::isEnabled($store, self::LIMIT) || self::isEnabled($store, self::TEMPLATE);
    }
}
