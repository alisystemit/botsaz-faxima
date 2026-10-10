# 🎨 راهنمای استفاده از UiPremium و PermissionManager

## بخش 1: UiPremium — UI شیشه‌ای و زیبا

### مثال‌های عملی:

```php
require_once 'src/UiPremium.php';

// Header شیشه‌ای
echo UiPremium::header('🤖', 'ساخت ربات جدید', 'ربات را اینجا تنظیم کنید');

// نتیجه:
// 🤖 ساخت ربات جدید
// این یک متن نمونه است
// ━━━━━━━━━━━━━━━━━━━━━━

// بخش (Section)
echo UiPremium::section('📦', 'ربات‌های من', 'محتوای بخش');

// اطلاعات (Info)
echo UiPremium::info('نام ربات', 'MyAwesomeBot', true);
// نتیجه: 🔹 نام ربات: MyAwesomeBot

// لیست Items
$items = ['ربات ۱', 'ربات ۲', 'ربات ۳'];
echo UiPremium::listItems($items, '✓');

// کارت (Card)
echo UiPremium::card('👥', 'تعداد کاربران', '1,234');

// Alert
echo UiPremium::alert('success', 'ربات با موفقیت ساخته شد!');
echo UiPremium::alert('error', 'خطا در اتصال به دیتابیس');
echo UiPremium::alert('warning', 'این عملیات برگردان‌ناپذیر است');

// Step Indicator
echo UiPremium::step(2, 5, 'دریافت توکن');
// نتیجه: 🟦🟦⬜⬜⬜ 2/5 — دریافت توکن

// Progress Bar
echo UiPremium::progress(75);
// نتیجه: █████████░ 75%

// Timeline
$events = ['ایجاد', 'تنظیم', 'فعال‌سازی'];
echo UiPremium::timeline($events);

// Compact Info
$data = ['تعداد' => '5', 'فعال' => '3', 'غیرفعال' => '2'];
echo UiPremium::compact($data);
// نتیجه: تعداد: 5  •  فعال: 3  •  غیرفعال: 2
```

### جای‌های استفاده:

1. **Main Menu** — Headers و Sections برای سازماندهی
2. **Bot Stats** — Cards برای نمایش آمار
3. **Error Messages** — Alert برای خطاهای واضح
4. **Build Process** — Step Indicator برای مراحل
5. **Status Reports** — Progress Bar برای عملیات درحال‌انجام

---

## بخش 2: PermissionManager — مدیریت دسترسی‌ها

### نحوهٔ استفاده:

```php
require_once 'src/PermissionManager.php';

// تنظیم تمام دسترسی‌ها
$results = PermissionManager::fixAll('/path/to/botsaz-faxima');

// نتیجه:
// [
//     'success' => true,
//     'fixed_dirs' => 150,
//     'fixed_files' => 450,
//     'errors' => []
// ]

// بررسی و گزارش دسترسی‌های نادرست
$audit = PermissionManager::audit('/path/to/botsaz-faxima');
// [
//     'total_issues' => 5,
//     'issues' => [
//         'دسترسی نادرست (dir): /path/to/data (700 بجای 755)',
//         ...
//     ]
// ]

// آمار کلی
$stats = PermissionManager::stats('/path/to/botsaz-faxima');
// [
//     'total_dirs' => 200,
//     'total_files' => 600,
//     'correct_perms' => 790,
//     'incorrect_perms' => 10
// ]

// تنظیم دسترسی یک پوشه
PermissionManager::fixDirectory('/path/to/data', 0755);

// تنظیم دسترسی یک فایل
PermissionManager::fixFile('/path/to/config.php', 0644);
```

---

## بخش 3: یکپارچگی در نصب‌کننده

فایل `tools/install.php` اکنون خودکار:

1. ✅ دیتابیس را ایجاد می‌کند
2. ✅ Config را تنظیم می‌کند
3. ✅ **تمام دسترسی‌ها را تنظیم می‌کند** (755)
4. ✅ Permissions را چک می‌کند و خطاهای را گزارش می‌دهد

### نتیجهٔ نصب:

```
mkdir bots
mkdir data
...

🔧 تنظیم دسترسی‌ها...
✅ دسترسی‌ها تنظیم شدند:
  • پوشه‌های تنظیم‌شده: 150
  • فایل‌های تنظیم‌شده: 450

manager DB OK
payment defaults OK
...
```

---

## بخش 4: استفاده در bot.php

مثال‌های عملی برای بهبود پیام‌ها:

### قبل (ساده):
```php
BotApi::send($TOKEN, $chatId, 
    "ساخت ربات\n" .
    "نام: " . $botName . "\n" .
    "قالب: " . $type);
```

### بعد (شیشه‌ای):
```php
require_once 'src/UiPremium.php';

$message = UiPremium::header('🤖', 'ساخت ربات جدید') .
    UiPremium::spacer() .
    UiPremium::info('نام ربات', $botName, true) .
    "\n" .
    UiPremium::info('قالب', $type, true);

BotApi::send($TOKEN, $chatId, $message);
```

---

## بخش 5: استفاده در Diagnostic Panel

### Permissions Check:

```php
require_once 'src/PermissionManager.php';

// در healthcheck.php یا صفحهٔ دیاگنوز:
$audit = PermissionManager::audit($rootPath);

echo "📋 وضعیت دسترسی‌ها:\n";
if ($audit['total_issues'] === 0) {
    echo "✅ تمام دسترسی‌ها صحیح است\n";
} else {
    echo "⚠️ " . $audit['total_issues'] . " دسترسی نادرست:\n";
    foreach ($audit['issues'] as $issue) {
        echo "  • " . $issue . "\n";
    }
    
    // دکمهٔ Fix
    echo "\n[🔧 اصلاح خودکار] → php tools/install.php\n";
}
```

---

## بخش 6: فاصله‌گذاری استاندارد

### قواعد کلی:

```
Header
━━━━━━━━━━━━━━━━━━━━━━

Section 1
محتوا


━━━━━━━━━━━━━━━━━━━━━━
Section 2
محتوا
```

### فاصله‌ها:
- **بین sections:** 2 خط خالی (`\n\n`)
- **بین items:** 1 خط خالی (`\n`)
- **بین groups:** divider + 1 خط
- **داخل code/pre:** بدون تغییر

---

## بخش 7: نمونه‌های کامل

### مثال 1: Bot Creation Success Page

```php
$message = 
    UiPremium::header('✅', 'ربات ساخته شد!', 'تبریک، ربات شما آماده است') .
    UiPremium::spacer() .
    
    UiPremium::section('📊', 'اطلاعات ربات') .
    "\n" .
    UiPremium::info('نام', 'MyBot', true) . "\n" .
    UiPremium::info('قالب', 'Faxima', true) . "\n" .
    UiPremium::info('وبهوک', 'example.com/bot.php', true) .
    
    UiPremium::spacer() .
    
    UiPremium::section('⚡', 'مراحل بعدی') .
    "\n" .
    UiPremium::timeline([
        'ربات به‌صورت آنلاین فعال شد',
        'کاربران می‌توانند /start را بزنند',
        'شروع به استفاده کنید!'
    ]);

BotApi::send($TOKEN, $chatId, $message);
```

### مثال 2: System Status Page

```php
$stats = PermissionManager::stats($rootPath);

$message = 
    UiPremium::header('🏥', 'وضعیت سیستم') .
    UiPremium::spacer() .
    
    UiPremium::card('📁', 'پوشه‌ها', $stats['total_dirs']) .
    UiPremium::card('📄', 'فایل‌ها', $stats['total_files']) .
    UiPremium::card('✅', 'دسترسی صحیح', $stats['correct_perms']) .
    UiPremium::card('❌', 'دسترسی نادرست', $stats['incorrect_perms']) .
    
    UiPremium::spacer() .
    
    ($stats['incorrect_perms'] > 0 
        ? UiPremium::alert('warning', 'برخی دسترسی‌ها نادرست است. اجرا کنید: php tools/install.php')
        : UiPremium::alert('success', 'تمام دسترسی‌ها صحیح است'));

BotApi::send($TOKEN, $chatId, $message);
```

---

## بخش 8: خلاصهٔ بهبودی‌ها

| بخش | قبل | بعد |
|------|------|------|
| **UI** | ساده | ✨ شیشه‌ای |
| **فاصله‌ها** | نامنظم | 📏 رعایت‌شده |
| **Permissions** | دستی | 🔧 خودکار |
| **Readability** | ⬜ کم | ✅ زیاد |
| **Professional** | ⬜ متوسط | ✅ حرفه‌ای |

---

**تاریخ آپدیت:** ۱۰ مهرماه ۱۴۰۵  
**نسخه:** ۱.۲.۰  
**وضعیت:** ✅ Ready for Production 🚀
