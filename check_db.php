<?php
require_once __DIR__ . '/src/Store.php';
$cfg = include __DIR__ . '/config.php';
$store = new Store($cfg['manager_db'], $cfg);
$pdo = $store->getPdo();

// Check tables
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables: " . implode(', ', $tables) . PHP_EOL . PHP_EOL;

// Check users count
$count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
echo "Users count: $count" . PHP_EOL;

// Check bots count
$botsCount = $pdo->query("SELECT COUNT(*) FROM bots")->fetchColumn();
echo "Bots count: $botsCount" . PHP_EOL;

// Show all users if any
if ($count > 0) {
    $rows = $pdo->query('SELECT user_id, first_name, username, is_admin, is_allowed, bot_limit FROM users')->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) {
        echo "ID: {$r['user_id']} | {$r['first_name']} | @{$r['username']} | admin:{$r['is_admin']} | allow:{$r['is_allowed']} | limit:{$r['bot_limit']}" . PHP_EOL;
    }
}