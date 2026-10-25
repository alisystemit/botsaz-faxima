# راهنمای امن‌سازی استقرار (Nginx / Apache)

این سند مکمل `docs/AUDIT_FA.md` است و کارهایی را می‌گوید که باید **روی سرور**
انجام شود؛ کد به‌تنهایی نمی‌تواند Nginx را قفل کند.

## ۱) فایل‌ها و پوشه‌هایی که هرگز نباید از وب قابل دانلود باشند

- `config.php` و هر بکاپ آن (`config.php.bak`, `config.php.*`)
- `data/` (دیتابیس مدیریتی، لاگ‌ها، قفل‌ها، فایل‌های موقت)
- `src/`, `tools/`, `templates/`, `docs/`
- `bots/backups/`, `bots/states/`
- هر فایل `*.sqlite`, `*.sql`, `*.log`, `*.lock`, `*.bak`, `.env*`, `.git*`

فایل `.htaccess` ریشه و `bots/.htaccess` این موارد را برای Apache پوشش می‌دهند.
برای Nginx این بلوک را داخل `server { ... }` اضافه کنید (قبل از `location ~ \.php$`):

```nginx
# فایل‌های حساس
location ~* /(?:config\.php(?:\..*)?|\.env.*|\.git.*|composer\.(?:json|lock))$ {
    deny all;
    return 404;
}

# پوشه‌های داخلی و بکاپ‌ها
location ~* ^/(?:src|tools|templates|data|docs|bots/(?:backups|states))/ {
    deny all;
    return 404;
}

# پسوندهای حساس (شامل بکاپ فشرده و کلید/گواهی)
location ~* \.(?:sqlite3?|db|sql(?:\.gz)?|log|lock|bak|ini|dist|pem|key|crt|p12|pfx|zip|tar|gz|tgz)$ {
    deny all;
    return 404;
}

# فقط entrypointهای واقعی ربات‌های فرزند
location ~ ^/bots/[^/]+/(?:index|table|cron/[^/]+)\.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

> اگر از مسیر دیگری برای PHP-FPM استفاده می‌کنید، `fastcgi_pass` را اصلاح کنید.
> مهم این است که `location ~ \.php$` عمومی بعد از این بلوک‌ها باشد.

## ۲) دسترسی فایل‌ها

```bash
chown -R www-data:www-data /var/www/botsaz
find /var/www/botsaz -type d -exec chmod 755 {} \;
find /var/www/botsaz -type f -exec chmod 644 {} \;
chmod 600 /var/www/botsaz/config.php
chmod -R 700 /var/www/botsaz/data /var/www/botsaz/bots/backups
```

`config.php` باید فقط برای کاربر وب‌سرور قابل خواندن باشد.

## ۳) اعتبارسنجی TLS

پیش‌فرض همهٔ کلاینت‌های عمومی (زرین‌پال، آقای پرداخت، NOWPayments، تلگرام،
پایش آپ‌تایم) گواهی TLS را بررسی می‌کنند. اگر پنل داخلی شما گواهی
self-signed دارد، **به‌جای خاموش کردن سراسری** فقط برای همان قالب در محیط
وب‌سرور این متغیر را تنظیم کنید:

```nginx
# فقط اگر پنل میرزا گواهی معتبر ندارد
fastcgi_param MIRZA_SSL_INSECURE "1";

# فقط برای درخواست‌های داخلی/پنل فاکسیما
fastcgi_param FAOXIMA_SSL_INSECURE "1";

# فقط برای سایت‌های داخلی پایش آپ‌تایم
fastcgi_param UPTIME_TLS_INSECURE "1";
```

این متغیرها را تا حد امکان فعال نکنید؛ با گواهی Let's Encrypt نیازی نیست.

## ۴) نکات عملیاتی

- `data/` را در بکاپ روزانه بگذارید؛ شامل `botsaz.sqlite` و `logs/` است.
- cron تلگرام/دستیار را با کاربر `www-data` و از مسیر امن اجرا کنید.
- پس از تغییر `config.php`، `php -l config.php` بگیرید.
- لاگ‌های `data/logs/` را دوره‌ای پاک کنید؛ `audit.log` حاوی IP و شناسهٔ
  ادمین است و نباید عمومی شود.
- اگر پشت CDN/پروکسی هستید، `REMOTE_ADDR` را با هدر معتبر پروکسی تنظیم کنید؛
  در غیر این صورت rate-limit ورود پنل بر اساس IP واقعی کار نمی‌کند.
