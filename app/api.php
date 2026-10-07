<?php
/**
 * API مینی‌اپ ربات‌ساز.
 * احراز هویت با initData تلگرام (HMAC-SHA256 روی main_token) + محدود به ادمین‌ها.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/Store.php';
require_once $ROOT . '/src/Manager.php';
require_once $ROOT . '/src/BuildSettings.php';
require_once $ROOT . '/src/Payment/Payments.php';
require_once $ROOT . '/src/Payment/Gateways.php';
require_once $ROOT . '/src/Payment/CardToCard.php';

$cfg = @include $ROOT . '/config.php';
if (!is_array($cfg)) {
    out(500, ['ok' => false, 'msg' => 'config.php یافت نشد']);
}
$TOKEN  = (string)($cfg['main_token'] ?? '');
$SUPERS = array_map('intval', (array)($cfg['super_admins'] ?? []));

$store = new Store((string)($cfg['manager_db'] ?? ($ROOT . '/data/botsaz.sqlite')), $cfg);

$action = (string)($_REQUEST['action'] ?? '');
$initData = (string)($_POST['initData'] ?? $_GET['initData'] ?? '');

$uid = auth($initData, $TOKEN, $SUPERS, $store);

try {
    switch ($action) {
        case 'me':
            out(200, ['ok' => true, 'user_id' => $uid, 'is_super' => in_array($uid, $SUPERS, true)]);

        case 'dashboard': {
            $pdo = $store->getPdo();
            $users = (int)$store->countUsers();
            $bots  = (int)$store->countBots();
            $pending = (int)$store->countPendingRequests();
            $openPay = (int)Payments::pendingAdminCount($store);
            $revenue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status IN ('paid','used')")->fetchColumn();
            $byType = $pdo->query("SELECT type, COUNT(*) c FROM bots GROUP BY type")->fetchAll(PDO::FETCH_ASSOC);
            $recentBots = $pdo->query("SELECT id, type, folder, bot_username, status, created_at FROM bots ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            $recentPays = Payments::pendingAdminList($store, 5);
            out(200, ['ok' => true, 'users' => $users, 'bots' => $bots, 'pending' => $pending,
                      'open_payments' => $openPay, 'revenue' => $revenue, 'by_type' => $byType,
                      'recent_bots' => $recentBots, 'recent_payments' => $recentPays]);
        }

        case 'bots_list': {
            $pdo = $store->getPdo();
            $rows = $pdo->query("SELECT b.*, u.username, u.first_name FROM bots b LEFT JOIN users u ON u.user_id=b.owner_id ORDER BY b.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['token'] = ''; }
            out(200, ['ok' => true, 'bots' => $rows]);
        }

        case 'bot_status': {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'active');
            if (!in_array($status, ['active', 'disabled'], true)) out(400, ['ok' => false, 'msg' => 'وضعیت نامعتبر']);
            $store->setBotStatus($id, $status);
            out(200, ['ok' => true]);
        }

        case 'bot_delete': {
            $id = (int)($_POST['id'] ?? 0);
            $bot = $store->botById($id);
            if (!$bot) out(404, ['ok' => false, 'msg' => 'یافت نشد']);
            try {
                $dir = Manager::childBotsDir() . '/' . $bot['folder'];
                if (is_dir($dir)) Manager::removeDir($dir);
            } catch (Throwable $e) { /* پوشه شاید نباشد */ }
            $store->deleteBot($id);
            out(200, ['ok' => true]);
        }

        case 'users_list': {
            $pdo = $store->getPdo();
            $rows = $pdo->query("SELECT user_id, first_name, username, is_admin, is_allowed, bot_limit, build_count, created_at FROM users ORDER BY user_id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
            out(200, ['ok' => true, 'users' => $rows]);
        }

        case 'user_toggle_allow': {
            $uid2 = (int)($_POST['user_id'] ?? 0); $v = (int)($_POST['v'] ?? 0);
            $store->setAllowed($uid2, $v ? 1 : 0);
            out(200, ['ok' => true]);
        }

        case 'user_toggle_admin': {
            $uid2 = (int)($_POST['user_id'] ?? 0); $v = (int)($_POST['v'] ?? 0);
            if (in_array($uid2, $SUPERS, true)) out(403, ['ok' => false, 'msg' => 'سوپرادمین قابل تغییر نیست']);
            $store->setAllowed($uid2, (int)($store->user($uid2)['is_allowed'] ?? 0), $v ? 1 : 0);
            out(200, ['ok' => true]);
        }

        case 'user_set_limit': {
            $uid2 = (int)($_POST['user_id'] ?? 0); $n = max(0, (int)($_POST['limit'] ?? 0));
            Payments::setUserLimit($store, $uid2, $n);
            out(200, ['ok' => true]);
        }

        case 'pending_list':
            out(200, ['ok' => true, 'requests' => $store->getPendingRequests()]);

        case 'pending_approve': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$store->approveRequest($id)) out(400, ['ok' => false, 'msg' => 'درخواست نامعتبر یا قبلاً پردازش شده']);
            out(200, ['ok' => true]);
        }

        case 'pending_decline': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$store->declineRequest($id)) out(400, ['ok' => false, 'msg' => 'درخواست نامعتبر']);
            out(200, ['ok' => true]);
        }

        case 'payments_list': {
            $pdo = $store->getPdo();
            $st = (string)($_GET['status'] ?? '');
            if ($st !== '') {
                $q = $pdo->prepare("SELECT * FROM payments WHERE status=? ORDER BY id DESC LIMIT 100");
                $q->execute([$st]);
            } else {
                $q = $pdo->query("SELECT * FROM payments ORDER BY id DESC LIMIT 100");
            }
            out(200, ['ok' => true, 'payments' => $q->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'payment_approve': {
            $id = (int)($_POST['id'] ?? 0);
            $p = Payments::approveByAdmin($store, $id);
            if (!$p) out(400, ['ok' => false, 'msg' => 'این پرداخت قابل تأیید نیست']);
            out(200, ['ok' => true, 'note' => $p['grant_note'] ?? '']);
        }

        case 'payment_decline': {
            $id = (int)($_POST['id'] ?? 0);
            Payments::setStatus($store, $id, Payments::ST_DECLINED);
            out(200, ['ok' => true]);
        }

        case 'settings_get': {
            $gateways = [];
            foreach (PaymentGateways::keys() as $k) {
                $gateways[] = ['key' => $k, 'label' => PaymentGateways::label($k), 'enabled' => PaymentGateways::isEnabled($store, $k)];
            }
            out(200, ['ok' => true,
                'maintenance' => BuildSettings::maintenanceOn($store),
                'maintenance_eta' => BuildSettings::maintenanceEta($store),
                'approval_required' => BuildSettings::approvalRequired($store),
                'fx_rate' => (string)($store->getSetting('fx_rate', '') ?? ''),
                'limit_price' => (string)($store->getSetting('pay_limit_price', '') ?? ''),
                'card_number' => PaymentCard::getCardNumber($store),
                'card_owner' => PaymentCard::getCardOwner($store),
                'gateways' => $gateways,
            ]);
        }

        case 'settings_set': {
            $k = (string)($_POST['k'] ?? '');
            $v = (string)($_POST['v'] ?? '');
            switch ($k) {
                case 'maintenance':   BuildSettings::setMaintenance($store, $v === '1'); break;
                case 'approval':      BuildSettings::setApprovalRequired($store, $v === '1'); break;
                case 'maintenance_eta': BuildSettings::setMaintenanceEta($store, $v); break;
                case 'limit_price':   $store->setSetting('pay_limit_price', $v); break;
                case 'card_number':   PaymentCard::setCard($store, $v, PaymentCard::getCardOwner($store)); break;
                case 'card_owner':    PaymentCard::setCard($store, PaymentCard::getCardNumber($store), $v); break;
                case 'gateway':
                    $gk = (string)($_POST['key'] ?? '');
                    PaymentGateways::setEnabled($store, $gk, $v === '1');
                    break;
                default: out(400, ['ok' => false, 'msg' => 'کلید نامعتبر']);
            }
            out(200, ['ok' => true]);
        }

        case 'templates':
            out(200, ['ok' => true, 'templates' => Manager::templates(), 'types' => Manager::availableTypes()]);

        default:
            out(404, ['ok' => false, 'msg' => 'action نامعتبر']);
    }
} catch (Throwable $e) {
    out(500, ['ok' => false, 'msg' => $e->getMessage()]);
}

function auth(string $initData, string $token, array $supers, Store $store): int
{
    if ($initData === '' || $token === '') deny();
    parse_str($initData, $p);
    $hash = $p['hash'] ?? '';
    unset($p['hash']);
    ksort($p);
    $pairs = [];
    foreach ($p as $k => $v) $pairs[] = $k . '=' . $v;
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $calc = hash_hmac('sha256', implode("\n", $pairs), $secret);
    if (!hash_equals($calc, (string)$hash)) deny();
    if (isset($p['auth_date']) && time() - (int)$p['auth_date'] > 86400 * 7) deny();
    $user = json_decode($p['user'] ?? '{}', true);
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) deny();
    $u = $store->user($uid);
    if (in_array($uid, $supers, true) || (int)($u['is_admin'] ?? 0) === 1) return $uid;
    deny();
}

function deny(): void
{
    out(403, ['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
}

function out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
