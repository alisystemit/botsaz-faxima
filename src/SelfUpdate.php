<?php
// ===== بروزرسانی خودِ ربات‌ساز (از درون تلگرام) =====
//
// این ماژولِ «دکمهٔ ⬆️ آپدیت» در پنل ادمین است. اجرای واقعی را به
// خودِ tools/update.sh می‌سپارد (تنها منبع حقیقت)؛ اینجا فقط چک‌کردنِ
// شرایط، راه‌اندازی امنِ پس‌زمینه و نمایش وضعیت و لاگ است.
//
// چرا پس‌زمینه؟ ری‌استارت وب‌سرور وسط یک درخواست وبهوک، خودِ آن درخواست را
// می‌کشد و تلگرام همان آپدیت را دوباره می‌فرستد. پس اجرای --web همیشه
// جدا (nohup) و با --no-restart است؛ ری‌استارت سرویس عمداً کارِ اجرای
// معمولیِ دستی یا کرون هفتگی باقی می‌ماند.

class SelfUpdate
{
    public static function rootDir(): string
    {
        return dirname(__DIR__);
    }

    public static function logPath(): string
    {
        return self::rootDir() . '/data/logs/selfupdate.log';
    }

    // ===== مسیر سورسِ گیت (حالت دوپوشه: /root → /var/www) =====
    //
    // خودِ ربات از درونِ «زنده» (پوشهٔ وب) اجرا می‌شود؛ اگر نصب دوپوشه باشد،
    // گیت و tools/update.sh در پوشهٔ سورس‌اند و باید از همان‌جا اجرا شوند.
    // منبع‌ها به‌ترتیب: فایلِ اشاره‌گرِ خودِ update.sh، کلید source_dir در
    // config.php، و در نهایت خودِ همین نصب (حالت تک‌پوشه).

    private static bool $srcResolved = false;
    private static ?string $srcDir = null;
    private static string $srcHint = '';

    /** مسیر سورسِ گیت؛ null یعنی پیدا نشد/دسترسی نیست (دلیل را sourceHint بگیر) */
    public static function sourceDir(): ?string
    {
        self::resolveSource();
        return self::$srcDir;
    }

    /** توضیحِ اینکه چرا sourceDir پیدا نشد (برای نمایش به ادمین) */
    public static function sourceHint(): string
    {
        self::resolveSource();
        return self::$srcHint;
    }

    /** پوشه‌ای که باید دستورهای git/update.sh از آنجا اجرا شوند */
    public static function repoDir(): string
    {
        return self::sourceDir() ?? self::rootDir();
    }

    private static function resolveSource(): void
    {
        if (self::$srcResolved) return;
        self::$srcResolved = true;
        $root = self::rootDir();

        $cands = [];
        $ptr = $root . '/data/source_dir.txt';
        if (is_file($ptr)) {
            $v = trim((string)@file_get_contents($ptr));
            if ($v !== '') $cands[] = $v;
        }
        try {
            $cfgFile = $root . '/config.php';
            if (is_file($cfgFile)) {
                $c = @include $cfgFile;
                if (is_array($c) && !empty($c['source_dir']) && is_string($c['source_dir'])) {
                    $cands[] = $c['source_dir'];
                }
            }
        } catch (Throwable $e) { /* config خراب ⇒ همین خودش در problems گزارش می‌شود */ }
        $cands[] = $root;

        foreach ($cands as $d) {
            $d = rtrim(str_replace('\\', '/', trim((string)$d)), '/');
            if ($d === '') continue;
            if (is_dir($d . '/.git') && is_file($d . '/tools/update.sh')) {
                self::$srcDir = $d;
                return;
            }
            if (self::$srcHint === '' && $d !== $root) {
                self::$srcHint = is_dir($d)
                    ? "سورسِ ثبت‌شده ({$d}) گیت/اِسکریپت ندارد."
                    : "سورسِ ثبت‌شده ({$d}) برای کاربر وب قابل دسترسی نیست — پوشهٔ سورس را از /root به مسیری مثل /home/botsaz منتقل کنید یا دسترسی بدهید.";
            }
        }
        if (self::$srcHint === '') {
            self::$srcHint = 'هیچ مسیر سورسِ گیتی پیدا نشد؛ یک بار bash tools/update.sh را دستی اجرا کنید تا فایل اشاره‌گر ساخته شود.';
        }
    }

    /** پیش‌نیازهای همین دکمه؛ [] یعنی همه‌چیز آماده است */
    public static function problems(): array
    {
        $out = [];
        if (Manager::runnerAvailable() === null) {
            $out[] = 'اجرای پروسه (exec/shell_exec/popen) روی این سرور غیرفعال است؛ آپدیت باید دستی با bash tools/update.sh شود.';
        }
        $src = self::sourceDir();
        if ($src === null) {
            $out[] = self::sourceHint();
        }
        $sh = ($src ?? self::rootDir()) . '/tools/update.sh';
        if (!is_file($sh)) {
            $out[] = "tools/update.sh پیدا نشد ({$sh})؛ نسخهٔ ناقص را کامل از گیت‌هاب بگیرید.";
        }
        $bashOk = false;
        foreach (['bash', '/bin/bash', '/usr/bin/bash'] as $b) {
            if (@is_executable($b)) { $bashOk = true; break; }
        }
        if (!$bashOk && self::isWindows()) { /* ویندوز dev: فقط هشدار لازم نیست */ $bashOk = true; }
        if (!$bashOk) $out[] = 'bash روی سرور پیدا نشد.';
        if (!is_writable(self::rootDir() . '/data/logs')) {
            $out[] = 'پوشهٔ data/logs/ قابل نوشتن نیست؛ ری‌موشن پیام‌رسان کار نمی‌کند.';
        }
        return $out;
    }

    private static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /** آیا gitِ این نصب از --no-optional-locks پشتیبانی می‌کند؟ (git ≥ 2.15) */
    private static $noOptLocks = null;

    /**
     * خروجی یک دستور git کوتاه با timeout؛ '' یعنی ناموفق
     *
     * همهٔ این فراخوانی‌ها «خواندنی»‌اند و با --no-optional-locks اصلاً
     * .git/index.lock نمی‌سازند. یک gitِ وسطِ کارِ کشته‌شده (بسته‌شدن لوله توسط
     * PHP، کشته‌شدن وبهوک) قفل را رها می‌کند و از آن پس هر git reset با
     * «Unable to create index.lock: File exists» می‌شکند؛ status و fetch اما
     * سالم می‌مانند و دقیقاً همان خطای گیج‌کنندهٔ «reset hard failed» پیش می‌آید.
     */
    private static function git(string $args, int $timeout = 8): string
    {
        $flag = (self::$noOptLocks === null || self::$noOptLocks) ? '--no-optional-locks ' : '';
        $out = self::gitRun($args, $timeout, $flag);
        // gitِ قدیمی (< 2.15) این گزینه را نمی‌شناسد ⇒ یک بار بدون آن دوباره امتحان کن.
        // پیامِ خودِ گزینه باید دیده شود تا خطای بی‌ربط («unknown option» دیگر)
        // باعثِ خاموش‌شدنِ دائمیِ این محافظ نشود.
        if ($flag !== '' && preg_match('/unknown option[^\n]*no-optional-locks/i', $out)) {
            self::$noOptLocks = false;
            $out = self::gitRun($args, $timeout, '');
        }
        return $out;
    }

    private static function gitRun(string $args, int $timeout, string $flag): string
    {
        $dir = self::repoDir();
        $cmd = 'git -c safe.directory=' . escapeshellarg($dir)
            . ' --no-pager ' . $flag . $args . ' 2>&1';
        $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptorspec, $pipes, $dir);
        if (!is_resource($proc)) {
            $out = '';
            if (function_exists('shell_exec')) $out = (string)@shell_exec($cmd);
            return trim($out);
        }
        fclose($pipes[0]);
        // تایم‌اوت کوچکِ استریم + مهلت کلِ خودمان؛ این‌طور هم خروجی تا انتها خوانده
        // می‌شود هم زمانِ کل دقیقاً کنترل است.
        @stream_set_timeout($pipes[1], 1);
        $out = '';
        $deadline = microtime(true) + max(2, $timeout);
        $eof = false;
        // تا انتها می‌خوانیم و فقط ذخیره را محدود می‌کنیم؛ بستنِ زودهنگامِ لوله
        // باعث می‌شود git وسطِ نوشتن با EPIPE بمیرد و قفل را رها کند.
        while (true) {
            $chunk = fgets($pipes[1]);
            if ($chunk === false) {
                if (feof($pipes[1])) { $eof = true; break; }
                if (microtime(true) >= $deadline) break;
                continue;   // بدونِ داده بود؛ تا موعد دوباره گوش می‌دهیم
            }
            if (strlen($out) < 65536) $out .= $chunk;
        }
        if (!$eof) @proc_terminate($proc, 9);   // واقعاً timeout ⇒ پروسه را بکش
        fclose($pipes[1]);
        fclose($pipes[2]);
        @proc_close($proc);
        return trim($out);
    }

    /** وضعیت فعلی نصب: شاخه، کامیت، تعداد عقب‌افتادگی، تمیزی درخت */
    public static function status(): array
    {
        $branch = self::git('rev-parse --abbrev-ref HEAD');
        $commit = self::git('rev-parse --short HEAD');
        $dirty = self::git('status --porcelain --untracked-files=no');
        // بدون fetch زور نمی‌کنیم؛ فقط از آخرین fetch خبر داریم
        $ahead = self::git('rev-list --count HEAD..origin/' . $branch);
        $lastFetch = '';
        $f = self::repoDir() . '/.git/FETCH_HEAD';
        if (is_file($f)) $lastFetch = date('Y-m-d H:i', (int)@filemtime($f));
        return [
            'branch' => $branch !== '' ? $branch : '?',
            'commit' => $commit !== '' ? $commit : '?',
            'dirty' => trim($dirty) !== '',
            'behind' => is_numeric(trim($ahead)) ? (int)trim($ahead) : -1,
            'last_fetch' => $lastFetch,
            'log_tail' => self::logTail(12),
        ];
    }

    /** fetch ایمن برای دیدن اینکه نسخهٔ تازه هست یا نه (قبل از اجرا) */
    public static function refreshRemote(): array
    {
        $out = self::git('fetch origin 2>&1', 45);
        return ['ok' => $out !== '' || self::git('rev-parse --abbrev-ref HEAD') !== '', 'out' => $out];
    }

    // ===== وضعیتِ پوشهٔ قالب‌ها (templates/) =====

    /**
     * چه چیزی در origin برای templates/ تازه‌تر از نسخهٔ نصب‌شده است؟
     * هیچ تغییری نمی‌دهد؛ فقط می‌گوید دکمهٔ «دریافت سورس بروز» چه خواهد کرد.
     *
     * @return array{branch:string, commit:string, behind:int, files:array<string>, templates:array<string,int>}
     */
    public static function templatesStatus(): array
    {
        $branch = self::git('rev-parse --abbrev-ref HEAD', 8);
        $commit = self::git('rev-parse --short HEAD', 8);
        if (preg_match('/\bfatal\b|\berror\b|unknown revision/i', $branch)) $branch = '';
        $out = [
            'branch'    => $branch !== '' ? $branch : '?',
            'commit'    => $commit !== '' ? $commit : '?',
            'behind'    => -1,
            'files'     => [],
            'templates' => [],
        ];
        if ($branch === '' || self::sourceDir() === null) return $out;

        $n = self::git("rev-list --count HEAD..origin/{$branch}", 20);
        if (is_numeric(trim($n))) $out['behind'] = (int)trim($n);

        $diff = self::git("diff --name-only HEAD..origin/{$branch} -- templates/", 30);
        if ($diff === '' || preg_match('/\bfatal\b|unknown revision/i', $diff)) return $out;
        foreach (preg_split('/\r\n|\r|\n/', $diff) ?: [] as $f) {
            $f = trim(str_replace('\\', '/', $f));
            if ($f === '') continue;
            $out['files'][] = $f;
            $p = explode('/', $f);
            if (isset($p[1]) && $p[1] !== '') {
                $name = $p[1];
                $out['templates'][$name] = ($out['templates'][$name] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** fetch ایمن برای همین بخش (قبل از محاسبهٔ وضعیت قالب‌ها) */
    public static function fetchSource(): array
    {
        if (self::sourceDir() === null) return ['ok' => false, 'out' => self::sourceHint()];
        $out = self::git('fetch --prune origin 2>&1', 60);
        $ok = stripos((string)$out, 'fatal:') === false
           && stripos((string)$out, 'could not read') === false
           && stripos((string)$out, 'unable to access') === false;
        return ['ok' => $ok, 'out' => $out];
    }

    // ===== اجرای همزمانِ بروزرسانی سورس =====

    /**
     * اجرای همزمانِ `tools/update.sh --templates-only` از پوشهٔ سورس.
     * هیچ سرویسی ری‌استارت نمی‌شود، config و دیتابیس و وبهوک دست نمی‌خورند و
     * فقط templates/ روی زنده کپی می‌شود؛ یعنی ربات‌های ساخته‌شده تغییری نمی‌بینند.
     *
     * @return array{ok:bool, rc:int, out:string, problems:array<string>}
     */
    public static function runTemplatesOnly(int $timeoutSec = 600): array
    {
        $probs = self::problems();
        if ($probs !== []) return ['ok' => false, 'rc' => -1, 'out' => '', 'problems' => $probs];

        $src = self::repoDir();
        $live = self::rootDir();
        $bash = is_executable('/usr/bin/bash') ? '/usr/bin/bash' : (is_executable('/bin/bash') ? '/bin/bash' : 'bash');
        $pathGuess = dirname(PHP_BINARY);

        // متغیرهای محیطیِ جدا از proc_open داده می‌شوند تا هم در لینوکس کار کند
        // و هم در ویندوز (که cmd.exe پیشوندِ VAR=x را نمی‌فهمد)
        $env = getenv();
        if (!is_array($env)) $env = [];
        $env['PATH'] = $pathGuess . PATH_SEPARATOR . ($env['PATH'] ?? '');
        $env['GIT_CONFIG_COUNT'] = '1';
        $env['GIT_CONFIG_KEY_0'] = 'safe.directory';
        // مسیرها با اسلش رو به جلو تا برای bash هم قابل فهم باشند (ویندوز/دِو)
        $env['GIT_CONFIG_VALUE_0'] = str_replace('\\', '/', $src);
        $env['BOTSAZ_LIVE_DIR'] = str_replace('\\', '/', $live);

        $cmd = $bash . ' tools/update.sh --templates-only 2>&1';
        $res = self::runCmd($cmd, $src, $timeoutSec, $env);
        $res['problems'] = [];
        return $res;
    }

    /**
     * اجرای یک دستورِ خط فرمان و گرفتن خروجی کامل (با مهلت کل).
     * @return array{ok:bool, rc:int, out:string}
     */
    private static function runCmd(string $cmd, string $cwd, int $timeoutSec, ?array $env = null): array
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // $env === null ⇒ محیط فعلی PHP به فرزند داده می‌شود (آرایهٔ خالی یعنی محیطِ خالی!)
        $proc = @proc_open($cmd, $desc, $pipes, $cwd, $env);
        if (!is_resource($proc)) return ['ok' => false, 'rc' => -1, 'out' => 'proc_open failed'];
        @fclose($pipes[0]);
        foreach ([1, 2] as $i) {
            @stream_set_blocking($pipes[$i], false);
        }

        $out = '';
        $deadline = microtime(true) + max(5, $timeoutSec);
        $rc = -1;
        while (true) {
            $r = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;
            $n = @stream_select($r, $w, $e, 1, 0);
            if ($n > 0) {
                foreach ($r as $h) {
                    $chunk = @fread($h, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $out .= $chunk;
                        // فقط دُمِ خروجی نگه داشته می‌شود (گزارش خطا همیشه همین‌جاست)
                        if (strlen($out) > 200000) $out = substr($out, -200000);
                    }
                }
            }
            $st = @proc_get_status($proc);
            if (is_array($st) && !$st['running']) {
                if (isset($st['exitcode']) && (int)$st['exitcode'] !== -1) $rc = (int)$st['exitcode'];
                foreach ([1, 2] as $i) {
                    $rest = (string)@stream_get_contents($pipes[$i]);
                    if ($rest !== '') {
                        $out .= $rest;
                        if (strlen($out) > 200000) $out = substr($out, -200000);
                    }
                }
                break;
            }
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 9);
                $out .= "\n[timeout after {$timeoutSec}s – process killed]";
                break;
            }
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        if ($rc === -1) {
            $c = @proc_close($proc);
            $rc = is_int($c) && $c !== -1 ? $c : -1;
        } else {
            @proc_close($proc);
        }
        return ['ok' => $rc === 0, 'rc' => $rc, 'out' => trim($out)];
    }

    /**
     * اجرای پس‌زمینهٔ update.sh --web؛ لاگ در data/logs/selfupdate.log.
     * برمی‌گرداند پیام فارسی برای نمایش به ادمین.
     */
    public static function startWeb(): string
    {
        $probs = self::problems();
        if ($probs !== []) return "⛔️ امکان اجرا نیست:\n• " . implode("\n• ", $probs);

        $log = self::logPath();
        // باقی ماندن لاگ قبلی کمک می‌کند بفهمیم اجرا اصلاً شروع شده یا نه
        $marker = "\n========== SelfUpdate " . date('Y-m-d H:i:s') . " ==========\n";
        @file_put_contents($log, $marker, FILE_APPEND | LOCK_EX);

        $src = self::repoDir();   // حالت دوپوشه: گیت/اِسکریپت در سورس است نه در زنده
        $live = self::rootDir();
        $gitEnv = 'GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=' . escapeshellarg($src);
        $bash = is_executable('/usr/bin/bash') ? '/usr/bin/bash' : (is_executable('/bin/bash') ? '/bin/bash' : 'bash');
        $runner = Manager::runnerAvailable();
        $pathGuess = dirname(PHP_BINARY);
        $cmd = 'cd ' . escapeshellarg($src)
            . ' && PATH="' . $pathGuess . ':/usr/local/bin:/usr/bin:/bin:$PATH" ' . $gitEnv
            . ' BOTSAZ_LIVE_DIR=' . escapeshellarg($live)
            . ' nohup ' . $bash . ' tools/update.sh --web >> ' . escapeshellarg($log) . ' 2>&1 & echo started';
        $out = '';
        try {
            if ($runner === 'shell_exec') { $out = (string)@shell_exec($cmd); }
            elseif ($runner === 'exec') { $lines = []; @exec($cmd, $lines); $out = implode("\n", $lines); }
            elseif ($runner === 'popen') { $h = @popen($cmd, 'r'); if (is_resource($h)) { $out = (string)@stream_get_contents($h); @pclose($h); } }
        } catch (Throwable $e) {
            return 'خطا در راه‌اندازی: ' . $e->getMessage();
        }
        usleep(800000); // 0.8s برای اینکه لاگ شروع شود
        $tail = self::logTail(5);
        if (!str_contains((string)$out, 'started') && !self::isRunning()) {
            return "⚠️ اجرای پس‌زمینه تأیید نشد. آخرین لاگ:\n" . ($tail !== '' ? $tail : '(خالی)');
        }
        if (!self::isRunning()) {
            // خیلی زود تمام شد: اگر با خطا تمام شده باشد نباید «✅ شروع شد» بگوییم.
            // همین پیامِ دروغینِ موفقیت بود که ادمین را گیج می‌کرد (لاگِ شکست، پیامِ موفقیت).
            $seg = self::logSinceLastMarker();
            if ($seg !== '' && preg_match('/✘|fatal:|Another update is already running/i', $seg)) {
                return "⚠️ بروزرسانی همان ابتدا متوقف شد:\n<pre>"
                    . htmlspecialchars(mb_substr($seg, -1500), ENT_QUOTES, 'UTF-8') . "</pre>";
            }
        }
        return "✅ بروزرسانی در پس‌زمینه شروع شد.\nلاگ زنده: «📜 آخرین لاگ آپدیت».\nپس از اتمام، ادمین از تلگرام نوتیفیکیشن می‌گیرد و کد جدید بعد از ری‌استارت دستی/کرون اعمال می‌شود.";
    }

    /** فقط بخشِ بعد از آخرین مارکرِ لاگ (یعنی خروجی همین اجرای نو) */
    private static function logSinceLastMarker(): string
    {
        $f = self::logPath();
        if (!is_file($f)) return '';
        $s = (string)@file_get_contents($f);
        if ($s === '') return '';
        $p = strrpos($s, '========== SelfUpdate');
        return $p === false ? $s : substr($s, $p);
    }

    /** آیا الان update.sh در حال اجراست؟ (بر اساس فایل قفل + ps) */
    public static function isRunning(): bool
    {
        if (!function_exists('exec') && !function_exists('shell_exec')) return false;
        $out = '';
        if (function_exists('shell_exec')) $out = (string)@shell_exec('ps aux 2>/dev/null | grep -c "[u]pdate.sh"');
        elseif (function_exists('exec')) { $l = []; @exec('ps aux 2>/dev/null | grep -c "[u]pdate.sh"', $l); $out = implode('', $l); }
        return (int)trim($out) > 0;
    }

    /** آخرین $n خط لاگ اجرای قبلی */
    public static function logTail(int $n = 15): string
    {
        $f = self::logPath();
        if (!is_file($f)) return '';
        $lines = @file($f, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) return '';
        return implode("\n", array_slice($lines, -max(1, $n)));
    }
}
