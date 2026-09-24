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
     * لاگ شکست API — هرگز توکن/URL را در پیام نمی‌آورد.
     *
     * چرا لازم بود: قبلاً call() موقع شکست فقط آرایهٔ ok:false برمی‌گرداند و
     * بیشتر فراخواننده‌ها (نزدیک به ۶۷ مورد sendMessage در bot.php) نتیجه را
     * نمی‌خواندند؛ یعنی هر خطای تلگرام بی‌صدا گم می‌شد و هیچ لاگی ثبت نمی‌شد.
     *
     * dedupe داخلی: همان خطا در یک درخواست فقط یک‌بار نوشته می‌شود تا لاگ سیل نشود.
     * (staticها در PHP به‌ازای هر request ریست می‌شوند؛ پس بین درخواست‌ها اثری ندارد.)
     */
    private static function logFail(string $method, string $detail): void
    {
        if (!class_exists('Logger')) return;
        static $seen = [];
        if (count($seen) >= 50) return;              // سقف ساده برای یک درخواست
        $key = $method . '|' . $detail;
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        try {
            Logger::getInstance()->error('telegram', "{$method} failed: {$detail}");
        } catch (Throwable $e) {
            // لاگ هرگز نباید خودش باعث شکست درخواست شود
        }
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

            // جدی/برگشت‌ناپذیر: لاگ کن تا دلیل «جواب ندادن ربات» در data/logs/ دیده شود
            self::logFail($method, 'error_code=' . ($j['error_code'] ?? '-') . ' — ' . $description);
            return $j;
        }

        // همهٔ retryها تمام شد (خطای شبکه/JSON نامعتبر/429) — اینجا هم لاگ کن
        self::logFail($method, 'Max retries exceeded — ' . $lastErr);

        // description (همان کلیدی که فراخواننده‌ها می‌خوانند)؛ error برای سازگاری قدیمی حفظ می‌شود
        return ['ok' => false, 'description' => 'Max retries exceeded: ' . $lastErr, 'error' => 'Max retries exceeded: ' . $lastErr];
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
        // بدون drop_pending_updates: ست مجدد وبهوک (ترمیم/فعال‌سازی مجدد) نباید
        // صف پیام‌های در انتظار کاربران را بریزد — قبلاً هر بار ست وبهوک همه را پاک می‌کرد.
        $p = ['url' => $url];
        if ($secretToken !== null && $secretToken !== '') $p['secret_token'] = $secretToken;
        return self::call($token, 'setWebhook', $p);
    }

    public static function deleteWebhook(string $token): array
    {
        return self::call($token, 'deleteWebhook', ['drop_pending_updates' => true]);
    }

    public static function send(string $token, $chatId, string $text, array $extra = []): array
    {
        $r = self::call($token, 'sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra));
        // قبلاً خیلی از فراخواننده‌ها نتیجه را نمی‌خواندند ⇒ پیام ارسال‌نشده بی‌صدا گم می‌شد
        if (!is_array($r) || empty($r['ok'])) {
            self::logFail('sendMessage', 'chat=' . $chatId . ' — ' . (($r['description'] ?? '') ?: 'no response'));
        }
        return $r;
    }

    public static function answerCb(string $token, string $cbId, string $text = ''): void
    {
        $r = self::call($token, 'answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => $text]);
        // خروجی void بود و نتیجه دور ریخته می‌شد ⇒ شکست کاملاً نامرئی
        if (!is_array($r) || empty($r['ok'])) {
            self::logFail('answerCallbackQuery', (($r['description'] ?? '') ?: 'no response'));
        }
    }

    public static function edit(string $token, $chatId, $msgId, string $text, array $extra = []): void
    {
        $r = self::call($token, 'editMessageText', array_merge([
            'chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'HTML',
        ], $extra));
        if (!is_array($r) || empty($r['ok'])) {
            self::logFail('editMessageText', 'chat=' . $chatId . ' — ' . (($r['description'] ?? '') ?: 'no response'));
        }
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
