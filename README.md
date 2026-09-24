# ربات‌ساز فاکسیما/میرزا 🤖

ربات تلگرامی مجزا که با چند دکمه، ربات **فاکسیما** یا **میرزا** می‌سازد:
پوشه + دیتابیس MySQL جدا + کانفیگ + وبهوک — همه خودکار. فقط **توکن + آیدی ادمین + نام** از کاربر گرفته می‌شود.

## نصب سریع (کپی و اجرا) ⚡

روی سرور لینوکس (پیش‌نیاز: `git` و `PHP 8.2+` و MySQL)، این سه خط را کپی و اجرا کن:

> **چرا PHP 8.2؟** قالب‌ها `vendor/` آمادهٔ Composer دارند و فایل `platform_check` آن‌ها نسخهٔ مورد نیاز را ثبت کرده است — قالب **فاکسیما** به **PHP ≥ 8.2** نیاز دارد (قالب میرزا با 8.1 هم کار می‌کند). اگر نسخهٔ PHP پایین‌تر باشد، `index.php` و `table.php` ربات ساخته‌شده هر دو خطای **500** می‌دهند. ربات‌ساز همین را قبل از ساخت می‌سنجد و اگر نسخه کافی نباشد با پیام روشن متوقف می‌کند تا ربات خراب تحویل داده نشود.

```bash
git clone https://github.com/alisystemit/botsaz-faxima.git
cd botsaz-faxima
bash tools/install.sh
```

یا تک‌خطی:

```bash
git clone https://github.com/alisystemit/botsaz-faxima.git && cd botsaz-faxima && bash tools/install.sh
```

اسکریپت همه‌چیز را قدم‌به‌قدم می‌پرسد (توکن @BotFather، آیدی سوپرادمین، آدرس دامنه، مشخصات MySQL) و نصب را کامل می‌کند. راهنمای کامل هر مرحله در بخش «نصب خودکار روی لینوکس» همین فایل (پایین‌تر) است. اگر SSH نداری (هاست cPanel) یا روی ویندوز/لاراگن هستی، سراغ بخش **«نصب دستی — راهنمای کامل»** برو. 📖 [مشاهده در گیت‌هاب](https://github.com/alisystemit/botsaz-faxima/blob/main/README.md)

## ساختار

```
bot.php               → وبهوک ربات اصلی (ربات‌ساز)
config.php            → تنظیمات (از روی config.example.php)
.htaccess             → مسدودسازی دسترسی مستقیم به tools/ src/ templates/ data/ و README
src/BotApi.php        → wrapper تلگرام (params سازگار با urlencoded)
src/Store.php         → دیتابیس مدیریتی SQLite (کاربران + ربات‌ها)؛ اگر sqlite نبود خودکار MySQL
src/Manager.php       → کپی قالب، ساخت دیتابیس، پچ کانفیگ، ست وبهوک، رمزگشایی توکن فرزند
src/Migrator.php      → مایگریشن schema دیتابیس مدیریتی
src/Logger.php        → لاگ فایلی روی data/logs
templates/faxima/     → سورس واقعی فاکسیما (https://github.com/Mmd-Amir/Faoxima — فروش VPN، ریفکتور میرزا)
templates/mirza/      → سورس واقعی میرزا (https://github.com/NewMreza/botmirzapanel — فروش VPN مرزبان)
bots/<slug>/          → ربات‌های ساخته‌شده (کرون با secret محافظت می‌شود)
tools/install.php     → نصب اولیه
tools/install.sh      → نصب اتوماتیک روی لینوکس (config را بازنویسی نمی‌کند)
tools/set_webhook.php → ست وبهوک ربات اصلی
tools/selftest.php    → تست عملکردی
tools/healthcheck.php → بررسی پوشه ↔ رکورد ↔ دیتابیس ↔ وبهوک (exit code برای CI)
tools/dryrun.php      → ساخت خشک بدون تلگرام (exit code برای CI)
tools/cron_dispatcher.php → اجرای کرون همه ربات‌ها از یک خط crontab
```

## نصب دستی — راهنمای کامل (هاست و لاراگون) 🛠️

این راهنما برای وقتی است که نمی‌خواهی/نمی‌توانی از `tools/install.sh` استفاده کنی (هاست اشتراکی cPanel، یا ویندوز/لاراگون). همهٔ مراحل را می‌توانی قدم‌به‌قدم دستی انجام بدهی.

> ⚠️ `tools/` ،`src/` ،`templates/` ،`data/` و `config.php` از طریق مرورگر **403** هستند (حفاظت `.htaccess`). یعنی `tools/install.php` را نباید با `https://domain/.../tools/install.php` باز کنی — باید از **ترمینال/SSH** اجرا شود.

### گام ۰ — پیش‌نیازها

| نیاز | هاست لینوکس (cPanel/VPS) | لاراگن (ویندوز) |
| :--- | :--- | :--- |
| PHP | **8.2+** برای ساخت «فاکسیما»؛ 8.1+ فقط «میرزا» | `Menu → PHP → Version` نسخهٔ 8.2+ (پیش‌فرض لاراگن 8.1 است) |
| اکستنشن‌ها | `curl` `mbstring` `openssl` `json` `pdo_mysql` + `pdo_sqlite` (اختیاری) | همه با بستهٔ PHP نصب‌اند ✅ |
| MySQL/MariaDB | یوزر با دسترسی **`CREATE DATABASE`** | `root` بدون پسورد روی `127.0.0.1:3306` ✅ |
| دامنه + SSL | **https عمومی** (تلگرام وبهوک http قبول نمی‌کند) | ندارد → حتماً ngrok/cloudflared (گام ۶) |
| سورس کامل | کل ریپو، به‌خصوص `templates/faxima` و `templates/mirza` | همین |

```bash
php -v                                              # نسخهٔ PHP
php -m | grep -E 'curl|mbstring|pdo_mysql|openssl'   # اکستنشن‌ها
```

> **چرا PHP 8.2؟** قالب‌ها `vendor/` کامپایل‌شده دارند و `platform_check` خودشان نسخهٔ لازم را ثبت کرده (فاکسیما ≥ 8.2، میرزا ≥ 8.1). ربات‌ساز قبل از ساخت می‌سنجد و با پیام روشن متوقف می‌شود؛ اگر هم با اجبار بالاتر ببری، `index.php`/`table.php` ربات ساخته‌شده خطای **500** می‌دهند.

### گام ۱ — دریافت سورس

**هاست بدون SSH:** ZIP ریپو را از گیت‌هاب دانلود و در File Manager آپلود و Extract کن؛ مسیر پیشنهادی `public_html/botsaz-faxima`.

**هاست با SSH:**
```bash
cd ~/public_html
git clone https://github.com/alisystemit/botsaz-faxima.git
```

**لاراگن:** پوشه را در `C:\laragon\www\botsaz-faxima` بگذار (کپی مستقیم یا `git clone`).

✅ چک کن این‌ها موجود باشند: `bot.php`، `templates/faxima/index.php`، `templates/mirza/index.php`، `bots/.htaccess`.
> پوشه‌های `bots/` و `data/` و `data/.htaccess` در خود ریپو هستند؛ پس با آپلود ساخته می‌شوند.

### گام ۲ — ساخت `config.php`

`config.example.php` را کپی و به نام `config.php` ذخیره کن (در File Manager یا `cp`/`copy`)، بعد این کلیدها را پر کن:

| کلید | چه بگذاری | نمونه |
| :--- | :--- | :--- |
| `main_token` | توکن ربات‌ساز از @BotFather | `123456:ABC-DEF...` |
| `super_admins` | آرایهٔ آیدی عددی خودت از @userinfobot | `[987654321]` |
| `base_url` | آدرس عمومی پروژه **بدون اسلش آخر** | `https://example.com/botsaz-faxima` |
| `db_host` / `db_port` | **جدا** از هم؛ هرگز `host:port` در یک فیلد | `127.0.0.1` / `3306` |
| `db_user` / `db_pass` | یوزری با `CREATE DATABASE` | — |
| `db_prefix` | پیشوند نام دیتابیس‌های فرزند | `botsaz_` (هاست: ببین گام ۳) |
| `php_bin` | اگر `php` در PATH نیست، مسیر کامل | `C:\laragon\bin\php\php-8.2.x\php.exe` |
| `secret_key` | رشتهٔ تصادفی ۳۲+ کاراکتری | `php -r 'echo bin2hex(random_bytes(16));'` |

> ⚠️ `secret_key` را **بعد از ساخت ربات‌ها عوض نکن**؛ توکن‌های رمزنگاری‌شده دیگر رمزگشایی نمی‌شوند.

### گام ۳ — دیتابیس MySQL

ربات‌ساز برای هر ربات فرزند یک دیتابیس جدا می‌سازد؛ پس یوزرت باید `CREATE DATABASE` داشته باشد.

**VPS/سرور اختصاصی (root):**
```sql
CREATE USER 'botsaz'@'localhost' IDENTIFIED BY 'پسورد_قوی';
GRANT ALL PRIVILEGES ON `botsaz_%`.* TO 'botsaz'@'localhost';
FLUSH PRIVILEGES;
```

> 📌 **cPanel / هاست اشتراکی:** پنل cPanel معمولاً نام دیتابیس را با پیشوند نام کاربری‌ات می‌سازد و فقط روی `youruser_%` privilege می‌دهد. اگر `CREATE DATABASE botsaz_x` خطای **access denied** داد، در `config.php` بگذار:
> ```php
> 'db_prefix' => 'youruser_botsaz_',
> ```
> با همین پیشوند، هم دیتابیس فرزند و هم دیتابیس `manager` ساخته می‌شوند.

### گام ۴ — نصب اولیه (`tools/install.php`)

این ابزار پوشه‌ها را می‌سازد، `config.php` را (اگر نباشد) از روی example کپی می‌کند، دیتابیس مدیریتی را آماده می‌کند و `data/.htaccess` را می‌نویسد. **اجرای مجددش امن است** (config موجود بازنویسی نمی‌شود).

**هاست با SSH / ترمینال cPanel:**
```bash
php tools/install.php
```

**لاراگن (PowerShell/CMD):**
```powershell
C:\laragon\bin\php\php-8.2.x\php.exe tools\install.php
```

**هاست بدون SSH — cPanel → Cron Jobs** (یک خط موقت بگذار، بعد حذفش کن):
```
* * * * * /usr/local/bin/php /home/USER/public_html/botsaz-faxima/tools/install.php >/dev/null 2>&1
```
> مسیر `php` را با `command -v php` پیدا کن؛ در cPanel معمولاً `/usr/local/bin/php` است.

**کلاً بدون ترمینال:** پوشه‌ها و `data/.htaccess` با آپلود موجودند، `config.php` را در گام ۲ ساختی، و دیتابیس مدیریتی SQLite **خودکار** موقع اولین درخواست ساخته می‌شود — پس فقط همان `config.php` لازم است.

### گام ۵ — پیکربندی وب‌سرور

این پروژه بخش زیادی از حفاظتش را با `.htaccess` می‌دهد؛ پس باید فعال باشد:

- **Apache:** روی پوشهٔ سایت `AllowOverride All` لازم است. لاراگن خودش برای هر پوشه در `www` یک vhost با `AllowOverride All` می‌سازد (اینجا `C:\laragon\etc\apache2\sites-enabled\auto.botsaz-faxima.test.conf`) و ردیف `hosts` را هم اضافه می‌کند. روی هاست اگر `.htaccess` اعمال نشد، از پشتیبانی بخواه `AllowOverride All` بگذارد.
- **nginx:** قواعد `deny` را خودت تکرار کن (بخش «حفاظت‌های `.htaccess`» همین فایل).

بدون `AllowOverride`، `tools/` ،`src/` ،`templates/` ،`data/` و `config.php` از وب قابل دانلود می‌شوند.

### گام ۶ — ست وبهوک

تلگرام فقط `https://` **عمومی** قبول دارد.

**روش A — ترمینال (توصیه‌شده):**
```bash
php tools/set_webhook.php
# یا با آدرس صریح:
php tools/set_webhook.php https://example.com/botsaz-faxima/bot.php
```
خروجی `"ok": true` یعنی موفق (exit code هم 0/1 است).

**روش B — بدون ترمینال:** secret وبهوک این است:
```
sha256( <main_token> + "_faoxima_webhook_secret" )
```
(اگر در محیط سرور `TELEGRAM_WEBHOOK_SECRET` ست شده، همان جایگزین می‌شود.) بعد در مرورگر:
```
https://api.telegram.org/bot<TOKEN>/setWebhook?url=<URL_ENCODED_BASE>/bot.php&secret_token=<SECRET>
```
✅ **تأیید:** `https://api.telegram.org/bot<TOKEN>/getWebhookInfo` → `url` پر باشد و `last_error_message` خالی.

**لاراگن:** `http://botsaz-faxima.test` از اینترنت دیده نمی‌شود و https هم ندارد (فایل `httpd-ssl.conf` لاراگن فقط `Listen 443` و cipherها را دارد؛ نه `VirtualHost *:443` نه گواهی). پس حتماً تونل بزن:
```powershell
ngrok http 80
# یا: cloudflared tunnel --url http://localhost:80
```
آدرس `https://xxxx.ngrok-free.app` را در `base_url` بگذار، `config.php` را ذخیره کن، بعد `tools/set_webhook.php` را اجرا کن.

### گام ۷ — کرون

بدون کرون، انقضا/حجم/گزارش کارت ربات‌های فرزند کار نمی‌کند.

**هاست — cPanel → Cron Jobs یا `crontab -e`:**
```
*/5 * * * * /usr/local/bin/php /home/USER/public_html/botsaz-faxima/tools/cron_dispatcher.php >/dev/null 2>&1
```

**لاراگن/ویندوز — Task Scheduler (تست‌شده):**
```powershell
schtasks /Create /TN "botsaz-cron" /SC MINUTE /MO 5 /F /TR "C:\laragon\bin\php\php-8.2.x\php.exe C:\laragon\www\botsaz-faxima\tools\cron_dispatcher.php"
schtasks /Query  /TN "botsaz-cron"     # وضعیت
schtasks /Run    /TN "botsaz-cron"     # اجرای دستی
schtasks /Delete /TN "botsaz-cron" /F  # حذف
```
> اگر مسیرها فاصله داشت، داخل `/TR` هر کدام را جداگانه داخل `"` بگذار.

> ⚠️ بعضی هاست‌های اشتراکی `exec` را غیرفعال می‌کنند. `cron_dispatcher` در این حالت دیگر Fatal نمی‌گیرد و با هشدار `⚠️ exec is disabled by this host` رد می‌شود؛ آن‌وقت از **روش ۲** (کرون مستقیم هر ربات — بخش «کرون ربات‌های فرزند») استفاده کن.

### گام ۸ — تست و تأیید نهایی

```bash
php tools/healthcheck.php      # exit 0 یعنی سالم
php tools/selftest.php         # بدون تلگرام/MySQL
php tools/dryrun.php mirza t1  # ساخت خشک (بدون تلگرام)
```

**بدون ترمینال** (`tools/` از HTTP 403 است):
1. `getWebhookInfo` را بالا چک کن (url پر، `last_error_message` خالی).
2. به ربات `/start` بزن — باید منوی 👑 بیاید.
3. این آدرس‌ها در مرورگر باید **403** بدهند: `config.php` ،`src/Manager.php` ،`tools/selftest.php` ،`data/` و `/` (لیست پوشه؛ `Options -Indexes`).

### عیب‌یابی نصب دستی

| علامت | علت | راه‌حل |
| :--- | :--- | :--- |
| `config.php missing` (500) | `config.php` ساخته نشده | گام ۲ |
| `main_token not set!` | توکن هنوز placeholder است | `main_token` واقعی بگذار |
| `Access denied ... CREATE DATABASE` | نبود privilege یا پیشوند cPanel | گام ۳ — `GRANT` یا `db_prefix` |
| وبهوک همیشه **403** | secret نادرست یا `TELEGRAM_WEBHOOK_SECRET` ناهماهنگ | دوبارهٔ گام ۶ (روش A/B) |
| `setWebhook` خطای *not https* | `base_url` روی `http` است | دامنهٔ https یا ngrok |
| ربات ساخته می‌شود ولی 500 | نسخهٔ PHP پایین‌تر از حداقل قالب | گام ۰ — ارتقا به 8.2+ |
| `/` سایت 403 می‌دهد | طبیعی: `index.php` در ریشه نیست و `Options -Indexes` فعال است | — |
| کرون اجرا نمی‌شود | نبود cron/Task یا `php_bin` نادرست | گام ۷ |
| `⚠️ exec is disabled by this host` | هاست `exec` را بسته | کرون مستقیم هر ربات (روش ۲) |
| ربات جواب نمی‌دهد ولی وبهوک ok است | خطا در ارسال تلگرام | `getWebhookInfo.last_error_message` + `data/logs/` |

## نصب خودکار روی لینوکس با `tools/install.sh`

اسکریپت `tools/install.sh` نصب تعاملی ربات‌ساز روی سرور لینوکس (VPS/هاست با دسترسی SSH) را انجام می‌دهد: سؤال می‌پرسد، `config.php` را امن می‌سازد، دیتابیس مدیریتی را آماده می‌کند، وبهوک را ست می‌کند و قالب‌ها را چک می‌کند.

### ۱. پیش‌نیازها

| نیاز | توضیح |
| :--- | :--- |
| سیستم‌عامل | لینوکس (Ubuntu 20.04+ توصیه می‌شود) با دسترسی SSH |
| PHP | نسخه **8.1 یا بالاتر** (`php -v`) + اکستنشن‌های `curl`, `mbstring`, `pdo_mysql` (و `pdo_sqlite` اختیاری) |
| MySQL/MariaDB | یوزری با دسترسی **`CREATE DATABASE`** (ربات‌ساز برای هر ربات فرزند یک دیتابیس جدا می‌سازد) |
| دامنه + SSL | `base_url` باید **https عمومی** باشد، وگرنه تلگرام وبهوک را قبول نمی‌کند |
| سورس پروژه | کل پوشه پروژه (همراه `templates/faxima` و `templates/mirza`) روی سرور باشد |

### ۲. اطلاعاتی که قبل از اجرا آماده کن

1. **توکن ربات‌ساز:** از [@BotFather](https://t.me/BotFather) با `/newbot` یک ربات بساز و توکن را کپی کن (شکل `123456:ABC...`).
2. **آیدی عددی سوپرادمین:** از [@userinfobot](https://t.me/userinfobot) بگیر (فقط عدد، مثلاً `123456789`).
3. **آدرس پایه (`base_url`):** آدرس عمومی پوشه پروژه **بدون اسلش آخر**، مثلاً `https://example.com/botsaz-faxima`.
4. **مشخصات MySQL:** هاست (معمولاً `127.0.0.1`)، پورت (`3306`)، یوزر، پسورد. پیشوند دیتابیس‌ها پیش‌فرض `botsaz_` است.

### ۳. اجرا

```bash
cd /path/to/botsaz-faxima
bash tools/install.sh
```

اسکریپت قدم‌به‌قدم جلو می‌رود (با `set -e` یعنی در اولین خطای جدی می‌ایستد):

| مرحله | چه می‌کند |
| :--- | :--- |
| ۰. پیش‌نیازها | اگر `php` نباشد یا قدیمی‌تر از 8.1 باشد، با تأیید تو نصب خودکار می‌کند (`php` + اکستنشن‌های `curl/mbstring/mysql/sqlite3`)؛ اکستنشن‌های ناقص هم همین‌طور؛ `git` و `curl` هم چک می‌شوند |
| ۲. توکن | فرمت را چک می‌کند + با `getMe` زنده بودنش را تست می‌کند (اگر شبکه قطع بود فقط هشدار می‌دهد) |
| ۳. سوپرادمین | فقط عدد ۵+ رقمی قبول می‌کند |
| ۴. `base_url` | اسلش آخر را تمیز می‌کند؛ اگر `https` نباشد هشدار جدی می‌دهد (وبهوک کار نمی‌کند) |
| ۵. MySQL | مشخصات را با پیش‌فرض می‌گیرد، بعد **واقعاً وصل می‌شود و یک دیتابیس تستی می‌سازد و پاک می‌کند** تا هم اتصال و هم دسترسی `CREATE DATABASE` ثابت شود؛ تا درست نشود جلو نمی‌رود (یا با تأیید تو رد می‌شود) |
| ۶. ساخت `config.php` | **اگر `config.php` از قبل باشد، بازنویسی نمی‌کند.** در غیر این صورت آن را با `var_export` می‌سازد (پس کاراکترهای خاص مثل `$` و `'` در پسورد مشکلی ایجاد نمی‌کنند) + یک `secret_key` تصادفی ۳۲کاراکتری برای رمزنگاری توکن‌ها تولید می‌کند |
| ۷. نصب اولیه | `tools/install.php` را اجرا می‌کند (ساخت پوشه‌ها + دیتابیس مدیریتی SQLite + `.htaccess` حفاظتی) |
| ۸. ست وبهوک | `tools/set_webhook.php` را با آدرس `{base_url}/bot.php` صدا می‌زند (**همراه secret**، چون `bot.php` هدر secret تلگرام را چک می‌کند). اگر ست نشود فقط هشدار می‌دهد و ادامه می‌دهد |
| ۹. بررسی قالب‌ها | وجود `config.php` / `index.php` هر دو قالب فاکسیما و میرزا را چک می‌کند |
| ۱۰. بررسی نهایی | با `getMe` + `getWebhookInfo` چک می‌کند ربات واقعاً بالاست یا نه و آخرش واضح می‌گوید «ربات اجراست ✅» یا دقیقاً چه چیزی مانده (مثلاً دستور دستی ست وبهوک) |

### ۴. بعد از نصب

1. در تلگرام به ربات‌ساز `/start` بده — باید منوی مدیر (👑) را ببینی.
2. سلامت را چک کن:
   ```bash
   php tools/healthcheck.php
   ```
   خطای `main_token` و `MySQL` نباید بماند؛ هشدار `base_url` لوکال یعنی وبهوک کار نمی‌کند.
3. کرون مرکزی را فعال کن (برای ربات‌های فرزند لازم است):
   ```
   */5 * * * * php /path/to/botsaz-faxima/tools/cron_dispatcher.php
   ```
   جزئیات در بخش «کرون ربات‌های فرزند» همین فایل.
4. فلو عادی: کاربر «🤖 ساخت ربات جدید» می‌زند → درخواست ثبت می‌شود → تو در «📋 درخواست‌های جدید» تأیید/رد می‌کنی → کاربر نوع ربات را انتخاب و با توکن+آیدی ادمین+نام، رباتش را تحویل می‌گیرد.

   - کاربران «مجاز» (`👥 کاربران مجاز` / سوپرادمین) بدون درخواست مستقیم وارد مرحلهٔ انتخاب نوع می‌شوند.
   - کاربر عادی هم می‌تواند درخواست ثبت کند (بدون اینکه از قبل در لیست مجاز‌ها باشد)؛ تأیید تو همان درخواست را باز می‌کند و بعد از یک ساخت، مصرف می‌شود.

### ۵. اجرای مجدد و عیب‌یابی

- اجرای دوباره اسکریپت **امن** است: `config.php` موجود بازنویسی نمی‌شود؛ فقط وبهوک و بررسی‌ها تکرار می‌شوند.
- بیشتر مشکلات همان‌جا موقع نصب گرفته می‌شوند (PHP/اکستنشن نصب می‌شود، اتصال و دسترسی دیتابیس تست می‌شود، فرمت توکن چک می‌شود) و در آخر هم وضعیت واقعی ربات (`getMe` + `getWebhookInfo`) گزارش می‌شود.
- `PHP پیدا نشد` و apt هم نداری → دستی: `sudo apt install php php-cli php-curl php-mbstring php-mysql php-sqlite3`.
- آخر نصب گفت «ربات هنوز بالا نیست» → معمولاً `base_url` عمومی/https نیست؛ بعداً دستی بزن: `php tools/set_webhook.php https://domain/botsaz-faxima/bot.php` و دوباره چک کن.
- `ساخت دیتابیس ناموفق` موقع ساخت ربات → یوزر MySQL دسترسی `CREATE DATABASE` ندارد؛ به یوزر دسترسی بده یا از یوزر root (یا هم‌رده) استفاده کن.
- `secret_key` را بعد از ساخت ربات‌ها عوض نکن (توکن‌های ذخیره‌شده با همان کلید رمزگشایی می‌شوند).
- اگر `php` در PATH نیست، در `config.php` کلید `php_bin` را با مسیر کامل ست کن (کرون و lint از آن استفاده می‌کنند).

## فلو ساخت ربات (داخل تلگرام)

1. «🤖 ساخت ربات جدید» → انتخاب ✨ فاکسیما / 🌙 میرزا (اینلاین)
2. ارسال **توکن** (اعتبارسنجی با `getMe`)
3. ارسال **آیدی عددی ادمین** ربات جدید
4. ارسال **نام انگلیسی** (مثلا `shop1` یا `vpn1`) → تمام!

**فاکسیما (سورس واقعی):** کپی کامل سورس + دیتابیس خالی جدا + پچ خودکار `config.php` (توکن، آیدی ادمین، نام دیتابیس، یوزرنیم ربات، آدرس دامنه، هاست/پورت دیتابیس — دقیقاً با همان regex نصب‌کننده رسمی) + حذف پوشه `installer/` برای امنیت + وبهوک روی `index.php` همراه `secret_token` (فرمول `lib/WebhookAuth.php`) + اجرای خودکار `table.php` برای ساخت جدول‌ها و درج ادمین.

**میرزا (سورس واقعی):** کپی کامل سورس + دیتابیس خالی جدا + پچ خودکار `config.php` (توکن، آیدی ادمین، نام دیتابیس، یوزرنیم ربات، آدرس دامنه، هاست/پورت دیتابیس — دقیقاً مثل نصب‌کننده رسمی) + حذف پوشه `installer/` برای امنیت + وبهوک روی `index.php` + اجرای خودکار `table.php` برای ساخت جدول‌ها.

> 🔒 `table.php` هر دو قالب حالا در برابر فراخوانی مستقیم HTTP با `?secret=` محافظت می‌شود.
> secret از `hash('sha256', $APIKEY . '_<type>_table_secret')` ساخته می‌شود؛ پس فقط کسی که توکن
> همان ربات را دارد می‌تواند جدول‌ها را بسازد. ربات‌ساز موقع ساخت خودش این `secret` را می‌فرستد،
> و لینک‌های داخل پنل/پیام‌های خطای فاکسیما هم همان را می‌سازند. اجرای `include` داخلی و CLI مستثنی‌اند.

## امکانات ربات‌ساز

- 👑 سوپرادمین: 📊 آمار (کاربران/ربات‌ها/مجموع کاربران فرزندها)، 📣 همگانی به کاربران ربات‌ساز، 👥 افزودن/حذف/لیست کاربران مجاز، 📋 همه ربات‌ها
- ✅ کاربر مجاز: ساخت ربات، 📦 ربات‌های من (آمار، همگانی به کاربران همان ربات، ست مجدد وبهوک، فعال/غیرفعال، حذف)

## امکانات ربات‌های فرزند

- فاکسیما (سورس کامل فروش VPN — مرزبان/پاسارگارد/3x-ui/Remnawave/ربکا + مینی‌اپ + پنل وب): آمار ربات‌ساز از جدول `user` خوانده می‌شود و همگانی هم پشتیبانی می‌شود
- میرزا (سورس کامل فروش VPN): خرید خودکار کانفیگ مرزبان/3x-ui، اکانت تست، درگاه‌ها، پنل ادمین کامل — آمار و همگانی پشتیبانی می‌شود

## نکته‌ها

- هر دو سورس رسماً PHP 8.2+ می‌خواهند؛ لوکال لاراگون 8.1 است (سینتکس روی 8.1 بدون خطاست؛ روی هاست با 8.2 مشکلی نیست).
- `base_url` باید **https عمومی** باشد تا ست وبهوک و اجرای `table.php` کار کند (روی لوکال: ngrok).
- فاکسیما علاوه بر ربات، مینی‌اپ و پنل وب دارد که روی همان پوشه سرو می‌شوند؛ کرون‌جاب‌هایش (`cron/`) را در هاست تنظیم کن (در پنل ربات، خط `curl` آماده با `secret` را کپی کن).
- `bots/.htaccess` فایل‌های `config.php` / `error_log` / `hash.txt` / `info` / `*.log` / `*.sql` /
  `*.sqlite` / `*.json` / `*.lock` و پوشه‌های `states/` ،`backups/` ،`logs/` را مسدود می‌کند؛
  فقط `index.php` / `table.php` / `cron/*` باز می‌مانند. (queueهای زمان‌اجرا مثل `cron/users.json`
  و `cron/info` هم زیر این قاعده‌اند.)
- پورت غیرپیش‌فرض MySQL مشکلی ندارد: `db_host` و `db_port` جدا نگه داشته می‌شوند
  (هاست هرگز به‌صورت `host:port` در `mysqli_connect` یا DSN نمی‌نشیند).
- اگر `TELEGRAM_WEBHOOK_SECRET` در محیط سرور ست شده باشد، **هم** ربات‌ساز **هم** فاکسیما همان را
  به‌عنوان secret وبهوک استفاده می‌کنند (قبلاً فقط قالب آن را می‌شناخت و همهٔ وبهوک‌ها 403 می‌شدند).

## کرون ربات‌های فرزند (مهم)

ربات‌های ساخته‌شده نیاز به کرون دارند (انقضای اکانت، حذف خودکار، هشدار حجم، گزارش کارت). دو روش وجود دارد:

### روش ۱: کرون دیسبچر مرکزی (توصیه‌شده)
خط زیر را به `crontab` اضافه کن:
```
*/5 * * * * php /path/to/botsaz-faxima/tools/cron_dispatcher.php
```
این ابزار روی همهٔ `bots/*` بچرخد و کرون‌های فاکسیما/میرزا را اجرا کند.

### روش ۲: کرون مستقیم هر ربات
```
*/5 * * * * php /path/to/bots/bot_slug/cron/cron.php
```
فقط از **CLI** اجرا کن؛ فراخوانی HTTP این اسکریپت‌ها نیاز به `?secret=` دارد (گارد `cron/_guard.php`).

### دکمه راه‌اندازی کرون
در پنل ربات‌ساز، دستور `/cron` یا «⏰ کرون» را بزن تا خط کرون را ببینید.
اگر کرون را از پنل ربات فرزند ثبت کنی، خط `curl` شامل `?secret=` مناسب همان ربات است.

## تست و سلامت‌رسانی

```powershell
php tools/selftest.php          # تست عملکردی
php tools/healthcheck.php       # بررسی پوشه ↔ رکورد ↔ دیتابیس ↔ وبهوک
php tools/dryrun.php faxima test  # ساخت خشک (بدون تلگرام)
```

> یوزر MySQL باید دسترسی `CREATE DATABASE` داشته باشد؛ اگر نداشته باشد ساخت ربات با خطا متوقف می‌شود.

## حفاظت‌های `.htaccess` و پیکربندی وب‌سرور

این پروژه بخش زیادی از حفاظتش را با `.htaccess` می‌دهد (مسدودسازی `templates/` ،`tools/` ،`src/` ،
`data/` ،`.git/` و فایل‌های حساس). برای همین:

- **Apache:** دستور `AllowOverride All` (یا دست‌کم `FileInfo AuthConfig Limit Indexes`) روی پوشهٔ
  سایت لازم است؛ بدون آن همهٔ این مسدودسازی‌ها بی‌اثر می‌شود. روی لوکال لاراگون در
  `conf/extra/httpd-vhosts.conf` یا همان vhost پیش‌فرض این دستور هست.
- **nginx:** به `.htaccess` توجهی ندارد؛ باید همان قواعد را خودت در `server` تکرار کنی، مثلاً:
  ```nginx
  location ~ ^/(tools|src|templates|data|docs)/ { deny all; }
  location ~ ^/config\.php$ { deny all; }
  location ~ ^/\.(env|git) { deny all; }
  ```
  و داخل `bots/` هم فایل‌های `config.php` / `*.log` / `*.json` / `*.lock` را deny کنی.
- `templates/.htaccess` با `Require all denied` کل درخت قالب‌ها را می‌بندد (rewrite ریشه به‌تنهایی
  زیرپوشه‌هایی مثل `templates/faxima/{api,app,sub}` را نمی‌پوشاند — آنجا scopeِ rewrite عوض می‌شود).
