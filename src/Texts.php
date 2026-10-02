<?php
// ===== ماژول متن‌های قابل ویرایش ربات =====
// هر متنی که کاربر/ادمین می‌بیند باید از همین‌جا خوانده شود تا مدیر ربات بتواند
// از داخل خود تلگرام (دکمهٔ «📝 متن‌ها» در منوی ادمین) آن را ویرایش کند.
//
// قاعده‌ها:
//  ۱) مقدار دلخواه در جدول settings با کلید «txt_<key>» نگه داشته می‌شود؛
//     خالی‌بودن یعنی متن پیش‌فرض ⇒ رفتارِ قبل از هر ویرایشی دست‌نخورده می‌ماند.
//  ۲) جایگذین‌ها با «‹نام›» نوشته می‌شوند و هنگام نمایش با مقدار واقعی عوض
//     می‌شوند. «‹nav›» مخصوص خط «برگشت / انصراف» است تا ادمین بتواند آن را هم
//     حذف یا ویرایش کند.
//  ۳) متن‌های گروه «پرداخت» در PaymentGateways ذخیره می‌شوند (قدیمی‌ترین ساز و
//     کارِ ویرایش متن) و اینجا فقط از یک رابط واحد نمایش داده می‌شوند.
//  ۴) هیچ‌وقت نباید جایگذینِ جای‌مانده به کاربر برسد: «get» باقی‌مانده را حذف
//     می‌کند و علت (کلید + جایگذین‌ها) را در لاگ می‌نویسد؛ هشدارِ همان موضوع
//     هنگام ذخیره به ادمین نشان داده می‌شود.

class Texts
{
    /** پیشوند کلید ذخیره‌سازی در جدول settings */
    public const SETTING_PREFIX = 'txt_';

    /** متن‌هایی که عملاً در PaymentGateways نگهداری می‌شوند */
    public const ENGINE_GATEWAYS = 'gateways';

    /** سقف طول متن ذخیره‌شده (پیام تلگرام ۴۰۹۶ کاراکتر است) */
    public const MAX_LEN = 4000;

    private const GROUPS = [
        'general' => ['icon' => '🌐', 'label' => 'عمومی'],
        'build'   => ['icon' => '🤖', 'label' => 'ساخت ربات'],
        'manage'  => ['icon' => '📣', 'label' => 'مدیریت'],
        'payment' => ['icon' => '💳', 'label' => 'پرداخت'],
    ];

    /** مقدار نمونهٔ هر جایگذین — فقط برای پیش‌نمایش در پنل ادمین */
    private const SAMPLES = [
        'role'          => 'مدیر 👑',
        'types'         => 'فاکسیما، میرزا',
        'type'          => 'فاکسیما',
        'username'      => 'mybot',
        'slug'          => 'shop1',
        'db'            => 'botsaz_shop1',
        'folder'        => 'shop1',
        'input'         => 'سلام',
        'error'         => 'اتصال به دیتابیس برقرار نشد (mysql:3306)',
        'ok'            => '10',
        'total'         => '12',
        'skipped_note'  => "\n⚠️ ۲ نفر دریافت نکردند (ربات را بلاک کرده‌اند).",
        'amount'        => '۲۵۰,۰۰۰',
        'slots'         => '۵',
        'count'         => '۲',
        'limit'         => '۱۰',
        'remaining'     => '۳',
        // بخش‌های کمکی راهنما
        'payment'       => "\n\n💳 <b>پرداخت</b>\nسقف ساخت ربات و بعضی قالب‌ها پولی است.",
        'diag'          => 'لاگ کامل هم در <code>data/logs/</code> و «🔍 دیاگنوز» است.',
        'contact'       => "@my_admin • کانال: @my_channel",
    ];

    /**
     * رجیستری متن‌ها (کاملاً تنبل: فقط یک‌بار ساخته می‌شود).
     * ساختنِ دنباله‌ها با implode اینجا انجام می‌شود چون عبارت const در PHP
     * نمی‌تواند تابع صدا بزند.
     *
     * هر مورد: group / label / vars (نام => توضیح) / default / engine?
     */
    private static function registry(): array
    {
        static $reg = null;
        if ($reg !== null) return $reg;

        $nav = self::navHint();

        $reg = [
            // ---------- عمومی ----------
            'welcome' => [
                'group'  => 'general',
                'label'  => 'پیام خوش‌آمد (‎/start)',
                'vars'   => ['role' => 'نقش کاربر', 'types' => 'فهرست قالب‌های موجود'],
                'default' => implode("\n", [
                    '👋 سلام! به <b>ربات‌ساز</b> خوش آمدی.',
                    'نقش شما: <b>‹role›</b>',
                    '',
                    '🤖 <b>اینجا چه کاری انجام می‌شود؟</b>',
                    'در چند ثانیه یک ربات تلگرامی کامل (پوشه + دیتابیس + کانفیگ + وبهوک) می‌سازی؛ قالب‌های موجود: ‹types›.',
                    '',
                    '🧩 <b>مراحل ساخت:</b>',
                    '1️⃣ «🤖 ساخت ربات جدید» → انتخاب قالب',
                    '2️⃣ توکن ربات از @BotFather',
                    '3️⃣ آیدی عددی ادمین (از @userinfobot)',
                    '4️⃣ یک نام انگلیسی کوتاه برای پوشه',
                    '',
                    'با دکمه‌های زیر همه‌چیز را مدیریت کن؛ هرجا گیر کردی «' . Nav::BACK . '» یا «' . Nav::CANCEL . '» بزن.',
                ]),
            ],
            'help' => [
                'group'  => 'general',
                'label'  => 'راهنما (‎/help و ℹ️ راهنما)',
                'vars'   => [
                    'payment' => 'بخش پرداخت (فقط وقتی درگاهی فعال است)',
                    'diag'    => 'اشاره به لاگ/دیاگنوز (فقط برای ادمین)',
                    'contact' => 'راه‌های ارتباطی (متن بخش «ارتباط با ادمین»)',
                ],
                'default' => implode("\n", [
                    '📖 <b>راهنمای کامل ربات‌ساز</b>',
                    '',
                    '🧱 <b>پیش‌نیازها</b>',
                    '• یک ربات خالی از @BotFather با دستور /newbot بساز و توکن را کپی کن.',
                    '• آیدی عددی ادمین را از @userinfobot بگیر (عدد بزرگ، نه @یوزرنیم).',
                    '',
                    '🛠 <b>مراحل ساخت</b>',
                    '1️⃣ «🤖 ساخت ربات جدید» → انتخاب قالب (فاکسیما/میرزا/آپ‌تایم/پاسارگاد).',
                    '2️⃣ توکن ربات را بفرست (توکن رمزگاری‌شده ذخیره می‌شود).',
                    '3️⃣ آیدی عددی ادمین را بفرست.',
                    '4️⃣ یک نام انگلیسی کوتاه برای پوشه بده (حروف/اعداد لاتین؛ نام‌های <code>backups</code> و <code>states</code> رزروند).',
                    '5️⃣ ربات‌ساز خودش پوشه + دیتابیس + کانفیگ + وبهوک را می‌سازد و در صورت خطا، همهٔ منابع را برمی‌گرداند (rollback).',
                    '',
                    '📦 <b>مدیریت ربات‌ها</b> از «📦 ربات‌های من»:',
                    '📊 آمار کاربران • 📣 پیام همگانی • 🔗 ست‌کردن مجدد وبهوک • 🔴/🟢 غیرفعال‌سازی • 🗑 حذف (با بکاپ قبلی).',
                    '',
                    '🧭 <b>دستورات</b>',
                    '<code>/start</code> منوی اصلی • <code>/mybots</code> ربات‌های من • <code>/help</code> همین راهنما • <code>/stats</code> آمار • <code>/cancel</code> انصراف • <code>/menu</code> منو.',
                    '',
                    '⚠️ <b>امنیت</b>',
                    'توکن ربات را به هیچ‌کس نده؛ ربات‌ساز هم هرگز توکن ربات دیگران را نمی‌پرسد.',
                    '',
                    '🩹 <b>اگر جایی خطا دیدی</b>',
                    'پیام خطا شامل «بخش + علت + محل دقیق (فایل:خطا)» است؛ همان را بفرست. ‹diag›',
                    '‹contact›',
                    '‹payment›',
                ]),
            ],
            'contact' => [
                'group'  => 'general',
                'label'  => 'ارتباط با ادمین (زیرمجموعهٔ راهنما)',
                'vars'   => [],
                'default' => '',
            ],
            'denied' => [
                'group'  => 'general',
                'label'  => 'پیام «دسترسی ندارید»',
                'vars'   => [],
                'default' => implode("\n", [
                    '⛔️ دسترسی ندارید.',
                    'برای استفاده از ربات‌ساز ابتدا «🤖 ساخت ربات جدید» را بزنید تا درخواستتان ثبت و تأیید شود.',
                ]),
            ],
            'invalid' => [
                'group'  => 'general',
                'label'  => 'پیام «دستور نامعتبر»',
                'vars'   => ['input' => 'متنی که کاربر فرستاده (برای عیب‌یابی)'],
                'default' => implode("\n", [
                    '⛔️ دستور نامعتبر است.',
                    'دریافت‌شده: ‹input›',
                    'از دکمه‌های منو استفاده کن یا «ℹ️ راهنما» را بزن.',
                ]),
            ],
            'request_pending' => [
                'group'  => 'general',
                'label'  => 'ثبت درخواستِ در انتظار تأیید',
                'vars'   => [],
                'default' => implode("\n", [
                    '📝 درخواست شما ثبت شد.',
                    'لطفاً منتظر تأیید ادمین بمانید.',
                    'پس از تأیید می‌توانید ساخت (و در صورت نیاز خرید) را انجام دهید.',
                ]),
            ],
            'request_pending_again' => [
                'group'  => 'general',
                'label'  => 'درخواستِ تکراری (قبلاً ثبت شده)',
                'vars'   => [],
                'default' => implode("\n", [
                    '⏳ درخواست شما قبلاً ثبت شده و در انتظار تأیید ادمین است.',
                    'تا تأیید، امکان خرید اسلات یا مجوز قالب وجود ندارد.',
                    'لطفاً صبر کنید.',
                ]),
            ],
            'request_approved' => [
                'group'  => 'general',
                'label'  => 'تأیید درخواستِ کاربر توسط ادمین',
                'vars'   => [],
                'default' => '🎉 درخواستت تأیید شد! حالا «🤖 ساخت ربات جدید» را بزن و نوع ربات را انتخاب کن.',
            ],

            // ---------- ساخت ربات ----------
            'type_prompt' => [
                'group'  => 'build',
                'label'  => 'انتخاب نوع ربات',
                'vars'   => [],
                'default' => 'نوع ربات را انتخاب کن 👇',
            ],
            'token_prompt' => [
                'group'  => 'build',
                'label'  => 'درخواست توکن ربات',
                'vars'   => ['type' => 'نام قالب انتخاب‌شده'],
                'default' => "توکن ربات <b>‹type›</b> را بفرست.\n" . $nav,
            ],
            'token_ok' => [
                'group'  => 'build',
                'label'  => 'توکن درست بود → درخواست آیدی ادمین',
                'vars'   => ['username' => 'یوزرنیم ربات تأییدشده'],
                'default' => "✅ ربات شناسایی شد: @‹username›\n\nحالا آیدی عددی ادمین را بفرست:\n" . $nav,
            ],
            'admin_prompt' => [
                'group'  => 'build',
                'label'  => 'درخواست آیدی عددی ادمین',
                'vars'   => [],
                'default' => "آیدی عددی ادمین را بفرست:\n" . $nav,
            ],
            'folder_prompt' => [
                'group'  => 'build',
                'label'  => 'درخواست نام پوشهٔ ربات',
                'vars'   => [],
                'default' => "حالا یک نام انگلیسی کوتاه بفرست (مثلا: <code>shop1</code>)\n" . $nav,
            ],
            'build_running' => [
                'group'  => 'build',
                'label'  => 'شروع ساخت ربات',
                'vars'   => ['slug' => 'نام پوشهٔ ربات'],
                'default' => '⏳ در حال ساخت ربات <b>‹slug›</b> ...',
            ],
            'build_done' => [
                'group'  => 'build',
                'label'  => 'ساخت موفق (پیام پیش‌فرض)',
                'vars'   => ['username' => 'یوزرنیم ربات', 'slug' => 'نام پوشه', 'db' => 'نام دیتابیس'],
                'default' => implode("\n", [
                    '🎉 <b>ربات آماده شد!</b>',
                    '',
                    '🤖 @‹username›',
                    '📁 پوشه: <code>‹slug›</code>',
                    '🗄 دیتابیس: <code>‹db›</code>',
                    '🔗 وبهوک: ست شد ✅',
                ]),
            ],
            'build_failed' => [
                'group'  => 'build',
                'label'  => 'خطا در ساخت ربات',
                'vars'   => ['error' => 'علت دقیق خطا'],
                'default' => implode("\n", [
                    '❌ خطا در ساخت ربات:',
                    '‹error›',
                    'نام دیگری بفرست، یا برگرد / انصراف بده.',
                ]),
            ],

            // ---------- مدیریت ----------
            'broadcast_prompt' => [
                'group'  => 'manage',
                'label'  => 'درخواست پیام همگانی (ربات‌ساز)',
                'vars'   => [],
                'default' => "متن پیام همگانی را بفرست.\n" . $nav,
            ],
            'broadcast_child_prompt' => [
                'group'  => 'manage',
                'label'  => 'درخواست پیام همگانیِ ربات فرزند',
                'vars'   => ['folder' => 'نام پوشهٔ ربات'],
                'default' => "پیام همگانی برای ربات <b>‹folder›</b> را بفرست:\n" . $nav,
            ],
            'broadcast_done' => [
                'group'  => 'manage',
                'label'  => 'پایان پیام همگانی (ربات‌ساز)',
                'vars'   => ['ok' => 'تعداد موفق', 'total' => 'تعداد کل', 'skipped_note' => 'خطای نرسیدن (خالی = بدون خطا)'],
                'default' => '✅ همگانی تمام شد: <b>‹ok›/‹total›</b> ارسال موفق‹skipped_note›',
            ],
            'broadcast_child_done' => [
                'group'  => 'manage',
                'label'  => 'پایان پیام همگانیِ ربات فرزند',
                'vars'   => ['folder' => 'نام پوشهٔ ربات', 'ok' => 'تعداد موفق', 'total' => 'تعداد کل'],
                'default' => '✅ همگانی ‹folder›: ‹ok›/‹total›',
            ],

            // ---------- پرداخت (ذخیره در PaymentGateways) ----------
            'limit' => [
                'group'  => 'payment',
                'label'  => 'متن بخش «لیمیت/اسلات»',
                'engine' => self::ENGINE_GATEWAYS,
                'vars'   => [
                    'amount' => 'قیمت هر اسلات', 'slots' => 'تعداد اسلات', 'count' => 'اسلات فعلی',
                    'limit' => 'سقف مجاز', 'remaining' => 'باقی‌مانده', 'type' => 'عنوان بخش',
                ],
                'default' => PaymentGateways::defaultText('limit'),
            ],
            'template' => [
                'group'  => 'payment',
                'label'  => 'متن بخش «قالب پولی»',
                'engine' => self::ENGINE_GATEWAYS,
                'vars'   => ['amount' => 'قیمت قالب', 'type' => 'نام قالب'],
                'default' => PaymentGateways::defaultText('template'),
            ],
            'card' => [
                'group'  => 'payment',
                'label'  => 'متن بخش «کارت‌به‌کارت»',
                'engine' => self::ENGINE_GATEWAYS,
                'vars'   => [],
                'default' => PaymentGateways::defaultText('card'),
            ],
            'nowpay' => [
                'group'  => 'payment',
                'label'  => 'متن بخش «کریپتو (NOWPayments)»',
                'engine' => self::ENGINE_GATEWAYS,
                'vars'   => ['amount' => 'مبلغ به دلار'],
                'default' => PaymentGateways::defaultText('nowpay'),
            ],
        ];

        return $reg;
    }

    // ---------- اطلاعات رجیستری ----------

    /** خط «برگشت / انصراف» که در متن‌های پیش‌فرض به‌صورت ‹nav› می‌آید */
    public static function navHint(): string
    {
        return 'برای برگشت: ' . Nav::BACK . ' | برای انصراف: ' . Nav::CANCEL;
    }

    /** فهرست گروه‌ها به‌همراه شمارش متن‌های هر گروه */
    public static function groups(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => $g) {
            $out[] = [
                'key'   => $key,
                'icon'  => $g['icon'],
                'label' => $g['label'],
                'count' => count(self::keys($key)),
            ];
        }
        return $out;
    }

    /** کلیدهای یک گروه (یا همه اگر null/خالی) — با ترتیب رجیستری */
    public static function keys(?string $group = null): array
    {
        $out = [];
        foreach (self::registry() as $k => $item) {
            if ($group !== null && $group !== '' && ($item['group'] ?? '') !== $group) continue;
            $out[] = $k;
        }
        return $out;
    }

    public static function item(string $key): ?array
    {
        $r = self::registry();
        return $r[$key] ?? null;
    }

    public static function isValid(string $key): bool
    {
        return isset(self::registry()[$key]);
    }

    public static function isValidGroup(string $group): bool
    {
        return isset(self::GROUPS[$group]);
    }

    public static function label(string $key): string
    {
        return (string)(self::item($key)['label'] ?? $key);
    }

    public static function groupOf(string $key): string
    {
        return (string)(self::item($key)['group'] ?? 'general');
    }

    /** «🌐 عمومی» — برای نمایش در صفحهٔ هر متن */
    public static function groupLabel(string $key): string
    {
        $g = self::GROUPS[self::groupOf($key)] ?? ['icon' => '•', 'label' => self::groupOf($key)];
        return $g['icon'] . ' ' . $g['label'];
    }

    /** جایگذین‌های مجازِ یک متن: نام => توضیح */
    public static function vars(string $key): array
    {
        return (array)(self::item($key)['vars'] ?? []);
    }

    public static function varNames(string $key): array
    {
        return array_keys(self::vars($key));
    }

    public static function defaultText(string $key): string
    {
        return (string)(self::item($key)['default'] ?? '');
    }

    /** آیا این متن ذخیره‌شده (دلخواه) است یا پیش‌فرض؟ — برای نمونهٔ ✍️/📌 */
    public static function hasCustom(Store $store, string $key): bool
    {
        if (!self::isValid($key)) return false;
        if (self::isGateway($key)) return PaymentGateways::hasCustomText($store, $key);
        return trim((string)($store->getSetting(self::SETTING_PREFIX . $key, '') ?? '')) !== '';
    }

    public static function statusIcon(Store $store, string $key): string
    {
        return self::hasCustom($store, $key) ? '✍️' : '📌';
    }

    /** متن خام (دلخواه یا پیش‌فرض) — بدون جایگذینی */
    public static function text(Store $store, string $key): string
    {
        if (!self::isValid($key)) return '';
        if (self::isGateway($key)) {
            $custom = PaymentGateways::customText($store, $key);
            return $custom !== '' ? $custom : PaymentGateways::defaultText($key);
        }
        $custom = trim((string)($store->getSetting(self::SETTING_PREFIX . $key, '') ?? ''));
        return $custom !== '' ? $custom : self::defaultText($key);
    }

    /**
     * متن آمادهٔ نمایش: جایگذین‌ها عوض می‌شوند و هر جایگذینِ جای‌مانده حذف می‌شود
     * تا کاربر هرگز متنی مثل «‹foo›» نبیند. جایگذینِ اعلام‌نشده در لاگ ثبت می‌شود.
     */
    public static function get(Store $store, string $key, array $vars = []): string
    {
        if (!self::isValid($key)) {
            self::logMissing($key);
            return '⚠️ متن پیش‌فرض یافت نشد: ' . $key;
        }
        $raw = self::text($store, $key);
        $out = self::render($raw, $vars);
        if (preg_match('/‹[^›]*›/u', $out, $m)) {
            self::warn($key, 'missing-or-unknown placeholder ' . $m[0]
                . ' (declared: ' . implode(',', self::varNames($key)) . ')');
            $out = (string)preg_replace('/‹[^›]*›/u', '', $out);
        }
        return $out;
    }

    /** جایگذینی ساده — برای پیش‌نمایش؛ هیچ چیزی حذف نمی‌شود */
    public static function render(string $text, array $vars = []): string
    {
        $vars = array_merge(['nav' => self::navHint()], $vars);
        foreach ($vars as $name => $value) {
            $text = str_replace('‹' . $name . '›', (string)$value, $text);
        }
        return $text;
    }

    /** متن نهایی با مقدارهای نمونه — برای نمایش در پنل ادمین */
    public static function preview(Store $store, string $key): string
    {
        if (!self::isValid($key)) return '';
        return self::get($store, $key, self::sampleVars($key));
    }

    /** مقدار نمونهٔ هر جایگذینِ اعلام‌شده (نامعلوم ⇐ «…») */
    public static function sampleVars(string $key): array
    {
        $out = [];
        foreach (self::varNames($key) as $name) {
            $out[$name] = self::SAMPLES[$name] ?? '…';
        }
        return $out;
    }

    /**
     * جایگذین‌هایی که در متن آمده ولی برای این کلید تعریف نشده‌اند.
     * هشدارِ هنگام ذخیره به ادمین: متنش قرار است حین نمایش تکه‌تکه شود.
     */
    public static function unknownVars(string $key, string $text): array
    {
        $allowed = array_merge(self::varNames($key), ['nav']);
        $found = [];
        if (preg_match_all('/‹([^›]*)›/u', $text, $m)) {
            foreach ($m[1] as $name) {
                if (!in_array($name, $allowed, true) && !in_array($name, $found, true)) $found[] = $name;
            }
        }
        return $found;
    }

    // ---------- ذخیره‌سازی ----------

    /**
     * ذخیرهٔ متن دلخواه. false یعنی کلید نامعتبر یا متن خالی — دلیل دقیقش را
     * خودِ فراخوان (پنل ادمین) با پیام مشخص اعلام می‌کند.
     */
    public static function set(Store $store, string $key, string $text): bool
    {
        if (!self::isValid($key)) return false;
        $text = trim($text);
        if ($text === '') return false;
        if (mb_strlen($text) > self::MAX_LEN) $text = mb_substr($text, 0, self::MAX_LEN);
        if (self::isGateway($key)) {
            PaymentGateways::setCustomText($store, $key, $text);
            return true;
        }
        $store->setSetting(self::SETTING_PREFIX . $key, $text);
        return true;
    }

    /** بازگشت به متن پیش‌فرض */
    public static function reset(Store $store, string $key): bool
    {
        if (!self::isValid($key)) return false;
        if (self::isGateway($key)) {
            PaymentGateways::resetText($store, $key);
            return true;
        }
        $store->setSetting(self::SETTING_PREFIX . $key, '');
        return true;
    }

    // ---------- داخلی ----------

    private static function isGateway(string $key): bool
    {
        return (string)(self::item($key)['engine'] ?? '') === self::ENGINE_GATEWAYS;
    }

    private static function logMissing(string $key): void
    {
        self::warn($key, 'unknown text key requested by caller');
    }

    private static function warn(string $key, string $why): void
    {
        if (!class_exists('Logger')) return;
        try {
            Logger::getInstance()->warning('texts', "key={$key}: {$why} (src/Texts.php)");
        } catch (Throwable $ignored) { /* لاگ‌کردن هرگز نباید خودش خطا بدهد */ }
    }
}
