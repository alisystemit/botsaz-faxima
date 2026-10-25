<?php
/**
 * API مینی‌اپ ربات‌ساز.
 * احراز هویت با initData تلگرام (HMAC-SHA256 روی main_token) + محدود به ادمین‌ها.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/Store.php';
require_once $ROOT . '/src/SuperAdmins.php';
require_once $ROOT . '/src/Manager.php';
require_once $ROOT . '/src/BotApi.php';
require_once $ROOT . '/src/BotLifecycle.php';
require_once $ROOT . '/src/BuildSettings.php';
require_once $ROOT . '/src/Payment/Payments.php';
require_once $ROOT . '/src/Payment/Gateways.php';
require_once $ROOT . '/src/Payment/CardToCard.php';
require_once $ROOT . '/src/Payment/Pricing.php';
require_once $ROOT . '/src/Payment/ZarinPal.php';
require_once $ROOT . '/src/Payment/AqaPay.php';
require_once $ROOT . '/src/Payment/NowPayments.php';
require_once $ROOT . '/src/Payment/Limits.php';
require_once $ROOT . '/src/SelfUpdate.php';
require_once $ROOT . '/src/SourceUpdate.php';

$cfg = @include $ROOT . '/config.php';
if (!is_array($cfg)) {
    out(500, ['ok' => false, 'msg' => 'config.php یافت نشد']);
}
$TOKEN  = (string)($cfg['main_token'] ?? '');

try {
    $store = new Store((string)($cfg['manager_db'] ?? ($ROOT . '/data/botsaz.sqlite')), $cfg);
    // سوپرادمین‌های مؤثر = config.php + افزوده‌شده‌ها از پنل (settings.super_admins).
    // قبل از auth استفاده می‌شود تا سوپرادمینِ تازه‌اضافه‌شده هم بتواند وارد شود.
    $SUPERS = SuperAdmins::effective($cfg, $store);

    $action = (string)($_REQUEST['action'] ?? '');
    // initData فقط از هدر یا بدنهٔ POST خوانده می‌شود؛ NEVER از query string.
    // در query، این رشتهٔ معتبر تا ۲۴ ساعت در access log/Referer/تاریخچهٔ مرورگر
    // می‌ماند و هر کسی با دسترسی به لاگ می‌تواند نقش ادمین را replay کند.
    $initData = (string)($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? $_POST['initData'] ?? '');

    $uid = auth($initData, $TOKEN, $SUPERS, $store);
} catch (Throwable $e) {
    internalError($e, 'bootstrap');
}
// برای AuditLog و BotLifecycle؛ مقدار غیرحساس است.
$GLOBALS['__botsaz_actor'] = $uid;

try {
    switch ($action) {
        case 'me':
            out(200, ['ok' => true, 'user_id' => $uid, 'is_super' => isSuper($SUPERS, $uid)]);

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
            // فیلتر اختیاری مالک: «ربات‌های این کاربر» باید سمت سرور فیلتر شود.
            // قبلاً فقط صفحهٔ اول (۳۰ ربات) گرفته می‌شد و فیلتر در مرورگر انجام
            // می‌شد؛ برای کاربری که رباتش در صفحه‌های بعد بود «رباتی ندارد»
            // نشان داده می‌شد، در حالی که ربات داشت.
            $uidFilter = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
            $where = $uidFilter > 0 ? ' WHERE b.owner_id=' . $uidFilter : '';
            $total = (int)$pdo->query("SELECT COUNT(*) FROM bots b" . $where)->fetchColumn();
            $rows = $pdo->query("SELECT b.*, u.username, u.first_name FROM bots b LEFT JOIN users u ON u.user_id=b.owner_id" . $where . " ORDER BY b.id DESC LIMIT $per OFFSET $off")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['token'] = ''; }
            out(200, ['ok' => true, 'bots' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
        }

        case 'bot_status': {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'active');
            if ($id <= 0) out(400, ['ok' => false, 'msg' => 'شناسهٔ ربات نامعتبر است']);
            if (!in_array($status, ['active', 'disabled'], true)) out(400, ['ok' => false, 'msg' => 'وضعیت نامعتبر']);
            $bot = $store->botById($id);
            if (!$bot) out(404, ['ok' => false, 'msg' => 'ربات یافت نشد']);
            if (!isSuper($SUPERS, $uid) && (int)($bot['owner_id'] ?? 0) !== $uid) {
                out(403, ['ok' => false, 'msg' => 'فقط مالک ربات یا سوپرادمین می‌تواند وضعیت را تغییر دهد']);
            }
            $r = $status === 'disabled'
                ? BotLifecycle::disable($cfg, $store, $bot)
                : BotLifecycle::enable($cfg, $store, $bot);
            audit('bot_status', ['id' => $id, 'status' => $status, 'ok' => $r['ok'] ?? false]);
            if (empty($r['ok'])) out(502, ['ok' => false, 'msg' => (string)($r['error'] ?? 'تغییر وضعیت ناموفق بود')]);
            out(200, ['ok' => true]);
        }

        case 'bot_delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) out(400, ['ok' => false, 'msg' => 'شناسهٔ ربات نامعتبر است']);
            $bot = $store->botById($id);
            if (!$bot) out(404, ['ok' => false, 'msg' => 'یافت نشد']);
            if (!isSuper($SUPERS, $uid) && (int)($bot['owner_id'] ?? 0) !== $uid) {
                out(403, ['ok' => false, 'msg' => 'فقط مالک ربات یا سوپرادمین می‌تواند ربات را حذف کند']);
            }
            $r = BotLifecycle::delete($cfg, $store, $bot);
            audit('bot_delete', ['id' => $id, 'folder' => (string)($bot['folder'] ?? ''), 'ok' => $r['ok'] ?? false]);
            if (empty($r['ok'])) out(500, ['ok' => false, 'msg' => (string)($r['error'] ?? 'حذف ناموفق بود')]);
            out(200, ['ok' => true, 'warnings' => $r['warnings'] ?? [], 'backup' => $r['backup'] ?? '']);
        }

        case 'users_list': {
            $pdo = $store->getPdo();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per  = min(100, max(5, (int)($_GET['per'] ?? 30)));
            $off  = ($page - 1) * $per;
            $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            // شمارش ربات هر کاربر: هم برای نمایش «ساخته/ربات» در فهرست، هم برای
            // اینکه ادمین پیش از بازکردن جزئیات بداند هیچ رباتی گم نشده.
            $rows = $pdo->query("SELECT user_id, first_name, username, is_admin, is_allowed, bot_limit, build_count, created_at, (SELECT COUNT(*) FROM bots b WHERE b.owner_id = users.user_id) AS bots FROM users ORDER BY user_id DESC LIMIT $per OFFSET $off")->fetchAll(PDO::FETCH_ASSOC);
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
            if ($uid2 <= 0) out(400, ['ok' => false, 'msg' => 'شناسهٔ کاربر نامعتبر است']);
            if (!userExists($store, $uid2)) out(404, ['ok' => false, 'msg' => 'کاربر یافت نشد']);
            $store->setAllowed($uid2, $v ? 1 : 0);
            audit('user_toggle_allow', ['user_id' => $uid2, 'v' => $v ? 1 : 0]);
            out(200, ['ok' => true]);
        }

        case 'user_toggle_admin': {
            // تغییر نقش، یک عملیات حساس است؛ فقط سوپرادمین.
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین می‌تواند نقش ادمین را تغییر دهد']);
            $uid2 = (int)($_POST['user_id'] ?? 0); $v = (int)($_POST['v'] ?? 0);
            if ($uid2 <= 0) out(400, ['ok' => false, 'msg' => 'شناسهٔ کاربر نامعتبر است']);
            if (isSuper($SUPERS, $uid2)) out(403, ['ok' => false, 'msg' => 'سوپرادمین قابل تغییر نیست']);
            if (!userExists($store, $uid2)) out(404, ['ok' => false, 'msg' => 'کاربر یافت نشد']);
            $store->setAllowed($uid2, (int)($store->user($uid2)['is_allowed'] ?? 0), $v ? 1 : 0);
            audit('user_toggle_admin', ['user_id' => $uid2, 'v' => $v ? 1 : 0]);
            out(200, ['ok' => true]);
        }

        case 'user_set_limit': {
            // سقف ساخت کاربر، تنظیم عملیاتی است؛ هر ادمین می‌تواند آن را تغییر دهد.
            $uid2 = (int)($_POST['user_id'] ?? 0); $n = max(0, (int)($_POST['limit'] ?? 0));
            if ($uid2 <= 0) out(400, ['ok' => false, 'msg' => 'شناسهٔ کاربر نامعتبر است']);
            if (!userExists($store, $uid2)) out(404, ['ok' => false, 'msg' => 'کاربر یافت نشد']);
            Payments::setUserLimit($store, $uid2, $n);
            audit('user_set_limit', ['user_id' => $uid2, 'limit' => $n]);
            out(200, ['ok' => true]);
        }

        case 'super_list': {
            // فهرست سوپرادمین‌های مؤثر: کانفیگ (قابل حذف نیست) + افزوده‌شده‌ها از پنل
            out(200, ['ok' => true,
                'config'  => SuperAdmins::fromConfig($cfg),
                'runtime' => SuperAdmins::runtime($store),
            ]);
        }

        case 'super_add': {
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین می‌تواند سوپرادمین اضافه کند']);
            $uid2 = (int)($_POST['user_id'] ?? 0);
            if ($uid2 <= 0) out(400, ['ok' => false, 'msg' => 'شناسهٔ کاربر نامعتبر است']);
            // جلوگیری از اشتباه تایپی: کاربر باید حداقل یک‌بار ربات را شروع کرده باشد.
            if (!userExists($store, $uid2)) out(404, ['ok' => false, 'msg' => 'کاربر یافت نشد؛ اول باید ربات را /start کند']);
            $r = SuperAdmins::add($cfg, $store, $uid2);
            if (!$r['ok']) out(400, ['ok' => false, 'msg' => $r['error']]);
            out(200, ['ok' => true]);
        }

        case 'super_remove': {
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین می‌تواند سوپرادمین حذف کند']);
            $uid2 = (int)($_POST['user_id'] ?? 0);
            $r = SuperAdmins::remove($cfg, $store, $uid2);
            if (!$r['ok']) out(400, ['ok' => false, 'msg' => $r['error']]);
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
            $pay = $id > 0 ? Payments::getPayment($store, $id) : null;
            if (!$pay) out(404, ['ok' => false, 'msg' => 'پرداخت یافت نشد']);
            $p = Payments::approveByAdmin($store, $id);
            audit('payment_approve', ['id' => $id, 'ok' => (bool)$p]);
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
            // کلیدهای درگاه فقط برای سوپرادمین کامل برگردانده می‌شوند.
            // هر is_admin قبلاً می‌توانست nowpay_ipn را ببیند و با آن IPN جعلی بسازد.
            $isSuper = isSuper($SUPERS, $uid);
            $secret = function (string $v) use ($isSuper): string {
                if ($isSuper) return $v;
                return $v === '' ? '' : ('••••' . substr($v, -4));
            };
            out(200, ['ok' => true,
                'maintenance' => BuildSettings::maintenanceOn($store),
                'maintenance_eta' => BuildSettings::maintenanceEta($store),
                'approval_required' => BuildSettings::approvalRequired($store),
                'fx_rate' => (string)($store->getSetting('fx_rate', '') ?? ''),
                'limit_price' => (string)($store->getSetting('pay_limit_price', '') ?? ''),
                'card_number' => PaymentCard::getCardNumber($store),
                'card_owner' => PaymentCard::getCardOwner($store),
                'gateways' => $gateways,
                'is_super' => $isSuper,
                'zarin_merchant' => $secret(PaymentZarin::merchantId($store)),
                'zarin_sandbox' => PaymentZarin::isSandbox($store),
                'aqaye_pin' => $secret(PaymentAqaye::pin($store)),
                'aqaye_sandbox' => PaymentAqaye::isSandbox($store),
                'nowpay_key' => $secret(trim((string)($store->getSetting('pay_nowpay_api_key', '') ?? ''))),
                'nowpay_ipn' => $secret(trim((string)($store->getSetting('pay_nowpay_ipn_secret', '') ?? ''))),
            ]);
        }

        case 'settings_set': {
            $k = (string)($_POST['k'] ?? '');
            $v = (string)($_POST['v'] ?? '');
            $secretKeys = ['zarin_merchant', 'aqaye_pin', 'nowpay_key', 'nowpay_ipn'];
            // دسترسی به این endpoint از قبل از طریق auth() به ادمین‌ها/سوپرادمین‌ها
            // محدود شده است. کارت مقصد/درگاه/قیمت تنظیمات عملیاتی فروشگاه‌اند،
            // نه راز درجه‌یک؛ بنابراین هر ادمین می‌تواند آن‌ها را پیکربندی کند.
            // (حفاظت باقی‌مانده: خواندن کلیدهای راز برای غیرسوپرادمین ماسک می‌شود و
            // مقدار ماسک‌شده را نمی‌توان دوباره ذخیره کرد.)
            if (in_array($k, $secretKeys, true)) {
                if (strpos($v, '••••') !== false) {
                    out(400, ['ok' => false, 'msg' => 'مقدار ماسک‌شده را نمی‌توان ذخیره کرد؛ کلید کامل را وارد کنید']);
                }
            }
            switch ($k) {
                case 'maintenance':   BuildSettings::setMaintenance($store, $v === '1'); break;
                case 'approval':      BuildSettings::setApprovalRequired($store, $v === '1'); break;
                case 'maintenance_eta':
                    if (mb_strlen($v) > 200) out(400, ['ok' => false, 'msg' => 'متن زمان تعمیرات بیش از حد طولانی است']);
                    BuildSettings::setMaintenanceEta($store, $v);
                    break;
                case 'limit_price': {
                    $digits = preg_replace('/\D/', '', $v) ?? '';
                    if ($digits === '') out(400, ['ok' => false, 'msg' => 'قیمت سقف باید عدد باشد']);
                    $store->setSetting('pay_limit_price', (string)(int)$digits);
                    break;
                }
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
                default: out(400, ['ok' => false, 'msg' => 'کلید نامعتبر']);
            }
            if (in_array($k, $secretKeys, true)) audit('settings_secret_set', ['key' => $k]);
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
                    // قالب غیر PHP (PGClockBot) نه ساختنی است نه «نصب‌نشدهٔ معیوب»؛
                    // UI باید «نصب جداگانه» نشان دهد، نه دکمهٔ نصب/آماده.
                    'buildable' => Manager::isBuildable($type),
                    'runtime'   => (string)($spec['runtime'] ?? 'php'),
                    'requires'  => array_values((array)($spec['requires'] ?? [])),
                    'docs'      => (string)($spec['docs'] ?? ''),
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
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین']);
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
            $seek = max(0, $size - $want);
            fseek($fh, $seek);
            $buf = (string)fread($fh, $want);
            fclose($fh);
            // اگر از وسط یک خط شروع کرده‌ایم، بقیهٔ آن خط را دور بریز؛ وگرنه
            // خط اولِ صفحه با کاراکترهای شکسته/ناقص UTF-8 نمایش داده می‌شود.
            if ($seek > 0 && ($nl = strpos($buf, "\n")) !== false) {
                $buf = substr($buf, $nl + 1);
            }
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
            // قیمت‌گذاری تنظیم عملیاتی فروشگاه است؛ هر ادمین (auth قبلاً ادمین
            // بودن را می‌سنجد) می‌تواند آن را تغییر دهد.
            require_once $ROOT . '/src/Payment/Pricing.php';
            $type = (string)($_POST['type'] ?? '');
            $v = max(0, (int)preg_replace('/\D/', '', (string)($_POST['v'] ?? '0')));
            if ($type !== '') {
                if (Manager::templateSpec($type) === null) out(400, ['ok' => false, 'msg' => 'قالب نامعتبر است']);
                PaymentPricing::setTemplatePrice($store, $type, $v);
            } else {
                PaymentPricing::setLimitUnitPrice($store, $v);
            }
            audit('price_set', ['type' => $type, 'value' => $v]);
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
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین']);
            $dir = $ROOT . '/data/logs';
            $files = glob($dir . '/*.log') ?: [];
            rsort($files);
            if (!$files) out(200, ['ok' => true, 'lines' => []]);
            // فقط انتهای فایل خوانده می‌شود (فایل لاگ تا ۵MB بزرگ می‌شود)
            $fh = @fopen($files[0], 'rb');
            if (!$fh) out(200, ['ok' => true, 'lines' => []]);
            $size = (int)filesize($files[0]);
            $chunk = 32 * 1024;
            $seek = max(0, $size - $chunk);
            fseek($fh, $seek);
            $buf = (string)fread($fh, $chunk);
            fclose($fh);
            // اگر از وسط خط/کاراکتر شروع شده‌ایم، خط ناقص اول را دور بریزیم؛
            // وگرنه json_encode روی UTF-8 ناقص false می‌شود و پاسخ خالی می‌رسد.
            if ($seek > 0 && ($nl = strpos($buf, "\n")) !== false) {
                $buf = substr($buf, $nl + 1);
            }
            $tail = array_values(array_filter(array_slice(explode("\n", $buf), -50), 'strlen'));
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
                internalError($e, 'src_status');
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
                internalError($e, 'src_update');
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
                              'error' => Manager::sanitizeDbError($e->getMessage()), 'username' => (string)($bot['bot_username'] ?? '')];
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
                          'failed' => (int)($res['failed'] ?? 0),
                          'reverted' => (int)($res['reverted'] ?? 0),
                          'failed_files' => array_values((array)($res['failed_files'] ?? [])),
                          'config_new' => !empty($res['config_new'])]);
            } catch (Throwable $e) {
                internalError($e, 'bot_update');
            }
        }

        case 'bot_update_all': {
            if (!isSuper($SUPERS, $uid)) out(403, ['ok' => false, 'msg' => 'فقط سوپرادمین']);
            @set_time_limit(0);
            $done = 0; $skip = 0; $errs = [];
            foreach ($store->allBots() as $bot) {
                try {
                    $plan = SourceUpdate::plan((string)$bot['type'], (string)$bot['folder']);
                    if (empty($plan['ok'])) {
                        $errs[] = ($bot['folder'] ?? '?') . ': ' . (string)($plan['error'] ?? 'plan failed');
                        continue;
                    }
                    if (empty($plan['new']) && empty($plan['changed'])) { $skip++; continue; }
                    $res = SourceUpdate::apply($plan, $uid);
                    if (!empty($res['ok'])) $done++; else $errs[] = $bot['folder'];
                } catch (Throwable $e) { $errs[] = ($bot['folder'] ?? '?') . ': ' . Manager::sanitizeDbError($e->getMessage()); }
            }
            out(200, ['ok' => $errs === [], 'updated' => $done, 'skipped' => $skip, 'errors' => $errs]);
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
    internalError($e, $action);
}

/**
 * خطای داخلی: جزئیات فقط در لاگ سرور، پاسخ عمومی با request_id.
 * قبلاً $e->getMessage() مستقیم به کلاینت می‌رفت و می‌توانست DSN، مسیر فایل،
 * نام کاربری دیتابیس یا Query را لو بدهد.
 */
function internalError(Throwable $e, string $where = ''): void
{
    $rid = bin2hex(random_bytes(6));
    // action از ورودی کاربر می‌آید؛ قبل از لاگ، کاراکترهای کنترلی/خط جدید را
    // حذف می‌کنیم تا کسی نتواند خط جعلی در audit/log تزریق کند.
    $where = preg_replace('/[^A-Za-z0-9_.-]/', '', $where) ?: 'unknown';
    try {
        require_once dirname(__DIR__) . '/src/Logger.php';
        Logger::getInstance()->error('miniapp', 'internal error [' . $rid . '] ' . $where . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } catch (Throwable $ignored) {
        error_log('[miniapp][' . $rid . '] ' . $where . ': ' . $e->getMessage());
    }
    out(500, ['ok' => false, 'code' => 'internal_error', 'request_id' => $rid,
              'msg' => 'خطای داخلی سرور رخ داد. کد پیگیری: ' . $rid]);
}

function userExists(Store $store, int $uid): bool
{
    if ($uid <= 0) return false;
    $st = $store->getPdo()->prepare('SELECT 1 FROM users WHERE user_id=? LIMIT 1');
    $st->execute([$uid]);
    return $st->fetchColumn() !== false;
}

/** ثبت سادهٔ Audit برای عملیات حساس (بدون هیچ مقدار محرمانه‌ای). */
function audit(string $action, array $meta = []): void
{
    $uid = (int)($GLOBALS['__botsaz_actor'] ?? 0);
    $line = json_encode([
        'at' => date('c'),
        'actor' => $uid,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'action' => $action,
        'meta' => $meta,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($line)) return;
    $dir = dirname(__DIR__) . '/data/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/audit.log';
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    @chmod($file, 0640);
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
    // auth_date اجباری است؛ بدون آن، initData قدیمی/بدون تاریخ قابل استفاده می‌شد.
    if (!isset($p['auth_date'])) deny();
    // محدودیت زمانی سخت‌گیرانه‌تر: بیش از ۲۴ ساعت = رد (قبلاً ۷ روز بود)
    if (time() - (int)$p['auth_date'] > 86400 || (int)$p['auth_date'] > time() + 300) deny();
    $user = json_decode($p['user'] ?? '{}', true);
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) deny();
    // عمداً از Store::user() استفاده نمی‌کنیم: آن متد برای هر کاربر تلگرام یک
    // رکورد می‌سازد، حتی اگر ادمین نباشد. مینی‌اپ فقط باید کاربران موجود را
    // بخواند؛ سوپرادمین‌های config هم بدون رکورد مجازند.
    $st = $store->getPdo()->prepare('SELECT is_admin FROM users WHERE user_id=? LIMIT 1');
    $st->execute([$uid]);
    $isAdminRow = $st->fetchColumn();
    if (isSuper($supers, $uid) || (int)$isAdminRow === 1) return $uid;
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
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
