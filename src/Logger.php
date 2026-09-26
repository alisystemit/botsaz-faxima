<?php
// ===== سیستم لاگ و مانیتورینگ =====
// لاگ‌ها در data/logs/ ذخیره می‌شوند

class Logger
{
    private const LOG_DIR = __DIR__ . '/../data/logs/';
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    private const MAX_FILES = 10;

    private static ?Logger $instance = null;
    private string $logFile;
    /** فقط هر ۶۰ ثانیه یک‌بار — قبلاً به‌ازای هر خط لاگ یک glob انجام می‌شد */
    private static int $lastRotateAt = 0;

    private function __construct()
    {
        if (!is_dir(self::LOG_DIR)) {
            @mkdir(self::LOG_DIR, 0755, true);
        }
        $this->rotateLogs();
        $this->logFile = self::LOG_DIR . date('Y-m-d') . '.log';
    }

    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }

    public function info(string $context, string $message): void
    {
        $this->write('INFO', $context, $message);
    }

    public function error(string $context, string $message, array $contextData = []): void
    {
        $extra = !empty($contextData) ? ' | ' . json_encode($contextData) : '';
        $this->write('ERROR', $context, $message . $extra);
    }

    public function warning(string $context, string $message, array $contextData = []): void
    {
        $extra = !empty($contextData) ? ' | ' . json_encode($contextData) : '';
        $this->write('WARN', $context, $message . $extra);
    }

    public function debug(string $context, string $message): void
    {
        $this->write('DEBUG', $context, $message);
    }

    private function write(string $level, string $context, string $message): void
    {
        $this->rotateLogs(true);
        // اگر لاگ‌فایل خالی هست (mkdir نشد)، دوباره تلاش کن
        if (empty($this->logFile) || !is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0755, true);
            $this->logFile = self::LOG_DIR . date('Y-m-d') . '.log';
        }
        // اگر هنوز هم نتوانستم، لاگ را نمی‌نویسم ولی خطا را ثبت می‌کنم
        if (!is_dir(dirname($this->logFile))) {
            error_log("[Logger] Cannot create log directory: " . self::LOG_DIR);
            return;
        }
        $time = date('Y-m-d H:i:s');
        $line = "[{$time}] [{$level}] [{$context}] {$message}\n";
        $result = @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            // Last resort: error_log
            error_log("[Logger] Cannot write to {$this->logFile} - check data/ permissions");
        }
    }

    private function rotateLogs(bool $throttled = false): void
    {
        if ($throttled && (time() - self::$lastRotateAt) < 60) return;
        self::$lastRotateAt = time();
        if (!is_dir(self::LOG_DIR)) {
            @mkdir(self::LOG_DIR, 0755, true);
            // Log file will be set on next write
            return;
        }

        // حذف فایل‌های قدیمی بیش از MAX_FILES
        $files = glob(self::LOG_DIR . '*.log');
        if ($files === false) return;
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        while (count($files) > self::MAX_FILES) {
            @unlink(array_pop($files));
        }
    }

        // بررسی اندازه فایل فعلی
        $current = self::LOG_DIR . date('Y-m-d') . '.log';
        if (file_exists($current) && filesize($current) > self::MAX_FILE_SIZE) {
            $backup = self::LOG_DIR . date('Y-m-d') . '.' . time() . '.log';
            @rename($current, $backup);
        }
    }

    public static function getLogs(int $days = 7): array
    {
        if (!is_dir(self::LOG_DIR)) return [];
        $logs = [];
        $cutoff = strtotime("-{$days} days");
        foreach (glob(self::LOG_DIR . '*.log') as $file) {
            if (filemtime($file) < $cutoff) continue;
            $content = @file_get_contents($file);
            if ($content !== false) {
                $logs[basename($file)] = trim($content);
            }
        }
        return $logs;
    }

    public static function getLastErrorContext(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'curl_version' => function_exists('curl_version') ? curl_version()['version'] : 'N/A',
            'pdo_sqlite' => in_array('sqlite', PDO::getAvailableDrivers()),
            'pdo_mysql' => in_array('mysql', PDO::getAvailableDrivers()),
        ];
    }
}

// ===== ثبت خودکار استثناها =====
function registerExceptionHandler(): void
{
    set_exception_handler(function ($e) {
        $log = Logger::getInstance();
        $log->error('uncaught', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        // اگر خروجی هنوز فرستاده نشده، status واقعی را بگذار؛
        // وگرنه وبهوک همیشه 200 می‌دهد و تلگرام آپدیتِ شکست‌خورده را دوباره نمی‌فرستد.
        if (!headers_sent()) http_response_code(500);
    });
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return;
        Logger::getInstance()->warning('php_error', $message, ['file' => $file, 'line' => $line]);
    });
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            Logger::getInstance()->error('fatal', $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
            if (!headers_sent()) http_response_code(500);
        }
    });
}

registerExceptionHandler();
