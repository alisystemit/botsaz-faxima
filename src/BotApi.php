<?php
// ===== لایه ارتباط حرفه‌ای با API تلگرام =====
// ویژگی‌ها: retry با backoff، مدیریت 429، پشتیبانی پروکسی، لگ‌گذاری

class BotApi
{
    private static ?string $proxy = null;
    private static int $maxRetries = 3;
    private static int $baseDelay = 200000; // 200ms initial backoff

    public static function setProxy(?string $url): void
    {
        self::$proxy = $url;
    }

    public static function setMaxRetries(int $n): void
    {
        self::$maxRetries = $n;
    }

    /**
     * اصلی‌ترین درخواست API — با retry و backoff
     */
    public static function call(string $token, string $method, array $params = []): array
    {
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        $attempt = 0;
        $lastErr = '';

        // ===== نرمال‌سازی پارامترها برای Content-Type: x-www-form-urlencoded =====
        // آرایه‌ها → JSON (مثل commands)، بولین → 'true'/'false'، null حذف می‌شود.
        $flat = [];
        foreach ($params as $k => $v) {
            if ($v === null) continue;
            if (is_array($v)) $flat[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            elseif (is_bool($v)) $flat[$k] = $v ? 'true' : 'false';
            else $flat[$k] = $v;
        }
        $body = http_build_query($flat, '', '&');

        while ($attempt <= self::$maxRetries) {
            if ($attempt > 0) {
                $delay = self::$baseDelay * (2 ** ($attempt - 1));
                usleep($delay);
            }

            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ];

            if (self::$proxy) {
                $opts[CURLOPT_PROXY] = self::$proxy;
            }

            curl_setopt_array($ch, $opts);
            $out = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($out === false) {
                $lastErr = $err;
                $attempt++;
                continue;
            }

            // ===== 429 Rate Limit =====
            if ($httpCode === 429) {
                $retryAfter = self::extractRetryAfter($out);
                if ($retryAfter > 0) {
                    usleep($retryAfter * 1000000);
                } else {
                    usleep(self::$baseDelay * (2 ** $attempt));
                }
                $attempt++;
                continue;
            }

            $j = json_decode($out, true);
            if (!is_array($j)) {
                $lastErr = 'Invalid JSON response';
                $attempt++;
                continue;
            }

            // ===== ok === true but with retry-after in response =====
            if (!empty($j['ok'])) {
                return $j;
            }

            // ===== Error in response body =====
            $description = $j['description'] ?? 'Unknown error';
            // Retry on transient errors
            if (str_contains($description, 'Retry') || str_contains($description, 'retry')) {
                $attempt++;
                continue;
            }

            return $j;
        }

        return ['ok' => false, 'error' => 'Max retries exceeded: ' . $lastErr];
    }

    private static function extractRetryAfter(string $response): int
    {
        // Parse Retry-After from Telegram error response
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['parameters']['retry_after'])) {
            return (int)$data['parameters']['retry_after'];
        }
        return 0;
    }

    public static function getMe(string $token): array
    {
        return self::call($token, 'getMe');
    }

    public static function getWebhookInfo(string $token): array
    {
        return self::call($token, 'getWebhookInfo');
    }

    public static function setWebhook(string $token, string $url, ?string $secretToken = null): array
    {
        $p = ['url' => $url, 'drop_pending_updates' => true];
        if ($secretToken !== null && $secretToken !== '') $p['secret_token'] = $secretToken;
        return self::call($token, 'setWebhook', $p);
    }

    public static function deleteWebhook(string $token): array
    {
        return self::call($token, 'deleteWebhook', ['drop_pending_updates' => true]);
    }

    public static function send(string $token, $chatId, string $text, array $extra = []): array
    {
        return self::call($token, 'sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public static function answerCb(string $token, string $cbId, string $text = ''): void
    {
        self::call($token, 'answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => $text]);
    }

    public static function edit(string $token, $chatId, $msgId, string $text, array $extra = []): void
    {
        self::call($token, 'editMessageText', array_merge([
            'chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'HTML',
        ], $extra));
    }

    public static function setMyCommands(string $token, array $commands): array
    {
        return self::call($token, 'setMyCommands', [
            'commands' => array_map(fn($cmd) => [
                'command' => $cmd['command'],
                'description' => $cmd['description'] ?? '',
            ], $commands),
        ]);
    }

    public static function deleteMyCommands(string $token): array
    {
        return self::call($token, 'deleteMyCommands');
    }

    public static function kb(array $rows, bool $oneTime = false): string
    {
        return json_encode(['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => $oneTime]);
    }

    public static function ikb(array $rows): string
    {
        return json_encode(['inline_keyboard' => $rows]);
    }

    public static function removeKb(): string
    {
        return json_encode(['remove_keyboard' => true]);
    }
}
