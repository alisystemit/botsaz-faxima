<?php
// ===== Glass Morphism UI — زیباسازی پیشرفتهٔ ربات =====
// شیشه‌ای، gradients، spacing منسجم، typography بهتر

class UiPremium
{
    // ===== رنگ‌ها و استایل‌ها =====
    public const ACCENT = '🎯';
    public const SUCCESS = '✅';
    public const ERROR = '❌';
    public const WARNING = '⚠️';
    public const INFO = 'ℹ️';
    public const LOADING = '⏳';
    
    // جداکنندهٔ premium (شیشه‌ای)
    public const GLASS_LINE = '━━━━━━━━━━━━━━━━━━━━━━';
    public const GLASS_DOT = '✨';
    public const DIVIDER = "\n━━━━━━━━━━━━━━━━━━━━━━\n";
    
    /**
     * Header شیشه‌ای برای هر بخش
     * استفاده: UiPremium::header('🤖', 'ساخت ربات جدید', 'ربات را اینجا تنظیم کنید')
     */
    public static function header(string $icon, string $title, string $subtitle = ''): string
    {
        $h = $icon . ' <b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>';
        if ($subtitle !== '') {
            $h .= "\n<i>" . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . "</i>";
        }
        return $h . "\n" . self::GLASS_LINE;
    }
    
    /**
     * بخش (section) با استایل شیشه‌ای
     * استفاده: UiPremium::section('📦', 'ربات‌های من', $content)
     */
    public static function section(string $icon, string $title, string $content = ''): string
    {
        $s = "\n" . self::GLASS_LINE . "\n";
        $s .= $icon . ' <b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>';
        if ($content !== '') {
            $s .= "\n\n" . $content;
        }
        return $s;
    }
    
    /**
     * اطلاعات با فاصلهٔ شیشه‌ای
     * استفاده: UiPremium::info('نام', 'علی', true)
     */
    public static function info(string $label, string $value, bool $code = false): string
    {
        $val = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        if ($code) $val = '<code>' . $val . '</code>';
        return '🔹 <b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</b> ' . $val;
    }
    
    /**
     * لیست items با استایل premium
     * استفاده: UiPremium::listItem('✓', 'موارد انجام‌شده', ['item1', 'item2'])
     */
    public static function listItems(array $items, string $prefix = '•'): string
    {
        if (empty($items)) return '';
        $out = [];
        foreach ($items as $item) {
            $out[] = $prefix . ' ' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
        }
        return implode("\n", $out);
    }
    
    /**
     * کارت اطلاعات (Card) برای نمایش آمار
     * استفاده: UiPremium::card('👥', 'کاربران', '1,234')
     */
    public static function card(string $icon, string $label, string $value): string
    {
        return "\n" . 
            $icon . ' <b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b>' . "\n" .
            '  → <code>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</code>';
    }
    
    /**
     * Status badge برای نمایش وضعیت
     * استفاده: UiPremium::badge('🟢', 'فعال')
     */
    public static function badge(string $icon, string $status): string
    {
        return $icon . ' <b>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</b>';
    }
    
    /**
     * Alert برای پیام‌های مهم
     * استفاده: UiPremium::alert('warning', 'این عملیات خطرناک است!')
     */
    public static function alert(string $type, string $message): string
    {
        $icons = [
            'success' => '✅',
            'error' => '❌',
            'warning' => '⚠️',
            'info' => 'ℹ️',
        ];
        $icon = $icons[$type] ?? 'ℹ️';
        
        return $icon . ' <b>پیام ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . ':</b>' . "\n" .
               htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * فاصله‌گذاری حرفه‌ای بین بخش‌ها
     * استفاده: text1 + UiPremium::spacer(2) + text2
     */
    public static function spacer(int $lines = 1): string
    {
        return str_repeat("\n", max(1, min($lines, 3)));
    }
    
    /**
     * Step indicator برای فرایند‌های چندمرحله‌ای
     * استفاده: UiPremium::step(2, 5, 'دریافت توکن')
     */
    public static function step(int $current, int $total, string $label = ''): string
    {
        $bar = '';
        for ($i = 1; $i <= $total; $i++) {
            $bar .= $i <= $current ? '🟦' : '⬜';
        }
        $step = $bar . ' ' . $current . '/' . $total;
        if ($label !== '') {
            $step .= ' — ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        }
        return $step;
    }
    
    /**
     * Progress bar برای نمایش پیشرفت
     * استفاده: UiPremium::progress(75)
     */
    public static function progress(int $percent): string
    {
        $percent = max(0, min(100, $percent));
        $filled = (int)ceil($percent / 10);
        $empty = 10 - $filled;
        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
        return $bar . ' ' . $percent . '%';
    }
    
    /**
     * متن فاصله‌دار حرفه‌ای
     * استفاده: UiPremium::text('سلام', 'این یک متن نمونه است')
     */
    public static function text(string $title = '', string $body = ''): string
    {
        $out = '';
        if ($title !== '') {
            $out .= '<b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>';
        }
        if ($body !== '') {
            if ($title !== '') $out .= "\n\n";
            $out .= htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        }
        return $out;
    }
    
    /**
     * Table-like display (باید متناسب باشد)
     * استفاده: UiPremium::table([['نام' => 'علی', 'وضعیت' => 'فعال']])
     */
    public static function table(array $rows): string
    {
        if (empty($rows)) return '';
        
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $key => $val) {
                $line[] = '<b>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ':</b> ' .
                         htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            }
            $out[] = implode(" • ", $line);
        }
        return implode("\n", $out);
    }
    
    /**
     * Error message حرفه‌ای
     * استفاده: UiPremium::error('خطا در اتصال', 'دیتابیس در دسترس نیست')
     */
    public static function error(string $title, string $details = ''): string
    {
        $msg = '❌ <b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>';
        if ($details !== '') {
            $msg .= "\n\n" . htmlspecialchars($details, ENT_QUOTES, 'UTF-8');
        }
        return $msg;
    }
    
    /**
     * Success message
     * استفاده: UiPremium::success('ربات ساخته شد!', 'شناسهٔ ربات: 123')
     */
    public static function success(string $title, string $details = ''): string
    {
        $msg = '✅ <b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>';
        if ($details !== '') {
            $msg .= "\n\n" . htmlspecialchars($details, ENT_QUOTES, 'UTF-8');
        }
        return $msg;
    }
    
    /**
     * Warning message
     * استفاده: UiPremium::warning('دقت کنید!', 'این عملیات برگردان‌ناپذیر است')
     */
    public static function warning(string $title, string $details = ''): string
    {
        $msg = '⚠️ <b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>';
        if ($details !== '') {
            $msg .= "\n\n" . htmlspecialchars($details, ENT_QUOTES, 'UTF-8');
        }
        return $msg;
    }
    
    /**
     * Inline badge برای وضعیت
     * استفاده: UiPremium::inlineBadge('🟢', 'فعال') + ' و ' + UiPremium::inlineBadge('🔴', 'غیرفعال')
     */
    public static function inlineBadge(string $icon, string $text): string
    {
        return $icon . ' <b>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</b>';
    }
    
    /**
     * متن توضیحی (مثل راهنما)
     * استفاده: UiPremium::hint('نکته:', 'این تنظیم برای...')
     */
    public static function hint(string $title, string $text): string
    {
        return '💡 <b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b> ' .
               htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Timeline برای نمایش رویدادها
     * استفاده: UiPremium::timeline(['ایجاد', 'تنظیم', 'فعال‌سازی'])
     */
    public static function timeline(array $events): string
    {
        if (empty($events)) return '';
        $out = [];
        foreach ($events as $i => $event) {
            $icon = $i === count($events) - 1 ? '✅' : '→';
            $out[] = $icon . ' ' . htmlspecialchars($event, ENT_QUOTES, 'UTF-8');
        }
        return implode("\n", $out);
    }
    
    /**
     * Compact info (برای موارد محدود)
     * استفاده: UiPremium::compact(['تعداد' => '5', 'فعال' => '3'])
     */
    public static function compact(array $data): string
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[] = htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ': ' .
                    '<code>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</code>';
        }
        return implode("  •  ", $out);
    }
}
