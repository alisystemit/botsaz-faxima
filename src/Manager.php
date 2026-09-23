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
        if (strlen($s) < 3) $s = 'bot-' . substr($s . '-' . time(), -8);
        if (!preg_match('/^[a-z]/', $s)) $s = 'bot-' . $s;
        return substr($s, 0, 40);
    }

    public static function copyDir(string $src, string $dst, array $exclude = []): void
    {
        if (!is_dir($src)) throw new Exception("قالب پیدا نشد: $src");
        @mkdir($dst, 0777, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            $relPath = $it->getSubPathName();
            foreach ($exclude as $ex) {
                if ($relPath === $ex || str_starts_with($relPath, $ex . '/')) continue 2;
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
        $host = $cfg['db_host']; $port = $cfg['db_port'] ?? 3306;
        $user = $cfg['db_user']; $pass = $cfg['db_pass'];
        $prefix = $cfg['db_prefix'] ?? 'botsaz_';
        $rand = substr(strtolower(bin2hex(random_bytes(3))), 0, 6);
        $safeFolder = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $folder)));
        $dbName = $prefix . $safeFolder . '_' . $rand;

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci");
            if ($schemaFile !== null) self::importSql($pdo, $dbName, $schemaFile, '');
            return [$dbName, ''];
        } catch (Exception $e) {
            // fallback: هاست اشتراکی بدون دسترسی ساخت دیتابیس → جدول‌های پیشونددار در دیتابیس مشترک
            // (فقط برای قالب‌های schemaمحور مثل فاکسیما؛ میرزا نام جدول ثابت می‌خواهد و fallback ندارد)
            if (empty($cfg['fallback_db_name']) || $schemaFile === null) throw $e;
            $shared = $cfg['fallback_db_name'];
            $tp = $prefix . $safeFolder . '_' . $rand . '_';
            self::importSql($pdo, $shared, $schemaFile, $tp);
            return [$shared, $tp];
        }
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
            '{BOT_TOKEN}' => $token,
            '{ADMIN_#ID}' => (string)$adminId,
            '{DOMAIN.COM/PATH/BOT}' => $domainPath,
            '{BOT_USERNAME}' => $botUsername,
        ];
        $new = str_replace(array_keys($replacements), array_values($replacements), $raw, $count);
        if ($count === 0) throw new Exception("placeholderهای config میرزا پیدا نشد (نسخه ناسازگار؟)");
        // هاست دیتابیس میرزا هاردکد localhost است؛ با هاست واقعی جایگزین می‌کنیم
        $dbHost = $cfg['db_host'];
        $dbPort = $cfg['db_port'] ?? 3306;
        $dbHostStr = $dbHost . ($dbPort != 3306 ? ":{$dbPort}" : '');
        $new = str_replace('mysqli_connect("localhost"', 'mysqli_connect("' . $dbHostStr . '"', $new);
        // ===== فیکس: DSN مجزا برای PDO =====
        $new = str_replace('mysql:host=localhost;', 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';', $new);
        if (file_put_contents($file, $new) === false) throw new Exception("خطا در نوشتن config.php میرزا");
    }

    /** رمزگشایی توکن ذخیره‌شده ربات فرزند (سازگار با توکن‌های ساده قدیمی) */
    public static function decryptChildToken(string $stored, string $key): string
    {
        if ($stored === '') return '';
        $data = base64_decode($stored, true);
        if ($data === false || strlen($data) <= 16) return $stored;
        $d = openssl_decrypt(substr($data, 16), 'AES-256-CBC', hash('sha256', $key), 0, substr($data, 0, 16));
        if (is_string($d) && preg_match('/^\d+:[\w\-]{20,}$/', $d)) return $d;
        return $stored;
    }

    /** اجرای table.php از طریق HTTP (ساخت جدول‌ها) — مثل نصب‌کننده‌های رسمی */
    public static function triggerTable(string $tableUrl): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false]]);
        $res = @file_get_contents($tableUrl, false, $ctx);
        // ===== فیکس: بررسی کد HTTP واقعی =====
        if ($res === false) return false;
        if (isset($http_response_header[0])) {
            if (preg_match('{HTTP/\S*\s+(\d+)}', $http_response_header[0], $m)) {
                $code = (int)$m[1];
                return $code >= 200 && $code < 300;
            }
        }
        return true;
    }

    /** اجرای table.php میرزا از طریق HTTP — نگهداشته برای سازگاری */
    public static function runMirzaTable(string $tableUrl): bool
    {
        return self::triggerTable($tableUrl);
    }

    /** محاسبه secret وبهوک فاکسیما (همان فرمول lib/WebhookAuth.php) */
    public static function faximaWebhookSecret(string $botToken): string
    {
        return $botToken === '' ? '' : hash('sha256', $botToken . '_faoxima_webhook_secret');
    }

    /**
     * پچ کردن config.php فاکسیما (جایگزینی مقدار ۸ متغیر — همان کاری که نصب‌کننده رسمی می‌کند).
     * $domainPath یعنی «دامنه/مسیر» بدون https، مثلا: example.com/botsaz-faxima/bots/shop1
     */
    public static function patchFaximaConfig(string $botDir, array $cfg, string $dbName, string $token, int $adminId, string $botUsername, string $domainPath): void
    {
        $file = $botDir . '/config.php';
        $raw = file_get_contents($file);
        if ($raw === false) throw new Exception("config.php فاکسیما پیدا نشد");
        // ===== فیکس: جدا کردن host و port =====
        $dbHost = $cfg['db_host'];
        $dbPort = $cfg['db_port'] ?? 3306;
        $dbHostStr = $dbHost . ($dbPort != 3306 ? ":{$dbPort}" : '');
        $values = [
            'dbname' => $dbName,
            'usernamedb' => $cfg['db_user'],
            'passworddb' => $cfg['db_pass'],
            'dbhost' => $dbHostStr,
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
