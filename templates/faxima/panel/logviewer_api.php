<?php

if (!defined('FAOXIMA_SKIP_BOTAPI_ROUTER')) {
    define('FAOXIMA_SKIP_BOTAPI_ROUTER', true);
}

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', '1');
ini_set('display_errors', '0');
session_start();

function lv_json(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    echo $out === false ? '{"ok":false,"code":"encode"}' : $out;
    exit;
}

function lv_fail(string $code, int $status): void
{
    lv_json(['ok' => false, 'code' => $code], $status);
}

try {
    ob_start();
    require_once __DIR__ . '/../config.php';
    if (!@include_once __DIR__ . '/lib/ad_boot.php') { http_response_code(503); header('Retry-After: 3600'); exit; }
    ob_end_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    lv_fail('server', 500);
}

require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/logviewer.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    lv_fail('server', 500);
}

$lvUser = (string)($_SESSION['user'] ?? '');
if ($lvUser === '') {
    lv_fail('auth', 401);
}
try {
    $lvAllowed = lv_admin_allowed($pdo, $lvUser);
} catch (Throwable $e) {
    lv_fail('server', 500);
}
if (!$lvAllowed) {
    lv_fail('forbidden', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : '';

$postActions = ['delete_file', 'delete_bulk'];
if (in_array($action, $postActions, true)) {
    if ($method !== 'POST') {
        lv_fail('method', 405);
    }
    fx_csrf_guard();
} elseif ($method !== 'GET') {
    lv_fail('method', 405);
}

session_write_close();

function lv_snapshot_secret(): string
{
    $t = (string)($_SESSION['csrf_token'] ?? '');
    if ($t === '') {
        lv_fail('auth', 401);
    }
    return $t . '|' . (string)($_SESSION['user'] ?? '');
}

function lv_param_id(): string
{
    $v = $_REQUEST['id'] ?? '';
    return is_string($v) ? $v : '';
}

function lv_param_cursors(): array
{
    $raw = $_GET['cursors'] ?? '';
    if (!is_string($raw) || $raw === '' || strlen($raw) > 16000) {
        return [];
    }
    $d = json_decode($raw, true);
    $out = [];
    if (is_array($d)) {
        foreach ($d as $k => $v) {
            if (is_int($v) || is_float($v)) {
                $out[(string)$k] = (int)$v;
            }
        }
    }
    return $out;
}

function lv_param_limit(int $default = 50): int
{
    $l = isset($_GET['limit']) ? (int)$_GET['limit'] : $default;
    return max(1, min(LV_MAX_LIMIT, $l));
}

function lv_param_source(): ?string
{
    $s = $_GET['source'] ?? '';
    return in_array($s, ['logs', 'php'], true) ? $s : null;
}

switch ($action) {
    case 'list':
        $files = lv_discover();
        $counts = ['logs' => 0, 'php' => 0, 'support' => 0, 'binary' => 0];
        foreach ($files as $e) {
            if ($e['kind'] === 'support') {
                $counts['support']++;
            } else {
                $counts[$e['source']]++;
                if ($e['binary']) {
                    $counts['binary']++;
                }
            }
        }
        lv_json([
            'ok' => true,
            'files' => $files,
            'counts' => $counts,
            'logs_dir' => is_dir(lv_root() . '/logs'),
            'server_time' => date('Y-m-d H:i:s'),
        ]);
        break;

    case 'stats':
        lv_json(['ok' => true] + lv_stats());
        break;

    case 'read':
        $filt = lv_parse_filters($_GET);
        $limit = lv_param_limit();
        $id = lv_param_id();
        if ($id === '*') {
            $res = lv_read_merge(lv_param_cursors(), $limit, $filt, lv_param_source());
            lv_json(['ok' => true, 'mode' => 'merge'] + $res);
        }
        $v = lv_validate($id);
        if (!$v['ok']) {
            lv_fail($v['code'] === 'missing' ? 'missing' : 'invalid', $v['code'] === 'missing' ? 404 : 400);
        }
        if ($v['cls']['kind'] !== 'log') {
            lv_fail('unsupported', 400);
        }
        if (!is_readable($v['abs'])) {
            lv_fail('permission', 403);
        }
        if (lv_is_binary($v['abs'])) {
            lv_fail('binary', 415);
        }
        $before = isset($_GET['before']) && $_GET['before'] !== '' ? max(0, (int)$_GET['before']) : null;
        $res = lv_read_page($v, $before, $limit, $filt, LV_PAGE_BUDGET);
        if (isset($res['error'])) {
            lv_fail($res['error'], 403);
        }
        clearstatcache(true, $v['abs']);
        lv_json(['ok' => true, 'mode' => 'file', 'mtime' => (int)@filemtime($v['abs'])] + $res);
        break;

    case 'delete_preview':
        $scope = $_GET['scope'] ?? '';
        if (!in_array($scope, ['logs', 'php', 'all'], true)) {
            lv_fail('invalid', 400);
        }
        $cand = lv_delete_candidates($scope);
        $bytes = 0;
        foreach ($cand as $e) {
            $bytes += $e['size'];
        }
        lv_json(['ok' => true, 'scope' => $scope, 'count' => count($cand), 'bytes' => $bytes, 'snapshot' => lv_snapshot_token($scope, $cand, lv_snapshot_secret())]);
        break;

    case 'download':
        $filt = lv_parse_filters($_GET);
        $mode = ($_GET['mode'] ?? 'raw') === 'json' ? 'json' : 'raw';
        $id = lv_param_id();
        if ($mode === 'json') {
            $events = [];
            if ($id === '*') {
                $res = lv_read_merge([], 1000, $filt, lv_param_source());
                $events = $res['events'];
                $name = 'logs-export';
            } else {
                $v = lv_validate($id);
                if (!$v['ok'] || $v['cls']['kind'] !== 'log') {
                    lv_fail('invalid', 400);
                }
                if (!is_readable($v['abs'])) {
                    lv_fail('permission', 403);
                }
                if (lv_is_binary($v['abs'])) {
                    lv_fail('binary', 415);
                }
                $res = lv_read_page($v, null, 1000, $filt, 16777216);
                if (isset($res['error'])) {
                    lv_fail($res['error'], 403);
                }
                $events = $res['events'];
                $name = basename($id);
            }
            foreach ($events as &$ev) {
                unset($ev['k'], $ev['tse']);
            }
            unset($ev);
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '.json"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo json_encode(['exported_at' => date('c'), 'count' => count($events), 'events' => $events], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            exit;
        }
        $v = lv_validate($id);
        if (!$v['ok'] || $v['cls']['kind'] !== 'log') {
            lv_fail('invalid', 400);
        }
        if (!is_readable($v['abs'])) {
            lv_fail('permission', 403);
        }
        if (lv_is_binary($v['abs'])) {
            lv_fail('binary', 415);
        }
        $fh = @fopen($v['abs'], 'rb');
        if (!$fh) {
            lv_fail('permission', 403);
        }
        $size = (int)@filesize($v['abs']);
        $start = max(0, $size - LV_EXPORT_BYTES);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($id));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.txt"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($start > 0) {
            header('X-Export-Truncated: 1');
        }
        fseek($fh, $start);
        $carry = '';
        $first = $start > 0;
        while (!feof($fh)) {
            $piece = fread($fh, 262144);
            if ($piece === false || $piece === '') {
                break;
            }
            $buf = $carry . $piece;
            if ($first) {
                $p = strpos($buf, "\n");
                if ($p === false) {
                    $carry = $buf;
                    continue;
                }
                $buf = substr($buf, $p + 1);
                $first = false;
            }
            $cut = strrpos($buf, "\n");
            if ($cut === false) {
                $carry = $buf;
                continue;
            }
            $carry = substr($buf, $cut + 1);
            echo lv_redact_string(lv_utf8(substr($buf, 0, $cut + 1)));
        }
        if ($carry !== '' && !$first) {
            echo lv_redact_string(lv_utf8($carry));
        }
        fclose($fh);
        exit;

    case 'delete_file':
        $id = lv_param_id();
        $r = lv_delete_one($id);
        $status = $r['status'];
        $http = 200;
        if ($status === 'unsafe' || $status === 'protected') {
            $http = 403;
        }
        lv_json(['ok' => $status === 'deleted' || $status === 'missing', 'result' => $r], $http);
        break;

    case 'delete_bulk':
        $scope = $_POST['scope'] ?? '';
        if (!in_array($scope, ['logs', 'php', 'all'], true) || ($_POST['confirm'] ?? '') !== '1') {
            lv_fail('invalid', 400);
        }
        $cand = lv_delete_candidates($scope);
        $secret = lv_snapshot_secret();
        $given = isset($_POST['snapshot']) && is_string($_POST['snapshot']) ? $_POST['snapshot'] : '';
        $current = lv_snapshot_token($scope, $cand, $secret);
        if ($given === '' || !hash_equals($current, $given)) {
            $bytes = 0;
            foreach ($cand as $e) {
                $bytes += $e['size'];
            }
            lv_json(['ok' => false, 'code' => 'changed', 'count' => count($cand), 'bytes' => $bytes, 'snapshot' => $current], 409);
        }
        $summary = ['deleted' => 0, 'missing' => 0, 'permission' => 0, 'failed' => 0, 'protected' => 0, 'unsafe' => 0];
        $failures = [];
        $deletedIds = [];
        foreach ($cand as $e) {
            $r = lv_delete_one($e['id']);
            $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1;
            if ($r['status'] === 'deleted' && count($deletedIds) < 500) {
                $deletedIds[] = $e['id'];
            }
            if (!in_array($r['status'], ['deleted', 'missing'], true) && count($failures) < 50) {
                $failures[] = ['id' => $e['id'], 'status' => $r['status']];
            }
        }
        $bad = $summary['permission'] + $summary['failed'] + $summary['protected'] + $summary['unsafe'];
        lv_json(['ok' => $bad === 0, 'scope' => $scope, 'total' => count($cand), 'summary' => $summary, 'failures' => $failures, 'deleted_ids' => $deletedIds]);
        break;

    default:
        lv_fail('invalid', 400);
}
