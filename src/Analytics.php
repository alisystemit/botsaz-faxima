<?php
// ===== Analytics — تجزیهٔ داده‌ها و آمار =====
// استفاده: Analytics::track('command_used', ['command' => '/start']);

class Analytics
{
    private static string $dataDir = __DIR__ . '/../data/analytics/';
    
    // انواع events
    public const EVENT_COMMAND = 'command_used';
    public const EVENT_MESSAGE = 'message_sent';
    public const EVENT_BOT_BUILD = 'bot_build';
    public const EVENT_PAYMENT = 'payment_received';
    public const EVENT_ERROR = 'error_occurred';
    public const EVENT_USER_JOIN = 'user_joined';
    
    public static function init(): void
    {
        if (!is_dir(self::$dataDir)) {
            @mkdir(self::$dataDir, 0755, true);
        }
    }
    
    /**
     * ثبت یک event
     */
    public static function track(string $event, array $data = [], int $userId = 0): void
    {
        self::init();
        
        $entry = [
            'timestamp' => microtime(true),
            'date' => date('Y-m-d'),
            'hour' => (int)date('H'),
            'event' => $event,
            'user_id' => $userId,
            'data' => $data,
        ];
        
        $file = self::$dataDir . 'events-' . date('Y-m-d') . '.jsonl';
        @file_put_contents($file, json_encode($entry) . "\n", FILE_APPEND);
    }
    
    /**
     * دریافت آمار یک event
     */
    public static function getEventStats(string $event, int $daysBack = 30): array
    {
        self::init();
        
        $stats = [
            'total' => 0,
            'by_hour' => array_fill(0, 24, 0),
            'by_day' => [],
            'users' => [],
        ];
        
        $now = time();
        for ($i = 0; $i < $daysBack; $i++) {
            $date = date('Y-m-d', $now - ($i * 86400));
            $file = self::$dataDir . "events-{$date}.jsonl";
            
            if (!is_file($file)) continue;
            
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) continue;
            
            $dayCount = 0;
            foreach ($lines as $line) {
                $entry = @json_decode($line, true);
                if (!is_array($entry) || $entry['event'] !== $event) continue;
                
                $stats['total']++;
                $dayCount++;
                
                $hour = $entry['hour'] ?? 0;
                $stats['by_hour'][$hour]++;
                
                if ($entry['user_id'] > 0) {
                    $userId = $entry['user_id'];
                    $stats['users'][$userId] = ($stats['users'][$userId] ?? 0) + 1;
                }
            }
            
            if ($dayCount > 0) {
                $stats['by_day'][$date] = $dayCount;
            }
        }
        
        return $stats;
    }
    
    /**
     * دریافت top events
     */
    public static function getTopEvents(int $limit = 10, int $daysBack = 30): array
    {
        self::init();
        
        $events = [];
        $now = time();
        
        for ($i = 0; $i < $daysBack; $i++) {
            $date = date('Y-m-d', $now - ($i * 86400));
            $file = self::$dataDir . "events-{$date}.jsonl";
            
            if (!is_file($file)) continue;
            
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) continue;
            
            foreach ($lines as $line) {
                $entry = @json_decode($line, true);
                if (!is_array($entry)) continue;
                
                $event = $entry['event'];
                $events[$event] = ($events[$event] ?? 0) + 1;
            }
        }
        
        arsort($events);
        return array_slice($events, 0, $limit);
    }
    
    /**
     * دریافت active users
     */
    public static function getActiveUsers(int $daysBack = 30): array
    {
        self::init();
        
        $users = [];
        $now = time();
        
        for ($i = 0; $i < $daysBack; $i++) {
            $date = date('Y-m-d', $now - ($i * 86400));
            $file = self::$dataDir . "events-{$date}.jsonl";
            
            if (!is_file($file)) continue;
            
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) continue;
            
            foreach ($lines as $line) {
                $entry = @json_decode($line, true);
                if (!is_array($entry) || $entry['user_id'] <= 0) continue;
                
                $userId = $entry['user_id'];
                if (!isset($users[$userId])) {
                    $users[$userId] = ['count' => 0, 'last_seen' => 0];
                }
                $users[$userId]['count']++;
                $users[$userId]['last_seen'] = max($users[$userId]['last_seen'], $entry['timestamp']);
            }
        }
        
        uasort($users, fn($a, $b) => $b['count'] <=> $a['count']);
        return $users;
    }
    
    /**
     * دریافت conversion rate
     */
    public static function getConversionRate(int $daysBack = 30): float
    {
        $joined = self::getEventStats(self::EVENT_USER_JOIN, $daysBack)['total'];
        $payments = self::getEventStats(self::EVENT_PAYMENT, $daysBack)['total'];
        
        if ($joined === 0) return 0;
        return ($payments / $joined) * 100;
    }
}
