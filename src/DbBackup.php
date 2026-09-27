<?php
// ===== بکاپ خودکار دیتابیس ربات‌های فرزند =====
// برای هر ربات فعال: دامپ MySQL همان دیتابیس → فشرده‌سازی gzip → ارسال به
// ادمین همان ربات با توکن خود همان ربات (sendDocument).
//
// زمان‌بندی در config.php:
//   'db_backup' => ['enabled' => true, 'times' => ['03:00', '15:00']]
// ساعت‌ها به وقت سرور. برای روزی یک‌بار کافی است یکی را حذف کنی.
// تکراری اجرا شدن بی‌خطر است: هر اسلات در هر روز فقط یک‌بار ارسال می‌شود
// (وضعیت در data/db_backup.json) مگر با --force.

class DbBackup
{
    public const DEFAULT_TIMES = ['03:00', '15:00'];
    // حاشیه امن زیر سقف 50MB تلگرام؛ بزرگ‌تر از این فقط локально نگه داشته می‌شود
    public const MAX_SEND_BYTES = 45 * 1024 * 1024;

    /**
     * تنظیمات بکاپ — اولویت: دیتابیس مدیریتی (قابل تغییر از داخل ربات توسط ادمین)
     * بعد config.php، بعد پیش‌فرض. پس config.phpهای قدیمی هم کار می‌کنند.
     */
    public static function settings(?array $cfg, ?Store $store = null): array
    {
        $raw = null;
        if ($store !== null) {
            try {
                $j = $store->getSetting('db_backup', null);
                if ($j !== null) {
                    $decoded = json_decode($j, true);
                    if (is_array($decoded)) $raw = $decoded;
                }
            } catch (Throwable $e) {
                $raw = null;
            }
        }
        if (!is_array($raw)) {
            $raw = (is_array($cfg) && isset($cfg['db_backup']) && is_array($cfg['db_backup']))
                ? $cfg['db_backup'] : [];
        }
        $enabled = $raw['enabled'] ?? true;
        $times = $raw['times'] ?? self::DEFAULT_TIMES;
        if (!is_array($times)) $times = self::DEFAULT_TIMES;
        $times = array_values(array_unique(array_filter(array_map(
            fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string)$t)) ? trim((string)$t) : null,
            $times
        ))));
        if ($times === []) $times = self::DEFAULT_TIMES;
        sort($times);
        return ['enabled' => (bool)$enabled, 'times' => $times];
    }

    /** ذخیره تنظیمات بکاپ در دیتابیس مدیریتی (از داخل ربات توسط ادمین) */
    public static function saveSettings(Store $store, bool $enabled, array $times): array
    {
        $set = self::settings(['db_backup' => ['enabled' => $enabled, 'times' => $times]]);
        $store->setSetting('db_backup', json_encode($set, JSON_UNESCAPED_UNICODE));
        return $set;
    }

    /**
     * پارس ساعت‌های واردشده توسط ادمین: «3,15» «03:00,15:00» «6» «3 15»
     * @return array{ok:bool, times:string[], error:string}
     */
    public static function parseTimes(string $input): array
    {
        $parts = preg_split('/[\s,;|]+/', trim($input)) ?: [];
        $times = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            if (preg_match('/^(\d{1,2})$/', $p, $m)) {
                $h = (int)$m[1];
                if ($h < 0 || $h > 23) return ['ok' => false, 'times' => [], 'error' => "ساعت «{$p}» معتبر نیست (۰ تا ۲۳)"];
                $times[] = sprintf('%02d:00', $h);
            } elseif (preg_match('/^(\d{1,2}):(\d{1,2})$/', $p, $m)) {
                $h = (int)$m[1];
                $min = (int)$m[2];
                if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return ['ok' => false, 'times' => [], 'error' => "ساعت «{$p}» معتبر نیست"];
                $times[] = sprintf('%02d:%02d', $h, $min);
            } else {
                return ['ok' => false, 'times' => [], 'error' => "قالب «{$p}» اشتباه است؛ مثلاً: 3,15 یا 03:00,15:00"];
            }
        }
        $times = array_values(array_unique($times));
        sort($times);
        if ($times === []) return ['ok' => false, 'times' => [], 'error' => 'هیچ ساعتی وارد نشد؛ مثلاً: 3,15'];
        if (count($times) > 4) return ['ok' => false, 'times' => [], 'error' => 'حداکثر ۴ ساعت در روز'];
        return ['ok' => true, 'times' => $times, 'error' => ''];
    }

    public static function stateFile(): string
    {
        return dirname(__DIR__) . '/data/db_backup.json';
    }

    /** @return array<string, array{last_sent:string,last_file:string,last_size:int}> */
    public static function readState(): array
    {
        $f = self::stateFile();
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    public static function writeState(array $state): void
    {
        $f = self::stateFile();
        @mkdir(dirname($f), 0755, true);
        @file_put_contents($f, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** آیا اسلات امروزِ این ربات رسیده و هنوز ارسال نشده؟ */
    public static function isDue(string $folder, array $times, array $state, ?int $now = null): bool
    {
        $now = $now ?? time();
        $today = date('Y-m-d', $now);
        $hm = date('H:i', $now);
        $slot = null;
        foreach ($times as $t) {
            if ($t <= $hm) $slot = $t;
        }
        if ($slot === null) return false; // هنوز اولین اسلات امروز نرسیده
        $last = (string)($state[$folder]['last_sent'] ?? '');
        return $last < ($today . ' ' . $slot);
    }

    /**
     * اجرای بکاپ برای ربات‌هایی که اسلات‌شان رسیده.
     * @return array{sent: string[], skipped: string[], failed: array<string,string>}
     */
    public static function runDue(array $cfg, Store $store, bool $force = false, ?string $onlyFolder = null): array
    {
        $log = Logger::getInstance();
        $out = ['sent' => [], 'skipped' => [], 'failed' => []];
        $set = self::settings($cfg, $store);
        if (!$set['enabled'] && !$force) {
            $out['skipped'][] = 'disabled in config (db_backup.enabled=false)';
            return $out;
        }
        $state = self::readState();
        foreach ($store->allBots() as $bot) {
            if (($bot['status'] ?? '') !== 'active') continue;
            $folder = (string)($bot['folder'] ?? '');
            if ($folder === '') continue;
            if ($onlyFolder !== null && $folder !== $onlyFolder) continue;
            $dbName = (string)($bot['db_name'] ?? '');
            if ($dbName === '') {
                $out['skipped'][] = "$folder: no db_name stored";
                continue;
            }
            if (!$force && !self::isDue($folder, $set['times'], $state)) {
                $out['skipped'][] = "$folder: slot not due yet";
                continue;
            }
            try {
                $file = self::dumpGzip($cfg, $dbName, $folder);
                $size = (int)@filesize($file);
                $adminId = (int)($bot['admin_id'] ?? 0) ?: (int)($bot['owner_id'] ?? 0);
                if ($adminId <= 0) throw new Exception("admin_id نامشخص است");
                $plainToken = Manager::decryptChildToken((string)($bot['token'] ?? ''), Manager::secretKey($cfg));
                if (!preg_match('/^\d+:[\w\-]{20,}$/', $plainToken)) throw new Exception("توکن ربات معتبر نیست");
                $stamp = date('Y-m-d H:i');
                $caption = "نسخه پشتیبان دیتابیس\nربات: {$folder}\nدیتابیس: {$dbName}\nتاریخ: {$stamp}";
                if ($size > self::MAX_SEND_BYTES) {
                    // تلگرام قبول نمی‌کند — локально نگه دار و فقط خبر بده
                    $kept = self::keepLocal($file, $folder);
                    BotApi::send($plainToken, $adminId, "⚠️ بکاپ دیتابیس {$folder} خیلی بزرگ شد (" . self::fmtSize($size) . ") و در تلگرام جا نشد.\nفایل در سرور نگه داشته شد: {$kept}\nتاریخ: {$stamp}");
                    $log->warning('backup', "Bot {$folder}: dump too big (" . self::fmtSize($size) . "), kept locally");
                    $out['failed'][$folder] = 'too big for Telegram, kept on server';
                } else {
                    $res = BotApi::sendDocument($plainToken, $adminId, $file, $caption);
                    if (empty($res['ok'])) throw new Exception("ارسال تلگرام ناموفق: " . ($res['description'] ?? 'unknown'));
                    $log->info('backup', "Bot {$folder}: backup sent to {$adminId} (" . self::fmtSize($size) . ")");
                    $out['sent'][] = "$folder → $adminId (" . self::fmtSize($size) . ")";
                }
                $state[$folder] = [
                    'last_sent' => date('Y-m-d H:i'),
                    'last_file' => basename($file),
                    'last_size' => $size,
                ];
                self::writeState($state);
                @unlink($file);
            } catch (Throwable $e) {
                $log->error('backup', "Bot {$folder} backup failed: " . $e->getMessage());
                $out['failed'][$folder] = $e->getMessage();
            }
        }
        return $out;
    }

    /** دامپ + gzip؛ مسیر فایل .sql.gz برمی‌گرداند */
    public static function dumpGzip(array $cfg, string $dbName, string $folder): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) throw new Exception("نام دیتابیس نامعتبر است");
        $tmp = sys_get_temp_dir() . '/botsaz_backup';
        @mkdir($tmp, 0700, true);
        $sql = $tmp . '/' . $folder . '_' . date('Y-m-d_H-i') . '.sql';
        if (!self::dumpViaMysqldump($cfg, $dbName, $sql)) {
            self::dumpViaPhp($cfg, $dbName, $sql); // fallback وقتی mysqldump نیست/بسته است
        }
        $gz = $sql . '.gz';
        $in = @fopen($sql, 'rb');
        if ($in === false) throw new Exception("خواندن دامپ ممکن نشد");
        $out = @gzopen($gz, 'wb9');
        if ($out === false) { @fclose($in); throw new Exception("ساخت فایل gzip ممکن نشد"); }
        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk !== false && $chunk !== '') gzwrite($out, $chunk);
        }
        @fclose($in);
        @gzclose($out);
        @unlink($sql);
        if (!is_file($gz)) throw new Exception("فایل بکاپ ساخته نشد");
        return $gz;
    }

    /** دامپ با mysqldump؛ false یعنی نبود/نشد و باید fallback اجرا شود */
    private static function dumpViaMysqldump(array $cfg, string $dbName, string $outFile): bool
    {
        $bin = null;
        foreach (['mysqldump', 'mariadb-dump'] as $b) {
            $w = self::which($b);
            if ($w !== null) { $bin = $w; break; }
        }
        if ($bin === null) return false;
        $host = (string)($cfg['db_host'] ?? '127.0.0.1');
        $port = (int)($cfg['db_port'] ?? 3306);
        $user = (string)($cfg['db_user'] ?? '');
        $pass = (string)($cfg['db_pass'] ?? '');
        $cmd = escapeshellarg($bin)
            . ' -h ' . escapeshellarg($host)
            . ' -P ' . escapeshellarg((string)$port)
            . ' -u ' . escapeshellarg($user)
            . ' --single-transaction --quick --routines --events'
            . ' ' . escapeshellarg($dbName)
            . ' > ' . escapeshellarg($outFile) . ' 2>/dev/null';
        // پسورد فقط از env تا در لیست پروسس‌ها (ps) دیده نشود
        $oldPwd = getenv('MYSQL_PWD');
        @putenv('MYSQL_PWD=' . $pass);
        try {
            $code = 1;
            if (function_exists('exec')) {
                @exec($cmd, $lines, $code);
            } elseif (function_exists('shell_exec')) {
                @shell_exec($cmd);
                $code = is_file($outFile) && @filesize($outFile) > 0 ? 0 : 1;
            } else {
                return false;
            }
        } finally {
            if ($oldPwd === false) @putenv('MYSQL_PWD');
            else @putenv('MYSQL_PWD=' . $oldPwd);
        }
        return $code === 0 && is_file($outFile) && @filesize($outFile) > 0;
    }

    private static function which(string $bin): ?string
    {
        if (!function_exists('shell_exec') && !function_exists('exec')) return null;
        $paths = ['/usr/bin/' . $bin, '/usr/local/bin/' . $bin, '/usr/local/mysql/bin/' . $bin];
        foreach ($paths as $p) {
            if (is_file($p) && is_executable($p)) return $p;
        }
        if (function_exists('shell_exec')) {
            $w = trim((string)@shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($w !== '' && is_file($w)) return $w;
        }
        return null;
    }

    /** دامپ pure-PHP وقتی mysqldump در دسترس نیست */
    private static function dumpViaPhp(array $cfg, string $dbName, string $outFile): void
    {
        $pdo = new PDO(
            "mysql:host=" . ($cfg['db_host'] ?? '127.0.0.1') . ";port=" . (int)($cfg['db_port'] ?? 3306) . ";charset=utf8mb4",
            (string)($cfg['db_user'] ?? ''), (string)($cfg['db_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $fh = @fopen($outFile, 'wb');
        if ($fh === false) throw new Exception("ساخت فایل دامپ ممکن نشد");
        $w = fn($s) => fwrite($fh, $s . "\n");
        $w("-- botsaz backup of `{$dbName}` at " . date('Y-m-d H:i:s'));
        $w("SET NAMES utf8mb4;");
        $tables = $pdo->query("SHOW FULL TABLES IN `{$dbName}`")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$name, $kind]) {
            if (strtoupper((string)$kind) === 'VIEW') {
                $row = $pdo->query("SHOW CREATE VIEW `{$dbName}`.`{$name}`")->fetch(PDO::FETCH_ASSOC);
                $w("DROP VIEW IF EXISTS `{$name}`;");
                $w(($row['Create View'] ?? '') . ";");
                continue;
            }
            $row = $pdo->query("SHOW CREATE TABLE `{$dbName}`.`{$name}`")->fetch(PDO::FETCH_ASSOC);
            $w("DROP TABLE IF EXISTS `{$name}`;");
            $w(($row['Create Table'] ?? '') . ";");
            $off = 0;
            while (true) {
                $rows = $pdo->query("SELECT * FROM `{$dbName}`.`{$name}` LIMIT 500 OFFSET {$off}")->fetchAll(PDO::FETCH_NUM);
                if ($rows === []) break;
                $vals = [];
                foreach ($rows as $r) {
                    $cells = [];
                    foreach ($r as $c) {
                        $cells[] = $c === null ? 'NULL' : $pdo->quote((string)$c);
                    }
                    $vals[] = '(' . implode(',', $cells) . ')';
                }
                $w("INSERT INTO `{$name}` VALUES " . implode(',', $vals) . ";");
                $off += 500;
            }
        }
        // تریگرها
        try {
            foreach ($pdo->query("SHOW TRIGGERS IN `{$dbName}`")->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                $tname = $tr['Trigger'] ?? '';
                if ($tname === '') continue;
                $cr = $pdo->query("SHOW CREATE TRIGGER `{$dbName}`.`{$tname}`")->fetch(PDO::FETCH_ASSOC);
                if (!empty($cr['SQL Original Statement'])) {
                    $w("DROP TRIGGER IF EXISTS `{$tname}`;");
                    $w($cr['SQL Original Statement'] . ";");
                }
            }
        } catch (Throwable $e) {
            // دسترسی SHOW TRIGGER نباشد، از خیرش می‌گذریم
        }
        @fclose($fh);
    }

    /** فایل‌های بزرگ‌تر از سقف تلگرام: نگه‌داری локالی در data/backups/ */
    private static function keepLocal(string $file, string $folder): string
    {
        $dir = dirname(__DIR__) . '/data/backups';
        @mkdir($dir, 0755, true);
        // قدیمی‌ها پاک شوند تا دیسک پر نشود (آخرین ۳ نسخه هر ربات)
        $old = glob($dir . '/' . $folder . '_*.sql.gz') ?: [];
        rsort($old);
        foreach (array_slice($old, 2) as $o) @unlink($o);
        $dst = $dir . '/' . basename($file);
        @rename($file, $dst);
        return 'data/backups/' . basename($file);
    }

    public static function fmtSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
