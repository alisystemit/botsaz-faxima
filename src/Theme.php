<?php
// ===== Theme System — پشتیبانی از تم‌های مختلف =====
// استفاده: Theme::set('dark'); یا $theme = Theme::get('light');

class Theme
{
    private static string $currentTheme = 'light';
    private static array $themes = [
        'light' => [
            'header' => '✨',
            'success' => '✅',
            'error' => '❌',
            'warning' => '⚠️',
            'info' => 'ℹ️',
            'divider' => '━━━━━━━━━━━━━━━━━━━━━━',
            'sep_thick' => '═══════════════════════',
            'bullet' => '•',
            'check' => '✓',
            'cross' => '✗',
            'arrow' => '→',
            'star' => '⭐',
        ],
        'dark' => [
            'header' => '🌙',
            'success' => '✔️',
            'error' => '⛔',
            'warning' => '⚠️',
            'info' => 'ℹ️',
            'divider' => '════════════════════════',
            'sep_thick' => '████████████████████████',
            'bullet' => '▪',
            'check' => '✔',
            'cross' => '✘',
            'arrow' => '➜',
            'star' => '★',
        ],
        'minimal' => [
            'header' => '→',
            'success' => '✓',
            'error' => '✗',
            'warning' => '!',
            'info' => 'i',
            'divider' => '---',
            'sep_thick' => '===',
            'bullet' => '·',
            'check' => '✓',
            'cross' => '✗',
            'arrow' => '>',
            'star' => '*',
        ],
        'colorful' => [
            'header' => '🎨',
            'success' => '🎉',
            'error' => '💥',
            'warning' => '🔥',
            'info' => '💡',
            'divider' => '🌈🌈🌈🌈🌈🌈🌈🌈🌈🌈',
            'sep_thick' => '✨✨✨✨✨✨✨✨✨✨',
            'bullet' => '🔹',
            'check' => '👍',
            'cross' => '👎',
            'arrow' => '👉',
            'star' => '⭐',
        ],
    ];
    
    /**
     * ست‌کردن تم فعلی
     */
    public static function set(string $theme): void
    {
        if (isset(self::$themes[$theme])) {
            self::$currentTheme = $theme;
        }
    }
    
    /**
     * دریافت تم فعلی
     */
    public static function getCurrent(): string
    {
        return self::$currentTheme;
    }
    
    /**
     * دریافت یک icon
     */
    public static function icon(string $key, string $theme = ''): string
    {
        $t = $theme !== '' ? $theme : self::$currentTheme;
        return self::$themes[$t][$key] ?? self::$themes['light'][$key] ?? $key;
    }
    
    /**
     * دریافت تمام icons یک تم
     */
    public static function get(string $theme = ''): array
    {
        $t = $theme !== '' ? $theme : self::$currentTheme;
        return self::$themes[$t] ?? self::$themes['light'];
    }
    
    /**
     * دریافت تمام تم‌های موجود
     */
    public static function list(): array
    {
        return array_keys(self::$themes);
    }
}
