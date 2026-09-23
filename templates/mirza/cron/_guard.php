<?php
// ===== گارد ورود اسکریپت‌های کرون میرزا =====
// * اجرای CLI (cron_dispatcher یا crontab با php) همیشه مجاز است.
// * دسترسی HTTP فقط با secret مشتق‌شده از توکن ربات مجاز است:
//   https://domain/bots/<slug>/cron/<script>.php?secret=sha256(token + "_mirza_cron_secret")

// ۱) مسیر کاری را به پوشه cron ببر تا require_once های نسبی داخل اسکریپت‌ها درست حل شوند
chdir(__DIR__);

// ۲) از CLI کاری به گارد نداریم
if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    return;
}

// ۳) توکن ربات را بدون اجرای کانفیگ (بدون اتصال دیتابیس) بخوان
$mirzaGuardCfg = dirname(__DIR__) . '/config.php';
$mirzaGuardToken = '';
if (is_readable($mirzaGuardCfg)) {
    $mirzaGuardRaw = (string) @file_get_contents($mirzaGuardCfg);
    if (preg_match('/\$APIKEY\s*=\s*[\'"]([^\'"]*)[\'"]/', $mirzaGuardRaw, $mirzaGuardM)) {
        $mirzaGuardToken = $mirzaGuardM[1];
    }
}
$mirzaGuardSecret = $mirzaGuardToken !== '' ? hash('sha256', $mirzaGuardToken . '_mirza_cron_secret') : '';
$mirzaGuardProvided = isset($_GET['secret']) && is_string($_GET['secret']) ? $_GET['secret'] : '';

if ($mirzaGuardSecret === '' || $mirzaGuardProvided === '' || !hash_equals($mirzaGuardSecret, $mirzaGuardProvided)) {
    http_response_code(403);
    exit('Forbidden');
}
