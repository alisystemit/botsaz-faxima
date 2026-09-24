<?php
// ===== منطق ساخت/حذف/مدیریت ربات‌های فرزند =====

class Manager
{
    public static function slugify(string $s): string
    {
        $s = trim(mb_strtolower($s));
        // فقط حروف لاتین/عدد برای نام پوشه و دیتابیس مجاز است
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        $s = trim($s, '-');
        // نام‌های خیلی کوتاه/غیرلاتین: به‌جای time() (که دو ورودی همزمان را یکسان می‌کند)
        // از تصادفی بودن استفاده می‌شود تا برخورد تصادفی slug رخ ندهد.
        if (strlen($s) < 3) {
            $suffix = substr(bin2hex(random_bytes(4)), 0, 6);
            $s = ($s !== '' ? $s . '-' : '') . $suffix;
        }
        if (!preg_match('/^[a-z]/', $s)) $s = 'bot-' . $s;
        $s = substr($s, 0, 40);
        $s = trim($s, '-');
        if ($s === '' || strlen($s) < 3) $s = 'bot-' . substr(bin2hex(random_bytes(4)), 0, 6);
        return $s;
    }

    /** کلید AES-256 از secret_key (۳۲ بایت خام به‌جای رشتهٔ hex بریده‌شده) */
    public static function encryptionKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    /** کلید AES قدیمی — فقط برای رمزگشایی توکن‌های ساخته‌شده با نسخه‌های قبلی */
    public static function legacyEncryptionKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /** یک منبع واحد برای secret_key تا همه‌جا (bot.php / healthcheck / cron / tools) یکی باشند */
    public const DEFAULT_SECRET_KEY = 'change-this-to-a-random-string';

    public static function secretKey(?array $cfg): string
    {
        $k = trim((string)($cfg['secret_key'] ?? ''));
        return $k !== '' ? $k : self::DEFAULT_SECRET_KEY;
    }

    /** پاک‌سازی پیام خطای دیتابیس پیش از نمایش به کاربر (پیام کامل در error_log می‌ماند) */
    public static function sanitizeDbError(string $msg): string
    {
        // کاربر/هاست مجاز ('root'@'localhost') → ('***'@'***')
        $msg = preg_replace("~('[^']*'@'[^']*')~", "'***'@'***'", $msg) ?? $msg;
        // "using password: NO|YES" و "password: ..." → password: ***
        $msg = preg_replace("~((?:using\s+)?password\s*[:=]\s*)\S+~i", '$1***', $msg) ?? $msg;
        return $msg;
    }

    public static function copyDir(string $src, string $dst, array $exclude = []): void
    {
        if (!is_dir($src)) throw new Exception("قالب پیدا نشد: $src");
        @mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            $relPath = $it->getSubPathName();
            foreach ($exclude as $ex) {
                // «docker/» و «docker» هر دو باید پوشه را مستثنا کنند؛ قبلاً نوشتهٔ «docker/»
                // هیچ‌وقت با $relPath برابر نمی‌شد و کل پوشه کپی می‌شد.
                $norm = rtrim(str_replace('\\', '/', $ex), '/');
                if ($norm === '') continue;
                $relNorm = str_replace('\\', '/', $relPath);
                if ($relNorm === $ex || $relNorm === $norm
                    || str_starts_with($relNorm, $ex . '/') || str_starts_with($relNorm, $norm . '/')) {
                    continue 2;
                }
            }
            $target = $dst . DIRECTORY_SEPARATOR . $relPath;
            if ($f->isDir()) @mkdir($target, 0777, true);
            else copy($f->getPathname(), $target);
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }

    /** ساخت دیتابیس جدید MySQL و (اختیاری) ایمپورت schema.sql — برمی‌گرداند: [dbName, tablePrefix]
     *  اگر schemaFile برابر null باشد فقط دیتابیس خالی ساخته می‌شود (مثل میرزا که table.php خودش جدول می‌سازد). */
    public static function createDatabase(array $cfg, string $folder, ?string $schemaFile): array
    {
        $host = $cfg['db_host'] ?? '127.0.0.1';
        $port = $cfg['db_port'] ?? 3306;
        $user = $cfg['db_user'] ?? 'root';
        $pass = $cfg['db_pass'] ?? '';
        $prefix = $cfg['db_prefix'] ?? 'botsaz_';
        $rand = substr(strtolower(bin2hex(random_bytes(3))), 0, 6);
        $safeFolder = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $folder)));
        $dbName = $prefix . $safeFolder . '_' . $rand;

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // تلاش اول: ساخت دیتابیس جداگانه
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci");
            if ($schemaFile !== null) self::importSql($pdo, $dbName, $schemaFile, '');
            return [$dbName, ''];
        } catch (PDOException $e) {
            error_log("createDatabase '{$dbName}' failed: " . $e->getMessage());
            throw new Exception(
                "ساخت دیتابیس '{$dbName}' ناموفق (\"" . self::sanitizeDbError($e->getMessage()) . "\") — "
                . "لطفاً مطمئن شوید کاربر دیتابیس دسترسی CREATE DATABASE دارد. "
                . "در هاست اشتراکی ممکن است نیاز به تغییر تنظیمات دیتابیس باشد."
            );
        }
    }

    /** حذف فایل‌های حساس از یک درخت (حتی تو در تو) — برای بکاپ‌ها */
    public static function stripSensitiveFiles(string $dir, array $basenames): void
    {
        if (!is_dir($dir) || $basenames === []) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        $wanted = array_flip($basenames);
        foreach ($it as $f) {
            if ($f->isDir()) continue;
            if (isset($wanted[$f->getFilename()])) @unlink($f->getPathname());
        }
    }

    /** نگه‌داشتن حداکثر $keep بکاپ از هر ربات (کنترل رشد bots/backups/) */
    public static function pruneBackups(string $backupsDir, string $folder, int $keep = 5): int
    {
        $keep = max(1, $keep);
        $items = glob(rtrim($backupsDir, '/\\') . '/' . $folder . '_*', GLOB_ONLYDIR);
        if (!$items) return 0;
        usort($items, fn($a, $b) => (int)@filemtime($b) <=> (int)@filemtime($a));
        $removed = 0;
        foreach (array_slice($items, $keep) as $old) {
            self::removeDir($old);
            $removed++;
        }
        return $removed;
    }

    private static function importSql(PDO $pdo, string $db, string $file, string $tablePrefix): void
    {
        if (!file_exists($file)) throw new Exception("فایل schema پیدا نشد");
        $sql = file_get_contents($file);
        if ($tablePrefix !== '') {
            // جایگزینی ساده نام جدول‌ها با پیشوند
            $sql = preg_replace_callback('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', function ($m) use ($tablePrefix) {
                return "CREATE TABLE IF NOT EXISTS `{$tablePrefix}{$m[1]}`";
            }, $sql);
            $sql = preg_replace_callback('/INSERT INTO\s+`?(\w+)`?/i', function ($m) use ($tablePrefix) {
                // اگر قبلا پیشوند نخورده
                if (str_starts_with($m[1], $tablePrefix)) return $m[0];
                return "INSERT INTO `{$tablePrefix}{$m[1]}`";
            }, $sql);
        }
        $pdo->exec("USE `{$db}`");
        // اجرای چنددستوری
        $pdo->exec("SET NAMES utf8mb4");
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
    }

    /** نوشتن config.php داخل پوشه ربات فرزند */
    public static function writeChildConfig(string $botDir, array $data): void
    {
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";
        file_put_contents($botDir . '/config.php', $content);
    }

    public static function childBotsDir(): string
    {
        return dirname(__DIR__) . '/bots';
    }

    public static function templateDir(string $type): string
    {
        return dirname(__DIR__) . '/templates/' . $type;
    }

    public static function validTypes(): array
    {
        return ['faxima' => 'فاکسیما (فروش VPN)', 'mirza' => 'میرزا (فروش VPN)'];
    }

    /** فایل ورودی وبهوک هر قالب (هر دو سورس واقعی ورودی index.php دارند) */
    public static function entryFile(string $type): string
    {
        return 'index.php';
    }

    /** آدرس وبهوک ربات فرزند — برای میرزا بدون secret، برای فاکسیما با secret */
    public static function webhookUrl(array $cfg, string $folder, string $type, string $secret = ''): string
    {
        $url = rtrim($cfg['base_url'], '/') . '/bots/' . $folder . '/' . self::entryFile($type);
        if ($secret !== '') $url .= '?secret=' . $secret;
        return $url;
    }

    /**
     * پچ کردن config.php میرزا (جایگزینی placeholderها مثل نصب‌کننده رسمی).
     * $domainPath یعنی «دامنه/مسیر» بدون https، مثلا: example.com/botsaz-faxima/bots/shop1
     */
    public static function patchMirzaConfig(string $botDir, array $cfg, string $dbName, string $token, int $adminId, string $botUsername, string $domainPath): void
    {
        $file = $botDir . '/config.php';
        $raw = file_get_contents($file);
        if ($raw === false) throw new Exception("config.php میرزا پیدا نشد");
        $replacements = [
            '{DATABASE_NAME}' => $dbName,
            '{DATABASE_USERNAME}' => $cfg['db_user'],
            '{DATABASE_PASSOWRD}' => $cfg['db_pass'],
            '{DATABASE_PASSWORD}' => $cfg['db_pass'],
            '{BOT_TOKEN}' => $token,
            '{ADMIN_#ID}' => (string)$adminId,
            '{DOMAIN.COM/PATH/BOT}' => $domainPath,
            '{BOT_USERNAME}' => $botUsername,
        ];
        $new = str_replace(array_keys($replacements), array_values($replacements), $raw, $count);
        if ($count === 0) throw new Exception("placeholderهای config میرزا پیدا نشد (نسخه ناسازگار؟)");
        $afterPlaceholders = $new;
        // هاست دیتابیس میرزا هاردکد localhost است؛ با هاست/پورت واقعی جایگزین می‌کنیم.
        // پورت صریحاً به‌عنوان آرگومان پنجم mysqli_connect داده می‌شود («host:port» داخل آرگومان host
        // رسمی/پشتیبانی‌شده نیست و روی بعضی استک‌ها نادیده گرفته می‌شود).
        $dbHost = $cfg['db_host'] ?? 'localhost';
        $dbPort = (int)($cfg['db_port'] ?? 3306);
        // استفاده از regex برای انعطاف‌پذیری بیشتر
        $afterMysqli = preg_replace(
            '/mysqli_connect\s*\(\s*"localhost"\s*,\s*(\$[a-zA-Z_]+)\s*,\s*(\$[a-zA-Z_]+)\s*,\s*(\$[a-zA-Z_]+)\s*\)/',
            'mysqli_connect("' . $dbHost . '", $1, $2, $3, ' . $dbPort . ')',
            $new
        );
        $new = is_string($afterMysqli) ? $afterMysqli : $new;
        // ===== فیکس: DSN مجزا برای PDO — regex برای انعطاف‌پذیری بیشتر =====
        $afterDsn = preg_replace(
            '/mysql:host=localhost(?:;|$)/',
            'mysql:host=' . $dbHost . ';port=' . $dbPort . ';',
            $new
        );
        $new = is_string($afterDsn) ? $afterDsn : $new;
        // اگر هیچ‌کدام از regexها چیزی عوض نکردند، backup امن str_replace (فقط وقتی واقعاً نیاز است)
        if ($new === $afterPlaceholders) {
            $new = str_replace(
                ['mysql:host=localhost;', 'mysqli_connect("localhost"'],
                ['mysql:host=' . $dbHost . ';port=' . $dbPort . ';', 'mysqli_connect("' . $dbHost . '"'],
                $new
            );
        }
        if (file_put_contents($file, $new) === false) throw new Exception("خطا در نوشتن config.php میرزا");
    }

    /** رمزگشایی توکن ذخیره‌شده ربات فرزند (سازگار با توکن‌های ساده قدیمی و کلید قدیمی) */
    public static function decryptChildToken(string $stored, string $key): string
    {
        if ($stored === '') return '';
        $data = base64_decode($stored, true);
        if ($data === false || strlen($data) <= 16) return $stored;
        $iv = substr($data, 0, 16);
        $enc = substr($data, 16);
        foreach ([self::encryptionKey($key), self::legacyEncryptionKey($key)] as $k) {
            $d = openssl_decrypt($enc, 'AES-256-CBC', $k, 0, $iv);
            if (is_string($d) && preg_match('/^\d+:[\w\-]{20,}$/', $d)) return $d;
        }
        return $stored;
    }

    /** secret وبهوک تلگرام: فقط وقتی سرور واقعاً override داده (مثل روی داکر) */
    private static function webhookSecretOverride(): string
    {
        $configured = getenv('TELEGRAM_WEBHOOK_SECRET') ?: ($_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '');
        if ($configured === '' && defined('TELEGRAM_WEBHOOK_SECRET')) {
            $configured = constant('TELEGRAM_WEBHOOK_SECRET');
        }
        return (is_string($configured) && $configured !== '') ? $configured : '';
    }

    /** secret وبهوک فاکسیما — دقیقاً همان فرمول lib/WebhookAuth.php (شامل override) */
    public static function faximaWebhookSecret(string $botToken): string
    {
        $override = self::webhookSecretOverride();
        if ($override !== '') return $override;
        return $botToken === '' ? '' : hash('sha256', $botToken . '_faoxima_webhook_secret');
    }

    /** secret دسترسی به table.php فاکسیما (فقط با $APIKEY همان ربات قابل محاسبه است) */
    public static function faximaTableSecret(string $botToken): string
    {
        return $botToken === '' ? '' : hash('sha256', $botToken . '_faxima_table_secret');
    }

    /** secret دسترسی به table.php میرزا */
    public static function mirzaTableSecret(string $botToken): string
    {
        return $botToken === '' ? '' : hash('sha256', $botToken . '_mirza_table_secret');
    }

    /** اجرای table.php از طریق HTTP (ساخت جدول‌ها) — مثل نصب‌کننده‌های رسمی */
    public static function triggerTable(string $tableUrl, string $secret = ''): bool
    {
        if ($secret !== '') {
            $tableUrl .= (strpos($tableUrl, '?') === false ? '?' : '&') . 'secret=' . rawurlencode($secret);
        }
        // تلاش اول: file_get_contents
        // تأیید TLS روشن: این آدرس همان base_url سایت خودمان است (همان گواهی که تلگرام برای وبهوک قبول دارد)
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $res = @file_get_contents($tableUrl, false, $ctx);
        if ($res !== false && isset($http_response_header[0])) {
            if (preg_match('{HTTP/\S*\s+(\d+)}', $http_response_header[0], $m)) {
                return (int)$m[1] >= 200 && (int)$m[1] < 300;
            }
        }
        if ($res !== false) return true;
        // تلاش دوم: cURL fallback اگر file_get_contents غیرفعال باشد
        if (function_exists('curl_init')) {
            $ch = curl_init($tableUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => false,
            ]);
            $out = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        }
        return false;
    }
    /** اجرای table.php میرزا از طریق HTTP — نگهداشته برای سازگاری */
    public static function runMirzaTable(string $tableUrl, string $secret = ''): bool
    {
        return self::triggerTable($tableUrl, $secret);
    }

    /**
     * پچ کردن config.php فاکسیما (جایگزینی مقدار ۹ متغیر — همان کاری که نصب‌کننده رسمی می‌کند
     * به‌علاوهٔ $dbport که نصب‌کننده رسمی ندارد).
     * $domainPath یعنی «دامنه/مسیر» بدون https، مثلا: example.com/botsaz-faxima/bots/shop1
     */
    public static function patchFaximaConfig(string $botDir, array $cfg, string $dbName, string $token, int $adminId, string $botUsername, string $domainPath): void
    {
        $file = $botDir . '/config.php';
        $raw = file_get_contents($file);
        if ($raw === false) throw new Exception("config.php فاکسیما پیدا نشد");
        // ===== فیکس: host و port کاملاً جدا =====
        // قبلاً «host:port» داخل $dbhost می‌نشست که هم mysqli_connect (آرگومان host)
        // و هم DSN می‌شکست؛ حالا $dbhost فقط هاست است و پورت در $dbport می‌رود.
        $dbHost = (string)($cfg['db_host'] ?? 'localhost');
        $dbPort = (int)($cfg['db_port'] ?? 3306);
        $values = [
            'dbname' => $dbName,
            'usernamedb' => $cfg['db_user'],
            'passworddb' => $cfg['db_pass'],
            'dbhost' => $dbHost,
            'dbport' => (string)$dbPort,
            'APIKEY' => $token,
            'adminnumber' => (string)$adminId,
            'domainhosts' => $domainPath,
            'usernamebot' => $botUsername,
        ];
        $new = $raw; $count = 0;
        foreach ($values as $var => $val) {
            $pattern = '/(\$' . preg_quote($var, '/') . '\s*=\s*)((?:\'(?:\\\\.|[^\'\\\\])*\')|(?:"(?:\\\\.|[^"\\\\])*"))(\s*;)/u';
            $new = preg_replace_callback($pattern, function ($m) use ($val, &$count) {
                $count++;
                $q = $m[2][0];
                $v = str_replace('\\', '\\\\', $val);
                $v = $q === "'" ? str_replace("'", "\\'", $v) : str_replace('"', '\\"', $v);
                return $m[1] . $q . $v . $q . $m[3];
            }, $new, 1);
        }
        if ($count < count($values)) throw new Exception("پچ config فاکسیما ناقص ماند ({$count}/" . count($values) . ") — نسخه ناسازگار؟");
        if (file_put_contents($file, $new) === false) throw new Exception("خطا در نوشتن config.php فاکسیما");
    }

    // ===== پاکسازی فایل‌های اضافی از کپی =====
    public static function cleanupExtraFiles(string $botDir, array $extraPaths): void
    {
        foreach ($extraPaths as $path) {
            $fullPath = $botDir . '/' . $path;
            if (is_dir($fullPath)) {
                self::removeDir($fullPath);
            } elseif (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
}
