<?php

if (!function_exists('rx_richUi_chatIsUserUi')) {
    function rx_richUi_chatIsUserUi($chatId): bool
    {
        $chatId = trim((string) $chatId);
        if ($chatId === '' || $chatId === '0' || !ctype_digit($chatId)) {
            return false;
        }
        $isAdmin = function_exists('rx_isAdminChat') && rx_isAdminChat($chatId);
        if (!$isAdmin) {
            return true;
        }
        $actor = isset($GLOBALS['from_id']) ? trim((string) $GLOBALS['from_id']) : '';
        if ($actor === '' || $actor !== $chatId) {
            return false;
        }
        return empty($GLOBALS['rx_admin_scope']);
    }
}

if (!function_exists('rx_richUi_extractText')) {
    function rx_richUi_extractText($node, string $glue = ''): string
    {
        if (is_string($node)) {
            return $node;
        }
        if (!is_array($node)) {
            return '';
        }
        $type = $node['type'] ?? null;
        if ($type === 'buttons' || $type === 'button') {
            return '';
        }
        $skip = ['type' => 1, 'url' => 1, 'callback_data' => 1, 'style' => 1, 'align' => 1, 'name' => 1, 'id' => 1, 'is_rtl' => 1, 'language' => 1];
        $isList = $node === [] || array_keys($node) === range(0, count($node) - 1);
        $parts = [];
        foreach ($node as $key => $value) {
            if (!$isList && isset($skip[$key])) {
                continue;
            }
            $piece = rx_richUi_extractText($value, $key === 'blocks' ? "
" : '');
            if ($piece !== '') {
                $parts[] = $piece;
            }
        }
        return trim(implode($isList ? $glue : "
", $parts));
    }
}

if (!function_exists('rx_richUi_textToHtml')) {
    function rx_richUi_textToHtml($text, $parseMode): ?string
    {
        $mode = strtolower(trim((string) $parseMode));
        if ($mode === 'html') {
            $html = (string) $text;
        } elseif ($mode === '' || $mode === 'none') {
            $html = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } else {
            return null;
        }
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        if (trim($html) === '') {
            return '';
        }
        $segments = preg_split('~(<pre\b[\s\S]*?</pre>|<blockquote\b[\s\S]*?</blockquote>)~i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === null) {
                continue;
            }
            if (preg_match('~^<pre\b~i', $segment)) {
                $out .= $segment;
            } elseif (preg_match('~^<blockquote\b~i', $segment)) {
                $out .= str_replace("\n", '<br>', $segment);
            } else {
                $trimmed = trim($segment, "\n");
                if (trim($trimmed) === '') {
                    continue;
                }
                foreach (explode("\n", $trimmed) as $line) {
                    if (trim($line) !== '') {
                        $out .= '<p>' . $line . '</p>';
                    }
                }
            }
        }
        return preg_replace('~<tg-emoji\s+emoji-id="(\d+)"\s*>[\s\S]*?</tg-emoji>~u', '<tg-emoji emoji-id="$1"></tg-emoji>', $out);
    }
}

if (!function_exists('rx_richUi_plainHtml')) {
    function rx_richUi_plainHtml($text, $parseMode): ?string
    {
        $mode = strtolower(trim((string) $parseMode));
        if ($mode !== 'html' && $mode !== '' && $mode !== 'none') {
            return null;
        }
        $plain = (string) $text;
        if ($mode === 'html') {
            $plain = preg_replace('~<br\s*/?>~i', "\n", $plain);
            $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return rx_richUi_textToHtml($plain, '');
    }
}

if (!function_exists('rx_richUi_buttonHtml')) {
    function rx_richUi_buttonHtml(array $btn): ?string
    {
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        if (!isset($btn['text']) || !is_string($btn['text'])) {
            return '';
        }
        $label = $esc($btn['text']);
        $emojiId = isset($btn['icon_custom_emoji_id']) ? trim((string) $btn['icon_custom_emoji_id']) : '';
        if ($emojiId !== '' && ctype_digit($emojiId)) {
            $label = '<tg-emoji emoji-id="' . $emojiId . '"></tg-emoji>' . $label;
        }
        if (trim($btn['text']) === '' && $emojiId === '') {
            return '';
        }
        $style = isset($btn['style']) && in_array($btn['style'], ['primary', 'success', 'danger'], true)
            ? ' style="' . $btn['style'] . '"' : '';

        if (isset($btn['callback_data'])) {
            $data = (string) $btn['callback_data'];
            if ($data === '' || strlen($data) > 64) {
                return null;
            }
            return '<tg-button type="callback_data"' . $style . ' data="' . $esc($data) . '">' . $label . '</tg-button>';
        }
        if (isset($btn['url'])) {
            return '<tg-button type="url"' . $style . ' url="' . $esc($btn['url']) . '">' . $label . '</tg-button>';
        }
        if (isset($btn['web_app']['url'])) {
            return '<tg-button type="web_app"' . $style . ' url="' . $esc($btn['web_app']['url']) . '">' . $label . '</tg-button>';
        }
        if (isset($btn['login_url']['url'])) {
            $extra = '';
            if (!empty($btn['login_url']['forward_text'])) {
                $extra .= ' forward-text="' . $esc($btn['login_url']['forward_text']) . '"';
            }
            if (!empty($btn['login_url']['request_write_access'])) {
                $extra .= ' request-write-access';
            }
            return '<tg-button type="login_url"' . $style . ' url="' . $esc($btn['login_url']['url']) . '"' . $extra . '>' . $label . '</tg-button>';
        }
        if (isset($btn['switch_inline_query'])) {
            return '<tg-button type="switch_inline_query"' . $style . ' query="' . $esc($btn['switch_inline_query']) . '">' . $label . '</tg-button>';
        }
        if (isset($btn['switch_inline_query_current_chat'])) {
            return '<tg-button type="switch_inline_query_current_chat"' . $style . ' query="' . $esc($btn['switch_inline_query_current_chat']) . '">' . $label . '</tg-button>';
        }
        if (isset($btn['copy_text']['text'])) {
            return '<tg-button type="copy_text"' . $style . ' text="' . $esc($btn['copy_text']['text']) . '">' . $label . '</tg-button>';
        }
        return null;
    }
}

if (!function_exists('rx_richUi_buttonsHtml')) {
    function rx_richUi_buttonsHtml(array $inlineRows): ?string
    {
        $html = '';
        foreach ($inlineRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $btn) {
                if (!is_array($btn)) {
                    continue;
                }
                $cell = rx_richUi_buttonHtml($btn);
                if ($cell === null) {
                    return null;
                }
                if ($cell !== '') {
                    $cells[] = $cell;
                }
            }
            foreach (array_chunk($cells, 8) as $chunk) {
                $html .= '<tg-button-row align="center">' . implode('', $chunk) . '</tg-button-row>';
            }
        }
        return $html;
    }
}

if (!function_exists('rx_richUi_isRtl')) {
    function rx_richUi_isRtl(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
    }
}

if (!function_exists('rx_richUi_targetIsRichMessage')) {
    function rx_richUi_targetIsRichMessage($messageId): bool
    {
        $callbackMessage = $GLOBALS['update']['callback_query']['message'] ?? null;
        if (!is_array($callbackMessage) || !isset($callbackMessage['rich_message'])) {
            return false;
        }
        return (int) ($callbackMessage['message_id'] ?? 0) === (int) $messageId && (int) $messageId > 0;
    }
}

if (!function_exists('rx_richUi_buildMessage')) {
    function rx_richUi_buildMessage(string $textHtml, string $buttonsHtml, array $media = []): array
    {
        $message = [
            'html' => $textHtml . $buttonsHtml,
            'is_rtl' => rx_richUi_isRtl(strip_tags($textHtml)),
        ];
        if ($media) {
            $message['media'] = $media;
        }
        return $message;
    }
}

if (!function_exists('rx_richUi_isFallbackError')) {
    function rx_richUi_isFallbackError($result): bool
    {
        if (!is_array($result)) {
            return false;
        }
        $code = (int) ($result['error_code'] ?? 0);
        $desc = strtolower((string) ($result['description'] ?? ''));
        if ($code === 0 || $code === 429 || $code >= 500) {
            return false;
        }
        foreach (['not modified', 'not found', 'blocked', 'deactivated', 'chat not found'] as $needle) {
            if (strpos($desc, $needle) !== false) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('rx_richUi_attempt')) {
    function rx_richUi_attempt(string $apiMethod, array $base, string $textHtml, string $plainHtml, string $buttonsHtml, array $media, $token)
    {
        $variants = [['full', $textHtml]];
        if ($plainHtml !== '' && $plainHtml !== $textHtml) {
            $variants[] = ['plain', $plainHtml];
        }
        $last = null;
        $chatKey = (string) ($base['chat_id'] ?? '');
        $withRemove = $apiMethod === 'sendRichMessage' && $chatKey !== '' && !empty($GLOBALS['rx_pending_reply_kb_remove'][$chatKey]) && !isset($base['reply_markup']);
        foreach ($variants as [$label, $html]) {
            $payload = $base;
            if ($withRemove) {
                $payload['reply_markup'] = json_encode(['remove_keyboard' => true]);
            }
            $payload['rich_message'] = json_encode(rx_richUi_buildMessage($html, $buttonsHtml, $media), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = telegram($apiMethod, $payload, $token);
            if (is_array($result) && !empty($result['ok'])) {
                if ($withRemove) {
                    unset($GLOBALS['rx_pending_reply_kb_remove'][$chatKey]);
                    if (function_exists('update')) {
                        update('user', 'reply_kb_cleared', '1', 'id', $chatKey);
                    }
                }
                return $result;
            }
            $last = $result;
            if (!rx_richUi_isFallbackError($result)) {
                return $result;
            }
        }
        return ['__fallback' => true, 'last' => $last];
    }
}

if (!function_exists('rx_richUi_dispatch')) {
    function rx_richUi_dispatch($method, array $datas, $token)
    {
        $m = strtolower((string) $method);
        $mediaKeys = ['sendphoto' => 'photo', 'sendvideo' => 'video', 'senddocument' => 'document', 'sendanimation' => 'animation'];
        if (!in_array($m, ['sendmessage', 'editmessagetext', 'editmessagereplymarkup'], true) && !isset($mediaKeys[$m])) {
            return null;
        }
        global $APIKEY;
        if ($token !== null && $token !== $APIKEY) {
            return null;
        }
        $chatId = $datas['chat_id'] ?? null;
        if (!rx_richUi_chatIsUserUi($chatId)) {
            return null;
        }
        if (!empty($datas['entities']) || !empty($datas['caption_entities'])) {
            return null;
        }
        $destRich = function_exists('rx_richActiveForSend') && rx_richActiveForSend($chatId, $datas['reply_markup'] ?? null);
        if ($m === 'editmessagetext') {
            $editId = (int) ($datas['message_id'] ?? 0);
            $cbMessage = $GLOBALS['update']['callback_query']['message'] ?? null;
            $isCbTarget = $editId > 0 && is_array($cbMessage) && (int) ($cbMessage['message_id'] ?? 0) === $editId && !isset($datas['inline_message_id']);
            $targetRich = $isCbTarget && isset($cbMessage['rich_message']);
            $targetLegacyText = $isCbTarget && !$targetRich && isset($cbMessage['text']);
            if (($destRich && $targetLegacyText) || (!$destRich && $targetRich)) {
                return rx_richUi_replaceMessage($datas, $token, $editId);
            }
        }
        if (!$destRich && !($m === 'editmessagereplymarkup' && rx_richUi_targetIsRichMessage((int) ($datas['message_id'] ?? 0)))) {
            return null;
        }

        $markup = $datas['reply_markup'] ?? null;
        if (is_string($markup) && $markup !== '') {
            $markup = json_decode($markup, true);
        }
        $buttonsHtml = '';
        if (is_array($markup) && $markup) {
            if (!isset($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) {
                return null;
            }
            $buttonsHtml = rx_richUi_buttonsHtml($markup['inline_keyboard']);
            if ($buttonsHtml === null) {
                return null;
            }
        }

        $passKeys = ['message_thread_id', 'disable_notification', 'protect_content', 'message_effect_id', 'reply_parameters'];
        $media = [];

        if (isset($mediaKeys[$m])) {
            if ($buttonsHtml === '') {
                return null;
            }
            return rx_richUi_dispatchMedia($m, $mediaKeys[$m], $datas, $buttonsHtml, $passKeys, $token);
        }

        if ($m === 'sendmessage') {
            if ($buttonsHtml === '' || !isset($datas['text']) || !is_string($datas['text'])) {
                return null;
            }
            $textHtml = rx_richUi_textToHtml($datas['text'], $datas['parse_mode'] ?? '');
            $plainHtml = (string) rx_richUi_plainHtml($datas['text'], $datas['parse_mode'] ?? '');
            if ($textHtml === null || $textHtml === '') {
                return null;
            }
            $base = ['chat_id' => $chatId];
            foreach ($passKeys as $passKey) {
                if (isset($datas[$passKey])) {
                    $base[$passKey] = $datas[$passKey];
                }
            }
            if (!isset($base['reply_parameters']) && !empty($datas['reply_to_message_id'])) {
                $base['reply_parameters'] = json_encode(['message_id' => (int) $datas['reply_to_message_id']]);
            }
            $apiMethod = 'sendRichMessage';
            if (rx_ui_section_for_send($chatId, $datas['reply_markup'] ?? null) === 'main_menu') {
                rx_richUi_markMainMenuKeyboardRemoval($chatId);
            }
        } else {
            $messageId = (int) ($datas['message_id'] ?? 0);
            if ($messageId <= 0 || isset($datas['inline_message_id'])) {
                return null;
            }
            $targetIsRich = rx_richUi_targetIsRichMessage($messageId);
            if ($m === 'editmessagereplymarkup') {
                if (!$targetIsRich) {
                    return null;
                }
                $current = rx_richUi_extractText($GLOBALS['update']['callback_query']['message']['rich_message']);
                $textHtml = rx_richUi_textToHtml($current, '');
                $plainHtml = (string) $textHtml;
            } else {
                if ($buttonsHtml === '' && !$targetIsRich) {
                    return null;
                }
                if (!isset($datas['text']) || !is_string($datas['text'])) {
                    return null;
                }
                $textHtml = rx_richUi_textToHtml($datas['text'], $datas['parse_mode'] ?? '');
                $plainHtml = (string) rx_richUi_plainHtml($datas['text'], $datas['parse_mode'] ?? '');
            }
            if ($textHtml === null || $textHtml === '') {
                return null;
            }
            $base = ['chat_id' => $chatId, 'message_id' => $messageId];
            $apiMethod = 'editMessageText';
        }

        $result = rx_richUi_attempt($apiMethod, $base, $textHtml, $plainHtml, $buttonsHtml, $media, $token);
        if (is_array($result) && isset($result['__fallback'])) {
            return null;
        }
        return $result;
    }
}

if (!function_exists('rx_richUi_replaceMessage')) {
    function rx_richUi_replaceMessage(array $datas, $token, int $messageId)
    {
        $parseMode = $datas['parse_mode'] ?? null;
        $text = isset($datas['text']) && is_string($datas['text']) ? $datas['text'] : '';
        if (trim($text) === '') {
            $callbackMessage = $GLOBALS['update']['callback_query']['message'] ?? [];
            $text = rx_richUi_extractText($callbackMessage['rich_message'] ?? ($callbackMessage['text'] ?? ''));
            $parseMode = null;
        }
        if (trim($text) === '') {
            return null;
        }
        $send = [
            'chat_id' => $datas['chat_id'],
            'text' => $text,
            'parse_mode' => $parseMode,
            'reply_markup' => $datas['reply_markup'] ?? null,
            '_rx_already_transformed' => true,
        ];
        $thread = (int) ($GLOBALS['update']['callback_query']['message']['message_thread_id'] ?? 0);
        if ($thread > 0) {
            $send['message_thread_id'] = $thread;
        }
        $result = telegram('sendmessage', $send, $token);
        if (is_array($result) && !empty($result['ok'])) {
            telegram('deletemessage', ['chat_id' => $datas['chat_id'], 'message_id' => $messageId], $token);
        }
        return $result;
    }
}

if (!function_exists('rx_richUi_markMainMenuKeyboardRemoval')) {
    function rx_richUi_markMainMenuKeyboardRemoval($chatId): void
    {
        $chatKey = trim((string) $chatId);
        if ($chatKey === '' || !ctype_digit($chatKey) || !function_exists('rx_autoRemoveReplyKeyboardOn') || !rx_autoRemoveReplyKeyboardOn() || !function_exists('select')) {
            return;
        }
        try {
            $flag = select('user', 'reply_kb_cleared', 'id', $chatKey, 'select', ['cache' => false]);
            if (!is_scalar($flag) || (string) $flag !== '1') {
                $GLOBALS['rx_pending_reply_kb_remove'][$chatKey] = true;
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('rx_richUi_dispatchMedia')) {
    function rx_richUi_dispatchMedia(string $method, string $kind, array $datas, string $buttonsHtml, array $passKeys, $token)
    {
        $chatId = $datas['chat_id'];
        $file = $datas[$kind] ?? null;
        $caption = isset($datas['caption']) && is_string($datas['caption']) ? $datas['caption'] : '';
        $parseMode = $datas['parse_mode'] ?? '';
        $captionHtml = $caption === '' ? '' : rx_richUi_textToHtml($caption, $parseMode);
        if ($captionHtml === null) {
            return null;
        }

        if (is_string($file) && preg_match('/^[A-Za-z0-9_\-]{20,}$/', $file)) {
            $mediaType = $kind;
            $tags = [
                'photo' => '<figure><img src="tg://photo?id=m1"/></figure>',
                'video' => '<video src="tg://video?id=m1"></video>',
                'animation' => '<video src="tg://video?id=m1"></video>',
                'document' => '<tg-document src="tg://document?id=m1"></tg-document>',
            ];
            $tag = $tags[$kind];
            $mediaItem = [['id' => 'm1', 'media' => ['type' => $mediaType, 'media' => $file]]];
            $plainCaption = $caption === '' ? '' : (string) rx_richUi_plainHtml($caption, $parseMode);
            $base = ['chat_id' => $chatId];
            foreach ($passKeys as $passKey) {
                if (isset($datas[$passKey])) {
                    $base[$passKey] = $datas[$passKey];
                }
            }
            $result = rx_richUi_attempt('sendRichMessage', $base, $tag . $captionHtml, $tag . $plainCaption, $buttonsHtml, $mediaItem, $token);
            if (!(is_array($result) && isset($result['__fallback']))) {
                return $result;
            }
        }

        $mediaDatas = $datas;
        unset($mediaDatas['reply_markup']);
        $mediaResult = telegram($method, $mediaDatas, $token);
        if (!is_array($mediaResult) || empty($mediaResult['ok'])) {
            return $mediaResult;
        }
        $control = [
            'chat_id' => $chatId,
            'text' => 'برای ادامه یکی از گزینه‌های زیر را انتخاب کنید.',
            'parse_mode' => 'HTML',
            'reply_markup' => is_string($datas['reply_markup']) ? $datas['reply_markup'] : json_encode($datas['reply_markup'], JSON_UNESCAPED_UNICODE),
            '_rx_already_transformed' => true,
        ];
        foreach ($passKeys as $passKey) {
            if (isset($datas[$passKey]) && $passKey !== 'reply_parameters') {
                $control[$passKey] = $datas[$passKey];
            }
        }
        $controlResult = telegram('sendmessage', $control, $token);
        if (is_array($controlResult) && !empty($controlResult['ok'])) {
            $mediaResult['rx_control_message_id'] = (int) ($controlResult['result']['message_id'] ?? 0);
        }
        return $mediaResult;
    }
}

if (!function_exists('rx_richUi_sendBanner')) {
    function rx_richUi_sendBanner($chatId, $fileId, $text, $keyboard, $parseMode, $section = null)
    {
        if (!function_exists('rx_richActiveForSend') || !rx_richActiveForSend($chatId, $keyboard, $section)) {
            return null;
        }
        $fileId = trim((string) $fileId);
        if ($fileId === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $fileId)) {
            return null;
        }
        if (function_exists('applyPremiumEmojiTransform')) {
            $text = applyPremiumEmojiTransform($text, $parseMode);
        }
        if (function_exists('processKeyboardStyles')) {
            $keyboard = processKeyboardStyles($keyboard, false, $chatId);
        }
        if (function_exists('applyPremiumEmojiToKeyboard')) {
            $keyboard = applyPremiumEmojiToKeyboard($keyboard);
        }
        $markup = is_string($keyboard) ? json_decode($keyboard, true) : $keyboard;
        if (!is_array($markup) || !isset($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) {
            return null;
        }
        $buttonsHtml = rx_richUi_buttonsHtml($markup['inline_keyboard']);
        $textHtml = rx_richUi_textToHtml($text, $parseMode);
        if ($buttonsHtml === null || $buttonsHtml === '' || $textHtml === null) {
            return null;
        }
        $media = [['id' => 'banner', 'media' => ['type' => 'photo', 'media' => $fileId]]];
        $tag = '<figure><img src="tg://photo?id=banner"/></figure>';
        $plainHtml = (string) rx_richUi_plainHtml($text, $parseMode);
        $result = rx_richUi_attempt('sendRichMessage', ['chat_id' => $chatId], $tag . $textHtml, $tag . $plainHtml, $buttonsHtml, $media, null);
        return is_array($result) && !empty($result['ok']) ? $result : null;
    }
}

if (!function_exists('rx_richUi_readModes')) {
    function rx_richUi_readModes(): ?array
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return null;
        }
        $stmt = $pdo->query("SELECT inlinebtnmain, user_rich_buttons_enabled FROM setting LIMIT 1");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!is_array($row)) {
            return null;
        }
        return [
            'glass' => (string) ($row['inlinebtnmain'] ?? '') === 'oninline',
            'rich' => (string) ($row['user_rich_buttons_enabled'] ?? 'off') === 'on',
        ];
    }
}

if (!function_exists('rx_richUi_refreshSettings')) {
    function rx_richUi_refreshSettings(): void
    {
        if (function_exists('clearSelectCache')) {
            clearSelectCache('setting');
        }
        if (function_exists('faoxima_bust_bot_selectcache')) {
            faoxima_bust_bot_selectcache('setting');
        }
        if (function_exists('select')) {
            $fresh = select('setting', '*', null, null, 'select', ['cache' => false]);
            if (is_array($fresh)) {
                $GLOBALS['setting'] = $fresh;
            }
        }
    }
}

if (!function_exists('rx_richUi_toggleMode')) {
    function rx_richUi_toggleMode(string $which, string $embeddedValue): array
    {
        global $pdo;
        $modes = rx_richUi_readModes();
        if ($modes === null) {
            return ['ok' => false, 'changed' => false, 'message' => 'خطا در خواندن تنظیمات.'];
        }
        $isGlass = $which === 'glass';
        $current = $isGlass ? $modes['glass'] : $modes['rich'];
        $embeddedOn = $isGlass ? $embeddedValue === 'oninline' : $embeddedValue === 'on';
        if ($embeddedOn !== $current) {
            return ['ok' => true, 'changed' => false, 'message' => 'وضعیت تغییر کرده بود؛ صفحه به‌روز شد.'];
        }

        $column = $isGlass ? 'inlinebtnmain' : 'user_rich_buttons_enabled';
        $target = $isGlass ? ($current ? 'offinline' : 'oninline') : ($current ? 'off' : 'on');
        $onValue = $isGlass ? 'oninline' : 'on';
        $guard = $current ? "{$column} = ?" : "COALESCE({$column}, '') <> ?";
        $stmt = $pdo->prepare("UPDATE setting SET {$column} = ? WHERE {$guard}");
        $stmt->execute([$target, $onValue]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => true, 'changed' => false, 'message' => 'وضعیت تغییر کرده بود؛ صفحه به‌روز شد.'];
        }
        return ['ok' => true, 'changed' => true, 'message' => ''];
    }
}

if (!function_exists('rx_richUi_adminToggle')) {
    function rx_richUi_adminToggle(string $which, string $embeddedValue, bool $authorized, $callbackQueryId = null): array
    {
        $outcome = ['ok' => false, 'changed' => false, 'message' => 'شما دسترسی تغییر این تنظیم را ندارید.'];
        if ($authorized) {
            try {
                $outcome = rx_richUi_toggleMode($which, $embeddedValue);
            } catch (Throwable $e) {
                error_log('[rich_ui] toggle failed: ' . $e->getMessage());
                $outcome = ['ok' => false, 'changed' => false, 'message' => 'خطا در ذخیره تنظیمات.'];
            }
            rx_richUi_refreshSettings();
        }
        if (!empty($callbackQueryId) && $outcome['message'] !== '' && function_exists('telegram')) {
            @telegram('answerCallbackQuery', [
                'callback_query_id' => $callbackQueryId,
                'text' => $outcome['message'],
                'show_alert' => true,
            ]);
        }
        return $outcome;
    }
}
