# ربات‌ساز فاکسیما/میرزا 🤖

ربات تلگرامی مجزا که با چند دکمه، ربات **فاکسیما** یا **میرزا** می‌سازد:
پوشه + دیتابیس MySQL جدا + کانفیگ + وبهوک — همه خودکار. فقط **توکن + آیدی ادمین + نام** از کاربر گرفته می‌شود.

---

## محتویات فهرست

- [نصب سریع (کپی و اجرا) ⚡](#نصب-سریع-کپی-و-اجرا)
- [ساختار پروژه](#ساختار-پروژه)
- [بعد از نصب: چکر سلامت و گزارش لاگ 🔍📜](#بعد-از-نصب-چکر-سلامت-و-گزارش-لاگ)
- [بک‌آپ خودکار دیتابیس 💾](#بک‌آپ-خودکار-دیتابیس)
- [بروزرسانی از گیت‌هاب 🔄](#بروزرسانی-از-گیت‌هاب)
- [کرون ربات‌های فرزند (مهم) 🕐](#کرون-ربات‌های-فرزند-مهم)
- [حفاظت‌های `.htaccess` و پیکربندی وب‌سرور 🔒](#حفاظت‌های-htaccess-و-پیکربندی-وب‌سرور)
- [نصب دستی — راهنمای کامل (هاست و لاراگون) 🛠️](#نصب-دستی-راهنمای-کامل-هاست-و-لاراگون)
- [نصب خودکار روی لینوکس با `tools/install.sh`](#نصب-خودکار-روی-لینوکس-با-installsh)
- [فلو ساخت ربات (داخل تلگرام)](#فلو-ساخت-ربات-داخل-تلگرام)
- [امکانات ربات‌ساز و ربات‌های فرزند](#امکانات-ربات‌ساز-و-ربات‌های-فرزند)
- [نکات مهم](#نکات-مهم)
- [مشکلات رایج و راه‌حل](#مشکلات-رایج-و-راه‌حل)

---

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

اسکریپت همه‌چیز را قدم‌به‌قدم می‌پرسد (توکن @BotFather، آیدی سوپرادمین، آدرس دامنه، مشخصات MySQL) و نصب را کامل می‌کند؛ SSL هم می‌گیرد و vhost را می‌نویسد. **بعدش** می‌توانی هر لحظه با `bash tools/install.sh --check` مطمئن شوی همه‌چیز برقرار است و با `--logs` ببینی ربات از کجا خطا داده (بخش بعدی). راهنمای کامل هر مرحله در بخش «نصب خودکار روی لینوکس» همین فایل (پایین‌تر) است. اگر SSH نداری (هاست cPanel) یا روی ویندوز/لاراگون هستی، سراغ بخش **«نصب دستی — راهنمای کامل»** برو. 📖 [مشاهده در گیت‌هاب](https://github.com/alisystemit/botsaz-faxima/blob/main/README.md)

---

## ساختار پروژه

```
bot.php                    → وبهوک ربات اصلی (ربات‌ساز)
config.php                 → تنظیمات (از روی config.example.php) — هرگز توسط git تأثیر نمی‌شود
config.example.php         → قالب تنظیمات
.htaccess                  → مسدودسازی دسترسی مستقیم به tools/ src/ templates/ data/ و README
src/
  BotApi.php               → wrapper تلگرام (params سازگار با urlencoded)
  Store.php                → دیتابیس مدیریتی SQLite (کاربران + ربات‌ها)؛ اگر sqlite نبود خودکار MySQL
  Manager.php              → کپی قالب، ساخت دیتابیس، پچ کانفیگ، ست وبهوک، رمزگشایی توکن فرزند
  Migrator.php             → مایگریشن schema دیتابیس مدیریتی
  Logger.php               → لاگ فایلی روی data/logs
  DbBackup.php             → کلاس بک‌آپ دیتابیس فرزندها
templates/
  faxima/                  → سورس واقعی فاکسیما (https://github.com/Mmd-Amir/Faoxima)
  mirza/                   → سورس واقعی میرزا (https://github.com/NewMreza/botmirzapanel)
bots/<slug>/               → ربات‌های ساخته‌شده (کرون با secret محافظت می‌شود)
  config.php               → کانفیگ هر ربات — هرگز توسط git تأثیر نمی‌شود
  cron/                    → کرون‌های ربات (users.json, info, etc.)
  states/                  → وضعیت‌های موقت ربات
  logs/                    → لاگ‌های ربات
  backups/                 → بک‌آپ‌های ذخیره‌شده
tools/
  install.php              → نصب اولیه
  install.sh               → نصب اتوماتیک روی لینوکس (config را بازنویسی نمی‌کند)
  install.sh --check       → چکر کامل سلامت بعد از نصب (فقط‌خواندنی)
  install.sh --logs        → نمایش همهٔ خطاهای ربات از همهٔ منابع (فقط‌خواندنی)
  set_webhook.php          → ست وبهوک ربات اصلی
  selftest.php             → تست عملکردی
  healthcheck.php          → بررسی پوشه ↔ رکورد ↔ دیتابیس ↔ وبهوک (exit code برای CI)
  dryrun.php               → ساخت خشک بدون تلگرام (exit code برای CI)
  cron_dispatcher.php      → اجرای کرون همه ربات‌ها از یک خط crontab
  backup_dispatcher.php    → بک‌آپ خودکار دیتابیس ربات‌ها و ارسال به ادمین
  update.sh                → بروزرسانی از گیت‌هاب بدون نصب مجدد
  diagnose.php             → تشخیص خودکار مشکلات سیستمی
data/                      → دیتابیس‌ها، لاگ‌ها، بک‌آپ‌ها (محافظت‌شده)
  *.sqlite                 → دیتابیس‌های SQLite
  logs/                    → لاگ‌های سیستمی
  backups/                 → بک‌آپ‌های SQL gzip
README.md                  → این فایل
.gitignore                 → فایل‌های محافظت‌شده از git
```

---

## بعد از نصب: چکر سلامت و گزارش لاگ 🔍📜

نصب‌کننده فقط برای نصب نیست؛ **هر وقت** خواستی بفهمی «همه‌چیز برقرار است یا نه» و «ربات از کجا خطا داده»، دو دستور زیر را بزن. هیچ‌کدام چیزی را تغییر نمی‌دهند (فقط‌خواندنی) و هر دو **روی سروری که از کار افتاده** هم امن‌اند:

```bash
bash tools/install.sh --check              # همه‌چیز درست است؟ (۱۱ بخش)
bash tools/install.sh --logs               # چه خطاهایی ثبت شده؟
bash tools/install.sh --logs --days=3      # فقط ۳ روز اخیر
bash tools/install.sh --help               # فهرست حالت‌ها
```

### `--check` — چکر کامل سلامت

خروجی جدول `[OK] / [WARN] / [FAIL]` است و اگر **حتی یک `FAIL`** باشد کد خروج `1` می‌دهد (مناسب برای cron/CI):

| بخش | دقیقاً چه می‌پرسد |
| :--- | :--- |
| PHP | نسخه ≥ ۸.۲ و اکستنشن‌های `pdo_mysql` `pdo_sqlite` `curl` `mbstring` `openssl` `json` |
| وب‌سرور | نصب است؟ سرویس بالاست؟ `mod_rewrite` فعال است (بدون آن `.htaccess` بی‌اثر می‌شود)؟ |
| فایل‌ها | `config.php`، هر ۴ فایل قالب، و اینکه `www-data` بتواند در `data/` بنویسد |
| `config.php` | `base_url` با `https://`؟ توکن واقعی؟ `super_admins` و `secret_key` جایگزین شده‌اند؟ |
| vhost | `DocumentRoot` برای وب‌سرور قابل دسترس است؟ وهاست دیگری هم `ServerName` همین دامنه را ندارد؟ |
| SSL | گواهی Let's Encrypt هست؟ چند روز تا انقضا مانده (زیر ۱۴ روز = هشدار)؟ |
| DNS | دامنه resolve می‌شود؟ به کجا اشاره می‌کند؟ + رکورد **AAAA** هم خوانده می‌شود، چون تلگرام با IPv6 هم می‌رود و اگر آدرس اشتباه باشد، پیامش به ماشین دیگری می‌رسد و `404` برمی‌گرداند در حالی که پینگِ خودِ این سرور سبز است |
| دیتابیس | اتصال MySQL، سطح migration، مجوز `CREATE DATABASE`، تعداد ربات‌های فرزند |
| تلگرام | `getMe`، webhook ثبت‌شده، `last_error_message` و زمانش، آپدیت‌های صف‌شده |
| **وبهوک** | **کد HTTP واقعی آدرس**: `200`/`403` سالم · `404` یعنی دقیقاً همان خطای تلگرام · `500` یعنی PHP خطا دارد · `0` یعنی کسی جواب نمی‌دهد |
| امنیت | `config.php` ،`src/` ،`tools/` ،`data/` نباید از وب دانلود شوند |
| ربات‌های فرزند | وبهوک هر ربات فعال جداگانه پینگ می‌شود (بدون webhook ثبت‌شده یا با `404` گزارش می‌شود) |

اگر وبهوک `404` داد، خودش ریشه را هم نشان می‌دهد: `404` را با `/bot.php` در ریشهٔ دامنه مقایسه می‌کند تا بگوید مشکل **مسیرِ داخل `base_url`** است یا **اینکه اصلاً هیچ vhostای این پروژه را سرو نمی‌کند**.

### `--logs` — همهٔ خطاهای ربات، از همهٔ منابع

فقط مشکلات را نشان می‌دهد؛ هیچ خط `INFO`/`DEBUG` چاپ نمی‌شود:

1. **لاگ خود برنامه** — `data/logs/*.log` با فیلتر واقعی بر اساس تاریخِ فایل (سطوح `ERROR`/`WARN`/`CRITICAL`/`FATAL`)
2. **خطای وب‌سرور** — `error.log` آپاچی/nginx، شامل خطاهای PHP
3. **تحویل‌های ناموفق وبهوک** — از access log فقط همان درخواست‌های `bot.php` و `/bots/` که `4xx/5xx` برگردانده‌اند (یعنی همان چیزی که تلگرام `Wrong response from the webhook` می‌نامد)
4. **`error_log` خود PHP** — ۲۰ خط آخر
5. **وضعیت خودِ تلگرام** — `getWebhookInfo` با `url`، تعداد صف، آخرین خطا و **ترجمهٔ زمان وقوع** آن

> اگر هیچ لاگی نبود، صریح می‌گوید: *«این ربات هنوز حتی یک آپدیت هم دریافت نکرده»* — که خودش یعنی وبهوک کار نمی‌کند.

### تفاوت با `tools/healthcheck.php`

| ابزار | سطح بررسی |
| :--- | :--- |
| `bash tools/install.sh --check` | **سیستم**: وب‌سرور، vhost، SSL، DNS، دسترسی فایل‌ها، وبهوک از بیرون، امنیت HTTP |
| `php tools/healthcheck.php` | **برنامه**: تطابق پوشه ↔ رکورد ↔ دیتابیس ↔ وبهوک |

هر دو را با هم بزن؛ یکی پایه را می‌سنجد و دیگری داده‌های داخلش را.

---

## بک‌آپ خودکار دیتابیس 💾

هر ربات فرزند دیتابیس MySQL خودش دارد. تک‌سیستم بک‌آپ (`tools/backup_dispatcher.php` + `src/DbBackup.php`) دیتابیس هر ربات فعال را خروجی می‌گیرد (gzip) و با توکن خود همان ربات به ادمینش در تلگرام ارسال می‌کند.

تغییر ساعت از داخل ربات (ادمین): دکمه «💾 بکاپ دیتابیس» → فعال/غیرفعال، پیش‌فرض روزی ۱ یا ۲ بار، ساعت دلخواه (مثلاً `3,15`)، بکاپ دستی و مشاهده آخرین ارسال هر ربات. تنظیمات در دیتابیس مدیریتی ذخیره می‌شود (نیازی به ویرایش config نیست).

خط کرون:
```
0 3,15 * * * php /path/to/botsaz-faxima/tools/backup_dispatcher.php
```
کرون ۵دقیقه‌ای (`cron_dispatcher.php`) هم اسلات‌ها را خودش چک می‌کند؛ ارسال هر اسلات فقط یک‌بار در روز انجام می‌شود.

پیش‌نیازها:
- `mysqldump` اگر باشد استفاده می‌شود، وگرنه دامپ داخلی PHP (نیازی به نصب چیز اضافه نیست)
- `curl` باید فعال باشد
- ادمین ربات باید قبلاً با ربات تعامل داشته باشد (تلگرام فقط به کاربرانی فایل می‌فرستد که start کرده‌اند)
- فایل‌های بزرگ‌تر از ~۴۵MB در تلگرام جا نمی‌شوند و در `data/backups/` سرور نگه داشته می‌شوند

---

## بروزرسانی از گیت‌هاب 🔄

وقتی روی گیت‌هاب تغییری ثبت می‌شود، روی سرور کافیه یک بار بزنی:

```bash
bash /root/botsaz-faxima/tools/update.sh
```

### آنچه `update.sh` انجام می‌دهد:

| مرحله | توضیح |
| :--- | :--- |
| ۰ | **پیش‌بینی**: بررسی اینترنت، فضای دیسک، وضعیت git |
| ۱ | **بک‌آپ**: کپی `config.php` و `bots/*/config.php` به `/tmp/` |
| ۲ | **`git pull`**: `--ff-only` اول، بعد merge fallback |
| ۳ | **بازرسی config**: اگر `config.php` تغییر کرده، از بک‌آپ برمی‌گرداند |
| ۴ | **`.htaccess`**: فایل‌های حذف‌شده را از git برمی‌گرداند |
| ۵ | **`/root` permissions**: `chmod 711 /root` |
| ۶ | **vhost**: ریلود Apache/nginx |
| ۷ | **systemd sandbox**: فیکس `InaccessiblePaths=/root` |
| ۸ | **PCRE JIT**: `pcre.jit=0` در همهٔ `.ini` فایل‌ها |
| ۹ | **ریستارت سرویس‌ها**: Apache/nginx/php-fpm |
| ۱۰ | **تست سلامت**: اجرای `install.sh --check` |
| ۱۱ | **تأیید وبهوک**: بررسی `getWebhookInfo` |
| ۱۲ | **نوتیفیکیشن**: ارسال پیام تلگرام به ادمین |

### آپشن‌ها:

```bash
bash tools/update.sh --dry-run    # فقط چاپ می‌کنه، تغییری نمی‌ده
bash tools/update.sh --no-restart # سرویس‌ها رو ریستارت نمی‌کنه
bash tools/update.sh --force      # بدون پیش‌بینی، مستقیم اجرا
```

### بدون `update.sh` (دستی):

```bash
cd /root/botsaz-faxima
git pull origin main
# اگر .htaccess پاک شد:
git show HEAD:.htaccess > .htaccess
chmod 711 /root
bash tools/install.sh --check
systemctl restart apache2 || systemctl restart nginx
```

### کرون هفتگی اپدیت (اختیاری):

```
0 6 * * 0 bash /root/botsaz-faxima/tools/update.sh
```

> **چرا `config.php` ایمن است؟** `config.php` و `bots/*/config.php` در `.gitignore` هستند و `git` هرگز آن‌ها را ردیف نمی‌کند. `update.sh` اضافه روی هم این ایمنی را تأیید می‌کند.

---

## کرون ربات‌های فرزند (مهم) 🕐

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

---

## حفاظت‌های `.htaccess` و پیکربندی وب‌سرور 🔒

این پروژه بخش زیادی از حفاظتش را با `.htaccess` می‌دهد (مسدودسازی `templates/` ،`tools/` ،`src/` ،`data/` ،`.git/` و فایل‌های حساس). برای همین:

### Apache
دستور `AllowOverride All` (یا دست‌کم `FileInfo AuthConfig Limit Indexes`) روی پوشهٔ سایت لازم است؛ بدون آن همهٔ این مسدودسازی‌ها بی‌اثر می‌شود. روی لوکال لاراگون در `conf/extra/httpd-vhosts.conf` یا همان vhost پیش‌فرض این دستور هست.

### nginx
به `.htaccess` توجهی ندارد؛ باید همان قواعد را خودت در `server` تکرار کنی:

```nginx
server {
    listen 443 ssl default_server;
    listen 80 default_server;
    server_name alibot.api-system.top;

    root /root/botsaz-faxima;
    index index.php;

    # --- Security: Block sensitive paths ---
    location ~ ^/(tools|src|templates|data|docs)/ { deny all; }
    location ~ ^/config\.php$ { deny all; }
    location ~ ^/\.(env|git) { deny all; }

    # --- Allow bots/<slug>/index.php and bots/<slug>/table.php ---
    location ~ ^/bots/.*\.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # --- Block sensitive bot files ---
    location ~ ^/bots/.*\.(env|json|log|sqlite|sql|bak|txt|lock)$ { deny all; }

    # --- MIME types ---
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    # --- SSL ---
    ssl_certificate /etc/letsencrypt/live/alibot.api-system.top/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/alibot.api-system.top/privkey.pem;
    ssl_session_cache shared:SSL:10m;
    ssl_protocols TLSv1.2 TLSv1.3;
}
```

و داخل `bots/` هم فایل‌های `config.php` / `*.log` / `*.json` / `*.lock` را deny کنی.

> ⚠️ **تداخل Apache و nginx:** اگر هر دو نصب باشند، فقط یکی باید روی پورت 80/443 فعال باشد. `install.sh` و `update.sh` هر دو این تداخل را تشخیص می‌دهند.

### `.htaccess` فایل‌ها (بازیابی شده)

| فایل | محتوا |
| :--- | :--- |
| `.htaccess` | مسدودسازی `tools/` ،`src/` ،`templates/` ،`data/` ،`.git/` و README |
| `bots/.htaccess` | مسدودسازی `config.php` / `*.log` / `*.json` / `*.lock` در هر اسلات؛ فقط `index.php` / `table.php` / `cron/*` باز |
| `data/.htaccess` | مسدودسازی دسترسی مستقیم به دیتابیس‌ها و لاگ‌ها |
| `templates/.htaccess` | `Require all denied` برای کل درخت قالب‌ها |
| `templates/faxima/.htaccess` | تکمیل امنیت قالب فاکسیما |

> **نکته:** اگر بعد از `git pull` فایل `.htaccess` های پاک شدند، `update.sh` خودکار آن‌ها را از git برمی‌گرداند. همچنین می‌توانید دستی بزنید:
> ```bash
> git show HEAD:.htaccess > .htaccess
> git show HEAD:bots/.htaccess > bots/.htaccess
> ```

---

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

**لاراگون:** پوشه را در `C:\laragon\www\botsaz-faxima` بگذار (کپی مستقیم یا `git clone`).

✅ چک کن این‌ها موجود باشند: `bot.php`، `templates/faxima/index.php`، `templates/mirza/index.php`، `bots/.htaccess`.
> پوشه‌های `bots/` و `data/` و `data/.htaccess` در خود ریپو هستند؛ پس با آپلود ساخته می‌شوند.

### گام ۲ — ساخت `config.php`

`config.example.php` را کپی و به نام `config.php` ذخیره کن (در File Manager یا `cp`/`copy`)، بعد این کلیدها را پر کن:

| کلید | چه بگذاری | نمونه |
| :--- | :--- | :--- |
| `main_token` | توکن ربات‌ساز از @BotFather | `123456:ABC-DEF...` |
| `super_admins` | آرایهٔ آیدی عددی خودت از @userinfobot | `[987654321]` |
| `base_url` | آدرس عمومی پروژه **بدون اسلش آخر** و حتماً با `https://` | `https://example.com/botsaz-faxima` |
| `db_host` / `db_port` | **جدا** از هم؛ هرگز `host:port` در یک فیلد | `127.0.0.1` / `3306` |
| `db_user` / `db_pass` | یوزری با `CREATE DATABASE` | — |
| `db_prefix` | پیشوند نام دیتابیس‌های فرزند | `botsaz_` (هاست: ببین گام ۳) |
| `php_bin` | اگر `php` در PATH نیست، مسیر کامل | `C:\laragon\bin\php\php-8.2.x\php.exe` |
| `secret_key` | رشتهٔ تصادفی ۳۲+ کاراکتری | `php -r 'echo bin2hex(random_bytes(16));'` |

> ⚠️ **`base_url` رایج‌ترین علت «ربات جواب نمی‌دهد».** باید با `https://` شروع شود؛ آدرسی مثل `example.com/botsaz-faxima` (بدون scheme) یا `http://...` باعث می‌شود تلگرام آدرس را مستقیم بفرستد و جواب **`Wrong response from the webhook: 404`** بگیرد. نصب‌کنندهٔ لینوکس چنین ورودی‌ای را قبول نمی‌کند و تا `https://...` وارد نشود دوباره می‌پرسد. بعد از هر تغییر حتماً وبهوک را دوباره بزنید: `php tools/set_webhook.php`.

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

**هاست با SSH / ترمینال:**
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

**لاراگون:** `http://botsaz-faxima.test` از اینترنت دیده نمی‌شود و https هم ندارد (فایل `httpd-ssl.conf` لاراگن فقط `Listen 443` و cipherها را دارد؛ نه `VirtualHost *:443` نه گواهی). پس حتماً تونل بزن:
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
bash tools/install.sh --check   # فقط‌خواندنی: وب‌سرور، vhost، SSL، DNS، وبهوک، امنیت (لازم: SSH/لینوکس)
bash tools/install.sh --logs    # فقط‌خواندنی: همهٔ خطاهای ربات
php tools/healthcheck.php       # exit 0 یعنی سالم
php tools/selftest.php          # بدون تلگرام/MySQL
php tools/dryrun.php mirza t1   # ساخت خشک (بدون تلگرام)
```
> `install.sh` اسکریپت **bash** است؛ روی cPanel بدون SSH یا ویندوز اجرا نمی‌شود — آنجا سه دستور `php` پایین همین جدول کافی است.

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
| ربات جواب نمی‌دهد ولی وبهوک ok است | خطا در ارسال تلگرام | `getWebhookInfo.last_error_message` + `data/logs/` + `bash tools/install.sh --logs` |
| وبهوک `Wrong response from the webhook: 404` | `base_url` بدون `https://` یا با مسیر غلط | `https://` کامل بگذار، `php tools/set_webhook.php` بزن، بعد `bash tools/install.sh --check` |
| همین `404` ولی `base_url` درست است | یک وهاست دیگر هم همین `ServerName` را دارد (معمولاً `000-default-le-ssl.conf` که certbot جا می‌گذارد) و **اول** جواب می‌دهد | `a2dissite 000-default-le-ssl && systemctl reload apache2` |
| `AH00112 DocumentRoot ... does not exist` یا `AH00035 search permissions are missing` | مسیر پروژه برای `www-data` قابل دسترس نیست (معمولاً پروژه زیر `/root` است و `/root` حالت `0700` دارد) | حین نصب خودش می‌پرسد و با `y` تعمیر می‌کند؛ دستی: `chmod o+x /root` و `chown -R www-data:www-data data` |
| وبهوک یک لحظه `Connection refused` می‌دهد | معمولاً همان لحظهٔ `systemctl reload apache2` است؛ خودش جا می‌افتد | `systemctl status apache2` و بعد `bash tools/install.sh --check` |
| پینگ خودِ سرور `403` می‌دهد ولی لاگِ تلگرام `404` از `/var/www/html` | تلگرام به ماشین/وهاست دیگری رسیده — اول رکورد **AAAA** را ببین | `getent ahosts دامنه` و `bash tools/install.sh --check` (بخش DNS) |

---

## نصب خودکار روی لینوکس با `tools/install.sh`

اسکریپت `tools/install.sh` نصب تعاملی ربات‌ساز روی سرور لینوکس (VPS/هاست با دسترسی SSH) را انجام می‌دهد: سؤال می‌پرسد، پیش‌نیازهای سرور خالی را می‌سازد، گواهی SSL می‌گیرد، vhost وب‌سرور را می‌نویسد، `config.php` را امن می‌سازد، دیتابیس مدیریتی را آماده می‌کند، وبهوک را ست می‌کند و در پایان **هر دو گزارش سلامت و لاگ** را چاپ می‌کند. همان اسکریپت با `--check` و `--logs` هم قابل اجراست و آن دو حالت هیچ‌چیز را تغییر نمی‌دهند.

### ۱. پیش‌نیازها

| نیاز | توضیح |
| :--- | :--- |
| سیستم‌عامل | لینوکس (Ubuntu 20.04+ توصیه می‌شود) با دسترسی SSH |
| PHP | نصب‌کننده حداقل **8.1** را قبول می‌کند ولی ساخت «فاکسیما» **8.2+** می‌خواهد (`--check` همین را می‌سنجد). اکستنشن‌ها: `curl`, `mbstring`, `mysqli`, `pdo_mysql`, `sqlite3` |
| MySQL/MariaDB | یوزر با دسترسی **`CREATE DATABASE`** — نصب‌کنندهٔ لینوکس اگر `config.php` نباشد خودش یوزر اختصاصی `botsaz` می‌سازد و با لاگین واقعی + ساخت دیتابیس تستی اثبتش می‌کند |
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
| ۰. پیش‌نیاز سرور | سرور خالی را آماده می‌کند: ابزار پایه (`git`/`curl`)، وب‌سرور (آپاچی/nginx)، MySQL/MariaDB، به‌علاوه اختیاری phpMyAdmin — هر کدام را با یک سؤال. اگر `config.php` نباشد یک **یوزر MySQL اختصاصی `botsaz`** می‌سازد و فقط وقتی سبز گزارش می‌دهد که **لاگین واقعی روی TCP + ساختِ یک دیتابیس تستی** هر دو جواب داده باشند (root تازه روی Ubuntu با `auth_socket` است و این دقیقاً همان چیزی است که اتصال بعدی را می‌شکند) |
| ۰. PHP | اگر `php` نباشد یا قدیمی‌تر از 8.1 باشد، با تأیید تو نصب/ارتقا می‌کند (`php`, `php-cli`, `php-curl`, `php-mbstring`, `php-mysql`, `php-sqlite3`, `php-xml`, `php-zip`) |
| ۰. اکستنشن‌ها و ماژول وب‌سرور | `curl` `mbstring` `mysqli` `pdo_mysql` `sqlite3` را تک‌تک چک و نصب می‌کند؛ بعد ماژول مناسب وب‌سرور (`libapache2-mod-php` برای آپاچی، `php-fpm` برای nginx) |
| ۲. توکن | فرمت را چک می‌کند + با `getMe` زنده بودنش را تست می‌کند (اگر شبکه قطع بود فقط هشدار می‌دهد) |
| ۳. سوپرادمین | فقط عدد ۵+ رقمی قبول می‌کند |
| ۴. `base_url` | اسلش آخر را تمیز می‌کند، بعد **حلقهٔ سخت** می‌چرخد: ورودی بدون scheme، با `http://` (به‌جز `localhost`/`127.0.0.1`/`::1`) یا خالی را با پیام `[X]` رد و دوباره می‌پرسد تا بالاخره `https://...` وارد شود |
| ۴ب. SSL | دامنه را از `base_url` درمی‌آورد، `certbot` را نصب و اجرا می‌کند؛ اگر جواب نداد فقط هشدار می‌دهد و ادامه می‌دهد |
| ۵ب. vhost وب‌سرور | vhost آپاچی یا nginx + بلوک SSL را می‌نویسد. **قبل از نوشتن** می‌سنجد `www-data` واقعاً تا `DocumentRoot` راه دارد (وگرنه `AH00112`/`AH00035` می‌دهد و هیچ درخواستی به پروژه نمی‌رسد)؛ اگر نبود، دستورهای تعمیر را دقیق چاپ می‌کند و می‌پرسد «خودم تعمیرشان کنم؟» (پیش‌فرض **نه**، چون دسترسی به `/root` را باز می‌کند). **بعد از `a2ensite`** هر وهاستِ فعالِ دیگری که همین `ServerName` را دارد — معمولاً `000-default-le-ssl.conf` که خودِ certbot جا می‌گذارد — غیرفعال می‌کند، چون اولی روی :443 جواب می‌دهد و هر آپدیتی `404` می‌شود |
| ۵. MySQL | مشخصات را با پیش‌فرض می‌گیرد، بعد **واقعاً وصل می‌شود و یک دیتابیس تستی می‌سازد و پاک می‌کند** تا هم اتصال و هم دسترسی `CREATE DATABASE` ثابت شود؛ تا درست نشود جلو نمی‌رود (یا با تأیید تو رد می‌شود) |
| ۶. ساخت `config.php` | **اگر `config.php` از قبل باشد، بازنویسی نمی‌کند.** در غیر این صورت آن را با `var_export` می‌سازد (پس کاراکترهای خاص مثل `$` و `'` در پسورد مشکلی ایجاد نمی‌کنند) + یک `secret_key` تصادفی ۳۲کاراکتری برای رمزنگاری توکن‌ها تولید می‌کند |
| ۷. نصب اولیه | `tools/install.php` را اجرا می‌کند (ساخت پوشه‌ها + دیتابیس مدیریتی SQLite + `.htaccess` حفاظتی) |
| ۸. ست وبهوک | `tools/set_webhook.php` را با آدرس `{base_url}/bot.php` صدا می‌زند (**همراه secret**، چون `bot.php` هدر secret تلگرام را چک می‌کند). اگر ست نشود فقط هشدار می‌دهد و ادامه می‌دهد |
| ۹. بررسی کامل + گزارش لاگ | همان دو گزارش `--check` و `--logs` را چاپ می‌کند: ۱۱ بخش سلامت + همهٔ خطاهای ثبت‌شده از ۵ منبع. تا وقتی حتی یک `FAIL` باشد «ربات اجراست ✅» چاپ نمی‌شود و دقیقاً می‌گوید چه مانده و دستور تعمیرش چیست |

> 📌 شماره‌ها همان کامنت‌های داخل `tools/install.sh` است؛ ۴ب و ۵ب در عمل **قبل از** مرحلهٔ ۵ (MySQL) اجرا می‌شوند.

### ۴. بعد از نصب

1. در تلگرام به ربات‌ساز `/start` بده — باید منوی مدیر (👑) را ببینی.
2. **سلامت کل سیستم** را بگیر (توضیح کامل در بخش «بعد از نصب: چکر سلامت و گزارش لاگ»):
   ```bash
   bash tools/install.sh --check     # وب‌سرور، vhost، SSL، DNS، دسترسی فایل‌ها، وبهوک، امنیت
   bash tools/install.sh --logs      # همهٔ خطاهای ثبت‌شده، از همهٔ منابع
   ```
   تا شمارندهٔ `FAILURE(S)` صفر نشه دست نکش؛ هر `FAIL` دقیقاً دستور تعمیرش را هم چاپ می‌کند.
3. **سلامت داده‌های برنامه** را بگیر:
   ```bash
   php tools/healthcheck.php
   ```
   خطای `main_token` و `MySQL` نباید بماند؛ هشدار `base_url` لوکال یعنی وبهوک کار نمی‌کند.
4. کرون مرکزی را فعال کن (برای ربات‌های فرزند لازم است):
   ```
   */5 * * * * php /path/to/botsaz-faxima/tools/cron_dispatcher.php
   ```
   جزئیات در بخش «کرون ربات‌های فرزند» همین فایل.
5. فلو عادی: کاربر «🤖 ساخت ربات جدید» می‌زند → درخواست ثبت می‌شود → تو در «📋 درخواست‌های جدید» تأیید/رد می‌کنی → کاربر نوع ربات را انتخاب و با توکن+آیدی ادمین+نام، رباتش را تحویل می‌گیرد.

   - کاربران «مجاز» (`👥 کاربران مجاز` / سوپرادمین) بدون درخواست مستقیم وارد مرحلهٔ انتخاب نوع می‌شوند.
   - کاربر عادی هم می‌تواند درخواست ثبت کند (بدون اینکه از قبل در لیست مجاز‌ها باشد)؛ تأیید تو همان درخواست را باز می‌کند و بعد از یک ساخت، مصرف می‌شود.

### ۵. اجرای مجدد و عیب‌یابی

- **اولین کار بعد از هر مشکل:** `bash tools/install.sh --check` — ۱۱ بخش را می‌سنجد و هر `FAIL` را با دستور تعمیرش چاپ می‌کند. بعد `bash tools/install.sh --logs` تا ببینی دقیقاً کجا و کِی خطا داده.
- اجرای دوباره اسکریپت **امن** است: `config.php` موجود بازنویسی نمی‌شود؛ فقط وبهوک و بررسی‌ها تکرار می‌شوند.
- بیشتر مشکلات همان‌جا موقع نصب گرفته می‌شوند (PHP/اکستنشن نصب می‌شود، اتصال و دسترسی دیتابیس تست می‌شود، فرمت توکن چک می‌شود) و در آخر هم هر دو گزارش سلامت و لاگ چاپ می‌شود.
- `PHP پیدا نشد` و apt هم نداری → دستی: `sudo apt install php php-cli php-curl php-mbstring php-mysql php-sqlite3`.
- **وبهوک `Wrong response from the webhook: 404`** — سه علت رایج، هر سه را `--check` تشخیص می‌دهد:
  1. `base_url` بدون `https://` یا با مسیر غلط است (مثلاً `domain.com/botsaz` بدون scheme) → `https://` کامل بگذار و `php tools/set_webhook.php` بزن.
  2. یک وهاست دیگر هم همین دامنه را دارد (معمولاً `000-default-le-ssl.conf` که certbot جا می‌گذارد) و اول جواب می‌دهد → `a2dissite 000-default-le-ssl && systemctl reload apache2`.
  3. `www-data` به `DocumentRoot` راه ندارد (پروژه زیر `/root` با حالت `0700`) → `chmod o+x /root` و `chown -R www-data:www-data data`.
- `ساخت دیتابیس ناموفق` موقع ساخت ربات → یوزر MySQL دسترسی `CREATE DATABASE` ندارد؛ به یوزر دسترسی بده یا از یوزر root (یا هم‌رده) استفاده کن.
- `secret_key` را بعد از ساخت ربات‌ها عوض نکن (توکن‌های ذخیره‌شده با همان کلید رمزگشایی می‌شوند).
- اگر `php` در PATH نیست، در `config.php` کلید `php_bin` را با مسیر کامل ست کن (کرون و lint از آن استفاده می‌کنند).
- **403 Forbidden همیشه:** اگر `www-data` نمی‌تواند از `/root` برود → `chmod 711 /root` + `chown -R www-data:www-data data/`. اگر AppArmor هم فعاله، systemd drop-in لازمه.

---

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

---

## امکانات ربات‌ساز

- 👑 سوپرادمین: 📊 آمار (کاربران/ربات‌ها/مجموع کاربران فرزندها)، 📣 همگانی به کاربران ربات‌ساز، 👥 افزودن/حذف/لیست کاربران مجاز، 📋 همه ربات‌ها
- ✅ کاربر مجاز: ساخت ربات، 📦 ربات‌های من (آمار، همگانی به کاربران همان ربات، ست مجدد وبهوک، فعال/غیرفعال، حذف)

## امکانات ربات‌های فرزند

- فاکسیما (سورس کامل فروش VPN — مرزبان/پاسارگارد/3x-ui/Remnawave/ربکا + مینی‌اپ + پنل وب): آمار ربات‌ساز از جدول `user` خوانده می‌شود و همگانی هم پشتیبانی می‌شود
- میرزا (سورس کامل فروش VPN): خرید خودکار کانفیگ مرزبان/3x-ui، اکانت تست، درگاه‌ها، پنل ادمین کامل — آمار و همگانی پشتیبانی می‌شود

---

## نکات مهم

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

---

## مشکلات رایج و راه‌حل

### ۴۰۳ Forbidden — علل و راه‌حل‌ها

| علت | راه‌حل |
| :--- | :--- |
| `/root` حالت `0700` دارد | `chmod 711 /root` |
| `www-data` نمی‌تواند وارد `data/` شود | `chown -R www-data:www-data data/` |
| AppArmor `InaccessiblePaths=/root` | ساخت drop-in: `/etc/systemd/system/apache2.service.d/botsaz.conf` با `InaccessiblePaths=` و `ProtectHome=false` |
| `.htaccess` غیرفعاله | Apache: `AllowOverride All` روی DocumentRoot |
| `www-data` به DocumentRoot راه ندارد | `chmod o+x` روی هر پوشهٔ زنجیرهٔ `/root/botsaz-faxima/...` |

### وبهوک خطاها

| خطا | علت | راه‌حل |
| :--- | :--- | :--- |
| `Wrong response from the webhook: 404` | `base_url` بدون `https://` یا با مسیر غلط | `https://` کامل بگذار، `php tools/set_webhook.php` بزن |
| همین `404` ولی `base_url` درست | وهاست دیگر هم همین `ServerName` دارد | `a2dissite 000-default-le-ssl && systemctl reload apache2` |
| `Connection refused` | `systemctl reload` در همان لحظه | `systemctl status apache2` + `bash tools/install.sh --check` |
| تلگرام به ماشین دیگر می‌رسد | رکورد **AAAA** اشتباه | `getent ahosts دامنه` + `bash tools/install.sh --check` |
| وبهوک همیشه `403` | secret نادرست یا `TELEGRAM_WEBHOOK_SECRET` ناهماهنگ | دوبارهٔ گام ۶ (روش A/B) |

### سیستمی

| مشکل | راه‌حل |
| :--- | :--- |
| `PCRE JIT` warning در PHP | `pcre.jit=0` را در همهٔ `.ini` فایل‌ها (CLI + Apache + FPM) ست کن |
| `config.php` بعد از `git pull` پاک شد | `config.php` در `.gitignore` هست — اگر پاک شد، `git checkout HEAD~1 -- config.php` |
| `.htaccess` بعد از آپدیت پاک شد | `update.sh` خودکار برمی‌گرداند؛ دستی: `git show HEAD:.htaccess > .htaccess` |
| هر دو Apache و nginx فعاله | فقط یکی باید روی پورت 80/443 باشد |
| nginx `default_server` تداخل | `000-default` را غیرفعال کن یا `default_server` روی `botsaz.conf` تأیید کن |

---

## فلو عادی کار 🔄

```
گیت‌هاب ایمیل ← تغییر ثبت شد
       ↓
ssh root@server
       ↓
bash /root/botsaz-faxima/tools/update.sh
       ↓
├── پیش‌بینی ✓
├── بک‌آپ config ✓
├── git pull --ff-only ✓
├── config intact ✓
├── .htaccess restored ✓
├── /root permissions ✓
├── vhost reloaded ✓
├── systemd sandbox ✓
├── PCRE JIT ✓
├── services restarted ✓
├── health check ✓
├── webhook verified ✓
└── Telegram notification ✓
       ↓
ربات آپدیت شد ✅
```

---

## کرون لینک‌ها

```
# کرون ۵دقیقه‌ای — اسلات‌ها را چک می‌کند
*/5 * * * * php /root/botsaz-faxima/tools/cron_dispatcher.php

# بک‌آپ دیتابیس — هر روز ساعت ۳ و ۱۵
0 3,15 * * * php /root/botsaz-faxima/tools/backup_dispatcher.php

# اپدیت هفتگی — هر شنبه ساعت ۶ صبح
0 6 * * 0 bash /root/botsaz-faxima/tools/update.sh
```

---

## آدرس‌های مهم

- 📖 **گیت‌هاب:** [https://github.com/alisystemit/botsaz-faxima](https://github.com/alisystemit/botsaz-faxima)
- 🐛 **گزارش مشکل:** یک ایسو را در GitHub باز کنید
- 📧 **توسعه‌دهنده:** alisystemit

---

## لایسنس

این پروژه تحت لایسنس‌های متناسب با قالب فاکسیما/میرزا منتشر شده است. برای جزئیات، فایل لایسنس هر قالب را بررسی کنید.
