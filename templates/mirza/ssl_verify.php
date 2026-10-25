<?php
/**
 * بررسی گواهی TLS برای اتصال به پنل‌های میرزا.
 *
 * پیش‌فرض امن است (verify=true). بعضی پنل‌های x-ui/wg با گواهی self-signed
 * بالا می‌آیند؛ برای آن‌ها ادمین می‌تواند صریحاً
 *   MIRZA_SSL_INSECURE=1
 * را در محیط وب‌سرور تنظیم کند. این کار آگاهانه و قابل مستندسازی است، نه
 * یک false بی‌قید و شرط در کد.
 */
if (!function_exists('mirza_ssl_verify')) {
    function mirza_ssl_verify(): bool
    {
        $v = getenv('MIRZA_SSL_INSECURE');
        if ($v === false || $v === '') $v = getenv('BOTSAZ_SSL_INSECURE');
        return !in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }
}
