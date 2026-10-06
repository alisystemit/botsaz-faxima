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

    /** پوشهٔ pending برای exec هایی که کاربر وب نمی‌تواند مستقیم بزند */
    private static function pendingDir(): string
    {
        $d = self::rootDir() . '/data/pending_update';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    /** تستِ اینکه کاربر فعلی واقعاً می‌تواند داخل یک پوشهٔ داده‌شده فایل بسازد */
    private static function canWriteInto(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $probe = @fopen(rtrim($dir, '/\\') . '/.botsaz_probe', 'c');
        if (is_resource($probe)) { @fclose($probe); @unlink(rtrim($dir, '/\\') . '/.botsaz_probe'); return true; }
        return false;
    }

    /**
     * اگر کاربرِ فعلی (معمولاً وب‌کاربر) روی پوشهٔ سورس حقِ نوشتن ندارد،
     * اجرای واقعی را به کرون_دیسپچر واگذار می‌کند که با مالک/کلونِ صحیح
     * `bash tools/update.sh` اجرا می‌کند. از داخلِ وب این تنها راهِ
     * بی‌خطاست — وگرنه git reset با «Permission denied» می‌شکند.
     */
    public static function enqueueUpdate(string $kind, int $byUid = 0): array
    {
        $kind = ($kind === 'templates') ? 'templates' : 'full';
        $payload = ['kind' => $kind, 'by' => $byUid, 'at' => date('Y-m-d H:i:s'), 'ts' => time()];
        $f = self::pendingDir() . '/' . $kind . '.json';
        @file_put_contents($f, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return is_file($f)
            ? ['ok' => true, 'note' => "درخواست ثبت شد ({$kind})؛ در نوبتِ بعدی کرون اجرا می‌شود. نتیجه را در data/logs/selfupdate.log ببینید."]
            : ['ok' => false, 'note' => 'ثبتِ درخواست ممکن نشد (مجوزِ نوشتنِ data/؟)'];
    }

    /** اعلام می‌کند آیا الان یک درخواستِ معلق برای اجرا وجود دارد */
    public static function hasPendingUpdate(string $kind): bool
    {
        $kind = ($kind === 'templates') ? 'templates' : 'full';
        return is_file(self::pendingDir() . '/' . $kind . '.json');
    }

    /** حذفِ نشانهٔ pending پس از مصرف */
    public static function clearPendingUpdate(string $kind): void
    {
        $kind = ($kind === 'templates') ? 'templates' : 'full';
        @unlink(self::pendingDir() . '/' . $kind . '.json');
    }

    /**
     * اگر اجرای فعلی با مالکِ پوشهٔ سورس برابر است، '' برمی‌گرداند؛
     * وگرنه پیشوندِ `sudo -n -u <owner>` تا از داخل وب دقیقاً همان
     * کاری اجرا شود که کاربر مالک روی سرور می‌زند (وگرنه git reset
     * با «Permission denied» می‌شکند و آپدیت نیمه‌کاره می‌ماند).
     */
    private static function asOwnerPrefix(string $dir): string
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) return '';
        $uid = @fileowner($dir);
        if ($uid === false) return '';
        if (posix_geteuid() === $uid) return '';
        $pw = @posix_getpwuid($uid);
        $user = is_array($pw) && !empty($pw['name']) ? (string)$pw['name'] : (string)$uid;
        $sudo = trim((string)@shell_exec('command -v sudo 2>/dev/null'));
        return $sudo !== '' ? 'sudo -n -u ' . escapeshellarg($user) . ' ' : '';
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
    //
    // هر قالب ریپوی گیتهابِ مخصوصِ خودش را در Manager::templates()['repo'] دارد.
    // این بخش وضعیتِ templates/ را با «همان ریپو» می‌سنجد نه با originِ ریپوی
    // خودِ ربات‌ساز: فاکسیما با ریپوی فاکسیما، میرزا با ریپوی میرزا و… وقتی
    // کسی دکمهٔ «دریافت سورس بروز» را می‌زند باید سورسِ هر قالب از لینکِ
    // گیتهابِ خودش بیاید، نه از یک کپیِ قدیمیِ داخلِ ریپوی ربات‌ساز.

    /** فایل ثبتِ کامیتِ آخرین نسخه‌ای که از هر ریپو روی templates/ کپی شده */
    public static function templateStateFile(): string
    {
        return self::rootDir() . '/data/template_sources.json';
    }

    public static function templateSourceState(): array
    {
        $f = self::templateStateFile();
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    public static function saveTemplateSourceState(array $state): void
    {
        $f = self::templateStateFile();
        @mkdir(dirname($f), 0755, true);
        @file_put_contents($f, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function rsafeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $key) ?: 'tpl';
    }

    /** پوشهٔ کشِ کلون‌های bare قالب‌ها (برای diff و archive) */
    private static function tplCacheDir(): string
    {
        $d = self::rootDir() . '/data/tpl_repos';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    /** مسیر کلونِ bare هر قالب */
    public static function templateCloneDir(string $key): string
    {
        return self::tplCacheDir() . '/' . self::rsafeKey($key) . '.git';
    }

    /** محیطِ git برای دستورهای وابسته به کلونِ bare (safe.directory را آزاد می‌کند) */
    private static function tplEnv(): array
    {
        $env = getenv();
        if (!is_array($env)) $env = [];
        $env['PATH'] = dirname(PHP_BINARY) . PATH_SEPARATOR . ($env['PATH'] ?? '');
        $env['GIT_CONFIG_COUNT'] = '1';
        $env['GIT_CONFIG_KEY_0'] = 'safe.directory';
        $env['GIT_CONFIG_VALUE_0'] = '*';
        return $env;
    }

    /** خروجی یک git با --git-dir=<bare> */
    private static function gitDir(string $bare, string $args, int $timeout = 20): string
    {
        $r = self::runCmd('git --git-dir=' . escapeshellarg($bare) . ' --no-pager ' . $args . ' 2>&1',
            self::rootDir(), $timeout, self::tplEnv());
        return trim((string)($r['out'] ?? ''));
    }

    /** شناسهٔ HEADِ ریپوی ریموت (بدون کلون) */
    private static function lsRemoteHead(string $repo, int $timeout = 20): string
    {
        $r = self::runCmd('git ls-remote ' . escapeshellarg($repo) . ' HEAD 2>&1',
            self::rootDir(), $timeout, self::tplEnv());
        $out = trim((string)($r['out'] ?? ''));
        if (preg_match('/^([0-9a-f]{40})/', $out, $m)) return $m[1];
        return '';
    }

    /**
     * کلونِ bare قالبی را تازه می‌کند و شناسهٔ آخرین کامیتش را برمی‌گرداند.
     * روی هر به‌روزرسانی یک fetch عمیق‌۱ اجرا می‌شود؛ اگر اول‌بار باشد، کلون می‌سازد.
     *
     * @return array{ok:bool, sha:string, out:string}
     */
    public static function refreshTemplateClone(string $key, int $timeoutSec = 90): array
    {
        $spec = Manager::templateSpec($key);
        $repo = (string)($spec['repo'] ?? '');
        if ($repo === '') return ['ok' => false, 'sha' => '', 'out' => 'این قالب لینک گیتهاب ندارد'];
        $bare = self::templateCloneDir($key);
        if (!is_dir($bare)) {
            $cmd = 'git clone --bare --depth 1 --no-tags ' . escapeshellarg($repo) . ' ' . escapeshellarg($bare) . ' 2>&1';
        } else {
            $cmd = 'git --git-dir=' . escapeshellarg($bare) . ' fetch --depth 1 --no-tags origin HEAD 2>&1';
        }
        $r = self::runCmd($cmd, self::rootDir(), $timeoutSec, self::tplEnv());
        $sha = '';
        if (!is_dir($bare)) {
            return ['ok' => false, 'sha' => '', 'out' => (string)($r['out'] ?? 'clone ناموفق')];
        }
        // بعد از clone اول، rev-parse HEAD؛ بعد از fetch مکرر، FETCH_HEAD
        $sha = trim(self::gitDir($bare, 'rev-parse FETCH_HEAD', 10));
        if (!preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            $sha = trim(self::gitDir($bare, 'rev-parse HEAD', 10));
        }
        if (!preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            return ['ok' => false, 'sha' => '', 'out' => (string)($r['out'] ?? 'sha پیدا نشد')];
        }
        return ['ok' => true, 'sha' => $sha, 'out' => (string)($r['out'] ?? '')];
    }

    /**
     * چه چیزی در هر ریپوی قالب تازه‌تر از نسخهٔ نصب‌شده است؟
     * هیچ تغییری نمی‌دهد؛ فقط می‌گوید دکمهٔ «دریافت سورس بروز» چه خواهد کرد.
     *
     * behind   تعداد قالب‌هایی که مخزنِ خودشان جلوتر است
     * files    مسیرهای templates/ که عوض می‌شوند (تقریبی؛ وقتی diffِ محلی در دست است)
     * templates کلید قالب ⇒ تعداد فایلِ در حال تغییر (یا ۱: فقط «نیاز به بروزرسانی»)
     * repos    وضعیتِ تفکیکی برای هر قالب
     *
     * @return array{branch:string, commit:string, behind:int, files:array<string>, templates:array<string,int>, repos:array<string,array>}
     */
    public static function templatesStatus(): array
    {
        $branch = self::git('rev-parse --abbrev-ref HEAD', 8);
        $commit = self::git('rev-parse --short HEAD', 8);
        if (preg_match('/\bfatal\b|\berror\b|unknown revision/i', $branch)) $branch = '';
        $out = [
            'branch'    => $branch !== '' ? $branch : '?',
            'commit'    => $commit !== '' ? $commit : '?',
            'behind'    => 0,
            'files'     => [],
            'templates' => [],
            'repos'     => [],
        ];
        $state = self::templateSourceState();
        $now = time();
        foreach (Manager::templates() as $key => $spec) {
            $repo = (string)($spec['repo'] ?? '');
            if ($repo === '') continue;
            $dir = Manager::templateDir($key);
            if (!is_dir($dir)) continue;
            // نتیجهٔ ls-remote را ۲ دقیقه کش می‌کنیم تا باز شدن پنل کند نشود
            $cachedSha = (string)($state['_remote'][$key]['sha'] ?? '');
            $cachedAt = (int)($state['_remote'][$key]['at'] ?? 0);
            if ($cachedSha !== '' && $cachedAt > $now - 120) {
                $remoteSha = $cachedSha;
            } else {
                $remoteSha = self::lsRemoteHead($repo, 20);
                if ($remoteSha !== '') {
                    $state['_remote'][$key] = ['sha' => $remoteSha, 'at' => $now];
                    self::saveTemplateSourceState($state);
                }
            }
            $old = (string)($state[$key]['sha'] ?? '');
            $changed = ($remoteSha !== '') && ($old === '' || $old !== $remoteSha);
            $files = [];
            if ($changed && $old !== '') {
                $diff = self::gitDir(self::templateCloneDir($key), "diff --name-only {$old} {$remoteSha}", 20);
                foreach (preg_split('/\r\n|\r|\n/', $diff) ?: [] as $f) {
                    $f = trim(str_replace('\\', '/', $f));
                    if ($f !== '' && !preg_match('/\bfatal\b|unknown revision|ambiguous/i', $f)) $files[] = $f;
                }
            }
            $out['repos'][$key] = [
                'repo' => $repo, 'ok' => $remoteSha !== '',
                'changed' => $changed, 'old' => $old, 'new' => $remoteSha, 'files' => $files,
            ];
            if ($changed) {
                $out['behind']++;
                $out['files'][] = 'templates/' . basename(rtrim(str_replace('\\', '/', $dir), '/'));
                $out['templates'][$key] = $files !== [] ? count($files) : 1;
            }
        }
        return $out;
    }

    /** fetch ایمن برای همین بخش (قبل از محاسبهٔ وضعیت قالب‌ها)؛ bare clone هر قالب را تازه می‌کند */
    public static function fetchSource(): array
    {
        $errors = [];
        $seen = 0;
        foreach (Manager::templates() as $key => $spec) {
            $repo = (string)($spec['repo'] ?? '');
            if ($repo === '') continue;
            $seen++;
            $r = self::refreshTemplateClone($key, 90);
            if (!$r['ok']) $errors[] = $key . ': ' . mb_substr((string)$r['out'], -200);
        }
        if ($seen === 0) return ['ok' => false, 'out' => 'هیچ قالبی لینک گیتهاب ندارد'];
        // چکِ زندهٔ ls-remote را هم به‌روز کن تا پنل عددِ درست نشان دهد
        $state = self::templateSourceState();
        foreach (Manager::templates() as $key => $spec) {
            $repo = (string)($spec['repo'] ?? '');
            if ($repo === '') continue;
            $sha = self::lsRemoteHead($repo, 20);
            if ($sha !== '') $state['_remote'][$key] = ['sha' => $sha, 'at' => time()];
        }
        self::saveTemplateSourceState($state);
        return ['ok' => $errors === [], 'out' => implode("\n", $errors)];
    }

    /**
     * دانلودِ تازه‌ترین سورسِ هر قالب از ریپوی گیتهابِ خودش و کپی‌کردنش روی
     * templates/ زنده — بدون touch شدنِ config.php، .env، دیتابیس‌ها، پوشه‌های
     * data/ و logs/ و بدون هیچ migrate یا ری‌استارتی.
     *
     * فایل‌هایی که دیگر در نسخهٔ تازه نیستند حذف نمی‌شوند (امن؛ فقط گزارش می‌شوند).
     *
     * @return array{ok:bool, results:array<string,array>, out:string}
     */
    public static function updateTemplates(int $timeoutSec = 600): array
    {
        $out = ['ok' => true, 'results' => [], 'out' => ''];
        // اگر کاربرِ وب نمی‌تواند روی templates/ بنویسد، همان update.sh --templates-only
        // را به کرون واگذار کن که با مالکِ صحیح اجرا می‌شود.
        if (!self::canWriteInto(self::rootDir() . '/templates')) {
            $en = self::enqueueUpdate('templates', 0);
            return ['ok' => true, 'results' => [], 'out' => $en['note'], 'queued' => true];
        }
        $state = self::templateSourceState();
        foreach (Manager::templates() as $key => $spec) {
            $repo = (string)($spec['repo'] ?? '');
            $dir = Manager::templateDir($key);
            if ($repo === '' || !is_dir($dir)) continue;
            $r = self::refreshTemplateClone($key, 120);
            if (!$r['ok']) {
                $out['ok'] = false;
                $out['results'][$key] = ['ok' => false, 'applied' => 0, 'error' => 'fetch ناموفق: ' . mb_substr((string)$r['out'], -200)];
                continue;
            }
            $old = (string)($state[$key]['sha'] ?? '');
            if ($old !== '' && $old === $r['sha']) {
                $out['results'][$key] = ['ok' => true, 'skipped' => true, 'applied' => 0, 'files' => []];
                continue;
            }
            // استخراجِ کامیتِ تازه به پوشهٔ موقت (بدون .git، بدون نیاز به tar سیستم‌عامل)
            $work = self::tplCacheDir() . '/.work_' . self::rsafeKey($key);
            try { Manager::removeDir($work); } catch (Throwable $e) { /* بی‌اهمیت */ }
            @mkdir($work, 0755, true);
            $cmd = 'git --git-dir=' . escapeshellarg(self::templateCloneDir($key))
                . ' --no-pager archive --format=tar ' . escapeshellarg($r['sha']);
            $res = self::runCmdBinary($cmd, self::rootDir(), $timeoutSec, self::tplEnv());
            if (!$res['ok'] || $res['out'] === '') {
                $out['ok'] = false;
                $out['results'][$key] = ['ok' => false, 'applied' => 0, 'error' => 'استخراج سورس ناموفق: ' . mb_substr((string)$res['err'], -300)];
                try { Manager::removeDir($work); } catch (Throwable $e) { /* بی‌اهمیت */ }
                continue;
            }
            $untarN = SourceUpdate::untar($res['out'], $work);
            if ($untarN === 0) {
                $out['ok'] = false;
                $out['results'][$key] = ['ok' => false, 'applied' => 0, 'error' => 'بازکردن آرشیو سورس ممکن نشد'];
                try { Manager::removeDir($work); } catch (Throwable $e) { /* بی‌اهمیت */ }
                continue;
            }
            $applied = [];
            foreach (SourceUpdate::walk($work) as $rel => $abs) {
                $relN = ltrim(str_replace('\\', '/', $rel), '/');
                if ($relN === '.git' || str_starts_with($relN, '.git/')) continue;
                // config.php، .env، دیتابیس‌ها، data/، logs/ : هرگز بازنویسی نمی‌شوند
                if (SourceUpdate::isProtected($relN)) continue;
                $dst = rtrim(str_replace('\\', '/', $dir), '/') . '/' . $relN;
                $need = !is_file($dst) || SourceUpdate::hashFile($abs) !== SourceUpdate::hashFile($dst);
                if (!$need) continue;
                $ddir = dirname($dst);
                if (!is_dir($ddir) && !@mkdir($ddir, 0755, true) && !is_dir($ddir)) continue;
                $tmp = $dst . '.botsaztmp';
                if (!@copy($abs, $tmp)) { continue; }
                if (!@rename($tmp, $dst)) {
                    @unlink($dst);
                    if (!@rename($tmp, $dst)) { @unlink($tmp); continue; }
                }
                @chmod($dst, 0644);
                $applied[] = $relN;
            }
            try { Manager::removeDir($work); } catch (Throwable $e) { /* بی‌اهمیت */ }
            $state[$key] = ['sha' => $r['sha'], 'at' => date('Y-m-d H:i:s'), 'applied' => count($applied)];
            unset($state['_remote'][$key]);
            $out['results'][$key] = ['ok' => true, 'skipped' => false, 'applied' => count($applied), 'files' => $applied];
        }
        self::saveTemplateSourceState($state);
        return $out;
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
        // --templates-only قبلاً از مسیر update.sh می‌گذشت که git reset --hard
        // اجرا می‌کرد و بروزرسانی‌های templates/ را برمی‌گرداند. حالا مستقیم به
        // همگام‌سازِ PHP واگذار می‌شود.
        $live = self::rootDir();
        $script = $live . '/tools/sync_templates.php';
        if (!is_file($script)) {
            return ['ok' => false, 'rc' => -1, 'out' => "sync_templates.php نیست", 'problems' => []];
        }
        $php = Manager::phpBinary();
        $res = self::runCmd(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . (int)$timeoutSec . ' 2>&1', $live, $timeoutSec);
        $res['problems'] = [];
        return $res;
    }

    /**
     * اجرای یک دستور و گرفتن خروجیِ باینریِ کامل (مثل stdoutِ git archive).
     * stderr جدا نگه داشته می‌شود تا آرشیو خراب نشود.
     *
     * @return array{ok:bool, rc:int, out:string, err:string}
     */
    private static function runCmdBinary(string $cmd, string $cwd, int $timeoutSec, ?array $env = null): array
    {
        // stream_select روی لوله‌ها در ویندوز قابل اتکا نیست؛ پس خروجی مستقیم
        // روی فایل موقت ریدایرکت می‌شود و فایلِ تمام‌شدهٔ بعد از خروجِ پروسه خوانده می‌شود.
        $tmpOut = self::rootDir() . '/data/logs/tpl_git_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 8) . '.out';
        $tmpErr = $tmpOut . '.err';
        @mkdir(dirname($tmpOut), 0755, true);
        $cmdF = $cmd . ' > ' . escapeshellarg($tmpOut) . ' 2> ' . escapeshellarg($tmpErr);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmdF, $desc, $pipes, $cwd, $env);
        if (!is_resource($proc)) { @unlink($tmpOut); @unlink($tmpErr); return ['ok' => false, 'rc' => -1, 'out' => '', 'err' => 'proc_open failed']; }
        @fclose($pipes[0]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $deadline = microtime(true) + max(5, $timeoutSec);
        $rc = -1;
        while (true) {
            $st = @proc_get_status($proc);
            if (is_array($st) && !$st['running']) {
                $rc = isset($st['exitcode']) && (int)$st['exitcode'] !== -1 ? (int)$st['exitcode'] : $rc;
                break;
            }
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 9);
                break;
            }
            usleep(100000);
        }
        if ($rc === -1) {
            $c = @proc_close($proc);
            $rc = is_int($c) && $c !== -1 ? $c : -1;
        } else {
            @proc_close($proc);
        }
        $out = (string)@file_get_contents($tmpOut);
        $err = (string)@file_get_contents($tmpErr);
        @unlink($tmpOut);
        @unlink($tmpErr);
        return ['ok' => $rc === 0, 'rc' => $rc, 'out' => $out, 'err' => trim($err)];
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

        $src = self::repoDir();   // حالت دوپوشه: گیت/اِسکریپت در سورس است نه در زنده

        // اگر کاربرِ فعلی (معمولاً وب‌کاربر) روی درخت حق‌نوشتن ندارد، اجرای
        // مستقیم با «Permission denied» متوقف می‌شود؛ پس در اینجا کار را به
        // نشانهٔ pending واگذار می‌کنیم تا cron_dispatcher آن را اجرا کند.
        if (!self::canWriteInto($src)) {
            $en = self::enqueueUpdate('full', 0);
            return ($en['ok'] ? '✅ ' : '⚠️ ') . $en['note'];
        }

        $log = self::logPath();
        // باقی ماندن لاگ قبلی کمک می‌کند بفهمیم اجرا اصلاً شروع شده یا نه
        $marker = "\n========== SelfUpdate " . date('Y-m-d H:i:s') . " ==========\n";
        @file_put_contents($log, $marker, FILE_APPEND | LOCK_EX);

        $live = self::rootDir();
        $gitEnv = 'GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=' . escapeshellarg($src);
        $bash = is_executable('/usr/bin/bash') ? '/usr/bin/bash' : (is_executable('/bin/bash') ? '/bin/bash' : 'bash');
        $runner = Manager::runnerAvailable();
        $pathGuess = dirname(PHP_BINARY);
        $ownerPrefix = self::asOwnerPrefix($src);
        $inner = 'cd ' . escapeshellarg($src)
            . ' && PATH="' . $pathGuess . ':/usr/local/bin:/usr/bin:/bin:$PATH" ' . $gitEnv
            . ' BOTSAZ_LIVE_DIR=' . escapeshellarg($live)
            . ' nohup ' . $bash . ' tools/update.sh --web >> ' . escapeshellarg($log) . ' 2>&1 & echo started';
        $cmd = $ownerPrefix !== ''
            ? $ownerPrefix . 'bash -lc ' . escapeshellarg($inner)
            : $inner;
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
