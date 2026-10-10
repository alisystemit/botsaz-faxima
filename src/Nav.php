<?php
// ===== ماژول یکپارچه ناوبری (انصراف / برگشت / منو) =====
// تنها منبع حقیقت برای دکمه‌های ناوبری در کل ربات.
// همهٔ مسیرها (پیام و کال‌بک، همهٔ stepها و پنل‌ها) از همین‌جا تغذیه می‌شوند
// تا رفتار انصراف/برگشت همه‌جا یکسان و بدون باگ باشد.

require_once __DIR__ . '/BuildSettings.php';

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

    /**
     * نام‌های مستعار متنی: کاربران ایرانی معمولاً به‌جای دکمه تایپ می‌کنند.
     * قبلاً فقط «❌ انصراف» دقیقاً match می‌شد؛ تایپ «انصراف»/«لغو»/«cancel»
     * به «دستور نامعتبر» می‌خورد و کاربر وسط مرحله گیر می‌کرد.
     * این متن‌ها عمداً کوتاه و کم‌برخورد هستند تا با ورودی عادی تداخل نکنند.
     */
    private const CANCEL_WORDS = ['انصراف', 'لغو', 'لغو کن', 'cancel', 'stop', '/stop', '/cancel'];
    private const BACK_WORDS   = ['برگشت', 'بازگشت', 'بازگشت به عقب', 'back', '/back'];
    private const MENU_WORDS   = ['منو', 'منوی اصلی', 'menu', '/menu', '/home', '/main'];

    public static function isCancel(string $text): bool
    {
        if (in_array($text, self::CANCEL_WORDS, true)) return true;
        return $text === self::CANCEL;
    }

    public static function isBack(string $text): bool
    {
        if (in_array($text, self::BACK_WORDS, true)) return true;
        return $text === self::BACK;
    }

    public static function isMenu(string $text): bool
    {
        if (in_array($text, self::MENU_WORDS, true)) return true;
        return $text === self::MENU;
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

    /**
     * پنل مدیریت کاربران مجاز — همیشه دارای برگشت.
     * دکمهٔ «📋 درخواست‌ها» اضافه شد چون با «ساخت بدون درخواست» خاموش، بیشتر
     * وقت‌ها صفِ درخواست‌ها خالی است و ادمین باید یک راهِ دیدنِ آن داشته باشد.
     */
    public static function usersPanelKb(): string
    {
        return BotApi::ikb([
            [['text' => '➕ افزودن کاربر', 'callback_data' => 'users:add'], ['text' => '➖ حذف کاربر', 'callback_data' => 'users:remove']],
            [['text' => '📃 لیست کاربران', 'callback_data' => 'users:list'], ['text' => '📋 درخواست‌ها', 'callback_data' => 'users:requests']],
            [['text' => self::BACK, 'callback_data' => self::CB_BACK_MAIN]],
        ]);
    }

    /** لیست «ربات‌های من» + ردیف برگشت (ورودی: ردیف‌های ربات، خروجی: ikb آماده) */
    public static function myBotsKb(array $botRows): string
    {
        $botRows[] = [['text' => self::BACK, 'callback_data' => self::CB_BACK_MAIN]];
        return BotApi::ikb($botRows);
    }

    /**
     * کیبورد پنل یک ربات + بازگشت به لیست.
     *
     * «✏️ ویرایش توکن» و «✏️ ویرایش آیدی ادمین» اضافه شدند چون توکن ربات‌ها
     * مرتب عوض می‌شود (چرخش توکن در @BotFather) و قبلاً تنها راه، حذف کامل ربات
     * و ساخت دوباره بود — یعنی از دست رفتن کاربران و تنظیمات.
     *
     * دکمهٔ «دریافت سورس بروز» قبلاً حذف شده بود: بروزرسانی روی ربات‌های
     * ساخته‌شده انجام نمی‌شود و از پنل سوپرادمین روی templates/ انجام می‌گیرد.
     */
    public static function botPanelKb(array $bot, bool $isAdmin = false): string
    {
        $id = (int)$bot['id'];
        $toggle = ($bot['status'] ?? 'active') === 'active' ? '🔴 غیرفعال' : '🟢 فعال‌سازی';
        $rows = [
            [['text' => '📊 آمار', 'callback_data' => "act:stats:{$id}"], ['text' => '📣 همگانی', 'callback_data' => "act:broadcast:{$id}"]],
            [['text' => '✏️ ویرایش توکن', 'callback_data' => "act:edittoken:{$id}"], ['text' => '✏️ ویرایش آیدی ادمین', 'callback_data' => "act:editadmin:{$id}"]],
            [['text' => '🔗 ست مجدد وبهوک', 'callback_data' => "act:webhook:{$id}"], ['text' => $toggle, 'callback_data' => "act:toggle:{$id}"]],
        ];
        $rows[] = [['text' => '🗑 حذف ربات', 'callback_data' => "act:delask:{$id}"]];
        $rows[] = [['text' => '↩️ بازگشت به لیست', 'callback_data' => self::CB_MY_BOTS]];
        return BotApi::ikb($rows);
    }

    /**
     * کیبورد پنل «⚙️ تنظیمات» — همهٔ کلیدهای روشن/خاموشِ ربات‌ساز یک‌جا.
     * دو کلید اصلی: «حالت تعمیرات» و «ساخت بدون درخواست (نیاز به تأیید)».
     */
    public static function settingsPanelKb(Store $store): string
    {
        $approval = BuildSettings::approvalRequired($store);
        $maint = BuildSettings::maintenanceOn($store);
        return BotApi::ikb([
            [
                ['text' => ($maint ? '🟢 روشن' : '🔴 خاموش') . ' — حالت تعمیرات', 'callback_data' => 'set:maintenance'],
                ['text' => ($approval ? '🟢 روشن' : '🔴 خاموش') . ' — نیاز به تأیید', 'callback_data' => 'set:approval'],
            ],
            [['text' => '📝 متن پیام تعمیرات', 'callback_data' => 'set:mainttext']],
            [['text' => '⏳ زمان تقریبی بازگشت', 'callback_data' => 'set:maintaineta']],
            [['text' => '🔄 بروزرسانی نرخ دلار از API', 'callback_data' => 'payadmin:fxrefresh']],
            [['text' => '💳 مدیریت پرداخت‌ها و درگاه‌ها', 'callback_data' => 'payadmin:panel']],
            [['text' => '🏢 اکانت سازندهٔ پنل', 'callback_data' => 'set:childowner']],
            [['text' => '📝 متن‌های ربات', 'callback_data' => 'texts:g:general']],
            [['text' => self::BACK, 'callback_data' => self::CB_BACK_MAIN]],
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
     * kind: type (انتخاب نوع) | step (یک مرحله قبل) | menu | users | backup | bot | texts
     */
    public static function backTarget(string $step): array
    {
        switch ($step) {
            case 'await_bot_token': return ['kind' => 'type'];
            case 'await_admin_id': return ['kind' => 'step', 'step' => 'await_bot_token'];
            case 'await_folder': return ['kind' => 'step', 'step' => 'await_admin_id'];
            // ===== ویرایش هویتِ رباتِ ساخته‌شده ⇒ برگشت به پنل همان ربات =====
            case 'await_edit_bot_token':
            case 'await_edit_admin_id': return ['kind' => 'bot'];
            // ===== پنل تنظیمات (کلیدهای روشن/خاموش و متن تعمیرات) =====
            case 'await_maintenance_text':
            case 'await_maintenance_eta': return ['kind' => 'settings'];
            case 'await_child_owner':     return ['kind' => 'settings'];
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
            case 'await_pay_zarin_merchant':
            case 'await_pay_aqaye_pin':
            case 'await_pay_setlimit': return ['kind' => 'payments'];
            // ویرایش متن‌های پویا ⇒ برگشت به فهرست گروه‌ها
            case 'await_text_edit': return ['kind' => 'texts'];
            case 'await_broadcast':
            default: return ['kind' => 'menu'];
        }
    }
}
