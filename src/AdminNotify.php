<?php
// ===== اعلان رویدادهای مهم به ادمین‌ها =====
//
// چرا این کلاس: «درخواست ساخت ربات» و «فاکتور جدید» رویدادهایی هستند که اگر
// ادمین خودش دستی چک نکند، ممکن است ساعت‌ها/روزها معلق بمانند. مسیرهای
// ساخت فاکتور و ثبت درخواست فقط «کلاس Store/Payments» را صدا می‌زنند و آن‌ها
// به تنظیمات دسترسی ندارند، پس اعلان از همان نقاط فراخوانی می‌شود.
//
// نکتهٔ مهم: اعلان هرگز نباید مسیر اصلی ربات را خراب کند ⇒ همه‌چیز در try.

require_once __DIR__ . '/BotApi.php';

class AdminNotify
{
    /**
     * پیام به همهٔ سوپرادمین‌ها. اگر تابع notifySupers ربات‌ساز در دسترس باشد
     * (bot.php آن را تعریف می‌کند) از همان استفاده می‌شود تا دو مسیر موازی نداشته باشیم.
     */
    public static function notify(?array $cfg, string $text): void
    {
        try {
            if (!is_array($cfg)) return;
            $token = (string)($cfg['main_token'] ?? '');
            if ($token === '' || $token === 'YOUR_BOT_TOKEN_FROM_BOTFATHER') return;
            $supers = array_values(array_filter(array_map('intval', (array)($cfg['super_admins'] ?? []))));
            if (!$supers) return;

            if (function_exists('notifySupers')) {
                notifySupers($token, $supers, $text);
                return;
            }
            foreach ($supers as $uid) {
                try { BotApi::send($token, $uid, $text); } catch (Throwable $e) { /* بی‌صدا */ }
            }
        } catch (Throwable $e) { /* اعلان هرگز مسیر اصلی را خراب نمی‌کند */ }
    }
}