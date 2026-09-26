<?php
// ست وبهوک ربات اصلی: php tools/set_webhook.php
// یا: php tools/set_webhook.php https://domain/botsaz-faxima/bot.php

$root = dirname(__DIR__);
$cfg = require $root.'/config.php';
require_once $root.'/src/BotApi.php';
require_once $root.'/src/Manager.php';

$url = $argv[1] ?? (rtrim($cfg['base_url'], '/') . '/bot.php');

// ===== بررسی دسترسی مسیر (مشکل رایج: /root permission denied) =====
// اگر bot.php را نمی‌توان بخوانی، وبهوک هیچ‌وقت کار نمی‌کنه
$botPhp = $root . '/bot.php';
if (!is_readable($botPhp)) {
    fwrite(STDERR, "❌ bot.php قابل خواندن نیست ($botPhp)\n");
    fwrite(STDERR, "   دلیل احتمالی: /root دسترسی execute نداره\n");
    fwrite(STDERR, "   حل: chmod o+x /root\n");
    fwrite(STDERR, "   یا: bash tools/install.sh برای فیکس خودکار\n");
    exit(1);
}

// ===== گاردهای تشخیصی قبل از فراخوانی تلگرام =====
// قبلاً فقط JSON خامِ 404 چاپ می‌شد و معلوم نبود چرا شکست خورده.
$token = (string)($cfg['main_token'] ?? '');
if ($token === '' || $token === 'PUT_MAIN_BOT_TOKEN_HERE') {
    fwrite(STDERR, "❌ main_token هنوز placeholder است — ست وبهوک معنا ندارد.\n");
    fwrite(STDERR, "   توکن واقعی @BotFather را در config.php مقدار main_token بگذار و دوباره اجرا کن.\n");
    exit(1);
}
if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
    fwrite(STDERR, "⚠️  main_token فرمت استاندارد تلگرام (number:token) را ندارد — احتمالاً 404 می‌گیری.\n");
}
if (stripos($url, 'https://') !== 0) {
    fwrite(STDERR, "⚠️  آدرس وبهوک ({$url}) عمومی و https نیست — تلگرام آن را رد می‌کند.\n");
    fwrite(STDERR, "   base_url را به یک دامنهٔ https عمومی تغییر بده (یا روی لوکال ngrok بزن).\n");
}

// bot.php هدر secret تلگرام را اجباری چک می‌کند — باید موقع ست وبهوک ارسال شود
$secret = Manager::faximaWebhookSecret($token);
$res = BotApi::setWebhook($token, $url, $secret);
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// تفسیر خطای رایج: 404 یعنی توکن نامعتبر/باطل است، نه مشکل شبکه
if (empty($res['ok'])) {
    $code = (int)($res['error_code'] ?? 0);
    if ($code === 404) {
        fwrite(STDERR, "❌ تلگرام 404 داد: توکن نامعتبر است یا باطل شده (از @BotFather دوباره /token بگیر).\n");
    } elseif ($code === 0) {
        fwrite(STDERR, "❌ اتصال به api.telegram.org برقرار نشد — شبکه/DNS/فایروال را چک کن.\n");
    } else {
        fwrite(STDERR, "❌ ست وبهوک ناموفق: " . ($res['description'] ?? 'unknown') . "\n");
    }
}
// کد خروج برای install.sh: فقط زمانی 0 که API واقعاً ok:true برگرداند
exit(empty($res['ok']) ? 1 : 0);
