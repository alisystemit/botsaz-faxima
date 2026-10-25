# 🕐 PGClockBot — قالب «فهرست فقط» (بدون ساخت خودکار)

## چرا در منو هست ولی دکمهٔ ساخت ندارد؟

PGClockBot با PHP نوشته نشده است:

| مورد | PGClockBot |
|---|---|
| زبان/ران‌تایم | Python 3 + aiogram 3 + FastAPI/uvicorn |
| دیتابیس | PostgreSQL (`asyncpg` + `alembic`) |
| اجرا | سرویس دائمی + پنل وب روی پورت `9000` |
| نصب | `venv` + `pip install -r requirements.txt` + `alembic upgrade head` |
| وابستگی بیرونی | پنل PasarGuard (`PG_BASE_URL`, `PG_USERNAME`, `PG_PASSWORD`) |

ربات‌ساز (botsaz-faxima) مدل نصب زیر را دارد:

1. کپی پوشهٔ قالب از `templates/<dir>` به `bots/<slug>/`
2. ساخت دیتابیس MySQL یا SQLite (`Manager::createDatabase`)
3. پچ‌کردن `config.php` با توکن/ادمین/دیتابیس (`Manager::patch*Config`)
4. ست‌کردن وبهوک تلگرام روی `bots/<slug>/index.php` (`Manager::webhookUrl`)
5. اجرای `table.php` برای ساخت جدول‌ها (یا migrate برای SQLite)

هیچ‌کدام از این پنج مرحله برای یک اپ پایتون معنا ندارد. اگر PGClockBot را
`buildable` می‌کردیم، نتیجه این می‌شد: دیتابیس MySQL خالی + `config.php` با
placeholderهای قالبی دیگر + وبهوک روی فایلی که وجود ندارد؛ یعنی پیام
«✅ ربات ساخته شد» برای رباتی که هرگز بالا نمی‌آید.

برای همین در رجیستری (`src/Manager.php`) این‌طور ثبت شده است:

```php
'pgclock' => [
    'label'     => 'پی‌جی‌کلاک (پاسارگارد)',
    'runtime'   => 'python',
    'buildable' => false,      // ← هیچ مسیر ساخت/قیمت/آپدیت سورس وارد نمی‌شود
    'db'        => 'postgresql',
    'repo'      => 'https://github.com/Mrclocks/PGClockBot',
    'requires'  => [ ... ],
    'docs'      => 'docs/PGCLOCKBOT.md',
],
```

`Manager::isBuildable()` تنها منبع تصمیم است و در این جاها اعمال می‌شود:

- `Manager::validTypes()` / `availableTypes()` → در منوی ساخت و قیمت‌گذاری نمی‌آید
- `Manager::catalogTypes()` → فقط برای نمایش راهنما در `Nav::typeMenu()`
- `checkBuildPrerequisites()` → پیش‌نیازهایش ساخت بقیه را نمی‌بندد
- `buildBot()` → گارد دوم؛ حتی با دکمهٔ کهنه هم چیزی ساخته نمی‌شود
- `SelfUpdate::templatesStatus()/fetchSource()/updateTemplates()` → کلون/کپی نمی‌شود
- `tools/install.php`, `tools/dryrun.php`, `tools/healthcheck.php` → نادیده گرفته می‌شود

## نصب درست روی VPS (لینوکس، root)

پیش‌نیازها: Ubuntu 22.04+، ۲ گیگ رم، ۲ هسته، دامنه، توکن ربات، دسترسی پنل PasarGuard.

```bash
# ۱) نصب (ویزارد وب روی پورت 9000)
bash <(curl -fsSL https://raw.githubusercontent.com/Mrclocks/PGClockBot/main/get.sh)

# ۲) باز کردن ویزارد
#    http://SERVER_IP:9000/   (یا دامنهٔ متصل)
```

نکته‌های عملیاتی:

- پورت `9000` را فقط به nginx/reverse-proxy بدهید؛ مستقیم روی اینترنت باز نکنید.
- `WEBHOOK_URL` را روی HTTPS کامل ست کنید (مثلاً `https://pg.example.com/telegram/webhook`)؛
  خالی‌بودنش یعنی حالت polling و نیاز به پروسهٔ دائمی.
- `.env` را از وب‌روت جدا نگه دارید (سرویس در `/opt` یا `/srv`، نه داخل `public_html`).
- بکاپ: `pgclock backup --note "pre-update"` (PostgreSQL + `.env`).
- به‌روزرسانی: `bash pgclock.sh update` (`.env` حفظ می‌شود).

## اتصال به ربات‌ساز (مرحلهٔ بعد، اختیاری)

وقتی خواستی این محصول از داخل ربات‌ساز هم «فروخته/مدیریت» شود، دو گزینه دارد:

1. **سرویس خارجی:** یک نوع رکورد `external` در ربات‌ساز با دامنه/پورت؛ کاربر
   فقط لینک پنل و راهنما می‌گیرد و وبهوک تلگرام به همان سرویس ست می‌شود.
   (بدون کپی سورس، بدون دیتابیس جدید.)
2. **ران‌تایم پایتون:** افزودن نوع `python` به `buildBot` با ساخت venv، نوشتن
   `.env`، ساخت دیتابیس PostgreSQL، سرویس systemd روی پورت یکتا و پروکسی nginx.
   نیازمند دسترسی root و تغییر بزرگ در ربات‌ساز است.

هر دو گزینه بعد از تصمیم روی همین قالب سوار می‌شوند؛ تا آن موقع این ورودی
فقط «فهرست/راهنما» می‌ماند.
