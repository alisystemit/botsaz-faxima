<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../lib/PaymentConfirm.php';

function tronado_dispute_respond($code, $body)
{
    http_response_code($code);
    exit($body);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tronado_dispute_respond(405, 'Method not allowed');
}

$rawBody = (string) file_get_contents('php://input');
$signingRow = select("PaySetting", "*", "NamePay", "ipnsigningkeytronado", "select");
$signingKey = is_array($signingRow) ? trim((string) ($signingRow['ValuePay'] ?? '')) : '';
if ($signingKey === '') {
    tronado_dispute_respond(500, 'Signing key not configured');
}
$signatureHeader = isset($_SERVER['HTTP_X_TRONADO_SIG']) ? (string) $_SERVER['HTTP_X_TRONADO_SIG'] : '';
$expectedSignature = hash_hmac('sha512', $rawBody, $signingKey);
if ($signatureHeader === '' || !hash_equals($expectedSignature, strtolower($signatureHeader))) {
    error_log('[tronado-dispute] signature verification failed from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    tronado_dispute_respond(401, 'Invalid signature');
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    tronado_dispute_respond(400, 'Invalid body');
}

if (!function_exists('tronadoV2HandleDispute')) {
    tronado_dispute_respond(500, 'Handler unavailable');
}
$setting = select("setting", "*");
[$code, $body] = tronadoV2HandleDispute($data);
tronado_dispute_respond($code, $body);
