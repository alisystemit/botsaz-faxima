<?php
// ست وبهوک ربات اصلی: php tools/set_webhook.php
// یا: php tools/set_webhook.php https://domain/botsaz-faxima/bot.php

$root = dirname(__DIR__);
$cfg = require $root.'/config.php';
require_once $root.'/src/BotApi.php';

$url = $argv[1] ?? (rtrim($cfg['base_url'], '/') . '/bot.php');
$res = BotApi::setWebhook($cfg['main_token'], $url);
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
