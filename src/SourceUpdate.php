<?php
// ===== بروزرسانی سورس ربات‌های فرزند =====
//
// ربات‌های فرزند «کپی» قالب‌ها از لحظهٔ ساخت‌اند. وقتی خودِ قالب در
// templates/<قالب> به‌روز می‌شود (git pull یا tools/update.sh)، کد ربات‌های
// فرزند همان نسخهٔ قدیمی می‌ماند. این ماژول آن دو را هم‌تراز می‌کند:
//
//   plan()   ⇒ می‌گوید چه فایل‌هایی تازه/تغییرکرده‌اند (هیچ تغییری نمی‌دهد)
//   backup() ⇒ آرشیو کامل پوشهٔ ربات (config.php و دیتابیس هم داخلش هست)
//   apply()  ⇒ اول بکاپ، بعد فقط کپی فایل‌های سورس
//
// ===== چرا config.php و دیتابیس هرگز دست نمی‌خورند =====
//
// این فایل ماژول «کپی از قالب به ربات» را انجام می‌دهد و قالب‌ها هم
// config.php خالی/placeholder دارند؛ یعنی اگر config.php ربات فرزند
// بازنویسی شود، توکن و نام دیتابیس‌اش پاک می‌شود و ربات برای همیشه می‌میرد.
// دیتابیس SQLite هم داخل پوشهٔ خودِ ربات است (data/bot.sqlite) و کپی‌شدنِ
// یک SQLite خالی روی فایل واقعی، یعنی پاک‌شدن کل اطلاعات ربات. پس هر دو
// در PROTECTED* می‌مانند و حتی اگر اشتباهی به فهرست کپی برسند، apply()
// دوباره قبل از نوشتن چکشان می‌کند.

class SourceUpdate
{
    public const MANIFEST_VERSION = 1;
    /** زیرِ سقف ۵۰MB تلگرام؛ بزرگ‌تر فقط روی سرور می‌ماند */
    public const MAX_SEND_BYTES = 45 * 1024 * 1024;
    /** چند بکاپ برای هر ربات روی سرور نگه داشته شود */
    public const KEEP_BACKUPS = 3;

    /** پوشه‌های سطح اول که کامل مال ربات‌اند و کپی نباید داخلشان برود */
    private const PROTECTED_TOP_DIRS = ['data', 'logs', 'backups', 'states', 'node_modules'];
    /** نام فایل‌هایی که در هر عمقی محافظت می‌شوند */
    private const PROTECTED_NAMES = ['config.php', '.htaccess', '.env', 'error_log'];
    /** پسوندهای داده/لاگ/قفل — در هر عمقی */
    private const PROTECTED_EXT = ['sqlite', 'sqlite3', 'db', 'sql', 'bak', 'log', 'lock', 'pid'];
    /** پسوندهای دوتایی SQLite (WAL کنار فایل اصلی می‌ماند) */
    private const PROTECTED_SUFFIX = ['.sqlite-wal', '.sqlite-shm', '.sqlite-journal'];

    /** @var array<string, array<string, array{size:int, sha:string}>> کش مانیفست قالب‌ها در هر درخواست */
    private static array $tplCache = [];
    /** @var array<string, array> کش plan هر ربات در هر درخواست */
    private static array $planCache = [];
    /** @var array|null کش state.json در همان درخواست (منو چند بار state می‌خواند) */
    private static ?array $stateCache = null;
    /** عمر کش شمارندهٔ منو */
    public const COUNTER_TTL = 600;

    // =================================================================
    // مسیرها
    // =================================================================

    /** پوشهٔ ربات فرزند — نام نامعتبر (مسیر/..) اصلاً پذیرفته نمی‌شود */
    public static function botDir(string $folder): string
    {
        $folder = trim($folder);
        if ($folder === '' || str_contains($folder, '..')
            || !preg_match('/^[A-Za-z0-9._-]+$/', $folder)) {
            throw new Exception("نام پوشهٔ ربات نامعتبر است: " . $folder);
        }
        return Manager::childBotsDir() . '/' . $folder;
    }

    public static function dataDir(): string
    {
        return dirname(__DIR__) . '/data';
    }

    /** مانیفست هر ربات بیرون از پوشهٔ ربات نگه داشته می‌شود:
     *  هم از وب در دسترس نیست، هم موقع بکاپ/حذف ربات قاطی سورس نمی‌شود. */
    public static function manifestFile(string $folder): string
    {
        return self::dataDir() . '/manifests/' . self::safeName($folder) . '.json';
    }

    public static function stateFile(): string
    {
        return self::dataDir() . '/source_update.json';
    }

    public static function backupsDir(): string
    {
        return self::dataDir() . '/backups/source';
    }

    private static function safeName(string $folder): string
    {
        $f = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($folder)) ?? '_';
        return $f === '' || str_contains($f, '..') ? '_' : $f;
    }

    // =================================================================
    // حفاظت از فایل‌های حساس
    // =================================================================

    /** این مسیر (نسبت به ریشهٔ ربات) هرگز نباید بازنویسی/حذف شود */
    public static function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') return true;
        $top = explode('/', $rel)[0];
        if (in_array($top, self::PROTECTED_TOP_DIRS, true)) return true;
        $base = basename($rel);
        if (in_array($base, self::PROTECTED_NAMES, true)) return true;
        $lower = strtolower($rel);
        foreach (self::PROTECTED_SUFFIX as $sfx) {
            if (str_ends_with($lower, $sfx)) return true;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::PROTECTED_EXT, true);
    }

    // =================================================================
    // پیمایش و هش
    // =================================================================

    /**
     * فهرست فایل‌های یک پوشه به‌صورت «مسیر نسبی ⇒ مسیر کامل».
     * لینک‌های نمادین عمداً رد می‌شوند (حلقهٔ بی‌نهایه و خروج از پوشهٔ ربات).
     */
    public static function walk(string $dir, array $exclude = []): array
    {
        $out = [];
        if (!is_dir($dir)) return $out;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if ($f->isLink()) continue;
            $rel = str_replace('\\', '/', $it->getSubPathName());
            if ($f->isDir()) {
                foreach ($exclude as $ex) {
                    $norm = rtrim(str_replace('\\', '/', (string)$ex), '/');
                    if ($norm === '') continue;
                    if ($rel === $norm || str_starts_with($rel, $norm . '/')) continue 2;
                }
                continue;
            }
            foreach ($exclude as $ex) {
                $norm = rtrim(str_replace('\\', '/', (string)$ex), '/');
                if ($norm === '') continue;
                if ($rel === $ex || $rel === $norm
                    || str_starts_with($rel, $ex . '/') || str_starts_with($rel, $norm . '/')) {
                    continue 2;
                }
            }
            $out[$rel] = $f->getPathname();
        }
        ksort($out);
        return $out;
    }

    public static function hashFile(string $file): string
    {
        $sha = @sha1_file($file);
        if (is_string($sha) && $sha !== '') return $sha;
        $raw = @file_get_contents($file);
        return is_string($raw) ? md5($raw) : '';
    }

    // =================================================================
    // مانیفست قالب (کش‌شده)
    // =================================================================

    /**
     * فهرست فایل‌های سورسِ قالب با اندازه و هش — «نسخهٔ فعلیِ» سورس.
     * یک‌بار برای همهٔ ربات‌های هم‌نوع محاسبه و در همان درخواست کش می‌شود.
     *
     * @return array<string, array{size:int, sha:string}>
     */
    public static function templateFiles(string $type): array
    {
        if (isset(self::$tplCache[$type])) return self::$tplCache[$type];
        $out = [];
        $dir = Manager::templateDir($type);
        if (is_dir($dir)) {
            // فایل‌های «کپی‌شونده ولی تحویل‌نشده» (کلید cleanup در spec) عمداً کنار گذاشته
            // می‌شوند: آنها بعد از نصب پاک می‌شوند، پس اگر اینجا شمرده شوند هر بار
            // «۱ فایل در انتظار» می‌ماند و هیچ‌وقت به «همه‌چیز به‌روز» نمی‌رسیدیم
            // (کپی‌شدن و بلافاصله حذف‌شدنِ همان یک فایل در هر اجرا).
            $clean = self::cleanupSet($type);
            foreach (self::walk($dir, Manager::copyExcludes($type)) as $rel => $abs) {
                if (self::isProtected($rel)) continue;
                if (self::isCleanupPath($rel, $clean)) continue;
                $out[$rel] = ['size' => (int)@filesize($abs), 'sha' => self::hashFile($abs)];
            }
        }
        self::$tplCache[$type] = $out;
        return $out;
    }

    /**
     * فهرستِ مسیرهای کلید `cleanup` در spec قالب، به‌صورت مجموعهٔ نام → true.
     * («cleanup» یعنی کپی لازم بود ولی نباید به ربات تحویل شود.)
     *
     * @return array<string,bool>
     */
    private static function cleanupSet(string $type): array
    {
        try {
            $spec = Manager::templateSpec($type);
        } catch (Throwable $e) {
            $spec = null;
        }
        $out = [];
        foreach ((array)(is_array($spec) ? ($spec['cleanup'] ?? []) : []) as $p) {
            $p = rtrim(trim((string)$p), '/');
            if ($p !== '') $out[$p] = true;
        }
        return $out;
    }

    /** آیا مسیرِ نسبی، خودِ فایل/پوشهٔ cleanup است یا زیرِ آن پوشه می‌رود؟ */
    private static function isCleanupPath(string $rel, array $set): bool
    {
        if ($set === []) return false;
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (isset($set[$rel])) return true;
        foreach (array_keys($set) as $p) {
            if ($p !== '' && strpos($rel, $p . '/') === 0) return true;
        }
        return false;
    }

    /** امضای کلی سورس قالب — تغییرش یعنی «نسخهٔ تازه آمده» */
    public static function templateSignature(string $type): string
    {
        $parts = [];
        foreach (self::templateFiles($type) as $rel => $m) {
            $parts[] = $rel . ':' . $m['sha'];
        }
        return substr(sha1(implode('|', $parts)), 0, 16);
    }

    /** هش config.php خودِ قالب — برای تشخیص اینکه قالب نسخهٔ تازه‌ای آورده */
    public static function templateConfigSha(string $type): string
    {
        $f = Manager::templateDir($type) . '/config.php';
        return is_file($f) ? self::hashFile($f) : '';
    }

    /** خلاصهٔ یک فهرست فایل (اندازهٔ کل + تعداد) */
    public static function summarize(array $files): array
    {
        $bytes = 0;
        foreach ($files as $m) $bytes += (int)($m['size'] ?? 0);
        return ['count' => count($files), 'bytes' => $bytes];
    }

    // =================================================================
    // مانیفست ربات
    // =================================================================

    public static function readManifest(string $folder): array
    {
        $f = self::manifestFile($folder);
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    /**
     * ثبت «چه چیزی از قالب کپی شده» — مبنای گزارش فایل‌های حذف‌شده در
     * نسخهٔ تازه و مبنای فهمیدن اینکه قالب کلاً عوض شده یا نه.
     *
     * @param array|null $files فقط همین فایل‌ها ثبت شوند (وقتی بخشی از کپی شکست خورد)
     * @return int تعداد فایل ثبت‌شده
     */
    public static function saveManifest(string $type, string $folder, ?array $files = null): int
    {
        $tpl = self::templateFiles($type);
        $list = [];
        foreach (($files === null ? $tpl : $files) as $rel => $m) {
            if (!is_string($rel) || self::isProtected($rel)) continue;
            $meta = $tpl[$rel] ?? $m;
            if (!is_array($meta)) continue;
            $list[$rel] = ['size' => (int)($meta['size'] ?? 0), 'sha' => (string)($meta['sha'] ?? '')];
        }
        ksort($list);
        $payload = [
            'version'   => self::MANIFEST_VERSION,
            'type'      => $type,
            'folder'    => $folder,
            'at'        => date('Y-m-d H:i:s'),
            'signature' => self::templateSignature($type),
            'config_sha'=> self::templateConfigSha($type),
            'files'     => $list,
        ];
        $file = self::manifestFile($folder);
        @mkdir(dirname($file), 0755, true);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return 0;
        if (@file_put_contents($file, $json, LOCK_EX) === false) {
            Logger::getInstance()->warning('source', "cannot write manifest for {$folder}");
            return 0;
        }
        return count($list);
    }

    /** وضعیت آخرین بروزرسانی هر ربات */
    public static function readState(): array
    {
        if (self::$stateCache !== null) return self::$stateCache;
        $f = self::stateFile();
        if (!is_file($f)) return self::$stateCache = [];
        $j = json_decode((string)@file_get_contents($f), true);
        return self::$stateCache = is_array($j) ? $j : [];
    }

    public static function writeState(array $state): void
    {
        $f = self::stateFile();
        @mkdir(dirname($f), 0755, true);
        @file_put_contents($f, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        self::$stateCache = $state;
    }

    public static function lastUpdate(string $folder): array
    {
        $s = self::readState();
        return is_array($s[$folder] ?? null) ? $s[$folder] : [];
    }

    /**
     * تعداد ربات‌هایی که سورسشان از قالب عقب است — برای شمارندهٔ منوی اصلی.
     *
     * چرا کش: این عدد روی «منوی اصلی» حساب می‌شود و منو تقریباً در هر پیام
     * ساخته می‌شود، حالا مقایسهٔ کامل یعنی هزاران stat برای هر پیام. پس نتیجه
     * چند دقیقه کش می‌شود؛ «🔄 تازه‌سازی فهرست» در پنل کش را دور می‌ریزد و
     * خودِ بروزرسانی هم بعد از اتمام.
     *
     * و چرا مسیر ارزان: اول امضای قالب با امضای مانیفست ربات مقایسه می‌شود
     * (یک خواندن فایل کوچک + یک مقایسهٔ رشته). رباتی که مانیفستش با قالب
     * هم‌نسخه است، در شمارنده «به‌روز» حساب می‌شود و برایش هزاران stat نمی‌زنیم.
     * اگر ادمین خودش فایلی را داخل ربات دست‌کاری کرده باشد، شمارنده ممکن است
     * صفر بماند ولی خودِ پنل (که همیشه plan() واقعی می‌زند) آن را نشان می‌دهد.
     */
    public static function counter(array $bots, bool $force = false): int
    {
        $state = self::readState();
        $cached = $state['_outdated'] ?? null;
        if (!$force && is_array($cached)
            && (int)($cached['at'] ?? 0) > time() - self::COUNTER_TTL) {
            return (int)($cached['n'] ?? 0);
        }
        $n = 0;
        foreach ($bots as $bot) {
            $type = (string)($bot['type'] ?? '');
            $folder = (string)($bot['folder'] ?? '');
            $man = self::readManifest($folder);
            if ($man !== [] && (string)($man['signature'] ?? '') !== ''
                && (string)$man['signature'] === self::templateSignature($type)) {
                continue;
            }
            $plan = self::plan($type, $folder);
            if ($plan['ok'] && !empty($plan['out_of_date'])) $n++;
        }
        $state['_outdated'] = ['at' => time(), 'n' => $n];
        self::writeState($state);
        return $n;
    }

    public static function clearCounter(): void
    {
        $state = self::readState();
        if (!array_key_exists('_outdated', $state)) return;
        unset($state['_outdated']);
        self::writeState($state);
    }

    // =================================================================
    // برنامه‌ریزی (بدون هیچ تغییری)
    // =================================================================

    /**
     * مقایسهٔ سورسِ قالب با پوشهٔ ربات.
     *
     * خروجی:
     *   ok            آیا اصلاً قابل بررسی بود
     *   error         علتِ «نه» (قالب/پوشه پیدا نشد و…)
     *   new/changed   فایل‌هایی که باید کپی شوند
     *   obsolete      فایل‌هایی که در نسخهٔ تازهٔ قالب حذف شده‌اند (پاک نمی‌شوند)
     *   skipped       فایل‌های محافظت‌شده که عمداً دست نمی‌خورند
     *   config_new    نسخهٔ تازهٔ قالب config.php را هم عوض کرده (هشدار)
     *   out_of_date   چیزی برای کار هست یا نه
     */
    public static function plan(string $type, string $folder): array
    {
        if (isset(self::$planCache[$type . '|' . $folder])) {
            return self::$planCache[$type . '|' . $folder];
        }
        $p = [
            'ok' => false, 'error' => '', 'type' => $type, 'folder' => $folder,
            'template' => '', 'label' => Manager::templateLabel($type),
            'new' => [], 'changed' => [], 'obsolete' => [], 'skipped' => [],
            'counts' => ['new' => 0, 'changed' => 0, 'same' => 0, 'obsolete' => 0, 'skipped' => 0, 'tracked' => 0],
            'config_new' => false, 'out_of_date' => false, 'has_manifest' => false,
        ];

        $spec = Manager::templateSpec($type);
        if ($spec === null) {
            $p['error'] = "قالب «{$type}» در رجیستری نیست";
            return self::$planCache[$type . '|' . $folder] = $p;
        }
        $tplDir = Manager::templateDir($type);
        if (!is_dir($tplDir)) {
            $p['error'] = "پوشهٔ سورس قالب روی سرور نیست ({$tplDir})";
            return self::$planCache[$type . '|' . $folder] = $p;
        }
        try {
            $botDir = self::botDir($folder);
        } catch (Throwable $e) {
            $p['error'] = $e->getMessage();
            return self::$planCache[$type . '|' . $folder] = $p;
        }
        if (!is_dir($botDir)) {
            $p['error'] = "پوشهٔ ربات روی سرور پیدا نشد (bots/{$folder})";
            return self::$planCache[$type . '|' . $folder] = $p;
        }

        $tpl = self::templateFiles($type);
        if ($tpl === []) {
            $p['error'] = 'سورس قالب خالی است (یا همه‌اش exclude شده)';
            return self::$planCache[$type . '|' . $folder] = $p;
        }

        $same = 0;
        foreach ($tpl as $rel => $meta) {
            if (self::isProtected($rel)) { $p['skipped'][] = $rel; continue; }
            $abs = $botDir . '/' . $rel;
            if (!is_file($abs)) { $p['new'][] = $rel; continue; }
            // اول اندازه: اگر فرق دارد هرگز لازم نیست هش گرفته شود
            if ((int)@filesize($abs) !== (int)$meta['size']) { $p['changed'][] = $rel; continue; }
            if (self::hashFile($abs) === $meta['sha']) { $same++; continue; }
            $p['changed'][] = $rel;
        }

        // فایل‌هایی که قبلاً از قالب آمده‌اند ولی در نسخهٔ تازه نیستند ⇒ گزارش، نه حذف
        $man = self::readManifest($folder);
        $p['has_manifest'] = ($man !== []);
        if ($man !== [] && is_array($man['files'] ?? null)) {
            foreach (array_keys($man['files']) as $rel) {
                if (isset($tpl[$rel]) || self::isProtected((string)$rel)) continue;
                $p['obsolete'][] = (string)$rel;
            }
            $p['config_new'] = ((string)($man['config_sha'] ?? '') !== self::templateConfigSha($type))
                && trim((string)($man['config_sha'] ?? '')) !== '';
        }

        sort($p['new']);
        sort($p['changed']);
        sort($p['obsolete']);
        $p['template'] = (string)($man['signature'] ?? '');
        $p['counts'] = [
            'new' => count($p['new']),
            'changed' => count($p['changed']),
            'same' => $same,
            'obsolete' => count($p['obsolete']),
            'skipped' => count($p['skipped']),
            'tracked' => count($tpl),
        ];
        $p['out_of_date'] = ($p['new'] !== [] || $p['changed'] !== []);
        $p['ok'] = true;
        return self::$planCache[$type . '|' . $folder] = $p;
    }

    /** پاک‌کردن کش plan — بعد از هر اعمال‌کردن لازم است */
    public static function forget(string $type, string $folder): void
    {
        unset(self::$planCache[$type . '|' . $folder]);
    }

    /** نمای کوتاه وضعیت یک ربات برای پنل */
    public static function statusLine(array $bot): array
    {
        $type = (string)($bot['type'] ?? '');
        $folder = (string)($bot['folder'] ?? '');
        $plan = self::plan($type, $folder);
        if (!$plan['ok']) {
            return ['ok' => false, 'icon' => '🔴', 'text' => $plan['error'], 'pending' => 0];
        }
        $pending = (int)$plan['counts']['new'] + (int)$plan['counts']['changed'];
        if ($pending === 0) {
            $last = (string)(self::lastUpdate($folder)['at'] ?? '');
            return ['ok' => true, 'icon' => '🟢', 'pending' => 0,
                'text' => $last !== '' ? 'به‌روز' : 'به‌روز (بدو سابقهٔ ثبت‌شده)'];
        }
        $parts = [];
        if ((int)$plan['counts']['changed'] > 0) $parts[] = $plan['counts']['changed'] . ' تغییر';
        if ((int)$plan['counts']['new'] > 0) $parts[] = $plan['counts']['new'] . ' تازه';
        return ['ok' => true, 'icon' => '🟡', 'pending' => $pending, 'text' => implode('، ', $parts)];
    }

    // =================================================================
    // بکاپ کامل
    // =================================================================

    /**
     * آرشیو کامل پوشهٔ ربات — شامل config.php و دیتابیس SQLite و همه‌چیز.
     * عمداً چیزی حذف نمی‌شود: هدف «برگشت کامل» است نه بکاپ سبک.
     *
     * @return array{ok:bool, error:string, file?:string, size?:int, files?:int, method?:string}
     */
    public static function backup(string $folder): array
    {
        try {
            $dir = self::botDir($folder);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!is_dir($dir)) return ['ok' => false, 'error' => "پوشهٔ ربات پیدا نشد (bots/{$folder})"];

        $outDir = self::backupsDir();
        if (!@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            return ['ok' => false, 'error' => "پوشهٔ بکاپ ساخته نشد: {$outDir}"];
        }
        $res = self::backupDir($dir, $outDir . '/' . $folder . '_' . date('Y-m-d_H-i-s'));
        if (!empty($res['ok'])) {
            try { self::pruneBackups($folder); } catch (Throwable $e) { /* بی‌اهمیت */ }
        }
        return $res;
    }

    /**
     * آرشیو گرفتن از یک پوشهٔ دلخواه. $destPrefix بدون پسوند داده می‌شود و
     * پسوند واقعی را تعیین می‌کند (zip اگر ext-zip باشد، وگرنه tar.gz).
     * $method فقط برای تست است تا مسیر tar.gz هم روی هاستی که ext-zip دارد سنجیده شود.
     *
     * @param string|null $note متنِ داخلِ آرشیو (پیش‌فرض: یادداشتِ بکاپِ ربات)
     *
     * @return array{ok:bool, error:string, file?:string, size?:int, files?:int, method?:string}
     */
    public static function backupDir(string $dir, string $destPrefix, ?string $method = null, ?string $note = null): array
    {
        if (!is_dir($dir)) return ['ok' => false, 'error' => "پوشه پیدا نشد: {$dir}"];
        $files = self::walk($dir);
        $count = count($files);
        if ($note === null) {
            $note = "botsaz source-update backup\nfolder: " . basename(rtrim($dir, '/\\')) . "\n"
                . "date: " . date('Y-m-d H:i:s') . "\nfiles: {$count}\n\n"
                . "این آرشیو شامل config.php و دیتابیس ربات است؛ آن را محرمانه نگه دارید.\n";
        }
        $useZip = ($method === 'tar.gz') ? false : ($method === 'zip' ? true : class_exists('ZipArchive'));
        $res = $useZip
            ? self::backupZip($dir, $files, $destPrefix . '.zip', $note)
            : self::backupTarGz($dir, $files, $destPrefix . '.tar.gz', $note);
        if (!empty($res['ok'])) $res['files'] = $count;
        return $res;
    }

    /**
     * فهرست نام فایل‌های داخل آرشیو (برای بازبینی/بازگردانی).
     * مسیرها با «/» نرمال می‌شوند و نام پوشهٔ ربات هم جلوی هر ورودی می‌آید.
     */
    public static function listArchive(string $file): array
    {
        if (!is_file($file)) return [];
        if (class_exists('ZipArchive') && strtolower((string)pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($file) !== true) return [];
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (is_string($n)) $names[] = str_replace('\\', '/', $n);
            }
            $zip->close();
            return $names;
        }
        $raw = self::tarRead((string)@file_get_contents($file));
        return array_map(static fn(array $e): string => $e['name'], $raw);
    }

    /** محتوای یک فایل داخل آرشیو، یا null اگر نبود */
    public static function readArchiveEntry(string $file, string $entry): ?string
    {
        if (!is_file($file)) return null;
        $entry = str_replace('\\', '/', $entry);
        if (class_exists('ZipArchive') && strtolower((string)pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($file) !== true) return null;
            $body = $zip->getFromName($entry);
            $zip->close();
            return is_string($body) ? $body : null;
        }
        foreach (self::tarRead((string)@file_get_contents($file)) as $e) {
            if ($e['name'] === $entry) return $e['body'];
        }
        return null;
    }

    private static function backupZip(string $dir, array $files, string $dest, string $note): array
    {
        $zip = new ZipArchive();
        if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => "ساخت فایل zip ممکن نشد", 'method' => 'zip'];
        }
        $zip->addFromString('_botsaz_backup.txt', $note);
        $base = strlen(rtrim($dir, '/\\')) + 1;
        $root = basename(rtrim($dir, '/\\'));
        foreach ($files as $rel => $abs) {
            // نام‌ها داخل آرشیو با پوشهٔ ربات شروع می‌شوند تا unzip چندرباتی قاطی نشود
            $zip->addFile($abs, $root . '/' . $rel);
        }
        unset($base);
        if (!$zip->close()) {
            @unlink($dest);
            return ['ok' => false, 'error' => "بستن فایل zip ممکن نشد", 'method' => 'zip'];
        }
        if (!is_file($dest)) return ['ok' => false, 'error' => "فایل بکاپ ساخته نشد", 'method' => 'zip'];
        return ['ok' => true, 'file' => $dest, 'size' => (int)@filesize($dest), 'method' => 'zip'];
    }

    /**
     * جایگزین ext-zip: آرشیو tar فشرده با PHP خالص.
     * zlib در این پروژه از قبل لازم است (DbBackup::dumpGzip پشتیبانی gzip می‌گیرد)،
     * پس این مسیر روی هر هاستی که ربات کار می‌کند در دسترس است.
     */
    private static function backupTarGz(string $dir, array $files, string $dest, string $note): array
    {
        $raw = self::tarBuild($dir, $files, $note);
        if ($raw === null) return ['ok' => false, 'error' => "ساخت tar ممکن نشد", 'method' => 'tar.gz'];
        $gz = gzencode($raw, 6);
        if (!is_string($gz) || $gz === '') return ['ok' => false, 'error' => "فشرده‌سازی gzip ممکن نشد", 'method' => 'tar.gz'];
        if (@file_put_contents($dest, $gz) === false) return ['ok' => false, 'error' => "نوشتن فایل بکاپ ممکن نشد", 'method' => 'tar.gz'];
        return ['ok' => true, 'file' => $dest, 'size' => (int)@filesize($dest), 'method' => 'tar.gz'];
    }

    /** نوشتن آرشیو tar (بدون وابستگی به Phar) — هدر ۵۱۲ بایتی استاندارد */
    private static function tarBuild(string $dir, array $files, string $note): ?string
    {
        $root = basename(rtrim($dir, '/\\'));
        $out = '';
        $addNote = static function (string $name, string $body) use (&$out): void {
            $out .= self::tarHeader($name, strlen($body), '0');
            $out .= $body;
            $pad = (512 - (strlen($body) % 512)) % 512;
            if ($pad > 0) $out .= str_repeat("\0", $pad);
        };
        $addNote($root . '/_botsaz_backup.txt', $note);
        foreach ($files as $rel => $abs) {
            $body = @file_get_contents($abs);
            if (!is_string($body)) continue; // فایل خوانده نشد ⇒ بی‌صدا رد شود (بکاپ ناقص بهتر از کرش)
            $out .= self::tarHeader($root . '/' . $rel, strlen($body), '0');
            $out .= $body;
            $pad = (512 - (strlen($body) % 512)) % 512;
            if ($pad > 0) $out .= str_repeat("\0", $pad);
        }
        return $out . str_repeat("\0", 1024); // دو بلوک پایانی
    }

    /**
     * خواندن آرشیو tar (یا tar.gz اگر بدنه gzip باشد) — فقط برای بازبینی و
     * بازگردانی تک‌فایلی؛ unzip/untar دستی هم همیشه در دسترس است.
     * @return array<int, array{name:string, body:string}>
     */
    private static function tarRead(string $raw): array
    {
        if (str_starts_with($raw, "\x1f\x8b")) {
            $plain = @gzdecode($raw);
            if (!is_string($plain)) return [];
            $raw = $plain;
        }
        $out = [];
        $len = strlen($raw);
        $pos = 0;
        while ($pos + 512 <= $len) {
            $header = substr($raw, $pos, 512);
            $pos += 512;
            $name = rtrim(substr($header, 0, 100), "\0");
            if ($name === '') break;                 // بلوک پایانی
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') $name = $prefix . '/' . $name;
            $sizeField = trim(substr($header, 124, 12), "\0 ");
            $size = $sizeField === '' ? 0 : (int)octdec($sizeField);
            $body = substr($raw, $pos, $size);
            $pos += (int)(ceil($size / 512) * 512);
            $type = substr($header, 156, 1);
            if ($type === '0' || $type === "\0" || $type === '') {
                $out[] = ['name' => str_replace('\\', '/', $name), 'body' => $body];
            }
        }
        return $out;
    }

    /**
     * استخراجِ آرشیوِ tar خام (خروجیِ `git archive --format=tar`) به یک پوشه.
     * چرا PHP خالص: بعضی هاست‌ها tar سیستم‌عامل ندارند یا روی آرشیو خراب
     * می‌کند («Cannot open: Function not implemented»)، پس نباید به آن تکیه کرد.
     *
     * @return int تعداد فایل نوشته‌شده
     */
    public static function untar(string $raw, string $dest): int
    {
        $n = 0;
        foreach (self::tarRead($raw) as $e) {
            $rel = ltrim(str_replace('\\', '/', $e['name']), '/');
            if ($rel === '' || $rel === 'pax_global_header' || basename($rel) === 'pax_global_header') continue;
            // مهار مسیر: هیچ فایلی بیرون از مقصد نوشته نشود
            if (str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel)) continue;
            $abs = rtrim(str_replace('\\', '/', $dest), '/') . '/' . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) continue;
            if (@file_put_contents($abs, $e['body']) !== false) {
                @chmod($abs, 0644);
                $n++;
            }
        }
        return $n;
    }

    /**
     * هدر استاندارد ustar (۵۱۲ بایت) — همان چیزی که `tar` روی لینوکس و
     * Windows می‌خواند. جمعِ بخش‌ها دقیقاً ۵۱۲ است وگرنه آرشیو در ابزارهای
     * واقعی باز نمی‌شود (و فقط خوانندهٔ خودمان آن را «درست» می‌دید).
     */
    private static function tarHeader(string $name, int $size, string $type): string
    {
        $name = str_replace('\\', '/', $name);
        $prefix = '';
        if (strlen($name) > 100) {
            // نام‌های بلند (مسیرهای تو در تو) در بخش prefix هدر جا می‌شوند
            $cut = strrpos(substr($name, 0, 155), '/');
            if ($cut !== false && $cut < 100) {
                $prefix = substr($name, 0, $cut);
                $name = substr($name, $cut + 1);
            } else {
                $name = substr($name, 0, 100);
            }
        }
        $h = pack('a100', $name)
            . pack('a8', sprintf('%07o', 0644))      // mode
            . pack('a8', sprintf('%07o', 0))          // uid
            . pack('a8', sprintf('%07o', 0))          // gid
            . pack('a12', sprintf('%011o', $size))    // size
            . pack('a12', sprintf('%011o', time()))   // mtime
            . '        '                              // checksum placeholder (۸ فاصله)
            . $type                                   // typeflag
            . pack('a100', '')                        // linkname
            . "ustar\0" . '00'                        // magic + version
            . pack('a32', '')                         // uname
            . pack('a32', '')                         // gname
            . pack('a8', '')                          // devmajor
            . pack('a8', '')                          // devminor
            . pack('a155', $prefix)                   // prefix (نام‌های بلند)
            . pack('a12', '')                         // padding تا ۵۱۲ بایت
            ;
        $sum = 0;
        for ($i = 0; $i < 512; $i++) $sum += ord($h[$i]);
        return substr_replace($h, pack('a8', sprintf('%06o', $sum) . "\0 "), 148, 8);
    }

    /** فقط چند بکاپ آخر هر ربات روی سرور بماند */
    public static function pruneBackups(string $folder, int $keep = self::KEEP_BACKUPS): int
    {
        $dir = self::backupsDir();
        $items = glob($dir . '/' . self::safeName($folder) . '_*') ?: [];
        if (count($items) <= $keep) return 0;
        rsort($items); // نام‌ها تاریخ دارند ⇒ جدیدترین اول
        $removed = 0;
        foreach (array_slice($items, $keep) as $old) {
            if (@unlink($old)) $removed++;
        }
        return $removed;
    }

    public static function fmtSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    // =================================================================
    // اجرا
    // =================================================================

    /**
     * اعمال بروزرسانی سورس روی یک ربات.
     *
     * ترتیب کار ثابت است و قابل کم‌کردن نیست:
     *   ۱) بکاپ کامل ⇒ شکستش یعنی هیچ فایلی دست نمی‌خورد
     *   ۲) کپی فایل‌های تازه/تغییرکرده (موقت + rename ⇒ فایل نیمه‌کاره نمی‌ماند)
     *   ۳) lint روی فایل‌های php و برگشتِ هر فایلی که خطای نحوی دارد
     *   ۴) پاکسازی فایل‌های اضافی (همان cleanup نصب)
     *   ۵) ثبت مانیفست تازه + وضعیت
     *
     * config.php و دیتابیس در هیچ‌کدام از این مراحل لمس نمی‌شوند.
     *
     * @return array{ok:bool, error:string, applied:int, failed:int, reverted:int,
     *               files:string[], backup:array, notes:string[], skipped:int, obsolete:int,
     *               config_new:bool, elapsed_ms:int}
     */
    public static function apply(array $plan, ?int $byUid = null): array
    {
        $log = Logger::getInstance();
        $out = [
            'ok' => false, 'error' => '', 'applied' => 0, 'failed' => 0, 'reverted' => 0,
            'files' => [], 'backup' => [], 'notes' => [], 'skipped' => 0, 'obsolete' => 0,
            'config_new' => false, 'elapsed_ms' => 0,
        ];
        $started = microtime(true);
        $type = (string)($plan['type'] ?? '');
        $folder = (string)($plan['folder'] ?? '');
        if ($type === '' || $folder === '') {
            $out['error'] = 'برنامهٔ بروزرسانی نامعتبر است';
            return $out;
        }
        $spec = Manager::templateSpec($type);
        if ($spec === null) { $out['error'] = "قالب «{$type}» نامعتبر است"; return $out; }

        try {
            $tplDir = Manager::templateDir($type);
            $botDir = self::botDir($folder);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
        if (!is_dir($tplDir)) { $out['error'] = "پوشهٔ سورس قالب پیدا نشد"; return $out; }
        if (!is_dir($botDir)) { $out['error'] = "پوشهٔ ربات پیدا نشد (bots/{$folder})"; return $out; }

        // برنامه باید تازه باشد: ممکن است بین «دیدن پنل» و «زدن دکمه» فایلی عوض شده باشد
        self::forget($type, $folder);
        $fresh = self::plan($type, $folder);
        if (!$fresh['ok']) { $out['error'] = $fresh['error']; return $out; }
        if (!$fresh['out_of_date']) {
            self::forget($type, $folder);
            $out['ok'] = true;
            $out['notes'][] = 'ربات از قبل به‌روز بود — چیزی برای کپی نبود.';
            $out['elapsed_ms'] = (int)((microtime(true) - $started) * 1000);
            return $out;
        }
        $plan = $fresh;
        $out['skipped'] = (int)$plan['counts']['skipped'];
        $out['obsolete'] = (int)$plan['counts']['obsolete'];
        $out['config_new'] = (bool)$plan['config_new'];

        // ---------- ۱) بکاپ کامل — شرط ادامهٔ کار ----------
        $backup = self::backup($folder);
        if (empty($backup['ok'])) {
            $out['error'] = "بکاپ ساخته نشد، بروزرسانی انجام نشد: " . (string)($backup['error'] ?? '?');
            $log->error('source', "update aborted for {$folder}: backup failed — " . (string)($backup['error'] ?? '?'));
            return $out;
        }
        $out['backup'] = $backup;
        $log->info('source', "backup before update of {$folder}: " . basename((string)$backup['file'])
            . ' (' . self::fmtSize((int)$backup['size']) . ', ' . $backup['method'] . ')');

        // نسخهٔ قبلی فایل‌های php که قرار است بازنویسی شوند — برای برگشت در صورت خطای نحوی
        $lint = self::canLint($type);
        $tmpPrev = self::dataDir() . '/backup_tmp/' . $folder . '_' . date('Ymd_His');
        if ($lint) @mkdir($tmpPrev, 0755, true);

        $targets = array_merge($plan['new'], $plan['changed']);
        $done = [];
        try {
            foreach ($targets as $rel) {
                // گارد دوم: هرچند plan() رعایت می‌کند، قبل از نوشتن دوباره چک می‌شود
                if (self::isProtected($rel)) {
                    $out['notes'][] = "رد شد (محافظت‌شده): {$rel}";
                    continue;
                }
                $src = $tplDir . '/' . $rel;
                $dst = $botDir . '/' . $rel;
                if (!is_file($src)) { $out['failed']++; continue; }
                if ($lint && is_file($dst) && self::isPhp($rel)) {
                    $keepDir = $tmpPrev . '/' . dirname($rel);
                    if (!is_dir($keepDir)) @mkdir($keepDir, 0755, true);
                    @copy($dst, $keepDir . '/' . basename($rel));
                }
                if (!self::copyAtomic($src, $dst)) {
                    $out['failed']++;
                    $log->warning('source', "{$folder}: copy failed for {$rel}");
                    continue;
                }
                $done[$rel] = true;
                $out['applied']++;
            }

            // ---------- ۳) lint و برگشتِ فایل خراب ----------
            if ($lint) {
                foreach (array_keys($done) as $rel) {
                    if (!self::isPhp($rel)) continue;
                    $abs = $botDir . '/' . $rel;
                    if (self::lintPhp($abs)) continue;
                    $prev = $tmpPrev . '/' . $rel;
                    if (is_file($prev) && @copy($prev, $abs)) {
                        $out['reverted']++;
                        $out['applied'] = max(0, $out['applied'] - 1);
                        $out['notes'][] = "خطای نحوی ⇒ نسخهٔ قبلی برگردانده شد: {$rel}";
                        $log->error('source', "{$folder}: lint failed, reverted {$rel}");
                    } else {
                        $out['notes'][] = "⚠️ خطای نحوی و نسخهٔ قبلی هم نبود: {$rel}";
                        $log->error('source', "{$folder}: lint failed and no previous copy for {$rel}");
                    }
                }
            }

            // ---------- ۴) پاکسازی فایل‌های اضافی (مثل نصب) ----------
            try {
                Manager::cleanupExtraFiles($botDir, (array)($spec['cleanup'] ?? []));
            } catch (Throwable $e) {
                $out['notes'][] = 'پاکسازی فایل‌های اضافی کامل نشد: ' . $e->getMessage();
            }

            // ---------- ۵) مانیفست + وضعیت ----------
            // فایل‌هایی که کپی نشدند از مانیفست بیرون می‌مانند تا دفعهٔ بعد دوباره دیده شوند
            $recorded = [];
            foreach (self::templateFiles($type) as $rel => $m) {
                if (self::isProtected($rel)) continue;
                if (!isset($done[$rel]) && in_array($rel, $targets, true)) continue;
                $recorded[$rel] = $m;
            }
            self::saveManifest($type, $folder, $recorded);
            $out['files'] = array_keys($done);
            $state = self::readState();
            $state[$folder] = [
                'at'        => date('Y-m-d H:i'),
                'by'        => $byUid,
                'type'      => $type,
                'applied'   => $out['applied'],
                'failed'    => $out['failed'],
                'reverted'  => $out['reverted'],
                'obsolete'  => $out['obsolete'],
                'signature' => self::templateSignature($type),
                'backup'    => basename((string)$backup['file']),
            ];
            self::writeState($state);
            self::forget($type, $folder);

            $out['ok'] = true;
            $log->info('source', "update done for {$folder}: {$out['applied']} file(s), "
                . "{$out['failed']} failed, {$out['reverted']} reverted");
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            $log->error('source', "update crashed for {$folder}: " . $e->getMessage());
        } finally {
            try { if (is_dir($tmpPrev)) Manager::removeDir($tmpPrev); } catch (Throwable $e) { /* بی‌اهمیت */ }
            $out['elapsed_ms'] = (int)((microtime(true) - $started) * 1000);
        }
        return $out;
    }

    public static function isPhp(string $rel): bool
    {
        return strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'php';
    }

    /** کپی با فایل موقت و rename — قطع‌شدن وسط کار نباید فایل نیم‌کاره بگذارد */
    private static function copyAtomic(string $src, string $dst): bool
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $tmp = $dst . '.botsaztmp';
        if (!@copy($src, $tmp)) { @unlink($tmp); return false; }
        if (!@rename($tmp, $dst)) {
            // بعضی هاست‌ها rename روی فایل موجود را رد می‌کنند
            @unlink($dst);
            if (!@rename($tmp, $dst)) { @unlink($tmp); return false; }
        }
        @chmod($dst, 0644);
        return true;
    }

    /**
     * آیا lint را می‌شود به این قالب اطمینان کرد؟
     * اگر نسخهٔ PHP سرور از نیازِ قالب کمتر باشد، `php -l` روی سینتکسِ
     * نسخهٔ جدیدتر خطا می‌دهد و ما فایل درست را هم برمی‌گردانیم ⇒ پس آن‌وقت
     * اصلاً lint نمی‌کنیم (و این نکته در گزارش می‌آید).
     */
    private static function canLint(string $type): bool
    {
        if (Manager::runnerAvailable() === null) return false;
        $min = Manager::templateMinPhp($type);
        if ($min === null) return true;
        // $min یک PHP_VERSION_ID است (مثلاً 80100)، نه «۸٫۰۱»
        return PHP_VERSION_ID >= $min;
    }

    /**
     * `php -l` روی یک فایل — عمومی تا ابزارهای تست هم بتوانند همان را بسنجند.
     * اگر اجرای php در این هاست ممکن نباشد، «true» برمی‌گردد (یعنی «دلیلی برای
     * برگشتِ فایل نیست») و نه false.
     */
    public static function lintPhp(string $file): bool
    {
        if (!is_file($file)) return true;
        $php = Manager::phpBinary();
        $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        try {
            if (function_exists('exec')) {
                $lines = [];
                $rc = 1;
                @exec($cmd, $lines, $rc);
                $out = implode("\n", $lines);
                return $rc === 0 && stripos($out, 'No syntax errors') !== false;
            }
            if (function_exists('shell_exec')) {
                $out = (string)@shell_exec($cmd);
                return stripos($out, 'No syntax errors') !== false;
            }
        } catch (Throwable $e) {
            return true; // ابزار در دسترس نبود ⇒ دلیلی برای برگشت نیست
        }
        return true;
    }

    public static function fmtMs(int $ms): string
    {
        return $ms < 1000 ? "{$ms} میلی‌ثانیه" : round($ms / 1000, 1) . ' ثانیه';
    }
}
