<?php
// ===== نرخ دلار از API (با fallback چند سرویس) =====
//
// نرخ تبدیل تومان↔دلار قبلاً فقط دستی (کادر «💵 نرخ دلار») بود و بعد از هر
// نوسان بازار، فاکتور کریپتویی با نرخ کهنه ساخته می‌شد ⇒ ضرر به فروشنده.
//
// اینجا نرخ از چند سرویس به‌ترتیب امتحان می‌شود و اولین پاسخِ معتبر برنده است:
//
//   ۱) TGJU (بازار آزاد، تومان)  ← رایج‌ترین نرخ برای کاربر ایرانی
//   ۲) open.er-api.com          ← نرخ رسمی
//   ۳) exchangerate-api.com     ← نرخ رسمی (پشتیبان دوم)
//   ۴) نرخ دستیِ ذخیره‌شده      ← اگر همه سرویس‌ها شکست خوردند
//
// دو نکتهٔ مهم:
//  • همهٔ سرویس‌ها «ریال» می‌دهند و ما به «تومان» تقسیم می‌کنیم (۱۰ ریال = ۱ تومان).
//  • بازهٔ معقول (۱۰٬۰۰۰ تا ۵۰۰٬۰۰۰٬۰۰۰ تومان) اعمال می‌شود تا پاسخِ خرابِ یک
//    سرویس (مثل «۰» یا ریال/تومانِ اشتباه) هرگز نرخِ فاکتورها را خراب نکند.
//
// ذخیره‌سازی: نرخ + زمان + نام سرویس در جدول settings. کش ۶ ساعته است تا
// هر بار ساخت فاکتور یک درخواست شبکه اضافه نزنیم.

require_once __DIR__ . '/Ui.php';

class FxRate
{
    /** کلید نرخ ذخیره‌شده (تومان برای ۱ دلار) */
    public const K_RATE    = 'fx_toman_per_usd';
    public const K_UPDATED = 'fx_updated_at';
    public const K_SOURCE  = 'fx_source';
    public const K_FETCHED = 'fx_last_error';
    /** آیا نرخ از API بیاید؟ «۰» یعنی ادمین نرخ را دستی قفل کرده */
    public const K_AUTO    = 'fx_auto';
    /** کلیدِ نرخ دستی (همان کلیدی که کادر «💵 نرخ دلار» می‌نویسد) */
    public const K_MANUAL  = 'pay_toman_per_usd';

    /** نرخ پیش‌فرض اگر هیچ‌چیز نداریم */
    public const FALLBACK_RATE = 100000.0;

    /** عمر کش (ثانیه) */
    public const CACHE_TTL = 6 * 3600;

    /** بازهٔ معقول نرخ (تومان/دلار) — دفاع در برابر پاسخِ خرابِ سرویس */
    private const MIN = 10000.0;
    private const MAX = 500000000.0;

    // ---------- نرخِ ذخیره‌شده ----------

    /**
     * نرخ دستیِ ادمین (بدون شبکه).
     * تا وقتی خودش معتبر باشد برنده است، مگر ادمین «نرخ خودکار» را روشن کرده باشد.
     */
    public static function manual(Store $store): float
    {
        $raw = Payments::toNumber((string)($store->getSetting(self::K_MANUAL, '') ?? ''));
        return ($raw !== null && $raw > 0) ? (float)$raw : 0.0;
    }

    /** آیا نرخ باید از API بیاید؟ (پیش‌فرض: بله) */
    public static function isAuto(Store $store): bool
    {
        $raw = strtolower(trim((string)($store->getSetting(self::K_AUTO, '1') ?? '1')));
        if ($raw === '') return true;
        return !in_array($raw, ['0', 'off', 'false', 'no', 'disabled', 'خاموش'], true);
    }

    /**
     * ادمین یک نرخ را دستی می‌گذارد ⇒ حالت خودکار خاموش و همان نرخ «قفل» می‌شود
     * تا تازه‌سازی خودکار بی‌دلیل دستِ ادمین را عوض نکند.
     */
    public static function setManual(Store $store, float $rate): void
    {
        if ($rate <= 0) return;
        $store->setSetting(self::K_MANUAL, (string)$rate);
        $store->setSetting(self::K_RATE, (string)$rate);
        $store->setSetting(self::K_UPDATED, (string)time());
        $store->setSetting(self::K_SOURCE, 'دستی (ادمین)');
        $store->setSetting(self::K_FETCHED, '');
        $store->setSetting(self::K_AUTO, '0');
    }

    /** برگرداندن به حالت خودکار (نرخ از API) */
    public static function enableAuto(Store $store): void
    {
        $store->setSetting(self::K_AUTO, '1');
    }

    public static function stored(Store $store): float
    {
        $raw = Payments::toNumber((string)($store->getSetting(self::K_RATE, '') ?? ''));
        if ($raw !== null && $raw > 0) return (float)$raw;
        $m = self::manual($store);
        return $m > 0 ? $m : self::FALLBACK_RATE;
    }

    public static function source(Store $store): string
    {
        return trim((string)($store->getSetting(self::K_SOURCE, '') ?? ''));
    }

    public static function updatedAt(Store $store): int
    {
        return (int)Payments::parseIntLoose((string)($store->getSetting(self::K_UPDATED, '0') ?? '0'));
    }

    public static function lastError(Store $store): string
    {
        return trim((string)($store->getSetting(self::K_FETCHED, '') ?? ''));
    }

    public static function isStale(Store $store): bool
    {
        $at = self::updatedAt($store);
        if ($at <= 0) return true;
        return (time() - $at) > self::CACHE_TTL;
    }

    /** «۲ ساعت پیش» / «۳ دقیقه پیش» — برای نمایش در پنل ادمین */
    public static function ageText(Store $store): string
    {
        $at = self::updatedAt($store);
        if ($at <= 0) return 'هرگز';
        $d = max(0, time() - $at);
        if ($d < 90) return 'همین الان';
        if ($d < 3600) return intdiv($d, 60) . ' دقیقه پیش';
        if ($d < 86400) return intdiv($d, 3600) . ' ساعت پیش';
        return intdiv($d, 86400) . ' روز پیش';
    }

    // ---------- گرفتن نرخ از سرویس‌ها ----------

    /**
     * تلاش برای گرفتن نرخ از سرویس‌ها به‌ترتیب.
     * خروجی: ['ok'=>bool, 'rate'=>float, 'source'=>string, 'error'=>string, 'tried'=>array]
     * هیچ‌وقت خطا نمی‌دهد؛ فقط «ناموفق» برمی‌گرداند تا caller بتواند نرخ قبلی را نگه دارد.
     */
    public static function fetch(int $timeout = 8): array
    {
        $tried = [];
        foreach (self::providers() as $p) {
            $r = self::fetchProvider($p, $timeout);
            $tried[] = ['name' => $p['name'], 'ok' => !empty($r['ok']), 'error' => (string)($r['error'] ?? '')];
            if (!empty($r['ok']) && !empty($r['rate'])) {
                return [
                    'ok'     => true,
                    'rate'   => (float)$r['rate'],
                    'source' => (string)$p['name'],
                    'error'  => '',
                    'tried'  => $tried,
                ];
            }
        }
        return [
            'ok' => false, 'rate' => 0.0, 'source' => '',
            'error' => 'هیچ سرویسی پاسخ معتبر نداد', 'tried' => $tried,
        ];
    }

    /** تلاشِ اجباری برای تازه‌کردن (دکمهٔ «🔄 به‌روزرسانی نرخ») */
    public static function refresh(Store $store, int $timeout = 8): array
    {
        $res = self::fetch($timeout);
        if (!empty($res['ok'])) {
            self::store($store, (float)$res['rate'], (string)$res['source']);
            // تازه‌کردن دستیِ ادمین هم حالت خودکار را روشن می‌کند (نیازِ صریحِ او)
            self::enableAuto($store);
            return $res;
        }
        $store->setSetting(self::K_FETCHED, (string)$res['error']);
        return $res;
    }

    /** کاشتنِ نرخِ اولیه بدون تغییر حالت خودکار/دستی (نصبِ تازه) */
    public static function seed(Store $store, float $rate, string $source): bool
    {
        if ($rate < self::MIN || $rate > self::MAX) return false;
        self::store($store, $rate, $source);
        return true;
    }

    /** ذخیرهٔ نرخِ دریافتی (فقط اگر در بازهٔ معقول باشد) */
    private static function store(Store $store, float $rate, string $source): void
    {
        if ($rate < self::MIN || $rate > self::MAX) return;
        $store->setSetting(self::K_RATE, (string)$rate);
        $store->setSetting(self::K_UPDATED, (string)time());
        $store->setSetting(self::K_SOURCE, $source);
        $store->setSetting(self::K_FETCHED, '');
    }

    /**
     * نرخِ «به‌روز»: اگر کش قدیمی باشد اول تلاش می‌کند تازه کند، وگرنه نرخ
     * ذخیره‌شده (یا پیش‌فرض) را برمی‌گرداند. شبکه هیچ‌وقت caller را معطل نمی‌کند:
     * خطا ⇒ همان نرخ قبلی استفاده می‌شود (فاکتور نباید خراب شود).
     */
    public static function current(Store $store, bool $auto = true): float
    {
        if ($auto && self::isAuto($store) && self::isStale($store)) {
            try {
                $res = self::fetch(6);
                if (!empty($res['ok'])) self::store($store, (float)$res['rate'], (string)$res['source']);
                else $store->setSetting(self::K_FETCHED, (string)$res['error']);
            } catch (Throwable $e) {
                // شبکه/سرویس مشکل دارد ⇒ نرخ قبلی می‌ماند (فاکتور نباید خراب شود)
                try { $store->setSetting(self::K_FETCHED, 'خطای شبکه: ' . $e->getMessage()); } catch (Throwable $ignored) {}
            }
        }
        return self::stored($store);
    }

    /** نرخی که فاکتور باید با آن ساخته شود (همیشه «به‌روز») */
    public static function forInvoice(Store $store): float
    {
        return self::current($store, true);
    }

    // ---------- سرویس‌ها ----------

    /** فهرست سرویس‌ها به‌ترتیب اولویت */
    private static function providers(): array
    {
        return [
            // TGJU: قیمت دلار بازار آزاد؛ پاسخ: data[0][0] = «۲,۶۸۷,۱۰۰» (ریال)
            ['name' => 'TGJU (بازار آزاد)', 'kind' => 'tgju',
             'url' => 'https://api.tgju.org/v1/market/indicator/summary-table-data/price_dollar_rl'],
            // open.er-api.com: نرخ رسمی؛ پاسخ: rates.IRR (ریال)
            ['name' => 'open.er-api.com', 'kind' => 'erapi_v6',
             'url' => 'https://open.er-api.com/v6/latest/USD'],
            // exchangerate-api.com: پشتیبان دوم؛ پاسخ: rates.IRR (ریال)
            ['name' => 'exchangerate-api.com', 'kind' => 'erapi_v4',
             'url' => 'https://api.exchangerate-api.com/v4/latest/USD'],
        ];
    }

    private static function fetchProvider(array $p, int $timeout): array
    {
        try {
            $body = self::httpGet((string)$p['url'], $timeout);
            if ($body === null) return ['ok' => false, 'error' => 'پاسخ نگرفتیم'];
            $j = json_decode($body, true);
            if (!is_array($j)) return ['ok' => false, 'error' => 'پاسخ JSON نبود'];
            return self::parse($p['kind'], $j);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** جداکردنِ «کدام سرویس» از «چه پاسخی» — تا افزودن سرویس تازه فقط یک شاخه باشد */
    private static function parse(string $kind, array $j): array
    {
        $rial = null;
        switch ($kind) {
            case 'tgju':
                // data: [[آخرین معامله, باز شدن, ...], ...] — همه به ریال
                $row = $j['data'][0] ?? null;
                if (is_array($row)) $rial = self::toFloat($row[0] ?? null);
                break;
            case 'erapi_v6':
            case 'erapi_v4':
                $rial = self::toFloat($j['rates']['IRR'] ?? null);
                break;
        }
        if ($rial === null || $rial <= 0) return ['ok' => false, 'error' => 'مبلغِ دلار در پاسخ نبود'];
        $toman = $rial / 10.0;   // ۱۰ ریال = ۱ تومان
        if ($toman < self::MIN || $toman > self::MAX) {
            return ['ok' => false, 'error' => 'نرخِ غیرواقعی (' . number_format($toman) . ')'];
        }
        return ['ok' => true, 'rate' => $toman];
    }

    /** عددِ «۱٬۲۳۴٬۵۶۷» یا «1,234,567.8» را به float تبدیل می‌کند */
    private static function toFloat($v): ?float
    {
        if (is_int($v) || is_float($v)) return (float)$v;
        if (!is_string($v)) return null;
        $s = Payments::normalizeDigits($v);
        $s = str_replace(['٬', '،', ','], '', $s);
        $s = trim($s);
        if ($s === '' || !preg_match('/\d/', $s)) return null;
        return Payments::toNumber($s);
    }

    // ---------- HTTP ----------

    private static function httpGet(string $url, int $timeout): ?string
    {
        $ch = @curl_init($url);
        if (!$ch) return null;
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => max(3, (int)ceil($timeout / 2)),
            CURLOPT_TIMEOUT        => max(4, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // بعضی سرویس‌ها (TGJU) بدون UA جواب ۴۰۳ می‌دهند
            CURLOPT_USERAGENT      => 'botsaz-faxima/1.0 (+fx-rate)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: fa,en;q=0.8'],
        ]);
        $out = @curl_exec($ch);
        $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)@curl_error($ch);
        @curl_close($ch);
        if ($out === false || $err !== '') return null;
        if ($code < 200 || $code >= 300) return null;
        $out = (string)$out;
        return $out === '' ? null : $out;
    }

    // ---------- نمایش ----------

    public static function format(float $toman): string
    {
        return number_format($toman) . ' تومان';
    }

    /** توضیحِ وضعیت برای پنل ادمین (سبز/زرد/قرمز + منبع + سن) */
    public static function statusLines(Store $store): array
    {
        $rate = self::stored($store);
        $src = self::source($store);
        $auto = self::isAuto($store);

        if (!$auto) {
            $head = '🔒 <b>نرخ دلار</b> — دستی (قفل‌شده توسط ادمین)';
        } elseif (self::updatedAt($store) <= 0) {
            $head = '⚪️ <b>نرخ دلار</b> — هنوز از هیچ سرویسی گرفته نشده';
        } elseif (self::isStale($store)) {
            $head = '🟡 <b>نرخ دلار</b> — کش قدیمی شده (' . self::e(self::ageText($store)) . ')';
        } else {
            $head = '🟢 <b>نرخ دلار</b> — تازه از API';
        }

        $lines = [$head];
        $lines[] = '   ↳ 💵 هر دلار ≈ <b>' . self::format($rate) . '</b>';
        $lines[] = '   ↳ 🔄 منبع: ' . ($auto ? 'خودکار (API)' : 'دستی');
        if ($src !== '') $lines[] = '   ↳ 📡 سرویس: ' . Ui::e($src);
        $lines[] = '   ↳ 🕒 آخرین به‌روزرسانی: ' . Ui::e(self::ageText($store));
        $err = self::lastError($store);
        if ($err !== '') $lines[] = '   ↳ ⚠️ آخرین خطا: ' . Ui::e($err);
        return $lines;
    }
}