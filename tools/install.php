<?php
// نصب اولیه: php tools/install.php
// کارها: ساخت پوشه‌ها، ساخت config.php از روی example، ساخت دیتابیس مدیریتی

$root = dirname(__DIR__);
foreach (['bots', 'data', 'templates/faxima', 'templates/mirza'] as $d) {
    if (!is_dir($root.'/'.$d)) { mkdir($root.'/'.$d, 0777, true); echo "mkdir $d\n"; }
}

if (!file_exists($root.'/config.php')) {
    copy($root.'/config.example.php', $root.'/config.php');
    echo "config.php ساخته شد — آن را ویرایش کن (توکن، ادمین‌ها، base_url، MySQL).\n";
} else {
    echo "config.php وجود دارد.\n";
}

$cfg = require $root.'/config.php';
require_once $root.'/src/Store.php';
$store = new Store($cfg['manager_db'], $cfg);
echo "manager DB OK (driver: {$store->getDriver()})\n";

// محافظت از پوشه data (Apache 2.4 — سینتکس قدیمی Deny from all فقط با mod_access_compat کار می‌کند)
file_put_contents($root.'/data/.htaccess', "Require all denied\n");

// نکته: bots/.htaccess دستی نوشته نمی‌شود چون باید index.php / table.php / cron/ را
// برای وبهوک ربات‌های فرزند باز بگذارد (فایل .htaccess این پوشه در مخزن نگه‌داری می‌شود).
if (!file_exists($root.'/bots/.htaccess')) {
    echo "⚠️  bots/.htaccess پیدا نشد — از نسخه موجود در مخزن کپی کن.\n";
}
echo "done.\n";
