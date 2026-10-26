<?php
// ===== آماده‌سازی قالب‌ها: پچ سازگاری + وابستگی‌های Composer =====
//
// بعضی قالب‌ها (مثل میرزا بات پرو) vendor/ را در گیت‌هاب ندارند و باید با
// Composer ساخته شوند. بدون vendor/autoload.php قالب در پنل «نصب نشده»
// می‌ماند و در منوی ساخت ربات هم نمی‌آید.
//
// استفاده:
//   php tools/ensure_template_vendor.php mirzapro
//   php tools/ensure_template_vendor.php mirzapro,faxima
//   php tools/ensure_template_vendor.php all
//
// خروجی: کد ۰ یعنی همهٔ قالب‌های درخواستی آماده‌اند؛ ۱ یعنی دست‌کم یکی نصب نشد.

require_once __DIR__ . '/../src/Manager.php';

$type = trim((string)($argv[1] ?? ''));
$targets = [];
if ($type === '' || $type === 'all') {
    foreach (Manager::buildableTypes() as $key => $spec) $targets[] = (string)$key;
} else {
    // امکان «mirzapro,faxima» برای درخواست‌های تجمیعی کرون
    foreach (array_filter(array_map('trim', explode(',', $type))) as $one) {
        if (Manager::templateSpec($one) === null) {
            fwrite(STDERR, "قالب نامعتبر: {$one}\n");
            exit(2);
        }
        $targets[] = $one;
    }
    $targets = array_values(array_unique($targets));
}

$failed = 0;
foreach ($targets as $t) {
    $dir = Manager::templateDir($t);
    if (!is_dir($dir)) {
        echo "✘ {$t}: پوشهٔ قالب نیست ({$dir})\n";
        $failed++;
        continue;
    }
    try {
        $patches = Manager::applyTemplateCompatPatches($dir, $t);
        if ($patches !== []) echo "↳ {$t}: پچ سازگاری " . implode('، ', $patches) . "\n";
    } catch (Throwable $e) {
        echo "⚠️ {$t}: پچ سازگاری اعمال نشد (" . $e->getMessage() . ")\n";
    }
    if (Manager::templateVendorReady($t)) {
        echo "• {$t}: آماده است\n";
        continue;
    }
    if (!Manager::templateHasComposer($t)) {
        echo "• {$t}: composer.json ندارد؛ vendor باید همراه سورس باشد\n";
        continue;
    }
    echo "▶ {$t}: نصب وابستگی‌های Composer…\n";
    $res = Manager::ensureTemplateVendor($t, 600);
    echo (!empty($res['ok']) ? "✔ " : "✘ ") . (string)$res['note'] . "\n";
    $out = trim((string)$res['output']);
    if ($out !== '') echo "  " . str_replace("\n", "\n  ", $out) . "\n";
    if (empty($res['ok'])) $failed++;
}

exit($failed > 0 ? 1 : 0);
