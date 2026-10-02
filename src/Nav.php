<?php
// ===== ماژول یکپارچه ناوبری (انصراف / برگشت / منو) =====
// تنها منبع حقیقت برای دکمه‌های ناوبری در کل ربات.
// همهٔ مسیرها (پیام و کال‌بک، همهٔ stepها و پنل‌ها) از همین‌جا تغذیه می‌شوند
// تا رفتار انصراف/برگشت همه‌جا یکسان و بدون باگ باشد.

class Nav
{
    public const CANCEL = '❌ انصراف';
    public const BACK = '↩️ برگشت';
    public const MENU = '🏠 منو';

    public const CB_CANCEL = 'cancel';
    public const CB_BACK_MAIN = 'back:main';
    public const CB_BACK_TYPE = 'back:type';
    public const CB_BACK_USERS = 'back:users';
    public const CB_BACK_BACKUP = 'back:backup';
    public const CB_MY_BOTS = 'mybots:list';

    public static function isCancel(string $text): bool
    {
        return $text === self::CANCEL || $text === '/cancel';
    }

    public static function isBack(string $text): bool
    {
        return $text === self::BACK;
    }

    public static function isMenu(string $text): bool
    {
        return $text === self::MENU || $text === '/menu';
    }

    public static function isNav(string $text): bool
    {
        return self::isCancel($text) || self::isBack($text) || self::isMenu($text);
    }

    /** کیبورد ریپلای استاندارد داخل همهٔ stepها: برگشت + انصراف + منو */
    public static function stepKb(): string
    {
        return BotApi::kb([
            [['text' => self::BACK], ['text' => self::CANCEL]],
            [['text' => self::MENU]],
        ]);
    }

    /**
     * منوی انتخاب نوع ربات — همیشه دارای انصراف و برگشت.
     *
     * از رجیستری قالب‌ها ساخته می‌شود، پس افزودن قالب تازه فقط ویرایش
     * Manager::templates() است. قالبی که روی سرور نباشد اصلاً نشان داده نمی‌شود
     * (وگرنه کاربر دکمه می‌زند و با خطای «قالب روی سرور نیست» روبه‌رو می‌شود).
     */
    public static function typeMenu(): string
    {
        $rows = [];
        foreach (Manager::availableTypes() as $key => $label) {
            $spec = Manager::templateSpec($key);
            $icon = (string)($spec['icon'] ?? '🤖');
            $rows[] = [['text' => $icon . ' ' . $label, 'callback_data' => 'newbot:' . $key]];
        }
        if ($rows === []) {
            $rows[] = [['text' => '⚠️ هیچ قالبی روی سرور نصب نیست', 'callback_data' => 'noop']];
        }
        $rows[] = [['text' => self::BACK, 'callback_data' => self::CB_BACK_MAIN],
                   ['text' => self::CANCEL, 'callback_data' => self::CB_CANCEL]];
        return BotApi::ikb($rows);
    }

    /** پنل مدیریت کاربران مجاز — همیشه دارای برگشت */
    public static function usersPanelKb(): string
    {
        return BotApi::ikb([
            [['text' => '➕ افزودن کاربر', 'callback_data' => 'users:add'], ['text' => '➖ حذف کاربر', 'callback_data' => 'users:remove']],
            [['text' => '📃 لیست', 'callback_data' => 'users:list']],
            [['text' => self::BACK, 'callback_data' => self::CB_BACK_MAIN]],
        ]);
    }

    /** لیست «ربات‌های من» + ردیف برگشت (ورودی: ردیف‌های ربات، خروجی: ikb آماده) */
    public static function myBotsKb(array $botRows): string
    {
        $botRows[] = [['text' => self::BACK, 'callback_data' => self::CB_BACK_MAIN]];
        return BotApi::ikb($botRows);
    }

    /** کیبورد پنل یک ربات + بازگشت به لیست */
    public static function botPanelKb(array $bot): string
    {
        $toggle = ($bot['status'] ?? 'active') === 'active' ? '🔴 غیرفعال' : '🟢 فعال‌سازی';
        return BotApi::ikb([
            [['text' => '📊 آمار', 'callback_data' => "act:stats:{$bot['id']}"], ['text' => '📣 همگانی', 'callback_data' => "act:broadcast:{$bot['id']}"]],
            [['text' => '🔗 ست مجدد وبهوک', 'callback_data' => "act:webhook:{$bot['id']}"], ['text' => $toggle, 'callback_data' => "act:toggle:{$bot['id']}"]],
            [['text' => '🗑 حذف ربات', 'callback_data' => "act:delask:{$bot['id']}"]],
            [['text' => '↩️ بازگشت به لیست', 'callback_data' => self::CB_MY_BOTS]],
        ]);
    }

    /** کیبورد پنل بکاپ + برگشت */
    public static function backupPanelKb(bool $enabled): string
    {
        return BotApi::ikb([
            [['text' => $enabled ? '🔴 غیرفعال' : '🟢 فعال‌سازی', 'callback_data' => 'backup:toggle']],
            [['text' => '2 بار در روز (۳ و ۱۵)', 'callback_data' => 'backup:preset2']],
            [['text' => '1 بار در روز (۳ صبح)', 'callback_data' => 'backup:preset1']],
            [['text' => '🕐 ساعت دلخواه', 'callback_data' => 'backup:custom']],
            [['text' => '▶️ بکاپ الان', 'callback_data' => 'backup:now'], ['text' => '🔄 بروزرسانی', 'callback_data' => 'backup:refresh']],
            [['text' => self::BACK, 'callback_data' => self::CB_BACK_MAIN]],
        ]);
    }

    /**
     * مقصد «برگشت» برای هر step — خالص و بدون وابستگی، تا قابل تست بماند.
     * kind: type (انتخاب نوع) | step (یک مرحله قبل) | menu | users | backup | bot
     */
    public static function backTarget(string $step): array
    {
        switch ($step) {
            case 'await_bot_token': return ['kind' => 'type'];
            case 'await_admin_id': return ['kind' => 'step', 'step' => 'await_bot_token'];
            case 'await_folder': return ['kind' => 'step', 'step' => 'await_admin_id'];
            case 'await_user_add':
            case 'await_user_remove': return ['kind' => 'users'];
            case 'await_backup_times': return ['kind' => 'backup'];
            case 'await_child_broadcast': return ['kind' => 'bot'];
            // ===== پرداخت =====
            // رسید کارت: فروشگاه لیمیت (کاربر) — مراحل تنظیم ادمین: پنل پرداخت
            case 'await_card_receipt': return ['kind' => 'shop'];
            case 'await_pay_text':
            case 'await_pay_price':
            case 'await_pay_limit_price':
            case 'await_pay_usdrate':
            case 'await_pay_card':
            case 'await_pay_card_owner':
            case 'await_pay_nowpay_key':
            case 'await_pay_nowpay_secret':
            case 'await_pay_setlimit': return ['kind' => 'payments'];
            case 'await_broadcast':
            default: return ['kind' => 'menu'];
        }
    }
}
