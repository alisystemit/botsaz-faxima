<?php
// ===== کلیدهای روشن/خاموش ساخت ربات =====
//
// دو کلید مستقل که ادمین با یک دکمهٔ روشن/خاموش عوضشان می‌کند (پنل «⚙️ تنظیمات»):
//
//  ۱) approval  → آیا ساخت ربات نیاز به «درخواست و تأیید ادمین» دارد؟
//                 خاموش ⇒ هر کاربرِ مجاز مستقیم می‌سازد (سقف/پرداخت همچنان برقرار است)
//                 روشن  ⇒ رفتار پیش‌فرضِ قبلی: درخواست ثبت و منتظر تأیید ادمین می‌ماند
//
//  ۲) maintenance → «قسمت ربات در حال تعمیر است»
//                 روشن ⇒ هیچ‌کس (حتی ادمین) ربات تازه نمی‌سازد و پیامِ تعمیرات می‌بیند.
//                 هدفش این است که هنگام خرابیِ قالب‌ها، کاربرها پیام روشن ببینند
//                 به‌جای خطای مبهمِ نیمه‌کاره.
//
// مقادیر در جدول settings ذخیره می‌شوند تا از داخل تلگرام عوض شوند؛
// نبودنِ کلید = پیش‌فرض (approval روشن، maintenance خاموش) تا نصبِ تازه
// دقیقاً مثل رفتار قبلی باشد.

require_once __DIR__ . '/Ui.php';
require_once __DIR__ . '/Texts.php';

class BuildSettings
{
    /** کلیدهای نگهداری وضعیت در جدول settings */
    public const K_APPROVAL  = 'build_require_approval';
    public const K_MAINT     = 'build_maintenance';
    public const K_MAINT_ETA = 'build_maintenance_eta';

    /** پیش‌فرض‌ها */
    private const DEF_APPROVAL = '1';
    private const DEF_MAINT    = '0';

    // ---------- کمکی: خواندن/نوشتن کلید بولی ----------

    private static function flag(Store $store, string $key, string $def): bool
    {
        $raw = strtolower(trim((string)($store->getSetting($key, $def) ?? $def)));
        if ($raw === '') return $def === '1';
        return !in_array($raw, ['0', 'off', 'false', 'no', 'disabled', 'خاموش'], true);
    }

    private static function setFlag(Store $store, string $key, bool $on): void
    {
        $store->setSetting($key, $on ? '1' : '0');
    }

    // ---------- ۱) تأیید ادمین ----------

    /** آیا ساخت ربات نیاز به تأیید ادمین دارد؟ (پیش‌فرض: بله) */
    public static function approvalRequired(Store $store): bool
    {
        return self::flag($store, self::K_APPROVAL, self::DEF_APPROVAL);
    }

    public static function setApprovalRequired(Store $store, bool $on): void
    {
        self::setFlag($store, self::K_APPROVAL, $on);
    }

    /** برعکسش را برمی‌گرداند تا دکمهٔ روشن/خاموش همیشه کار کند */
    public static function toggleApproval(Store $store): bool
    {
        $new = !self::approvalRequired($store);
        self::setApprovalRequired($store, $new);
        return $new;
    }

    // ---------- ۲) حالت تعمیرات ----------

    public static function maintenanceOn(Store $store): bool
    {
        return self::flag($store, self::K_MAINT, self::DEF_MAINT);
    }

    public static function setMaintenance(Store $store, bool $on): void
    {
        self::setFlag($store, self::K_MAINT, $on);
    }

    public static function toggleMaintenance(Store $store): bool
    {
        $new = !self::maintenanceOn($store);
        self::setMaintenance($store, $new);
        return $new;
    }

    /** متن پیام تعمیرات در خودِ ماژول متن‌ها نگه داشته می‌شود (کلید 'maintenance')
     * تا فقط یک جای ویرایش داشته باشد؛ اینجا فقط خواننده‌اش هستیم. */
    public static function maintenanceText(Store $store): string
    {
        return Texts::text($store, 'maintenance');
    }

    /**
     * زمانِ تخمینی بازگشت — اختیاری و فقط نمایشی.
     * مقدارش در متنِ «maintenance» با جایگذین ‹eta› می‌نشیند.
     */
    public static function maintenanceEta(Store $store): string
    {
        return trim((string)($store->getSetting(self::K_MAINT_ETA, '') ?? ''));
    }

    public static function setMaintenanceEta(Store $store, string $eta): void
    {
        $eta = trim(preg_replace('/[\x00-\x1F]/u', ' ', $eta) ?? '');
        if (mb_strlen($eta) > 60) $eta = mb_substr($eta, 0, 60);
        $store->setSetting(self::K_MAINT_ETA, $eta);
    }

    /**
     * پیام تعمیرات برای کاربر.
     * متن از ماژول Texts خوانده می‌شود (و همان‌جا قابل ویرایش است) و جایگذین
     * ‹eta› با زمانِ تخمینی پر می‌شود. اگر ادمین متن را خالی گذاشته باشد، یک متنِ
     * حداقلی می‌آید تا هیچ‌وقت پیام خالی/خراب به کاربر نرسد.
     */
    public static function maintenanceNotice(Store $store): string
    {
        $eta = self::maintenanceEta($store);
        $vars = ['eta' => $eta !== '' ? $eta : 'به‌زودی'];
        $t = Texts::get($store, 'maintenance', $vars);
        if (trim(strip_tags((string)$t)) !== '') return Ui::out((string)$t);

        return Ui::out(
            "🔧 <b>قسمت ربات در حال تعمیر است</b>\n\n"
            . "لطفاً کمی بعد دوباره سر بزنید.\n"
            . "زمان تقریبی بازگشت: <b>" . Ui::e($vars['eta']) . "</b>\n\n"
            . "بابت مزاحمت متأسفیم 🙏"
        );
    }

    // ---------- نمایش در پنل تنظیمات ----------

    /** سطر «🟢/🔴 عنوان — توضیح» برای پنل ادمین */
    public static function statusLine(Store $store, bool $on, string $label, string $hint): string
    {
        $mark = $on ? '🟢 فعال' : '🔴 غیرفعال';
        return $mark . ' — ' . $label . "\n   ↳ <i>" . Ui::e($hint) . '</i>';
    }

    /**
     * آیا این کاربرِ غیرادمین می‌تواند بدون ساختن «درخواست» وارد مسیر ساخت شود؟
     * (تابع isAdmin عمداً صدا زده نمی‌شود: این فایل در endpointهای IPN هم لود می‌شود
     *  که آن‌جا تابع‌های سراسریِ bot.php وجود ندارند؛ تفکیکِ ادمین در bot.php انجام می‌شود.)
     */
    public static function skipApproval(Store $store): bool
    {
        return !self::approvalRequired($store);
    }
}