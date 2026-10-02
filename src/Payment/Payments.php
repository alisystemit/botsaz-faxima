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

    /**
     * بیشترین تعداد فاکتورِ «باز» مجاز برای هر کاربر.
     * بدون سقف، کاربری که فاکتور می‌سازد و رها می‌کند می‌توانست با یک کلیک
     * هزاران ردیف در دیتابیس بسازد.
     */
    public const MAX_OPEN_PAYMENTS = 5;

    /**
     * کلید کش «اسکیما ساخته شد» به تفکیک دیتابیس.
     * بدون این کش، هر متد پرداخت (که چند بار در هر پیام صدا زده می‌شد) یک
     * CREATE TABLE IF NOT EXISTS + PRAGMA/SHOW COLUMNS می‌فرستاد؛ یعنی ده‌ها
     * رفت‌وبرگشت اضافه به دیتابیس برای هر پیام کاربر.
     */
    private static array $schemaReady = [];

    /** ساخت جدول/ستون در صورت نبودن (sqlite + mysql) */
    public static function ensureSchema(Store $store): void
    {
        $pdo = $store->getPdo();
        $driver = $store->getDriver();
        $cacheKey = $store->getSchemaId();
        if (isset(self::$schemaReady[$cacheKey])) return;
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
            // ایندکس ext_id لازم است: fallbackهای IPN و «🔄 بررسی وضعیت» با همین ستون جست‌وجو می‌کنند
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pay_ext ON payments(ext_id)");
            try {
                $cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
                if (!in_array('bot_limit', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN bot_limit INTEGER DEFAULT 1");
            } catch (Throwable $e) { /* نادیده */ }
        }
        // فقط وقتی علامت می‌زنیم که ساخت جدول واقعاً موفق بوده؛
        // اگر CREATE خطا بدهد، درخواست بعدی دوباره تلاش می‌کند.
        self::$schemaReady[$cacheKey] = true;
    }

    /** فقط برای تست: کش اسکیما را پاک می‌کند */
    public static function resetSchemaCache(): void
    {
        self::$schemaReady = [];
    }

    private static function nowSql(Store $store): string
    {
        return $store->getDriver() === 'mysql' ? 'NOW()' : "datetime('now')";
    }

    // ---------- ابزار متن/عدد ----------

    /**
     * ارقام فارسی (۰-۹) و عربی (٠-٩) را به لاتین تبدیل می‌کند و
     * علامت‌های منفی نمایشی/عربی را هم به «-» می‌آورد.
     * بدون این کار، ادمینی که «۵۰۰۰۰» یا «۱۰۰٬۰۰۰» تایپ کند
     * با پیام «فقط عدد بفرستید» روبه‌رو می‌شد.
     */
    public static function normalizeDigits(string $s): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            // جداکنندهٔ هزارگان فارسی/عربی حذف، اعشار به نقطه
            '٬' => '', '،' => '', ',' => '', '٫' => '.', ' ' => ' ',
            '−' => '-', '–' => '-', '—' => '-',
        ];
        return strtr($s, $map);
    }

    /**
     * عدد اعشاری از ورودی آزاد ادمین (ارقام فارسی + جداکنندهٔ هزارگان).
     * خروجی null یعنی ورودی اصلاً عدد نبود.
     */
    public static function toNumber(string $s): ?float
    {
        $t = self::normalizeDigits($s);
        $t = preg_replace('/[^0-9.\-]/', '', $t) ?? '';
        if ($t === '' || !preg_match('/-?\d*\.?\d+/', $t)) return null;
        return (float)$t;
    }

    /** فقط رقم (بعد از نرمال‌سازی)؛ برای قیمت‌ها و مبالغ */
    public static function digitsOnly(string $s): string
    {
        return preg_replace('/[^0-9]/', '', self::normalizeDigits($s));
    }

    /**
     * استخراج عدد صحیح از ورودی آزاد ادمین؛ منفی هم می‌پذیرد.
     * خروجی null یعنی ورودی اصلاً عدد نبود.
     */
    public static function parseIntLoose(string $s): ?int
    {
        $t = self::normalizeDigits($s);
        if (!preg_match('/-?\d+/', $t, $m)) return null;
        return (int)$m[0];
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

    /**
     * افزایش/کاهش اتمیک سقف کاربر.
     * نسخهٔ قبلی «بخوان، بعد بنویس» بود؛ دو IPN همزمان (که NOWPayments
     * به‌صورت طبیعی تکرار می‌فرستد) هر دو یک مقدار می‌خواندند و یکی از
     * افزایش‌ها گم می‌شد. اینجا خودِ دیتابیس جمع می‌زند.
     * مقدار -1 یعنی نامحدود و دست‌نخورده می‌ماند.
     */
    public static function addUserLimit(Store $store, int $uid, int $delta): int
    {
        self::ensureSchema($store);
        $cur = self::getUserLimit($store, $uid);
        if ($cur < 0) return $cur; // نامحدود می‌ماند
        $new = max(0, $cur + $delta);
        // CASE WHEN تا به‌ازای هر سطر سقفِ منفی (نامحدود) تبدیل به عدد نشود
        $sql = "UPDATE users SET bot_limit = CASE WHEN bot_limit < 0 THEN bot_limit ELSE MAX(0, bot_limit + ?) END WHERE user_id=?";
        if ($store->getDriver() === 'sqlite') {
            $sql = "UPDATE users SET bot_limit = CASE WHEN bot_limit < 0 THEN bot_limit ELSE MAX(0, COALESCE(bot_limit,0) + ?) END WHERE user_id=?";
        }
        $st = $store->getPdo()->prepare($sql);
        $st->execute([$delta, $uid]);
        return self::getUserLimit($store, $uid);
    }

    // ---------- پرداخت‌ها ----------

    /**
     * مبلغ موردنیاز برای ساخت: قیمت قالب + اگر سقف پر است قیمت یک اسلات.
     * خروجی: ['need_limit'=>bool, 'need_template'=>bool, 'blocked'=>bool, 'amount'=>int, 'slots'=>int]
     *
     * نکتهٔ 'blocked': کاربر مسدود (bot_limit=0) نباید فاکتور ساخت ببیند؛ چون
     * پرداختِ او سقف را از ۰ به ۱ می‌برد و عملاً مسدودی ادمین را دور می‌زند.
     */
    public static function requiredForBuild(Store $store, array $user, string $type, array $supers = []): array
    {
        // اگر درگاه لیمیت غیرفعال باشد، نیاز به لیمیت ندارد
        $limitEnabled = PaymentLimits::isActive($store);
        // اگر درگاه قالب غیرفعال باشد، نیاز به ووچر ندارد
        $templateEnabled = PaymentPricing::isActive($store);
        $blocked = PaymentLimits::isBlocked($store, $user, $supers);

        $needLimit = $blocked ? false : ($limitEnabled ? !PaymentLimits::canBuild($store, $user, $supers) : false);
        $needTemplate = $templateEnabled
            && PaymentPricing::isPaid($store, $type)
            && !PaymentLimits::isAdminUnlimited($user, $supers)
            && self::countUsableTemplateVoucher($store, (int)$user['user_id'], $type) <= 0;
        // کاربر مسدود فاکتور نمی‌گیرد؛ فقط باید پیام مسدودی را ببیند
        if ($blocked) $needTemplate = false;
        $amount = 0;
        if ($needTemplate) $amount += PaymentPricing::templatePrice($store, $type);
        if ($needLimit) $amount += PaymentPricing::limitUnitPrice($store); // یک اسلات
        return [
            'need_limit' => $needLimit,
            'need_template' => $needTemplate,
            'blocked' => $blocked,
            'amount' => $amount,
            'slots' => $needLimit ? 1 : 0,
        ];
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
        // kind=limit برای حالت «فقط اسلات» و حالت مرکب (اسلات + قالب با هم)
        // تا grant یک‌بار هر دو اثر را اعمال کند؛ فقط وقتی قالب لازم است و اسلات نه، kind=template.
        $kind = (!empty($req['need_limit']) || empty($req['need_template'])) ? self::KIND_LIMIT : self::KIND_TEMPLATE;
        $tpl = !empty($req['need_template']) ? $type : '';
        return self::createPayment($store, $uid, $kind, $tpl, (int)($req['slots'] ?? 0), (int)($req['amount'] ?? 0), $method);
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

    /**
     * صف بررسی ادمین.
     * «pending» عمداً نیست: آن وضعیت یعنی فاکتور ساخته شده ولی کاربر هیچ
     * روشی انتخاب نکرده (یا رها کرده) — نه چیزی برای تأیید، نه چیزی که
     * باید صف ادمین را پر کند. فقط چیزی می‌آید که واقعاً منتظر اقدام است.
     */
    public static function pendingAdminList(Store $store, int $limit = 20): array
    {
        self::ensureSchema($store);
        $limit = max(1, min(50, $limit));
        $st = $store->getPdo()->query(
            "SELECT * FROM payments WHERE status IN ('await_admin','await_receipt','await_pay') ORDER BY id DESC LIMIT {$limit}"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * شمار فاکتورهای صف ادمین — برای شمارندهٔ منوی اصلی.
     * منوی اصلی روی هر پیام ساخته می‌شود؛ کشیدن ۵۰ ردیف کامل برای فقط شمارش،
     * بار دیتابیس را بی‌دلیل بالا می‌برد.
     */
    public static function pendingAdminCount(Store $store): int
    {
        try { self::ensureSchema($store); } catch (Throwable $e) { return 0; }
        $st = $store->getPdo()->query(
            "SELECT COUNT(*) FROM payments WHERE status IN ('await_admin','await_receipt','await_pay')"
        );
        return (int)$st->fetchColumn();
    }

    /** شمار فاکتورهای باز (هنوز نهایی/لغو نشده) یک کاربر */
    public static function openPaymentCount(Store $store, int $uid): int
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare(
            "SELECT COUNT(*) FROM payments WHERE user_id=? AND status IN (?,?,?,?)"
        );
        $st->execute([$uid, self::ST_PENDING, self::ST_AWAIT_RECEIPT, self::ST_AWAIT_ADMIN, self::ST_AWAIT_PAY]);
        return (int)$st->fetchColumn();
    }

    /**
     * فاکتورِ بازِ هم‌مورد (همان قالب/همان مبلغ) برای «ساخت ربات».
     *
     * بدون این، هر کلیک روی «ساخت ربات جدید» یک ردیف تازه می‌ساخت و جدول
     * payments را با فاکتورهای رهاشده پر می‌کرد. قالب خالی یعنی فقط اسلات لیمیت
     * خریداری می‌شود و با هر قالبی می‌تواند هم‌مورد باشد (مبلغ یکسان است).
     *
     * @return array|null آخرین فاکتور باز، یا null اگر چیزی پیدا نشد
     */
    public static function findOpenBuildPayment(Store $store, int $uid, string $type, int $amount): ?array
    {
        if ($amount <= 0) return null;
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare(
            "SELECT * FROM payments WHERE user_id=? AND amount=? AND status IN (?,?,?) ORDER BY id DESC LIMIT 20"
        );
        $st->execute([$uid, $amount, self::ST_PENDING, self::ST_AWAIT_RECEIPT, self::ST_AWAIT_PAY]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $tpl = trim((string)($p['template'] ?? ''));
            if ($tpl === '' || $tpl === $type) return $p;
        }
        return null;
    }

    public static function setMethod(Store $store, int $id, string $method, string $status, string $extId = '', string $payUrl = ''): void
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare("UPDATE payments SET method=?, status=?, ext_id=?, pay_url=? WHERE id=?");
        $st->execute([$method, $status, $extId, $payUrl, $id]);
    }

    /**
     * ثبت رسید و بردن پرداخت به صف بررسی ادمین.
     * گارد وضعیت لازم است: بدون آن، رسیدی که بعد از لغو/رد فرستاده شود
     * پرداخت مرده را دوباره زنده می‌کرد و ادمین نادیده می‌گرفتش.
     */
    public static function setReceipt(Store $store, int $id, string $receipt): bool
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare(
            "UPDATE payments SET receipt=?, status=? WHERE id=? AND status IN (?,?)"
        );
        $st->execute([$receipt, self::ST_AWAIT_ADMIN, $id, self::ST_AWAIT_RECEIPT, self::ST_PENDING]);
        return $st->rowCount() > 0;
    }

    /** ثبت/به‌روزرسانی شناسهٔ بیرونی سرویس (invoice_id یا payment_id) */
    public static function setExtId(Store $store, int $id, string $extId): void
    {
        self::ensureSchema($store);
        $st = $store->getPdo()->prepare("UPDATE payments SET ext_id=? WHERE id=?");
        $st->execute([$extId, $id]);
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
        if ($tpl !== '') $notes[] = "مجوز ساخت «" . htmlspecialchars($tpl) . "» صادر شد";
        if ($notes === []) $notes[] = "ثبت شد";
        return implode('، ', $notes);
    }

    /**
     * تأیید ادمین برای کارت‌به‌کارت: paid + grant
     *
     * دو نکته که قبلاً غلط بود:
     * ۱) «pending» (فاکتوری که کاربر هرگز روشی برایش انتخاب نکرد) هم تأییدپذیر
     *    بود؛ یعنی یک ردیف رهاشده را می‌شد تأیید کرد و رایگان اسلات داد.
     * ۲) بررسی وضعیت و سپس setStatus جدا بود؛ دو کلیک همزمان روی «تأیید»
     *    هر دو از گارد رد می‌شدند و لیمیت را دوبار اضافه می‌کردند.
     *    حالا UPDATE خودش شرط وضعیت دارد و فقط کسی که واقعاً سطر را برداشته grant می‌کند.
     */
    public static function approveByAdmin(Store $store, int $id): ?array
    {
        self::ensureSchema($store);
        $now = self::nowSql($store);
        $st = $store->getPdo()->prepare(
            "UPDATE payments SET status=?, paid_at={$now}, handled_at={$now} WHERE id=? AND status IN (?,?)"
        );
        $st->execute([self::ST_PAID, $id, self::ST_AWAIT_ADMIN, self::ST_AWAIT_RECEIPT]);
        if ($st->rowCount() === 0) return null;
        $p = self::getPayment($store, $id);
        if (!$p) return null;
        $p['grant_note'] = self::grant($store, $p);
        return $p;
    }

    /**
     * پرداخت کریپتویی که IPN/«بررسی وضعیت» تأییدش کرد.
     * idempotent و اتمیک: فقط گذار اولیهٔ وضعیت grant می‌کند، پس IPN تکراری
     * (که NOWPayments عادی است) هیچ اثری ندارد.
     * اگر پرداخت لغو/رد شده باشد null برمی‌گرداند تا IPN چیزی احیا نکند.
     */
    public static function markCryptoPaid(Store $store, int $id): ?array
    {
        self::ensureSchema($store);
        $now = self::nowSql($store);
        $st = $store->getPdo()->prepare(
            "UPDATE payments SET status=?, paid_at={$now}, handled_at={$now} WHERE id=? AND status IN (?,?)"
        );
        $st->execute([self::ST_PAID, $id, self::ST_AWAIT_PAY, self::ST_PENDING]);
        if ($st->rowCount() === 0) {
            // اگر قبلاً پرداخت شده، گزارش وضعیت نهایی را برمی‌گردانیم (idempotent)
            $p = self::getPayment($store, $id);
            if ($p && in_array($p['status'], [self::ST_PAID, self::ST_USED], true)) return $p;
            return null;
        }
        $p = self::getPayment($store, $id);
        if (!$p) return null;
        $p['grant_note'] = self::grant($store, $p);
        return $p;
    }

    /**
     * یک خط خلاصه از وضعیت یک پرداخت.
     * خروجی HTML است (پیام‌ها parse_mode=HTML دارند) ⇒ هر فیلدِ دیتابیس escape می‌شود.
     */
    public static function describe(array $p): string
    {
        $stMap = [
            'pending' => '⏳ در انتظار', 'await_receipt' => '🧾 در انتظار فیش',
            'await_admin' => '👀 نزد ادمین', 'await_pay' => '🪙 در انتظار پرداخت کریپتو',
            'paid' => '✅ پرداخت‌شده', 'used' => '🎟️ مصرف‌شده',
            'declined' => '❌ رد شده', 'cancelled' => '🚫 لغو شده', 'expired' => '⌛ منقضی',
        ];
        $raw = (string)($p['status'] ?? '');
        $st = $stMap[$raw] ?? ($raw !== '' ? $raw : 'نامشخص');
        $kind = ($p['kind'] ?? '') === 'template' ? 'قالب' : 'لیمیت';
        $tpl = (string)($p['template'] ?? '') !== '' ? ' (' . htmlspecialchars((string)$p['template']) . ')' : '';
        $slots = (int)($p['slots'] ?? 0) > 0 ? " [{$p['slots']} اسلات]" : '';
        return "#{$p['id']} {$kind}{$tpl}{$slots} — " . number_format((int)($p['amount'] ?? 0)) . " تومان — " . htmlspecialchars($st);
    }
}
