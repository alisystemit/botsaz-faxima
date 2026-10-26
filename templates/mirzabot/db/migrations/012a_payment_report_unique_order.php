<?php

/**
 * کلید یکتای لازم برای FKهای مایگریشن 013.
 *
 * MySQL 8.4 (خطای 6125) اجازه نمی‌دهد ستون ارجاع‌شده کلید یکتا نداشته باشد.
 * مایگریشن 013 روی DB تازه، اول FK را می‌سازد و شکست می‌خورد. این مایگریشن
 * عمداً در فایلی جدا و با نامی قبل از 013 است تا:
 *   - روی نصب تازه، قبل از 013 کلید یکتا را بسازد؛
 *   - روی نصب‌های قبلی که 013 شکست خورده، دوباره اجرا شود (مایگریشن‌ها
 *     هر بار اجرا می‌شوند) و FKها را در اجرای بعدی 013 ممکن کند؛
 *   - با «دریافت سورس بروز» از بین نرود: فایل اضافی است و سینک آن را
 *     حذف/بازنویسی نمی‌کند (برخلاف نسخهٔ ویرایش‌شدهٔ 013 که از گیت می‌آید).
 */
return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('Payment_report') || !$schema->hasColumn('Payment_report', 'id_order')) {
        return;
    }

    $hasUniqueKey = static function () use ($pdo): bool {
        $stmt = $pdo->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'Payment_report'
               AND COLUMN_NAME = 'id_order'
               AND NON_UNIQUE = 0
             LIMIT 1"
        );
        return (bool)$stmt->fetchColumn();
    };

    if ($hasUniqueKey()) {
        return;
    }

    $duplicates = (int)$pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT id_order FROM `Payment_report`
            WHERE id_order IS NOT NULL
            GROUP BY id_order
            HAVING COUNT(*) > 1
        ) AS d"
    )->fetchColumn();

    if ($duplicates > 0) {
        // پاک‌کردن خودکار دادهٔ مالی درست نیست. FKها می‌مانند و ادمین باید
        // تکراری‌ها را اصلاح کند؛ بقیهٔ جدول‌ها و ربات بدون مشکل کار می‌کنند.
        error_log("[db:migration:012a] duplicate id_order rows: {$duplicates}; unique index/FKs skipped");
        return;
    }

    $pdo->exec("ALTER TABLE `Payment_report` ADD UNIQUE INDEX `uniq_payreport_id_order` (`id_order`)");
    error_log('[db:migration:012a] unique index uniq_payreport_id_order created');
};
