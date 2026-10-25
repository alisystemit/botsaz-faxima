<?php

if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', __DIR__);
}
require __DIR__ . '/re/function.php';

/**
 * اعتبارسنجی TLS برای درخواست‌های داخلی/پنل.
 * پیش‌فرض امن است. برای پنل self-signed می‌توان FAOXIMA_SSL_INSECURE=1
 * (یا BOTSAZ_SSL_INSECURE=1) را در محیط وب‌سرور تنظیم کرد.
 */
if (!function_exists('faoxima_ssl_verify')) {
    function faoxima_ssl_verify(): bool
    {
        $v = getenv('FAOXIMA_SSL_INSECURE');
        if ($v === false || $v === '') $v = getenv('BOTSAZ_SSL_INSECURE');
        return !in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }
}

