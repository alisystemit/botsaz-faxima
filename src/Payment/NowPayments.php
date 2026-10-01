<?php
// ===== کلاینت NOWPayments (پرداخت کریپتو، خودکار) =====
// داک: https://documenter.getpostman.com/view/7907941/2s9YJdQZkE
// فاکتور: POST https://api.nowpayments.io/v1/invoice (هدر x-api-key)
// استعلام: GET https://api.nowpayments.io/v1/payment/:id (هدر x-api-key)
// IPN: هدر x-nowpayments-sig = HMAC_SHA512(body, ipn_secret)

class PaymentNowPay
{
    public const API_BASE = 'https://api.nowpayments.io/v1';

    public static function apiKey(Store $store, ?array $cfg = null): string
    {
        $fromStore = trim((string)($store->getSetting('pay_nowpay_api_key', '') ?? ''));
        if ($fromStore !== '') return $fromStore;
        return trim((string)(($cfg['nowpayments']['api_key'] ?? '') ?? ''));
    }

    public static function ipnSecret(Store $store, ?array $cfg = null): string
    {
        $fromStore = trim((string)($store->getSetting('pay_nowpay_ipn_secret', '') ?? ''));
        if ($fromStore !== '') return $fromStore;
        return trim((string)(($cfg['nowpayments']['ipn_secret'] ?? '') ?? ''));
    }

    /** فقط کلید API موجود است؟ (برای پیام خطای دقیق‌تر) */
    public static function hasApiKey(Store $store, ?array $cfg = null): bool
    {
        return self::apiKey($store, $cfg) !== '';
    }

    public static function hasIpnSecret(Store $store, ?array $cfg = null): bool
    {
        return self::ipnSecret($store, $cfg) !== '';
    }

    /**
     * آیا درگاه کریپتو کامل پیکربندی شده؟
     * هر دو کلید لازم‌اند: بدون ipn_secret هیچ IPNی تأیید نمی‌شود، پس کاربر
     * پول می‌دهد و اعتبارش دستی باید تأیید شود — یعنی نباید اصلاً روش پرداخت
     * کریپتو به او پیشنهاد شود. قبلاً فقط api_key چک می‌شد و همین اتفاق می‌افتاد.
     */
    public static function isConfigured(Store $store, ?array $cfg = null): bool
    {
        return self::hasApiKey($store, $cfg) && self::hasIpnSecret($store, $cfg);
    }

    public static function setCredentials(Store $store, string $apiKey, string $ipnSecret): void
    {
        $store->setSetting('pay_nowpay_api_key', trim($apiKey));
        $store->setSetting('pay_nowpay_ipn_secret', trim($ipnSecret));
    }

    /**
     * ساخت فاکتور. برمی‌گرداند: ['ok'=>bool,'invoice_id'=>..,'pay_url'=>..,'raw'=>..,'error'=>..]
     * $orderId همان شناسه پرداخت داخلی ماست تا در IPN به آن برگردیم.
     */
    public static function createInvoice(string $apiKey, float $usdAmount, string $orderId, string $successUrl = '', string $cancelUrl = '', string $ipnUrl = ''): array
    {
        if ($apiKey === '' || $usdAmount <= 0 || $orderId === '') {
            return ['ok' => false, 'error' => 'invalid params'];
        }
        $payload = [
            'price_amount' => $usdAmount,
            'price_currency' => 'usd',
            'order_id' => $orderId,
            'order_description' => 'Bot limit/template payment #' . $orderId,
        ];
        if ($successUrl !== '') $payload['success_url'] = $successUrl;
        if ($cancelUrl !== '') $payload['cancel_url'] = $cancelUrl;
        if ($ipnUrl !== '') $payload['ipn_callback_url'] = $ipnUrl;
        $res = self::http('POST', '/invoice', $apiKey, $payload);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        $invoiceId = (string)($d['id'] ?? ($d['invoice_id'] ?? ''));
        $payUrl = (string)($d['invoice_url'] ?? ($d['payment_url'] ?? ''));
        if ($invoiceId === '' && $payUrl === '') {
            return ['ok' => false, 'error' => 'bad response: ' . substr($res['body'], 0, 200)];
        }
        return ['ok' => true, 'invoice_id' => $invoiceId, 'pay_url' => $payUrl, 'raw' => $d];
    }

    /** استعلام وضعیت یک payment_id */
    public static function fetchPayment(string $apiKey, string $paymentId): array
    {
        if ($apiKey === '' || $paymentId === '') return ['ok' => false, 'error' => 'invalid params'];
        return self::http('GET', '/payment/' . rawurlencode($paymentId), $apiKey, null);
    }

    /** استخراج payment_id از پاسخ فاکتور */
    public static function paymentIdFromInvoice(array $invoice): string
    {
        foreach (['payment_id', 'paymentId'] as $k) {
            if (!empty($invoice[$k])) return (string)$invoice[$k];
        }
        return '';
    }

    /**
     * استعلام وضعیت با شناسه‌ای که ما ذخیره کرده‌ایم.
     *
     * ما «invoice_id» را ذخیره می‌کنیم، ولی endpoint وضعیتِ NOWPayments
     * یعنی GET /v1/payment/{payment_id} و invoice_id را نمی‌پذیرد.
     * پس اول فاکتور را می‌خوانیم تا payment_id را دربیاوریم و بعد وضعیت را می‌گیریم.
     * اگر شناسه از قبل payment_id باشد، همان مسیر /payment جواب می‌دهد.
     */
    public static function fetchStatus(string $apiKey, string $id): array
    {
        if ($apiKey === '' || $id === '') return ['ok' => false, 'error' => 'invalid params'];

        // مسیر مستقیم (وقتی شناسه payment_id است)
        $res = self::fetchPayment($apiKey, $id);
        if (!empty($res['ok'])) {
            $res['payment_id'] = self::paymentIdFromInvoice((array)($res['data'] ?? [])) ?: $id;
            return $res;
        }

        // مسیر فاکتور: invoice_id ⇒ payment_id ⇒ وضعیت
        $inv = self::http('GET', '/invoice/' . rawurlencode($id), $apiKey, null);
        if (empty($inv['ok'])) return $inv; // هر دو شکست خوردند ⇒ خطای واقعی
        $pid = self::paymentIdFromInvoice((array)($inv['data'] ?? []));
        if ($pid === '') {
            // فاکتور هنوز به payment وصل نشده (مشتری هنوز صفحهٔ پرداخت را باز نکرده)
            return ['ok' => true, 'data' => ['payment_status' => 'waiting'], 'body' => (string)($inv['body'] ?? ''), 'payment_id' => ''];
        }
        $out = self::fetchPayment($apiKey, $pid);
        $out['payment_id'] = $pid;
        return $out;
    }

    /** آیا وضعیت NOWPayments به معنی «پرداخت شده» است؟ */
    public static function isPaidStatus(string $status): bool
    {
        $s = strtolower(trim($status));
        // finished = تسویه کامل؛ confirmed/finished مقبول؛ waiting/failed نه
        return in_array($s, ['finished', 'confirmed'], true);
    }

    /** راستی‌آزمایی امضای IPN — ورودی: بدنه خام + هدر دریافتی */
    public static function verifyIpn(string $rawBody, string $sigHeader, string $ipnSecret): bool
    {
        if ($rawBody === '' || $sigHeader === '' || $ipnSecret === '') return false;
        $calc = hash_hmac('sha512', $rawBody, $ipnSecret);
        return hash_equals(strtolower($calc), strtolower(trim($sigHeader)));
    }

    /** استخراج order_id از بدنه IPN (فرمت‌های مختلف NOWPayments) */
    public static function extractOrderId(array $data): string
    {
        foreach (['order_id', 'orderId', 'order'] as $k) {
            if (!empty($data[$k])) return (string)$data[$k];
        }
        return '';
    }

    public static function extractStatus(array $data): string
    {
        foreach (['payment_status', 'status'] as $k) {
            if (!empty($data[$k])) return (string)$data[$k];
        }
        return '';
    }

    private static function http(string $method, string $path, string $apiKey, ?array $payload): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl not available'];
        $ch = curl_init(self::API_BASE . $path);
        $headers = ['x-api-key: ' . $apiKey, 'Content-Type: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?? new stdClass(), JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) return ['ok' => false, 'error' => 'network: ' . $err];
        $data = json_decode((string)$out, true);
        if ($code >= 200 && $code < 300 && is_array($data)) {
            return ['ok' => true, 'data' => $data, 'body' => (string)$out];
        }
        $msg = is_array($data) ? (($data['message'] ?? '') ?: (string)$out) : (string)$out;
        return ['ok' => false, 'error' => 'HTTP ' . $code . ': ' . mb_substr($msg, 0, 300)];
    }
}
