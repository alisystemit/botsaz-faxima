<?php
// ===== کلاینت درگاه زرین‌پال (ریالی، تأیید خودکار) =====
// داک: https://www.zarinpal.com/docs/paymentGateway/connectToGateway
//
// جریان:
//   ۱) POST /pg/v4/payment/request.json  ⇒ data.code=100 و data.authority
//   ۲) هدایت کاربر به https://payment.zarinpal.com/pg/StartPay/{authority}
//   ۳) بازگشت به callback_url ما با ?Authority=…&Status=OK
//   ۴) POST /pg/v4/payment/verify.json   ⇒ code=100 (تازه) یا 101 (قبلاً تأیید شده)
//
// واحد پولی: ما همه‌جا تومان حساب می‌کنیم و با currency="IRT" مستقیم تومان
// می‌فرستیم ⇒ نه تبدیل دستی، نه گردکردن اشتباه. (حالت پیش‌فرض زرین‌پال ریال است.)
//
// sandbox: https://sandbox.zarinpal.com/... با merchant_id ساختگی — برای تست.

class PaymentZarin
{
    public const BASE_PROD  = 'https://payment.zarinpal.com';
    public const BASE_TEST  = 'https://sandbox.zarinpal.com';

    public const CODE_OK        = 100;  // پرداخت موفق
    public const CODE_DUPLICATE = 101;  // قبلاً تأیید شده (هنوز پرداخت موفق است)

    // ---------- پیکربندی (از جدول settings، با fallback به config.php) ----------

    public static function merchantId(Store $store, ?array $cfg = null): string
    {
        $v = trim((string)($store->getSetting('pay_zarin_merchant_id', '') ?? ''));
        if ($v !== '') return $v;
        return trim((string)(($cfg['zarinpal']['merchant_id'] ?? '') ?? ''));
    }

    public static function isSandbox(Store $store): bool
    {
        $raw = strtolower(trim((string)($store->getSetting('pay_zarin_sandbox', '0') ?? '0')));
        return in_array($raw, ['1', 'on', 'true', 'yes'], true);
    }

    public static function setCredentials(Store $store, string $merchantId, bool $sandbox = false): void
    {
        $store->setSetting('pay_zarin_merchant_id', trim($merchantId));
        $store->setSetting('pay_zarin_sandbox', $sandbox ? '1' : '0');
    }

    public static function setSandbox(Store $store, bool $on): void
    {
        $store->setSetting('pay_zarin_sandbox', $on ? '1' : '0');
    }

    public static function hasMerchantId(Store $store, ?array $cfg = null): bool
    {
        return self::merchantId($store, $cfg) !== '';
    }

    /** آدرس callback پیشنهادی — همین فایل IPN است */
    public static function callbackPath(): string
    {
        return '/zarinpal_ipn.php';
    }

    /**
     * آیا درگاه کامل پیکربندی شده؟
     * برخلاف کریپتو، زرین‌پال «کلید امضا» جدا ندارد ⇒ فقط merchant_id کافی است
     * (تأیید با خودِ فراخوانی verify انجام می‌شود، نه با هدر امضا).
     */
    public static function isConfigured(Store $store, ?array $cfg = null): bool
    {
        return self::hasMerchantId($store, $cfg);
    }

    public static function base(Store $store): string
    {
        return self::isSandbox($store) ? self::BASE_TEST : self::BASE_PROD;
    }

    // ---------- API ----------

    /**
     * ساخت تراکنش.
     * @param int $toman مبلغ به تومان
     * @return array{ok:bool, authority:string, code:int, message:string, error:string}
     */
    public static function request(
        string $merchantId,
        int $toman,
        string $callbackUrl,
        string $description = '',
        string $orderId = '',
        bool $sandbox = false
    ): array {
        if ($merchantId === '' || $toman <= 0 || $callbackUrl === '') {
            return ['ok' => false, 'authority' => '', 'code' => 0, 'message' => '', 'error' => 'پارامترهای ناقص'];
        }
        $payload = [
            'merchant_id'  => $merchantId,
            'amount'       => $toman,
            'currency'     => 'IRT',          // تومان — همه‌جای ربات‌ساز تومان است
            'description'  => $description,
            'callback_url' => $callbackUrl,
        ];
        if ($orderId !== '') $payload['metadata'] = ['order_id' => $orderId, 'mobile' => ''];

        $res = self::http(self::baseUrl($sandbox) . '/pg/v4/payment/request.json', $payload);
        if (empty($res['ok'])) {
            return ['ok' => false, 'authority' => '', 'code' => 0, 'message' => '', 'error' => (string)($res['error'] ?? 'unknown')];
        }
        $d = (array)($res['data'] ?? []);
        $code = (int)($d['code'] ?? 0);
        $authority = trim((string)($d['authority'] ?? ''));
        if ($code !== self::CODE_OK || $authority === '') {
            $msg = (string)($d['message'] ?? '');
            foreach ((array)($res['errors'] ?? []) as $err) {
                if (is_array($err) && !empty($err['message'])) $msg .= ' | ' . $err['message'];
            }
            return ['ok' => false, 'authority' => '', 'code' => $code, 'message' => $msg, 'error' => $msg !== '' ? $msg : 'کد ' . $code];
        }
        return ['ok' => true, 'authority' => $authority, 'code' => $code, 'message' => (string)($d['message'] ?? ''), 'error' => ''];
    }

    /**
     * اعتبارسنجی تراکنش.
     * code=100 تازه، 101 قبلاً تأیید‌شده ⇒ هر دو یعنی «پول واقعاً رسیده».
     */
    public static function verify(string $merchantId, int $toman, string $authority = '', bool $sandbox = false): array
    {
        if ($merchantId === '' || $authority === '' || $toman <= 0) {
            return ['ok' => false, 'paid' => false, 'code' => 0, 'ref_id' => 0, 'card_pan' => '', 'message' => '', 'error' => 'پارامترهای ناقص'];
        }
        $res = self::http(self::baseUrl($sandbox) . '/pg/v4/payment/verify.json', [
            'merchant_id' => $merchantId,
            'amount'      => $toman,
            'authority'   => $authority,
            'currency'    => 'IRT',
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'paid' => false, 'code' => 0, 'ref_id' => 0, 'card_pan' => '', 'message' => '', 'error' => (string)($res['error'] ?? 'unknown')];
        }
        $d = (array)($res['data'] ?? []);
        $code = (int)($d['code'] ?? 0);
        $paid = in_array($code, [self::CODE_OK, self::CODE_DUPLICATE], true);
        $msg = (string)($d['message'] ?? '');
        return [
            'ok'      => true,
            'paid'    => $paid,
            'code'    => $code,
            'ref_id'  => (int)($d['ref_id'] ?? 0),
            'card_pan'=> (string)($d['card_pan'] ?? ''),
            'message' => $msg,
            'error'   => '',
        ];
    }

    /** استعلام وضعیت از روی authority (همان verify، ولی بدون اثرِ نمایشیِ متفاوت) */
    public static function status(Store $store, string $merchantId, int $toman, string $authority): array
    {
        return self::verify($merchantId, $toman, $authority, self::isSandbox($store));
    }

    public static function startPayUrl(string $authority, bool $sandbox = false): string
    {
        $base = self::baseUrl($sandbox);
        return $base . '/pg/StartPay/' . rawurlencode($authority);
    }

    // ---------- داخلی ----------

    private static function baseUrl(bool $sandbox): string
    {
        return $sandbox ? self::BASE_TEST : self::BASE_PROD;
    }

    private static function http(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'data' => null, 'errors' => [], 'error' => 'curl not available'];
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
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: botsaz-faxima/1.0 (+zarinpal)',
            ],
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);
        if ($out === false) return ['ok' => false, 'data' => null, 'errors' => [], 'error' => 'network: ' . $err];
        $j = json_decode((string)$out, true);
        // زرین‌پال برای خطای منطقی هم HTTP 400/422 می‌دهد ولی بدنهٔ ساختاریافته دارد
        if (!is_array($j)) {
            return ['ok' => false, 'data' => null, 'errors' => [],
                    'error' => 'HTTP ' . $code . ': ' . mb_substr((string)$out, 0, 250)];
        }
        return ['ok' => ($code >= 200 && $code < 300) || isset($j['data']) || isset($j['errors']),
                'data' => $j['data'] ?? null, 'errors' => $j['errors'] ?? [], 'error' => ''];
    }
}