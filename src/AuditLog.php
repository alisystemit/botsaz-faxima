<?php
// ===== Audit Log — ثبت تمام عملیات حساس =====
// استفاده: AuditLog::log('bot_created', ['type' => 'faxima'], $adminId);

class AuditLog
{
    private static string $logDir = __DIR__ . '/../data/audit/';
    
    // انواع عملیات
    public const ACTION_BOT_CREATE = 'bot_create';
    public const ACTION_BOT_DELETE = 'bot_delete';
    public const ACTION_BOT_UPDATE = 'bot_update';
    public const ACTION_PAYMENT_RECEIVED = 'payment_received';
    public const ACTION_PAYMENT_FAILED = 'payment_failed';
    public const ACTION_USER_CREATED = 'user_created';
    public const ACTION_USER_ADMIN = 'user_admin_toggle';
    public const ACTION_CONFIG_CHANGED = 'config_changed';
    public const ACTION_SECURITY_ALERT = 'security_alert';
    public const ACTION_LOGIN = 'login';
    
    public static function init(): void
    {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }
    
    /**
     * ثبت یک عملیات
     */
    public static function log(
        string $action,
        array $data = [],
        int $userId = 0,
        int $severity = 0 // 0: info, 1: warning, 2: critical
    ): void {
        self::init();
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'user_id' => $userId,
            'severity' => $severity,
            'data' => $data,
            'ip' => self::getClientIp(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ];
        
        // ثبت در فایل روزانه
        $date = date('Y-m-d');
        $file = self::$logDir . "audit-{$date}.jsonl";
        @file_put_contents($file, json_encode($logEntry) . "\n", FILE_APPEND);
        
        // ثبت در فایل critical (فقط entries مهم)
        if ($severity >= 1) {
            $criticalFile = self::$logDir . 'critical.jsonl';
            @file_put_contents($criticalFile, json_encode($logEntry) . "\n", FILE_APPEND);
        }
        
        // ارسال alert برای critical events
        if ($severity >= 2) {
            self::alertAdmins($action, $data);
        }
    }
    
    /**
     * دریافت لاگ‌های یک محدودهٔ زمانی
     */
    public static function getLogs(
        string $action = '',
        int $daysBack = 7,
        int $limit = 1000
    ): array {
        self::init();
        
        $logs = [];
        $now = time();
        
        for ($i = 0; $i < $daysBack; $i++) {
            $date = date('Y-m-d', $now - ($i * 86400));
            $file = self::$logDir . "audit-{$date}.jsonl";
            
            if (!is_file($file)) continue;
            
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) continue;
            
            foreach (array_reverse($lines) as $line) {
                if (count($logs) >= $limit) break;
                
                $entry = @json_decode($line, true);
                if (!is_array($entry)) continue;
                
                if ($action !== '' && $entry['action'] !== $action) continue;
                
                $logs[] = $entry;
            }
            
            if (count($logs) >= $limit) break;
        }
        
        return array_slice($logs, 0, $limit);
    }
    
    /**
     * دریافت تمام critical events
     */
    public static function getCriticalEvents(int $limit = 100): array
    {
        self::init();
        
        $criticalFile = self::$logDir . 'critical.jsonl';
        if (!is_file($criticalFile)) return [];
        
        $lines = file($criticalFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];
        
        $events = [];
        foreach (array_reverse($lines) as $line) {
            if (count($events) >= $limit) break;
            $entry = @json_decode($line, true);
            if (is_array($entry)) $events[] = $entry;
        }
        
        return $events;
    }
    
    /**
     * آمار عملیات
     */
    public static function getStats(int $daysBack = 30): array
    {
        self::init();
        
        $stats = [
            'total' => 0,
            'by_action' => [],
            'by_severity' => [0 => 0, 1 => 0, 2 => 0],
            'by_user' => [],
        ];
        
        $logs = self::getLogs('', $daysBack, 100000);
        
        foreach ($logs as $log) {
            $stats['total']++;
            
            $action = $log['action'];
            $stats['by_action'][$action] = ($stats['by_action'][$action] ?? 0) + 1;
            
            $severity = $log['severity'] ?? 0;
            $stats['by_severity'][$severity]++;
            
            $userId = $log['user_id'] ?? 0;
            if ($userId > 0) {
                $stats['by_user'][$userId] = ($stats['by_user'][$userId] ?? 0) + 1;
            }
        }
        
        return $stats;
    }
    
    /**
     * جستجو در لاگ‌ها
     */
    public static function search(
        array $filters,
        int $limit = 1000
    ): array {
        self::init();
        
        $results = [];
        $logs = self::getLogs('', 30, 100000);
        
        foreach ($logs as $log) {
            // فیلتر بر اساس شرایط
            if (isset($filters['action']) && $log['action'] !== $filters['action']) continue;
            if (isset($filters['user_id']) && $log['user_id'] !== $filters['user_id']) continue;
            if (isset($filters['severity']) && $log['severity'] !== $filters['severity']) continue;
            if (isset($filters['ip']) && $log['ip'] !== $filters['ip']) continue;
            
            if (count($results) >= $limit) break;
            $results[] = $log;
        }
        
        return $results;
    }
    
    /**
     * پاک‌کردن لاگ‌های قدیمی
     */
    public static function cleanup(int $daysOld = 90): int
    {
        self::init();
        
        $deleted = 0;
        $files = @glob(self::$logDir . 'audit-*.jsonl');
        
        if ($files === false) return 0;
        
        $cutoffTime = time() - ($daysOld * 86400);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (@unlink($file)) $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * دریافت IP کاربر
     */
    private static function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? // Cloudflare
              $_SERVER['HTTP_X_FORWARDED_FOR'] ?? // Proxy
              $_SERVER['REMOTE_ADDR'] ?? // Direct
              'unknown';
        
        // اگر X-Forwarded-For چند IP داشت، اول را بگیر
        if (strpos($ip, ',') !== false) {
            $ips = explode(',', $ip);
            $ip = trim($ips[0]);
        }
        
        return $ip;
    }
    
    /**
     * ارسال alert برای ادمین‌ها
     */
    private static function alertAdmins(string $action, array $data): void
    {
        // TODO: اضافهٔ ارسال پیام تلگرام به ادمین‌ها
        error_log("CRITICAL AUDIT: {$action} - " . json_encode($data));
    }
}
