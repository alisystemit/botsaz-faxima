<?php
/**
 * چرخهٔ عمر مشترک ربات‌های ساخته‌شده.
 *
 * قبلاً «غیرفعال/فعال» و «حذف» دو پیاده‌سازی جدا داشتند: bot.php وبهوک و
 * دیتابیس را درست مدیریت می‌کرد، ولی app/api.php فقط رکورد/پوشه را دست می‌زد.
 * نتیجه: وبهوک باقی‌مانده، دیتابیس یتیم و «غیرفعال» بی‌اثر. این کلاس یک منبع
 * حقیقت برای هر دو مسیر است.
 */
class BotLifecycle
{
    /** توکن خام ربات فرزند (رمزگشایی‌شده) */
    public static function token(array $cfg, array $bot): string
    {
        $stored = (string)($bot['token'] ?? '');
        if ($stored === '') return '';
        return Manager::decryptChildToken($stored, Manager::secretKey($cfg));
    }

    /** @return array{ok:bool,error:string,warnings:string[]} */
    public static function disable(array $cfg, Store $store, array $bot): array
    {
        $warnings = [];
        $token = self::token($cfg, $bot);
        if ($token === '') {
            return ['ok' => false, 'error' => 'توکن ربات قابل خواندن نیست', 'warnings' => []];
        }
        $r = BotApi::deleteWebhook($token);
        if (!is_array($r) || empty($r['ok'])) {
            // یک تلاش دوباره برای خطای گذرای شبکه/تلگرام
            usleep(400000);
            $r = BotApi::deleteWebhook($token);
        }
        if (!is_array($r) || empty($r['ok'])) {
            $err = (string)($r['description'] ?? 'deleteWebhook ناموفق بود');
            return ['ok' => false, 'error' => $err, 'warnings' => $warnings];
        }
        $store->setBotStatus((int)$bot['id'], 'disabled');
        self::log('disable', $bot, ['by' => $GLOBALS['__botsaz_actor'] ?? 0]);
        return ['ok' => true, 'error' => '', 'warnings' => $warnings];
    }

    /** @return array{ok:bool,error:string,warnings:string[]} */
    public static function enable(array $cfg, Store $store, array $bot): array
    {
        $token = self::token($cfg, $bot);
        if ($token === '') {
            return ['ok' => false, 'error' => 'توکن ربات قابل خواندن نیست', 'warnings' => []];
        }
        $url = Manager::webhookUrlForBot($cfg, $bot);
        $secret = Manager::resolveWebhookSecret($bot, $cfg);
        $spec = Manager::templateSpec((string)($bot['type'] ?? ''));
        if (is_array($spec) && (string)($spec['secret'] ?? '') === 'static' && $secret === '') {
            // بدون secret، ربات پس‌از فعال‌سازی همهٔ updateها را ۴۰۳ می‌کند.
            return ['ok' => false, 'error' => 'secret وبهوک ربات پیدا نشد؛ ابتدا آدرس وبهوک را بازسازی کنید', 'warnings' => []];
        }
        $r = BotApi::setWebhook($token, $url, $secret);
        if (!is_array($r) || empty($r['ok'])) {
            usleep(400000);
            $r = BotApi::setWebhook($token, $url, $secret);
        }
        if (!is_array($r) || empty($r['ok'])) {
            $err = (string)($r['description'] ?? 'setWebhook ناموفق بود');
            return ['ok' => false, 'error' => $err, 'warnings' => []];
        }
        $store->setBotStatus((int)$bot['id'], 'active');
        self::log('enable', $bot, ['by' => $GLOBALS['__botsaz_actor'] ?? 0]);
        return ['ok' => true, 'error' => '', 'warnings' => []];
    }

    /**
     * حذف کامل: وبهوک → بکاپ → حذف دیتابیس → حذف پوشه → حذف رکورد.
     * اگر حذف دیتابیس شکست بخورد، رکورد و پوشه نگه داشته می‌شوند تا ادمین
     * بتواند دوباره تلاش کند (به‌جای یتیم‌ماندن دیتابیس).
     *
     * @return array{ok:bool,error:string,warnings:string[],backup:string,db_dropped:bool}
     */
    public static function delete(array $cfg, Store $store, array $bot): array
    {
        $warnings = [];
        $folder = (string)($bot['folder'] ?? '');
        if ($folder === '' || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $folder)) {
            return ['ok' => false, 'error' => 'پوشهٔ ربات نامعتبر است', 'warnings' => [], 'backup' => '', 'db_dropped' => false];
        }
        $token = self::token($cfg, $bot);
        if ($token !== '') {
            $del = BotApi::deleteWebhook($token);
            if (!is_array($del) || empty($del['ok'])) {
                usleep(400000);
                $del = BotApi::deleteWebhook($token);
            }
            if (!is_array($del) || empty($del['ok'])) {
                $warnings[] = 'حذف وبهوک از تلگرام ناموفق بود: ' . (string)($del['description'] ?? 'unknown');
            }
        } else {
            $warnings[] = 'توکن ربات قابل خواندن نبود؛ وبهوک حذف نشد.';
        }

        $dir = Manager::childBotsDir() . '/' . $folder;
        $backup = '';
        if (is_dir($dir)) {
            $backupDir = dirname($dir) . '/backups/' . $folder . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            @mkdir($backupDir, 0755, true);
            try {
                Manager::copyDir($dir, $backupDir . '/data', ['config.php', '.htaccess']);
                Manager::stripSensitiveFiles($backupDir . '/data', ['config.php', '.htaccess']);
                // فایل‌های موقت patch (config.php.tmp-*/config.php.botsaztmp-*) هم ممکن است
                // در اثر crash باقی مانده باشند و نباید وارد بکاپ شوند.
                foreach (glob($backupDir . '/data/config.php.*') ?: [] as $tmpCfg) {
                    @unlink($tmpCfg);
                }
                $backup = $backupDir;
            } catch (Throwable $e) {
                // حذف، برگشت‌ناپذیر است؛ اگر بکاپ گرفته نشد، دیتابیس/پوشه را
                // پاک نمی‌کنیم تا ادمین بتواند مشکل دیسک/دسترسی را رفع و دوباره
                // تلاش کند. بکاپ نیمه‌کاره هم پاک می‌شود.
                if (is_dir($backupDir)) { try { Manager::removeDir($backupDir); } catch (Throwable $ignored) {} }
                self::log('delete.backup_failed', $bot, ['error' => $e->getMessage()]);
                return [
                    'ok' => false,
                    'error' => 'ساخت بکاپ قبل از حذف ناموفق بود؛ برای جلوگیری از از دست رفتن داده، حذف انجام نشد: ' . $e->getMessage(),
                    'warnings' => $warnings,
                    'backup' => '',
                    'db_dropped' => false,
                ];
            }
        }

        $dbDropped = true;
        $dbName = (string)($bot['db_name'] ?? '');
        if ($dbName !== '') {
            try {
                self::dropDatabase($cfg, $dbName);
            } catch (Throwable $e) {
                // دیتابیس را رها نمی‌کنیم: رکورد و پوشه می‌مانند تا تلاش دوباره ممکن باشد.
                self::log('delete.db_failed', $bot, ['db' => $dbName, 'error' => $e->getMessage()]);
                return [
                    'ok' => false,
                    'error' => 'حذف دیتابیس ناموفق بود؛ رکورد و پوشه نگه داشته شدند: ' . $e->getMessage(),
                    'warnings' => $warnings,
                    'backup' => $backup,
                    'db_dropped' => false,
                ];
            }
            $dbDropped = true;
        }

        if (is_dir($dir)) {
            Manager::removeDir($dir);
            if (is_dir($dir)) {
                return [
                    'ok' => false,
                    'error' => 'حذف پوشهٔ ربات ناموفق بود؛ رکورد نگه داشته شد',
                    'warnings' => $warnings,
                    'backup' => $backup,
                    'db_dropped' => $dbDropped,
                ];
            }
        }

        $store->deleteBot((int)$bot['id']);
        try { Manager::pruneBackups(dirname($dir) . '/backups', $folder, 5); } catch (Throwable $e) {
            $warnings[] = 'پاک‌سازی بکاپ‌های قدیمی ناموفق بود: ' . $e->getMessage();
        }
        self::log('delete', $bot, ['backup' => $backup, 'db' => $dbName]);
        return ['ok' => true, 'error' => '', 'warnings' => $warnings, 'backup' => $backup, 'db_dropped' => $dbDropped];
    }

    private static function dropDatabase(array $cfg, string $dbName): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new Exception('نام دیتابیس نامعتبر است');
        }
        $host = (string)($cfg['db_host'] ?? '127.0.0.1');
        $port = (int)($cfg['db_port'] ?? 3306);
        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            (string)($cfg['db_user'] ?? ''),
            (string)($cfg['db_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );
        $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }

    private static function log(string $action, array $bot, array $extra = []): void
    {
        try {
            require_once __DIR__ . '/Logger.php';
            $meta = array_merge([
                'id' => (int)($bot['id'] ?? 0),
                'folder' => (string)($bot['folder'] ?? ''),
            ], $extra);
            Logger::getInstance()->info('bot_lifecycle', $action . ' ' . json_encode($meta, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // لاگ نباید عملیات اصلی را بشکند
        }
    }
}
