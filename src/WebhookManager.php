<?php
// ===== API Webhook Manager — ارسال events به ربات‌های فرزند =====
// استفاده: WebhookManager::trigger('new_user', ['user_id' => 123]);

class WebhookManager
{
    private static string $queueDir = __DIR__ . '/../data/webhooks/';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 300; // 5 minutes
    
    // انواع events
    public const EVENT_NEW_USER = 'new_user';
    public const EVENT_BOT_ACTIVE = 'bot_active';
    public const EVENT_BOT_INACTIVE = 'bot_inactive';
    public const EVENT_PAYMENT_RECEIVED = 'payment_received';
    public const EVENT_CONFIG_CHANGED = 'config_changed';
    
    public static function init(): void
    {
        if (!is_dir(self::$queueDir)) {
            @mkdir(self::$queueDir, 0755, true);
        }
    }
    
    /**
     * Trigger یک webhook event
     */
    public static function trigger(string $event, array $data = [], string $botSlug = ''): void
    {
        self::init();
        
        $payload = [
            'event' => $event,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data,
            'bot_slug' => $botSlug,
        ];
        
        // ذخیرهٔ در queue برای processing بعدی
        $queueFile = self::$queueDir . time() . '-' . uniqid() . '.json';
        @file_put_contents($queueFile, json_encode($payload));
    }
    
    /**
     * ارسال تمام webhooks در queue
     */
    public static function processQueue(): array
    {
        self::init();
        
        $results = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
        ];
        
        $files = @glob(self::$queueDir . '*.json');
        if ($files === false) return $results;
        
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) continue;
            
            $payload = @json_decode($content, true);
            if (!is_array($payload)) {
                @unlink($file);
                continue;
            }
            
            // بررسی تعداد retry‌ها
            $retryFile = $file . '.retry';
            $retries = 0;
            if (is_file($retryFile)) {
                $retries = (int)@file_get_contents($retryFile);
            }
            
            if ($retries > self::MAX_RETRIES) {
                @unlink($file);
                @unlink($retryFile);
                $results['failed']++;
                continue;
            }
            
            // تلاش برای ارسال
            if (self::send($payload)) {
                @unlink($file);
                @unlink($retryFile);
                $results['processed']++;
            } else {
                // Increment retry counter
                @file_put_contents($retryFile, $retries + 1);
                
                // Check if retry delay has passed
                if (time() - filemtime($file) > self::RETRY_DELAY) {
                    $results['failed']++;
                    $results['errors'][] = 'Max retries exceeded for: ' . basename($file);
                    @unlink($file);
                    @unlink($retryFile);
                }
            }
        }
        
        return $results;
    }
    
    /**
     * ارسال webhook به endpoint
     */
    private static function send(array $payload): bool
    {
        $botSlug = $payload['bot_slug'] ?? '';
        $botDir = __DIR__ . '/../bots/' . $botSlug;
        $configFile = $botDir . '/config.php';
        
        if (!is_file($configFile)) return false;
        
        $cfg = @require $configFile;
        if (!is_array($cfg)) return false;
        
        $webhookUrl = $cfg['webhook_endpoint'] ?? '';
        if ($webhookUrl === '') return false;
        
        // ارسال HTTP POST
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . hash_hmac('sha256', json_encode($payload), $cfg['secret_key'] ?? ''),
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        
        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode >= 200 && $httpCode < 300;
    }
    
    /**
     * دریافت وضعیت queue
     */
    public static function getQueueStatus(): array
    {
        self::init();
        
        $files = @glob(self::$queueDir . '*.json');
        if ($files === false) return ['pending' => 0, 'failed' => 0];
        
        $pending = 0;
        $failed = 0;
        
        foreach ($files as $file) {
            $retryFile = $file . '.retry';
            $retries = is_file($retryFile) ? (int)@file_get_contents($retryFile) : 0;
            
            if ($retries >= self::MAX_RETRIES) {
                $failed++;
            } else {
                $pending++;
            }
        }
        
        return ['pending' => $pending, 'failed' => $failed];
    }
}
