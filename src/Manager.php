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

    /**
     * نسخهٔ اپلیکیشن (ربات‌ساز) — هر آپدیت سورس باید این عدد را یکی زیاد کند.
     *
     * چرا لازم است: روی سرور، «کدِ روی دیسک» و «کدی که PHP-FPM اجرا می‌کند»
     * می‌توانند فرق داشته باشند (opcache با validate_timestamps=0، یا
     * --no-restart در update.sh، یا اجرای یک کپیِ دیگرِ پروژه مثل
     * /var/www/botsaz-faxima به‌جای /root/botsaz-faxima). آن‌وقت ادمین فکر
     * می‌کند «آپدیت کار نکرد» در حالی که اصلاً کد تازه اجرا نشده.
     *
     * این عدد سه جا دیده می‌شود و همین سه‌تایی مشکل را لو می‌دهد:
     *   • پاسخ JSON وبهوک (کلید "v") ⇒ install.sh آن را با نسخهٔ روی دیسک مقایسه می‌کند
     *   • پنل «🔍 دیاگنوز» و پیام /help ⇒ ادمین می‌بیند چه نسخه‌ای اجرا می‌شود
     *   • tools/install.sh --check ⇒ خطای «نسخهٔ کهنه در حال اجرا» می‌دهد
     */
    public const APP_VERSION = '1.1.0';

    /** نسخه + اطلاعات کاربردی برای نمایش در پنل‌ها */
    public static function versionLine(): string
    {
        return 'نسخهٔ ربات‌ساز: ' . self::APP_VERSION;
    }

    /**
     * نسخهٔ نوشته‌شده در فایل‌های روی دیسک — یعنی «کدی که باید اجرا شود».
     *
     * این با APP_VERSION فرق دارد وقتی یک کپیِ دیگرِ پروژه اجرا می‌شود
     * (مثلاً ‎/var/www/... به‌جای ‎/root/...) یا وقتی فایل‌ها دستی عوض شده‌اند.
     * خالی یعنی فایل ناخوانده/ناموجود است و نباید هشدار ساخت.
     */
    public static function diskVersion(string $rootDir): string
    {
        $file = rtrim(str_replace('\\', '/', $rootDir), '/') . '/src/Manager.php';
        if (!is_file($file)) return '';
        $raw = @file_get_contents($file);
        if (!is_string($raw)) return '';
        return preg_match('/APP_VERSION\s*=\s*\'([^\']+)\'/', $raw, $m) ? (string)$m[1] : '';
    }

    public static function secretKey(?array $cfg): string
    {
        $k = trim((string)($cfg['secret_key'] ?? ''));
        return $k !== '' ? $k : self::DEFAULT_SECRET_KEY;
    }

    /**
     * بررسی پیش‌نیازها قبل از ساخت ربات.
     *
     * $type = قالبی که همین حالا دارد ساخته می‌شود. null یعنی نامشخص، و آن
     * موقع همهٔ قالب‌های معتبر بررسی می‌شوند.
     *
     * این آرگومان برای همین اضافه شد: قبلاً یک چکِ یکسان روی هر دو قالب
     * می‌خورد، یعنی اگر vendor قالبِ میرزا پاک می‌شد، ساختِ فاکسیما هم بسته
     * می‌شد (موفقیتِ ناموجود)، و اگر با || می‌شد، رباتِ قالبِ ناقص با پیام
     * «موفقیت» ساخته می‌شد (موفقیتِ دروغین). ساختنِ چیزی که داری می‌سازی،
     * باید همان چیزی را بسنجد که داری می‌سازی.
     *
     * اگر مشکلی بود، پیام خطای دقیق برمی‌گردوند
     * اگر همه چیز OK بود، "" برمی‌گردوند
     */
    public static function checkBuildPrerequisites(?string $type = null): string
    {
        $root = dirname(__DIR__);
        // 1. بررسی پوشه bots/
        if (!is_dir($root . '/bots')) {
            return "پوشه bots/ وجود ندارد - کлон را دوباره اجرا کنید";
        }
        if (!is_writable($root . '/bots')) {
            $_perm = substr(sprintf('%o', @fileperms($root . '/bots')), -4);
            $_owner = (function_exists('posix_getpwuid') && ($info = @posix_getpwuid(@fileowner($root . '/bots'))) !== false) ? $info['name'] : @fileowner($root . '/bots');
            return "❌ پوشه bots/ قابل نوشتن نیست\n"
                . "پرمیشن: {$_perm}\n"
                . "مال: {$_owner}\n"
                . "حل: chown www-data:www-data bots/";
        }
        // 2. بررسی پوشه data/
        if (!is_dir($root . '/data/logs') && !@mkdir($root . '/data/logs', 0755, true)) {
            return "پوشه data/logs/ قابل ساخت نیست";
        }
        if (!is_writable($root . '/data')) {
            $_perm = substr(sprintf('%o', @fileperms($root . '/data')), -4);
            return "❌ پوشه data/ قابل نوشتن نیست ({$_perm})\n"
                . "حل: chown www-data:www-data data/";
        }
        // 3. بررسی config.php
        if (!is_file($root . '/config.php')) {
            return "config.php پیدا نشد - php tools/install.php را اجرا کنید";
        }
        // 4. بررسی PHP version
        if (PHP_VERSION_ID < 80200) {
            return "نسخه PHP " . PHP_VERSION . " کمتر از 8.2 لازم است";
        }
        // 5. بررسی vendor/ قالبی که دارد ساخته می‌شود
        //
        // اینجا قبلاً vendor/ ریشهٔ پروژه را می‌خواست، در حالی که ریشه نه
        // composer.json دارد نه vendor/ - و در .gitignore هم نیست، يعني هرگز
        // نداشته. پس اين چک همیشه می‌خورد و ساخت ربات اصلاً شروع نمی‌شد، و
        // پیشنهادِ خودِ پیام (composer install) هم بدون composer.json ناممکن
        // بود. vendor واقعی داخلِ خودِ قالب است و bot.php آن را با copyDir()
        // داخل bots/<slug>/ می‌برد - پس همان را می‌سنجیم.
        $types = ($type !== null && self::templateSpec($type) !== null)
            ? [$type]
            : array_keys(self::templates());
        foreach ($types as $_tpl) {
            $_spec = self::templateSpec($_tpl);
            $_dir = self::templateDir($_tpl);
            if (!is_dir($_dir)) {
                return "قالب «{$_tpl}» روی سرور نیست ({$_dir})"
                    . "\nحل: git pull کنید";
            }
            $_need = (string)($_spec['autoload'] ?? 'vendor/autoload.php');
            if (!is_file($_dir . '/' . $_need)) {
                return "قالب «{$_tpl}» ناقص است ({$_dir}/{$_need} پیدا نشد)"
                    . "\nحل: git pull کنید - نه composer install"
                    . "\n(ریشهٔ پروژه اصلاً composer.json ندارد، پس آن کار نمی‌کند)";
            }
            // قالب SQLite به MySQL نیاز ندارد؛ برعکس، قالب MySQL بدون سرور MySQL بی‌معنی است
            if (self::templateDb($_tpl) === 'sqlite') {
                if (!self::hasPdoSqlite()) {
                    return "قالب «{$_tpl}» روی SQLite کار می‌کند ولی افزونهٔ pdo_sqlite روی PHP سرور فعال نیست";
                }
            } elseif (!self::hasPdoMysql()) {
                return "قالب «{$_tpl}» به MySQL نیاز دارد ولی افزونهٔ pdo_mysql روی PHP سرور فعال نیست";
            }
        }
        return ""; // همه چیز OK
    }

    public static function hasPdoSqlite(): bool
    {
        return class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    public static function hasPdoMysql(): bool
    {
        return class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);
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
            if ($f->isDir()) @mkdir($target, 0755, true);
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
        $spec = self::templateSpec($type);
        // نام پوشه ممکن است با کلید رجیستری فرق کند (مثل قالب‌های تازه که پوشه‌شان
        // نام دیگری دارد). مسیر واقعی فقط از رجیستری خوانده می‌شود تا این‌جا و در
        // کپی‌کردن، دو جای جدا از هم واقعیت را تعریف نکنند.
        $dir = is_array($spec) ? (string)($spec['dir'] ?? '') : '';
        if ($dir === '') $dir = $type;
        // مهار مسیر: نه «..» و نه جداکننده. بدون این، یک کلید بدخواهانه مثل
        // «../../..» می‌توانست copyDir را روی هر پوشه‌ای از سرور اجرا کند.
        if ($dir === '' || str_contains($dir, '..') || str_contains($dir, '/') || str_contains($dir, '\\') || str_contains($dir, ':')) {
            $dir = preg_replace('/[^A-Za-z0-9._-]+/', '-', $type) ?? 'faxima';
            // «.» برای نام‌هایی مثل uptime-bot-telegram لازم است، ولی «..» هرگز
            $dir = str_replace('..', '', $dir);
            $dir = trim($dir, '-.');
            if ($dir === '') $dir = 'faxima';
        }
        return dirname(__DIR__) . '/templates/' . $dir;
    }

    /**
     * ===== رجیستری قالب‌ها — تنها منبع حقیقت نصب خودکار =====
     *
     * هر قالب فقط یک ورودی این آرایه است؛ بقیهٔ سیستم (منوی انتخاب نوع،
     * پیش‌نیازها، مسیر وبهوک، رمزها، پاکسازی فایل‌ها) از همین می‌خواند.
     * قبلاً نصب با زنجیرهٔ if/else در buildBot بود، یعنی هر قالب تازه یعنی
     * دستکاری در ۶ جای پروژه و یک «else» که همه‌چیز را به فاکسیما می‌فرستاد.
     *
     * کلیدها:
     *   label      عنوان نمایشی در منو
     *   icon       ایموجی منو
     *   db         'mysql' | 'sqlite'
     *   entry      فایل ورودی وبهوک (نسبت به پوشهٔ ربات)
     *   autoload   فایلی که وجودش برای اجرای قالب لازم است
     *   secret     فرمول رمز وبهوک: 'faxima'|'uptime'|'static'
     *   table      نام فایل نصب جدول‌ها یا null اگر ندارد
     *   tableSecret فرمول رمز table.php یا null
     *   schema     روش نصب جدول‌ها: 'http' (table.php) | 'migrate' (درون‌فرایندی) | 'none'
     *   cron       فایل کرون (فقط برای نمایش/گزارش؛ دیسپچر خودش cron/*.php را اجرا می‌کند)
     *   exclude    مسیرهایی که موقع کپی نباید بروند (مستندات و ابزار نصب)
     *   generated   مسیرهایی که خودِ نصب می‌سازد (مثل دیتابیس SQLite) — برای بازبینی
     *   required   فایل‌هایی که نبودشان یعنی قالب ناقص است (dry-run و پیش‌نیازها)
     */
    public static function templates(): array
    {
        return [
            'faxima' => [
                'label' => 'فاکسیما (فروش VPN)',
                'icon' => '✨',
                'db' => 'mysql',
                'entry' => 'index.php',
                'autoload' => 'vendor/autoload.php',
                'secret' => 'faxima',
                'table' => 'table.php',
                'tableSecret' => 'faxima',
                'schema' => 'http',
                'cron' => 'cron/cron.php',
                'required' => ['index.php', 'config.php', 'table.php', 'botapi.php', 'function.php', 'lib/WebhookAuth.php'],
                'exclude' => ['docker/', 'docker-compose.yml', '.env.example', 'vpnbot/', 'install.sh', 'images.jpeg', 'composer.json', 'composer.lock', 'installer/'],
                'cleanup' => ['installer/'],
            ],
            'mirza' => [
                'label' => 'میرزا (فروش VPN)',
                'icon' => '🌙',
                'db' => 'mysql',
                'entry' => 'index.php',
                'autoload' => 'vendor/autoload.php',
                'secret' => '',
                'table' => 'table.php',
                'tableSecret' => 'mirza',
                'schema' => 'http',
                'cron' => 'cron/cron.php',
                'required' => ['index.php', 'config.php', 'table.php', 'botapi.php', 'functions.php'],
                'exclude' => ['docker/', 'docker-compose.yml', '.env.example', 'vpnbot/', 'install.sh', 'images.jpeg', 'composer.json', 'composer.lock', 'installer/'],
                'cleanup' => ['installer/'],
            ],
            'uptime' => [
                // نام پوشهٔ سورس با کلید رجیستری فرق دارد
                'dir' => 'uptime-bot-telegram',
                'label' => 'آپ‌تایم (پایش سایت)',
                'icon' => '📡',
                'db' => 'mysql',
                'entry' => 'index.php',
                'autoload' => 'vendor/autoload.php',
                'secret' => 'uptime',
                'table' => 'table.php',
                'tableSecret' => 'uptime',
                'schema' => 'http',
                'cron' => 'cron/checker.php',
                'required' => ['index.php', 'config.php', 'table.php', 'api.php', 'botapi.php', 'status.php', 'cron/checker.php'],
                // install.sh برای سرور خام لینوکس است؛ روی هاست اشتراکی و از داخل
                // ربات‌ساز قابل اجرا نیست و فقط کپی‌کردنش بی‌معنی است.
                // logs/ هم لاگ و قفل دورهٔ توسعه را دارد (۱+ مگابایت) که هرگز نباید
                // به ربات جدید کپی شود؛ .htaccess خودش هم با FilesMatch بسته می‌شود
                // و bots/.htaccess کل پوشهٔ logs/ را می‌بندد.
                'exclude' => ['install.sh', 'SOLUTION_SUMMARY.md', 'tests/', 'logs/', 'SOURCE.md'],
                'cleanup' => ['install.sh', 'install.log'],
            ],
            'pasargad' => [
                // پوشهٔ سورس این قالب نام فارسی/انگلیسی با فاصله دارد، پس کلید رجیستری
                // با نام پوشه فرق می‌کند؛ مسیر واقعی را خودِ رجیستری اعلام می‌کند.
                'dir' => "Pasargad Representatives' Bot Telegram",
                'label' => 'نمایندگان پاسارگاد',
                'icon' => '🏦',
                'db' => 'sqlite',
                'entry' => 'bot.php',
                // این قالب composer ندارد؛ خودش autoloader دستی دارد
                // (src/Support/Autoloader.php که bootstrap.php صدایش می‌زند).
                // پس «vendor/autoload.php» فاکتور وابستگی درستی نیست و وجودش را
                // نباید معیار در دسترس بودن قالب گذاشت.
                'autoload' => 'bootstrap.php',
                'secret' => 'static',
                'table' => null,
                'tableSecret' => null,
                'schema' => 'migrate',
                'cron' => 'cron/worker.php',
                'required' => ['bot.php', 'bootstrap.php', 'nowpayments_ipn.php', 'cron/worker.php', 'config.example.php', 'src/Support/Migrator.php'],
                // vendor/ و composer فقط ابزار توسعه‌اند (phpstan) و .gitignore خودِ
                // قالب هم آن‌ها را commit نمی‌کند؛ کپی‌شان فقط حجم بوت را زیاد می‌کند.
                // data/bot.sqlite دیتابیس توسعهٔ خودِ مخزن است — کپی‌شدنش یعنی هر
                // ربات تازه با دیتابیس یک نفر دیگر بالا می‌آید. قفل‌ها و لاگ‌ها هم
                // همین‌طور: data/logs باید بماند ولی خالی، نه پر از لاگ توسعه.
                'exclude' => [
                    'tests/', 'vendor/', 'composer.json', 'composer.lock', 'phpstan.neon', 'SOURCE.md',
                    'data/bot.sqlite', 'data/.migrate.lock', 'data/worker.lock', 'data/logs/', 'data/receipts/', 'data/cache/',
                ],
                'cleanup' => ['config.example.php'],
                // فایل‌هایی که خودِ نصب (مایگریشن) می‌سازد. وجودشان بعد از نصب
                // درست است، ولی اگر «کپی» شده باشند یعنی runtime مخزن کپی شده —
                // و اگر نباشند یعنی نصب ناقص بوده. tools/dryrun.php هر دو را می‌سنجد.
                'generated' => ['data/bot.sqlite', 'data/.migrate.lock', 'data/logs/'],
            ],
        ];
    }

    /** یک قالب یا null اگر نامعتبر بود */
    public static function templateSpec(string $type): ?array
    {
        $all = self::templates();
        $spec = $all[$type] ?? null;
        return is_array($spec) ? $spec + ['key' => $type] : null;
    }

    /**
     * مسیرهایی که موقع کپی قالب ⇒ ربات نباید بروند.
     *
     * تنها منبع حقیقت؛ هم نصب (buildBot) و هم بروزرسانی سورسِ ربات‌های
     * موجود (SourceUpdate) از همین می‌خوانند. دو فهرست جدا یعنی «چیزی که
     * موقع ساخت کپی نشد ولی موقع آپدیت شد» — دقیقاً همان چیزی که بعداً
     * کسی نمی‌فهمد چرا فایلی در ربات هست و در قالب نیست.
     */
    public static function copyExcludes(string $type): array
    {
        $spec = self::templateSpec($type);
        // متادیتای گیت هیچ قالبی نباید بیاورد (هم ریپو را سنگین می‌کند هم
        // .gitignore خود قالب می‌تواند جلوی کپی فایل‌های لازم را بگیرد)
        return array_merge(
            (array)($spec['exclude'] ?? []),
            ['.git/', '.gitignore', '.gitattributes', '.github/']
        );
    }

    /** دیتابیس این قالب mysql است یا sqlite */
    public static function templateDb(string $type): string
    {
        $spec = self::templateSpec($type);
        return ($spec['db'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
    }

    /**
     * روش ساخت جدول‌های این قالب: 'http' | 'migrate' | 'none'.
     *
     * 'http' یعنی با فراخوانی به table.php (قالب‌های MySQL)، 'migrate' یعنی با
     * مایگریشن درون‌فرایندی (قالب SQLite که table.php ندارد).
     *
     * اگر کلید schema نبود، از وجود table.php استنتاج می‌شود تا قالبی که
     * کلید جدید را ندارد (یا یک قالب تازهٔ دستی اضافه‌شده) هم درست کار کند.
     */
    public static function templateSchema(string $type): string
    {
        $spec = self::templateSpec($type);
        if ($spec === null) return 'none';
        $schema = (string)($spec['schema'] ?? '');
        if ($schema !== '') return $schema;
        return ((string)($spec['table'] ?? '')) !== '' ? 'http' : 'none';
    }

    /** نام نمایشی قالب (fallback به خود کلید) */
    public static function templateLabel(string $type): string
    {
        $spec = self::templateSpec($type);
        return (string)($spec['label'] ?? $type);
    }

    /** قالب‌هایی که روی سرور واقعاً نصب‌اند — منوی انتخاب نوع نباید گزینهٔ مرده نشان دهد */
    public static function availableTypes(): array
    {
        $out = [];
        foreach (self::templates() as $key => $spec) {
            if (is_dir(self::templateDir($key)) && is_file(self::templateDir($key) . '/' . ($spec['autoload'] ?? ''))) {
                $out[$key] = (string)$spec['label'];
            }
        }
        return $out;
    }

    /**
 * نگاشت «کلید ⇒ برچسب» همهٔ قالب‌ها.
 *
 * نکتهٔ ریز ولی مهم: array_keys() روی آرایهٔ رشته‌ای، کلیدهای **عددی** برمی‌گرداند
 * و array_map هم با یک آرایه، کلید عددی را دور می‌ریزد. با
 * `array_map(fn($t) => ..., array_keys(...))` خروجی `0=>'فاکسیما', 1=>'میرزا'`
 * می‌شد و همهٔ جاهایی که با کلید کار می‌کنند (`isPaid($store, $type)`،
 * `pay:buy:template:<type>`) بی‌صدا هیچ قالبی را نمی‌دیدند — یعنی فروشگاه بدون
 * دکمهٔ خرید قالب. اینجا حلقهٔ صریح است تا کلیدها حتماً حفظ شوند.
 */
public static function validTypes(): array
    {
        $out = [];
        foreach (self::templates() as $key => $spec) {
            $out[$key] = (string)$spec['label'];
        }
        return $out;
    }

    /**
     * حداقل نسخهٔ PHP که قالب به آن نیاز دارد — از روی vendor/composer/platform_check.php خودِ قالب.
     *
     * قالب‌ها vendor آماده و کامپایل‌شده دارند؛ Composer داخل آن فایل دقیقاً همان نسخه‌ای را
     * ثبت می‌کند که وابستگی‌ها به آن نیاز دارند (الان: فاکسیما ≥ 8.2، میرزا ≥ 8.1).
     * وقتی سرور پایین‌تر باشد، همان platform_check با trigger_error همهٔ endpoint های
     * ربات فرزند (index.php و table.php) را 500 می‌کند — یعنی رباتی که «موفقیت‌آمیز» ساخته
     * شده عملاً مرده است. مقدار null یعنی الزامی ثبت نشده و چیزی را نمی‌توان سنجید.
     */
    public static function templateMinPhp(string $type): ?int
    {
        $file = self::templateDir($type) . '/vendor/composer/platform_check.php';
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        if (preg_match('/PHP_VERSION_ID\s*>=\s*(\d{5,6})/', $raw, $m)) return (int)$m[1];
        return null;
    }

    /** نمایش مقدار PHP_VERSION_ID به شکل 8.2.0 */
    public static function formatPhpVersionId(int $id): string
    {
        return intdiv($id, 10000) . '.' . intdiv($id % 10000, 100) . '.' . ($id % 100);
    }

    /**
     * پیش از ساخت ربات — اگر PHP سرور به حداقل نیاز قالب نرسد، ساخت را متوقف می‌کند.
     * عمداً قبل از mkdir/دیتابیس است: هیچ منبعی ایجاد نمی‌شود و rollback لازم نیست.
     */
    public static function assertTemplatePhpCompatible(string $type): void
    {
        $min = self::templateMinPhp($type);
        if ($min === null || PHP_VERSION_ID >= $min) return;
        $need = self::formatPhpVersionId($min);
        throw new Exception(
            "قالب «{$type}» به PHP {$need} یا بالاتر نیاز دارد ولی سرور شما PHP " . PHP_VERSION . " است. "
            . "در این حالت همهٔ صفحه‌های ربات ساخته‌شده (index.php و table.php) خطای 500 می‌دهند و ربات کار نمی‌کند؛ "
            . "برای همین ساخت متوقف شد تا ربات خراب تحویل داده نشود. نسخهٔ PHP را به {$need} یا بالاتر ارتقا دهید."
        );
    }

    /** فایل ورودی وبهوک هر قالب — از رجیستری خوانده می‌شود */
    public static function entryFile(string $type): string
    {
        $spec = self::templateSpec($type);
        $f = (string)($spec['entry'] ?? 'index.php');
        return preg_match('/^[a-z0-9_.-]+\.php$/i', $f) ? $f : 'index.php';
    }

    /**
     * secret وبهوک یک قالب.
     *
     * هر قالب فرمول خودش را دارد و اینجا نگاشت می‌شود:
     *   faxima → sha256(token . '_faoxima_webhook_secret')  (دقیقاً همان فرمول lib/WebhookAuth.php)
     *   uptime → sha256(token . '_uptime_webhook_secret')
     *   mirza  → بدون secret
     *   static → مقدار تصادفیِ خودِ نصب‌کننده (پاسارگاد) که در config.php قالب می‌نشیند
     */
    public static function webhookSecret(string $type, string $botToken, string $static = ''): string
    {
        $spec = self::templateSpec($type);
        $formula = (string)($spec['secret'] ?? '');
        if ($botToken === '') return '';
        if ($formula === 'faxima') return self::faximaWebhookSecret($botToken);
        if ($formula === 'uptime') return hash('sha256', $botToken . '_uptime_webhook_secret');
        if ($formula === 'static') return $static;
        return ''; // mirza و هر قالب بی‌secret
    }

    /** آدرس وبهوک ربات فرزند */
    public static function webhookUrl(array $cfg, string $folder, string $type, string $secret = ''): string
    {
        $url = rtrim($cfg['base_url'], '/') . '/bots/' . $folder . '/' . self::entryFile($type);
        if ($secret !== '') $url .= '?secret=' . $secret;
        return $url;
    }

    /**
     * بازسازی secret وبهوک یک رباتِ از قبل ساخته‌شده، فقط از روی رکورد دیتابیس.
     *
     * لازم است چون «ست‌کردن مجدد وبهوک» (دکمهٔ 🔗 در پنل، یا فعال/غیرفعال‌کردن)
     * بعداً اتفاق می‌افتد و باید همان secret قبلی را بفرستد.
     *
     * برای قالب‌های با secret محاسباتی (فاکسیما/آپ‌تایم) از توکن ساخته می‌شود.
     * اما قالب‌هایی که secret تصادفی دارند (پاسارگاد) رمزشان نه از توکن درمی‌آید و نه
     * جایی جدا ذخیره شده — تنها جایی که می‌ماند همان `webhook_url` است که موقع ساخت
     * با `?secret=` ذخیره شده. بدون این بازیابی، هر بار فعال/غیرفعال‌کردن باعث می‌شد
     * ربات پاسارگاد با ۴۰۳ بمیرد (bot.php آن را ۴۰۳ می‌کند وقتی secret نخواند).
     *
     * @return string رشتهٔ خالی یعنی این قالب secret ندارد (میرزا)
     */
    public static function resolveWebhookSecret(array $bot): string
    {
        $type = (string)($bot['type'] ?? '');
        $token = '';
        if (function_exists('childToken')) $token = childToken($bot);
        $spec = self::templateSpec($type);
        $formula = is_array($spec) ? (string)($spec['secret'] ?? '') : '';

        // اول سعی کن از آدرس ذخیره‌شده بازیابی کن — برای 'static' تنها راه است
        $stored = (string)($bot['webhook_url'] ?? '');
        $qPos = strpos($stored, '?secret=');
        if ($qPos !== false) {
            $fromUrl = substr($stored, $qPos + 8);
            $amp = strpos($fromUrl, '&');
            if ($amp !== false) $fromUrl = substr($fromUrl, 0, $amp);
            $fromUrl = rawurldecode($fromUrl);
            // رشتهٔ تصادفی تلگرام (base64url) است و با فرمول محاسباتی اشتباه نمی‌شود
            if ($fromUrl !== '' && $formula === 'static') return $fromUrl;
        }
        return self::webhookSecret($type, $token);
    }

    /** آدرس وبهوک یک رباتِ از قبل ثبت‌شده (secret هم بازسازی می‌شود) */
    public static function webhookUrlForBot(array $cfg, array $bot): string
    {
        $type = (string)($bot['type'] ?? '');
        $folder = (string)($bot['folder'] ?? '');
        $secret = self::resolveWebhookSecret($bot);
        return self::webhookUrl($cfg, $folder, $type, $secret);
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

    /**
     * secret دسترسی به table.php هر قالب (از رجیستری خوانده می‌شود).
     * قالبی که table.php ندارد یا رمزش تصادفی است، رشتهٔ خالی می‌دهد.
     */
    public static function tableSecret(string $type, string $botToken): string
    {
        $spec = self::templateSpec($type);
        $formula = is_array($spec) ? (string)($spec['tableSecret'] ?? '') : '';
        if ($botToken === '' || $formula === '') return '';
        if ($formula === 'faxima') return self::faximaTableSecret($botToken);
        if ($formula === 'mirza') return self::mirzaTableSecret($botToken);
        if ($formula === 'uptime') return hash('sha256', $botToken . '_uptime_table_secret');
        return '';
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

    /**
     * پچ کردن config.php قالب آپ‌تایم.
     *
     * قالبش یک فایل `return [...]` با جای‌گذارهای {…} است (نه متغیرهای $var مثل فاکسیما)،
     * پس جای‌گزینی سادهٔ رشته‌ای درست است. دقت می‌کنیم که حتی یک جای‌گذار باقی نماند،
     * وگرنه ربات با توکن «{BOT_TOKEN}» بالا می‌آید و هیچ پیامی نمی‌گیرد.
     */
    public static function patchUptimeConfig(
        string $botDir,
        array $cfg,
        string $dbName,
        string $token,
        int $adminId,
        string $botUsername,
        string $domainPath,
        string $baseUrl
    ): void {
        $file = $botDir . '/config.php';
        $raw = is_file($file) ? file_get_contents($file) : null;
        if ($raw === false || $raw === null) throw new Exception("config.php آپ‌تایم پیدا نشد");
        $new = str_replace(
            ['{BOT_TOKEN}', '{ADMIN_#ID}', '{BOT_USERNAME}', '{DOMAIN.COM/PATH/BOT}', '{BASE_URL}',
                '{DB_HOST}', '{DB_PORT}', '{DATABASE_NAME}', '{DATABASE_USERNAME}', '{DATABASE_PASSWORD}'],
            [
                self::phpEscape($token),
                self::phpEscape((string)$adminId),
                self::phpEscape($botUsername),
                self::phpEscape($domainPath),
                self::phpEscape($baseUrl),
                self::phpEscape((string)($cfg['db_host'] ?? 'localhost')),
                self::phpEscape((string)($cfg['db_port'] ?? 3306)),
                self::phpEscape($dbName),
                self::phpEscape((string)($cfg['db_user'] ?? '')),
                self::phpEscape((string)($cfg['db_pass'] ?? '')),
            ],
            $raw
        );
        if ($new === $raw) throw new Exception("هیچ جای‌گذاری در config.php آپ‌تایم پیدا نشد (نسخه ناسازگار؟)");
        if (preg_match('/\{[A-Z_#.\/]+\}/', $new, $m)) {
            throw new Exception("جای‌گذار جا‌مانده در config.php آپ‌تایم: " . $m[0]);
        }
        if (file_put_contents($file, $new) === false) throw new Exception("خطا در نوشتن config.php آپ‌تایم");
    }

    /**
     * مقدار را برای نشستن داخل رشتهٔ تک‌کوتیشنی PHP آماده می‌کند.
     *
     * توجه: خودش کوتیشن اضافه نمی‌کند، چون قالب‌ها همین حالا جای‌گذارها را
     * داخل `'…'` نوشته‌اند (`'bot_token' => '{BOT_TOKEN}'`). اگر کوتیشن اضافه
     * می‌کردیم نتیجه `''{BOT_TOKEN}''` می‌شد و کانفیگ parse نمی‌شد — یعنی رباتی
     * که «موفقیت‌آمیز» ساخته شده و ۵۰۰ می‌دهد.
     *
     * کاراکترهای کنترلی حذف می‌شوند: نه در نام دیتابیس معتبرند و نه در هدر HTTP.
     */
    private static function phpEscape(string $v): string
    {
        // حذف \0 و کنترل‌ها + نرمال‌سازی خط‌شکن‌ها (کوتیشن/بک‌اسلش بعدش escape می‌شود)
        $v = str_replace(["\r\n", "\r", "\n", "\0"], ' ', $v);
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? $v;
        return addcslashes($v, "'\\");
    }

    /**
     * ساخت جدول‌های قالب، از مسیر رجیستری.
     *
     * buildBot این را صدا می‌زند (نه مستقیم installPasargadSchema) تا افزودن یک
     * قالب تازهٔ درون‌فرایندی یعنی یک ورودی در templates() و یک شاخهٔ کوتاه اینجا،
     * نه دستکاری دوبارهٔ بدنهٔ ساخت ربات.
     *
     * @return array{migrated:bool, seeded:bool, note:string}
     */
    public static function installTemplateSchema(string $type, string $botDir): array
    {
        switch (self::templateSchema($type)) {
            case 'migrate':
                if ($type === 'pasargad') return self::installPasargadSchema($botDir);
                // قالب SQLite تازه: همین روش (bootstrap + مایگریشن) به کار می‌آید
                if (self::templateDb($type) === 'sqlite') return self::installPasargadSchema($botDir);
                return ['migrated' => false, 'seeded' => false, 'note' => "مایگریشن درون‌فرایندی برای «{$type}» پیاده‌سازی نشده"];
            case 'http':
                return ['migrated' => true, 'seeded' => false, 'note' => 'جدول‌ها با table.php ساخته می‌شوند'];
            default:
                return ['migrated' => true, 'seeded' => false, 'note' => 'این قالب جدولی نمی‌سازد'];
        }
    }

    /**
     * نصب دیتابیس قالب پاسارگاد (SQLite).
     *
     * چرا درون‌فرایندی و نه اجرای `php tools/cli.php migrate`؟
     *  چون هاست اشتراکی خیلی جاها exec/popen/shell_exec را در disable_functions
     *  می‌گذارد؛ آن‌وقت نصبِ ربات در نیمه‌راه می‌شکست. مایگریشن فقط SQL است
     *  (کلاس‌های خود قالب namespaced هستند و با کلاس‌های ما تداخل ندارند)، پس
     *  مستقیم صدا زدنش هم امن است هم همیشه کار می‌کند.
     *
     * seed (بسته‌های پیش‌فرض فروشگاه) اختیاری است و از پنل خودِ ربات هم ساخته
     * می‌شود، پس اگر exec نبود فقط هشدار می‌دهیم و ساخت را متوقف نمی‌کنیم.
     *
     * @return array{migrated:bool, seeded:bool, note:string}
     */
    public static function installPasargadSchema(string $botDir): array
    {
        $out = ['migrated' => false, 'seeded' => false, 'note' => ''];
        $boot = $botDir . '/bootstrap.php';
        if (!is_file($boot)) {
            $out['note'] = 'bootstrap.php پیدا نشد';
            return $out;
        }
        // کلاس‌های قالب namespaced هستند؛ این include برای خود ربات‌ساز بی‌اثر است.
        require_once $boot;
        if (!class_exists('\Pasargad\Support\Db') || !class_exists('\Pasargad\Support\Migrator')) {
            $out['note'] = 'کلاس‌های مایگریشن قالب بارگذاری نشد';
            return $out;
        }
        try {
            $ran = (new \Pasargad\Support\Migrator(\Pasargad\Support\Db::instance()))->migrate();
            $out['migrated'] = true;
            $out['note'] = $ran === [] ? 'دیتابیس به‌روز بود' : ('مایگریشن: ' . implode(', ', $ran));
        } catch (Throwable $e) {
            $out['note'] = 'مایگریشن ناموفق: ' . $e->getMessage();
            return $out;
        } finally {
            // اتصال SQLite باید همین‌جا رها شود.
            //
            // دو دلیل، و دومی جدی‌تر است:
            //  ۱) قالب در حالت WAL کار می‌کند؛ تا وقتی PDO باز است فایل‌های
            //     ‎-wal/-shm کنار دیتابیس می‌مانند و هر کپی/حذف بعدی را خراب می‌کنند.
            //  ۲) اگر مرحلهٔ بعدیِ ساخت شکست بخورد، rollback باید کل پوشه را پاک کند؛
            //     روی ویندوز/هاست‌هایی که فایل قفل‌شده را حذف نمی‌کنند، پوشه می‌ماند
            //     و ربات نیمه‌ساخته از راه در رفته ولی در دیتابیس هم ثبت نشده.
            self::releasePasargadDb();
        }

        // seed اختیاری — فقط وقتی runner سیستمی موجود باشد
        $seed = $botDir . '/tools/seed.php';
        if (is_file($seed) && self::runnerAvailable() !== null) {
            $runner = self::runnerAvailable();
            $php = self::phpBinary();
            $cmd = '"' . $php . '" "' . $seed . '"';
            try {
                if ($runner === 'shell_exec') {
                    @shell_exec($cmd . ' 2>&1');
                    $out['seeded'] = true;
                } elseif ($runner === 'exec') {
                    $o = []; $rc = 0;
                    @exec($cmd . ' 2>&1', $o, $rc);
                    $out['seeded'] = ($rc === 0);
                } else {
                    $h = @popen($cmd . ' 2>&1', 'r');
                    if (is_resource($h)) { @stream_get_contents($h); $rc = @pclose($h); $out['seeded'] = ($rc === 0); }
                }
            } catch (Throwable $e) {
                $out['seeded'] = false;
            }
            if (!$out['seeded']) {
                $out['note'] .= ' | بسته‌های پیش‌فرض ساخته نشد (از پنل خود ربات بسازید)';
            }
        } elseif (is_file($seed)) {
            $out['note'] .= ' | exec غیرفعال است؛ بسته‌ها را از پنل خود ربات بسازید';
        }
        return $out;
    }

    /**
     * رها کردن اتصال SQLite قالب پاسارگاد.
     *
     * `Pasargad\Support\Db` یک singleton است و هیچ متد close ندارد، پس تنها راه
     * بستنش از بیرون، صفر کردن همان static است (تنها مرجع قوی به PDO همان‌جاست).
     * با reflection چون `$instance` خصوصی است.
     */
    private static function releasePasargadDb(): void
    {
        try {
            $ref = new ReflectionClass('\Pasargad\Support\Db');
            if (!$ref->hasProperty('instance')) return;
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            if ($prop->isStatic()) $prop->setValue(null, null);
        } catch (Throwable $e) {
            // کلاس رابطه‌ای عوض کرد یا نسخهٔ دیگری است — بی‌خیال، فقط اتصال می‌ماند
            error_log('releasePasargadDb: ' . $e->getMessage());
        }
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
    }

    /** اولین تابع اجرای پروسهٔ موجود در این هاست (exec > popen > shell_exec) یا null */
    public static function runnerAvailable(): ?string
    {
        foreach (['exec', 'popen', 'shell_exec'] as $fn) {
            if (function_exists($fn)) return $fn;
        }
        return null;
    }

    /** باینری PHP برای اجرای اسکریپت‌های CLI فرزند */
    public static function phpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '') return PHP_BINARY;
        return 'php';
    }

    /**
     * ساخت config.php قالب پاسارگاد از روی config.example.php.
     *
     * دو کار می‌کند که نصب‌کنندهٔ دستی هم لازم دارد و خیلی‌ها فراموشش می‌کنند:
     *   ۱) کپی نمونه ⇒ config.php
     *   ۲) ساخت webhook_secret و crypto_key تصادفی.
     * بدون این دو، ربات ۴۰۳ می‌دهد و رمزهای پنل کاربران رمزنگاری‌نشده ذخیره می‌شوند.
     *
     * @return array{secret:string, crypto:string}
     */
    public static function writePasargadConfig(
        string $botDir,
        string $token,
        int $adminId,
        string $botUsername,
        string $baseUrl
    ): array {
        $example = $botDir . '/config.example.php';
        $target = $botDir . '/config.php';
        if (!is_file($example)) throw new Exception("config.example.php پاسارگاد پیدا نشد");

        $secret = self::randomToken(40);
        $crypto = self::randomToken(48);
        $raw = file_get_contents($example);
        if ($raw === false) throw new Exception("خواندن config.example.php پاسارگاد نشد");

        // super_admins یک آرایهٔ PHP است، نه رشته — پس با الگوی خودش عوض می‌شود
        $new = preg_replace(
            "/('bot_token'\s*=>\s*)'[^']*'/",
            "$1'" . addcslashes($token, "'\\") . "'",
            $raw,
            1,
            $c1
        );
        $new = preg_replace(
            "/('webhook_secret'\s*=>\s*)'[^']*'/",
            "$1'" . addcslashes($secret, "'\\") . "'",
            (string)$new,
            1,
            $c2
        );
        $new = preg_replace(
            "/('crypto_key'\s*=>\s*)'[^']*'/",
            "$1'" . addcslashes($crypto, "'\\") . "'",
            (string)$new,
            1,
            $c3
        );
        $new = preg_replace(
            "/('bot_username'\s*=>\s*)'[^']*'/",
            "$1'" . addcslashes($botUsername, "'\\") . "'",
            (string)$new,
            1,
            $c4
        );
        $new = preg_replace(
            "/('base_url'\s*=>\s*)'[^']*'/",
            "$1'" . addcslashes(rtrim($baseUrl, '/'), "'\\") . "'",
            (string)$new,
            1,
            $c5
        );
        $new = preg_replace(
            "/('super_admins'\s*=>\s*)\[[^\]]*\]/",
            '$1[' . $adminId . ']',
            (string)$new,
            1,
            $c6
        );
        // لاگ پنل داخل data/logs است؛ کپی شده و قابل نوشتن است، ولی صریح می‌سازیم
        if (!is_dir($botDir . '/data/logs')) @mkdir($botDir . '/data/logs', 0755, true);

        if ((int)$c1 < 1 || (int)$c2 < 1 || (int)$c3 < 1 || (int)$c5 < 1 || (int)$c6 < 1) {
            throw new Exception("پچ config پاسارگاد ناقص ماند — نسخه ناسازگار؟");
        }
        if (file_put_contents($target, (string)$new) === false) {
            throw new Exception("خطا در نوشتن config.php پاسارگاد");
        }
        return ['secret' => $secret, 'crypto' => $crypto];
    }

    /**
     * رشتهٔ تصادفیِ امن برای secret (فقط حروف مجاز تلگرام: A-Z a-z 0-9 _ -).
     * چون secret_token تلگرام همین محدودیت را دارد، base64 (که + و / دارد) مناسب نیست.
     */
    public static function randomToken(int $len = 40): string
    {
        $len = max(8, min(256, $len));
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
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
