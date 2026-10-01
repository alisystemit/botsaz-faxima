<?php
// ===== ماژول لیمیت ساخت ربات =====
// تنها منبع حقیقت برای سقف تعداد ربات هر کاربر.
// bot_limit در جدول users: مقدار 1- یعنی نامحدود، 0 یعنی مسدود، N>0 یعنی سقف.
// ادمین (is_admin=1 یا super_admins) همیشه نامحدود است — بدون نیاز به تنظیم دستی.

class PaymentLimits
{
    public const UNLIMITED = -1;
    public const DEFAULT_LIMIT = 1;

    /** لیمیت مؤثر کاربر؛ ادمین همیشه نامحدود */
    public static function getLimit(Store $store, array $user, array $supers = []): int
    {
        if (self::isAdminUnlimited($user, $supers)) return self::UNLIMITED;
        return Payments::getUserLimit($store, (int)($user['user_id'] ?? 0));
    }

    /** آیا کاربر ادمینِ نامحدود است؟ */
    public static function isAdminUnlimited(array $user, array $supers = []): bool
    {
        if ((int)($user['is_admin'] ?? 0) === 1) return true;
        foreach ($supers as $s) {
            if ((string)$s === (string)($user['user_id'] ?? '')) return true;
        }
        return false;
    }

    /** تعداد ربات‌های فعلی کاربر */
    public static function botCount(Store $store, int $uid): int
    {
        return count($store->myBots($uid));
    }

    /** آیا درگاه لیمیت فعال است؟ خاموش‌کردن آن یعنی سقف اصلاً اعمال نشود */
    public static function isActive(Store $store): bool
    {
        return PaymentGateways::isEnabled($store, PaymentGateways::LIMIT);
    }

    public static function canBuild(Store $store, array $user, array $supers = []): bool
    {
        if (self::isAdminUnlimited($user, $supers)) return true;
        // اگر درگاه لیمیت غیرفعال باشد، کاربر نمی‌تواند افزایش لیمیت بخرد
        if (!self::isActive($store)) return true; // درگاه خاموش ⇒ سقف اصلاً اعمال نمی‌شود
        $limit = Payments::getUserLimit($store, (int)$user['user_id']);
        if ($limit < 0) return true;
        return self::botCount($store, (int)$user['user_id']) < $limit;
    }

    /** چند اسلات خالی مانده؟ نامحدود => -1 */
    public static function remaining(Store $store, array $user, array $supers = []): int
    {
        if (self::isAdminUnlimited($user, $supers)) return self::UNLIMITED;
        // اگر درگاه لیمیت غیرفعال باشد، کاربر نمی‌تواند افزایش لیمیت بخرد
        if (!self::isActive($store)) return self::UNLIMITED; // درگاه خاموش ⇒ محدودیتی نیست
        $limit = Payments::getUserLimit($store, (int)$user['user_id']);
        if ($limit < 0) return self::UNLIMITED;
        return max(0, $limit - self::botCount($store, (int)$user['user_id']));
    }

    public static function formatLimit(int $limit): string
    {
        return $limit < 0 ? 'نامحدود ♾️' : (string)$limit;
    }
}
