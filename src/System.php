<?php
// ===== Plugin System — سیستم افزونه‌ها =====
// استفاده: PluginManager::load('telegram-link-preview');

class PluginManager
{
    private static string $pluginsDir = __DIR__ . '/../plugins/';
    private static array $plugins = [];
    private static array $hooks = [];
    
    public static function init(): void
    {
        if (!is_dir(self::$pluginsDir)) {
            @mkdir(self::$pluginsDir, 0755, true);
        }
    }
    
    /**
     * بارگذاری یک افزونه
     */
    public static function load(string $pluginName): bool
    {
        self::init();
        
        $pluginPath = self::$pluginsDir . $pluginName;
        $manifestFile = $pluginPath . '/plugin.php';
        
        if (!is_file($manifestFile)) {
            return false;
        }
        
        try {
            $manifest = require $manifestFile;
            
            if (!is_array($manifest)) {
                return false;
            }
            
            // Validate manifest
            if (empty($manifest['name']) || empty($manifest['version'])) {
                return false;
            }
            
            self::$plugins[$pluginName] = $manifest;
            
            // Register hooks
            if (!empty($manifest['hooks']) && is_array($manifest['hooks'])) {
                foreach ($manifest['hooks'] as $hookName => $handler) {
                    self::addHook($hookName, $handler, $pluginName);
                }
            }
            
            // Call onLoad hook
            if (!empty($manifest['onLoad']) && is_callable($manifest['onLoad'])) {
                call_user_func($manifest['onLoad']);
            }
            
            return true;
        } catch (Throwable $e) {
            error_log("Plugin load error: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * بارگذاری تمام افزونه‌ها
     */
    public static function loadAll(): int
    {
        self::init();
        
        $dirs = @scandir(self::$pluginsDir);
        if ($dirs === false) return 0;
        
        $loaded = 0;
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir(self::$pluginsDir . $dir)) {
                continue;
            }
            
            if (self::load($dir)) {
                $loaded++;
            }
        }
        
        return $loaded;
    }
    
    /**
     * ثبت یک hook
     */
    public static function addHook(string $hook, callable $callback, string $plugin = 'system'): void
    {
        if (!isset(self::$hooks[$hook])) {
            self::$hooks[$hook] = [];
        }
        
        self::$hooks[$hook][] = [
            'callback' => $callback,
            'plugin' => $plugin,
        ];
    }
    
    /**
     * اجرای تمام hooks یک نقطه
     */
    public static function executeHook(string $hook, array $args = []): array
    {
        $results = [];
        
        if (!isset(self::$hooks[$hook])) {
            return $results;
        }
        
        foreach (self::$hooks[$hook] as $handler) {
            try {
                $result = call_user_func_array($handler['callback'], $args);
                $results[] = [
                    'plugin' => $handler['plugin'],
                    'result' => $result,
                ];
            } catch (Throwable $e) {
                error_log("Hook error in {$handler['plugin']}: {$e->getMessage()}");
            }
        }
        
        return $results;
    }
    
    /**
     * دریافت وضعیت افزونه‌ها
     */
    public static function getStatus(): array
    {
        self::init();
        
        $status = [
            'loaded' => count(self::$plugins),
            'plugins' => [],
            'hooks' => count(self::$hooks),
        ];
        
        foreach (self::$plugins as $name => $manifest) {
            $status['plugins'][] = [
                'name' => $manifest['name'],
                'version' => $manifest['version'],
                'author' => $manifest['author'] ?? 'Unknown',
                'enabled' => true,
            ];
        }
        
        return $status;
    }
}

// ===== Cache Manager — سیستم کش‌کردن =====
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
    
    private static function getCacheFile(string $key): string
    {
        return self::$cacheDir . md5($key) . '.cache';
    }
}

// ===== Monitor — نظارت بر سلامت سیستم =====
class Monitor
{
    private static string $metricsDir = __DIR__ . '/../data/metrics/';
    
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
        
        $file = self::$metricsDir . date('Y-m-d') . '.jsonl';
        @file_put_contents($file, json_encode($metric) . "\n", FILE_APPEND);
    }
    
    /**
     * دریافت وضعیت سیستم
     */
    public static function getStatus(): array
    {
        return [
            'memory' => [
                'usage' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => (int)ini_get('memory_limit') * 1024 * 1024,
            ],
            'disk' => [
                'free' => disk_free_space(__DIR__),
                'total' => disk_total_space(__DIR__),
            ],
            'uptime' => function_exists('posix_times') ? posix_times()['elapsed'] ?? 0 : 0,
            'database' => self::checkDatabase(),
            'webhook' => self::checkWebhook(),
        ];
    }
    
    /**
     * بررسی وضعیت دیتابیس
     */
    private static function checkDatabase(): array
    {
        try {
            $cfg = require __DIR__ . '/../config.php';
            $store = new Store($cfg['manager_db'], $cfg);
            
            return [
                'status' => 'ok',
                'driver' => $store->getDriver(),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * بررسی وضعیت webhook
     */
    private static function checkWebhook(): array
    {
        try {
            $cfg = require __DIR__ . '/../config.php';
            $store = new Store($cfg['manager_db'], $cfg);
            
            $bot = new BotApi();
            $info = $bot->call($cfg['main_token'], 'getWebhookInfo');
            
            return [
                'status' => 'ok',
                'url' => $info['result']['url'] ?? '',
                'pending_updates' => $info['result']['pending_update_count'] ?? 0,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * دریافت dashboard metrics
     */
    public static function getDashboard(): array
    {
        self::init();
        
        $status = self::getStatus();
        $memPercent = ($status['memory']['usage'] / $status['memory']['limit']) * 100;
        $diskPercent = ($status['disk']['free'] / $status['disk']['total']) * 100;
        
        return [
            'status' => $status,
            'memory_percent' => $memPercent,
            'disk_percent' => $diskPercent,
            'health' => [
                'memory' => $memPercent < 80 ? 'ok' : ($memPercent < 90 ? 'warning' : 'critical'),
                'disk' => $diskPercent > 20 ? 'ok' : ($diskPercent > 10 ? 'warning' : 'critical'),
                'database' => $status['database']['status'] === 'ok' ? 'ok' : 'critical',
                'webhook' => $status['webhook']['status'] === 'ok' ? 'ok' : 'critical',
            ],
        ];
    }
}
