<?php
// ===== نمای اصلی پرداخت (facade) =====
// همهٔ منطق دیتابیسی پرداخت‌ها اینجاست تا bot.php و IPN فقط همین کلاس را صدا بزنند.
// جدول payments + ستون users.bot_limit به‌صورت lazy ساخته می‌شوند،
// پس حتی اگر مایگریشن اجرا نشده باشد هم کار می‌کند (Migrator v6 همان را رسمی می‌کند).

class Payments
{
    public const KIND_LIMIT = 'limit';
    public const KIND_TEMPLATE = 'template';

    public const METHOD_CARD = 'card';
    public const METHOD_NOWPAY = 'nowpay';

    // pending=ساخته‌شده، await_receipt=در انتظار فیش، await_admin=نزد ادمین،
    // await_pay=فاکتور کریپتو صادر شده، paid=پرداخت‌شده، used=ووچر مصرف‌شده،
    // declined=رد شده، cancelled/expired=لغو/منقضی
    public const ST_PENDING = 'pending';
    public const ST_AWAIT_RECEIPT = 'await_receipt';
    public const ST_AWAIT_ADMIN = 'await_admin';
    public const ST_AWAIT_PAY = 'await_pay';
    public const ST_PAID = 'paid';
    public const ST_USED = 'used';
    public const ST_DECLINED = 'declined';
    public const ST_CANCELLED = 'cancelled';
    public const ST_EXPIRED = 'expired';

    /** ساخت جدول/ستون در صورت نبودن (sqlite + mysql) */
    public static function ensureSchema(Store $store): void
    {
        $pdo = $store->getPdo();
        $driver = $store->getDriver();
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
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
            try {
                $st = $pdo->query("SHOW COLUMNS FROM users LIKE 'bot_limit'");
                if ($st->fetch() === false) $pdo->exec("ALTER TABLE users ADD COLUMN bot_limit INT DEFAULT 1");
            } catch (Throwable $e) { /* ستون احتمالاً هست */ }
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
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
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pay_user ON payments(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pay_status ON payments(status)");
            try {
                $cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
                if (!in_array('bot_limit', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN bot_limit INTEGER DEFAULT 1");
            } catch (Throwable $e) { /* نادیده */ }
        }
    }

    private static function nowSql(Store $store): string
    {
        return $store->getDriver() === 'mysql' ? 'NOW()' : "datetime('now')";
    }

    // ---------- لیمیت کاربر ----------

    public static function getUserLimit(Store $store, int $uid): int
    {
        self::ensureSchema($store);
        try {
            $st = $store->getPdo()->prepare("SELECT bot_limit FROM users WHERE user_id=?");
            $st->execute([$uid]);
            $v = $st->fetchColumn();
            if ($v === false || $v === null) return PaymentLimits::DEFAULT_LIMIT;
            return (int)$v;
        } catch (Throwable $e) {
            return PaymentLimits::DEFAULT_LIMIT;
        }
    }

    public static function setUserLimit(Store $store, int $uid, int $limit): void
    {
        self::ensureSchema($store);
        $store->user($uid);
        $st = $store->getPdo()->prepare("UPDATE users SET bot_limit=? WHERE user_id=?");
        $st->execute([$limit, $uid]);
    }

    public static function addUserLimit(Store $store, int $uid, int $delta): int
    {
        self::ensureSchema($store);
        $cur = self::getUserLimit($store, $uid);
        if ($cur < 0) return $cur; // نامحدود می‌ماند
        $new = max(0, $cur + $delta);
        self::setUserLimit($store, $uid, $new);
        return $new;
    }

    // ---------- پرداخت‌ها ----------

    /** مبلغ موردنیاز برای ساخت: max(قیمت قالب، 0) + اگر سقف پر است قیمت اسلات */
    public static function requiredForBuild(Store $store, array $user, string $type, array $supers = []): array
    {
        // اگر درگاه لیمیت غیرفعال باشد، نیاز به لیمیت ندارد
        $limitEnabled = PaymentLimits::isActive($store);
        // اگر درگاه قالب غیرفعال باشد، نیاز به ووچر ندارد
        $templateEnabled = PaymentPricing::isActive($store);
        
        $needLimit = !$limitEnabled ? false : !PaymentLimits::canBuild($store, $user, $supers);
        $needTemplate = $templateEnabled
            && PaymentPricing::isPaid($store, $type)
            && !PaymentLimits::isAdminUnlimited($user, $supers)
            && self::countUsableTemplateVoucher($store, (int)$user['user_id'], $type) <= 0;
        $amount = 0;
        if ($needTemplate) $amount += PaymentPricing::templatePrice($store, $type);
        if ($needLimit) $amount += PaymentPricing::limitUnitPrice($store); // یک اسلات
        return ['need_limit' => $needLimit, 'need_template' => $needTemplate, 'amount' => $amount, 'slots' => $needLimit ? 1 : 0];
    }

    public static function createPayment(Store $store, int $uid, string $kind, string $template, int $slots, int $amount, string $method = ''): int
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare(
            "INSERT INTO payments (user_id, kind, template, slots, amount, method, status) VALUES (?,?,?,?,?,?,?)"
        );
        $st->execute([$uid, $kind, $template, $slots, $amount, $method, self::ST_PENDING]);
        return (int)$store->getPdo()->lastInsertId();
    }

    /** پرداخت مرکب ساخت ربات: هم اسلات (اگر لازم) هم ووچر قالب (اگر لازم) در یک ردیف */
    public static function createBuildPayment(Store $store, int $uid, string $type, array $req, string $method): int
    {
        $kind = $req['need_limit'] && !$req['need_template'] ? self::KIND_LIMIT
            : (!$req['need_limit'] && $req['need_template'] ? self::KIND_TEMPLATE : self::KIND_LIMIT);
        // اگر هر دو لازم است، kind=limit با template پر می‌شود تا grant هر دو را اعمال کند
        $tpl = $req['need_template'] ? $type : '';
        return self::createPayment($store, $uid, $kind, $tpl, $req['slots'], $req['amount'], $method);
    }

    public static function getPayment(Store $store, int $id): ?array
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare("SELECT * FROM payments WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function getPaymentByExtId(Store $store, string $extId): ?array
    {
        self::ensureSchema($store);
        if ($extId === '') return null;
        $st = $store->getPdo()->prepare("SELECT * FROM payments WHERE ext_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$extId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function getPaymentByOrderId(Store $store, string $orderId): ?array
    {
        // order_id ما همان "PAY-<id>" است
        if (preg_match('/^PAY-(\d+)$/', trim($orderId), $m)) return self::getPayment($store, (int)$m[1]);
        return null;
    }

    public static function userPayments(Store $store, int $uid, int $limit = 10): array
    {
        self::ensureSchema($store);
        $limit = max(1, min(50, $limit));
        $st = $store->getPdo()->prepare("SELECT * FROM payments WHERE user_id=? ORDER BY id DESC LIMIT {$limit}");
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function pendingAdminList(Store $store, int $limit = 20): array
    {
        self::ensureSchema($store);
        $limit = max(1, min(50, $limit));
        $st = $store->getPdo()->query(
            "SELECT * FROM payments WHERE status IN ('await_admin','await_receipt','await_pay','pending') ORDER BY id DESC LIMIT {$limit}"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setMethod(Store $store, int $id, string $method, string $status, string $extId = '', string $payUrl = ''): void
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare("UPDATE payments SET method=?, status=?, ext_id=?, pay_url=? WHERE id=?");
        $st->execute([$method, $status, $extId, $payUrl, $id]);
    }

    public static function setReceipt(Store $store, int $id, string $receipt): void
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare("UPDATE payments SET receipt=?, status=? WHERE id=?");
        $st->execute([$receipt, self::ST_AWAIT_ADMIN, $id]);
    }

    public static function setStatus(Store $store, int $id, string $status): void
    {
        self::ensureSchema($store);
        $now = self::nowSql($store);
        $extra = in_array($status, [self::ST_PAID, self::ST_DECLINED, self::ST_CANCELLED, self::ST_EXPIRED, self::ST_USED], true)
            ? ", handled_at={$now}" : '';
        if ($status === self::ST_PAID) $extra .= ", paid_at={$now}";
        $st = $store->getPdo()->prepare("UPDATE payments SET status=?{$extra} WHERE id=?");
        $st->execute([$status, $id]);
    }

    // ---------- ووچر قالب ----------

    public static function countUsableTemplateVoucher(Store $store, int $uid, string $type): int
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare(
            "SELECT COUNT(*) FROM payments WHERE user_id=? AND status=? AND template=? AND kind IN ('template','limit')"
        );
        // kind=limit با template پر هم ووچر قالب محسوب می‌شود (پرداخت مرکب)
        $st->execute([$uid, self::ST_PAID, $type]);
        return (int)$st->fetchColumn();
    }

    /** مصرف یک ووچر قالب بعد از ساخت موفق ربات؛ false یعنی ووچری نبود */
    public static function consumeTemplateVoucher(Store $store, int $uid, string $type): bool
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare(
            "SELECT id FROM payments WHERE user_id=? AND status=? AND template=? AND kind IN ('template','limit') ORDER BY id ASC LIMIT 1"
        );
        $st->execute([$uid, self::ST_PAID, $type]);
        $id = $st->fetchColumn();
        if ($id === false) return false;
        // فقط بخش قالب مصرف می‌شود؛ اگر ردیف اسلات هم داشت (slots>0) اسلات قبلاً با grant اعمال شده
        // پس کل ردیف used می‌شود مگر اینکه slots مصرف‌نشده داشته باشد — ساده‌سازی: used
        self::setStatus($store, (int)$id, self::ST_USED);
        return true;
    }

    // ---------- grant: اعمال اثر پرداختِ موفق ----------

    /**
     * بعد از paid شدن: لیمیت اضافه کن (slots) — ووچر قالب لازم نیست کاری کند،
     * فقط paid می‌ماند تا موقع ساخت مصرف شود. خروجی متن فارسی اثر اعمال‌شده.
     */
    public static function grant(Store $store, array $payment): string
    {
        $slots = (int)($payment['slots'] ?? 0);
        $tpl = (string)($payment['template'] ?? '');
        $notes = [];
        if ($slots > 0) {
            $new = self::addUserLimit($store, (int)$payment['user_id'], $slots);
            $notes[] = "سقف به " . PaymentLimits::formatLimit($new) . " رسید";
        }
        if ($tpl !== '') $notes[] = "مجوز ساخت «{$tpl}» صادر شد";
        if ($notes === []) $notes[] = "ثبت شد";
        return implode('، ', $notes);
    }

    /** تأیید ادمین برای کارت‌به‌کارت: paid + grant */
    public static function approveByAdmin(Store $store, int $id): ?array
    {
        $p = self::getPayment($store, $id);
        if (!$p || !in_array($p['status'], [self::ST_AWAIT_ADMIN, self::ST_AWAIT_RECEIPT, self::ST_PENDING], true)) return null;
        self::setStatus($store, $id, self::ST_PAID);
        $p = self::getPayment($store, $id);
        $note = self::grant($store, $p);
        $p['grant_note'] = $note;
        return $p;
    }

    /** پرداخت کریپتویی که IPN/confirmed آمده: idempotent — اگر قبلاً paid/used بود دوباره grant نکن */
    public static function markCryptoPaid(Store $store, int $id): ?array
    {
        $p = self::getPayment($store, $id);
        if (!$p) return null;
        if (in_array($p['status'], [self::ST_PAID, self::ST_USED], true)) return $p; // تکراری
        if (!in_array($p['status'], [self::ST_AWAIT_PAY, self::ST_PENDING], true)) return null;
        self::setStatus($store, $id, self::ST_PAID);
        $p = self::getPayment($store, $id);
        $note = self::grant($store, $p);
        $p['grant_note'] = $note;
        return $p;
    }

    public static function describe(array $p): string
    {
        $stMap = [
            'pending' => '⏳ در انتظار', 'await_receipt' => '🧾 در انتظار فیش',
            'await_admin' => '👀 نزد ادمین', 'await_pay' => '🪙 در انتظار پرداخت کریپتو',
            'paid' => '✅ پرداخت‌شده', 'used' => '🎟️ مصرف‌شده',
            'declined' => '❌ رد شده', 'cancelled' => '🚫 لغو شده', 'expired' => '⌛ منقضی',
        ];
        $st = $stMap[$p['status'] ?? ''] ?? ($p['status'] ?? '');
        $kind = ($p['kind'] ?? '') === 'template' ? 'قالب' : 'لیمیت';
        $tpl = ($p['template'] ?? '') !== '' ? " ({$p['template']})" : '';
        $slots = (int)($p['slots'] ?? 0) > 0 ? " [{$p['slots']} اسلات]" : '';
        return "#{$p['id']} {$kind}{$tpl}{$slots} — " . number_format((int)($p['amount'] ?? 0)) . " تومان — {$st}";
    }
}
