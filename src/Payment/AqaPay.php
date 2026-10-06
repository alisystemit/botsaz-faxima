<?php
// ===== کلاینت درگاه «آقای پرداخت» (aqayepardakht.ir) =====
// داک: https://aqayepardakht.ir/api
//
// جریان:
//   ۱) POST https://panel.aqayepardakht.ir/api/v2/create  {pin, amount(تومان), callback, …}
//        ⇒ {"status":"success","transid":"…"}
//   ۲) هدایت کاربر به https://panel.aqayepardakht.ir/startpay/{transid}
//   ۳) بازگشت به callback ما با GET: transid, status(1=موفق), tracking_number, cardnumber, bank
//   ۴) POST https://panel.aqayepardakht.ir/api/v2/verify  {pin, amount, transid}
//        ⇒ {"status":"success|error","code":"1"}   1=موفق، 2=قبلاً وریفا، 0=ناموفق
//
// واحد پولی: تومان (بین ۱٬۰۰۰ تا ۴۰۰٬۰۰۰٬۰۰۰) — دقیقاً مثل واحد ربات‌ساز.
// ملاحظهٔ مهم: «pin» مثل کلید API است ⇒ در جدول settings نگه داشته می‌شود و
// هرگز در پیام یا لاگ به کاربر نشان داده نمی‌شود.

class PaymentAqaye
{
    public const API_BASE = 'https://panel.aqayepardakht.ir';
    public const API_V2   = self::API_BASE . '/api/v2';

    /** حداقل/بیشینهٔ مبلغ سرویس (تومان) — بیرون از این بازه سرویس خطا می‌دهد */
    public const MIN_TOMAN = 1000;
    public const MAX_TOMAN = 400000000;

    // ---------- پیکربندی ----------

    public static function pin(Store $store, ?array $cfg = null): string
    {
        $v = trim((string)($store->getSetting('pay_aqaye_pin', '') ?? ''));
        if ($v !== '') return $v;
        return trim((string)(($cfg['aqayepardakht']['pin'] ?? '') ?? ''));
    }

    public static function setPin(Store $store, string $pin): void
    {
        $store->setSetting('pay_aqaye_pin', trim($pin));
    }

    public static function hasPin(Store $store, ?array $cfg = null): bool
    {
        return self::pin($store, $cfg) !== '';
    }

    /** سرویس تست (sandbox) با pinِ خودِ سندِ تست */
    public static function isSandbox(Store $store): bool
    {
        $raw = strtolower(trim((string)($store->getSetting('pay_aqaye_sandbox', '0') ?? '0')));
        return in_array($raw, ['1', 'on', 'true', 'yes'], true);
    }

    public static function setSandbox(Store $store, bool $on): void
    {
        $store->setSetting('pay_aqaye_sandbox', $on ? '1' : '0');
    }

    public static function isConfigured(Store $store, ?array $cfg = null): bool
    {
        return self::hasPin($store, $cfg);
    }

    public static function callbackPath(): string
    {
        return '/aqayepardakht_ipn.php';
    }

    // ---------- کمکیِ مبلغ ----------

    /** مبلغ را به بازهٔ مجاز سرویس می‌آورد؛ اگر اصلاً نشد ⇒ null (فاکتور ساخته نشود) */
    public static function clampAmount(int $toman): ?int
    {
        if ($toman <= 0) return null;
        if ($toman < self::MIN_TOMAN) return self::MIN_TOMAN;
        if ($toman > self::MAX_TOMAN) return self::MAX_TOMAN;
        return $toman;
    }

    // ---------- API ----------

    /**
     * ساخت تراکنش.
     * @return array{ok:bool, transid:string, error:string, code:string}
     */
    public static function create(
        string $pin,
        int $toman,
        string $callbackUrl,
        string $description = '',
        string $invoiceId = ''
    ): array {
        if ($pin === '' || $callbackUrl === '') {
            return ['ok' => false, 'transid' => '', 'code' => '', 'error' => 'pin یا callback تنظیم نشده'];
        }
        $amount = self::clampAmount($toman);
        if ($amount === null) {
            return ['ok' => false, 'transid' => '', 'code' => '', 'error' => 'مبلغ نامعتبر است'];
        }
        $payload = [
            'pin'             => $pin,
            'amount'          => $amount,
            'callback'        => $callbackUrl,
            'callback_method' => 'GET',        // مستندات GET را توصیه کرده‌اند
            'description'     => $description !== '' ? mb_substr($description, 0, 250) : 'پرداخت ربات‌ساز',
        ];
        if ($invoiceId !== '') $payload['invoice_id'] = mb_substr($invoiceId, 0, 50);

        $res = self::http(self::API_V2 . '/create', $payload);
        if (empty($res['ok'])) {
            return ['ok' => false, 'transid' => '', 'code' => '', 'error' => (string)($res['error'] ?? 'unknown')];
        }
        $d = (array)$res['data'];
        $transid = trim((string)($d['transid'] ?? ''));
        if ($transid === '') {
            return ['ok' => false, 'transid' => '', 'code' => '', 'error' => 'پاسخ سرویس transid ندارد'];
        }
        return ['ok' => true, 'transid' => $transid, 'code' => (string)($d['code'] ?? ''), 'error' => ''];
    }

    /**
     * وریفای تراکنش.
     * code=1 موفق، code=2 قبلاً وریفا شده (باز هم پرداخت موفق است)،
     * code=0 پرداخت انجام نشد، و کدهای منفی خطای ورودی/سرویس‌اند.
     */
    public static function verify(string $pin, int $toman, string $transid): array
    {
        if ($pin === '' || $transid === '') {
            return ['ok' => false, 'paid' => false, 'code' => '', 'error' => 'pin یا transid تنظیم نشده'];
        }
        $amount = self::clampAmount($toman);
        if ($amount === null) {
            return ['ok' => false, 'paid' => false, 'code' => '', 'error' => 'مبلغ نامعتبر است'];
        }
        $res = self::http(self::API_V2 . '/verify', [
            'pin'     => $pin,
            'amount'  => $amount,
            'transid' => $transid,
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'paid' => false, 'code' => '', 'error' => (string)($res['error'] ?? 'unknown')];
        }
        $d = (array)$res['data'];
        $code = trim((string)($d['code'] ?? ''));
        $statusOk = strtolower(trim((string)($d['status'] ?? ''))) === 'success';
        // ۱ = پرداخت موفق، ۲ = قبلاً وریفا (همان پرداختِ موفق)
        $paid = $statusOk && in_array($code, ['1', '2'], true);
        return [
            'ok'   => true,
            'paid' => $paid,
            'code' => $code,
            'error'=> '',
        ];
    }

    /** استعلام از روی transid (همان verify) */
    public static function status(string $pin, int $toman, string $transid): array
    {
        return self::verify($pin, $toman, $transid);
    }

    public static function startPayUrl(string $transid, bool $sandbox = false): string
    {
        $base = $sandbox ? self::API_BASE . '/startpay/sandbox/' : self::API_BASE . '/startpay/';
        return $base . rawurlencode($transid);
    }

    /** متن خطای خوانا از کدهای منفی سرویس (مستندات رسمی) */
    public static function errorText(string $code): string
    {
        $map = [
            '-1'  => 'مبلغ تراکنش ارسال نشده',
            '-2'  => 'کد پین درگاه ارسال نشده',
            '-3'  => 'آدرس بازگشت ارسال نشده',
            '-4'  => 'مبلغ عددی نیست',
            '-5'  => 'مبلغ باید بین ۱٬۰۰۰ تا ۴۰۰٬۰۰۰٬۰۰۰ تومان باشد',
            '-6'  => 'کد پین درگاه اشتباه است',
            '-7'  => 'کد تراکنش ارسال نشده',
            '-8'  => 'تراکنش موردنظر وجود ندارد',
            '-9'  => 'کد پین با درگاه تراکنش مطابقت ندارد',
            '-10' => 'مبلغ با مبلغ تراکنش مطابقت ندارد',
            '-11' => 'درگاه در انتظار تأیید یا غیرفعال است',
            '-12' => 'ارسال درخواست برای این پذیرنده ممکن نیست',
            '-13' => 'شماره کارت مجاز باید ۱۶ رقم باشد',
            '-14' => 'درگاه روی سایت دیگری در حال استفاده است',
            '-15' => 'دامنهٔ آدرس بازگشت با دامنهٔ تأییدشدهٔ درگاه نمی‌خواند',
            '-16' => 'ارجاع‌دهنده (Referrer) ارسال نشده',
            '-17' => 'مقدار callback_method باید POST یا GET باشد',
        ];
        $c = trim($code);
        if ($c === '0') return 'پرداخت انجام نشد';
        return $map[$c] ?? ('خطای سرویس (کد ' . ($c !== '' ? $c : '?') . ')');
    }

    // ---------- داخلی ----------

    private static function http(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'data' => null, 'error' => 'curl not available'];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            // سرویس برای مرحلهٔ create هدر Referer دامنهٔ پذیرنده را الزامی می‌داند
            CURLOPT_REFERER        => rtrim(self::referer($url), '/') . '/',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen((string)$json),
                'Accept: application/json',
                'User-Agent: botsaz-faxima/1.0 (+aqayepardakht)',
            ],
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);
        if ($out === false) return ['ok' => false, 'data' => null, 'error' => 'network: ' . $err];
        $j = json_decode((string)$out, true);
        if (!is_array($j)) {
            return ['ok' => false, 'data' => null, 'error' => 'HTTP ' . $code . ': ' . mb_substr((string)$out, 0, 250)];
        }
        $statusOk = strtolower(trim((string)($j['status'] ?? ''))) === 'success';
        if (!$statusOk) {
            $ec = (string)($j['code'] ?? '');
            return ['ok' => false, 'data' => $j, 'error' => self::errorText($ec) . ' (کد ' . ($ec !== '' ? $ec : '?') . ')'];
        }
        return ['ok' => true, 'data' => $j, 'error' => ''];
    }

    /** دامنهٔ سرویس تا Referer با دامنهٔ تأییدشده هم‌خوان باشد */
    private static function referer(string $url): string
    {
        $p = parse_url($url);
        $host = (string)($p['host'] ?? '');
        return ($p['scheme'] ?? 'https') . '://' . $host;
    }
}