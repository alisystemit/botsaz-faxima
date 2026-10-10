<?php
// ===== Monitor — نظارت بر سلامت سیستم =====
// استفاده: Monitor::recordMetric('webhook_latency', 0.25);

class Monitor
{
    private static string $metricsDir = __DIR__ . '/../data/metrics/';
    private static string $statusFile = __DIR__ . '/../data/monitor_status.json';
    
    public static function init(): void
    {
        if (!is_dir(self::$metricsDir)) {
            @mkdir(self::$metricsDir, 0755, true);
        }
    }
    
    /**
     * ثبت یک metric
     */
    public static function recordMetric(string $name, float $value, array $tags = []): void
    {
        self::init();
        
        $metric = [
            'timestamp' => microtime(true),
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
        ];
        
        $file = self::$metricsDir . 'metrics-' . date('Y-m-d') . '.jsonl';
        @file_put_contents($file, json_encode($metric) . "\n", FILE_APPEND);
    }
    
    /**
     * دریافت وضعیت سیستم
     */
    public static function getStatus(): array
    {
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'memory' => self::getMemoryStatus(),
            'disk' => self::getDiskStatus(),
            'database' => self::getDatabaseStatus(),
            'webhook' => self::getWebhookStatus(),
            'uptime' => self::getUptime(),
        ];
    }
    
    /**
     * وضعیت حافظه
     */
    private static function getMemoryStatus(): array
    {
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = (int)ini_get('memory_limit');
        
        if ($limit === -1) {
            $limitBytes = PHP_INT_MAX;
        } else {
            $limitBytes = $limit * 1024 * 1024;
        }
        
        $percent = ($usage / $limitBytes) * 100;
        
        return [
            'usage' => $usage,
            'usage_mb' => round($usage / 1024 / 1024, 2),
            'peak' => $peak,
            'peak_mb' => round($peak / 1024 / 1024, 2),
            'limit' => $limitBytes,
            'limit_mb' => round($limitBytes / 1024 / 1024, 2),
            'percent' => round($percent, 2),
            'health' => $percent < 70 ? 'ok' : ($percent < 85 ? 'warning' : 'critical'),
        ];
    }
    
    /**
     * وضعیت دیسک
     */
    private static function getDiskStatus(): array
    {
        $free = disk_free_space(__DIR__);
        $total = disk_total_space(__DIR__);
        
        if ($free === false || $total === false) {
            return ['status' => 'unknown'];
        }
        
        $used = $total - $free;
        $percent = ($used / $total) * 100;
        
        return [
            'total' => $total,
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'free' => $free,
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            'used' => $used,
            'used_gb' => round($used / 1024 / 1024 / 1024, 2),
            'percent' => round($percent, 2),
            'health' => $percent < 70 ? 'ok' : ($percent < 85 ? 'warning' : 'critical'),
        ];
    }
    
    /**
     * وضعیت دیتابیس
     */
    private static function getDatabaseStatus(): array
    {
        try {
            $cfg = @require __DIR__ . '/../config.php';
            if (!is_array($cfg)) {
                return ['status' => 'error', 'error' => 'config not found'];
            }
            
            $store = new Store($cfg['manager_db'], $cfg);
            
            return [
                'status' => 'ok',
                'driver' => $store->getDriver(),
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'health' => 'critical',
            ];
        }
    }
    
    /**
     * وضعیت webhook
     */
    private static function getWebhookStatus(): array
    {
        try {
            $cfg = @require __DIR__ . '/../config.php';
            if (!is_array($cfg)) {
                return ['status' => 'error', 'error' => 'config not found'];
            }
            
            $api = new BotApi();
            $info = $api->call($cfg['main_token'], 'getWebhookInfo');
            
            if (empty($info['ok'])) {
                return ['status' => 'error', 'error' => $info['description'] ?? 'unknown'];
            }
            
            $result = $info['result'] ?? [];
            
            return [
                'status' => 'ok',
                'url' => $result['url'] ?? '',
                'pending_updates' => $result['pending_update_count'] ?? 0,
                'last_error' => $result['last_error_message'] ?? '',
                'last_error_date' => $result['last_error_date'] ?? 0,
                'health' => empty($result['last_error_message']) ? 'ok' : 'warning',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'health' => 'critical',
            ];
        }
    }
    
    /**
     * دریافت uptime
     */
    private static function getUptime(): int
    {
        if (function_exists('posix_times')) {
            $times = posix_times();
            return (int)($times['elapsed'] ?? 0);
        }
        
        return 0;
    }
    
    /**
     * دریافت dashboard metrics
     */
    public static function getDashboard(): array
    {
        $status = self::getStatus();
        
        return [
            'status' => $status,
            'health' => self::calculateHealthScore($status),
            'alerts' => self::getAlerts($status),
            'recommendations' => self::getRecommendations($status),
        ];
    }
    
    /**
     * محاسبهٔ امتیاز سلامت (0-100)
     */
    private static function calculateHealthScore(array $status): int
    {
        $score = 100;
        
        // حافظه
        if ($status['memory']['health'] === 'warning') $score -= 10;
        if ($status['memory']['health'] === 'critical') $score -= 30;
        
        // دیسک
        if ($status['disk']['health'] === 'warning') $score -= 10;
        if ($status['disk']['health'] === 'critical') $score -= 30;
        
        // دیتابیس
        if ($status['database']['status'] !== 'ok') $score -= 40;
        
        // وبهوک
        if ($status['webhook']['status'] !== 'ok') $score -= 30;
        if ($status['webhook']['health'] === 'warning') $score -= 15;
        
        return max(0, min(100, $score));
    }
    
    /**
     * دریافت alerts
     */
    private static function getAlerts(array $status): array
    {
        $alerts = [];
        
        if ($status['memory']['health'] === 'critical') {
            $alerts[] = ['type' => 'memory', 'message' => 'استفادهٔ حافظه بیش از 85%'];
        }
        
        if ($status['disk']['health'] === 'critical') {
            $alerts[] = ['type' => 'disk', 'message' => 'فضای دیسک کمتر از 15%'];
        }
        
        if ($status['database']['status'] !== 'ok') {
            $alerts[] = ['type' => 'database', 'message' => 'دیتابیس دسترسی‌پذیر نیست'];
        }
        
        if ($status['webhook']['status'] !== 'ok') {
            $alerts[] = ['type' => 'webhook', 'message' => 'وبهوک خطا داشته است'];
        }
        
        return $alerts;
    }
    
    /**
     * دریافت توصیه‌ها
     */
    private static function getRecommendations(array $status): array
    {
        $recommendations = [];
        
        if ($status['memory']['percent'] > 70) {
            $recommendations[] = 'حافظه رام رو زیاد کنید یا کش‌ها رو پاک کنید';
        }
        
        if ($status['disk']['percent'] > 85) {
            $recommendations[] = 'فضای دیسک اضافه کنید';
        }
        
        if ($status['webhook']['pending_updates'] > 100) {
            $recommendations[] = 'تعداد زیادی پیام در صف است. وبهوک رو بررسی کنید';
        }
        
        return $recommendations;
    }
    
    /**
     * ذخیرهٔ وضعیت برای مراجعهٔ بعدی
     */
    public static function saveStatus(): void
    {
        self::init();
        $status = self::getStatus();
        @file_put_contents(self::$statusFile, json_encode($status, JSON_PRETTY_PRINT));
    }
    
    /**
     * دریافت وضعیت ذخیره‌شده
     */
    public static function getLastStatus(): array
    {
        if (!is_file(self::$statusFile)) {
            return [];
        }
        
        $content = @file_get_contents(self::$statusFile);
        return $content ? @json_decode($content, true) : [];
    }
}
