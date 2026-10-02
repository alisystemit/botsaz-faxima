# منبع این قالب

این پوشه یک کلون آمادهٔ یک ربات‌ساز/ربات مستقل است که به‌عنوان **قالب** وارد این ریپو شده
تا ربات‌ساز بتواند از داخل تلگرام آن را نصب کند (نه اینکه خودش ربات باشد).

| مورد | مقدار |
|---|---|
| مخزن مبدأ | https://github.com/alisystemit/uptime-bot-telegram |
| کامیت واردشده | `d5f9a4d` (`bug`) |
| نوع دیتابیس | MySQL (هر ربات فرزند دیتابیس جدا) |
| فایل ورودی وبهوک | `index.php` |
| رمز وبهوک | `sha256(token . '_uptime_webhook_secret')` |
| رمز `table.php` | `sha256(token . '_uptime_table_secret')` |
| کرون | `cron/checker.php` (با کرون‌دیسپچر مرکزی اجرا می‌شود) |
| نصب دیتابیس | از طریق HTTP به `table.php` |

## چرا `install.sh` کپی نمی‌شود

`install.sh` این پروژه برای **سرور خام لینوکس** نوشته شده (systemd، useradd، vhost،
`chown` و…). روی هاست اشتراکی و از داخل وبهوک تلگرام نه قابل اجراست و نه لازم؛
ساخت ربات از داخل ربات‌ساز همهٔ همان کارها را از پیش انجام می‌دهد.
به همین دلیل در رجیستری (`Manager::templates()`) در `exclude` قرار گرفته و بعد از کپی
هم پاک می‌شود.

## به‌روزرسانی قالب

```bash
# پوشه را از نو کلون کنید (چون .git داخل ریپو نیست)
git clone https://github.com/alisystemit/uptime-bot-telegram "templates/uptime-bot-telegram.new"
```

سپس `vendor/` را از کلون تازه نگه دارید و `config.php`/`logs/`/`tests/` را جا نگذارید.