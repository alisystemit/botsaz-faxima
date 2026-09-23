<?php
// ===== تنظیمات اصلی ربات‌ساز =====
// این فایل را ویرایش کن و به نام config.php ذخیره کن (یا از tools/install.php استفاده کن)

return [
    // توکن ربات اصلی (ربات‌ساز) از @BotFather
    'main_token' => 'PUT_MAIN_BOT_TOKEN_HERE',

    // آیدی عددی سوپرادمین‌ها (دسترسی کامل)
    'super_admins' => [123456789],

    // آدرس پایه پروژه روی هاست، بدون اسلش آخر. مثال:
    // 'base_url' => 'https://yourdomain.com/botsaz-faxima',
    'base_url' => 'http://botsaz-faxima.test',

    // مشخصات اتصال MySQL برای ساخت دیتابیس ربات‌های فرزند
    // این یوزر باید دسترسی CREATE DATABASE داشته باشد (در لوکال: root)
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_user' => 'root',
    'db_pass' => '',
    // پیشوند نام دیتابیس‌ها: مثلا botsaz_<slug>_<rand>
    'db_prefix' => 'botsaz_',

    // اگر ساخت دیتابیس جدا ناموفق بود (هاست اشتراکی)، از این دیتابیس مشترک با پیشوند جدول استفاده شود
    'fallback_db_name' => '',
    // 'fallback_db_name' => 'botsaz_shared',

    // مسیر دیتابیس مدیریتی (SQLite) — نیازی به تغییر نیست
    'manager_db' => __DIR__ . '/data/botsaz.sqlite',

    // توکن‌ها در دیتابیس مدیریتی به صورت متن ساده ذخیره می‌شوند.
    // اگر می‌خواهی رمزنگاری شود یک کلید ۳۲ بایتی اینجا بگذار (اختیاری، فعلا استفاده نمی‌شود)
    'secret_key' => 'change-this-to-a-random-string',
];
