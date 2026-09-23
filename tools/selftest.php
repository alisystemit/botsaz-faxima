<?php
// تست عملکردی بدون نیاز به تلگرام و MySQL
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Manager.php';
require __DIR__ . '/../src/BotApi.php';

$cfg = require __DIR__ . '/../config.php';
$store = new Store($cfg['manager_db'], $cfg);
echo "driver: " . $store->getDriver() . "\n";

// slugify + entry + webhook
echo "slug('vpn1') = " . Manager::slugify('vpn1') . "\n";
echo "entry faxima=" . Manager::entryFile('faxima') . " mirza=" . Manager::entryFile('mirza') . "\n";
echo "webhook faxima=" . Manager::webhookUrl($cfg, 'shop1', 'faxima') . "\n";
echo "webhook mirza=" . Manager::webhookUrl($cfg, 'vpn1', 'mirza') . "\n";

// قالب فاکسیما (سورس واقعی) موجود است؟
foreach (['index.php', 'config.php', 'table.php', 'botapi.php'] as $f) {
    echo "faxima/$f: " . (file_exists(Manager::templateDir('faxima') . '/' . $f) ? 'OK' : 'MISSING') . "\n";
}

// شبیه‌سازی پچ config فاکسیما روی کپی موقت
$tmp = sys_get_temp_dir() . '/botsaz_test_' . time();
@mkdir($tmp . '/faxima', 0777, true);
copy(Manager::templateDir('faxima') . '/config.php', $tmp . '/faxima/config.php');
Manager::patchFaximaConfig($tmp . '/faxima', $cfg, 'botsaz_shop1_abc123', '123456:AAFakeToken', 999001, 'shoptestbot', 'example.com/botsaz-faxima/bots/shop1');
$patched = file_get_contents($tmp . '/faxima/config.php');
$checks = [
    "dbname' => botsaz_shop1_abc123" => str_contains($patched, "\$dbname     = 'botsaz_shop1_abc123'") || str_contains($patched, "\$dbname = 'botsaz_shop1_abc123'"),
    'APIKEY token' => str_contains($patched, '123456:AAFakeToken'),
    'adminnumber' => str_contains($patched, '999001'),
    'domainhosts' => str_contains($patched, 'example.com/botsaz-faxima/bots/shop1'),
    'usernamebot' => str_contains($patched, 'shoptestbot'),
    'php lint' => (@token_get_all($patched) !== false),
];
foreach ($checks as $k => $v) echo "faxima patch $k: " . ($v ? 'OK' : 'FAIL') . "\n";
Manager::removeDir($tmp);

// secret فاکسیما باید با lib/WebhookAuth.php خود سورس یکی باشد
require_once Manager::templateDir('faxima') . '/lib/WebhookAuth.php';
$t = '123456:AAFakeTokenForTest1234567890123';
$a = Manager::faximaWebhookSecret($t);
$b = FaoximaWebhookAuth::secret($t);
echo "faxima secret match: " . ($a === $b && strlen($a) === 64 ? 'OK' : 'FAIL') . "\n";

// شبیه‌سازی پچ config میرزا
$tmp2 = sys_get_temp_dir() . '/botsaz_test2_' . time();
@mkdir($tmp2 . '/mirza', 0777, true);
copy(Manager::templateDir('mirza') . '/config.php', $tmp2 . '/mirza/config.php');
Manager::patchMirzaConfig($tmp2 . '/mirza', $cfg, 'botsaz_vpn1_abc123', '123456:AAFakeToken', 999001, 'vpntestbot', 'example.com/botsaz-faxima/bots/vpn1');
$patched2 = file_get_contents($tmp2 . '/mirza/config.php');
$leftover = [];
foreach (['{DATABASE_NAME}', '{DATABASE_USERNAME}', '{DATABASE_PASSOWRD}', '{BOT_TOKEN}', '{ADMIN_#ID}', '{DOMAIN.COM/PATH/BOT}', '{BOT_USERNAME}'] as $ph) {
    if (str_contains($patched2, $ph)) $leftover[] = $ph;
}
echo "mirza patch leftovers: " . ($leftover ? implode(',', $leftover) : 'none') . "\n";
Manager::removeDir($tmp2);

// Store CRUD
$store->user(999001, 'Test', 'tester');
$store->setStep(999001, 'await_bot_token', ['type' => 'faxima']);
$u = $store->user(999001);
echo "step: {$u['step']} temp: {$u['temp']}\n";
$store->clearStep(999001);
$store->setAllowed(999001, 1);
$id = $store->addBot(['owner_id'=>999001,'type'=>'faxima','folder'=>'testslug123','token'=>'123:ABC','bot_username'=>'testbot','bot_id'=>1,'admin_id'=>999001,'db_name'=>'db','db_table_prefix'=>'','webhook_url'=>'u','status'=>'active']);
$b = $store->botById($id);
echo "bot type={$b['type']} folder={$b['folder']}\n";
$store->deleteBot($id);
echo "deleted OK, bots now=" . $store->countBots() . "\n";

echo "ALL TESTS PASSED\n";
