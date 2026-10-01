<?php
// ===== ماژول کارت‌به‌کارت (تأیید دستی توسط ادمین) =====
// ادمین شماره کارت را ست می‌کند؛ کاربر فیش/رسید می‌فرستد؛ ادمین تأیید/رد می‌کند.

class PaymentCard
{
    public static function getCardNumber(Store $store): string
    {
        return trim((string)($store->getSetting('pay_card_number', '') ?? ''));
    }

    public static function getCardOwner(Store $store): string
    {
        return trim((string)($store->getSetting('pay_card_owner', '') ?? ''));
    }

    public static function setCard(Store $store, string $number, string $owner): void
    {
        // فقط ارقام/خط‌تیره/فاصله برای شماره کارت نگه داشته می‌شود
        $digits = preg_replace('/[^\d\-\s]/', '', self::normalizeNumber($number));
        $store->setSetting('pay_card_number', trim((string)$digits));
        // نام صاحب کارت هم escape-نشده در پیام HTML می‌آید ⇒ فقط متن ساده
        $owner = trim(preg_replace('/[<>&]/', '', $owner) ?? '');
        $store->setSetting('pay_card_owner', $owner);
    }

    /** ارقام فارسی/عربی را لاتین می‌کند (ورودی ادمین ممکن است فارسی باشد) */
    private static function normalizeNumber(string $s): string
    {
        return preg_replace('/[^0-9\-]/', '', Payments::normalizeDigits($s)) ?? '';
    }

    /**
     * آیا شماره کارت معتبر ثبت شده؟
     * فقط «رقم داشتن» کافی نبود؛ هر رشتهٔ دارای یک رقم (مثل «ab1») درگاه را
     * فعال نشان می‌داد و کاربر فاکتور می‌ساخت با شمارهٔ بی‌معنی.
     */
    public static function isConfigured(Store $store): bool
    {
        return strlen(preg_replace('/\D/', '', self::getCardNumber($store)) ?? '') === 16;
    }

    /**
     * متن راهنمای واریز برای نمایش به کاربر.
     * اگر ادمین برای درگاه کارت «متن دلخواه» ثبت کرده باشد، همان بالای پیام می‌آید.
     */
    public static function payInstructions(Store $store, int $amountToman, ?string $orderRef = ''): string
    {
        $num = self::getCardNumber($store);
        $owner = self::getCardOwner($store);
        $t = "💳 <b>پرداخت کارت‌به‌کارت</b>\n\nمبلغ: <b>" . number_format($amountToman) . " تومان</b>\n";
        if ($orderRef !== '') $t .= "شماره پیگیری داخلی: <code>" . htmlspecialchars($orderRef) . "</code>\n";
        $t .= "شماره کارت:\n<code>" . htmlspecialchars($num !== '' ? $num : 'تنظیم نشده — با ادمین در میان بگذارید') . "</code>\n";
        if ($owner !== '') $t .= "به نام: " . htmlspecialchars($owner) . "\n";
        $note = PaymentGateways::note($store, PaymentGateways::CARD, [
            'amount' => number_format($amountToman) . ' تومان',
            'slots' => '۱',
        ]);
        if ($note !== '') $t .= "\n" . $note . "\n";
        $t .= "\nبعد از واریز، فیش (عکس) یا شماره پیگیری را همین‌جا بفرستید تا ادمین بررسی کند.";
        return $t;
    }

    /** اعتبارسنجی ورودی رسید: عکس/فایل همیشه قبول؛ متن حداقل 4 کاراکتر */
    public static function isValidReceipt(?string $text, bool $hasAttachment): bool
    {
        if ($hasAttachment) return true;
        return mb_strlen(trim((string)$text)) >= 4;
    }
}
