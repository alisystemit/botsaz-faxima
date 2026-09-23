<?php
// ست وبهوک ربات اصلی: php tools/set_webhook.php
// یا: php tools/set_webhook.php https://domain/botsaz-faxima/bot.php

$root = dirname(__DIR__);
$cfg = require $root.'/config.php';
require_once $root.'/src/BotApi.php';
require_once $root.'/src/Manager.php';

$url = $argv[1] ?? (rtrim($cfg['base_url'], '/') . '/bot.php');
// bot.php هدر secret تلگرام را اجباری چک می‌کند — باید موقع ست وبهوک ارسال شود
$secret = Manager::faximaWebhookSecret($cfg['main_token']);
$res = BotApi::setWebhook($cfg['main_token'], $url, $secret);
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
