<?php
require_once __DIR__ . '/_init.php';
rx_cron_boot('tronadocheck', 60);

ini_set('error_log', 'error_log');

$ctx = rx_cron_load_payment_context();
if (empty($ctx['db_ready'])) {
    return;
}
require_once __DIR__ . '/../lib/PaymentConfirm.php';

global $pdo, $ManagePanel, $setting;
$ManagePanel = $ctx['managePanel'];
$setting = $ctx['setting'];

if (!($pdo instanceof PDO)) {
    error_log('[tronadocheck] no PDO connection');
    return;
}

if (!function_exists('tronadoV2RunCron')) {
    error_log('[tronadocheck] tronado v2 functions not available');
    return;
}

tronadoV2RunCron();
