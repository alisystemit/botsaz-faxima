<?php
// ===== i18n - سیستم چند‌زبانی =====
// استفاده: i18n::t('welcome'); یا i18n::t('welcome', 'en');

class i18n
{
    private static string $defaultLang = 'fa';
    private static string $currentLang = 'fa';
    
    private static array $translations = [
        'fa' => [
            // Menu items
            'build_bot' => '🤖 ساخت ربات جدید',
            'my_bots' => '📦 ربات‌های من',
            'stats' => '📊 آمار',
            'cron' => '⏰ کرون',
            'allowed_users' => '👥 کاربران مجاز',
            'broadcast' => '📣 همگانی',
            'payments' => '💳 پرداخت‌ها',
            'backup' => '💾 بکاپ دیتابیس',
            'settings' => '⚙️ تنظیمات',
            'texts' => '📝 متن‌ها',
            'help' => 'ℹ️ راهنما',
            'diagnostics' => '🔍 دیاگنوز',
            'all_bots' => '📋 همه ربات‌ها',
            'pending_requests' => '📋 درخواست‌های جدید',
            'buy_limit' => '💳 افزایش لیمیت',
            'source_update' => '🔄 دریافت سورس بروز',
            
            // Messages
            'welcome' => 'خوش‌آمدید!',
            'build_bot_welcome' => 'شروع فرآیند ساخت ربات جدید',
            'bot_created_success' => '✅ ربات با موفقیت ساخته شد!',
            'error_occurred' => '❌ خطایی رخ داد',
            'try_again' => 'دوباره تلاش کنید',
            'cancel' => '❌ انصراف',
            'back' => '↩️ برگشت',
            'home' => '🏠 منو',
            
            // Settings
            'enable' => 'فعال',
            'disable' => 'غیرفعال',
            'active' => 'فعال',
            'inactive' => 'غیرفعال',
            'enabled' => 'فعال',
            'disabled' => 'غیرفعال',
            
            // Errors
            'not_authorized' => 'شما مجاز نیستید',
            'invalid_token' => 'توکن نامعتبر است',
            'database_error' => 'خطای دیتابیس',
            'network_error' => 'خطای شبکه',
            'timeout' => 'حد زمانی تجاوز شد',
            
            // Time
            'just_now' => 'همین الان',
            'minute_ago' => 'یک دقیقه پیش',
            'hour_ago' => 'یک ساعت پیش',
            'day_ago' => 'یک روز پیش',
            'week_ago' => 'یک هفته پیش',
            'month_ago' => 'یک ماه پیش',
            
            // Numbers
            'one' => 'یکی',
            'many' => 'بسیاری',
        ],
        'en' => [
            // Menu items
            'build_bot' => '🤖 Create New Bot',
            'my_bots' => '📦 My Bots',
            'stats' => '📊 Statistics',
            'cron' => '⏰ Cron',
            'allowed_users' => '👥 Allowed Users',
            'broadcast' => '📣 Broadcast',
            'payments' => '💳 Payments',
            'backup' => '💾 Database Backup',
            'settings' => '⚙️ Settings',
            'texts' => '📝 Texts',
            'help' => 'ℹ️ Help',
            'diagnostics' => '🔍 Diagnostics',
            'all_bots' => '📋 All Bots',
            'pending_requests' => '📋 Pending Requests',
            'buy_limit' => '💳 Buy Slots',
            'source_update' => '🔄 Update Source',
            
            // Messages
            'welcome' => 'Welcome!',
            'build_bot_welcome' => 'Start creating a new bot',
            'bot_created_success' => '✅ Bot created successfully!',
            'error_occurred' => '❌ An error occurred',
            'try_again' => 'Try again',
            'cancel' => '❌ Cancel',
            'back' => '↩️ Back',
            'home' => '🏠 Home',
            
            // Settings
            'enable' => 'Enable',
            'disable' => 'Disable',
            'active' => 'Active',
            'inactive' => 'Inactive',
            'enabled' => 'Enabled',
            'disabled' => 'Disabled',
            
            // Errors
            'not_authorized' => 'You are not authorized',
            'invalid_token' => 'Invalid token',
            'database_error' => 'Database error',
            'network_error' => 'Network error',
            'timeout' => 'Request timeout',
            
            // Time
            'just_now' => 'Just now',
            'minute_ago' => 'A minute ago',
            'hour_ago' => 'An hour ago',
            'day_ago' => 'A day ago',
            'week_ago' => 'A week ago',
            'month_ago' => 'A month ago',
            
            // Numbers
            'one' => 'One',
            'many' => 'Many',
        ],
        'ar' => [
            // Menu items
            'build_bot' => '🤖 إنشاء بوت جديد',
            'my_bots' => '📦 بوتاتي',
            'stats' => '📊 الإحصائيات',
            'cron' => '⏰ المهام المجدولة',
            'allowed_users' => '👥 المستخدمون المسموحون',
            'broadcast' => '📣 البث',
            'payments' => '💳 الدفعات',
            'backup' => '💾 نسخة احتياطية من قاعدة البيانات',
            'settings' => '⚙️ الإعدادات',
            'texts' => '📝 النصوص',
            'help' => 'ℹ️ المساعدة',
            'diagnostics' => '🔍 التشخيص',
            
            // Messages
            'welcome' => 'مرحبا!',
            'bot_created_success' => '✅ تم إنشاء البوت بنجاح!',
            'error_occurred' => '❌ حدث خطأ',
            'try_again' => 'حاول مرة أخرى',
        ],
    ];
    
    /**
     * ست‌کردن زبان جاری
     */
    public static function setLang(string $lang): void
    {
        if (isset(self::$translations[$lang])) {
            self::$currentLang = $lang;
        }
    }
    
    /**
     * دریافت زبان جاری
     */
    public static function getLang(): string
    {
        return self::$currentLang;
    }
    
    /**
     * ترجمهٔ یک کلید
     */
    public static function t(string $key, string $lang = '', array $params = []): string
    {
        $l = $lang !== '' ? $lang : self::$currentLang;
        
        $translation = self::$translations[$l][$key] ?? 
                      self::$translations[self::$defaultLang][$key] ?? 
                      $key;
        
        // جایگزینی پارامترها
        foreach ($params as $k => $v) {
            $translation = str_replace(":{$k}", $v, $translation);
            $translation = str_replace("{{{$k}}}", $v, $translation);
        }
        
        return $translation;
    }
    
    /**
     * دریافت تمام ترجمه‌های یک زبان
     */
    public static function getAll(string $lang = ''): array
    {
        $l = $lang !== '' ? $lang : self::$currentLang;
        return self::$translations[$l] ?? self::$translations[self::$defaultLang];
    }
    
    /**
     * اضافهٔ ترجمه‌های جدید
     */
    public static function add(string $lang, array $translations): void
    {
        if (!isset(self::$translations[$lang])) {
            self::$translations[$lang] = [];
        }
        self::$translations[$lang] = array_merge(
            self::$translations[$lang],
            $translations
        );
    }
    
    /**
     * دریافت تمام زبان‌های موجود
     */
    public static function getAvailableLangs(): array
    {
        return array_keys(self::$translations);
    }
    
    /**
     * ترجمهٔ یک آرایه (برای پیام‌های پیچیده)
     */
    public static function translateArray(array $arr, string $lang = ''): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::translateArray($value, $lang);
            } elseif (is_string($value) && str_starts_with($value, 'i18n:')) {
                $translationKey = substr($value, 5);
                $result[$key] = self::t($translationKey, $lang);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    
    /**
     * تشخیص زبان کاربر از Telegram User Object
     */
    public static function detectFromUser(array $user): string
    {
        $langCode = $user['language_code'] ?? '';
        
        $langMap = [
            'fa' => 'fa',
            'en' => 'en',
            'ar' => 'ar',
            'es' => 'en', // fallback
            'fr' => 'en', // fallback
            'de' => 'en', // fallback
        ];
        
        return $langMap[$langCode] ?? self::$defaultLang;
    }
}
