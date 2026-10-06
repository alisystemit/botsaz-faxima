<?php
// ===== ماژول کارت‌به‌کارت (تأیید دستی توسط ادمین) =====
// ادمین شماره کارت را ست می‌کند؛ کاربر فیش/رسید می‌فرستد؛ ادمین تأیید/رد می‌کند.

require_once __DIR__ . '/../Ui.php';

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
        $t = "💳 <b>پرداخت کارت‌به‌کارت</b>\n\n";
        $t .= Ui::kv('💰', 'مبلغ', Ui::toman($amountToman)) . "\n";
        if ($orderRef !== '') $t .= Ui::kv('🔢', 'شماره پیگیری داخلی', (string)$orderRef, true) . "\n";
        $t .= "\n🏦 <b>اطلاعات کارت</b>\n";
        $t .= Ui::kv('🔢', 'شماره کارت', $num !== '' ? $num : 'تنظیم نشده — با ادمین در میان بگذارید', true) . "\n";
        if ($owner !== '') $t .= Ui::kv('👤', 'به نام', $owner) . "\n";
        $note = PaymentGateways::note($store, PaymentGateways::CARD, [
            'amount' => number_format($amountToman) . ' تومان',
            'slots' => '۱',
        ]);
        if ($note !== '') $t .= "\n" . $note . "\n";
        $t .= "\n" . Ui::sep() . "\n";
        $t .= "📸 <b>مرحلهٔ بعد</b>\n"
            . Ui::bullet('1️⃣', 'به همان اندازه واریز کنید.')
            . "\n" . Ui::bullet('2️⃣', 'عکس فیش یا شمارهٔ پیگیری را همین‌جا بفرستید.')
            . "\n" . Ui::bullet('3️⃣', 'ادمین بررسی می‌کند و به شما اطلاع می‌دهد.');
        return Ui::out($t);
    }

    /** اعتبارسنجی ورودی رسید: عکس/فایل همیشه قبول؛ متن حداقل 4 کاراکتر */
    public static function isValidReceipt(?string $text, bool $hasAttachment): bool
    {
        if ($hasAttachment) return true;
        return mb_strlen(trim((string)$text)) >= 4;
    }
}
