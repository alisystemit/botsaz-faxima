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
