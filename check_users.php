<?php
require_once __DIR__ . '/src/Store.php';
$cfg = include __DIR__ . '/config.php';
$store = new Store($cfg['manager_db'], $cfg);
$pdo = $store->getPdo();
$rows = $pdo->query('SELECT user_id, first_name, username, is_admin, is_allowed FROM users ORDER BY user_id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) {
    echo $r['user_id'] . ' | ' . $r['first_name'] . ' | ' . ($r['username']?:'-') . ' | admin:' . $r['is_admin'] . ' | allow:' . $r['is_allowed'] . PHP_EOL;
}