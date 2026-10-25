<?php

if (!defined('RX_SBV_MENU_LABEL')) {
    define('RX_SBV_MENU_LABEL', '🔐 تأیید رسید با بررسی');
}

if (!function_exists('rx_sbv_h')) {
    function rx_sbv_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('rx_sbv_digits')) {
    function rx_sbv_digits(string $value): string
    {
        return strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}

if (!function_exists('rx_sbv_status_label')) {
    function rx_sbv_status_label(string $status): string
    {
        $map = [
            'Unpaid' => 'در انتظار پرداخت',
            'pending' => 'در حال ارسال رسید',
            'waiting' => 'رسید ارسال شده (در انتظار بررسی)',
            'processing' => 'در حال پردازش',
            'reconciling' => 'در حال تطبیق با پنل',
            'manual_review' => 'نیازمند بررسی دستی',
            'paid' => 'تأیید شده',
            'reject' => 'رد شده',
            'expire' => 'منقضی شده',
            'cancelled' => 'لغو شده',
        ];
        return $map[$status] ?? ($status === '' ? '—' : $status);
    }
}

if (!function_exists('rx_sbv_state_label')) {
    function rx_sbv_state_label(string $state): string
    {
        $map = [
            'open' => 'فعال، در انتظار واریز',
            'matched' => 'واریز تطبیق یافت، در حال تأیید خودکار',
            'review' => 'واریز تطبیق یافت، تکمیل خودکار ناموفق (نیازمند ادمین)',
            'approved' => 'تأیید و تسویه شده',
            'credited' => 'به‌صورت شارژ کیف پول تسویه شده',
            'closed' => 'بسته شده',
            'void' => 'باطل',
        ];
        return $map[$state] ?? $state;
    }
}

if (!function_exists('rx_sbv_deposit_state_label')) {
    function rx_sbv_deposit_state_label(string $state): string
    {
        $map = [
            'pending' => 'در صف تطبیق',
            'matched' => 'تطبیق یافته',
            'applied' => 'اعمال شده',
            'unmatched' => 'تطبیق‌نیافته',
            'ambiguous' => 'مبهم (چند پرداخت هم‌مبلغ)',
            'late' => 'دیرهنگام / پرداخت غیرفعال',
            'linked' => 'متصل‌شده توسط ادمین',
            'dismissed' => 'بایگانی‌شده',
            'ignored' => 'غیرقابل‌استفاده (برداشت)',
            'test' => 'آزمایشی',
        ];
        return $map[$state] ?? $state;
    }
}

if (!function_exists('rx_sbv_toman_from_rial')) {
    function rx_sbv_toman_from_rial(?int $rial): string
    {
        if ($rial === null) {
            return 'ثبت نشده';
        }
        $toman = intdiv($rial, 10);
        $rem = $rial % 10;
        return rx_sbv_money($toman) . ($rem !== 0 ? '.' . $rem : '') . ' تومان (' . $rial . ' ریال)';
    }
}

if (!function_exists('rx_sbv_cb_ok')) {
    function rx_sbv_cb_ok(string $orderId): bool
    {
        return $orderId !== '' && strlen($orderId) <= 40 && preg_match('/^\w+$/', $orderId) === 1;
    }
}

if (!function_exists('rx_sbv_kb')) {
    function rx_sbv_kb(array $rows): string
    {
        return json_encode(['inline_keyboard' => array_values(array_filter($rows))], JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('rx_sbv_settings_view')) {
    function rx_sbv_settings_view(): array
    {
        rx_sbv_ensure_schema();
        $on = rx_sbv_setting_get('sbv_status', 'off') === 'on';
        $key = rx_sbv_api_key();
        $url = rx_sbv_webhook_url();
        $conn = rx_sbv_connection_status();
        $queue = rx_sbv_review_queue(0, 1);
        $text = "🔐 <b>تأیید رسید با بررسی (پیامک بانکی از طریق ForApp)</b>\n\n"
            . "وضعیت: " . ($on ? "✅ فعال" : "❌ غیرفعال") . "\n"
            . "اتصال: " . rx_sbv_h($conn['label']) . ($conn['last_at'] > 0 ? "\nآخرین درخواست معتبر: " . rx_sbv_h(rx_sbv_clock((int) $conn['last_at'], true)) : '') . "\n\n";
        if ($key === '') {
            $text .= "🔑 کلید API هنوز ساخته نشده است. با فعال‌سازی، کلید به‌صورت خودکار ساخته می‌شود.\n\n";
        } else {
            $text .= "━━ <b>تنظیمات مقصد در ForApp</b> ━━\n"
                . "🌐 آدرس (URL):\n" . ($url !== '' ? "<code>" . rx_sbv_h($url) . "</code>" : "⚠️ دامنه ربات تنظیم نشده است") . "\n"
                . "📮 متد: <code>POST</code>\n"
                . "📦 بدنه: <code>JSON</code> (قالب پیش‌فرض ForApp را تغییر ندهید)\n"
                . "🔑 کلید API:\n<code>" . rx_sbv_h($key) . "</code>\n"
                . "نام هدر احراز هویت: X-API-Key\n"
                . "🔁 هدر تکرار:\n<code>Idempotency-Key: {{id}}</code>\n\n";
        }
        $text .= "⏳ مهلت هر پرداخت: " . rx_sbv_lifetime_minutes() . " دقیقه (حداقل " . RX_SBV_LIFETIME_MIN_MINUTES . ")\n"
            . "📤 ارسال رسید: " . intdiv(RX_SBV_RECEIPT_DELAY, 60) . " دقیقه پس از ایجاد پرداخت\n"
            . "🏷 کارمزد شناسایی: " . RX_SBV_FEE_MIN . " تا " . RX_SBV_FEE_MAX . " تومان (جزو شارژ/سفارش نیست)\n"
            . "♻️ استفاده مجدد از یک مبلغ: حداقل " . intdiv(RX_SBV_REUSE_COOLDOWN, 3600) . " ساعت پس از بسته‌شدن پرداخت\n"
            . "📥 واریزهای نیازمند بررسی: " . (int) $queue['total'] . "\n\n"
            . "ℹ️ غیرفعال‌سازی فقط ساخت پرداخت‌های جدید با این روش را متوقف می‌کند؛ پرداخت‌های قبلی همچنان بررسی می‌شوند و کلید تغییر نمی‌کند.";
        $rows = [];
        $rows[] = [['text' => $on ? '❌ غیرفعال‌سازی' : '✅ فعال‌سازی', 'callback_data' => $on ? 'sbv_off' : 'sbv_on']];
        if ($key !== '') {
            $copy = [];
            if ($url !== '') {
                $copy[] = ['text' => '📋 کپی آدرس', 'copy_text' => ['text' => $url]];
            }
            $copy[] = ['text' => '📋 کپی کلید', 'copy_text' => ['text' => $key]];
            $rows[] = $copy;
            $rows[] = [['text' => '♻️ ساخت کلید جدید', 'callback_data' => 'sbv_rg']];
        }
        $rows[] = [['text' => '⏳ مهلت پرداخت', 'callback_data' => 'sbv_lf']];
        $rows[] = [['text' => '📥 واریزهای تطبیق‌نیافته', 'callback_data' => 'sbv_q_0']];
        $rows[] = [['text' => '🔄 بروزرسانی', 'callback_data' => 'sbv_home']];
        $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'cart_back']];
        return ['text' => $text, 'keyboard' => rx_sbv_kb($rows)];
    }
}

if (!function_exists('rx_sbv_invoice_label')) {
    function rx_sbv_invoice_label(string $idInvoice): string
    {
        $parts = explode('|', $idInvoice, 2);
        $head = $parts[0] ?? '';
        $target = explode('%', (string) ($parts[1] ?? ''), 2)[0];
        $map = [
            'getconfigafterpay' => 'خرید سرویس',
            'getextenduser' => 'تمدید سرویس',
            'getextravolumeuser' => 'خرید حجم اضافه',
            'getextratimeuser' => 'خرید زمان اضافه',
        ];
        if (isset($map[$head])) {
            return $map[$head] . ($target !== '' ? ' (' . $target . ')' : '');
        }
        return 'شارژ کیف پول';
    }
}

if (!function_exists('rx_sbv_deposit_lines')) {
    function rx_sbv_deposit_lines(array $dep): string
    {
        $body = (string) ($dep['body'] ?? '');
        if (function_exists('mb_strlen') && mb_strlen($body, 'UTF-8') > 400) {
            $body = mb_substr($body, 0, 400, 'UTF-8') . '…';
        }
        return "<blockquote>🆔 شناسه واریز: <code>" . (int) $dep['id'] . "</code>\n"
            . "💵 مبلغ: " . rx_sbv_h(rx_sbv_toman_from_rial($dep['amount_rial'] !== null ? (int) $dep['amount_rial'] : null)) . "\n"
            . "🏦 بانک: " . rx_sbv_h($dep['bank'] ?? '—') . " | فرستنده: " . rx_sbv_h($dep['sender'] ?? '—') . "\n"
            . "🔢 کد پیامک: " . rx_sbv_h($dep['code'] ?? '—') . " (یکتا بودن تضمین نشده)\n"
            . "📱 دستگاه: " . rx_sbv_h($dep['device'] ?? '—') . "\n"
            . "🕒 دریافت در دستگاه: " . rx_sbv_h($dep['received_at_raw'] ?? '—') . "\n"
            . "🕒 ثبت در سرور: " . rx_sbv_h(rx_sbv_clock((int) $dep['stored_at'], true)) . "\n"
            . "📌 وضعیت: " . rx_sbv_h(rx_sbv_deposit_state_label((string) $dep['state'])) . ((string) ($dep['note'] ?? '') !== '' ? " (" . rx_sbv_h($dep['note']) . ")" : '') . "\n"
            . "✉️ متن پیامک:\n" . rx_sbv_h($body) . "</blockquote>";
    }
}

if (!function_exists('rx_sbv_order_view')) {
    function rx_sbv_order_view(string $orderId): ?array
    {
        $sum = rx_sbv_order_summary($orderId);
        if ($sum === null) {
            return null;
        }
        $csp = $sum['csp'];
        $pr = is_array($sum['report']) ? $sum['report'] : [];
        $dep = $sum['deposit'];
        $status = (string) ($pr['payment_Status'] ?? '');
        $state = (string) $csp['state'];
        $final = in_array($state, ['approved', 'credited'], true) || $status === 'paid';
        $user = function_exists('select') && !empty($pr['id_user']) ? select('user', '*', 'id', $pr['id_user'], 'select') : null;
        $received = $sum['received_rial'];
        $diff = $sum['diff_rial'];
        $text = "🔎 <b>بررسی پرداخت با بررسی</b>\n\n"
            . "🛒 کد سفارش: <code>" . rx_sbv_h($orderId) . "</code>\n"
            . "👤 کاربر: <code>" . rx_sbv_h($csp['id_user']) . "</code>" . (is_array($user) && !empty($user['username']) ? " (@" . rx_sbv_h($user['username']) . ")" : '') . "\n"
            . "📦 نوع: " . rx_sbv_h(rx_sbv_invoice_label((string) ($pr['id_invoice'] ?? ''))) . "\n"
            . "💳 وضعیت پرداخت: " . rx_sbv_h(rx_sbv_status_label($status)) . "\n"
            . "🔐 وضعیت تطبیق: " . rx_sbv_h(rx_sbv_state_label($state)) . "\n\n"
            . "<blockquote>💰 اصل مبلغ (شارژ/سفارش): " . rx_sbv_money($csp['principal_toman']) . " تومان\n"
            . "🏷 کارمزد شناسایی: " . rx_sbv_money($csp['fee_toman']) . " تومان\n"
            . "✅ مبلغ مورد انتظار: " . rx_sbv_h(rx_sbv_toman_from_rial((int) $csp['payable_rial'])) . "\n"
            . "📥 مبلغ دریافتی: " . rx_sbv_h(rx_sbv_toman_from_rial($received)) . "\n"
            . "➖ اختلاف: " . ($diff === null ? '—' : rx_sbv_h(($diff > 0 ? '+' : ($diff < 0 ? '−' : '')) . rx_sbv_toman_from_rial(abs($diff)))) . "</blockquote>\n"
            . "<blockquote>🕒 ایجاد: " . rx_sbv_h(rx_sbv_clock((int) $csp['created_at'], true)) . "\n"
            . "📤 رسید از: " . rx_sbv_h(rx_sbv_clock((int) $csp['receipt_after'], true)) . "\n"
            . "⏳ مهلت: " . rx_sbv_h(rx_sbv_clock((int) $csp['expires_at'], true)) . (time() >= (int) $csp['expires_at'] ? " (گذشته)" : '') . "</blockquote>\n";
        if ($dep !== null) {
            $text .= "\n📨 <b>واریز متصل</b>\n" . rx_sbv_deposit_lines($dep) . "\n";
        }
        if ((string) ($csp['resolution'] ?? '') !== '') {
            $text .= "\n📝 نتیجه: " . rx_sbv_h($csp['resolution']) . ((string) ($csp['resolved_by'] ?? '') !== '' ? " — ادمین " . rx_sbv_h($csp['resolved_by']) : '') . ((string) ($csp['resolution_note'] ?? '') !== '' ? "\n✍️ " . rx_sbv_h($csp['resolution_note']) : '') . "\n";
        }
        $rows = [];
        if (!$final && $state !== 'matched') {
            if (in_array($status, ['Unpaid', 'waiting', 'expire'], true)) {
                $rows[] = [['text' => '✅ تأیید پرداخت / تحویل سفارش', 'callback_data' => 'sbv_oa_' . $orderId]];
            }
            if ($status === 'waiting') {
                $rows[] = [
                    ['text' => '❌ رد رسید', 'callback_data' => 'sbv_or_' . $orderId],
                    ['text' => '🚫 رد و مسدودسازی', 'callback_data' => 'sbv_ob_' . $orderId],
                ];
            }
            $r = [['text' => '✍️ ثبت مبلغ دریافتی (تومان)', 'callback_data' => 'sbv_om_' . $orderId]];
            if ($csp['deposit_id'] === null) {
                $r[] = ['text' => '🔗 اتصال واریز', 'callback_data' => 'sbv_ol_' . $orderId];
            }
            $rows[] = $r;
            if ($received !== null && intdiv($received, 10) - (int) $csp['fee_toman'] > 0 && in_array($status, ['Unpaid', 'waiting', 'expire', 'cancelled', 'reject'], true)) {
                $rows[] = [['text' => '💼 واریز ' . rx_sbv_money(intdiv($received, 10) - (int) $csp['fee_toman']) . ' تومان به کیف پول', 'callback_data' => 'sbv_oc_' . $orderId]];
            }
        }
        if (!empty($csp['id_user'])) {
            $rows[] = [['text' => '⚙️ مدیریت کاربر', 'callback_data' => 'manageuser_' . $csp['id_user']]];
        }
        $rows[] = [['text' => '🔄 بروزرسانی', 'callback_data' => 'sbv_oo_' . $orderId]];
        return ['text' => $text, 'keyboard' => rx_sbv_kb($rows)];
    }
}

if (!function_exists('rx_sbv_queue_view')) {
    function rx_sbv_queue_view(int $page): array
    {
        $per = 8;
        $q = rx_sbv_review_queue($page * $per, $per);
        $text = "📥 <b>واریزهای نیازمند بررسی</b>\n\nتعداد کل: " . (int) $q['total'] . "\n"
            . "ℹ️ واریزی که با هیچ پرداختی دقیقاً تطبیق نداشته باشد به‌صورت خودکار به کاربری نسبت داده نمی‌شود.\n";
        $rows = [];
        foreach ($q['rows'] as $dep) {
            $label = '#' . (int) $dep['id'] . ' | ' . ($dep['amount_rial'] !== null ? rx_sbv_money(intdiv((int) $dep['amount_rial'], 10)) . ' ت' : '—') . ' | ' . rx_sbv_deposit_state_label((string) $dep['state']) . ' | ' . rx_sbv_clock((int) $dep['stored_at']);
            $rows[] = [['text' => $label, 'callback_data' => 'sbv_d_' . (int) $dep['id']]];
        }
        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '◀️ قبلی', 'callback_data' => 'sbv_q_' . ($page - 1)];
        }
        if (($page + 1) * $per < (int) $q['total']) {
            $nav[] = ['text' => 'بعدی ▶️', 'callback_data' => 'sbv_q_' . ($page + 1)];
        }
        if ($nav) {
            $rows[] = $nav;
        }
        $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'sbv_home']];
        return ['text' => $text, 'keyboard' => rx_sbv_kb($rows)];
    }
}

if (!function_exists('rx_sbv_deposit_view')) {
    function rx_sbv_deposit_view(int $depositId): ?array
    {
        $dep = rx_sbv_deposit($depositId);
        if ($dep === null) {
            return null;
        }
        $text = "📨 <b>جزئیات واریز</b>\n\n" . rx_sbv_deposit_lines($dep);
        if ((string) ($dep['id_order'] ?? '') !== '') {
            $text .= "\n🔗 سفارش متصل: <code>" . rx_sbv_h($dep['id_order']) . "</code>";
        }
        $rows = [];
        $state = (string) $dep['state'];
        if (in_array($state, ['unmatched', 'ambiguous', 'late'], true)) {
            $rows[] = [['text' => '🔗 اتصال به پرداخت', 'callback_data' => 'sbv_dl_' . $depositId]];
            $rows[] = [['text' => '🗂 بایگانی (بررسی شد)', 'callback_data' => 'sbv_dx_' . $depositId]];
        }
        if ((string) ($dep['id_order'] ?? '') !== '' && rx_sbv_cb_ok((string) $dep['id_order'])) {
            $rows[] = [['text' => '🔎 مشاهده سفارش', 'callback_data' => 'sbv_oo_' . $dep['id_order']]];
        }
        $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'sbv_q_0']];
        return ['text' => $text, 'keyboard' => rx_sbv_kb($rows)];
    }
}

if (!function_exists('rx_sbv_link_preview')) {
    function rx_sbv_link_preview(int $depositId, string $orderId): ?array
    {
        $dep = rx_sbv_deposit($depositId);
        $sum = rx_sbv_order_summary($orderId);
        if ($dep === null || $sum === null || !rx_sbv_cb_ok($orderId)) {
            return null;
        }
        $csp = $sum['csp'];
        $pr = is_array($sum['report']) ? $sum['report'] : [];
        $received = $dep['amount_rial'] !== null ? (int) $dep['amount_rial'] : null;
        $diff = $received === null ? null : $received - (int) $csp['payable_rial'];
        $text = "🔗 <b>تأیید اتصال واریز به پرداخت</b>\n\n"
            . "🛒 سفارش: <code>" . rx_sbv_h($orderId) . "</code> | 👤 کاربر: <code>" . rx_sbv_h($csp['id_user']) . "</code>\n"
            . "📦 نوع: " . rx_sbv_h(rx_sbv_invoice_label((string) ($pr['id_invoice'] ?? ''))) . "\n"
            . "💳 وضعیت پرداخت: " . rx_sbv_h(rx_sbv_status_label((string) ($pr['payment_Status'] ?? ''))) . " | تطبیق: " . rx_sbv_h(rx_sbv_state_label((string) $csp['state'])) . "\n"
            . "<blockquote>💰 اصل مبلغ: " . rx_sbv_money($csp['principal_toman']) . " تومان\n"
            . "🏷 کارمزد شناسایی: " . rx_sbv_money($csp['fee_toman']) . " تومان\n"
            . "✅ مبلغ مورد انتظار: " . rx_sbv_h(rx_sbv_toman_from_rial((int) $csp['payable_rial'])) . "\n"
            . "📥 مبلغ واریز: " . rx_sbv_h(rx_sbv_toman_from_rial($received)) . "\n"
            . "➖ اختلاف: " . ($diff === null ? '—' : rx_sbv_h(($diff > 0 ? '+' : ($diff < 0 ? '−' : '')) . rx_sbv_toman_from_rial(abs($diff)))) . "\n"
            . "🕒 ایجاد پرداخت: " . rx_sbv_h(rx_sbv_clock((int) $csp['created_at'], true)) . " | مهلت: " . rx_sbv_h(rx_sbv_clock((int) $csp['expires_at'], true)) . "</blockquote>\n"
            . rx_sbv_deposit_lines($dep) . "\n\n"
            . "⚠️ اتصال به‌تنهایی پرداخت را تأیید نمی‌کند؛ پس از اتصال، نتیجه را از پنل سفارش انتخاب کنید.";
        $rows = [
            [['text' => '✅ تأیید اتصال', 'callback_data' => 'sbv_lk_' . $depositId . '_' . $orderId]],
            [['text' => '❌ انصراف', 'callback_data' => 'sbv_cancel']],
        ];
        return ['text' => $text, 'keyboard' => rx_sbv_kb($rows)];
    }
}

if (!function_exists('rx_sbv_error_text')) {
    function rx_sbv_error_text(string $reason): string
    {
        $map = [
            'verify_reason_required' => 'برای این تصمیم مالی ثبت دلیل الزامی است.',
            'reason_required' => 'ثبت دلیل الزامی است.',
            'already_paid' => 'این پرداخت قبلاً تسویه شده است.',
            'not_eligible' => 'وضعیت فعلی پرداخت اجازه این اقدام را نمی‌دهد.',
            'not_waiting' => 'رسید در وضعیت «در انتظار بررسی» نیست.',
            'race_lost' => 'وضعیت پرداخت هم‌زمان توسط عامل دیگری تغییر کرد.',
            'already_final' => 'این پرداخت قبلاً نهایی شده است.',
            'received_required' => 'ابتدا مبلغ دریافتی را ثبت یا واریز را متصل کنید.',
            'nothing_to_credit' => 'مبلغ دریافتی پس از کسر کارمزد شناسایی مثبت نیست.',
            'purchase_receipts_pending' => 'ابتدا رسیدهای خرید یا تمدید این کاربر را بررسی کنید.',
            'deposit_not_found' => 'واریز یافت نشد.',
            'order_not_verify' => 'این سفارش با روش «تأیید رسید با بررسی» ایجاد نشده است.',
            'order_has_deposit' => 'این سفارش از قبل به واریز دیگری متصل است.',
            'deposit_not_eligible' => 'این رکورد واریز قابل اتصال نیست.',
            'invalid_amount' => 'مبلغ نامعتبر است.',
        ];
        if (isset($map[$reason])) {
            return $map[$reason];
        }
        if (strpos($reason, 'deposit_state_') === 0 || strpos($reason, 'order_state_') === 0) {
            return 'وضعیت فعلی اجازه اتصال نمی‌دهد (' . $reason . ').';
        }
        if (strpos($reason, 'claim_') === 0 || strpos($reason, 'fulfillment_') === 0 || strpos($reason, 'credit_') === 0) {
            return 'عملیات تکمیل نشد (' . $reason . '). وضعیت پرداخت تغییر نکرد یا برای بررسی مجدد نگه داشته شد.';
        }
        return 'خطا: ' . $reason;
    }
}

if (!function_exists('rx_sbv_admin_handle')) {
    function rx_sbv_admin_handle(array $c): bool
    {
        if (!function_exists('rx_sbv_get')) {
            return false;
        }
        $fromId = (string) ($c['from_id'] ?? '');
        $datain = (string) ($c['datain'] ?? '');
        $text = (string) ($c['text'] ?? '');
        $update = is_array($c['update'] ?? null) ? $c['update'] : [];
        $user = is_array($c['user'] ?? null) ? $c['user'] : [];
        $rule = (string) ($c['rule'] ?? '');
        $cbq = (string) ($c['callback_query_id'] ?? '');
        $messageId = (int) ($c['message_id'] ?? 0);
        $isAdmin = $rule === 'administrator';
        $canReview = $isAdmin || $rule === 'Seller';
        if ($fromId === '' || !$canReview) {
            return false;
        }
        $step = (string) ($user['step'] ?? '');
        $isMessage = isset($update['message']) && !isset($update['callback_query']);
        $msgText = $isMessage ? trim((string) ($update['message']['text'] ?? '')) : '';
        $answer = function (string $msg = '', bool $alert = false) use ($cbq) {
            if ($cbq !== '' && function_exists('telegram')) {
                $p = ['callback_query_id' => $cbq, 'cache_time' => 0];
                if ($msg !== '') {
                    $p['text'] = function_exists('mb_substr') ? mb_substr($msg, 0, 190, 'UTF-8') : substr($msg, 0, 190);
                    $p['show_alert'] = $alert;
                }
                telegram('answerCallbackQuery', $p);
            }
        };
        $show = function (array $view, bool $fresh = false) use ($fromId, $answer) {
            if ($fresh) {
                $answer();
                sendmessage($fromId, $view['text'], $view['keyboard'], 'HTML');
                return;
            }
            nm_adminInstantReply($fromId, $view['text'], $view['keyboard'], 'HTML');
        };
        $say = function (string $msg, ?string $kb = null) use ($fromId) {
            sendmessage($fromId, $msg, $kb, 'HTML');
        };
        $storedRaw = (string) ($user['Processing_value_one'] ?? '');
        $sep = strrpos($storedRaw, '#~');
        $storedPayload = $sep === false ? $storedRaw : substr($storedRaw, 0, $sep);
        $storedNonce = $sep === false ? '' : substr($storedRaw, $sep + 2);
        $cancelKbFor = function (string $nonce): string {
            return rx_sbv_kb([[['text' => '❌ لغو', 'callback_data' => 'sbv_cancel_' . $nonce]]]);
        };
        $cancelKb = $cancelKbFor($storedNonce !== '' ? $storedNonce : '00000000');
        $setStep = function (string $s, string $payload) use ($fromId, &$cancelKb, $cancelKbFor) {
            $nonce = bin2hex(random_bytes(4));
            update('user', 'Processing_value_one', $payload . '#~' . $nonce, 'id', $fromId);
            step($s, $fromId);
            $cancelKb = $cancelKbFor($nonce);
        };
        $clearStep = function () use ($fromId) {
            step('home', $fromId);
        };
        $orderView = function (string $orderId, bool $fresh = false) use ($show, $answer) {
            $v = rx_sbv_order_view($orderId);
            if ($v === null) {
                $answer('این سفارش با روش تأیید رسید با بررسی ایجاد نشده است.', true);
                return;
            }
            $show($v, $fresh);
        };

        if (strpos($step, 'sbv_') === 0) {
            if ($isMessage) {
                $payload = $storedPayload;
                if ($msgText === '') {
                    $say('❌ لطفاً پاسخ را به‌صورت متن ارسال کنید.', $cancelKb);
                    return true;
                }
                if ($step === 'sbv_life') {
                    if (!$isAdmin) {
                        $clearStep();
                        return true;
                    }
                    $n = rx_sbv_digits($msgText);
                    if (!ctype_digit($n) || !rx_sbv_set_lifetime_minutes((int) $n)) {
                        $say('❌ عدد صحیح بین ' . RX_SBV_LIFETIME_MIN_MINUTES . ' و ' . RX_SBV_LIFETIME_MAX_MINUTES . ' دقیقه وارد کنید.', $cancelKb);
                        return true;
                    }
                    $clearStep();
                    $v = rx_sbv_settings_view();
                    $say("✅ مهلت پرداخت‌های جدید ذخیره شد. پرداخت‌های قبلی مهلت ثبت‌شده خود را حفظ می‌کنند.\n\n" . $v['text'], $v['keyboard']);
                    return true;
                }
                if ($step === 'sbv_amount') {
                    $n = rx_sbv_digits($msgText);
                    $n = str_replace([',', '٬', ' '], '', $n);
                    if (!ctype_digit($n) || strlen($n) > 12) {
                        $say('❌ مبلغ دریافتی را فقط به‌صورت عدد صحیح و به تومان وارد کنید.', $cancelKb);
                        return true;
                    }
                    $clearStep();
                    $res = rx_sbv_admin_set_received($payload, $fromId, (int) $n);
                    if (empty($res['ok'])) {
                        $say('❌ ' . rx_sbv_error_text((string) $res['reason']));
                        return true;
                    }
                    $v = rx_sbv_order_view($payload);
                    if ($v !== null) {
                        $say("✅ مبلغ دریافتی ثبت شد. اکنون یکی از نتایج را انتخاب کنید:\n• «تأیید پرداخت / تحویل سفارش» سفارش اصلی را کامل تسویه می‌کند (پذیرش کسری/مازاد).\n• «واریز به کیف پول» فقط مبلغ دریافتی منهای کارمزد را به کیف پول اضافه می‌کند.\n\n" . $v['text'], $v['keyboard']);
                    }
                    return true;
                }
                if ($step === 'sbv_linkord') {
                    $orderId = preg_replace('/\s+/', '', $msgText);
                    $clearStep();
                    $p = rx_sbv_link_preview((int) $payload, (string) $orderId);
                    if ($p === null) {
                        $say('❌ سفارش یا واریز یافت نشد یا سفارش با روش «تأیید رسید با بررسی» ایجاد نشده است.');
                        return true;
                    }
                    $say($p['text'], $p['keyboard']);
                    return true;
                }
                if ($step === 'sbv_linkdep') {
                    $n = rx_sbv_digits($msgText);
                    $n = ltrim(trim($n), '#');
                    $clearStep();
                    if (!ctype_digit($n)) {
                        $say('❌ شناسه واریز باید عدد باشد.');
                        return true;
                    }
                    $p = rx_sbv_link_preview((int) $n, $payload);
                    if ($p === null) {
                        $say('❌ واریز یا سفارش یافت نشد.');
                        return true;
                    }
                    $say($p['text'], $p['keyboard']);
                    return true;
                }
                if ($step === 'sbv_reason') {
                    $parts = explode('|', $payload, 2);
                    $action = $parts[0] ?? '';
                    $target = $parts[1] ?? '';
                    $reason = function_exists('mb_substr') ? mb_substr($msgText, 0, 500, 'UTF-8') : substr($msgText, 0, 500);
                    $clearStep();
                    if ($action === 'approve') {
                        $res = rx_sbv_admin_approve($target, $fromId, $reason);
                        $say(!empty($res['ok']) ? '✅ پرداخت تأیید و سفارش تسویه شد.' : '❌ ' . rx_sbv_error_text((string) $res['reason']));
                    } elseif ($action === 'credit') {
                        $res = rx_sbv_admin_credit($target, $fromId, $reason);
                        $say(!empty($res['ok']) ? '✅ مبلغ ' . rx_sbv_money($res['credit']) . ' تومان به کیف پول کاربر اضافه شد و پرداخت تسویه شد.' : '❌ ' . rx_sbv_error_text((string) $res['reason']));
                    } elseif ($action === 'reject' || $action === 'block') {
                        $res = rx_sbv_admin_reject($target, $fromId, $reason, $action === 'block');
                        if (!empty($res['ok'])) {
                            $say($action === 'block' ? ($res['blocked'] ? '✅ رسید رد و کاربر مسدود شد. سوابق واریز حفظ شد.' : '⚠️ رسید رد شد اما مسدودسازی کاربر ناموفق بود.') : '✅ رسید رد شد. سوابق واریز حفظ شد.');
                        } else {
                            $say('❌ ' . rx_sbv_error_text((string) $res['reason']));
                        }
                    } elseif ($action === 'dismiss') {
                        $res = rx_sbv_dismiss((int) $target, $fromId, $reason);
                        $say(!empty($res['ok']) ? '✅ واریز بایگانی شد (رکورد حذف نمی‌شود).' : '❌ ' . rx_sbv_error_text((string) $res['reason']));
                    }
                    if ($action !== 'dismiss' && rx_sbv_cb_ok($target)) {
                        $v = rx_sbv_order_view($target);
                        if ($v !== null) {
                            $say($v['text'], $v['keyboard']);
                        }
                    }
                    return true;
                }
                $clearStep();
                return false;
            }
            if ($datain !== '' && strpos($datain, 'sbv_') !== 0) {
                $clearStep();
            }
        }

        if ($datain === 'cart_smsverify' || ($text === RX_SBV_MENU_LABEL && $datain === '')) {
            if (!$isAdmin) {
                return false;
            }
            $show(rx_sbv_settings_view());
            return true;
        }

        if (preg_match('/^(Confirm_pay|addbalamceuser|reject_pay)_(\w+)$/', $datain, $m) && $m[2] !== '' && strpos($m[2], 'cancel_') !== 0) {
            if (!rx_sbv_is_verify_order($m[2])) {
                return false;
            }
            $answer('این پرداخت با روش «تأیید رسید با بررسی» ایجاد شده؛ اقدام را از پنل بررسی انجام دهید.', false);
            if (rx_sbv_cb_ok($m[2])) {
                $v = rx_sbv_order_view($m[2]);
                if ($v !== null) {
                    sendmessage($fromId, $v['text'], $v['keyboard'], 'HTML');
                }
            }
            return true;
        }

        if (strpos($datain, 'sbv_') !== 0) {
            return false;
        }

        if (preg_match('/^sbv_cancel(?:_([a-f0-9]{8}))?$/', $datain, $cm)) {
            $nonce = (string) ($cm[1] ?? '');
            $current = $nonce !== '' && strpos($step, 'sbv_') === 0 && $storedNonce !== '' && hash_equals($storedNonce, $nonce);
            if ($current) {
                $clearStep();
            }
            $stale = $nonce !== '' && !$current;
            $answer($stale ? 'این درخواست دیگر فعال نیست.' : 'لغو شد');
            if ($messageId > 0) {
                Editmessagetext($fromId, $messageId, $stale ? '⌛️ این درخواست دیگر فعال نیست و تغییری ایجاد نشد.' : '❌ عملیات لغو شد. هیچ تغییری روی پرداخت ایجاد نشد.', null, 'HTML');
            }
            return true;
        }
        if (in_array($datain, ['sbv_home', 'sbv_on', 'sbv_off', 'sbv_rg', 'sbv_rgy', 'sbv_lf'], true) && !$isAdmin) {
            $answer('این بخش فقط برای مدیر کل در دسترس است.', true);
            return true;
        }
        if ($datain === 'sbv_home') {
            $show(rx_sbv_settings_view());
            return true;
        }
        if ($datain === 'sbv_on') {
            $res = rx_sbv_enable();
            if (empty($res['ok'])) {
                $answer('❌ فعال‌سازی ناموفق بود (' . (string) $res['reason'] . ')', true);
                return true;
            }
            $v = rx_sbv_settings_view();
            $v['text'] = (!empty($res['generated']) ? "✅ فعال شد و کلید API جدید ساخته شد. آدرس و کلید زیر را در ForApp وارد کنید.\n\n" : "✅ فعال شد.\n\n") . $v['text'];
            $show($v);
            return true;
        }
        if ($datain === 'sbv_off') {
            rx_sbv_disable();
            $v = rx_sbv_settings_view();
            $v['text'] = "❌ غیرفعال شد. کلید API حفظ شد.\n\n" . $v['text'];
            $show($v);
            return true;
        }
        if ($datain === 'sbv_rg') {
            $show([
                'text' => "♻️ <b>ساخت کلید API جدید</b>\n\nبا ساخت کلید جدید، کلید فعلی فوراً باطل می‌شود و تا وقتی کلید جدید را در ForApp وارد نکنید، پیامک‌های بانکی دریافت نخواهند شد.\n\nآیا مطمئن هستید؟",
                'keyboard' => rx_sbv_kb([
                    [['text' => '✅ بله، کلید جدید بساز', 'callback_data' => 'sbv_rgy']],
                    [['text' => '🔙 انصراف', 'callback_data' => 'sbv_home']],
                ]),
            ]);
            return true;
        }
        if ($datain === 'sbv_rgy') {
            $ok = rx_sbv_regenerate_key();
            $v = rx_sbv_settings_view();
            $v['text'] = ($ok ? "✅ کلید جدید ساخته شد. کلید قبلی دیگر معتبر نیست؛ کلید جدید را در ForApp جایگزین کنید.\n\n" : "❌ ساخت کلید جدید ناموفق بود.\n\n") . $v['text'];
            $show($v);
            return true;
        }
        if ($datain === 'sbv_lf') {
            $answer();
            $setStep('sbv_life', '');
            $say('⏳ مهلت پرداخت‌های جدید را به دقیقه وارد کنید (حداقل ' . RX_SBV_LIFETIME_MIN_MINUTES . ' و حداکثر ' . RX_SBV_LIFETIME_MAX_MINUTES . ').\nمقدار فعلی: ' . rx_sbv_lifetime_minutes() . ' دقیقه', $cancelKb);
            return true;
        }
        if (preg_match('/^sbv_q_(\d+)$/', $datain, $m)) {
            $show(rx_sbv_queue_view((int) $m[1]));
            return true;
        }
        if (preg_match('/^sbv_d_(\d+)$/', $datain, $m)) {
            $v = rx_sbv_deposit_view((int) $m[1]);
            if ($v === null) {
                $answer('واریز یافت نشد.', true);
                return true;
            }
            $show($v);
            return true;
        }
        if (preg_match('/^sbv_dl_(\d+)$/', $datain, $m)) {
            $answer();
            $setStep('sbv_linkord', $m[1]);
            $say('🔗 کد پیگیری سفارشی را که این واریز (#' . (int) $m[1] . ') باید به آن متصل شود ارسال کنید:', $cancelKb);
            return true;
        }
        if (preg_match('/^sbv_dx_(\d+)$/', $datain, $m)) {
            $answer();
            $setStep('sbv_reason', 'dismiss|' . (int) $m[1]);
            $say('🗂 دلیل بایگانی واریز #' . (int) $m[1] . ' را بنویسید (رکورد حذف نمی‌شود):', $cancelKb);
            return true;
        }
        if (preg_match('/^sbv_lk_(\d+)_(\w+)$/', $datain, $m)) {
            $res = rx_sbv_link((int) $m[1], $m[2], $fromId);
            if (empty($res['ok'])) {
                $answer('❌ ' . rx_sbv_error_text((string) $res['reason']), true);
                return true;
            }
            $v = rx_sbv_order_view($m[2]);
            if ($v !== null) {
                $v['text'] = "✅ واریز متصل شد. نتیجه را انتخاب کنید.\n\n" . $v['text'];
                $show($v);
            }
            return true;
        }
        if (preg_match('/^sbv_(o|oo)_(\w+)$/', $datain, $m)) {
            $orderView($m[2], $m[1] === 'o');
            return true;
        }
        if (preg_match('/^sbv_oa_(\w+)$/', $datain, $m)) {
            $orderId = $m[1];
            if (rx_sbv_confirm_guard($orderId, '') === null && rx_sbv_get($orderId) !== null) {
                $answer();
                $res = rx_sbv_admin_approve($orderId, $fromId, '');
                $say(!empty($res['ok']) ? '✅ واریز دقیق تطبیق داشت؛ پرداخت تأیید و سفارش تسویه شد.' : '❌ ' . rx_sbv_error_text((string) $res['reason']));
                $orderView($orderId, true);
                return true;
            }
            $answer();
            $setStep('sbv_reason', 'approve|' . $orderId);
            $say("✍️ هیچ واریز دقیقاً منطبقی برای این سفارش ثبت نشده است. تأیید کامل یک تصمیم مالی دستی است (مثلاً پذیرش کسری).\n\nدلیل تأیید را بنویسید:", $cancelKb);
            return true;
        }
        if (preg_match('/^sbv_(or|ob)_(\w+)$/', $datain, $m)) {
            $answer();
            $setStep('sbv_reason', ($m[1] === 'ob' ? 'block|' : 'reject|') . $m[2]);
            $say($m[1] === 'ob' ? '🚫 دلیل رد رسید و مسدودسازی کاربر را بنویسید:' : '❌ دلیل رد رسید را بنویسید:', $cancelKb);
            return true;
        }
        if (preg_match('/^sbv_om_(\w+)$/', $datain, $m)) {
            $answer();
            $setStep('sbv_amount', $m[1]);
            $say("✍️ مبلغی را که واقعاً به حساب واریز شده به <b>تومان</b> وارد کنید (عدد صحیح).\nاین مبلغ فقط به‌عنوان «مبلغ دریافتی» ثبت می‌شود و به‌تنهایی چیزی را تسویه نمی‌کند.", $cancelKb);
            return true;
        }
        if (preg_match('/^sbv_oc_(\w+)$/', $datain, $m)) {
            $answer();
            $setStep('sbv_reason', 'credit|' . $m[1]);
            $say('💼 دلیل تسویه این پرداخت به‌صورت شارژ کیف پول (به‌جای تحویل سفارش) را بنویسید:', $cancelKb);
            return true;
        }
        if (preg_match('/^sbv_ol_(\w+)$/', $datain, $m)) {
            $answer();
            $setStep('sbv_linkdep', $m[1]);
            $say('🔗 شناسه واریز (عدد نمایش‌داده‌شده در صف واریزهای تطبیق‌نیافته) را ارسال کنید:', $cancelKb);
            return true;
        }
        $answer();
        return true;
    }
}
