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

    public static function isConfigured(Store $store, ?array $cfg = null): bool
    {
        return self::apiKey($store, $cfg) !== '';
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

    /** استعلام وضعیت پرداخت/فاکتور */
    public static function fetchStatus(string $apiKey, string $invoiceOrPaymentId): array
    {
        if ($apiKey === '' || $invoiceOrPaymentId === '') return ['ok' => false, 'error' => 'invalid params'];
        return self::http('GET', '/payment/' . rawurlencode($invoiceOrPaymentId), $apiKey, null);
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
