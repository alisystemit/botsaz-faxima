<?php
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', '1');
ob_start();

require_once __DIR__ . '/lib/bulk_price.php';

function fx_bp_respond(int $status, array $body): void
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
        $messages = fx_bp_messages();
        if (isset($messages[$body['error']])) {
            $body['message'] = $messages[$body['error']];
        }
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        error_log('[bulk_price.php] fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']);
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => fx_bp_messages()['server_error']], JSON_UNESCAPED_UNICODE);
        }
    }
});

try {
    session_start();
    require_once __DIR__ . '/../config.php';
    if (!@include_once __DIR__ . '/lib/ad_boot.php') { http_response_code(503); header('Retry-After: 3600'); exit; }
    require_once __DIR__ . '/lib/csrf.php';

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
        fx_bp_respond(401, ['ok' => false, 'error' => 'unauthorized']);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET' && $method !== 'POST') {
        fx_bp_respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    $src = $method === 'POST' ? $_POST : $_GET;
    $action = isset($src['action']) && is_string($src['action']) ? $src['action'] : '';

    if ($method === 'GET') {
        if ($action === 'options') {
            fx_bp_respond(200, ['ok' => true] + fx_bp_options($pdo));
        }
        if ($action === 'list' || $action === 'match') {
            $filter = [
                'cat_mode' => in_array($src['cat_mode'] ?? '', ['all', 'uncat', 'name'], true) ? $src['cat_mode'] : 'all',
                'category' => is_string($src['category'] ?? null) ? $src['category'] : '',
                'panel' => is_string($src['panel'] ?? null) ? $src['panel'] : '',
                'q' => is_string($src['q'] ?? null) ? mb_substr($src['q'], 0, 200, 'UTF-8') : '',
            ];
            $all = fx_bp_match($pdo, $filter);
            if ($action === 'match') {
                fx_bp_respond(200, ['ok' => true, 'total' => count($all), 'items' => $all]);
            }
            $per = FX_BP_PAGE_SIZE;
            $total = count($all);
            $pages = max(1, (int)ceil($total / $per));
            $page = isset($src['page']) && ctype_digit((string)$src['page']) ? max(1, (int)$src['page']) : 1;
            if ($page > $pages) {
                $page = $pages;
            }
            fx_bp_respond(200, [
                'ok' => true,
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'per' => $per,
                'items' => array_slice($all, ($page - 1) * $per, $per),
            ]);
        }
        fx_bp_respond(400, ['ok' => false, 'error' => 'invalid_action']);
    }

    $token = fx_csrf_token();
    $incoming = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($incoming) || $incoming === '' || !hash_equals($token, $incoming)) {
        fx_bp_respond(403, ['ok' => false, 'error' => 'csrf_invalid']);
    }

    if ($action !== 'apply') {
        fx_bp_respond(400, ['ok' => false, 'error' => 'invalid_action']);
    }

    $operation = $_POST['operation'] ?? '';
    if (!is_string($operation) || !in_array($operation, ['increase', 'decrease'], true)) {
        fx_bp_respond(422, ['ok' => false, 'error' => 'invalid_operation']);
    }

    $amount = fx_bp_parse_amount($_POST['amount'] ?? null);
    if ($amount === null) {
        fx_bp_respond(422, ['ok' => false, 'error' => 'invalid_amount']);
    }

    $requestId = $_POST['request_id'] ?? '';
    if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestId)) {
        fx_bp_respond(422, ['ok' => false, 'error' => 'invalid_request_id']);
    }

    $rawItems = $_POST['items'] ?? '';
    $decoded = is_string($rawItems) && $rawItems !== '' ? json_decode($rawItems, true) : null;
    if (!is_array($decoded) || !$decoded) {
        fx_bp_respond(422, ['ok' => false, 'error' => $decoded === [] ? 'empty_selection' : 'invalid_payload']);
    }
    if (count($decoded) > FX_BP_MAX_SELECTION) {
        fx_bp_respond(422, ['ok' => false, 'error' => 'too_many']);
    }

    $expected = [];
    foreach ($decoded as $item) {
        if (!is_array($item) || !isset($item['id'], $item['price'])) {
            fx_bp_respond(422, ['ok' => false, 'error' => 'invalid_payload']);
        }
        $idRaw = (string)$item['id'];
        $priceRaw = (string)$item['price'];
        if (!ctype_digit($idRaw) || (int)$idRaw <= 0 || !ctype_digit($priceRaw) || strlen($priceRaw) > 12) {
            fx_bp_respond(422, ['ok' => false, 'error' => 'invalid_payload']);
        }
        $expected[(int)$idRaw] = (int)$priceRaw;
    }

    $result = fx_bp_apply($pdo, (string)$_SESSION['user'], $requestId, $operation, $amount, $expected);
    fx_bp_respond($result['status'], $result['body']);
} catch (\Throwable $e) {
    error_log('[bulk_price.php] request: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    fx_bp_respond(500, ['ok' => false, 'error' => 'server_error']);
}
