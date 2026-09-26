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
        // خط را همیشه اول می‌سازیم تا اگر فایل در دسترس نبود، پیامِ اصلی
        // (و نه فقطِ «cannot write») به‌صورتِ خطا به error_log برسد.
        $time = date('Y-m-d H:i:s');
        $line = "[{$time}] [{$level}] [{$context}] {$message}";

        // اگر هنوز هم نتوانستم، لاگ را نمی‌نویسم ولی پیامِ واقعی را دور نمی‌ریزم
        if (!is_dir(dirname($this->logFile))) {
            self::fallbackLog('Cannot create log directory: ' . self::LOG_DIR, $line);
            return;
        }
        $result = @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($result === false) {
            // Last resort: error_log — خودِ لاگ هم نباید علتِ 500 را ببلعد
            self::fallbackLog("Cannot write to {$this->logFile} - check data/ permissions", $line);
        }
    }

    /**
     * لاگِ جایگزین وقتی data/logs در دسترس نیست.
     *
     * قبلاً اینجا فقطِ دلیلِ شکست نوشته می‌شد و خودِ پیام (مثلاً متنِ exception ای
     * که باعثِ HTTP 500 شده) برای همیشه گم می‌شد — یعنی درست در بدترین لحظه،
     * یعنی وقتی لاگ‌فایل خراب است، تنها راهِ فهمیدنِ علتِ خرابی از بین می‌رفت.
     * حالا هر دو در یک خطِ واحد به error_log می‌روند (زیر Apache یعنی
     * /var/log/apache2/error.log) که هیچ‌وقت به پوشهٔ پروژه وابسته نیست.
     */
    private static function fallbackLog(string $reason, string $line): void
    {
        // error_log نوعِ 0 (پیش‌فرض) مستقیم به SAPI می‌رود و از error handler
        // عبور نمی‌کند → هیچ‌وقت باعثِ بازگشتِ بی‌نهایت یا هشدارِ داخلِ JSON نمی‌شود.
        @error_log('[Logger] ' . $reason . ' | ' . $line);
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
    // لاگِ مستقیم، بی‌واسطهٔ Logger: اگر data/ بسته باشد خودِ Logger هم شکست
    // می‌خورد و علتِ 500 در تاریکی می‌ماند. error_log زیر Apache به
    // /var/log/apache2/error.log می‌رود و به پوشهٔ پروژه وابسته نیست.
    $raw = static function (string $tag, string $msg, string $file = '', int $line = 0): void {
        $where = $file !== '' ? " @ {$file}:{$line}" : '';
        @error_log("[bot.php] {$tag}: {$msg}{$where}");
    };

    set_exception_handler(function ($e) use ($raw) {
        $raw('uncaught ' . get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        try {
            $log = Logger::getInstance();
            $log->error('uncaught', $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (Throwable $ignored) {
            // لاگر نباید خودش باعثِ شکستِ دوم شود — پیام بالا همین‌طور به error_log رفته است
        }
        // اگر خروجی هنوز فرستاده نشده، status واقعی را بگذار؛
        // وگرنه وبهوک همیشه 200 می‌دهد و تلگرام آپدیتِ شکست‌خورده را دوباره نمی‌فرستد.
        if (!headers_sent()) http_response_code(500);
    });
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        try {
            Logger::getInstance()->warning('php_error', $message, ['file' => $file, 'line' => $line]);
        } catch (Throwable $ignored) {
        }
        // خروجیِ null مثلِ قبلِ کد: خطای «پردازش‌شده» محسوب می‌شود و PHP
        // هشدار را داخلِ بدنهٔ JSON تزریق نمی‌کند (بدنهٔ وبهوک باید JSON بماند).
        return;
    });
    register_shutdown_function(function () use ($raw) {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $raw('fatal', $error['message'], $error['file'], $error['line']);
            try {
                Logger::getInstance()->error('fatal', $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
            } catch (Throwable $ignored) {
            }
            if (!headers_sent()) http_response_code(500);
        }
    });
}

registerExceptionHandler();
