<?php

if (!defined('RX_CRON_HEALTH_LOADED')) {
    define('RX_CRON_HEALTH_LOADED', true);

    function rx_cron_health_root(): string
    {
        return dirname(__DIR__);
    }

    function rx_cron_health_window(): int
    {
        $window = 90;
        if (defined('FAOXIMA_CRON_HEALTH_WINDOW')) {
            $window = (int) FAOXIMA_CRON_HEALTH_WINDOW;
        } elseif (isset($GLOBALS['cronHealthWindow']) && is_numeric($GLOBALS['cronHealthWindow'])) {
            $window = (int) $GLOBALS['cronHealthWindow'];
        }
        return max(65, min(600, $window));
    }

    function rx_cron_health_settle_grace(): int
    {
        return 45;
    }

    function rx_cron_health_new_id(int $bytes = 8): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable $e) {
            return substr(hash('sha256', uniqid('', true) . microtime(true) . getmypid()), 0, $bytes * 2);
        }
    }

    function rx_cron_health_valid_id($id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id) === 1;
    }

    function rx_cron_health_h($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function rx_cron_health_runtime_dir(): string
    {
        return rx_cron_health_root() . DIRECTORY_SEPARATOR . 'cronbot' . DIRECTORY_SEPARATOR . '.runtime';
    }

    function rx_cron_health_journal_dir(bool $create = true): ?string
    {
        $dir = rx_cron_health_runtime_dir() . DIRECTORY_SEPARATOR . 'health';
        if ($create && !@is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return @is_dir($dir) ? $dir : null;
    }

    function rx_cron_health_marker_path(): string
    {
        return rx_cron_health_runtime_dir() . DIRECTORY_SEPARATOR . 'cron_health_pending.json';
    }

    function rx_cron_health_marker_read(): array
    {
        $path = rx_cron_health_marker_path();
        if (!@is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $id => $deadline) {
            if (rx_cron_health_valid_id((string) $id)) {
                $out[(string) $id] = (int) $deadline;
            }
        }
        return $out;
    }

    function rx_cron_health_marker_pending(): bool
    {
        $now = time();
        foreach (rx_cron_health_marker_read() as $deadline) {
            if ($deadline >= $now) {
                return true;
            }
        }
        return false;
    }

    function rx_cron_health_marker_write(array $entries, bool $create): bool
    {
        $path = rx_cron_health_marker_path();
        if (!$create && !@is_file($path)) {
            return true;
        }
        if (empty($entries)) {
            if (@is_file($path)) {
                @unlink($path);
            }
            return !@is_file($path);
        }
        $dir = rx_cron_health_runtime_dir();
        if (!@is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }
        $ok = false;
        if (@flock($fh, LOCK_EX)) {
            $json = (string) json_encode($entries);
            @ftruncate($fh, 0);
            @rewind($fh);
            $ok = @fwrite($fh, $json) === strlen($json);
            @fflush($fh);
            @flock($fh, LOCK_UN);
        }
        @fclose($fh);
        return $ok;
    }

    function rx_cron_health_marker_sync(PDO $pdo, bool $create = false): bool
    {
        try {
            $rows = $pdo->query("SELECT test_id, deadline FROM cron_health_test WHERE active_key IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }
        $entries = [];
        foreach ((array) $rows as $row) {
            $entries[(string) $row['test_id']] = (int) $row['deadline'];
        }
        return rx_cron_health_marker_write($entries, $create);
    }

    function rx_cron_health_journal_append(string $inv, array $record): bool
    {
        if (!rx_cron_health_valid_id($inv)) {
            return false;
        }
        $dir = rx_cron_health_journal_dir(true);
        if ($dir === null) {
            return false;
        }
        $record['inv'] = $inv;
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return false;
        }
        return @file_put_contents($dir . DIRECTORY_SEPARATOR . 'inv_' . $inv . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    function rx_cron_health_journal_scan(int $since): array
    {
        $dir = rx_cron_health_journal_dir(false);
        if ($dir === null || !@is_readable($dir)) {
            return [];
        }
        $files = @glob($dir . DIRECTORY_SEPARATOR . 'inv_*.jsonl');
        if (!is_array($files)) {
            return [];
        }
        $records = [];
        foreach ($files as $file) {
            if ((int) @filemtime($file) < $since) {
                continue;
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                continue;
            }
            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $rec = json_decode($line, true);
                if (is_array($rec) && rx_cron_health_valid_id((string) ($rec['inv'] ?? ''))) {
                    $records[] = $rec;
                }
            }
        }
        return $records;
    }

    function rx_cron_health_journal_prune(int $maxAge = 172800): void
    {
        $dir = rx_cron_health_journal_dir(false);
        if ($dir === null) {
            return;
        }
        $files = @glob($dir . DIRECTORY_SEPARATOR . 'inv_*.jsonl');
        if (!is_array($files)) {
            return;
        }
        $limit = time() - $maxAge;
        foreach ($files as $file) {
            if ((int) @filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    function rx_cron_health_ensure_tables(PDO $pdo): bool
    {
        static $done = false;
        if ($done) {
            return true;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cron_health_test (
                test_id VARCHAR(32) NOT NULL PRIMARY KEY,
                admin_id BIGINT NOT NULL,
                chat_id BIGINT NOT NULL,
                message_id BIGINT NOT NULL DEFAULT 0,
                active_key VARCHAR(32) NULL DEFAULT NULL,
                created_at INT UNSIGNED NOT NULL,
                deadline INT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'waiting',
                invocation_id VARCHAR(32) NULL DEFAULT NULL,
                observed_at INT UNSIGNED NULL DEFAULT NULL,
                claim_token VARCHAR(32) NULL DEFAULT NULL,
                claim_until INT UNSIGNED NOT NULL DEFAULT 0,
                report_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                reported_at INT UNSIGNED NULL DEFAULT NULL,
                meta TEXT NULL,
                result MEDIUMTEXT NULL,
                UNIQUE KEY uq_active (active_key),
                KEY idx_status_deadline (status, deadline)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS cron_health_event (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                invocation_id VARCHAR(32) NOT NULL,
                kind VARCHAR(10) NOT NULL,
                job_key VARCHAR(64) NOT NULL DEFAULT '',
                worker INT NOT NULL DEFAULT 0,
                state VARCHAR(24) NOT NULL,
                started_at INT UNSIGNED NOT NULL DEFAULT 0,
                finished_at INT UNSIGNED NOT NULL DEFAULT 0,
                data MEDIUMTEXT NULL,
                created_at INT UNSIGNED NOT NULL,
                UNIQUE KEY uq_event (invocation_id, kind, job_key, worker),
                KEY idx_kind_started (kind, started_at),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
            $done = true;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    function rx_cron_health_current_pdo(): ?PDO
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        return $pdo instanceof PDO ? $pdo : null;
    }

    function rx_cron_health_probe_dir(string $dir): array
    {
        clearstatcache();
        if (!@is_dir($dir)) {
            return ['ok' => false, 'step' => 'missing'];
        }
        $name = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.rx_cronhealth_' . rx_cron_health_new_id(6) . '.tmp';
        $payload = 'rx-cron-health:' . rx_cron_health_new_id(8);
        $fh = @fopen($name, 'x');
        if ($fh === false) {
            return ['ok' => false, 'step' => 'create'];
        }
        $step = '';
        $written = @fwrite($fh, $payload);
        @fflush($fh);
        if ($written !== strlen($payload)) {
            $step = 'write';
        }
        if ($step === '') {
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                @flock($fh, LOCK_UN);
            } else {
                $step = 'lock';
            }
        }
        @fclose($fh);
        if ($step === '' && @file_get_contents($name) !== $payload) {
            $step = 'read';
        }
        $deleted = @unlink($name);
        clearstatcache(true, $name);
        if ((!$deleted || @file_exists($name)) && $step === '') {
            $step = 'delete';
        }
        return ['ok' => $step === '', 'step' => $step];
    }

    function rx_cron_health_fs_checks(): array
    {
        $root = rx_cron_health_root();
        $runtime = rx_cron_health_runtime_dir();
        if (!@is_dir($runtime)) {
            @mkdir($runtime, 0775, true);
        }
        return [
            'cron/' => rx_cron_health_probe_dir($root . DIRECTORY_SEPARATOR . 'cron'),
            'cronbot/.runtime/' => rx_cron_health_probe_dir($runtime),
            'temp' => rx_cron_health_probe_dir(rtrim(sys_get_temp_dir(), '/\\')),
        ];
    }

    function rx_cron_health_required_files(): array
    {
        $root = rx_cron_health_root();
        $bad = [];
        foreach (['config.php', 'function.php', 'botapi.php', 'cron/cron.php', 'cronbot/_init.php', 'lib/CronHealth.php'] as $rel) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!@is_file($path) || !@is_readable($path)) {
                $bad[] = $rel;
            }
        }
        return $bad;
    }

    function rx_cron_health_orch_begin(): string
    {
        $inv = rx_cron_health_new_id(8);
        $pending = rx_cron_health_marker_pending();
        $GLOBALS['rxCronHealthOrch'] = [
            'inv' => $inv,
            'entry' => time(),
            'traced' => $pending,
            'stages' => [],
            'claimed' => [],
            'diag' => null,
            'exited' => false,
        ];
        if ($pending) {
            rx_cron_health_journal_append($inv, ['t' => 'orch', 'entry' => time(), 'state' => 'entry']);
        }
        register_shutdown_function('rx_cron_health_orch_shutdown');
        return $inv;
    }

    function rx_cron_health_orch_stage(string $name, string $state): void
    {
        if (!isset($GLOBALS['rxCronHealthOrch']) || !is_array($GLOBALS['rxCronHealthOrch'])) {
            return;
        }
        $GLOBALS['rxCronHealthOrch']['stages'][$name] = $state;
    }

    function rx_cron_health_orch_traced(): bool
    {
        return !empty($GLOBALS['rxCronHealthOrch']['traced']);
    }

    function rx_cron_health_orch_persist(): bool
    {
        if (!rx_cron_health_orch_traced()) {
            return false;
        }
        $o = $GLOBALS['rxCronHealthOrch'];
        $record = [
            'entry' => $o['entry'],
            'stages' => $o['stages'],
            'claimed' => $o['claimed'],
            'diag' => $o['diag'],
            'exited' => $o['exited'],
        ];
        $state = isset($o['stages']['complete']) ? 'complete' : ($o['exited'] ? 'exited' : 'running');
        $pdo = rx_cron_health_current_pdo();
        if ($pdo instanceof PDO && rx_cron_health_ensure_tables($pdo)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO cron_health_event (invocation_id, kind, job_key, worker, state, started_at, finished_at, data, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), finished_at = VALUES(finished_at), data = VALUES(data)');
                if ($stmt->execute([$o['inv'], 'orch', '', 0, $state, (int) $o['entry'], $o['exited'] ? time() : 0, json_encode($record), time()])) {
                    return true;
                }
            } catch (\Throwable $e) {
            }
        }
        return rx_cron_health_journal_append($o['inv'], ['t' => 'orch', 'entry' => $o['entry'], 'state' => $state, 'full' => 1, 'data' => $record]);
    }

    function rx_cron_health_orch_shutdown(): void
    {
        if (!rx_cron_health_orch_traced()) {
            return;
        }
        $GLOBALS['rxCronHealthOrch']['exited'] = true;
        rx_cron_health_orch_persist();
    }

    function rx_cron_health_orch_attach_db(PDO $pdo): array
    {
        $result = ['traced' => rx_cron_health_orch_traced(), 'claimed' => [], 'active' => false];
        if (!isset($GLOBALS['rxCronHealthOrch']) || !is_array($GLOBALS['rxCronHealthOrch'])) {
            return $result;
        }
        $o = $GLOBALS['rxCronHealthOrch'];
        try {
            $rows = $pdo->query("SELECT test_id, status, deadline, created_at FROM cron_health_test WHERE active_key IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $result;
        }
        if (empty($rows)) {
            return $result;
        }
        $result['active'] = true;
        $entry = (int) $o['entry'];
        foreach ($rows as $row) {
            if ((string) $row['status'] !== 'waiting' || (int) $row['deadline'] < $entry || (int) $row['created_at'] > $entry) {
                continue;
            }
            $GLOBALS['rxCronHealthOrch']['traced'] = true;
            try {
                $stmt = $pdo->prepare("UPDATE cron_health_test SET status = 'observed', invocation_id = ?, observed_at = ? WHERE test_id = ? AND status = 'waiting' AND invocation_id IS NULL AND claim_token IS NULL");
                $stmt->execute([$o['inv'], $entry, (string) $row['test_id']]);
                if ($stmt->rowCount() === 1) {
                    $result['claimed'][] = (string) $row['test_id'];
                }
            } catch (\Throwable $e) {
            }
        }
        $GLOBALS['rxCronHealthOrch']['claimed'] = $result['claimed'];
        $result['traced'] = rx_cron_health_orch_traced();
        if ($result['traced']) {
            rx_cron_health_orch_persist();
        }
        return $result;
    }

    function rx_cron_health_orch_diagnostics(?PDO $pdo): void
    {
        if (!rx_cron_health_orch_traced()) {
            return;
        }
        try {
            $GLOBALS['rxCronHealthOrch']['diag'] = [
                'dirs' => rx_cron_health_fs_checks(),
                'files' => rx_cron_health_required_files(),
            ];
            rx_cron_health_orch_stage('diagnostics', 'completed');
        } catch (\Throwable $e) {
            rx_cron_health_orch_stage('diagnostics', 'failed');
        }
        rx_cron_health_journal_prune();
        if ($pdo instanceof PDO) {
            try {
                $pdo->prepare('DELETE FROM cron_health_event WHERE created_at < ?')->execute([time() - 3 * 86400]);
                $pdo->prepare('DELETE FROM cron_health_test WHERE active_key IS NULL AND created_at < ?')->execute([time() - 30 * 86400]);
            } catch (\Throwable $e) {
            }
        }
    }

    function rx_cron_health_orch_complete(?PDO $pdo, bool $active): void
    {
        if (rx_cron_health_orch_traced()) {
            rx_cron_health_orch_stage('complete', 'ok');
            rx_cron_health_orch_persist();
        }
        if ($active && $pdo instanceof PDO && function_exists('telegram')) {
            rx_cron_health_finalize_due($pdo, 3);
        }
    }

    function rx_cron_health_load_test(PDO $pdo, string $testId): ?array
    {
        if (!rx_cron_health_valid_id($testId)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT * FROM cron_health_test WHERE test_id = ?');
            $stmt->execute([$testId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    function rx_cron_health_collect(PDO $pdo, array $test): array
    {
        $from = (int) $test['created_at'];
        $to = (int) $test['deadline'];
        $orchs = [];
        try {
            $stmt = $pdo->prepare("SELECT invocation_id, started_at, data FROM cron_health_event WHERE kind = 'orch' AND started_at >= ? AND started_at <= ? ORDER BY started_at ASC LIMIT 30");
            $stmt->execute([$from, $to]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data = json_decode((string) $row['data'], true);
                $orchs[(string) $row['invocation_id']] = (is_array($data) ? $data : []) + ['entry' => (int) $row['started_at'], 'src' => 'db'];
            }
        } catch (\Throwable $e) {
        }
        foreach (rx_cron_health_journal_scan($from - 5) as $rec) {
            if (($rec['t'] ?? '') !== 'orch') {
                continue;
            }
            $inv = (string) $rec['inv'];
            $entry = (int) ($rec['entry'] ?? 0);
            if ($entry < $from || $entry > $to || (isset($orchs[$inv]) && ($orchs[$inv]['src'] ?? '') === 'db')) {
                continue;
            }
            if (!empty($rec['full']) && is_array($rec['data'] ?? null)) {
                $orchs[$inv] = $rec['data'] + ['entry' => $entry, 'src' => 'file'];
            } elseif (!isset($orchs[$inv])) {
                $orchs[$inv] = ['entry' => $entry, 'stages' => [], 'exited' => false, 'src' => 'file'];
            }
        }
        $claimedInv = (string) ($test['invocation_id'] ?? '');
        $claimed = ($claimedInv !== '' && isset($orchs[$claimedInv])) ? $orchs[$claimedInv] : null;
        if ($claimedInv !== '' && $claimed === null) {
            try {
                $stmt = $pdo->prepare("SELECT data FROM cron_health_event WHERE kind = 'orch' AND invocation_id = ?");
                $stmt->execute([$claimedInv]);
                $data = json_decode((string) $stmt->fetchColumn(), true);
                if (is_array($data)) {
                    $claimed = $data;
                }
            } catch (\Throwable $e) {
            }
        }
        return ['orchs' => $orchs, 'claimed' => $claimed];
    }

    function rx_cron_health_failures(array $rec, bool $claimed): array
    {
        $stages = is_array($rec['stages'] ?? null) ? $rec['stages'] : [];
        $failed = [];
        $lock = (string) ($stages['lock'] ?? '');
        if ($lock === 'busy') {
            return ['قفل اصلی اجرای کرون (اجرای قبلی هنوز در جریان بود)'];
        }
        if ($lock === 'open_failed') {
            return ['قفل اصلی اجرای کرون (فایل قفل قابل باز کردن نبود)'];
        }
        $boot = (string) ($stages['bootstrap'] ?? '');
        if ($boot === 'failed' || $boot === 'missing') {
            return ['بارگذاری فایل‌های راه‌اندازی'];
        }
        if (($stages['db'] ?? '') === 'failed') {
            return ['اتصال به دیتابیس'];
        }
        if ($lock === '' || $boot === '' || ($stages['db'] ?? '') === '') {
            return ['پایان کامل اجرای کرون اصلی'];
        }
        if (!$claimed) {
            return ['ثبت تست توسط اجرای کرون'];
        }
        $diag = is_array($rec['diag'] ?? null) ? $rec['diag'] : null;
        if ($diag === null) {
            $failed[] = 'بررسی دسترسی پوشه‌ها';
        } else {
            if (!empty($diag['files'])) {
                $failed[] = 'خواندن فایل‌های پیکربندی: ' . implode('، ', array_map('strval', (array) $diag['files']));
            }
            $steps = ['missing' => 'پوشه وجود ندارد', 'create' => 'ایجاد فایل', 'write' => 'نوشتن', 'read' => 'خواندن', 'lock' => 'قفل فایل', 'delete' => 'حذف فایل'];
            foreach ((array) ($diag['dirs'] ?? []) as $label => $res) {
                if (empty($res['ok'])) {
                    $failed[] = 'دسترسی پوشه ' . $label . ' (' . ($steps[(string) ($res['step'] ?? '')] ?? 'نامشخص') . ')';
                }
            }
        }
        if (!isset($stages['complete'])) {
            $failed[] = 'پایان کامل اجرای کرون اصلی';
        }
        return $failed;
    }

    function rx_cron_health_evaluate(array $test, array $c, int $now): array
    {
        $deadline = (int) $test['deadline'];
        if (!empty($test['invocation_id'])) {
            $rec = is_array($c['claimed']) ? $c['claimed'] : null;
            $settled = is_array($rec) && (isset($rec['stages']['complete']) || !empty($rec['exited']));
            if (!$settled && $now < $deadline + rx_cron_health_settle_grace()) {
                return ['verdict' => 'pending', 'observed' => true, 'failed' => []];
            }
            $failed = is_array($rec) ? rx_cron_health_failures($rec, true) : ['پایان کامل اجرای کرون اصلی'];
            return ['verdict' => empty($failed) ? 'success' : 'failed', 'observed' => true, 'failed' => $failed];
        }
        if ($now < $deadline) {
            return ['verdict' => 'pending', 'observed' => false, 'failed' => []];
        }
        if (empty($c['orchs'])) {
            return ['verdict' => 'timeout', 'observed' => false, 'failed' => []];
        }
        $best = null;
        foreach ($c['orchs'] as $o) {
            $f = rx_cron_health_failures($o, false);
            if ($best === null || count($o['stages'] ?? []) > count($best['o']['stages'] ?? [])) {
                $best = ['o' => $o, 'f' => $f];
            }
        }
        return ['verdict' => 'failed', 'observed' => true, 'failed' => $best['f']];
    }

    function rx_cron_health_render(array $eval): string
    {
        switch ($eval['verdict']) {
            case 'success':
                return "✅ <b>تست کرون موفق بود.</b>\n\nفراخوانی کرون اصلی، راه‌اندازی، اتصال دیتابیس و دسترسی پوشه‌های موردنیاز با موفقیت تأیید شد.";
            case 'timeout':
                return "❌ <b>تست کرون ناموفق بود.</b>\n\nدر بازهٔ بررسی، هیچ فراخوانی از کرون اصلی مشاهده نشد.";
            case 'failed':
                $lines = array_map(static function ($f) {
                    return '• ' . rx_cron_health_h($f);
                }, $eval['failed']);
                return "❌ <b>تست کرون ناموفق بود.</b>\n\nبررسی‌های ناموفق:\n" . implode("\n", $lines);
        }
        return !empty($eval['observed'])
            ? "🧪 <b>تست کرون</b>\n\n⏳ اجرای کرون اصلی مشاهده شد؛ منتظر پایان اجرا هستیم."
            : "🧪 <b>تست کرون</b>\n\n⏳ منتظر اجرای بعدی کرون اصلی هستیم. نتیجه در همین پیام نمایش داده می‌شود.";
    }

    function rx_cron_health_keyboard(string $testId, bool $final): string
    {
        $rows = $final
            ? [[['text' => '🧪 تست مجدد کرون', 'callback_data' => 'cronhealth_start']]]
            : [[['text' => '🔄 بررسی نتیجه', 'callback_data' => 'cronhealth_chk_' . $testId]]];
        $rows[] = [['text' => '🔙 بازگشت به کرون‌ها', 'callback_data' => 'featcat_crons']];
        return (string) json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    function rx_cron_health_deliver(array $test, string $text, string $keyboard): array
    {
        $mid = (int) ($test['message_id'] ?? 0);
        if (!function_exists('telegram')) {
            return ['ok' => false, 'message_id' => $mid];
        }
        if ($mid > 0) {
            $res = telegram('editMessageText', [
                'chat_id' => $test['chat_id'],
                'message_id' => $mid,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard,
            ]);
            if (is_array($res) && !empty($res['ok'])) {
                return ['ok' => true, 'message_id' => $mid];
            }
            $desc = strtolower((string) (is_array($res) ? ($res['description'] ?? '') : ''));
            if (strpos($desc, 'message is not modified') !== false) {
                return ['ok' => true, 'message_id' => $mid];
            }
            $gone = false;
            foreach (['message to edit not found', 'message_id_invalid', "message can't be edited", 'message identifier is not specified'] as $needle) {
                if (strpos($desc, $needle) !== false) {
                    $gone = true;
                    break;
                }
            }
            if (!$gone) {
                return ['ok' => false, 'message_id' => $mid];
            }
        }
        $res = telegram('sendMessage', [
            'chat_id' => $test['chat_id'],
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard,
        ]);
        if (is_array($res) && !empty($res['ok'])) {
            return ['ok' => true, 'message_id' => (int) ($res['result']['message_id'] ?? 0)];
        }
        return ['ok' => false, 'message_id' => $mid];
    }

    function rx_cron_health_claim(PDO $pdo, string $testId, int $lease): ?string
    {
        $token = rx_cron_health_new_id(8);
        try {
            $stmt = $pdo->prepare("UPDATE cron_health_test SET claim_token = ?, claim_until = ? WHERE test_id = ? AND status IN ('waiting', 'observed') AND claim_until < ?");
            $stmt->execute([$token, time() + $lease, $testId, time()]);
            return $stmt->rowCount() === 1 ? $token : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    function rx_cron_health_release(PDO $pdo, string $testId, string $token): void
    {
        try {
            $pdo->prepare('UPDATE cron_health_test SET claim_token = NULL, claim_until = 0 WHERE test_id = ? AND claim_token = ?')->execute([$testId, $token]);
        } catch (\Throwable $e) {
        }
    }

    function rx_cron_health_finalize(PDO $pdo, string $testId): string
    {
        $test = rx_cron_health_load_test($pdo, $testId);
        if ($test === null) {
            return 'missing';
        }
        if (!in_array((string) $test['status'], ['waiting', 'observed'], true)) {
            return (string) $test['status'];
        }
        if (rx_cron_health_evaluate($test, rx_cron_health_collect($pdo, $test), time())['verdict'] === 'pending') {
            return 'pending';
        }
        $token = rx_cron_health_claim($pdo, $testId, 45);
        if ($token === null) {
            return 'busy';
        }
        $test = rx_cron_health_load_test($pdo, $testId);
        if ($test === null) {
            return 'missing';
        }
        $eval = rx_cron_health_evaluate($test, rx_cron_health_collect($pdo, $test), time());
        if ($eval['verdict'] === 'pending') {
            rx_cron_health_release($pdo, $testId, $token);
            return 'pending';
        }
        $text = rx_cron_health_render($eval);
        $result = (string) json_encode(['verdict' => $eval['verdict'], 'failed' => $eval['failed'], 'text' => $text, 'finalized_at' => time()], JSON_UNESCAPED_UNICODE);
        $res = rx_cron_health_deliver($test, $text, rx_cron_health_keyboard($testId, true));
        try {
            if ($res['ok']) {
                $pdo->prepare("UPDATE cron_health_test SET status = 'reported', active_key = NULL, result = ?, reported_at = ?, message_id = ?, claim_token = NULL, claim_until = 0 WHERE test_id = ? AND claim_token = ?")
                    ->execute([$result, time(), (int) $res['message_id'], $testId, $token]);
                return 'reported';
            }
            $attempts = (int) $test['report_attempts'] + 1;
            if ($attempts >= 5) {
                $pdo->prepare("UPDATE cron_health_test SET status = 'undelivered', active_key = NULL, result = ?, report_attempts = ?, claim_token = NULL, claim_until = 0 WHERE test_id = ? AND claim_token = ?")
                    ->execute([$result, $attempts, $testId, $token]);
                return 'undelivered';
            }
            $pdo->prepare('UPDATE cron_health_test SET result = ?, report_attempts = ?, claim_token = NULL, claim_until = ? WHERE test_id = ? AND claim_token = ?')
                ->execute([$result, $attempts, time() + 20, $testId, $token]);
        } catch (\Throwable $e) {
        }
        return 'retry';
    }

    function rx_cron_health_finalize_due(PDO $pdo, int $limit = 3): void
    {
        try {
            $stmt = $pdo->prepare("SELECT test_id FROM cron_health_test WHERE active_key IS NOT NULL AND status IN ('waiting', 'observed') AND (deadline <= ? OR status = 'observed') AND claim_until < ? ORDER BY deadline ASC LIMIT " . max(1, min(10, $limit)));
            $stmt->execute([time(), time()]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return;
        }
        foreach ((array) $ids as $id) {
            rx_cron_health_finalize($pdo, (string) $id);
        }
    }

    function rx_cron_health_webhook_tick(): void
    {
        try {
            $marker = rx_cron_health_marker_read();
            if (empty($marker)) {
                return;
            }
            $now = time();
            $due = false;
            foreach ($marker as $deadline) {
                if ($deadline <= $now) {
                    $due = true;
                    break;
                }
            }
            if (!$due) {
                return;
            }
            $dir = rx_cron_health_journal_dir(true) ?? rx_cron_health_runtime_dir();
            $stamp = $dir . DIRECTORY_SEPARATOR . 'tick.stamp';
            if (@is_file($stamp) && ($now - (int) @filemtime($stamp)) < 5) {
                return;
            }
            @touch($stamp);
            if (!function_exists('telegram') || !function_exists('getDatabaseConnection')) {
                return;
            }
            $pdo = getDatabaseConnection();
            if (!($pdo instanceof PDO)) {
                return;
            }
            rx_cron_health_finalize_due($pdo, 3);
            rx_cron_health_marker_sync($pdo, false);
        } catch (\Throwable $e) {
        }
    }

    function rx_cron_health_answer($callbackId, string $text, bool $alert = false): void
    {
        if (empty($callbackId) || !function_exists('telegram')) {
            return;
        }
        try {
            telegram('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $text,
                'show_alert' => $alert,
                'cache_time' => 0,
            ]);
        } catch (\Throwable $e) {
        }
    }

    function rx_cron_health_admin_action($pdo, string $data, $adminId, $callbackId, $messageId): void
    {
        if (!($pdo instanceof PDO) || !rx_cron_health_ensure_tables($pdo)) {
            rx_cron_health_answer($callbackId, '❌ دیتابیس در دسترس نیست؛ تست کرون قابل ثبت نیست.', true);
            return;
        }
        $adminId = (int) $adminId;
        if ($data === 'cronhealth_start') {
            rx_cron_health_start($pdo, $adminId, $callbackId);
            return;
        }
        if (preg_match('/^cronhealth_chk_([a-f0-9]{16})$/', $data, $m)) {
            rx_cron_health_check($pdo, $m[1], $adminId, $callbackId, (int) $messageId);
            return;
        }
        rx_cron_health_answer($callbackId, '❔ درخواست نامعتبر');
    }

    function rx_cron_health_start(PDO $pdo, int $adminId, $callbackId): void
    {
        $activeKey = (string) $adminId;
        try {
            $stmt = $pdo->prepare('SELECT test_id FROM cron_health_test WHERE active_key = ?');
            $stmt->execute([$activeKey]);
            $existing = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $existing = false;
        }
        if (is_string($existing) && $existing !== '') {
            rx_cron_health_finalize($pdo, $existing);
            $still = rx_cron_health_load_test($pdo, $existing);
            if (is_array($still) && in_array((string) $still['status'], ['waiting', 'observed'], true)) {
                rx_cron_health_answer($callbackId, '⏳ یک تست کرون در جریان است؛ نتیجه در همان پیام اعلام می‌شود.', true);
                return;
            }
        }
        $now = time();
        $window = rx_cron_health_window();
        $testId = rx_cron_health_new_id(8);
        $marker = rx_cron_health_marker_read();
        $marker[$testId] = $now + $window;
        foreach ($marker as $id => $deadline) {
            if ($deadline < $now - 3600) {
                unset($marker[$id]);
            }
        }
        $markerOk = rx_cron_health_marker_write($marker, true);
        try {
            $stmt = $pdo->prepare("INSERT INTO cron_health_test (test_id, admin_id, chat_id, message_id, active_key, created_at, deadline, status, meta) VALUES (?, ?, ?, 0, ?, ?, ?, 'waiting', ?)");
            $stmt->execute([$testId, $adminId, $adminId, $activeKey, $now, $now + $window, json_encode(['window' => $window, 'marker' => $markerOk])]);
        } catch (\Throwable $e) {
            rx_cron_health_marker_sync($pdo, false);
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                rx_cron_health_answer($callbackId, '⏳ یک تست کرون در جریان است؛ نتیجه در همان پیام اعلام می‌شود.', true);
            } else {
                rx_cron_health_answer($callbackId, '❌ ثبت تست کرون ناموفق بود.', true);
            }
            return;
        }
        rx_cron_health_answer($callbackId, '⏳ تست کرون ثبت شد.');
        $test = rx_cron_health_load_test($pdo, $testId);
        if ($test === null) {
            return;
        }
        $text = rx_cron_health_render(['verdict' => 'pending', 'observed' => false, 'failed' => []]);
        $res = rx_cron_health_deliver($test, $text, rx_cron_health_keyboard($testId, false));
        if ($res['ok'] && $res['message_id'] > 0) {
            try {
                $pdo->prepare('UPDATE cron_health_test SET message_id = ? WHERE test_id = ? AND message_id = 0')->execute([$res['message_id'], $testId]);
            } catch (\Throwable $e) {
            }
        }
    }

    function rx_cron_health_check(PDO $pdo, string $testId, int $adminId, $callbackId, int $messageId): void
    {
        $test = rx_cron_health_load_test($pdo, $testId);
        if ($test === null) {
            rx_cron_health_answer($callbackId, '❔ این تست پیدا نشد؛ یک تست جدید شروع کنید.', true);
            return;
        }
        if ($messageId > 0 && (int) $test['message_id'] !== $messageId && (int) $test['chat_id'] === $adminId) {
            try {
                $pdo->prepare("UPDATE cron_health_test SET message_id = ? WHERE test_id = ? AND status IN ('waiting', 'observed')")->execute([$messageId, $testId]);
            } catch (\Throwable $e) {
            }
        }
        $state = rx_cron_health_finalize($pdo, $testId);
        $test = rx_cron_health_load_test($pdo, $testId);
        if ($test === null) {
            rx_cron_health_answer($callbackId, '❔ این تست پیدا نشد.', true);
            return;
        }
        if ($state === 'reported') {
            rx_cron_health_answer($callbackId, 'نتیجه ثبت شد.');
            rx_cron_health_marker_sync($pdo, false);
            return;
        }
        $view = $test;
        if ($messageId > 0) {
            $view['message_id'] = $messageId;
            $view['chat_id'] = $adminId;
        }
        if (!in_array((string) $test['status'], ['waiting', 'observed'], true)) {
            $stored = json_decode((string) ($test['result'] ?? ''), true);
            if (is_array($stored) && !empty($stored['text'])) {
                rx_cron_health_deliver($view, (string) $stored['text'], rx_cron_health_keyboard($testId, true));
            }
            rx_cron_health_answer($callbackId, 'نتیجه نهایی نمایش داده شد.');
            rx_cron_health_marker_sync($pdo, false);
            return;
        }
        if ($state === 'busy') {
            rx_cron_health_answer($callbackId, '⏳ نتیجه در حال ثبت است؛ چند لحظه دیگر دوباره بررسی کنید.');
            return;
        }
        if ($state === 'retry') {
            rx_cron_health_answer($callbackId, '⚠️ ارسال نتیجه ناموفق بود؛ به‌زودی دوباره تلاش می‌شود.', true);
            return;
        }
        $token = rx_cron_health_claim($pdo, $testId, 30);
        if ($token === null) {
            rx_cron_health_answer($callbackId, '⏳ نتیجه در حال به‌روزرسانی است؛ چند لحظه دیگر دوباره بررسی کنید.');
            return;
        }
        $test = rx_cron_health_load_test($pdo, $testId);
        if (is_array($test)) {
            $eval = rx_cron_health_evaluate($test, rx_cron_health_collect($pdo, $test), time());
            if ($eval['verdict'] !== 'pending') {
                $eval = ['verdict' => 'pending', 'observed' => !empty($test['invocation_id']), 'failed' => []];
            }
            rx_cron_health_deliver($view, rx_cron_health_render($eval), rx_cron_health_keyboard($testId, false));
        }
        rx_cron_health_release($pdo, $testId, $token);
        rx_cron_health_answer($callbackId, '⏳ هنوز در انتظار نتیجه…');
    }
}
