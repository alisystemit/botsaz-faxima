<?php
// ===== Rate Limiter — محدودیت تعداد درخواست‌ها =====
// استفاده: RateLimiter::isAllowed($userId, 10); // max 10 per minute

class RateLimiter
{
    private static string $cacheDir = __DIR__ . '/../data/rate_limit/';
    private const DEFAULT_LIMIT = 10; // per minute
    private const CLEANUP_INTERVAL = 300; // 5 minutes
    
    public static function init(): void
    {
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    /**
     * بررسی اجازهٔ درخواست
     * @return true اگر درخواست مجاز باشد
     */
    public static function isAllowed(
        int $userId,
        int $maxPerMinute = self::DEFAULT_LIMIT,
        string $action = 'general'
    ): bool {
        self::init();
        
        $key = "user_{$userId}_{$action}";
        $file = self::$cacheDir . md5($key) . '.json';
        $now = time();
        
        $data = [];
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $data = @json_decode($content, true) ?? [];
            }
        }
        
        // پاک‌کردن entries قدیمی (بیش‌تر از 60 ثانیه)
        $data['requests'] = array_filter(
            $data['requests'] ?? [],
            fn($time) => $time > $now - 60
        );
        
        // بررسی تعداد درخواست‌ها
        $count = count($data['requests'] ?? []);
        if ($count >= $maxPerMinute) {
            return false;
        }
        
        // اضافهٔ درخواست جدید
        $data['requests'][] = $now;
        $data['last_request'] = $now;
        $data['total_requests'] = ($data['total_requests'] ?? 0) + 1;
        
        @file_put_contents($file, json_encode($data));
        
        // Cleanup هر 5 دقیقه
        if (rand(1, 100) === 1) {
            self::cleanup();
        }
        
        return true;
    }
    
    /**
     * دریافت تعداد درخواست‌های باقی‌مانده
     */
    public static function getRemaining(
        int $userId,
        int $maxPerMinute = self::DEFAULT_LIMIT,
        string $action = 'general'
    ): int {
        self::init();
        
        $key = "user_{$userId}_{$action}";
        $file = self::$cacheDir . md5($key) . '.json';
        $now = time();
        
        $data = [];
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $data = @json_decode($content, true) ?? [];
            }
        }
        
        $requests = array_filter(
            $data['requests'] ?? [],
            fn($time) => $time > $now - 60
        );
        
        return max(0, $maxPerMinute - count($requests));
    }
    
    /**
     * پاک‌سازی فایل‌های قدیمی
     */
    private static function cleanup(): void
    {
        $now = time();
        $files = @glob(self::$cacheDir . '*.json');
        
        if ($files === false) return;
        
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) continue;
            
            $data = @json_decode($content, true);
            if (!is_array($data)) {
                @unlink($file);
                continue;
            }
            
            $requests = array_filter(
                $data['requests'] ?? [],
                fn($time) => $time > $now - 3600
            );
            
            if (empty($requests)) {
                @unlink($file);
            }
        }
    }
    
    /**
     * ریست برای کاربر مشخص (ادمین)
     */
    public static function reset(int $userId, string $action = 'general'): void
    {
        self::init();
        $key = "user_{$userId}_{$action}";
        $file = self::$cacheDir . md5($key) . '.json';
        @unlink($file);
    }
}
