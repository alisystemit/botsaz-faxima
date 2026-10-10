<?php
// ===== Cache Manager — سیستم کش‌کردن =====
// استفاده: CacheManager::get('key', fn() => expensive_operation(), 3600);

class CacheManager
{
    private static string $cacheDir = __DIR__ . '/../data/cache/';
    
    public static function init(): void
    {
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    /**
     * دریافت مقدار از cache
     */
    public static function get(string $key, callable $fallback = null, int $ttl = 3600): mixed
    {
        self::init();
        
        $file = self::getCacheFile($key);
        $now = time();
        
        if (is_file($file)) {
            $cached = @file_get_contents($file);
            if ($cached !== false) {
                $data = @json_decode($cached, true);
                
                if (is_array($data) && ($data['expires'] ?? 0) > $now) {
                    return $data['value'];
                }
            }
            
            @unlink($file);
        }
        
        if ($fallback === null) {
            return null;
        }
        
        $value = call_user_func($fallback);
        self::set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * ذخیرهٔ مقدار در cache
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): void
    {
        self::init();
        
        $file = self::getCacheFile($key);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
        ];
        
        @file_put_contents($file, json_encode($data));
    }
    
    /**
     * حذف از cache
     */
    public static function delete(string $key): void
    {
        self::init();
        $file = self::getCacheFile($key);
        @unlink($file);
    }
    
    /**
     * پاک‌کردن تمام cache
     */
    public static function flush(): int
    {
        self::init();
        
        $files = @glob(self::$cacheDir . '*.cache');
        if ($files === false) return 0;
        
        $deleted = 0;
        foreach ($files as $file) {
            if (@unlink($file)) $deleted++;
        }
        
        return $deleted;
    }
    
    /**
     * پاک‌کردن cache‌های منقضی
     */
    public static function cleanup(): int
    {
        self::init();
        
        $files = @glob(self::$cacheDir . '*.cache');
        if ($files === false) return 0;
        
        $deleted = 0;
        $now = time();
        
        foreach ($files as $file) {
            $cached = @file_get_contents($file);
            if ($cached === false) continue;
            
            $data = @json_decode($cached, true);
            if (!is_array($data) || ($data['expires'] ?? 0) < $now) {
                if (@unlink($file)) $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * دریافت آمار cache
     */
    public static function getStats(): array
    {
        self::init();
        
        $files = @glob(self::$cacheDir . '*.cache');
        if ($files === false) return ['total' => 0, 'expired' => 0, 'size' => 0];
        
        $total = 0;
        $expired = 0;
        $size = 0;
        $now = time();
        
        foreach ($files as $file) {
            $total++;
            $size += filesize($file);
            
            $cached = @file_get_contents($file);
            if ($cached !== false) {
                $data = @json_decode($cached, true);
                if (is_array($data) && ($data['expires'] ?? 0) < $now) {
                    $expired++;
                }
            }
        }
        
        return [
            'total' => $total,
            'expired' => $expired,
            'size' => $size,
            'size_mb' => round($size / 1024 / 1024, 2),
        ];
    }
    
    private static function getCacheFile(string $key): string
    {
        return self::$cacheDir . md5($key) . '.cache';
    }
}
