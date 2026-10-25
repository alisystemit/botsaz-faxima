<?php
/**
 * ابزار تشخیصی: چرا مینی‌اپ «دسترسی غیرمجاز» می‌دهد؟
 *
 * اجرا روی سرور:
 *   php tools/diag_auth.php '<initData از کنسول مرورگر>'
 *
 * دادهٔ ورودی را از داخل مینی‌اپ (داخل تلگرام) از کنسول مرورگر بگیرید:
 *   window.Telegram.WebApp.initData
 * و آن را به‌عنوان آرگومان اینجا بگذارید.
 */
error_reporting(E_ALL); ini_set('display_errors', '1');

$token = include __DIR__ . '/../config.php';
$token = is_array($token) ? (string)($token['main_token'] ?? '') : '';

$initData = $argv[1] ?? '';
echo "=== دیاگ‌نوزیک احراز هویت مینی‌اپ ===\n";
echo "main_token (اول ۲۵ کاراکتر): " . substr($token, 0, 25) . "...\n";
echo "طول initData: " . strlen($initData) . "\n\n";

if ($initData === '') {
    echo "[✘] initData خالی است — وباپ خارج از تلگرام باز شده یا JS هدر را نفرستاده.\n";
    exit(1);
}

parse_str($initData, $p);
$hash = $p['hash'] ?? '';
unset($p['hash']);
ksort($p);
$pairs = [];
foreach ($p as $k => $v) $pairs[] = $k . '=' . $v;

echo "auth_date = " . ($p['auth_date'] ?? '(ندارد)') . "\n";
echo "کاربر (user) = " . ($p['user'] ?? '(ندارد)') . "\n\n";

// چک ۱: auth_date
if (!isset($p['auth_date'])) {
    echo "[✘] چک ۱: auth_date اصلاً وجود ندارد.\n";
} else {
    $ad = (int)$p['auth_date'];
    $now = time();
    $diff = $now - $ad;
    echo "چک ۱ (auth_date): اکنون=" . $now . " ، auth_date=" . $ad . " ، اختلاف=" . $diff . " ثانیه\n";
    if ($diff > 86400) {
        echo "   [✘] initData منقضی است (بیش از ۲۴ ساعت). لینک کهنه را دوباره از منوی ربات باز کنید.\n";
    } elseif ($ad > $now + 300) {
        echo "   [✘] auth_date در «آینده» است — ساعت سرور جلوتر از تلگرام است (اختلاف " . ($ad - $now) . " ثانیه).\n";
        echo "   رفع: خروجی دستور `date` را بفرستید تا ساعت سرور را بررسی کنیم.\n";
    } else {
        echo "   [✔] auth_date در محدودهٔ مجاز است.\n";
    }
}
echo "\n";

// چک ۲: هش
$secret = hash_hmac('sha256', $token, 'WebAppData', true);
$calc  = hash_hmac('sha256', implode("\n", $pairs), $secret);
echo "چک ۲ (hash):\n";
echo "   hash دریافتی = " . $hash . "\n";
echo "   hash محاسبه = " . $calc . "\n";
if (hash_equals($calc, (string)$hash)) {
    echo "   [✔] هش معتبر است — امضا از همان ربات با main_token می‌آید.\n";
} else {
    echo "   [✘] هش نامعتبر است — مینی‌اپ از رباتِ دیگری باز شده یا main_token در config با رباتِ واقعی یکی نیست.\n";
}
echo "\n";

// چک ۳: آیدی کاربر و نقش
$user = json_decode($p['user'] ?? '{}', true);
$uid = (int)($user['id'] ?? 0);
echo "چک ۳ (کاربر):\n";
echo "   آیدی کاربر = " . $uid . "\n";
if ($uid <= 0) {
    echo "   [✘] آیدی کاربر معتبر نیست.\n";
} else {
    $cfg = include __DIR__ . '/../config.php';
    $SUPERS = [];
    if (is_array($cfg)) {
        $SUPERS = array_map('intval', (array)($cfg['super_admins'] ?? []));
    }
    $isSuper = in_array($uid, $SUPERS, true);
    echo "   super_admins در config = " . json_encode($SUPERS) . "\n";
    echo "   آیا این کاربر سوپرادمین است؟ " . ($isSuper ? '[✔] بله' : '[✘] خیر') . "\n";
    if (!$isSuper) {
        // فقط برای اطلاع؛ بعداً is_admin دیتابیس هم می‌تواند دسترسی دهد.
        try {
            require_once __DIR__ . '/../src/Store.php';
            $s = new Store((string)($cfg['manager_db'] ?? __DIR__ . '/../data/botsaz.sqlite'), $cfg);
            $st = $s->getPdo()->prepare('SELECT is_admin FROM users WHERE user_id=? LIMIT 1');
            $st->execute([$uid]);
            $row = $st->fetchColumn();
            echo "   is_admin در دیتابیس = " . var_export($row, true) . "\n";
        } catch (Throwable $e) {
            echo "   (نمی‌توان دیتابیس را خواند: " . $e->getMessage() . ")\n";
        }
    }
}
echo "\n=== پایان دیاگ‌نوزیک ===\n";
