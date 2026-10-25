<?php

ini_set('error_log', 'error_log');
ini_set('display_errors', '0');

function forapp_respond(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    forapp_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

if (!function_exists('rx_sbv_ingest')) {
    forapp_respond(503, ['ok' => false, 'error' => 'unavailable']);
}

$raw = file_get_contents('php://input', false, null, 0, RX_SBV_MAX_BODY + 1);
if (!is_string($raw) || $raw === '') {
    forapp_respond(400, ['ok' => false, 'error' => 'empty_body']);
}

[$code, $body] = rx_sbv_ingest(rx_sbv_header('X-API-Key'), rx_sbv_header('Idempotency-Key'), $raw);
forapp_respond($code, $body);
