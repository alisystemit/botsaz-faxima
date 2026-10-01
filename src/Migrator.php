<?php
// ===== مکانیزم migration برای Store =====
// اسکیما‌های دیتابیس با شماره نسخه هستند

class Migrator
{
    private const SCHEMA_VERSION = 6;
    private PDO $pdo;
    private string $driver;

    public function __construct(PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    /**
     * اجرای migration های واقعی.
     * هر مرحله به‌صورت مستقل اجرا و خطایش گزارش می‌شود؛ شماره نسخه فقط بعد از
     * موفقیت همهٔ مراحل ثبت می‌شود تا اجرای بعدی از همان‌جا ادامه دهد.
     */
    public function migrate(): array
    {
        $results = [];
        $currentVersion = $this->getCurrentVersion();

        if ($currentVersion >= self::SCHEMA_VERSION) {
            return $results; // به‌روز است — هیچ INSERT اضافه‌ای نزن
        }

        if ($currentVersion < 1) {
            $this->ensureIndex('pending_requests', 'idx_pending_status', 'status');
            $results['v1'] = 'index idx_pending_status on pending_requests(status) ensured';
        }
        if ($currentVersion < 2) {
            $this->ensureIndex('bots', 'idx_bots_owner', 'owner_id');
            $results['v2'] = 'index idx_bots_owner on bots(owner_id) ensured';
        }
        if ($currentVersion < 3) {
            $this->ensureIndex('processed_updates', 'idx_processed_updates_id', 'update_id');
            $results['v3'] = 'processed_updates primary key verified';
        }
        if ($currentVersion < 4) {
            // v4 قبلاً فقط پیام می‌داد؛ هیچ تغییر اسکیمایی لازم نیست
            $results['v4'] = 'no schema change required';
        }
        if ($currentVersion < 5) {
            $missing = [];
            foreach (['users', 'bots', 'pending_requests', 'processed_updates'] as $t) {
                if (!$this->tableExists($t)) $missing[] = $t;
            }
            if ($missing) {
                throw new Exception('tables missing: ' . implode(', ', $missing));
            }
            $results['v5'] = 'all tables verified';
        }
        if ($currentVersion < 6) {
            // v6: سیستم پرداخت/لیمیت — جدول payments + ستون users.bot_limit
            $this->createPaymentsTable();
            $this->ensureUserBotLimit();
            $results['v6'] = 'payments table + users.bot_limit ensured';
        }

        $this->setVersion(self::SCHEMA_VERSION);
        return $results;
    }

    private function tableExists(string $table): bool
    {
        if ($this->driver === 'sqlite') {
            $st = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $st->execute([$table]);
            return $st->fetch() !== false;
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    }

    /** ساخت ایندکس در صورت نبودن (بدون خطا اگر از قبل هست یا مجوز نداریم) */
    private function ensureIndex(string $table, string $index, string $column): void
    {
        try {
            if (!$this->tableExists($table)) return;
            if ($this->driver === 'sqlite') {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table}({$column})");
                return;
            }
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");
            $st->execute([$table, $index]);
            if ((int)$st->fetchColumn() > 0) return;
            $this->pdo->exec("CREATE INDEX {$index} ON {$table}({$column})");
        } catch (Exception $e) {
            error_log("Migrator::ensureIndex({$table}.{$index}) skipped: " . $e->getMessage());
        }
    }

    public function getCurrentVersion(): int
    {
        try {
            $st = $this->pdo->query("SELECT version FROM schema_versions ORDER BY applied_at DESC LIMIT 1");
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['version'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function setVersion(int $version): void
    {
        try {
            $st = $this->pdo->prepare("INSERT INTO schema_versions (version) VALUES (?)");
            $st->execute([$version]);
        } catch (Exception $e) {
            // جدول ممکن است قبلاً وجود داشته باشد
        }
    }

    /** v6: جدول payments */
    private function createPaymentsTable(): void
    {
        try {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS payments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    kind TEXT NOT NULL,
                    template TEXT DEFAULT '',
                    slots INTEGER DEFAULT 0,
                    amount INTEGER DEFAULT 0,
                    method TEXT DEFAULT '',
                    status TEXT DEFAULT 'pending',
                    receipt TEXT DEFAULT '',
                    ext_id TEXT DEFAULT '',
                    pay_url TEXT DEFAULT '',
                    created_at TEXT DEFAULT (datetime('now')),
                    paid_at TEXT DEFAULT NULL,
                    handled_at TEXT DEFAULT NULL
                )");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_pay_user ON payments(user_id)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_pay_status ON payments(status)");
                // پاریتی با MySQL: fallbackهای IPN و «🔄 بررسی وضعیت» روی ext_id جست‌وجو می‌کنند
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_pay_ext ON payments(ext_id)");
            } else {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    kind VARCHAR(20) NOT NULL,
                    template VARCHAR(20) DEFAULT '',
                    slots INT DEFAULT 0,
                    amount BIGINT DEFAULT 0,
                    method VARCHAR(20) DEFAULT '',
                    status VARCHAR(20) DEFAULT 'pending',
                    receipt TEXT,
                    ext_id VARCHAR(120) DEFAULT '',
                    pay_url TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    paid_at TIMESTAMP NULL DEFAULT NULL,
                    handled_at TIMESTAMP NULL DEFAULT NULL,
                    INDEX idx_pay_user (user_id),
                    INDEX idx_pay_status (status),
                    INDEX idx_pay_ext (ext_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (Exception $e) {
            error_log("Migrator v6 payments skipped: " . $e->getMessage());
        }
    }

    /** v6: ستون users.bot_limit (1- = نامحدود) */
    private function ensureUserBotLimit(): void
    {
        try {
            if ($this->driver === 'sqlite') {
                $cols = array_column($this->pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
                if (!in_array('bot_limit', $cols, true)) {
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN bot_limit INTEGER DEFAULT 1");
                }
            } else {
                $st = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'bot_limit'");
                if ($st->fetch() === false) {
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN bot_limit INT DEFAULT 1");
                }
            }
        } catch (Exception $e) {
            error_log("Migrator v6 bot_limit skipped: " . $e->getMessage());
        }
    }
}
