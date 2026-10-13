<?php
/**
 * سوپرادمین‌های چندتایی.
 *
 * منبع اصلی همیشه config.php است ('super_admins'). تا پیش از این، افزودن
 * سوپرادمین یعنی ویرایش فایل کانفیگ روی سرور. این کلاس یک لیست دوم در جدول
 * settings (کلید super_admins) نگه می‌دارد تا ادمین از پنل (مینی‌اپ) بتواند
 * کاربر دیگر را سوپرادمین کند یا برگرداند — بدون ssh و بدون ریستارت.
 *
 * قواعد:
 *   • لیست کانفیگ از پنل قابل حذف نیست (فایل است، نه دیتابیس).
 *   • آخرین سوپرادمین باقی‌مانده حذف نمی‌شود (جلوگیری از قفل‌شدن پنل).
 *   • مؤثر = کانفیگ + دیتابیس (یکتا، عددی).
 */
class SuperAdmins
{
    public const SETTING_KEY = 'super_admins';

    /** یکتا + عددی + مثبت */
    public static function normalize(array $ids): array
    {
        $seen = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) $seen[$n] = true;
        }
        return array_keys($seen);
    }

    /** لیست کانفیگ (فقط config.php) */
    public static function fromConfig(array $cfg): array
    {
        return self::normalize((array)($cfg['super_admins'] ?? []));
    }

    /** لیست ذخیره‌شده در دیتابیس — افزوده‌شده از پنل */
    public static function runtime(Store $store): array
    {
        $raw = trim((string)($store->getSetting(self::SETTING_KEY, '') ?? ''));
        if ($raw === '') return [];
        $list = json_decode($raw, true);
        return is_array($list) ? self::normalize($list) : [];
    }

    /** لیست مؤثر: کانفیگ + دیتابیس */
    public static function effective(array $cfg, Store $store): array
    {
        return self::normalize(array_merge(self::fromConfig($cfg), self::runtime($store)));
    }

    /**
     * افزودن سوپرادمین از پنل.
     *
     * @return array{ok:bool, error:string}
     */
    public static function add(array $cfg, Store $store, int $uid): array
    {
        if ($uid <= 0) return ['ok' => false, 'error' => 'آیدی عددی معتبر نیست'];
        if (in_array($uid, self::fromConfig($cfg), true)) {
            return ['ok' => false, 'error' => 'این آیدی از قبل در config.php سوپرادمین است'];
        }
        $list = self::runtime($store);
        if (!in_array($uid, $list, true)) $list[] = $uid;
        $store->setSetting(self::SETTING_KEY, json_encode(self::normalize($list), JSON_UNESCAPED_UNICODE));
        return ['ok' => true, 'error' => ''];
    }

    /**
     * حذف سوپرادمینِ افزوده‌شده از پنل.
     * سوپرادمینِ کانفیگ حذف نمی‌شود؛ آخرین سوپرادمینِ باقی‌مانده هم حذف نمی‌شود.
     *
     * @return array{ok:bool, error:string}
     */
    public static function remove(array $cfg, Store $store, int $uid): array
    {
        if ($uid <= 0) return ['ok' => false, 'error' => 'آیدی عددی معتبر نیست'];
        if (in_array($uid, self::fromConfig($cfg), true)) {
            return ['ok' => false, 'error' => 'این سوپرادمین در config.php تعریف شده — برای حذف باید config.php را ویرایش کنید'];
        }
        $list = self::runtime($store);
        if (!in_array($uid, $list, true)) {
            return ['ok' => false, 'error' => 'این آیدی در فهرست سوپرادمین‌های پنل نیست'];
        }
        $next = array_values(array_diff($list, [$uid]));
        if (self::normalize(array_merge(self::fromConfig($cfg), $next)) === []) {
            return ['ok' => false, 'error' => 'حداقل یک سوپرادمین باید بماند'];
        }
        $store->setSetting(self::SETTING_KEY, json_encode(self::normalize($next), JSON_UNESCAPED_UNICODE));
        return ['ok' => true, 'error' => ''];
    }
}
