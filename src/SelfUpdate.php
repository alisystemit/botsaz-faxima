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

    /** پیش‌نیازهای همین دکمه؛ [] یعنی همه‌چیز آماده است */
    public static function problems(): array
    {
        $out = [];
        if (Manager::runnerAvailable() === null) {
            $out[] = 'اجرای پروسه (exec/shell_exec/popen) روی این سرور غیرفعال است؛ آپدیت باید دستی با bash tools/update.sh شود.';
        }
        if (!is_dir(self::rootDir() . '/.git')) {
            $out[] = 'این نصب کپی گیت نیست (.git ندارد)؛ فقط git pull از مسیر دستی ممکن است.';
        }
        if (!is_file(self::rootDir() . '/tools/update.sh')) {
            $out[] = 'tools/update.sh پیدا نشد؛ نسخهٔ ناقص را کامل از گیت‌هاب بگیرید.';
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

    /** خروجی یک دستور git کوتاه با timeout؛ '' یعنی ناموفق */
    private static function git(string $args, int $timeout = 8): string
    {
        $cmd = 'git -c safe.directory=' . escapeshellarg(self::rootDir())
            . ' --no-pager ' . $args . ' 2>&1';
        $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptorspec, $pipes, self::rootDir());
        if (!is_resource($proc)) {
            $out = '';
            if (function_exists('shell_exec')) $out = (string)@shell_exec($cmd);
            return trim($out);
        }
        fclose($pipes[0]);
        @stream_set_timeout($pipes[1], $timeout);
        $out = '';
        while (!feof($pipes[1])) {
            $chunk = fgets($pipes[1]);
            if ($chunk === false) break;
            $out .= $chunk;
            if (strlen($out) > 65536) break;
        }
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
        $f = self::rootDir() . '/.git/FETCH_HEAD';
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
        $out = self::git('fetch origin 2>&1', 25);
        return ['ok' => $out !== '' || self::git('rev-parse --abbrev-ref HEAD') !== '', 'out' => $out];
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

        $root = self::rootDir();
        $gitEnv = 'GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=' . escapeshellarg($root);
        $bash = is_executable('/usr/bin/bash') ? '/usr/bin/bash' : (is_executable('/bin/bash') ? '/bin/bash' : 'bash');
        $runner = Manager::runnerAvailable();
        $pathGuess = dirname(PHP_BINARY);
        $cmd = 'cd ' . escapeshellarg($root)
            . ' && PATH="' . $pathGuess . ':/usr/local/bin:/usr/bin:/bin:$PATH" ' . $gitEnv
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
        if (str_contains((string)$out, 'started') || self::isRunning()) {
            return "✅ بروزرسانی در پس‌زمینه شروع شد.\nلاگ زنده: «📜 آخرین لاگ آپدیت».\nپس از اتمام، ادمین از تلگرام نوتیفیکیشن می‌گیرد و کد جدید بعد از ری‌استارت دستی/کرون اعمال می‌شود.";
        }
        return "⚠️ اجرای پس‌زمینه تأیید نشد. آخرین لاگ:\n" . ($tail !== '' ? $tail : '(خالی)');
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
