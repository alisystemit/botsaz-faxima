<?php

if (!defined('RX_AR_TTL')) {
    define('RX_AR_TTL', 1800);
}
if (!defined('RX_AR_NOTIFY_MAX_ATTEMPTS')) {
    define('RX_AR_NOTIFY_MAX_ATTEMPTS', 5);
}
if (!defined('RX_AR_NOTIFY_STALE_SECONDS')) {
    define('RX_AR_NOTIFY_STALE_SECONDS', 300);
}

if (!function_exists('rxArText')) {
    function rxArText(string $key, array $vars = []): string
    {
        return rxRenewalText('adm_' . $key, $vars);
    }
}

if (!function_exists('rxArEsc')) {
    function rxArEsc($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rxArAnswer')) {
    function rxArAnswer($callbackId, string $text = '', bool $alert = true): void
    {
        if (empty($callbackId) || !function_exists('telegram')) {
            return;
        }
        $payload = ['callback_query_id' => $callbackId, 'cache_time' => 1];
        if ($text !== '') {
            $payload['text'] = $text;
            $payload['show_alert'] = $alert;
        }
        try {
            telegram('answerCallbackQuery', $payload);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('rxArDraftEncode')) {
    function rxArDraftEncode(array $draft): string
    {
        return (string) json_encode($draft);
    }
}

if (!function_exists('rxArDraftLoad')) {
    function rxArDraftLoad($raw): ?array
    {
        if (!is_string($raw) || $raw === '' || $raw[0] !== '{') {
            return null;
        }
        $draft = json_decode($raw, true);
        if (!is_array($draft) || empty($draft['rxar']) || empty($draft['t']) || empty($draft['inv']) || !isset($draft['ph'])) {
            return null;
        }
        if (time() - (int) ($draft['ts'] ?? 0) > RX_AR_TTL) {
            return null;
        }
        return $draft;
    }
}

if (!function_exists('rxArDraftSave')) {
    function rxArDraftSave(array $draft, $fromId): array
    {
        global $user;
        $draft['ts'] = time();
        $draft['v'] = (int) ($draft['v'] ?? 0) + 1;
        $json = rxArDraftEncode($draft);
        update("user", "Processing_value", $json, "id", $fromId);
        if (is_array($user)) {
            $user['Processing_value'] = $json;
        }
        return $draft;
    }
}

if (!function_exists('rxArDraftClear')) {
    function rxArDraftClear($fromId): void
    {
        global $user;
        update("user", "Processing_value", "0", "id", $fromId);
        if (is_array($user)) {
            $user['Processing_value'] = "0";
        }
        step('home', $fromId);
    }
}

if (!function_exists('rxArDraftResetIfAny')) {
    function rxArDraftResetIfAny($fromId, $user = null): void
    {
        if (is_array($user) && rxArDraftLoad($user['Processing_value'] ?? null) !== null) {
            update("user", "Processing_value", "0", "id", $fromId);
        }
    }
}

if (!function_exists('rxArDraftClaim')) {
    function rxArDraftClaim($fromId, string $expectedRaw, array $next): bool
    {
        global $pdo, $user;
        $next['ts'] = time();
        $next['v'] = (int) ($next['v'] ?? 0) + 1;
        $json = rxArDraftEncode($next);
        try {
            $stmt = $pdo->prepare("UPDATE user SET Processing_value = ? WHERE id = ? AND Processing_value = ?");
            $stmt->execute([$json, (string) $fromId, $expectedRaw]);
        } catch (Throwable $e) {
            return false;
        }
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        if (is_array($user)) {
            $user['Processing_value'] = $json;
        }
        return true;
    }
}

if (!function_exists('rxArNew')) {
    function rxArNew($adminId, array $invoice, array $panel): array
    {
        return [
            'rxar' => 1,
            't' => bin2hex(random_bytes(4)),
            'ts' => time(),
            'v' => 1,
            'adm' => (string) $adminId,
            'inv' => (string) $invoice['id_invoice'],
            'u' => (string) $invoice['username'],
            'pn' => (string) $panel['name_panel'],
            'ph' => 'src',
            'mode' => '',
            'pid' => 0,
            'pg' => 1,
            'vt' => '',
            'vg' => 0,
            'dt' => '',
            'dg' => 0,
            'm' => '',
            'nf' => 1,
            'pp' => null,
        ];
    }
}

if (!function_exists('rxArCb')) {
    function rxArCb(array $draft, string $code, $arg = null): string
    {
        return 'rxar:' . $draft['t'] . ':' . $code . ($arg !== null ? ':' . $arg : '');
    }
}

if (!function_exists('rxArParseCallback')) {
    function rxArParseCallback($data): ?array
    {
        if (!is_string($data) || !preg_match('/^rxar:([0-9a-f]{8}):([a-z]{2})(?::([A-Za-z0-9]{1,12}))?$/', $data, $m)) {
            return null;
        }
        return ['t' => $m[1], 'code' => $m[2], 'arg' => isset($m[3]) ? $m[3] : null];
    }
}

if (!function_exists('rxArMethods')) {
    function rxArMethods(): array
    {
        return rxRenewalAdminBasePolicies();
    }
}

if (!function_exists('rxArKeepFlags')) {
    function rxArKeepFlags(array $d): array
    {
        if (($d['mode'] ?? '') === 'custom') {
            return [($d['vt'] ?? '') === 'unchanged', ($d['dt'] ?? '') === 'unchanged'];
        }
        return [false, false];
    }
}

if (!function_exists('rxArProductEligible')) {
    function rxArProductEligible($row, string $panelName): bool
    {
        if (!is_array($row) || !isset($row['Location'])) {
            return false;
        }
        if (!is_numeric($row['Volume_constraint'] ?? null) || !is_numeric($row['Service_time'] ?? null) || (float) $row['Volume_constraint'] < 0 || (float) $row['Service_time'] < 0) {
            return false;
        }
        $location = trim((string) $row['Location']);
        if ($location === '/all') {
            return true;
        }
        foreach (explode(',', $location) as $item) {
            if (trim($item) === $panelName) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('rxArEligibleProducts')) {
    function rxArEligibleProducts(string $panelName): array
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM product WHERE (FIND_IN_SET(?, Location) > 0 OR Location = '/all') ORDER BY id ASC LIMIT 300");
        $stmt->execute([$panelName]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (rxArProductEligible($row, $panelName) && (string) ($row['name_product'] ?? '') !== 'سرویس تست') {
                $out[] = $row;
            }
        }
        return $out;
    }
}

if (!function_exists('rxArProduct')) {
    function rxArProduct(array $d): ?array
    {
        if (($d['mode'] ?? '') !== 'product' || (int) ($d['pid'] ?? 0) <= 0) {
            return null;
        }
        $row = select("product", "*", "id", (int) $d['pid'], "select");
        return rxArProductEligible($row, (string) $d['pn']) ? $row : null;
    }
}

if (!function_exists('rxArQuantities')) {
    function rxArQuantities(array $d): array
    {
        if (($d['mode'] ?? '') === 'product') {
            $product = rxArProduct($d);
            if ($product === null) {
                return ['ok' => false, 'reason' => 'product_missing'];
            }
            return ['ok' => true, 'gb' => (float) $product['Volume_constraint'], 'days' => (float) $product['Service_time'], 'code' => (string) $product['code_product'], 'keep_traffic' => false, 'keep_time' => false, 'product' => $product];
        }
        if (($d['mode'] ?? '') !== 'custom' || ($d['vt'] ?? '') === '' || ($d['dt'] ?? '') === '') {
            return ['ok' => false, 'reason' => 'incomplete'];
        }
        $keepTraffic = $d['vt'] === 'unchanged';
        $keepTime = $d['dt'] === 'unchanged';
        if ($keepTraffic && $keepTime) {
            return ['ok' => false, 'reason' => 'both_unchanged'];
        }
        return [
            'ok' => true,
            'gb' => $d['vt'] === 'finite' ? (float) $d['vg'] : 0.0,
            'days' => $d['dt'] === 'finite' ? (float) $d['dg'] : 0.0,
            'code' => 'custom_volume',
            'keep_traffic' => $keepTraffic,
            'keep_time' => $keepTime,
            'product' => null,
        ];
    }
}

if (!function_exists('rxArFormatNumber')) {
    function rxArFormatNumber($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('rxArStateLabel')) {
    function rxArStateLabel(string $state, $value, string $unit): string
    {
        if ($state === 'unchanged') {
            return rxArText('state_unchanged');
        }
        if ($state === 'unlimited') {
            return rxArText('state_unlimited');
        }
        if ($state === 'finite') {
            return rxArFormatNumber($value) . ' ' . $unit;
        }
        return rxArText('state_unset');
    }
}

if (!function_exists('rxArPackageLabel')) {
    function rxArPackageLabel(array $d): string
    {
        if (($d['mode'] ?? '') === 'product') {
            $product = rxArProduct($d);
            if ($product === null) {
                return rxArText('state_unset');
            }
            return rxArText('package_product', [
                'name' => rxArEsc($product['name_product']),
                'volume' => (float) $product['Volume_constraint'] > 0 ? rxArFormatNumber($product['Volume_constraint']) . ' ' . rxRenewalText('gb_unit') : rxRenewalText('unlimited'),
                'days' => (float) $product['Service_time'] > 0 ? rxArFormatNumber($product['Service_time']) . ' ' . rxRenewalText('days_unit') : rxRenewalText('unlimited'),
            ]);
        }
        return rxArText('package_custom', [
            'volume' => rxArStateLabel((string) ($d['vt'] ?? ''), $d['vg'] ?? 0, rxRenewalText('gb_unit')),
            'days' => rxArStateLabel((string) ($d['dt'] ?? ''), $d['dg'] ?? 0, rxRenewalText('days_unit')),
        ]);
    }
}

if (!function_exists('rxArPrevPhase')) {
    function rxArPrevPhase(array $d): ?string
    {
        switch ((string) $d['ph']) {
            case 'prod':
            case 'vol':
                return 'src';
            case 'days':
                return 'vol';
            case 'meth':
                return ($d['mode'] ?? '') === 'product' ? 'prod' : 'days';
            case 'opt':
                return 'meth';
            case 'prev':
                return 'opt';
        }
        return null;
    }
}

if (!function_exists('rxArNavRows')) {
    function rxArNavRows(array $d, bool $showBack = true): array
    {
        $rows = [];
        if ($showBack && rxArPrevPhase($d) !== null) {
            $rows[] = [['text' => rxArText('btn_back'), 'callback_data' => rxArCb($d, 'bk')]];
        }
        $rows[] = [
            ['text' => rxArText('btn_service'), 'callback_data' => 'manageinvoice_' . $d['inv']],
            ['text' => rxArText('btn_admin'), 'callback_data' => rxArCb($d, 'am')],
        ];
        $rows[] = [['text' => rxArText('btn_cancel'), 'callback_data' => rxArCb($d, 'cx')]];
        return $rows;
    }
}

if (!function_exists('rxArHeader')) {
    function rxArHeader(array $d): string
    {
        return rxArText('title') . "\n" . rxArText('service_lines', ['username' => rxArEsc($d['u']), 'panel' => rxArEsc($d['pn'])]);
    }
}

if (!function_exists('rxArKeyboard')) {
    function rxArKeyboard(array $rows): string
    {
        return (string) json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('rxArReasonText')) {
    function rxArReasonText(string $reason, array $plan = []): string
    {
        switch ($reason) {
            case 'both_unchanged':
                return rxArText('both_unchanged');
            case 'product_missing':
                return rxArText('product_missing');
            case 'incomplete':
                return rxArText('incomplete');
            case 'panel_unreachable':
                return rxRenewalText('reason_panel_unreachable');
        }
        return rxRenewalReasonText($reason, $plan);
    }
}

if (!function_exists('rxArBuild')) {
    function rxArBuild($managePanel, array $panel, array $d, ?array $snapshot = null): array
    {
        $now = time();
        $q = rxArQuantities($d);
        if (empty($q['ok'])) {
            return ['ok' => false, 'reason' => (string) $q['reason'], 'text' => rxArReasonText((string) $q['reason'])];
        }
        $policy = rxRenewalAdminPolicy((string) ($d['m'] ?? ''), $q['keep_traffic'], $q['keep_time']);
        $label = rxRenewalAdminMethodLabel((string) ($d['m'] ?? ''));
        if ($policy === null || $label === null) {
            return ['ok' => false, 'reason' => 'policy_invalid', 'text' => rxRenewalReasonText('policy_invalid')];
        }
        $username = (string) $d['u'];
        if ($snapshot === null) {
            $snapshot = rx_pf_panel_snapshot($managePanel, (string) $panel['name_panel'], $username, (string) $panel['type']);
        }
        if (empty($snapshot['ok'])) {
            return ['ok' => false, 'reason' => 'panel_unreachable', 'text' => rxArReasonText('panel_unreachable'), 'snapshot' => $snapshot];
        }
        if (empty($snapshot['exists'])) {
            return ['ok' => false, 'reason' => 'user_missing', 'text' => rxArReasonText('user_missing'), 'snapshot' => $snapshot];
        }
        $logCtx = ['stage' => 'admin_preview', 'invoice_id' => (string) $d['inv'], 'ref' => rxArRef($d)];
        $base = ['q' => $q, 'policy' => $policy, 'method_label' => $label, 'snapshot' => $snapshot, 'now' => $now];
        $plan = rxRenewalPlanForSnapshot($snapshot, $panel, $username, $policy, false, $q['gb'], $q['days'], $label, $now, $logCtx);
        if (empty($plan['ok'])) {
            return $base + ['ok' => false, 'reason' => (string) ($plan['reason'] ?? ''), 'text' => rxArReasonText((string) ($plan['reason'] ?? ''), $plan), 'plan' => $plan, 'mode' => 'immediate'];
        }
        return $base + ['ok' => true, 'reason' => '', 'text' => '', 'plan' => $plan, 'mode' => 'immediate'];
    }
}

if (!function_exists('rxArPlanSignature')) {
    function rxArPlanSignature(array $built): array
    {
        $plan = is_array($built['plan'] ?? null) ? $built['plan'] : [];
        $signature = [
            'mode' => (string) ($built['mode'] ?? ''),
            'policy' => (string) ($built['policy'] ?? ''),
            'limit' => (int) ($plan['data_limit'] ?? 0),
            'reset' => !empty($plan['reset_usage']) ? 1 : 0,
            'pending' => !empty($plan['target_pending']) ? 1 : 0,
            'pduration' => (int) ($plan['target_pending_duration'] ?? 0),
            'expire' => ($plan['time_mode'] ?? '') === 'reset' ? 0 : (int) ($plan['expire'] ?? 0),
        ];
        return $signature;
    }
}

if (!function_exists('rxArPreviewText')) {
    function rxArPreviewText(array $d, array $built): string
    {
        $plan = $built['plan'];
        $lines = [];
        $lines[] = rxArText('preview_title');
        $lines[] = rxArText('service_lines', ['username' => rxArEsc($d['u']), 'panel' => rxArEsc($d['pn'])]);
        $lines[] = rxArText('preview_package', ['value' => rxArPackageLabel($d)]);
        $lines[] = rxArText('preview_method', ['label' => rxArEsc(rxRenewalPolicyLabel((string) $d['m']))]);
        if ((string) $built['policy'] !== (string) $d['m']) {
            $lines[] = rxArText('preview_effective', ['label' => rxArEsc(rxRenewalPolicyLabel((string) $built['policy']))]);
        }
        $lines[] = rxArText('preview_exec_now');
        $lines[] = rxArText('preview_free');
        $lines[] = rxArText('preview_notify_' . (!empty($d['nf']) ? 'on' : 'off'));
        $lines[] = '';
        $lines[] = rxArText('preview_current');
        $lines[] = rxRenewalText('preview_current_limit', ['value' => rxRenewalFormatBytes($plan['before_data_limit'] ?? 0)]);
        $lines[] = rxRenewalText('preview_used', ['value' => rxRenewalFormatBytes($plan['before_used_traffic'] ?? 0, false)]);
        $lines[] = rxRenewalText('preview_current_remaining', ['value' => rxRenewalFormatBytes($plan['before_remaining'] ?? null, false)]);
        $lines[] = !empty($plan['before_pending'])
            ? rxArText('preview_pending_current', ['value' => rxRenewalFormatDays(((int) ($plan['before_pending_duration'] ?? 0)) / 86400)])
            : rxRenewalText('preview_current_expire', ['value' => rxRenewalFormatExpire($plan['before_expire'] ?? 0)]);
        $lines[] = '';
        $lines[] = rxArText('preview_expected');
        $lines[] = rxRenewalText('preview_new_limit', ['value' => rxRenewalFormatBytes($plan['data_limit'] ?? 0)]);
        $lines[] = rxArText('preview_new_used', ['value' => rxRenewalFormatBytes($plan['target_used_traffic'] ?? 0, false)]);
        $lines[] = rxRenewalText('preview_new_remaining', ['value' => rxRenewalFormatBytes($plan['target_remaining'] ?? null)]);
        $lines[] = !empty($plan['target_pending'])
            ? rxRenewalText('preview_pending', ['value' => rxRenewalFormatDays(((int) ($plan['target_pending_duration'] ?? 0)) / 86400)])
            : rxRenewalText('preview_new_expire', ['value' => rxRenewalFormatExpire($plan['expire'] ?? 0)]);
        $lines[] = rxRenewalText(!empty($plan['reset_usage']) ? 'preview_usage_reset' : 'preview_usage_kept');
        $unchanged = [];
        if (($plan['traffic_mode'] ?? '') === 'keep') {
            $unchanged[] = rxArText('unchanged_traffic');
        }
        if (($plan['time_mode'] ?? '') === 'keep') {
            $unchanged[] = rxArText('unchanged_time');
        }
        $lines[] = rxArText('preview_unchanged', ['value' => $unchanged === [] ? rxArText('none') : implode('، ', $unchanged)]);
        if (!empty($plan['enable'])) {
            $lines[] = rxRenewalText('preview_enable');
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('rxArRender')) {
    function rxArRender($managePanel, array $panel, array $d, string $notice = ''): array
    {
        $header = rxArHeader($d);
        $notice = $notice !== '' ? "\n\n" . $notice : '';
        switch ((string) $d['ph']) {
            case 'prod':
                $products = rxArEligibleProducts((string) $d['pn']);
                $perPage = 8;
                $pages = max(1, (int) ceil(count($products) / $perPage));
                $page = min($pages, max(1, (int) ($d['pg'] ?? 1)));
                $rows = [];
                foreach (array_slice($products, ($page - 1) * $perPage, $perPage) as $product) {
                    $label = (string) $product['name_product'] . ' • '
                        . ((float) $product['Volume_constraint'] > 0 ? rxArFormatNumber($product['Volume_constraint']) . rxRenewalText('gb_unit') : rxRenewalText('unlimited')) . ' • '
                        . ((float) $product['Service_time'] > 0 ? rxArFormatNumber($product['Service_time']) . rxRenewalText('days_unit') : rxRenewalText('unlimited'));
                    $selected = ($d['mode'] ?? '') === 'product' && (int) $d['pid'] === (int) $product['id'];
                    $rows[] = [['text' => ($selected ? rxRenewalText('mark_on') . ' ' : '') . $label, 'callback_data' => rxArCb($d, 'pk', (int) $product['id'])]];
                }
                if ($pages > 1) {
                    $pager = [];
                    if ($page > 1) {
                        $pager[] = ['text' => '◀️', 'callback_data' => rxArCb($d, 'pg', $page - 1)];
                    }
                    $pager[] = ['text' => $page . '/' . $pages, 'callback_data' => rxArCb($d, 'pg', $page)];
                    if ($page < $pages) {
                        $pager[] = ['text' => '▶️', 'callback_data' => rxArCb($d, 'pg', $page + 1)];
                    }
                    $rows[] = $pager;
                }
                $text = $header . "\n\n" . ($products === [] ? rxArText('prod_empty') : rxArText('prod_prompt')) . $notice;
                return [$text, rxArKeyboard(array_merge($rows, rxArNavRows($d)))];
            case 'vol':
                $text = $header . "\n\n" . rxArText('vol_prompt');
                if (($d['vt'] ?? '') !== '') {
                    $text .= "\n\n" . rxArText('current_choice', ['value' => rxArStateLabel((string) $d['vt'], $d['vg'], rxRenewalText('gb_unit'))]);
                }
                $rows = [[['text' => rxArText('btn_keep_traffic'), 'callback_data' => rxArCb($d, 'kt')]]];
                return [$text . $notice, rxArKeyboard(array_merge($rows, rxArNavRows($d)))];
            case 'days':
                $text = $header . "\n\n" . rxArText('days_prompt');
                if (($d['dt'] ?? '') !== '') {
                    $text .= "\n\n" . rxArText('current_choice', ['value' => rxArStateLabel((string) $d['dt'], $d['dg'], rxRenewalText('days_unit'))]);
                }
                $rows = [[['text' => rxArText('btn_keep_time'), 'callback_data' => rxArCb($d, 'kd')]]];
                return [$text . $notice, rxArKeyboard(array_merge($rows, rxArNavRows($d)))];
            case 'meth':
                $text = $header . "\n" . rxArText('preview_package', ['value' => rxArPackageLabel($d)]) . "\n\n" . rxArText('method_prompt') . "\n";
                $rows = [];
                foreach (rxArMethods() as $index => $policy) {
                    $text .= "\n" . ($index + 1) . '. ' . rxArEsc(rxRenewalPolicyLabel($policy));
                    $rows[] = [['text' => (($d['m'] ?? '') === $policy ? rxRenewalText('mark_on') . ' ' : '') . rxRenewalPolicyLabel($policy, false, true), 'callback_data' => rxArCb($d, 'mt', $index)]];
                }
                list($keepTraffic, $keepTime) = rxArKeepFlags($d);
                if ($keepTraffic || $keepTime) {
                    $text .= "\n\n" . rxArText('method_override_note');
                }
                if (($d['m'] ?? '') !== '') {
                    $rows[] = [['text' => rxArText('btn_continue'), 'callback_data' => rxArCb($d, 'nx')]];
                }
                return [$text . $notice, rxArKeyboard(array_merge($rows, rxArNavRows($d)))];
            case 'opt':
                $text = $header . "\n" . rxArText('preview_package', ['value' => rxArPackageLabel($d)]) . "\n" . rxArText('preview_method', ['label' => rxArEsc(rxRenewalPolicyLabel((string) $d['m']))]) . "\n\n" . rxArText('opt_prompt');
                $rows = [];
                $rows[] = [
                    ['text' => rxRenewalText(!empty($d['nf']) ? 'mark_on' : 'mark_off') . ' ' . rxArText('btn_notify_on'), 'callback_data' => rxArCb($d, 'nf', 1)],
                    ['text' => rxRenewalText(empty($d['nf']) ? 'mark_on' : 'mark_off') . ' ' . rxArText('btn_notify_off'), 'callback_data' => rxArCb($d, 'nf', 0)],
                ];
                $rows[] = [['text' => rxArText('btn_preview'), 'callback_data' => rxArCb($d, 'go')]];
                return [$text . $notice, rxArKeyboard(array_merge($rows, rxArNavRows($d)))];
            case 'prev':
                $built = rxArBuild($managePanel, $panel, $d);
                if (empty($built['ok'])) {
                    $text = $header . "\n\n" . $built['text'] . "\n\n" . rxArText('revise_hint');
                    $rows = [[['text' => rxArText('btn_refresh'), 'callback_data' => rxArCb($d, 'rf')]]];
                    return [$text . $notice, rxArKeyboard(array_merge($rows, rxArNavRows($d)))];
                }
                $rows = [[
                    ['text' => rxArText('btn_confirm'), 'callback_data' => rxArCb($d, 'ok')],
                    ['text' => rxArText('btn_refresh'), 'callback_data' => rxArCb($d, 'rf')],
                ]];
                return [rxArPreviewText($d, $built) . $notice, rxArKeyboard(array_merge($rows, rxArNavRows($d))), $built];
        }
        $rows = [[
            ['text' => rxArText('btn_product'), 'callback_data' => rxArCb($d, 'sp')],
            ['text' => rxArText('btn_custom'), 'callback_data' => rxArCb($d, 'sc')],
        ]];
        return [$header . "\n\n" . rxArText('src_prompt') . $notice, rxArKeyboard(array_merge($rows, rxArNavRows($d)))];
    }
}

if (!function_exists('rxArRef')) {
    function rxArRef(array $d): string
    {
        return 'admin:' . $d['adm'] . ':' . $d['t'];
    }
}

if (!function_exists('rxArShow')) {
    function rxArShow($managePanel, array $panel, array $d, string $notice = ''): array
    {
        $d['ph'] = (string) $d['ph'];
        $rendered = rxArRender($managePanel, $panel, $d, $notice);
        if ($d['ph'] === 'prev' && isset($rendered[2])) {
            $d['pp'] = rxArPlanSignature($rendered[2]);
        } elseif ($d['ph'] === 'prev') {
            $d['pp'] = null;
        }
        $d = rxArDraftSave($d, $d['adm']);
        step($d['ph'] === 'vol' ? 'rxar_vol' : ($d['ph'] === 'days' ? 'rxar_days' : 'home'), $d['adm']);
        nm_adminInstantReply($d['adm'], $rendered[0], $rendered[1], 'HTML');
        return $d;
    }
}

if (!function_exists('rxArStart')) {
    function rxArStart($managePanel, $fromId, $idInvoice): void
    {
        $invoice = select("invoice", "*", "id_invoice", $idInvoice, "select");
        if (!is_array($invoice)) {
            nm_adminInstantReply($fromId, rxArText('error_start'), null, 'HTML');
            return;
        }
        $backRow = [[['text' => rxArText('btn_service'), 'callback_data' => 'manageinvoice_' . $invoice['id_invoice']]]];
        $panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
        if (!is_array($panel)) {
            nm_adminInstantReply($fromId, rxArText('error_start'), rxArKeyboard($backRow), 'HTML');
            return;
        }
        if (($panel['type'] ?? '') === 'Manualsale' || (function_exists('nmPanelNationalEnabled') && nmPanelNationalEnabled($panel))) {
            nm_adminInstantReply($fromId, rxArText('error_manual_panel'), rxArKeyboard($backRow), 'HTML');
            return;
        }
        if (!in_array($invoice['Status'], ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'], true)) {
            nm_adminInstantReply($fromId, rxArText('error_status', ['status' => rxArEsc($invoice['Status'])]), rxArKeyboard($backRow), 'HTML');
            return;
        }
        $snapshot = rx_pf_panel_snapshot($managePanel, (string) $panel['name_panel'], (string) $invoice['username'], (string) $panel['type']);
        if (empty($snapshot['ok']) || empty($snapshot['exists'])) {
            nm_adminInstantReply($fromId, empty($snapshot['ok']) ? rxArReasonText('panel_unreachable') : rxArReasonText('user_missing'), rxArKeyboard($backRow), 'HTML');
            return;
        }
        rxArShow($managePanel, $panel, rxArNew($fromId, $invoice, $panel));
    }
}

if (!function_exists('rxArFinalizeContext')) {
    function rxArFinalizeContext(array $d, array $built, array $invoice, array $panel): array
    {
        $q = $built['q'];
        $product = null;
        if (is_array($q['product'])) {
            $product = [
                'id' => (int) $q['product']['id'],
                'code' => (string) $q['product']['code_product'],
                'name' => (string) $q['product']['name_product'],
                'volume' => (string) $q['product']['Volume_constraint'],
                'time' => (string) $q['product']['Service_time'],
            ];
        }
        return [
            'v' => 1,
            'inv' => (string) $invoice['id_invoice'],
            'adm' => (string) $d['adm'],
            'u' => (string) $d['u'],
            'pn' => (string) $panel['name_panel'],
            'uid' => (string) ($invoice['id_user'] ?? ''),
            'bottype' => isset($invoice['bottype']) ? (string) $invoice['bottype'] : null,
            'method' => (string) $built['method_label'],
            'base' => (string) $d['m'],
            'policy' => (string) $built['policy'],
            'mode' => (string) $d['mode'],
            'vt' => (string) ($d['vt'] ?? ''),
            'dt' => (string) ($d['dt'] ?? ''),
            'gb' => $q['gb'],
            'days' => $q['days'],
            'keep_traffic' => $q['keep_traffic'] ? 1 : 0,
            'keep_time' => $q['keep_time'] ? 1 : 0,
            'product' => $product,
            'notify' => !empty($d['nf']) ? 1 : 0,
            'ref' => rxArRef($d),
        ];
    }
}

if (!function_exists('rxArNotifyStateOf')) {
    function rxArNotifyStateOf(string $opId): string
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT notify_state FROM renewal_operation WHERE op_id = ? LIMIT 1");
            $stmt->execute([$opId]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? 'none' : (string) $value;
        } catch (Throwable $e) {
            return 'none';
        }
    }
}

if (!function_exists('rxArNotifyClassify')) {
    function rxArNotifyClassify($response): array
    {
        if (!is_array($response) || $response === []) {
            return ['kind' => 'ambiguous', 'reason' => 'empty_or_invalid_response', 'retry_after' => 0];
        }
        if (array_key_exists('ok', $response) && $response['ok'] === true) {
            if (is_array($response['result'] ?? null) && isset($response['result']['message_id'])) {
                return ['kind' => 'sent', 'reason' => '', 'retry_after' => 0];
            }
            return ['kind' => 'ambiguous', 'reason' => 'success_without_message', 'retry_after' => 0];
        }
        if (!array_key_exists('ok', $response) || $response['ok'] !== false) {
            return ['kind' => 'ambiguous', 'reason' => 'unexpected_response', 'retry_after' => 0];
        }
        $code = isset($response['error_code']) && is_numeric($response['error_code']) ? (int) $response['error_code'] : 0;
        $description = rx_renewal_log_clean((string) ($response['description'] ?? ''), 120);
        if ($code === 0) {
            return ['kind' => 'ambiguous', 'reason' => 'network:' . $description, 'retry_after' => 0];
        }
        if ($code === 429) {
            $retryAfter = isset($response['parameters']['retry_after']) && is_numeric($response['parameters']['retry_after']) ? (int) $response['parameters']['retry_after'] : 0;
            return ['kind' => 'rate_limited', 'reason' => 'rate_limited', 'retry_after' => max(1, $retryAfter)];
        }
        if ($code >= 500 || $code === 401 || $code === 408) {
            return ['kind' => 'transient', 'reason' => 'http_' . $code . ':' . $description, 'retry_after' => 0];
        }
        return ['kind' => 'permanent', 'reason' => 'http_' . $code . ':' . $description, 'retry_after' => 0];
    }
}

if (!function_exists('rxArNotifyBackoff')) {
    function rxArNotifyBackoff(int $attempt, int $retryAfter = 0): int
    {
        $delay = (int) min(1800, 60 * pow(2, max(0, $attempt - 1)));
        return max($delay, min(3600, $retryAfter + 1));
    }
}

if (!function_exists('rxArNotifyLog')) {
    function rxArNotifyLog(string $stage, string $reason, array $op, int $attempt, string $detail): void
    {
        rx_renewal_log($stage, $reason, [
            'panel_name' => (string) ($op['name_panel'] ?? ''),
            'username' => (string) ($op['username'] ?? ''),
            'invoice_id' => (string) ($op['id_invoice'] ?? ''),
            'operation_id' => (string) ($op['op_id'] ?? ''),
            'method' => (string) ($op['method'] ?? ''),
            'policy' => (string) ($op['policy'] ?? ''),
            'mutation' => 'none',
            'explanation' => 'attempt=' . $attempt,
            'error' => rx_renewal_log_clean($detail, 160),
        ]);
    }
}

if (!function_exists('rxArNotifyOperation')) {
    function rxArNotifyOperation(string $opId): string
    {
        global $pdo;
        try {
            $now = time();
            $max = RX_AR_NOTIFY_MAX_ATTEMPTS;
            $token = bin2hex(random_bytes(8));
            $claim = $pdo->prepare(
                "UPDATE renewal_operation SET notify_state = 'sending', notify_token = ?, notify_claimed_at = ?, notify_attempts = notify_attempts + 1, updated_at = ? "
                . "WHERE op_id = ? AND finalize_state = 'done' AND notify_attempts < ? AND ("
                . "notify_state = 'pending' "
                . "OR (notify_state = 'failed' AND (notify_next IS NULL OR notify_next <= ?)) "
                . "OR (notify_state = 'sending' AND (notify_claimed_at IS NULL OR notify_claimed_at <= ?)))"
            );
            $claim->execute([$token, $now, $now, $opId, $max, $now, $now - RX_AR_NOTIFY_STALE_SECONDS]);
            if ($claim->rowCount() !== 1) {
                return rxArNotifyStateOf($opId);
            }
            $stmt = $pdo->prepare("SELECT * FROM renewal_operation WHERE op_id = ? LIMIT 1");
            $stmt->execute([$opId]);
            $op = $stmt->fetch(PDO::FETCH_ASSOC);
            $ctx = is_array($op) ? rx_pf_decode($op['finalize_context'] ?? null) : null;
            $attempt = is_array($op) ? (int) $op['notify_attempts'] : 1;
            $outcome = ['kind' => 'permanent', 'reason' => 'no_recipient', 'retry_after' => 0];
            if (is_array($ctx) && (string) ($ctx['uid'] ?? '') !== '') {
                $text = rxArText('customer_done', ['username' => rxArEsc($ctx['u'] ?? '')]);
                $before = rx_pf_decode($op['before_state'] ?? null);
                $after = rx_pf_decode($op['result_state'] ?? null);
                if (is_array($before) && is_array($after)) {
                    $text .= "\n\n" . rxRenewalResultText($before, $after);
                }
                try {
                    $outcome = rxArNotifyClassify(sendmessage($ctx['uid'], $text, null, 'HTML', $ctx['bottype'] ?? null));
                } catch (Throwable $e) {
                    $outcome = ['kind' => 'ambiguous', 'reason' => 'exception:' . $e->getMessage(), 'retry_after' => 0];
                }
            }
            $kind = $outcome['kind'];
            if ($kind === 'sent') {
                $state = 'sent';
                $next = null;
            } elseif ($kind === 'permanent') {
                $state = 'dead';
                $next = null;
            } elseif ($attempt >= $max) {
                $state = 'dead';
                $next = null;
            } else {
                $state = 'failed';
                $next = $now + rxArNotifyBackoff($attempt, (int) $outcome['retry_after']);
            }
            $final = $pdo->prepare("UPDATE renewal_operation SET notify_state = ?, notify_next = ?, notify_error = ?, notify_token = NULL, updated_at = ? WHERE op_id = ? AND notify_state = 'sending' AND notify_token = ?");
            $final->execute([$state, $next, $kind === 'sent' ? null : substr(rx_renewal_log_clean($outcome['reason'], 200), 0, 250), time(), $opId, $token]);
            if ($kind !== 'sent' && is_array($op)) {
                $stage = $state === 'dead' ? ($kind === 'permanent' ? 'notify_permanent' : 'notify_exhausted') : ($kind === 'ambiguous' ? 'notify_ambiguous' : 'notify_retry');
                rxArNotifyLog('admin_notify', $stage, $op, $attempt, $kind . ':' . $outcome['reason']);
            }
            return $state;
        } catch (Throwable $e) {
            rx_pf_log('ADMIN_RENEWAL_NOTIFY_FAILED', $e->getMessage(), ['op_id' => $opId]);
            return rxArNotifyStateOf($opId);
        }
    }
}

if (!function_exists('rxArFinalizeOperation')) {
    function rxArFinalizeOperation(string $opId): array
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !rx_rn_ensure_schema()) {
            return ['status' => 'pending', 'notify' => 'none'];
        }
        if ($pdo->inTransaction()) {
            return ['status' => 'pending', 'notify' => 'none'];
        }
        $status = 'pending';
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM renewal_operation WHERE op_id = ? FOR UPDATE");
            $stmt->execute([$opId]);
            $op = $stmt->fetch(PDO::FETCH_ASSOC);
            $ctx = is_array($op) ? rx_pf_decode($op['finalize_context'] ?? null) : null;
            if (!is_array($op) || (string) $op['source'] !== 'admin' || !is_array($ctx) || $op['finalize_state'] === null) {
                $pdo->rollBack();
                return ['status' => 'skipped', 'notify' => 'none'];
            }
            if ((string) $op['finalize_state'] !== 'pending') {
                $pdo->rollBack();
                $status = (string) $op['finalize_state'];
            } elseif ((string) $op['state'] !== 'verified') {
                $pdo->rollBack();
                return ['status' => 'not_verified', 'notify' => 'none'];
            } else {
                $invStmt = $pdo->prepare("SELECT * FROM invoice WHERE id_invoice = ? FOR UPDATE");
                $invStmt->execute([(string) $ctx['inv']]);
                $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($invoice) || (string) $invoice['username'] !== (string) $op['username'] || (string) $invoice['Service_location'] !== (string) $op['name_panel'] || (string) $ctx['inv'] !== (string) $op['id_invoice']) {
                    $mark = $pdo->prepare("UPDATE renewal_operation SET finalize_state = 'invalid', notify_state = 'none', finalize_error = ?, updated_at = ? WHERE op_id = ?");
                    $mark->execute(['invoice_mismatch', time(), $opId]);
                    $pdo->commit();
                    rx_pf_log('ADMIN_RENEWAL_FINALIZE_INVALID', 'invoice does not match operation', ['op_id' => $opId, 'invoice' => $ctx['inv'] ?? null]);
                    return ['status' => 'invalid', 'notify' => 'none'];
                }
                $newer = $pdo->prepare("SELECT COUNT(*) FROM renewal_operation WHERE service_key = ? AND id > ? AND state = 'verified'");
                $newer->execute([(string) $op['service_key'], (int) $op['id']]);
                $superseded = (int) $newer->fetchColumn() > 0;
                if (!$superseded) {
                    $sets = [];
                    $params = [];
                    if (is_array($ctx['product'] ?? null)) {
                        $sets = ['name_product = ?', 'Volume = ?', 'Service_time = ?'];
                        $params = [(string) $ctx['product']['name'], (string) $ctx['product']['volume'], (string) $ctx['product']['time']];
                    } else {
                        if (empty($ctx['keep_traffic'])) {
                            $sets[] = 'Volume = ?';
                            $params[] = rxArFormatNumber($ctx['gb']);
                        }
                        if (empty($ctx['keep_time'])) {
                            $sets[] = 'Service_time = ?';
                            $params[] = rxArFormatNumber($ctx['days']);
                        }
                    }
                    $sets[] = 'price_product = ?';
                    $params[] = '0';
                    $params[] = (string) $ctx['inv'];
                    $upd = $pdo->prepare("UPDATE invoice SET " . implode(', ', $sets) . " WHERE id_invoice = ?");
                    $upd->execute($params);
                }
                $history = $pdo->prepare("INSERT INTO service_other (id_user, username, value, type, time, price, output, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $history->execute([
                    (string) $ctx['adm'],
                    (string) $op['username'],
                    (!empty($ctx['keep_traffic']) ? 'unchanged' : rxArFormatNumber($ctx['gb'])) . '_' . (!empty($ctx['keep_time']) ? 'unchanged' : rxArFormatNumber($ctx['days'])),
                    'extend_user_by_admin',
                    date('Y/m/d H:i:s'),
                    '0',
                    (string) json_encode(['op_id' => $opId, 'ref' => $ctx['ref'] ?? null, 'method' => $ctx['method'] ?? null, 'policy' => $ctx['policy'] ?? null, 'invoice_updated' => !$superseded]),
                    'paid',
                ]);
                $done = $pdo->prepare("UPDATE renewal_operation SET finalize_state = 'done', finalized_at = ?, notify_state = ?, finalize_error = NULL, updated_at = ? WHERE op_id = ?");
                $done->execute([time(), !empty($ctx['notify']) && (string) ($ctx['uid'] ?? '') !== '' ? 'pending' : 'none', time(), $opId]);
                $pdo->commit();
                $status = 'done';
            }
        } catch (Throwable $e) {
            $attempts = 1;
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $retry = $pdo->prepare("UPDATE renewal_operation SET finalize_attempts = finalize_attempts + 1, finalize_next = ?, finalize_error = ?, updated_at = ? WHERE op_id = ? AND finalize_state = 'pending'");
                $reason = rx_renewal_log_clean($e->getMessage(), 200);
                $row = $pdo->prepare("SELECT * FROM renewal_operation WHERE op_id = ? LIMIT 1");
                $row->execute([$opId]);
                $failedOp = $row->fetch(PDO::FETCH_ASSOC);
                $attempts = is_array($failedOp) ? (int) $failedOp['finalize_attempts'] + 1 : 1;
                $retry->execute([time() + (int) min(1800, 120 * pow(2, max(0, $attempts - 1))), $reason, time(), $opId]);
                if (is_array($failedOp)) {
                    rx_renewal_log('admin_finalize', 'finalize_failed', [
                        'panel_name' => (string) $failedOp['name_panel'],
                        'username' => (string) $failedOp['username'],
                        'invoice_id' => (string) $failedOp['id_invoice'],
                        'operation_id' => $opId,
                        'method' => (string) ($failedOp['method'] ?? ''),
                        'policy' => (string) ($failedOp['policy'] ?? ''),
                        'mutation' => 'none',
                        'explanation' => 'attempt=' . $attempts . ' local_finalization_only',
                        'error' => $reason,
                    ]);
                }
            } catch (Throwable $inner) {
                rx_pf_log('ADMIN_RENEWAL_FINALIZE_FAILED', rx_renewal_log_clean($e->getMessage(), 200), ['op_id' => $opId]);
            }
            return ['status' => 'pending', 'notify' => 'none'];
        }
        $notify = rxArNotifyStateOf($opId);
        if ($status === 'done' && in_array($notify, ['pending', 'failed', 'sending'], true)) {
            $notify = rxArNotifyOperation($opId);
        }
        return ['status' => $status, 'notify' => $notify];
    }
}

if (!function_exists('rxArFinalizePending')) {
    function rxArFinalizePending(int $now, int $limit = 10): array
    {
        global $pdo;
        $results = [];
        if (!($pdo instanceof PDO) || !rx_rn_ensure_schema()) {
            return $results;
        }
        try {
            $exhausted = $pdo->prepare("UPDATE renewal_operation SET notify_state = 'dead', notify_error = 'attempts_exhausted_delivery_unknown', notify_token = NULL, updated_at = ? WHERE source = 'admin' AND finalize_state = 'done' AND notify_state = 'sending' AND notify_claimed_at <= ? AND notify_attempts >= ?");
            $exhausted->execute([$now, $now - RX_AR_NOTIFY_STALE_SECONDS, RX_AR_NOTIFY_MAX_ATTEMPTS]);
            $stmt = $pdo->prepare(
                "SELECT op_id FROM renewal_operation WHERE source = 'admin' AND state = 'verified' AND finalize_state IS NOT NULL AND ("
                . "(finalize_state = 'pending' AND (finalize_next IS NULL OR finalize_next <= ?)) "
                . "OR (finalize_state = 'done' AND notify_attempts < ? AND ("
                . "notify_state = 'pending' "
                . "OR (notify_state = 'failed' AND (notify_next IS NULL OR notify_next <= ?)) "
                . "OR (notify_state = 'sending' AND notify_claimed_at <= ?)))) "
                . "ORDER BY id ASC LIMIT " . (int) $limit
            );
            $stmt->execute([$now, RX_AR_NOTIFY_MAX_ATTEMPTS, $now, $now - RX_AR_NOTIFY_STALE_SECONDS]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $opId) {
                $results[(string) $opId] = rxArFinalizeOperation((string) $opId);
            }
        } catch (Throwable $e) {
            rx_pf_log('ADMIN_RENEWAL_FINALIZE_SWEEP_FAILED', rx_renewal_log_clean($e->getMessage(), 200));
        }
        return $results;
    }
}

if (!function_exists('rxArRun')) {
    function rxArRun($managePanel, array $panel, array $invoice, array $d, array $built): array
    {
        $q = $built['q'];
        $options = [
            'source' => 'admin',
            'ref' => rxArRef($d),
            'id_user' => $invoice['id_user'],
            'price' => 0,
            'policy' => $built['policy'],
            'finalize' => rxArFinalizeContext($d, $built, $invoice, $panel),
        ];
        try {
            $extend = $managePanel->extend($built['method_label'], $q['gb'], $q['days'], (string) $d['u'], $q['code'], $panel['code_panel'], null, $options);
        } catch (Throwable $e) {
            rx_pf_log('ADMIN_RENEWAL_EXCEPTION', $e->getMessage(), ['username' => (string) $d['u'], 'ref' => $options['ref']]);
            return ['state' => 'failed', 'extend' => ['status' => false, 'code' => 'exception', 'msg' => $e->getMessage()], 'revisable' => false];
        }
        if (!is_array($extend)) {
            return ['state' => 'failed', 'extend' => ['status' => false, 'code' => 'invalid_response', 'msg' => ''], 'revisable' => false];
        }
        if (rxRenewalIsPending($extend)) {
            return ['state' => 'pending', 'extend' => $extend, 'revisable' => false];
        }
        if (empty($extend['status'])) {
            return ['state' => 'failed', 'extend' => $extend, 'revisable' => !empty($extend['no_mutation'])];
        }
        if (empty($extend['rx_renewal']['verified'])) {
            return ['state' => 'pending', 'extend' => $extend, 'revisable' => false];
        }
        return ['state' => 'verified', 'extend' => $extend, 'revisable' => false];
    }
}

if (!function_exists('rxArFinish')) {
    function rxArFinish($managePanel, array $panel, array $invoice, array $d, array $run): void
    {
        global $setting, $errorreport, $otherservice;
        $adminId = $d['adm'];
        $extend = $run['extend'];
        $username = (string) $d['u'];
        $serviceRow = [[['text' => rxArText('btn_service'), 'callback_data' => 'manageinvoice_' . $d['inv']]]];
        if ($run['state'] === 'pending') {
            rxArDraftClear($adminId);
            nm_adminInstantReply($adminId, rxRenewalText('pending_verification') . "\n\n🧾 " . rxArEsc($extend['operation_id'] ?? ''), rxArKeyboard($serviceRow), 'HTML');
            return;
        }
        if ($run['state'] === 'failed') {
            $extend['msg'] = rx_panel_error_text($extend['msg'] ?? null, $extend['detail'] ?? null);
            $message = rxRenewalFailureText($extend, rxArText('failed_generic'));
            if (($extend['code'] ?? '') === 'manual_stock_empty') {
                $message = rxArText('failed_stock');
            }
            if (!empty($run['revisable'])) {
                $d['ph'] = 'prev';
                $d = rxArDraftSave($d, $adminId);
                step('home', $adminId);
                $rows = [[['text' => rxArText('btn_refresh'), 'callback_data' => rxArCb($d, 'rf')]]];
                nm_adminInstantReply($adminId, $message . "\n\n" . rxArText('revise_hint'), rxArKeyboard(array_merge($rows, rxArNavRows($d))), 'HTML');
            } else {
                rxArDraftClear($adminId);
                nm_adminInstantReply($adminId, $message, rxArKeyboard($serviceRow), 'HTML');
            }
            if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => "\n        خطای تمدید سرویس\n<blockquote>نام پنل : " . rxArEsc($panel['name_panel']) . "</blockquote>\n<blockquote>نام کاربری سرویس : " . rxArEsc($username) . "</blockquote>\n<blockquote>کد خطا : " . rxArEsc($extend['code'] ?? '') . "</blockquote>\n<blockquote>دلیل خطا : " . rxArEsc($extend['msg'] ?? '') . "</blockquote>",
                    'parse_mode' => "HTML",
                ]);
            }
            return;
        }
        rxArDraftClear($adminId);
        $final = is_array($extend['admin_finalize'] ?? null) ? $extend['admin_finalize'] : ['status' => 'pending', 'notify' => 'none'];
        $adminText = rxArText('admin_done') . "\n\n" . rxRenewalOutcomeText($extend);
        if (($final['status'] ?? '') !== 'done') {
            $adminText .= "\n\n" . rxArText('finalize_pending');
        }
        if (empty($d['nf'])) {
            $adminText .= "\n\n" . rxArText('notified_no');
        } elseif (($final['notify'] ?? '') === 'sent') {
            $adminText .= "\n\n" . rxArText('notified_yes');
        } elseif (($final['notify'] ?? '') === 'dead') {
            $adminText .= "\n\n" . rxArText('notified_failed');
        } elseif (in_array(($final['notify'] ?? ''), ['failed', 'sending'], true)) {
            $adminText .= "\n\n" . rxArText('notified_retrying');
        } else {
            $adminText .= "\n\n" . rxArText('notified_later');
        }
        nm_adminInstantReply($adminId, $adminText, rxArKeyboard($serviceRow), 'HTML');
        if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => rxArText('report_done') . "\n\n"
                    . "<blockquote>🪪 آیدی عددی ادمین : <code>" . rxArEsc($adminId) . "</code></blockquote>\n"
                    . "<blockquote>🪪 آیدی عددی : <code>" . rxArEsc($invoice['id_user']) . "</code></blockquote>\n"
                    . "<blockquote>🛍 بسته : " . rxArPackageLabel($d) . "</blockquote>\n"
                    . "<blockquote>⚙️ روش : " . rxArEsc(rxRenewalPolicyLabel((string) $d['m'])) . "</blockquote>\n"
                    . "<blockquote>👤 نام کاربری مشتری در پنل  : " . rxArEsc($username) . "</blockquote>\n"
                    . "<blockquote>موقعیت سرویس سرویس کاربر : " . rxArEsc($invoice['Service_location']) . "</blockquote>",
                'parse_mode' => "HTML",
            ]);
        }
    }
}

if (!function_exists('rxArOnCallback')) {
    function rxArOnCallback($managePanel, $fromId, $datain): void
    {
        global $user, $callback_query_id, $admin_ids;
        $parsed = rxArParseCallback($datain);
        $raw = (string) ($user['Processing_value'] ?? '');
        $d = rxArDraftLoad($raw);
        if (!in_array($fromId, $admin_ids) || $parsed === null || $d === null || $d['t'] !== $parsed['t'] || (string) $d['adm'] !== (string) $fromId) {
            rxArAnswer($callback_query_id, rxRenewalText('button_outdated'));
            return;
        }
        $invoice = select("invoice", "*", "id_invoice", $d['inv'], "select");
        $panel = select("marzban_panel", "*", "name_panel", $d['pn'], "select");
        if (!is_array($invoice) || !is_array($panel) || (string) $invoice['username'] !== (string) $d['u'] || (string) $invoice['Service_location'] !== (string) $d['pn']) {
            rxArDraftClear($fromId);
            rxArAnswer($callback_query_id, rxRenewalText('button_outdated'));
            return;
        }
        $code = $parsed['code'];
        $arg = $parsed['arg'];
        $phase = (string) $d['ph'];
        if (!empty($d['ex']) && $d['ex'] !== 'now') {
            unset($d['ex'], $d['tr']);
            $d['pp'] = null;
            if (in_array($phase, ['opt', 'prev'], true)) {
                $d['ph'] = 'opt';
            }
            if ($code !== 'cx' && $code !== 'am') {
                rxArShow($managePanel, $panel, $d, rxRenewalText('button_outdated'));
                return;
            }
        }
        if ($code === 'cx') {
            rxArDraftClear($fromId);
            nm_adminInstantReply($fromId, rxArText('cancelled'), rxArKeyboard([[['text' => rxArText('btn_service'), 'callback_data' => 'manageinvoice_' . $d['inv']]]]), 'HTML');
            return;
        }
        if ($code === 'am') {
            rxArDraftClear($fromId);
            global $text_panel_admin_login_template, $version, $keyboardadmin;
            $versionMiniApp = @file_get_contents('app/version');
            nm_adminInstantReply($fromId, sprintf($text_panel_admin_login_template, $version, $versionMiniApp), $keyboardadmin, 'HTML');
            return;
        }
        if ($phase === 'run') {
            rxArAnswer($callback_query_id, rxArText('already_running'));
            return;
        }
        if ($code === 'bk') {
            $prev = rxArPrevPhase($d);
            if ($prev === null) {
                rxArAnswer($callback_query_id, rxRenewalText('button_outdated'));
                return;
            }
            $d['ph'] = $prev;
            rxArShow($managePanel, $panel, $d);
            return;
        }
        $allowedFrom = [
            'sp' => ['src'], 'sc' => ['src'], 'pg' => ['prod'], 'pk' => ['prod'],
            'kt' => ['vol'], 'kd' => ['days'], 'mt' => ['meth'], 'nx' => ['meth'], 'nf' => ['opt'],
            'go' => ['opt', 'prev'], 'rf' => ['prev'], 'ok' => ['prev'],
        ];
        if (!isset($allowedFrom[$code]) || !in_array($phase, $allowedFrom[$code], true)) {
            rxArShow($managePanel, $panel, $d, rxRenewalText('button_outdated'));
            return;
        }
        switch ($code) {
            case 'sp':
                $d['ph'] = 'prod';
                $d['pg'] = 1;
                rxArShow($managePanel, $panel, $d);
                return;
            case 'sc':
                $d['mode'] = 'custom';
                $d['ph'] = 'vol';
                rxArShow($managePanel, $panel, $d);
                return;
            case 'pg':
                $d['pg'] = max(1, (int) $arg);
                rxArShow($managePanel, $panel, $d);
                return;
            case 'pk':
                $product = select("product", "*", "id", (int) $arg, "select");
                if (!rxArProductEligible($product, (string) $d['pn'])) {
                    rxArAnswer($callback_query_id, rxArText('product_missing'));
                    return;
                }
                $d['mode'] = 'product';
                $d['pid'] = (int) $product['id'];
                $d['ph'] = 'meth';
                rxArShow($managePanel, $panel, $d);
                return;
            case 'kt':
                if (($d['dt'] ?? '') === 'unchanged') {
                    rxArAnswer($callback_query_id, rxArText('both_unchanged'));
                    return;
                }
                $d['vt'] = 'unchanged';
                $d['vg'] = 0;
                $d['ph'] = 'days';
                rxArShow($managePanel, $panel, $d);
                return;
            case 'kd':
                if (($d['vt'] ?? '') === 'unchanged') {
                    rxArAnswer($callback_query_id, rxArText('both_unchanged'));
                    return;
                }
                $d['dt'] = 'unchanged';
                $d['dg'] = 0;
                $d['ph'] = 'meth';
                rxArShow($managePanel, $panel, $d);
                return;
            case 'mt':
                $methods = rxArMethods();
                if (!isset($methods[(int) $arg])) {
                    rxArAnswer($callback_query_id, rxRenewalText('button_outdated'));
                    return;
                }
                $d['m'] = $methods[(int) $arg];
                rxArShow($managePanel, $panel, $d);
                return;
            case 'nx':
                if (($d['m'] ?? '') === '') {
                    rxArAnswer($callback_query_id, rxArText('method_required'));
                    return;
                }
                $d['ph'] = 'opt';
                rxArShow($managePanel, $panel, $d);
                return;
            case 'nf':
                $d['nf'] = $arg === '1' ? 1 : 0;
                rxArShow($managePanel, $panel, $d);
                return;
            case 'go':
            case 'rf':
                $d['ph'] = 'prev';
                rxArShow($managePanel, $panel, $d);
                return;
            case 'ok':
                rxArConfirm($managePanel, $fromId, $raw, $d, $invoice, $panel);
                return;
        }
    }
}

if (!function_exists('rxArConfirm')) {
    function rxArConfirm($managePanel, $fromId, string $raw, array $d, array $invoice, array $panel): void
    {
        global $callback_query_id;
        if (!is_array($d['pp'] ?? null)) {
            rxArAnswer($callback_query_id, rxRenewalText('button_outdated'));
            return;
        }
        $claimed = $d;
        $claimed['ph'] = 'run';
        if (!rxArDraftClaim($fromId, $raw, $claimed)) {
            rxArAnswer($callback_query_id, rxArText('already_running'));
            return;
        }
        $claimed['v'] = (int) $claimed['v'] + 1;
        $built = rxArBuild($managePanel, $panel, $d);
        if (empty($built['ok'])) {
            $d['ph'] = 'prev';
            rxArShow($managePanel, $panel, $d, rxArText('state_changed'));
            return;
        }
        if (rxArPlanSignature($built) !== $d['pp']) {
            $d['ph'] = 'prev';
            rxArShow($managePanel, $panel, $d, rxArText('state_changed'));
            return;
        }
        if (!in_array($invoice['Status'], ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'], true)) {
            rxArDraftClear($fromId);
            nm_adminInstantReply($fromId, rxArText('error_status', ['status' => rxArEsc($invoice['Status'])]), rxArKeyboard([[['text' => rxArText('btn_service'), 'callback_data' => 'manageinvoice_' . $d['inv']]]]), 'HTML');
            return;
        }
        $run = rxArRun($managePanel, $panel, $invoice, $d, $built);
        $run['q'] = $built['q'];
        rxArFinish($managePanel, $panel, $invoice, $d, $run);
    }
}

if (!function_exists('rxArOnText')) {
    function rxArOnText($managePanel, $fromId, string $text): void
    {
        global $user, $admin_ids;
        $raw = (string) ($user['Processing_value'] ?? '');
        $d = rxArDraftLoad($raw);
        $step = (string) ($user['step'] ?? '');
        $expected = $step === 'rxar_vol' ? 'vol' : 'days';
        if (!in_array($fromId, $admin_ids) || $d === null || (string) $d['adm'] !== (string) $fromId || (string) $d['ph'] !== $expected) {
            step('home', $fromId);
            nm_adminInstantReply($fromId, rxRenewalText('button_outdated'), null, 'HTML');
            return;
        }
        $invoice = select("invoice", "*", "id_invoice", $d['inv'], "select");
        $panel = select("marzban_panel", "*", "name_panel", $d['pn'], "select");
        if (!is_array($invoice) || !is_array($panel)) {
            rxArDraftClear($fromId);
            nm_adminInstantReply($fromId, rxRenewalText('button_outdated'), null, 'HTML');
            return;
        }
        $value = trim((string) convertPersianNumbersToEnglish($text));
        if (!ctype_digit($value) || strlen($value) > 7) {
            $rendered = rxArRender($managePanel, $panel, $d, rxArText('invalid_number'));
            nm_adminInstantReply($fromId, $rendered[0], $rendered[1], 'HTML');
            return;
        }
        $number = (int) $value;
        if ($expected === 'vol') {
            $d['vt'] = $number === 0 ? 'unlimited' : 'finite';
            $d['vg'] = $number;
            $d['ph'] = 'days';
        } else {
            $d['dt'] = $number === 0 ? 'unlimited' : 'finite';
            $d['dg'] = $number;
            $d['ph'] = 'meth';
        }
        rxArShow($managePanel, $panel, $d);
    }
}
