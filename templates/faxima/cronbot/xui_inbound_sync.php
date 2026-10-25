<?php
require_once __DIR__ . '/_init.php';
rx_cron_boot('xui_inbound_sync', 180);

if (!rx_cron_require_or_skip('xui_inbound_sync', [
    __DIR__ . '/../config.php',
    __DIR__ . '/../botapi.php',
    __DIR__ . '/../panels.php',
    __DIR__ . '/../function.php',
])) {
    return;
}
if (!rx_cron_db_ready('xui_inbound_sync')) {
    return;
}
if (!function_exists('xsync_run_all')) {
    return;
}

try {
    xsync_run_all();
} catch (Throwable $e) {
    error_log('[xui_inbound_sync] ' . $e->getMessage());
}
