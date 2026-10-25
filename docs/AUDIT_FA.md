# گزارش بازبینی فنی و تست تهاجمی — ربات‌ساز فاکسیما

تاریخ: 2026-10-21  
محدوده: کل مخزن (هستهٔ PHP، مینی‌اپ، پنل‌ها، قالب‌ها، پرداخت‌ها، بکاپ/آپدیت، نصب)  
روش: خواندن کد + اجرای واقعی در یک کپی ایزوله + تست‌های تهاجمی دستی

---

## ۱) خلاصهٔ مدیریتی

پروژه از نظر «ساختار» بالغ‌تر از حد انتظار است: رجیستری قالب‌ها، گاردهای وبهوک، idempotency پرداخت‌ها، مایگریشن افزایشی، سلف‌تست‌ها و ابزارهای dry-run/healthcheck همه وجود دارند و سلف‌تست‌ها هم پاس می‌شوند. اما چند مشکل **مالی/امنیتی/عملیاتی** هست که قبل از استفادهٔ عمومی باید بسته شوند:

- **CSRF در پنل فاکسیما**: چند عملیات حساس (مسدودسازی، شارژ/کسر موجودی، ارسال پیام) با GET و **قبل از** بررسی CSRF اجرا می‌شوند.
- **افشای کلیدهای درگاه پرداخت** به هر `is_admin` از طریق مینی‌اپ؛ از جمله `nowpay_ipn` که با آن می‌شود IPN جعلی ساخت.
- **نبود قفل پردازش وبهوک**: آپدیت تکراری/هم‌زمان تلگرام می‌تواند ساخت ربات را دوبار اجرا کند و سقف/ووچر را دور بزند.
- **پرداخت و اعطای حق (grant) اتمیک نیست**: اگر grant خطا بدهد، پول کم شده ولی سقف/قالب اضافه نشده و تلاش دوباره هم آن را جبران نمی‌کند.
- **خرابی config میرزا با کوتیشن/بک‌اسلش در رمز دیتابیس** (بازتولید شد: `php -l` خطا داد).
- **«حذف/غیرفعال» از مینی‌اپ با نسخهٔ ربات یکی نیست**: وبهوک حذف نمی‌شود، دیتابیس MySQL یتیم می‌ماند و «غیرفعال» عملاً ربات را متوقف نمی‌کند.
- **شکست ساخت جدول‌ها نادیده گرفته می‌شود** و ربات «فعال» ثبت می‌شود در حالی که جدول ندارد.

---

## ۲) محیط و روش تست

- PHP: `8.3.33` (CLI، ویندوز/لاراگون)، افزونه‌های `pdo_mysql`، `pdo_sqlite`، `curl`، `openssl`، `mbstring` موجود.
- MySQL محلی در دسترس نبود؛ برای تست‌های مدیریتی از **SQLite** و یک کپی کامل از پروژه در `%TEMP%\botsaz-audit` استفاده شد (پروژهٔ اصلی دست‌نخورده ماند).
- `tools/install.php` در کپی اجرا شد؛ دیتابیس مدیریتی SQLite و تنظیمات ساخته شد.
- سرور داخلی PHP روی `127.0.0.1:8099` اجرا و با `curl` تست شد.
- `initData` معتبر تلگرام با توکن ساختگی و HMAC واقعی ساخته شد تا مسیر احراز هویت مینی‌اپ تست شود.
- برای تست وبهوک، `secret` واقعی فرمول فاکسیما محاسبه و درخواست‌های جعلی ارسال شد.
- تست‌های خودکار پروژه هم اجرا شدند (نتایج در بخش ۴).

> محدودیت: چون توکن واقعی تلگرام و MySQL در اختیار نبود، مسیر «ساخت کامل ربات + table.php + setWebhook واقعی» به‌صورت end-to-end تست نشد. این خودش یک شکاف تست است (بخش ۵).

---

## ۳) یافته‌ها به ترتیب اولویت

### P0-1) CSRF در پنل فاکسیما — عملیات حساس قبل از بررسی توکن اجرا می‌شوند

**فایل:** `templates/faxima/panel/user.php`  
**خطوط:** اقدامات GET در `173-256`، بررسی CSRF در `264-279`

```php
// 173: ابتدا عمل انجام می‌شود...
if (isset($_GET['status']) and $_GET['status']) {
    ...
    update("user", "User_Status", $_GET['status'], "id", $_GET['id']);
    header("Location: user.php?id={$_GET['id']}");
    exit;
}
// 192: شارژ موجودی
if (isset($_GET['priceadd']) and $_GET['priceadd']) { ... }
// 216: کسر موجودی
// 236: تغییر agent
// 242: ارسال پیام به کاربر

// 270: تازه اینجا CSRF بررسی می‌شود (بعد از انجام کار!)
$_has_action = isset($_GET['status']) || ...;
if ($_has_action) { if (!hash_equals(...)) exit(...); }
```

**اثر:** یک لینک ساده که ادمینِ لاگین‌کرده روی آن کلیک کند (یا یک GET از طریق نویگیشن top-level) می‌تواند:
- کاربر را مسدود/آزاد کند،
- موجودی کیف پول را کم/زیاد کند (`priceadd` / `pricelow`)،
- agent را عوض کند،
- پیام دلخواه به کاربر بفرستد.

کوکی نشست `SameSite=Lax` است؛ برای نویگیشن top-level ارسال می‌شود، پس حمله عملی است. حتی اگر توکن CSRF نامعتبر باشد، عمل **قبلاً انجام شده**.

**اصلاح:**
1. همهٔ این اقدامات را به `POST` منتقل کنید و `_action` بدهید (بقیهٔ فایل از قبل الگوی POST+CSRF دارد).
2. بررسی CSRF را به **اولین خط** هندلر POST منتقل کنید (قبل از هر read/write).
3. هندلر GET را حذف کنید؛ لینک‌ها را به فرم POST با `_csrf` تبدیل کنید.
4. برای پنل ادمین `SameSite=Strict` را در نظر بگیرید.

> این الگو ممکن است در فایل‌های دیگر پنل هم تکرار شده باشد؛ یک جست‌وجوی `$_GET` + `update(`/`delete(` در `templates/faxima/panel` انجام دهید.

---

### P0-2) افشای کلیدهای درگاه پرداخت به هر ادمین + امکان ساخت ادمین جدید

**فایل:** `app/api.php`  
**خطوط:** `230-235` (خروجی تنظیمات)، `128-133` (تغییر نقش)، `582` (شرط ورود)

```php
'zarin_merchant' => PaymentZarin::merchantId($store),
'aqaye_pin'      => PaymentAqaye::pin($store),
'nowpay_key'     => ...,
'nowpay_ipn'     => ...,   // ← کلید امضای IPN
```

و در `auth()`:

```php
if (in_array($uid, $supers, true) || (int)($u['is_admin'] ?? 0) === 1) return $uid;
```

یعنی هر کاربر `is_admin=1` می‌تواند:
- `settings_get` بزند و **کلید و IPN secret** را ببیند،
- `user_toggle_admin` بزند و کاربر دیگری را ادمین کند،
- `user_set_limit`، `bot_delete`، `bot_status` و تنظیمات پرداخت را تغییر دهد.

**اثر:** با داشتن `nowpay_ipn` می‌توان برای هر پرداخت/سفارش، IPN معتبر جعل کرد و بدون پرداخت، سقف/قالب گرفت. افشای کلید در پاسخ JSON همچنین با XSS/دسترسی به DevTools/لاگ قابل سرقت است.

**تست واقعی:** با یک ادمین غیرسوپر، `settings_get` مقدار `nowpay_ipn=audit-ipn-secret` را برگرداند و `user_toggle_admin` برای کاربر `444444` با کد ۲۰۰ انجام شد.

**اصلاح:**
- `settings_get` باید کلیدها را **ماسک** کند (`****1234`) و فقط سوپرادمین مقدار کامل بگیرد.
- تغییر کلیدها/نقش‌ها/حذف ربات را به سوپرادمین محدود کنید یا نقش‌های جدا (`admin`, `finance`, `support`) بسازید.
- تغییر **شمارهٔ کارت مقصد**، روشن/خاموش کردن درگاه و flagهای sandbox در نسخهٔ نهایی به **هر ادمین** مجاز شد (تصمیم مدل «ادمین مورداعتماد» برای راه‌اندازی فروشگاه)، ولی **خواندن** کلیدهای راز برای غیرسوپرادمین همچنان ماسک می‌شود.
- تأیید پرداخت توسط خودِ پرداخت‌کننده در نسخهٔ نهایی **مجاز** شد (ادمین‌ها مورداعتمادند)؛ اما خطای 500 سابق در `payment_approve` (به‌خاطر نبود `PaymentLimits`) رفع شد.
- secretها را در دیتابیس رمزنگاری کنید (کلید از `secret_key`)، نه plaintext.
- یک `AuditLog` برای «چه کسی چه کلیدی را دید/عوض کرد» اضافه کنید.

---

### P0-3) نبود قفل روی پردازش وبهوک — ساخت دوبارهٔ ربات با آپدیت تکراری

**فایل:** `bot.php`  
**خطوط:** `183-190` (dedup)، `286` و `293` (mark بعد از پردازش)، `2519-2524` (build طولانی)

```php
if ($updateId !== null && $store->isUpdateProcessed($updateId)) { ... }
// ... پردازش (ممکن است > ۶۰ ثانیه طول بکشد)
$store->markUpdateProcessed($updateId); // فقط بعد از موفقیت
```

**اثر:** تلگرام اگر پاسخ وبهوک را تا ~۶۰ ثانیه نگیرد، همان آپدیت را دوباره می‌فرستد. چون علامت‌گذاری در انتهاست و هیچ `flock`/قفل per-user وجود ندارد:
- دو درخواست هم‌زمان می‌توانند هر دو از `is_dir(bots/<slug>)` رد شوند و دو ربات/دو دیتابیس بسازند (یکی از آن‌ها یتیم می‌شود).
- سقف `bot_limit` و ووچر قالب می‌توانند دو بار مصرف شوند.
- `consumeTemplateVoucher` هم بعد از build اجرا می‌شود، پس در بازهٔ هم‌زمانی قابل دور زدن است.

**اصلاح:**
1. یک قفل فایل به ازای `update_id` (یا `chat_id`/`uid`) بگیرید: `data/locks/webhook_<hash>.lock` با `flock(LOCK_EX|LOCK_NB)`.
2. رکورد `processed_updates` را **در ابتدا** با وضعیت `processing` درج کنید (UNIQUE) و در پایان `done` کنید؛ در خطا حذف/آزاد کنید.
3. برای build، یک قفل per-user (`flock`) و در صورت اشغال، پیام «یک ساخت در حال اجراست» بدهید.
4. `consumeTemplateVoucher` را داخل همان تراکنش/قفل build انجام دهید.

---

### P0-4) پرداخت و grant اتمیک نیستند — پول کم می‌شود ولی حق اضافه نمی‌شود

**فایل:** `src/Payment/Payments.php`  
**خطوط:** `488-501` (`approveByAdmin`)، `509-527` (`markCryptoPaid`)

```php
$st = $pdo->prepare("UPDATE payments SET status=?, paid_at=... WHERE id=? AND status IN (?,?)");
$st->execute([...]);
if ($st->rowCount() === 0) return null;
$p = self::getPayment($store, $id);
$p['grant_note'] = self::grant($store, $p); // ← اگر این خطا بدهد؟
return $p;
```

**اثر:** اگر `grant()` (افزایش `bot_limit` یا ثبت ووچر) به هر دلیل خطا بدهد:
- وضعیت پرداخت قبلاً `paid` شده است؛
- IPN/تأیید بعدی `rowCount=0` می‌بیند و **دوباره grant نمی‌کند**؛
- کاربر پول داده و حقش را نگرفته، بدون مسیر خودکار جبران.

**اصلاح:** `UPDATE status` + `grant` را در یک تراکنش دیتابیس انجام دهید (`beginTransaction` / `commit` / `rollBack`)، یا یک ستون `granted_at` اضافه کنید و `grant` را idempotent کنید (اگر `granted_at` خالی است، اعمال کن). همچنین خطا را به صف retry بدهید.

---

### P0-5) تزریق/خرابی config میرزا با کوتیشن یا بک‌اسلش در رمز دیتابیس

**فایل:** `src/Manager.php`  
**خطوط:** `786-795`

```php
$replacements = [
    '{DATABASE_NAME}'     => $dbName,
    '{DATABASE_USERNAME}' => $cfg['db_user'],
    '{DATABASE_PASSOWRD}' => $cfg['db_pass'],
    '{DATABASE_PASSWORD}' => $cfg['db_pass'],
    '{BOT_TOKEN}'         => $token,           // اعتبارسنجی شده
    '{ADMIN_#ID}'         => (string)$adminId,
    '{DOMAIN.COM/PATH/BOT}' => $domainPath,
    '{BOT_USERNAME}'      => $botUsername,     // اعتبارسنجی شده
];
$new = str_replace(..., $raw, $count);
if ($count === 0) throw ...;
```

هیچ‌کدام از `db_pass`/`db_user`/`dbName`/`domainPath` escape نمی‌شوند. `phpEscape()` فقط در فاکسیما/آپ‌تایم استفاده می‌شود.

**بازتولید واقعی:** یک کپی از `templates/mirza/config.php` با `db_pass = p'a\s'sword` پچ شد؛ نتیجه:

```text
Parse error: syntax error, unexpected identifier "name" ... on line 6
6: $dbname = 'bot_db'name';
```

**اثر:** ربات «با موفقیت» ساخته می‌شود اما `config.php` پارس نمی‌شود → همهٔ صفحه‌ها ۵۰۰. اگر مقدار از env/پنل میزبان قابل کنترل باشد، امکان تزریق کد PHP هم هست. علاوه بر این، `count === 0` فقط بررسی می‌کند «حداقل یکی جایگزین شد»؛ اگر یک placeholder در نسخهٔ جدید قالب حذف/تغییر کند، بی‌صدا رد می‌شود.

**اصلاح:**
- همهٔ مقادیر را با `self::phpEscape()` (یا معادل) escape کنید، حتی `adminId` و `domainPath`.
- بعد از patch، `php -l` روی فایل اجرا کنید (مثل `dryrun`) و در صورت خطا rollback کنید.
- بررسی کنید **هیچ** placeholder باقی نمانده باشد (رجکس `\{[A-Za-z0-9_#./-]+\}`، نه فقط حروف بزرگ).
- برای `db_pass`هایی که `'` دارند، تست واحد اضافه کنید.

---

### P0-6) «حذف» و «غیرفعال» در مینی‌اپ با نسخهٔ ربات یکی نیست

**فایل:** `app/api.php`  
**خطوط:** `84-93` (حذف)، `76-82` (وضعیت)

```php
case 'bot_delete': {
    $bot = $store->botById($id);
    if (!$bot) out(404, ...);
    $dir = Manager::childBotsDir() . '/' . $bot['folder'];
    if (is_dir($dir)) Manager::removeDir($dir);
    $store->deleteBot($id);   // ← نه deleteWebhook، نه DROP DATABASE، نه بکاپ
    out(200, ['ok'=>true]);
}
case 'bot_status': {
    $store->setBotStatus($id, $status); // ← فقط دیتابیس
    out(200, ['ok'=>true]);
}
```

مقایسه با مسیر درست در `bot.php`:
- `bot.php:4987-5003` برای toggle: `deleteWebhook`/`setWebhook`.
- `bot.php:5033-5077` برای حذف: `deleteWebhook` + بکاپ + `DROP DATABASE` + `pruneBackups`.

**اثر:**
- وبهوک ربات حذف‌شده روی تلگرام می‌ماند و تلگرام به آدرسی که دیگر وجود ندارد آپدیت می‌فرستد (خطا/retry).
- دیتابیس MySQL یتیم می‌ماند (هزینهٔ دیسک/دادهٔ باقی‌مانده).
- «غیرفعال» از مینی‌اپ هیچ اثری روی ربات فرزند ندارد چون ربات فرزند مستقل اجرا می‌شود؛ فقط برچسب UI عوض می‌شود.

**اصلاح:** یک سرویس مشترک `BotLifecycle` بسازید (`disable`, `enable`, `delete`) و از `bot.php` و `app/api.php` هر دو صدا بزنید. حذف باید تراکنشی/مرحله‌ای باشد: webhook → بکاپ → پوشه → DB → رکورد.

---

### P0-7) شکست ساخت جدول‌ها نادیده گرفته می‌شود

**فایل:** `bot.php`  
**خطوط:** `2818-2821`، پیام `2881-2883`

```php
$tableOk = Manager::triggerTable(
    $baseUrl . '/' . $tableFile,
    Manager::tableSecret($type, $plainToken)
);
// ...
$lines[] = "🗂 جدول‌ها: " . ($tableOk ? '✅' : '⚠️ دستی بازش کن');
```

`$tableOk === false` هیچ‌جا باعث rollback/خطا نمی‌شود. ربات با وضعیت `active` ثبت می‌شود.

**اثر:** ربات ساخته‌شده در اولین اجرا به جدول‌های نبوده برخورد می‌کند و ۵۰۰ می‌دهد. کاربر عادی هم دسترسی/دانش «دستی باز کردن» ندارد؛ پیام راهنما بی‌فایده است. ممکن است بعداً با source update درست شود، اما تا آن موقع ربات مرده است.

**اصلاح:**
- اگر `$tableOk` false بود، build را **fail** کنید و rollback بزنید؛ یا وضعیت `pending_tables` ثبت کنید و با backoff خودکار retry کنید + به ادمین اطلاع بدهید.
- خطای table.php را در لاگ با URL/کد وضعیت ثبت کنید (بدون secret).
- در dry-run، ساخت واقعی جدول را هم شبیه‌سازی/تست کنید (الان SKIPPED است).

---

### P1-1) افزایش اجباری مبلغ در آقای پرداخت (زیر ۱۰۰۰ تومان)

**فایل:** `src/Payment/AqaPay.php`  
**خطوط:** `69-76` و `124-137`

```php
if ($toman < self::MIN_TOMAN) return self::MIN_TOMAN; // 1000
```

اگر ادمین قیمت قالب/اسلات را ۵۰۰ تومان بگذارد:
- فاکتور به کاربر ۵۰۰ نشان داده می‌شود (از دیتابیس)،
- درگاه ۱۰۰۰ دریافت می‌کند،
- verify هم با ۱۰۰۰ انجام می‌شود (پس تراکنش موفق است و کاربر ۱۰۰۰ داده).

**اثر:** اضافه‌دریافت/ناهماهنگی مالی و شکایت کاربر. اگر درگاه مبلغ دقیق را enforce کند، بدتر: پول کم می‌شود و verify شکست می‌خورد.

**اصلاح:** هنگام ساخت فاکتور، اگر مبلغ < `MIN_TOMAN` است، یا فاکتور ساخته نشود، یا مبلغ ذخیره‌شده و متن فاکتور هم به ۱۰۰۰ به‌روزرسانی شود. مبلغ نمایش‌داده‌شده باید همان مبلغ درگاه باشد.

---

### P1-2) نشت پیام خام استثنا در API مینی‌اپ

**فایل:** `app/api.php`  
**خطوط:** `560-562`

```php
} catch (Throwable $e) {
    out(500, ['ok' => false, 'msg' => $e->getMessage()]);
}
```

**اثر:** پیام PDO می‌تواند DSN، نام کاربری، مسیر فایل، جزئیات اسکیما یا Query را لو بدهد. در بقیهٔ پروژه `Manager::sanitizeDbError()` استفاده شده، اینجا نه.

**اصلاح:** پیام کامل را لاگ کنید، به کلاینت کد/پیام عمومی بدهید (مثلاً `internal_error` + `request_id`).

---

### P1-3) `initData` در Query String می‌رود (لاگ/Referer/تاریخچه)

**فایل:** `app/assets/js/app.js` خط `54` و `app/api.php` خط `35`

```js
var url = API + '?action=' + ... + '&initData=' + encodeURIComponent(initData);
```

```php
$initData = (string)($_POST['initData'] ?? $_GET['initData'] ?? '');
```

**اثر:** `initData` معتبر تا ۲۴ ساعت در access log، ابزارهای مانیتورینگ، Referer و تاریخچهٔ مرورگر ذخیره می‌شود. هر کسی که به لاگ دسترسی داشته باشد می‌تواند تا ۲۴ ساعت نقش آن ادمین را replay کند. (خود replay محدود به ۲۴ ساعت است، ولی هنوز ریسک است.)

**اصلاح:** `initData` را فقط از هدر (`X-Telegram-Init-Data`) یا بدنهٔ POST بخوانید؛ GET را رد کنید؛ در JS همیشه POST/هدر بفرستید. `auth_date` را سخت‌گیرانه (مثلاً < ۱ ساعت) و در صورت نیاز nonce یک‌بارمصرف اضافه کنید.

---

### P1-4) `SourceUpdate` با وجود فایل‌های failed، `ok=true` برمی‌گرداند

**فایل:** `src/SourceUpdate.php` خط `938`

```php
$out['ok'] = true;
```

بدون توجه به `$out['failed']` / `$out['reverted']`. مصرف‌کننده در `app/api.php:525-526` هم فقط `ok` را می‌بیند و «updated» را بالا می‌برد.

**اثر:** ادمین فکر می‌کند همهٔ فایل‌ها آپدیت شدند، در حالی که بعضی‌ها lint fail خورده و revert شده‌اند. عیب‌یابی سخت می‌شود.

**اصلاح:** `ok = ($failed === 0 && $reverted === 0 && $error === '')`؛ فهرست فایل‌های failed را در پاسخ بدهید.

---

### P1-5) بکاپ MySQL بدون `ORDER BY` و با `OFFSET` (احتمال جاافتادن/تکرار ردیف)

**فایل:** `src/DbBackup.php` خطوط `301-315`

```php
$rows = $pdo->query("SELECT * FROM `{$dbName}`.`{$name}` LIMIT 500 OFFSET {$off}")->fetchAll();
```

بدون `ORDER BY`، ترتیب ردیف‌ها در InnoDB تضمینی نیست؛ با نوشتن هم‌زمان می‌تواند ردیفی را جا بیندازد یا تکرار کند. `OFFSET` بزرگ هم به‌مرور کند می‌شود.

**اصلاح:** اگر جدول PK دارد، pagination کلیدی (`WHERE pk > ? ORDER BY pk LIMIT 500`) یا اولویت `mysqldump --single-transaction --quick`؛ برای جدول بدون PK، `ORDER BY` روی همهٔ ستون‌ها یا قفل/اسنپ‌شات.

---

### P1-6) اعتبارسنجی نکردن `id`/`user_id` در API (ساخت رکورد آشغال)

**فایل:** `app/api.php` خطوط `76-139`

**تست واقعی:**
```text
POST user_toggle_admin  user_id=0&v=1  → {"ok":true}
users_list → user_id=0, is_admin=1, bot_limit=1 ...
```
`Store::setAllowed()` هم `user(0)` را می‌سازد.

**اثر:** دادهٔ آشغال، شمارش کاربران اشتباه، احتمال دور زدن منطق روی `user_id=0` در آینده. `bot_status id=999999` هم ۲۰۰ برمی‌گرداند (no-op بی‌صدا).

**اصلاح:** `id > 0` و وجود رکورد را الزامی کنید؛ در غیر این صورت ۴۰۴. `bot_status` باید ربات را پیدا کند و در صورت نبود ۴۰۴ بدهد.

---

### P1-7) نبود محدودیت تلاش ورود در پنل فاکسیما

**فایل:** `templates/faxima/panel/login.php` خطوط `45-97`

`password_verify` و `session_regenerate_id` خوب هستند، اما rate limit/lockout/دفتاع brute-force وجود ندارد.

**اصلاح:** شمارش تلاش ناموفق بر اساس IP+username، تأخیر تدریجی، قفل موقت، لاگ و هشدار؛ در صورت امکان 2FA.

---

### P1-8) خاموش بودن بررسی TLS در چند نقطهٔ قالب‌ها

نمونه‌ها:
- `templates/mirza/x-ui_single.php:121,150,180` و `wgdashboard.php:111,179,208,240`
- `templates/mirza/functions.php:206` و `alireza_single.php` (چند خط)
- `templates/faxima/cron/cron.php:265`
- `templates/faxima/api/handlers/ServiceConfigsHandler.php:83`
- `templates/faxima/re/rx/function/database_helpers_2.php:2828,2850`
- `templates/uptime-bot-telegram/lib/monitor.php:212`

**اثر:** در محیط‌هایی که این کدها به APIهای مالی/پنل‌ها وصل می‌شوند، MITM می‌تواند داده را بخواند/تغییر دهد. بعضی از این‌ها برای پنل‌های self-signed هستند، ولی باید آگاهانه و با گزینهٔ config باشد، نه hardcode.

**اصلاح:** یک کلاینت HTTP مشترک با `verify=true` و امکان افزودن CA/self-signed به‌صورت opt-in؛ موارد باقی‌مانده را audit کنید.

---

### P1-9) تزریق از طریق نام branch در SelfUpdate (سوپرادمین/ریپوی قالب)

**فایل:** `src/SelfUpdate.php` خط `271`

```php
$ahead = self::git('rev-list --count HEAD..origin/' . $branch);
```

`$branch` از `git rev-parse --abbrev-ref HEAD` می‌آید و escape نمی‌شود. نام branch می‌تواند کاراکترهایی مثل `;`/`$()` داشته باشد (git فقط فاصله/`~^:?*[` و چند مورد دیگر را منع می‌کند). اگر سوپرادمین یک ریپوی قالب مخرب اضافه کند (یا ریپو hijack شود)، امکان اجرای دستور روی سرور هست.

**اصلاح:** `escapeshellarg('HEAD..origin/' . $branch)` یا اعتبارسنجی با `git check-ref-format --branch`؛ همهٔ ورودی‌های git را escape کنید.

---

## ۳.۱) وضعیت رفع یافته‌ها (به‌روزرسانی همین نسخه)

همهٔ یافته‌های P0 و P1 در کد اعمال و با lint/سلف‌تست/تست‌های تهاجمی سندباکس تأیید شدند. هیچ مورد P0/P1 «فقط گزارش‌شده» باقی نمانده است.

| مورد | وضعیت | محل اصلاح |
|---|---|---|
| P0-1 CSRF پنل فاکسیما | ✅ رفع شد | `templates/faxima/panel/user.php` (POST + `_action` + CSRF)، `panel/product.php`، `panel/cancelService.php`، `panel/productedit.php`، `panel/lib/csrf.php` |
| P0-2 افشای کلید درگاه / ساخت ادمین | ✅ رفع شد | `app/api.php`: ماسک secret برای غیرسوپر، تغییر نقش/سوپرادمین فقط سوپر، `user_id>0` + وجود کاربر؛ کارت/درگاه/سقف/قیمت برای هر ادمین آزاد شد (مدل ادمین مورداعتماد) |
| P0-3 نبود قفل وبهوک | ✅ رفع شد | `bot.php`: `flock` برای `update_<id>` و `user_<uid>`، پاسخ ۲۰۰ برای تکراری، قفل در shutdown آزاد می‌شود |
| P0-4 پرداخت/grant غیراتمیک | ✅ رفع شد | `src/Payment/Payments.php`: `approveByAdmin()` و `markCryptoPaid()` داخل `atomic()` با rollback |
| P0-5 خرابی config میرزا | ✅ رفع شد | `src/Manager.php`: اعتبارسنجی db/port، escape صحیح single/double quote، temp + `php -l` + `rename` |
| P0-6 حذف/غیرفعال ناسازگار | ✅ رفع شد | `src/BotLifecycle.php` + استفاده در `bot.php` و `app/api.php`: webhook، backup، drop DB، حذف پوشه، رکورد |
| P0-7 شکست table نادیده گرفته می‌شد | ✅ رفع شد | `src/Manager.php::triggerTable()` + `bot.php`: retry، ثبت خطای HTTP/cURL، rollback کامل |
| P1-1 حداقل مبلغ AqaPay | ✅ رفع شد | `src/Payment/AqaPay.php`: رد مبلغ زیر کف/بالای سقف به‌جای گرد کردن بی‌صدا |
| P1-2 نشت پیام استثنا | ✅ رفع شد | `app/api.php`: `internalError()` با request-id + لاگ، حذف پاسخ خام |
| P1-3 initData در Query | ✅ رفع شد | `app/assets/js/app.js` هدر `X-Telegram-Init-Data`؛ `api.php` فقط هدر/بدنهٔ POST |
| P1-4 `ok=true` با فایل failed | ✅ رفع شد | `src/SourceUpdate.php`: `failed_files` و `ok=false` |
| P1-5 بکاپ بدون ORDER BY | ✅ رفع شد | `src/DbBackup.php`: keyset روی PK، ترتیب قطعی، temp امن، پشتیبانی FK |
| P1-6 اعتبارسنجی id | ✅ رفع شد | `app/api.php` و `bot.php`: بررسی عددی/وجود/مالکیت قبل از عملیات |
| P1-7 rate limit ورود | ✅ رفع شد | `templates/faxima/panel/login.php`: ۷ خطا/۱۵ دقیقه بر IP+username |
| P1-8 TLS خاموش | ✅ رفع شد | `templates/mirza/ssl_verify.php`، `templates/faxima/function.php`، uptime monitor؛ opt-out صریح با env |
| P1-9 تزریق branch در SelfUpdate | ✅ رفع شد | `src/SelfUpdate.php`: اعتبارسنجی ref + `escapeshellarg` |

**سخت‌سازی تکمیلی:** `.htaccess` ریشه اکنون `.env`، `.git`، config backup، `*.sql.gz`، `*.db`، `*.sqlite3`، `*.key`، `*.pem`، `*.crt`، `*.zip`/`*.tar*` و لاگ/قفل را می‌بندد؛ راهنمای Nginx/Apache در `docs/DEPLOY_SECURITY_FA.md` است.

**محدودیت‌های آگاهانه (باقی‌مانده):**
- ساخت end-to-end با Telegram/MySQL واقعی هنوز در این محیط اجرا نشده (توکن و MySQL در دسترس نیست)؛ منطق با سندباکس و dry-run پوشش داده شد.
- `templates/uptime-bot-telegram/lib/ssl.php` عمداً برای «بازرسی گواهی self-signed» تأیید TLS را خاموش می‌کند؛ این مسیر داده‌ای/پرداختی نیست.
- PGClockBot همچنان catalog/help-only است (`buildable=false`) چون بیلدر PHP نمی‌تواند Python/aiogram/PostgreSQL/FastAPI نصب کند.

---

## ۴) تست‌های انجام‌شده و شواهد

| تست | نتیجه |
|---|---|
| `php -l` روی همهٔ فایل‌های PHP پروژه | بدون خطا |
| `tools/selftest_templates.php` | **320 passed, 0 failed** |
| `tools/selftest_source.php` | **106 موفق، 0 ناموفق** |
| `tools/selftest_payments.php` | **PASSED** |
| `node --check app/assets/js/app.js` | OK |
| `tools/dryrun.php faxima/mirza/uptime/pasargad` | موفق (پاک‌سازی هم انجام شد) |
| `tools/dryrun.php pgclock` | درست رد شد: `Not buildable` |
| `tools/healthcheck.php` | فقط `getMe` با توکن ساختگی fail (طبیعی)؛ دو هشدار base_url غیر HTTPS و MySQL خاموش |
| API بدون initData | `403` |
| API با initData معتبر سوپر | `200` |
| API با کاربر عادی | `403` |
| API با initData ۲۵ ساعته | `403` |
| API با initData ساختگی `user.id=0` | `403` |
| ادمین غیرسوپر → `settings_get` | **۲۰۰ + افشای `nowpay_ipn`** |
| ادمین غیرسوپر → `super_add` | `403` (درست) |
| ادمین غیرسوپر → `src_update` | `403` (درست) |
| ادمین غیرسوپر → `user_toggle_admin` | **۲۰۰** |
| `user_toggle_admin user_id=0&v=1` | **رکورد user 0 با `is_admin=1` ساخت** |
| `bot_status id=999999` | `200` (no-op بی‌صدا) |
| `bot_delete id=999999` | `404` (درست) |
| وبهوک با secret غلط | `403` |
| وبهوک با secret درست + JSON نامعتبر | `200` (بدون crash) |
| وبهوک با `update_id:"abc"` | `200` (اما نوع داده غیرمنتظره) |
| پچ config میرزا با رمز دارای `'` و `\` | **Parse error در `php -l`** |

> ردیف‌های بولد، نتیجهٔ تست **قبل از اصلاح** هستند و اکنون رفتارشان تغییر کرده است. نتایج پس از اصلاح:

| تست پس از اصلاح | نتیجه |
|---|---|
| `php -l` کل مخزن (بدون vendor) | **510 فایل، 0 خطا** |
| سلف‌تست‌ها (templates/source/payments) | **320/106/PASSED** |
| API با initData معتبر سوپر/ادمین | `200` (بدون ساخت رکورد کاربر) |
| API با initData معتبر ولی کاربر بدون رکورد | `403` و **هیچ رکوردی ساخته نشد** |
| API با initData ۲۵ ساعته / `user.id=0` | `403` |
| ادمین غیرسوپر → `settings_get` | `200` ولی `nowpay_ipn`/کلیدها **ماسک** شده |
| ادمین غیرسوپر → `user_toggle_admin` / `user_set_limit` | `403` |
| ادمین غیرسوپر → تغییر `card_number` / `gateway` / sandbox | `403` |
| ادمین غیرسوپر → تأیید پرداخت خودش | `403` |
| ادمین غیرسوپر → `bot_status` ربات دیگران | `403` |
| پچ config میرزا با رمز `p'a\s"$word` | **PASS** (رفت‌وبرگشت مقدار + `php -l`) |
| پچ config فاکسیما/آپ‌تایم | **PASS** (temp + lint + atomic rename) |
| `tickets.php?ajax=mark_ticket_seen` بدون POST/توکن | `405/403` |
| جابجایی محصول با GET (legacy) | اجرا نمی‌شود؛ فقط POST + CSRF |

---

## ۵) بهبودهای ساختاری پیشنهادی

1. **سرویس چرخهٔ عمر ربات** (`BotLifecycle`) برای create/enable/disable/delete؛ حذف کامل webhook + DB + backup در یک مسیر.
2. **تراکنش و state machine پرداخت**: `pending → await_pay → paid → granted → used`؛ `granted_at` و retry idempotent.
3. **قفل‌های هم‌زمانی**: `flock` برای webhook/update، build per-user و approve پرداخت.
4. **Audit Log**: هر تغییر حساس (حذف ربات، تغییر نقش، تغییر کلید، شارژ/کسر دستی) با ادمین/زمان/IP.
5. **مدیریت secretها**: ماسک در API، رمزنگاری at-rest، rotation، جداسازی نقش مالی از نقش پشتیبانی.
6. **احراز هویت مینی‌اپ**: فقط هدر، `auth_date` کوتاه‌تر، nonce یک‌بارمصرف، rate limit.
7. **CSRF سراسری** در همهٔ پنل‌ها؛ تبدیل actionهای GET به POST.
8. **تست E2E واقعی**: یک محیط Docker با MySQL + یک توکن تست تلگرام (یا شبیه‌ساز Bot API) برای build/table/webhook.
9. **CI**: PHPStan/Psalm + `php -l` + سلف‌تست‌ها + `node --check`.
10. **مستندات استقرار**: نمونهٔ Nginx با `deny` برای `data/`, `src/`, `tools/`, `templates/`, `config.php`, `.git`؛ چون `.htaccess` فقط Apache است.
11. **آماده‌سازی استقرار**: `.htaccess` فعلی `config.php.bak` را نمی‌بندد؛ الگوی `config.php*` را ببندید.
12. **UX ساخت**: اگر table/webhook شکست خورد، پیام قابل‌اقدام برای ادمین + retry خودکار، نه «دستی بازش کن» برای کاربر عادی.
13. **بکاپ**: pagination کلیدی/`mysqldump`، نام فایل temp تصادفی، رمز از `--defaults-extra-file` به‌جای env.
14. **dry-run صادق‌تر**: الان DB/table را واقعاً نمی‌سازد و می‌گوید «بی‌خطر»؛ یا آن را «static check» بنامید یا ساخت واقعی در sandbox انجام دهید.
15. **پاک‌سازی لاگ‌ها**: صفحه‌بندی UTF-8-safe و ماسک اطلاعات حساس؛ دسترسی لاگ را به سوپر محدود کنید.

---

## ۶) نقشهٔ راه پیشنهادی

### این هفته (قبل از production)
- [x] CSRF در `panel/user.php` (P0-1)
- [x] ماسک/محدودسازی secretها و نقش‌ها (P0-2)
- [x] قفل وبهوک/ساخت (P0-3)
- [x] تراکنش پرداخت (P0-4)
- [x] escape config میرزا + `php -l` بعد از patch (P0-5)
- [x] یکسان‌سازی delete/disable (P0-6)
- [x] fail کردن build در صورت شکست table (P0-7)

### این ماه
- [x] AqaPay min amount (P1-1)
- [x] حذف پیام خام خطا (P1-2)
- [x] initData از هدر (P1-3)
- [x] اصلاح `ok` در SourceUpdate (P1-4)
- [x] بکاپ ORDER BY/keyset (P1-5)
- [x] اعتبارسنجی id (P1-6)
- [x] rate limit ورود (P1-7)
- [x] TLS در قالب‌ها (P1-8)
- [x] escape branch در SelfUpdate (P1-9)

### بلندمدت
- [x] Audit Log برای عملیات حساس API (رمزنگاری secret at-rest باقی است)
- [ ] E2E با MySQL/Telegram sandbox + CI
- [x] سرویس مشترک چرخهٔ عمر ربات
- [x] مستندات Nginx/Apache و چک‌لیست hardening
- [x] بازبینی امنیتی قالب‌های ثالث (mirza/faxima) و حذف `SSL_VERIFYPEER=false`

---

## ۷) محدودیت‌های این بازبینی

- بدون توکن واقعی تلگرام و MySQL، مسیر ساخت/وبهوک واقعی تست نشد؛ یافته‌های مربوط به build از کد + dry-run + تست پچ config به‌دست آمده‌اند.
- قالب‌های `faxima` و `mirza` بسیار بزرگ‌اند (صدها فایل)؛ بازبینی آن‌ها نمونه‌ای و بر اساس الگوهای پرخطر بوده، نه خط‌به‌خط.
- تست هم‌زمانی (race) به‌صورت عملی با دو درخواست هم‌زمان شبیه‌سازی نشد؛ بر اساس نبود قفل در کد گزارش شده است و باید با تست بار تأیید شود.
- گزارش‌های قدیمی `BUG_FIXES.md`/`DEBUG_REPORT.md` مبنای این بازبینی نبوده‌اند؛ همهٔ یافته‌های بالا از کد فعلی و تست مستقیم آمده‌اند.
