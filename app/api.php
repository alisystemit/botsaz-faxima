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
require_once $ROOT . '/src/Payment/Pricing.php';
require_once $ROOT . '/src/Payment/ZarinPal.php';
require_once $ROOT . '/src/Payment/AqaPay.php';
require_once $ROOT . '/src/Payment/NowPayments.php';
require_once $ROOT . '/src/SelfUpdate.php';
require_once $ROOT . '/src/SourceUpdate.php';

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
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per  = min(100, max(5, (int)($_GET['per'] ?? 30)));
            $off  = ($page - 1) * $per;
            $total = (int)$pdo->query("SELECT COUNT(*) FROM bots")->fetchColumn();
            $rows = $pdo->query("SELECT b.*, u.username, u.first_name FROM bots b LEFT JOIN users u ON u.user_id=b.owner_id ORDER BY b.id DESC LIMIT $per OFFSET $off")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['token'] = ''; }
            out(200, ['ok' => true, 'bots' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
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
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per  = min(100, max(5, (int)($_GET['per'] ?? 30)));
            $off  = ($page - 1) * $per;
            $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $rows = $pdo->query("SELECT user_id, first_name, username, is_admin, is_allowed, bot_limit, build_count, created_at FROM users ORDER BY user_id DESC LIMIT $per OFFSET $off")->fetchAll(PDO::FETCH_ASSOC);
            // سوپرادمین‌ها همیشه مجازند، حتی اگر رکوردشان در جدول نباشد (مثلاً
            // قبل از اولین پیام). بدون این، پنل به ادمینِ خودش «معلق» نشان می‌داد
            // و سقفش هم بی‌معنا بود — در حالی که در ربات کاملاً ادمین است.
            foreach ($rows as &$r) {
                $r['is_super'] = in_array((int)$r['user_id'], $SUPERS, true);
                if ($r['is_super']) {
                    $r['is_admin'] = 1;
                    $r['is_allowed'] = 1;
                    if ((int)($r['bot_limit'] ?? 0) < 1) $r['bot_limit'] = 1;
                } else {
                    $r['is_super'] = false;
                }
            }
            unset($r);
            out(200, ['ok' => true, 'users' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
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
            $from = (string)($_GET['from'] ?? '');
            $to   = (string)($_GET['to'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per  = min(100, max(5, (int)($_GET['per'] ?? 30)));
            $off  = ($page - 1) * $per;
            $where = []; $args = [];
            if ($st !== '')   { $where[] = 'status=?';          $args[] = $st; }
            if ($from !== '') { $where[] = "created_at >= ?";  $args[] = $from . ' 00:00:00'; }
            if ($to !== '')   { $where[] = "created_at <= ?";  $args[] = $to . ' 23:59:59'; }
            $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $c = $pdo->prepare("SELECT COUNT(*) FROM payments $w"); $c->execute($args);
            $total = (int)$c->fetchColumn();
            $q = $pdo->prepare("SELECT * FROM payments $w ORDER BY id DESC LIMIT $per OFFSET $off");
            $q->execute($args);
            out(200, ['ok' => true, 'payments' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per' => $per]);
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
            require_once $ROOT . '/src/Payment/ZarinPal.php';
            require_once $ROOT . '/src/Payment/AqaPay.php';
            require_once $ROOT . '/src/Payment/NowPayments.php';
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
                'zarin_merchant' => PaymentZarin::merchantId($store),
                'zarin_sandbox' => PaymentZarin::isSandbox($store),
                'aqaye_pin' => PaymentAqaye::pin($store),
                'aqaye_sandbox' => PaymentAqaye::isSandbox($store),
                'nowpay_key' => trim((string)($store->getSetting('pay_nowpay_api_key', '') ?? '')),
                'nowpay_ipn' => trim((string)($store->getSetting('pay_nowpay_ipn_secret', '') ?? '')),
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
                case 'zarin_merchant':
                    PaymentZarin::setCredentials($store, $v, PaymentZarin::isSandbox($store));
                    break;
                case 'zarin_sandbox':
                    PaymentZarin::setSandbox($store, $v === '1');
                    break;
                case 'aqaye_pin':
                    PaymentAqaye::setPin($store, $v);
                    break;
                case 'aqaye_sandbox':
                    PaymentAqaye::setSandbox($store, $v === '1');
                    break;
                case 'nowpay_key':
                    $store->setSetting('pay_nowpay_api_key', trim($v));
                    break;
                case 'nowpay_ipn':
                    $store->setSetting('pay_nowpay_ipn_secret', trim($v));
                    break;
                case 'child_owner_username':
                    $store->setSetting('child_owner_username', trim($v));
                    break;
                case 'child_owner_password':
                    $store->setSetting('child_owner_password', trim($v));
                    break;
                case 'child_owner_reset':
                    $store->setSetting('child_owner_username', '');
                    $store->setSetting('child_owner_password', '');
                    break;
                default: out(400, ['ok' => false, 'msg' => 'کلید نامعتبر']);
            }
            out(200, ['ok' => true]);
        }

        case 'templates': {
            // نکته: Manager::templates() یک آرایهٔ «کلید ⇒ مشخصات» است، نه لیست.
            // قبلاً همین ساختار خام به کلاینت می‌رفت و JS با ‎.map‎ روی آبجکت
            // می‌شکست (TypeError: .map is not a function) ⇒ صفحهٔ «قالب‌ها»
            // همیشه «خطا در دریافت» نشان می‌داد. اینجا به لیستِ استاندارد تبدیل
            // می‌شود و فیلدهای لازم برای UI هم اضافه می‌گردد.
            $installed = Manager::availableTypes();
            $minPhp = [];
            foreach (array_keys(Manager::templates()) as $type) {
                try { $minPhp[$type] = Manager::templateMinPhp($type); } catch (Throwable $e) { $minPhp[$type] = null; }
            }
            $list = [];
            foreach (Manager::templates() as $type => $spec) {
                $list[] = [
                    'type'      => $type,
                    'label'     => (string)($spec['label'] ?? $type),
                    'icon'      => (string)($spec['icon'] ?? '🧩'),
                    'dir'       => (string)($spec['dir'] ?? $type),
                    'db'        => (string)($spec['db'] ?? ''),
                    'schema'    => (string)($spec['schema'] ?? ''),
                    'cron'      => (string)($spec['cron'] ?? ''),
                    'repo'      => (string)($spec['repo'] ?? ''),
                    'min_php'   => $minPhp[$type] ?? null,
                    'installed' => isset($installed[$type]),
                ];
            }
            out(200, ['ok' => true, 'templates' => $list, 'types' => $installed]);
        }

        case 'revenue_daily': {
            $pdo = $store->getPdo();
            $q = $pdo->query("SELECT substr(created_at,1,10) d, COALESCE(SUM(amount),0) total, COUNT(*) c FROM payments WHERE status IN ('paid','used') GROUP BY d ORDER BY d DESC LIMIT 14");
            $rows = array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
            out(200, ['ok' => true, 'days' => $rows]);
        }

        case 'backup_get': {
            require_once $ROOT . '/src/DbBackup.php';
            out(200, ['ok' => true, 'settings' => DbBackup::settings($cfg, $store), 'state' => DbBackup::readState()]);
        }

        case 'backup_set': {
            require_once $ROOT . '/src/DbBackup.php';
            $enabled = (string)($_POST['enabled'] ?? '1') === '1';
            $timesIn = (string)($_POST['times'] ?? '');
            $parsed = DbBackup::parseTimes($timesIn);
            if (!$parsed['ok']) out(400, ['ok' => false, 'msg' => $parsed['error']]);
            $saved = DbBackup::saveSettings($store, $enabled, $parsed['times']);
            out(200, ['ok' => true, 'settings' => $saved]);
        }

        case 'payments_export': {
            // خروجی کامل CSV: همهٔ ردیف‌های منطبق با فیلتر (نه فقط صفحهٔ فعلی)
            $pdo = $store->getPdo();
            $st = (string)($_GET['status'] ?? '');
            $from = (string)($_GET['from'] ?? '');
            $to   = (string)($_GET['to'] ?? '');
            $where = []; $args = [];
            if ($st !== '')   { $where[] = 'status=?';         $args[] = $st; }
            if ($from !== '') { $where[] = "created_at >= ?"; $args[] = $from . ' 00:00:00'; }
            if ($to !== '')   { $where[] = "created_at <= ?"; $args[] = $to . ' 23:59:59'; }
            $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $q = $pdo->prepare("SELECT * FROM payments $w ORDER BY id DESC LIMIT 5000");
            $q->execute($args);
            out(200, ['ok' => true, 'rows' => $q->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'logs_page': {
            // صفحه‌بندی لاگ: فقط یک پنجره از انتهای فایل خوانده می‌شود
            $dir = $ROOT . '/data/logs';
            $files = glob($dir . '/*.log') ?: [];
            rsort($files);
            if (!$files) out(200, ['ok' => true, 'lines' => [], 'file' => '', 'hasMore' => false]);
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $per   = min(200, max(20, (int)($_GET['per'] ?? 60)));
            $skip  = ($page - 1) * $per;
            $fh = @fopen($files[0], 'rb');
            if (!$fh) out(200, ['ok' => true, 'lines' => [], 'file' => basename($files[0]), 'hasMore' => false]);
            $size = (int)filesize($files[0]);
            // برای صفحهٔ n حدود n*per خط از انتها لازم است
            $want = ($skip + $per + 1) * 200;
            $want = (int)min(max($want, 32 * 1024), max($size, 1));
            fseek($fh, max(0, $size - $want));
            $buf = (string)fread($fh, $want);
            fclose($fh);
            $all = array_values(array_filter(array_map('rtrim', explode("\n", $buf)), 'strlen'));
            $total = count($all);
            $start = max(0, $total - $skip - $per);
            $lines = array_slice($all, $start, $per);
            out(200, ['ok' => true, 'file' => basename($files[0]), 'lines' => $lines,
                      'page' => $page, 'hasMore' => ($start > 0)]);
        }

        case 'prices_get': {
            require_once $ROOT . '/src/Payment/Pricing.php';
            $out = [];
            foreach (Manager::availableTypes() as $type => $label) {
                $out[] = ['type' => $type, 'label' => $label, 'price' => PaymentPricing::templatePrice($store, $type)];
            }
            out(200, ['ok' => true, 'prices' => $out, 'limit_price' => PaymentPricing::limitUnitPrice($store)]);
        }

        case 'price_set': {
            require_once $ROOT . '/src/Payment/Pricing.php';
            $type = (string)($_POST['type'] ?? '');
            $v = max(0, (int)preg_replace('/\D/', '', (string)($_POST['v'] ?? '0')));
            if ($type !== '') PaymentPricing::setTemplatePrice($store, $type, $v);
            else PaymentPricing::setLimitUnitPrice($store, $v);
            out(200, ['ok' => true]);
        }

        case 'users_growth': {
            $pdo = $store->getPdo();
            try {
                $q = $pdo->query("SELECT substr(created_at,1,10) d, COUNT(*) c FROM users GROUP BY d ORDER BY d DESC LIMIT 14");
                $rows = array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e) { $rows = []; }
            out(200, ['ok' => true, 'days' => $rows]);
        }

        case 'logs_tail': {
            $dir = $ROOT . '/data/logs';
            $files = glob($dir . '/*.log') ?: [];
            rsort($files);
            if (!$files) out(200, ['ok' => true, 'lines' => []]);
            // فقط انتهای فایل خوانده می‌شود (فایل لاگ تا ۵MB بزرگ می‌شود)
            $fh = @fopen($files[0], 'rb');
            if (!$fh) out(200, ['ok' => true, 'lines' => []]);
            $size = (int)filesize($files[0]);
            $chunk = 32 * 1024;
            fseek($fh, max(0, $size - $chunk));
            $buf = (string)fread($fh, $chunk);
            fclose($fh);
            $tail = array_values(array_filter(array_slice(explode("\n", $buf), -50)));
            out(200, ['ok' => true, 'file' => basename($files[0]), 'lines' => array_map('rtrim', $tail)]);
        }

        // ===== بروزرسانی سورس قالب‌ها =====
        case 'src_status': {
            try {
                $st = SelfUpdate::templatesStatus();
                $rows = [];
                foreach ((array)($st['repos'] ?? []) as $key => $r) {
                    $rows[] = [
                        'type'    => $key,
                        'label'   => Manager::templateLabel($key),
                        'repo'    => (string)($r['repo'] ?? ''),
                        'ok'      => !empty($r['ok']),
                        'changed' => !empty($r['changed']),
                        'old'     => substr((string)($r['old'] ?? ''), 0, 7),
                        'new'     => substr((string)($r['new'] ?? ''), 0, 7),
                        'files'   => (int)($st['templates'][$key] ?? 0),
                    ];
                }
                out(200, ['ok' => true, 'rows' => $rows, 'behind' => (int)($st['behind'] ?? 0),
                          'branch' => (string)($st['branch'] ?? '?')]);
            } catch (Throwable $e) {
                out(500, ['ok' => false, 'msg' => 'خطا در خواندن وضعیت: ' . $e->getMessage()]);
            }
        }

        case 'src_update': {
            // اجرای همگام‌سازی قالب‌ها (git pull هر ریپو). کاری به ربات‌های
            // ساخته‌شده ندارد — آن‌ها جداگانه با bot_update به‌روز می‌شوند.
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین']);
            @set_time_limit(0);
            try {
                $r = SelfUpdate::runTemplatesOnly(600);
                out(200, ['ok' => !empty($r['ok']),
                          'out' => mb_substr(trim((string)($r['out'] ?? '')), -1200),
                          'queued' => !empty($r['queued'])]);
            } catch (Throwable $e) {
                out(500, ['ok' => false, 'msg' => $e->getMessage()]);
            }
        }

        // ===== بروزرسانی ربات‌های ساخته‌شده =====
        case 'bot_plan': {
            // plan می‌گوید چه فایل‌هایی از قالب عوض شده‌اند و چه‌ها «محافظت‌شده»
            // (config.php و دیتابیس) هستند و دست‌نخورده می‌مانند.
            $out = [];
            foreach ($store->allBots() as $bot) {
                $folder = (string)($bot['folder'] ?? '');
                if ($folder === '') continue;
                try {
                    $p = SourceUpdate::plan((string)$bot['type'], $folder);
                    $out[] = [
                        'id'        => (int)$bot['id'],
                        'folder'    => $folder,
                        'username'  => (string)($bot['bot_username'] ?? ''),
                        'type'      => (string)$bot['type'],
                        'label'     => Manager::templateLabel((string)$bot['type']),
                        'ok'        => !empty($p['ok']),
                        'error'     => (string)($p['error'] ?? ''),
                        'new'       => (int)($p['counts']['new'] ?? 0),
                        'changed'   => (int)($p['counts']['changed'] ?? 0),
                        'obsolete'  => (int)($p['counts']['obsolete'] ?? 0),
                        'skipped'   => (int)($p['counts']['skipped'] ?? 0),
                        'config_new'=> !empty($p['config_new']),
                        'outdated'  => !empty($p['out_of_date']),
                    ];
                } catch (Throwable $e) {
                    $out[] = ['id' => (int)$bot['id'], 'folder' => $folder, 'ok' => false,
                              'error' => $e->getMessage(), 'username' => (string)($bot['bot_username'] ?? '')];
                }
            }
            out(200, ['ok' => true, 'bots' => $out]);
        }

        case 'bot_update': {
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین']);
            $id = (int)($_POST['id'] ?? 0);
            $bot = $store->botById($id);
            if (!$bot) out(404, ['ok' => false, 'msg' => 'ربات یافت نشد']);
            $folder = (string)$bot['folder'];
            try {
                $plan = SourceUpdate::plan((string)$bot['type'], $folder);
                if (empty($plan['ok'])) out(400, ['ok' => false, 'msg' => (string)($plan['error'] ?? 'برنامه‌ریزی ناموفق')]);
                if (empty($plan['new']) && empty($plan['changed'])) {
                    out(200, ['ok' => true, 'msg' => 'این ربات با قالبش هماهنگ است ✅', 'applied' => 0]);
                }
                @set_time_limit(0);
                $res = SourceUpdate::apply($plan, $uid);
                $note = trim(implode(' • ', array_map('strval', (array)($res['notes'] ?? []))));
                out(200, ['ok' => !empty($res['ok']),
                          'msg' => mb_substr($note !== '' ? $note : (string)($res['error'] ?? ''), -600),
                          'applied' => (int)($res['applied'] ?? 0),
                          'skipped' => (int)($res['skipped'] ?? 0),
                          'config_new' => !empty($res['config_new'])]);
            } catch (Throwable $e) {
                out(500, ['ok' => false, 'msg' => $e->getMessage()]);
            }
        }

        case 'bot_update_all': {
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین']);
            @set_time_limit(0);
            $done = 0; $skip = 0; $errs = [];
            foreach ($store->allBots() as $bot) {
                try {
                    $plan = SourceUpdate::plan((string)$bot['type'], (string)$bot['folder']);
                    if (empty($plan['ok'])) { $skip++; continue; }
                    if (empty($plan['new']) && empty($plan['changed'])) { $skip++; continue; }
                    $res = SourceUpdate::apply($plan, $uid);
                    if (!empty($res['ok'])) $done++; else $errs[] = $bot['folder'];
                } catch (Throwable $e) { $errs[] = ($bot['folder'] ?? '?') . ': ' . $e->getMessage(); }
            }
            out(200, ['ok' => true, 'updated' => $done, 'skipped' => $skip, 'errors' => $errs]);
        }

        case 'child_owner_get': {
            // اکانت سازندهٔ پنل برای قالب‌هایی که «پنل نمایندگی» دارند (پاسارگاد).
            // اگر خالی باشد، هنگام ساخت ربات خودکار ساخته می‌شود.
            out(200, ['ok' => true,
                'username' => (string)($store->getSetting('child_owner_username', '') ?? ''),
                'password' => (string)($store->getSetting('child_owner_password', '') ?? ''),
            ]);
        }

        case 'system_status': {
            $pdo = $store->getPdo();
            out(200, ['ok' => true,
                'php' => PHP_VERSION,
                'driver' => $store->getDriver(),
                'version' => Manager::APP_VERSION,
                'users' => (int)$store->countUsers(),
                'bots' => (int)$store->countBots(),
                'disk_free' => diskFree(),
                'fx_rate' => (string)($store->getSetting('fx_rate', '') ?? ''),
                'maintenance' => BuildSettings::maintenanceOn($store),
                'maintenance_eta' => BuildSettings::maintenanceEta($store),
                'uptime' => @file_exists('/proc/uptime') ? (int)floatval(file_get_contents('/proc/uptime')) : null,
            ]);
        }

        case 'fx_rate_get': {
            require_once $ROOT . '/src/FxRate.php';
            out(200, ['ok' => true,
                'rate' => (string)($store->getSetting(FxRate::K_RATE, '') ?? ''),
                'updated' => (string)($store->getSetting(FxRate::K_UPDATED, '') ?? ''),
                'source' => (string)($store->getSetting(FxRate::K_SOURCE, '') ?? ''),
            ]);
        }

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
    // محدودیت زمانی سخت‌گیرانه‌تر: بیش از ۲۴ ساعت = رد (قبلاً ۷ روز بود)
    if (isset($p['auth_date']) && (time() - (int)$p['auth_date'] > 86400 || (int)$p['auth_date'] > time() + 300)) deny();
    $user = json_decode($p['user'] ?? '{}', true);
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) deny();
    $u = $store->user($uid);
    if (in_array($uid, $supers, true) || (int)($u['is_admin'] ?? 0) === 1) return $uid;
    try { require_once dirname(__DIR__) . '/src/Logger.php'; Logger::getInstance()->error('miniapp', 'تلاش دسترسی غیرمجاز', ['uid' => $uid]); } catch (Throwable $e) {}
    deny();
}

function deny(): void
{
    out(403, ['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
}

function isSuper(array $supers, int $uid): bool
{
    return in_array((string)$uid, array_map('strval', $supers), true);
}

/**
 * فضای آزاد دیسک به بایت.
 *
 * disk_free_space روی مسیرهایی که وجود ندارند false برمی‌گرداند و مقدار
 * false در JSON می‌شود «0» — که برای ادمین یعنی «دیسک پر است». برای همین
 * چند مسیر امتحان می‌شود و اگر هیچ‌کدام جواب نداد null برمی‌گردد تا UI
 * بتواند «نامشخص» نشان دهد به‌جای عدد گمراه‌کننده.
 */
function diskFree(): ?int
{
    $root = dirname(__DIR__);
    foreach ([$root, __DIR__, $root . '/data', sys_get_temp_dir(), '.'] as $p) {
        $free = @disk_free_space($p);
        if ($free !== false && $free > 0) return (int)$free;
    }
    return null;
}

function out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
