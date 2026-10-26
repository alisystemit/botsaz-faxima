<?php

/**
 * Channel_Report در نصب‌های قدیمی NULL می‌ماند، در حالی که همهٔ مصرف‌کننده‌ها
 * آن را رشته فرض می‌کنند (strlen روی NULL در PHP 8.3 هشدار Deprecated می‌دهد).
 * مقدار '0' همان «گزارش کانال خاموش» است.
 */
return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->hasColumn('setting', 'Channel_Report')) {
        return;
    }
    $pdo->exec("UPDATE setting SET Channel_Report = '0' WHERE Channel_Report IS NULL OR Channel_Report = ''");
    $column = $pdo->query("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setting' AND COLUMN_NAME = 'Channel_Report'")->fetch(PDO::FETCH_ASSOC);
    if ($column && ($column['IS_NULLABLE'] !== 'NO' || trim((string) $column['COLUMN_DEFAULT'], "'") !== '0')) {
        $pdo->exec("ALTER TABLE setting MODIFY Channel_Report VARCHAR(600) NOT NULL DEFAULT '0'");
    }
};
