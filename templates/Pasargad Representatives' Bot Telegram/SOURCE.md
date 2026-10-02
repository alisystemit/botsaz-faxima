# منبع این قالب

این پوشه یک کلون آمادهٔ یک ربات مستقل است که به‌عنوان **قالب** وارد این ریپو شده
تا ربات‌ساز بتواند از داخل تلگرام آن را نصب کند.

| مورد | مقدار |
|---|---|
| مخزن مبدأ | https://github.com/alisystemit/Pasargad-Representatives-Bot-Telegram |
| کامیت واردشده | `dea018e` (fix: provision() … +22 تست رگرسیون) |
| نوع دیتابیس | **SQLite** — فایل `data/bot.sqlite` داخل پوشهٔ خود ربات |
| فایل ورودی وبهوک | `bot.php` (نه `index.php`) |
| رمز وبهوک | `webhook_secret` تصادفی که نصب‌کننده می‌سازد |
| کرون | `cron/worker.php` (با کرون‌دیسپچر مرکزی اجرا می‌شود) |
| نصب دیتابیس | مایگریشن درون‌فرایندی (`Manager::installPasargadSchema`) |

## تفاوت‌های مهم با دو قالب قبلی

1. **SQLite، نه MySQL** ⇒ برای این قالب دیتابیس جدا ساخته نمی‌شود و پشتیبان‌گیری
   MySQL روی آن بی‌معناست (`DbBackup` آن را رد می‌کند).
2. **autoloader دستی، نه composer** ⇒ این قالب `vendor/` برای اجرا لازم ندارد
   (`vendor/` فقط phpstan دارد و `.gitignore` خودش هم آن را commit نمی‌کند).
   معیار در دسترس بودن قالب، وجود `bootstrap.php` است.
3. **بدون `table.php`** ⇒ جدول‌ها با مایگریشن ساخته می‌شوند، نه با فراخوانی HTTP.
4. **`webhook_secret` اجباری است** ⇒ اگر خالی بماند، قالب همهٔ درخواست‌ها را ۴۰۳ می‌کند.
   ربات‌ساز آن را تصادفی می‌سازد (`Manager::writePasargadConfig`).

## فایل‌های زمان‌اجرا که هرگز نباید کپی شوند

`data/bot.sqlite` (دیتابیس توسعه)، `data/.migrate.lock`، `data/worker.lock`،
`data/logs/` و `*.log` — همه در رجیستری (`Manager::templates()`) در `exclude` هستند.

## به‌روزرسانی قالب

```bash
git clone https://github.com/alisystemit/Pasargad-Representatives-Bot-Telegram \
  "templates/Pasargad Representatives' Bot Telegram.new"
```

سپس `vendor/`، `composer.*`، `phpstan.neon` و `data/` را از کلون تازه جا نگذارید
(مگر پوشهٔ خالی `data/logs`).