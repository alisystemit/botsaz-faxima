<?php
// ===== ماژول متن‌های قابل ویرایش ربات =====
// وابستگی‌ها اینجا require می‌شوند تا هر نقطهٔ ورودی (bot.php، endpointهای
// پرداخت، تست‌ها) بتواند فقط همین یک فایل را لود کند و به ترتیبِ require
// وابسته باشد.
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

require_once __DIR__ . '/Ui.php';

class Texts
{
    /** پیشوند کلید ذخیره‌سازی در جدول settings */
    public const SETTING_PREFIX = 'txt_';

    /** متن‌هایی که عملاً در PaymentGateways نگهداری می‌شوند */
    public const ENGINE_GATEWAYS = 'gateways';

    /** سقف طول متن ذخیره‌شده (پیام تلگرام ۴۰۹۶ کاراکتر است) */
    public const MAX_LEN = 4000;

    /** پیش‌فرضِ متن حالت تعمیرات به‌صورت یک ثابت نگه داشته می‌شود تا هم در
     *  رجیستری و هم در پیش‌نمایشِ پنل به آن ارجاع داده شود. */
    public const MAINTENANCE_DEFAULT = "🔧 <b>قسمت ربات در حال تعمیر است</b>\n\n"
        . "سلام! 🌹\n"
        . "در حال حاضر امکان ساخت ربات تازه موقتاً غیرفعال است تا مشکل را برطرف کنیم.\n"
        . "ربات‌هایی که قبلاً ساخته‌اید <b>به‌طور کامل کار می‌کنند</b> و دست‌نخورده‌اند. ✅\n\n"
        . "⏳ <b>زمان تقریبی بازگشت:</b> ‹eta›\n\n"
        . "بابت مزاحمت متأسفیم 🙏\n"
        . "اگر سؤالی دارید با ادمین در میان بگذارید.";

    private const GROUPS = [
        'general' => ['icon' => '🌐', 'label' => 'عمومی'],
        'build'   => ['icon' => '🤖', 'label' => 'ساخت ربات'],
        'manage'  => ['icon' => '📣', 'label' => 'مدیریت'],
        'payment' => ['icon' => '💳', 'label' => 'پرداخت'],
    ];

    /** مقدار نمونهٔ هر جایگذین — فقط برای پیش‌نمایش در پنل ادمین */
    private const EXTRA_SAMPLES = [
        'usd'   => '۲٫۵۰',
        'ref'   => '۱۲۳۴۵۶۷۸',
        'card'  => '6037‑****‑****‑9999',
        'bank'  => 'بانک ملت',
        'eta'   => 'تا پایان امروز',
    ];

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
                    '👋 <b>سلام! به ربات‌ساز خوش آمدی</b> 🌹',
                    '',
                    '🏷️ <b>نقش شما:</b> ‹role›',
                    '',
                    '🤖 <b>اینجا چه کاری انجام می‌شود؟</b>',
                    'در چند ثانیه یک ربات تلگرامی کامل می‌سازی: پوشه + دیتابیس + کانفیگ + وبهوک.',
                    '🧩 <b>قالب‌های موجود روی این سرور:</b> ‹types›',
                    '',
                    '🧱 <b>مراحل ساخت (۴ قدم ساده):</b>',
                    '1️⃣ «🤖 ساخت ربات جدید» → انتخاب قالب',
                    '2️⃣ ارسال <b>توکن</b> ربات از ‎<code>@BotFather</code>',
                    '3️⃣ ارسال <b>آیدی عددی ادمین</b> (از ‎<code>@userinfobot</code>)',
                    '4️⃣ یک <b>نام انگلیسی کوتاه</b> برای پوشهٔ ربات',
                    '',
                    Ui::sep(),
                    '🧭 <b>نکته:</b> هرجا گیر کردی، یکی از این سه دکمه نجاتت می‌دهد:',
                    '↩️ «برگشت» — یک قدم به عقب • ❌ «انصراف» — شروع از اول • 🏠 «منو» — منوی اصلی',
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
                    Ui::sep(),
                    '🧱 <b>پیش‌نیازها</b>',
                    '• یک ربات خالی از ‎<code>@BotFather</code> بساز (دستور ‎<code>/newbot</code>) و توکن را کپی کن.',
                    '• آیدی عددی ادمین را از ‎<code>@userinfobot</code> بگیر (عدد بزرگ، نه ‎<code>@یوزرنیم</code>).',
                    '',
                    '🛠 <b>مراحل ساخت</b>',
                    '1️⃣ «🤖 ساخت ربات جدید» → انتخاب قالب',
                    '2️⃣ توکن ربات را بفرست (توکن رمزنگاری‌شده ذخیره می‌شود 🔐)',
                    '3️⃣ آیدی عددی ادمین را بفرست',
                    '4️⃣ یک نام انگلیسی کوتاه برای پوشه بده',
                    '5️⃣ ربات‌ساز خودش پوشه + دیتابیس + کانفیگ + وبهوک را می‌سازد؛ اگر خطایی رخ دهد، همهٔ منابع آزاد می‌شوند (rollback).',
                    '',
                    Ui::sep(),
                    '📦 <b>مدیریت ربات‌ها</b> (از «📦 ربات‌های من»)',
                    '📊 آمار • 📣 پیام همگانی • ✏️ ویرایش توکن • ✏️ ویرایش آیدی ادمین • 🔗 ست مجدد وبهوک • 🔴/🟢 فعال/غیرفعال • 🗑 حذف (با بکاپ خودکار)',
                    '',
                    '🧭 <b>دستورات</b>',
                    '<code>/start</code> منوی اصلی • <code>/mybots</code> ربات‌های من • <code>/stats</code> آمار • <code>/texts</code> متن‌ها • <code>/cancel</code> انصراف • <code>/menu</code> منو',
                    '',
                    Ui::sep(),
                    '⚠️ <b>امنیت</b>',
                    'توکن ربات را به هیچ‌کس نده؛ ربات‌ساز هرگز توکنِ رباتِ کسِ دیگر را نمی‌خواهد.',
                    '',
                    '🩹 <b>اگر جایی خطا دیدی</b>',
                    'پیام خطا همیشه «بخش + علت + محل دقیق (فایل:خطا)» را نشان می‌دهد؛ همان متن را بفرست. ‹diag›',
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
                    '⛔️ <b>دسترسی ندارید</b>',
                    '',
                    'برای استفاده از ربات‌ساز ابتدا «🤖 ساخت ربات جدید» را بزنید',
                    'تا درخواستتان ثبت و توسط ادمین تأیید شود. 📝',
                    'پس از تأیید، همین دکمه شما را مستقیم به ساخت می‌برد.',
                ]),
            ],
            'invalid' => [
                'group'  => 'general',
                'label'  => 'پیام «دستور نامعتبر»',
                'vars'   => ['input' => 'متنی که کاربر فرستاده (برای عیب‌یابی)'],
                'default' => implode("\n", [
                    '🤔 <b>متوجه نشدم!</b>',
                    '',
                    'دریافت‌شده: ‎<code>‹input›</code>',
                    '',
                    '💡 یکی از دکمه‌های منو را بزن، یا «ℹ️ راهنما» را انتخاب کن.',
                    'تا وقتی در یک مرحله هستی، «↩️ برگشت» و «❌ انصراف» هم کار می‌کنند.',
                ]),
            ],
            'request_pending' => [
                'group'  => 'general',
                'label'  => 'ثبت درخواستِ در انتظار تأیید',
                'vars'   => [],
                'default' => implode("\n", [
                    '📝 <b>درخواست شما ثبت شد!</b>',
                    '',
                    '⏳ لطفاً منتظر تأیید ادمین بمانید.',
                    'به‌محض تأیید، همین‌جا به شما اطلاع می‌دهیم.',
                    'بعد از تأیید می‌توانید ساخت (و در صورت نیاز، خرید) را انجام دهید. 🚀',
                ]),
            ],
            'request_pending_again' => [
                'group'  => 'general',
                'label'  => 'درخواستِ تکراری (قبلاً ثبت شده)',
                'vars'   => [],
                'default' => implode("\n", [
                    '⏳ <b>درخواست شما قبلاً ثبت شده است</b>',
                    '',
                    'هنوز در انتظار تأیید ادمین است؛ لطفاً کمی صبر کنید 🙏',
                    'تا قبل از تأیید، امکان خرید اسلات یا مجوز قالب وجود ندارد.',
                ]),
            ],
            'request_approved' => [
                'group'  => 'general',
                'label'  => 'تأیید درخواستِ کاربر توسط ادمین',
                'vars'   => [],
                'default' => "🎉 <b>درخواستت تأیید شد!</b>\n\n"
                    . "حالا «🤖 ساخت ربات جدید» را بزن و نوع ربات را انتخاب کن. 🚀",
            ],

            // ---------- ساخت ربات ----------
            'type_prompt' => [
                'group'  => 'build',
                'label'  => 'انتخاب نوع ربات',
                'vars'   => [],
                'default' => "🧩 <b>نوع ربات را انتخاب کن</b>\n\n"
                    . "هر قالب یک ربات کامل با پنل و دیتابیسِ خودش است.\n"
                    . "👇 روی قالبی که می‌خواهی بزن:",
            ],
            'token_prompt' => [
                'group'  => 'build',
                'label'  => 'درخواست توکن ربات',
                'vars'   => ['type' => 'نام قالب انتخاب‌شده'],
                'default' => "🔑 <b>توکن ربات ‹type›</b>\n\n"
                    . "توکن را از ‎<code>@BotFather</code> کپی کن و همین‌جا بفرست.\n"
                    . "نمونهٔ قالب: ‎<code>1234567890:AAH…</code>\n\n"
                    . "🔐 توکن رمزنگاری‌شده ذخیره می‌شود و هرگز نمایش داده نمی‌شود.\n"
                    . $nav,
            ],
            'token_ok' => [
                'group'  => 'build',
                'label'  => 'توکن درست بود → درخواست آیدی ادمین',
                'vars'   => ['username' => 'یوزرنیم ربات تأییدشده'],
                'default' => "✅ <b>ربات شناسایی شد!</b>\n\n"
                    . "🤖 یوزرنیم: <code>@‹username›</code>\n\n"
                    . "🆔 <b>حالا آیدی عددی ادمین را بفرست</b>\n"
                    . "از ‎<code>@userinfobot</code> بگیرش (مثل ‎<code>123456789</code>).\n"
                    . $nav,
            ],
            'admin_prompt' => [
                'group'  => 'build',
                'label'  => 'درخواست آیدی عددی ادمین',
                'vars'   => [],
                'default' => "🆔 <b>آیدی عددی ادمین را بفرست</b>\n\n"
                    . "از ‎<code>@userinfobot</code> بگیر؛ عدد بزرگ باشد، نه ‎<code>@یوزرنیم</code>.\n"
                    . $nav,
            ],
            'folder_prompt' => [
                'group'  => 'build',
                'label'  => 'درخواست نام پوشهٔ ربات',
                'vars'   => [],
                'default' => "📁 <b>حالا یک نام انگلیسی کوتاه بفرست</b>\n\n"
                    . "این نام، نام پوشهٔ ربات روی سرور می‌شود.\n"
                    . "مثال: ‎<code>shop1</code> یا ‎<code>my-vpn-bot</code>\n"
                    . "⚠️ نام‌های <code>backups</code> و <code>states</code> رزرو شده‌اند.\n\n"
                    . $nav,
            ],
            'build_running' => [
                'group'  => 'build',
                'label'  => 'شروع ساخت ربات',
                'vars'   => ['slug' => 'نام پوشهٔ ربات'],
                'default' => "⏳ <b>در حال ساخت ربات ‹slug› …</b>\n\n"
                    . "این کار ممکن است چند ثانیه طول بکشد؛ لطفاً صبر کنید 🙏\n"
                    . "در حال ساخت: پوشه • دیتابیس • کانفیگ • جدول‌ها • وبهوک",
            ],
            'build_done' => [
                'group'  => 'build',
                'label'  => 'ساخت موفق (پیام پیش‌فرض)',
                'vars'   => ['username' => 'یوزرنیم ربات', 'slug' => 'نام پوشه', 'db' => 'نام دیتابیس'],
                'default' => implode("\n", [
                    '🎉 <b>ربات شما آماده شد!</b>',
                    '',
                    Ui::sep(),
                    Ui::kv('🤖', 'یوزرنیم', '@‹username›', true),
                    Ui::kv('📁', 'پوشه', '‹slug›', true),
                    Ui::kv('🗄', 'دیتابیس', '‹db›', true),
                    Ui::kv('🔗', 'وبهوک', 'ست شد ✅'),
                    '',
                    '🚀 حالا یک پیام به رباتت بفرست و کار را شروع کن!',
                ]),
            ],
            'build_failed' => [
                'group'  => 'build',
                'label'  => 'خطا در ساخت ربات',
                'vars'   => ['error' => 'علت دقیق خطا'],
                'default' => implode("\n", [
                    '❌ <b>ساخت ربات ناموفق بود</b>',
                    '',
                    Ui::quote('‹error›'),
                    '',
                    '💡 یک نام دیگر بفرست، یا با «↩️ برگشت» / «❌ انصراف» از این مرحله خارج شو.',
                    '🛡 منابع ساخته‌شده آزاد شدند؛ چیزی نیمه‌کاره باقی نمانده است.',
                ]),
            ],

            // ---------- مدیریت ----------
            'broadcast_prompt' => [
                'group'  => 'manage',
                'label'  => 'درخواست پیام همگانی (ربات‌ساز)',
                'vars'   => [],
                'default' => "📣 <b>متن پیام همگانی را بفرست</b>\n\n"
                    . "همین پیام برای همهٔ کاربرانِ ربات‌ساز ارسال می‌شود.\n"
                    . "می‌توانید عکس، ویدیو، فایل یا استیکر هم بفرستید.\n\n"
                    . $nav,
            ],
            'broadcast_child_prompt' => [
                'group'  => 'manage',
                'label'  => 'درخواست پیام همگانیِ ربات فرزند',
                'vars'   => ['folder' => 'نام پوشهٔ ربات'],
                'default' => "📣 <b>پیام همگانی برای ربات ‹folder›</b>\n\n"
                    . "متن/رسانه را بفرست تا برای همهٔ کاربران آن ربات ارسال شود.\n"
                    . "⚠️ این پیام از چندروزی بعد، در چت خودت از ربات ‹folder› می‌آید (نه از ربات‌ساز).\n\n"
                    . $nav,
            ],
            'broadcast_done' => [
                'group'  => 'manage',
                'label'  => 'پایان پیام همگانی (ربات‌ساز)',
                'vars'   => ['ok' => 'تعداد موفق', 'total' => 'تعداد کل', 'skipped_note' => 'خطای نرسیدن (خالی = بدون خطا)'],
                'default' => "✅ <b>پیام همگانی تمام شد</b>\n\n"
                    . "📤 موفق: <b>‹ok›</b> از <b>‹total›</b> نفر‹skipped_note›",
            ],
            'broadcast_child_done' => [
                'group'  => 'manage',
                'label'  => 'پایان پیام همگانیِ ربات فرزند',
                'vars'   => ['folder' => 'نام پوشهٔ ربات', 'ok' => 'تعداد موفق', 'total' => 'تعداد کل'],
                'default' => "✅ <b>همگانی ‹folder› تمام شد</b>\n\n"
                    . "📤 موفق: <b>‹ok›</b> از <b>‹total›</b> نفر",
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
            'zarin' => [
                'group'  => 'payment',
                'label'  => 'متن بخش زرین‌پال',
                'engine' => self::ENGINE_GATEWAYS,
                'vars'   => ['amount' => 'مبلغ به تومان', 'usd' => 'معادل دلار', 'ref' => 'شمارهٔ پیگیری درگاه'],
                'default' => PaymentGateways::defaultText('zarin'),
            ],
            'aqaye' => [
                'group'  => 'payment',
                'label'  => 'متن بخش آقای پرداخت',
                'engine' => self::ENGINE_GATEWAYS,
                'vars'   => ['amount' => 'مبلغ به تومان', 'ref' => 'شمارهٔ پیگیری', 'card' => 'شمارهٔ کارت (ماسک‌شده)', 'bank' => 'نام بانک'],
                'default' => PaymentGateways::defaultText('aqaye'),
            ],
            'maintenance' => [
                'group'  => 'general',
                'label'  => 'پیام حالت تعمیرات (قسمت ربات)',
                'vars'   => ['eta' => 'زمان تقریبی بازگشت'],
                'default' => self::MAINTENANCE_DEFAULT,
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
        // فاصله‌ها یکدست و آدرس‌ها کلیک‌پذیر می‌شوند (متنِ ادمین هم همین‌طور)
        return Ui::out($out);
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
            $out[$name] = self::SAMPLES[$name] ?? self::EXTRA_SAMPLES[$name] ?? '…';
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
