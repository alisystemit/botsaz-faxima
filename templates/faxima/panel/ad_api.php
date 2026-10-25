<?php

if (!defined('FAOXIMA_SKIP_BOTAPI_ROUTER')) {
    define('FAOXIMA_SKIP_BOTAPI_ROUTER', true);
}

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', '1');
ini_set('display_errors', '0');
if ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    ini_set('session.cookie_secure', '1');
}
session_start();

function fx_ad_json(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
    }
    $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $out === false ? '{"ok":false}' : $out;
    exit;
}

ob_start();
try {
    require_once __DIR__ . '/../config.php';
} catch (Throwable $e) {
    fx_ad_json(['ok' => false, 'code' => 'server'], 500);
}
ob_end_clean();
if (!@include_once __DIR__ . '/lib/ad_boot.php') {
    http_response_code(503);
    header('Retry-After: 3600');
    exit;
}
require_once __DIR__ . '/lib/ad_client.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/schema.php';

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'banner') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        fx_ad_json(['ok' => false, 'code' => 'method'], 405);
    }
    $nonce = (string)($_GET['t'] ?? '');
    $expected = (string)($_SESSION['fx_ad_nonce'] ?? '');
    if ($expected === '' || $nonce === '' || !hash_equals($expected, $nonce)) {
        fx_ad_json(['ok' => false, 'code' => 'forbidden'], 403);
    }
    $last = (int)($_SESSION['fx_ad_last'] ?? 0);
    if ($last > 0 && time() - $last < 3) {
        fx_ad_json(['ok' => true, 'ad' => null]);
    }
    $_SESSION['fx_ad_last'] = time();
    session_write_close();
    fx_ad_json(['ok' => true, 'ad' => fx_ad_fetch('banner')]);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fx_ad_json(['ok' => false, 'code' => 'server'], 500);
}

$adUser = (string)($_SESSION['user'] ?? '');
if ($adUser === '') {
    fx_ad_json(['ok' => false, 'code' => 'auth'], 401);
}

try {
    $chk = $pdo->prepare('SELECT 1 FROM admin WHERE username = :u LIMIT 1');
    $chk->execute([':u' => $adUser]);
    if (!$chk->fetchColumn()) {
        fx_ad_json(['ok' => false, 'code' => 'auth'], 401);
    }
    faoxima_schema_ensure_column($pdo, 'admin', 'ad_modal_hidden_until', 'INT(11) NULL DEFAULT 0');
} catch (Throwable $e) {
    fx_ad_json(['ok' => false, 'code' => 'server'], 500);
}

if ($action === 'modal') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        fx_ad_json(['ok' => false, 'code' => 'method'], 405);
    }
    $token = fx_csrf_token();
    try {
        $st = $pdo->prepare('SELECT ad_modal_hidden_until FROM admin WHERE username = :u LIMIT 1');
        $st->execute([':u' => $adUser]);
        $until = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        fx_ad_json(['ok' => false, 'code' => 'server'], 500);
    }
    session_write_close();
    if ($until > time()) {
        fx_ad_json(['ok' => true, 'ad' => null, 'csrf' => $token]);
    }
    fx_ad_json(['ok' => true, 'ad' => fx_ad_fetch('modal'), 'csrf' => $token]);
}

if ($action === 'dismiss') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fx_ad_json(['ok' => false, 'code' => 'method'], 405);
    }
    $incoming = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($incoming) || $incoming === '' || !hash_equals(fx_csrf_token(), $incoming)) {
        fx_ad_json(['ok' => false, 'code' => 'csrf_invalid'], 403);
    }
    $until = time() + 86400;
    try {
        $up = $pdo->prepare('UPDATE admin SET ad_modal_hidden_until = :t WHERE username = :u');
        $up->execute([':t' => $until, ':u' => $adUser]);
    } catch (Throwable $e) {
        fx_ad_json(['ok' => false, 'code' => 'server'], 500);
    }
    fx_ad_json(['ok' => true, 'until' => $until]);
}

fx_ad_json(['ok' => false, 'code' => 'action'], 400);
