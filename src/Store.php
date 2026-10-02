<?php
// ===== دیتابیس مدیریتی ربات‌ساز =====
// اگر pdo_sqlite فعال باشد از فایل SQLite استفاده می‌شود،
// وگرنه خودکار به MySQL (دیتابیس manager) سوییچ می‌کند تا روی لاراگون بدون تنظیم کار کند.

require_once __DIR__ . '/Migrator.php';

class Store
{
    private PDO $pdo;
    private string $driver; // 'sqlite' | 'mysql'
    private string $schemaId; // شناسهٔ یکتای دیتابیس برای کش اسکیمای ماژول‌ها

    public function __construct(string $sqlitePath, array $cfg = [])
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $dir = dirname($sqlitePath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $this->pdo = new PDO('sqlite:' . $sqlitePath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // ===== پایداری SQLite در برابر «database is locked» =====
            // processed_updates به‌ازای هر آپدیت نوشته می‌شود؛ بدون busy_timeout و WAL،
            // دو درخواست همزمان وبهوک خطا می‌گرفتند. WAL همزمانی خواندن/نوشتن را هم آزاد می‌کند.
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->driver = 'sqlite';
            $this->schemaId = 'sqlite:' . $sqlitePath;
            $this->initSqlite();
            return;
        }
        // fallback: MySQL manager
        $host = $cfg['db_host'] ?? '127.0.0.1';
        $port = $cfg['db_port'] ?? 3306;
        $user = $cfg['db_user'] ?? 'root';
        $pass = $cfg['db_pass'] ?? '';
        $prefix = $cfg['db_prefix'] ?? 'botsaz_';
        $dbName = $prefix . 'manager';
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // بدون سقف، اتصال به MySQLِ خاموش تا ۶۰ ثانیه کل درخواست را نگه می‌دارد
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci");
        $pdo->exec("USE `{$dbName}`");
        $this->pdo = $pdo;
        $this->driver = 'mysql';
        $this->schemaId = 'mysql:' . $host . ':' . $port . ':' . $dbName;
        $this->initMysql();
    }

    /** شناسهٔ یکتای این دیتابیس — ماژول‌ها برای کش «اسکیما آماده است» ازش استفاده می‌کنند */
    public function getSchemaId(): string { return $this->schemaId; }

    public function getDriver(): string { return $this->driver; }

    private function initSqlite(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            first_name TEXT DEFAULT '',
            username TEXT DEFAULT '',
            is_admin INTEGER DEFAULT 0,
            is_allowed INTEGER DEFAULT 0,
            step TEXT DEFAULT 'idle',
            temp TEXT DEFAULT '{}',
            build_count INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS bots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            folder TEXT NOT NULL UNIQUE,
            token TEXT NOT NULL,
            bot_username TEXT DEFAULT '',
            bot_id INTEGER DEFAULT 0,
            admin_id INTEGER DEFAULT 0,
            db_name TEXT DEFAULT '',
            db_table_prefix TEXT DEFAULT '',
            webhook_url TEXT DEFAULT '',
            status TEXT DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS pending_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at TEXT DEFAULT (datetime('now')),
            handled_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(user_id)
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS processed_updates (
            update_id INTEGER PRIMARY KEY,
            processed_at TEXT DEFAULT (datetime('now'))
        )");
        // ارتقای دیتابیس‌های قدیمی (ساخته‌شده قبل از ستون build_count)
        $cols = array_column($this->pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('build_count', $cols, true)) {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN build_count INTEGER DEFAULT 0");
        }
        $this->initSchemaVersions();
    }

    private function initMysql(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            user_id BIGINT PRIMARY KEY,
            first_name VARCHAR(100) DEFAULT '',
            username VARCHAR(100) DEFAULT '',
            is_admin TINYINT DEFAULT 0,
            is_allowed TINYINT DEFAULT 0,
            step VARCHAR(40) DEFAULT 'idle',
            temp TEXT,
            build_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS bots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id BIGINT NOT NULL,
            type VARCHAR(20) NOT NULL,
            folder VARCHAR(60) NOT NULL UNIQUE,
            token TEXT NOT NULL,
            bot_username VARCHAR(100) DEFAULT '',
            bot_id BIGINT DEFAULT 0,
            admin_id BIGINT DEFAULT 0,
            db_name VARCHAR(100) DEFAULT '',
            db_table_prefix VARCHAR(100) DEFAULT '',
            webhook_url TEXT,
            status VARCHAR(20) DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY (owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS pending_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            type VARCHAR(20) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            handled_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS processed_updates (
            update_id BIGINT PRIMARY KEY,
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // ارتقای دیتابیس‌های قدیمی
        $st = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'build_count'");
        if ($st->fetch() === false) {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN build_count INT DEFAULT 0");
        }
        $this->initSchemaVersions();
    }

    // ---- users ----
    public function user(int $uid, string $first = '', string $username = ''): array
    {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE user_id=?");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            $st = $this->pdo->prepare("INSERT INTO users (user_id, first_name, username) VALUES (?,?,?)");
            $st->execute([$uid, $first, $username]);
            $st = $this->pdo->prepare("SELECT * FROM users WHERE user_id=?");
            $st->execute([$uid]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: ['user_id' => $uid, 'first_name' => $first, 'username' => $username, 'is_admin' => 0, 'is_allowed' => 0, 'step' => 'idle', 'temp' => '{}', 'build_count' => 0, 'created_at' => ''];
        }
        if (($first && $u['first_name'] !== $first) || ($username && $u['username'] !== $username)) {
            $st = $this->pdo->prepare("UPDATE users SET first_name=?, username=? WHERE user_id=?");
            $st->execute([$first ?: $u['first_name'], $username ?: $u['username'], $uid]);
            $u['first_name'] = $first ?: $u['first_name'];
            $u['username'] = $username ?: $u['username'];
        }
        return $u;
    }

    public function setStep(int $uid, string $step, array $temp = []): void
    {
        if (!empty($temp)) {
            $u = $this->user($uid);
            $old = json_decode($u['temp'] ?? '{}', true) ?: [];
            $temp = array_merge($old, $temp);
        }
        $st = $this->pdo->prepare("UPDATE users SET step=?, temp=? WHERE user_id=?");
        $st->execute([$step, json_encode($temp, JSON_UNESCAPED_UNICODE), $uid]);
    }

    /** حذف کامل کاربر (برای پاک‌سازی داده‌های تست مثل selftest) */
    public function deleteUser(int $uid): void
    {
        $st = $this->pdo->prepare("DELETE FROM users WHERE user_id=?");
        $st->execute([$uid]);
    }

    public function clearStep(int $uid): void
    {
        $st = $this->pdo->prepare("UPDATE users SET step='idle', temp='{}' WHERE user_id=?");
        $st->execute([$uid]);
    }

    public function setAllowed(int $uid, int $allowed, int $isAdmin = -1): void
    {
        if ($isAdmin === -1) {
            $current = $this->user($uid);
            $isAdmin = (int)$current['is_admin'];
        }
        $this->user($uid);
        $st = $this->pdo->prepare("UPDATE users SET is_allowed=?, is_admin=? WHERE user_id=?");
        $st->execute([$allowed, $isAdmin, $uid]);
    }

    public function isUpdateProcessed(int $updateId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM processed_updates WHERE update_id = ?");
        $st->execute([$updateId]);
        return $st->fetch() !== false;
    }

    public function markUpdateProcessed(int $updateId): void
    {
        if ($this->driver === 'mysql') {
            $st = $this->pdo->prepare("INSERT INTO processed_updates (update_id) VALUES (?) ON DUPLICATE KEY UPDATE update_id = update_id, processed_at = NOW()");
        } else {
            $st = $this->pdo->prepare("INSERT OR REPLACE INTO processed_updates (update_id) VALUES (?)");
        }
        $st->execute([$updateId]);
    }

    /** نگه‌داشتن حداکثر $keep رد update قدیمی — جدول processed_updates را بی‌انتها نگه نمی‌دارد */
    public function pruneProcessedUpdates(int $keep = 5000): int
    {
        $keep = max(1, $keep);
        if ($this->driver === 'mysql') {
            $st = $this->pdo->prepare("DELETE FROM processed_updates WHERE update_id NOT IN (SELECT update_id FROM (SELECT update_id FROM processed_updates ORDER BY update_id DESC LIMIT {$keep}) t)");
        } else {
            $st = $this->pdo->prepare("DELETE FROM processed_updates WHERE update_id NOT IN (SELECT update_id FROM processed_updates ORDER BY update_id DESC LIMIT {$keep})");
        }
        $st->execute();
        return $st->rowCount();
    }

    private function nowSql(): string
    {
        return $this->driver === 'mysql' ? 'NOW()' : "datetime('now')";
    }

    public function allowedIds(): array
    {
        return $this->pdo->query("SELECT user_id FROM users WHERE is_allowed=1")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function allUserIds(): array
    {
        return $this->pdo->query("SELECT user_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function countUsers(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }

    // ---- bots ----
    public function addBot(array $b): int
    {
        $st = $this->pdo->prepare("INSERT INTO bots
            (owner_id,type,folder,token,bot_username,bot_id,admin_id,db_name,db_table_prefix,webhook_url,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        try {
            $st->execute([
                $b['owner_id'], $b['type'], $b['folder'], $b['token'], $b['bot_username'] ?? '',
                $b['bot_id'] ?? 0, $b['admin_id'] ?? 0, $b['db_name'] ?? '', $b['db_table_prefix'] ?? '',
                $b['webhook_url'] ?? '', $b['status'] ?? 'active',
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // Duplicate folder (UNIQUE constraint violation)
            if ($e->getCode() == '23000') {
                throw new Exception("پوشه '{$b['folder']}' قبلاً ثبت شده است");
            }
            throw $e;
        }
    }

    public function myBots(int $owner): array
    {
        $st = $this->pdo->prepare("SELECT * FROM bots WHERE owner_id=? ORDER BY id DESC");
        $st->execute([$owner]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allBots(): array
    {
        return $this->pdo->query("SELECT * FROM bots ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function botById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM bots WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function botByFolder(string $folder): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM bots WHERE folder=?");
        $st->execute([$folder]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function setBotStatus(int $id, string $status): void
    {
        $st = $this->pdo->prepare("UPDATE bots SET status=? WHERE id=?");
        $st->execute([$status, $id]);
    }

    public function deleteBot(int $id): void
    {
        $st = $this->pdo->prepare("DELETE FROM bots WHERE id=?");
        $st->execute([$id]);
    }

    public function countBots(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM bots")->fetchColumn();
    }

    // ---- pending requests ----
    public function addPendingRequest(int $userId, string $type): int
    {
        $st = $this->pdo->prepare("INSERT INTO pending_requests (user_id, type) VALUES (?, ?)");
        $st->execute([$userId, $type]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getPendingRequests(): array
    {
        return $this->pdo->query("SELECT pr.*, u.first_name, u.username, u.user_id FROM pending_requests pr JOIN users u ON pr.user_id = u.user_id WHERE pr.status = 'pending' ORDER BY pr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPendingRequestByUser(int $userId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM pending_requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
        $st->execute([$userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getPendingRequestById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM pending_requests WHERE id = ? AND status = 'pending'");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function approveRequest(int $requestId): bool
    {
        $st = $this->pdo->prepare("UPDATE pending_requests SET status = 'approved', handled_at = {$this->nowSql()} WHERE id = ? AND status = 'pending'");
        $st->execute([$requestId]);
        return $st->rowCount() > 0;
    }

    public function declineRequest(int $requestId): bool
    {
        $st = $this->pdo->prepare("UPDATE pending_requests SET status = 'declined', handled_at = {$this->nowSql()} WHERE id = ? AND status = 'pending'");
        $st->execute([$requestId]);
        return $st->rowCount() > 0;
    }

    /** درخواست تأییدشده و هنوز استفاده‌نشده (برای ادامه ساخت ربات) */
    public function getApprovedRequestByUser(int $userId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM pending_requests WHERE user_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 1");
        $st->execute([$userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function hasApprovedRequest(int $userId): bool
    {
        return $this->getApprovedRequestByUser($userId) !== null;
    }

    /** بعد از ساخت موفق ربات: درخواست تأییدشده مصرف شود تا دوباره استفاده نشود */
    public function markRequestUsed(int $requestId): void
    {
        $st = $this->pdo->prepare("UPDATE pending_requests SET status = 'used', handled_at = {$this->nowSql()} WHERE id = ? AND status = 'approved'");
        $st->execute([$requestId]);
    }

    public function hasBot(int $userId): bool
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM bots WHERE owner_id = ?");
        $st->execute([$userId]);
        return (int)$st->fetchColumn() > 0;
    }

    public function hasPendingRequest(int $userId): bool
    {
        return $this->getPendingRequestByUser($userId) !== null;
    }

    public function countPendingRequests(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM pending_requests WHERE status = 'pending'")->fetchColumn();
    }

    // ---- build quota ----
    public function incrementBuildCount(int $userId): void
    {
        $st = $this->pdo->prepare("UPDATE users SET build_count = build_count + 1 WHERE user_id = ?");
        $st->execute([$userId]);
    }

    // ---- schema versions ----
    public function initSchemaVersions(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, version INTEGER NOT NULL, applied_at TEXT DEFAULT (datetime('now')))");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_versions (id INT AUTO_INCREMENT PRIMARY KEY, version INT NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        }
        // Run migrations — خطای migration مرگبار نیست ولی باید در لاگ بماند
        try {
            $migrator = new Migrator($this->pdo, $this->driver);
            $results = $migrator->migrate();
            if (!empty($results)) {
                error_log('Migrations applied: ' . implode(', ', array_keys($results)));
            }
        } catch (Exception $e) {
            error_log('Migration failed: ' . $e->getMessage());
        }
    }

    // ---- settings (key-value برای تنظیمات ران‌تایم مثل ساعت بکاپ) ----
    // جدول اگر نباشد ساخته می‌شود؛ پس روی دیتابیس‌های قدیمی هم بدون مایگریشن کار می‌کند.
    public function getSetting(string $key, ?string $default = null): ?string
    {
        $this->ensureSettingsTable();
        $st = $this->pdo->prepare("SELECT v FROM settings WHERE k=?");
        $st->execute([$key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? (string)$r['v'] : $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $this->ensureSettingsTable();
        if ($this->driver === 'mysql') {
            $st = $this->pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
        } else {
            $st = $this->pdo->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)");
        }
        $st->execute([$key, $value]);
    }

    private function ensureSettingsTable(): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(60) PRIMARY KEY,
                v TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                k TEXT PRIMARY KEY,
                v TEXT DEFAULT '',
                updated_at TEXT DEFAULT (datetime('now'))
            )");
        }
    }

    public function getPdo(): PDO { return $this->pdo; }
}
