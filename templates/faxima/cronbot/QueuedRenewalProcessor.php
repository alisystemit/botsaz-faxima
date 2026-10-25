<?php
require_once __DIR__ . '/_init.php';
rx_cron_boot('queued_renewal', 600);

ini_set('error_log', 'error_log');

if (!rx_cron_require_or_skip('queued_renewal', [
    __DIR__ . '/../config.php',
    __DIR__ . '/../botapi.php',
    __DIR__ . '/../panels.php',
    __DIR__ . '/../function.php',
])) {
    return;
}
if (!rx_cron_db_ready('queued_renewal')) {
    return;
}
if (!rx_rn_ensure_schema()) {
    error_log('[queued_renewal] renewal schema unavailable; skipping run');
    return;
}

$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$reportCron = select("topicid", "idreport", "report", "reportcron", "select")['idreport'];

$rxQrReport = function (string $text) use ($setting, $reportCron) {
    if (empty($setting['Channel_Report'])) {
        return;
    }
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $reportCron,
        'text' => $text,
        'parse_mode' => "HTML"
    ]);
};
$rxQrEsc = function ($value): string {
    return htmlspecialchars(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
};

$now = time();
rxReservationRecoverStale($now);

list($w, $n) = function_exists('rx_cron_shard') ? rx_cron_shard() : [0, 1];
$queuedRows = rxReservationDueRows($now, 30, (int) $w, (int) $n);

foreach ($queuedRows as $row) {
    if (function_exists('rx_cron_time_up') && rx_cron_time_up()) break;

    $outcome = rxReservationProcessRow($ManagePanel, $row, time());
    $status = (string) ($outcome['outcome'] ?? '');
    if (!in_array($status, ['applied', 'failed', 'retry', 'reconciling'], true)) {
        continue;
    }
    $fresh = rxReservationFetch((int) $row['id']) ?? $row;
    $reasonCode = preg_replace('/[^a-z_]/', '', strtolower((string) strtok((string) ($outcome['reason'] ?? ''), ':'))) ?: 'unknown';
    $notifyKey = $status . ':' . $reasonCode;
    if ((string) ($fresh['notified_state'] ?? '') === $notifyKey) {
        continue;
    }
    rxReservationMarkNotified((int) $row['id'], $notifyKey);
    $rxQrFulfillment = rx_pf_decode($row['fulfillment'] ?? null);
    $rxQrFulfillment = is_array($rxQrFulfillment) ? $rxQrFulfillment : [];
    $rxQrAdminSource = ($rxQrFulfillment['source'] ?? '') === 'admin';
    $rxQrNotifyCustomer = !$rxQrAdminSource || ($rxQrFulfillment['notify_customer'] ?? true) !== false;
    $diagnostic = "<blockquote>شناسه رزرو : <code>{$row['id']}</code></blockquote>\n"
        . "<blockquote>نام کاربری سرویس : <code>" . $rxQrEsc($row['username']) . "</code></blockquote>\n"
        . "<blockquote>نام پنل : " . $rxQrEsc($row['name_panel']) . "</blockquote>\n"
        . "<blockquote>زمان فعال‌سازی : " . $rxQrEsc(rxReservationTriggerLabel((string) ($row['trigger_type'] ?? 'either'), $row['trigger_at'] ?? null, rxReservationRowConditions($row))) . "</blockquote>\n"
        . "<blockquote>سیاست اجرا : " . $rxQrEsc(rxRenewalPolicyLabel((string) ($row['policy'] ?? 'reset_all'), !empty($row['reset_usage']))) . "</blockquote>\n"
        . "<blockquote>تلاش : " . (int) ($fresh['attempt_count'] ?? 0) . "</blockquote>";

    if ($status === 'applied') {
        $message = faoxima_render_text(faoxima_textbot_get('dyn_queued_renewal_activated_tpl', "با سلام خدمت شما کاربر گرامی 👋\n✅ رزرو اشتراک سرویس {username} فعال شد و حجم و زمان سرویس شما تمدید گردید."), [
            'username' => $row['username'],
        ]);
        if ($rxQrAdminSource) {
            $message = rxRenewalText('adm_customer_activated', ['username' => $rxQrEsc($row['username'])]);
        }
        if (is_array($outcome['before'] ?? null) && is_array($outcome['after'] ?? null)) {
            $message .= "\n\n" . rxRenewalResultText($outcome['before'], $outcome['after']);
        }
        if ($rxQrNotifyCustomer && (string) ($row['id_user'] ?? '') !== '') {
            sendmessage($row['id_user'], $message, null, 'HTML', $row['bottype']);
        }
        $after = is_array($outcome['after'] ?? null) ? $outcome['after'] : [];
        $rxQrReport("📌 اطلاعیه فعال‌سازی رزرو اشتراک\n\n" . $diagnostic
            . (isset($after['data_limit']) ? "\n<blockquote>حجم کل جدید : " . $rxQrEsc(rxRenewalFormatBytes($after['data_limit'])) . "</blockquote>" : '')
            . (isset($after['expire']) ? "\n<blockquote>انقضای جدید : " . $rxQrEsc(rxRenewalFormatExpire($after['expire'])) . "</blockquote>" : ''));
        continue;
    }

    if ($status === 'failed') {
        if ($rxQrNotifyCustomer && (string) ($row['id_user'] ?? '') !== '') {
            sendmessage($row['id_user'], rxRenewalText('reservation_failed_user_tpl', ['username' => $row['username']]), null, 'HTML', $row['bottype']);
        }
        $rxQrReport("❌ خطای دائمی فعال‌سازی رزرو اشتراک (نیازمند بررسی دستی)\n\n" . $diagnostic . "\n<blockquote>دلیل : " . $rxQrEsc($outcome['reason'] ?? '') . "</blockquote>");
        continue;
    }

    $title = $status === 'reconciling'
        ? "⚠️ نتیجه فعال‌سازی رزرو اشتراک نامشخص است و به‌صورت خودکار بررسی می‌شود"
        : ($reasonCode === 'admin_blocked'
            ? "⛔️ رزرو اشتراک به دلیل غیرفعال بودن سرویس توسط مدیر اجرا نشد (تا رفع محدودیت منتظر می‌ماند)"
            : "⚠️ خطای موقت فعال‌سازی رزرو اشتراک؛ تلاش مجدد خودکار");
    $rxQrReport($title . "\n\n" . $diagnostic . "\n<blockquote>دلیل : " . $rxQrEsc($outcome['reason'] ?? '') . "</blockquote>");
}

if (!(function_exists('rx_cron_time_up') && rx_cron_time_up()) && (int) $w === 0) {
    foreach (rxRenewalOpsReconcile($ManagePanel, time(), 20) as $result) {
        $op = is_array($result['op'] ?? null) ? $result['op'] : [];
        $outcome = (string) ($result['outcome'] ?? '');
        if ($outcome === 'verified') {
            $invoice = !empty($op['id_invoice']) ? select("invoice", "*", "id_invoice", $op['id_invoice'], "select") : null;
            if (is_array($invoice) && (string) ($invoice['id_user'] ?? '') !== '' && (string) ($op['source'] ?? '') !== 'admin') {
                $rxQrReconciledText = rxRenewalText('reconciled_user_tpl', ['username' => $rxQrEsc($op['username'] ?? '')]);
                if (is_array($result['after'] ?? null) && rxRenewalSameLinkSupported(rx_rn_panel_type((string) ($op['name_panel'] ?? '')))) {
                    $rxQrReconciledText .= "\n\n" . trim(rxRenewalText('success_same_link'));
                }
                sendmessage($invoice['id_user'], $rxQrReconciledText, null, 'HTML', $invoice['bottype'] ?? null);
            }
            $rxQrReport("✅ تمدید نامشخص پس از بررسی خودکار تأیید شد\n\n<blockquote>شناسه عملیات : <code>" . $rxQrEsc($op['op_id'] ?? '') . "</code></blockquote>\n<blockquote>نام کاربری سرویس : <code>" . $rxQrEsc($op['username'] ?? '') . "</code></blockquote>\n<blockquote>نام پنل : " . $rxQrEsc($op['name_panel'] ?? '') . "</blockquote>");
        }
    }
}
