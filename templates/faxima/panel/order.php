<?php
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', '1');
ob_start();

function fx_order_messages(): array
{
    return [
        'unauthorized' => 'نشست شما منقضی شده است. دوباره وارد شوید.',
        'csrf_invalid' => 'توکن امنیتی نامعتبر است. صفحه را دوباره بارگذاری کنید.',
        'method_not_allowed' => 'روش درخواست مجاز نیست.',
        'invalid_entity' => 'نوع فهرست نامعتبر است.',
        'invalid_payload' => 'داده ارسالی نامعتبر است.',
        'invalid_id' => 'شناسه نامعتبر است.',
        'server_error' => 'خطای داخلی سرور. جزئیات در لاگ سرور ثبت شد.',
    ];
}

function fx_order_respond(int $status, array $body): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    if (!isset($body['message']) && isset($body['error'])) {
        $messages = fx_order_messages();
        if (isset($messages[$body['error']])) {
            $body['message'] = $messages[$body['error']];
        }
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function fx_order_fail(string $context, \Throwable $e): void
{
    error_log('[order.php] ' . $context . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    fx_order_respond(500, ['ok' => false, 'error' => 'server_error']);
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        error_log('[order.php] fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']);
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => fx_order_messages()['server_error']], JSON_UNESCAPED_UNICODE);
        }
    }
});

try {
    session_start();
    require_once __DIR__ . '/../config.php';
    if (!@include_once __DIR__ . '/lib/ad_boot.php') { http_response_code(503); header('Retry-After: 3600'); exit; }
    require_once __DIR__ . '/lib/csrf.php';
    require_once __DIR__ . '/lib/ordering.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('database connection unavailable');
    }

    $admin = false;
    if (!empty($_SESSION['user'])) {
        $query = $pdo->prepare("SELECT 1 FROM admin WHERE username = :username LIMIT 1");
        $query->execute([':username' => $_SESSION['user']]);
        $admin = (bool)$query->fetchColumn();
    }
    if (!$admin) {
        fx_order_respond(401, ['ok' => false, 'error' => 'unauthorized']);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET' && $method !== 'POST') {
        fx_order_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    $entity = (string)($method === 'POST' ? ($_POST['entity'] ?? '') : ($_GET['entity'] ?? ''));
    if (!isset(fx_order_entities()[$entity])) {
        fx_order_respond(400, ['ok' => false, 'error' => 'invalid_entity']);
    }

    fx_order_ensure_schema($pdo, $entity);

    if ($method === 'GET') {
        fx_order_respond(200, fx_order_payload($pdo, $entity));
    }

    $token = fx_csrf_token();
    $incoming = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($incoming) || $incoming === '' || !hash_equals($token, $incoming)) {
        fx_order_respond(403, ['ok' => false, 'error' => 'csrf_invalid']);
    }

    $rawIds = $_POST['ids'] ?? '';
    $version = $_POST['version'] ?? '';
    if (!is_string($rawIds) || !is_string($version) || $rawIds === '' || $version === '') {
        fx_order_respond(422, ['ok' => false, 'error' => 'invalid_payload']);
    }
    $ids = [];
    foreach (explode(',', $rawIds) as $part) {
        if (!ctype_digit($part)) {
            fx_order_respond(422, ['ok' => false, 'error' => 'invalid_id']);
        }
        $ids[] = (int)$part;
    }

    $result = fx_order_save($pdo, $entity, $ids, $version);
    fx_order_respond($result['status'], $result['body']);
} catch (\Throwable $e) {
    fx_order_fail('request', $e);
}
