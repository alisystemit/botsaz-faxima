<?php


if (!function_exists('fx_session_start')) {

    function fx_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_httponly', '1');
        session_start();
    }
}

if (!function_exists('fx_csrf_token')) {

    function fx_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('fx_csrf_field')) {

    function fx_csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(fx_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('fx_same_origin_post')) {

    /**
     * بررسی Origin/Referer برای فرم‌های قدیمی که هنوز فیلد _csrf ندارند.
     * این جایگزین توکن نیست، ولی SameSite=Lax + Origin معتبر جلوی CSRF
     * cross-site را می‌گیرد تا فرم‌های قدیمی بعد از فعال‌سازی گارد نشکنند.
     */
    function fx_same_origin_post(): bool
    {
        $normalize = static function (string $host): string {
            $host = strtolower($host);
            return preg_replace('/:(80|443)$/', '', $host) ?? $host;
        };
        $host = $normalize((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') return false;
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
            $url = (string)($_SERVER[$key] ?? '');
            if ($url === '') continue;
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) continue;
            $originHost = $normalize((string)$parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''));
            if (hash_equals($host, $originHost)) return true;
        }
        return false;
    }
}

if (!function_exists('fx_csrf_guard')) {

    function fx_csrf_guard(): void
    {
        $token = fx_csrf_token();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        @header('Referrer-Policy: no-referrer');
        @header('X-Content-Type-Options: nosniff');
        $incoming = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $valid = is_string($incoming) && $incoming !== '' && hash_equals($token, $incoming);
        if (!$valid && fx_same_origin_post()) {
            $valid = true;
        }
        if (!$valid) {
            http_response_code(403);
            if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
            } else {
                echo 'درخواست نامعتبر — توکن CSRF اشتباه است';
            }
            exit;
        }
    }
}
