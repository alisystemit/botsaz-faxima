<?php
// ===== لایهٔ زیباسازی متن (HTML parse_mode = HTML تلگرام) =====
//
// همهٔ پیام‌های ربات‌ساز parse_mode=HTML هستند. این کلاس کمک می‌کند متن‌ها
//  * خوانا  باشند      → فاصله‌گذاری و جداکنندهٔ ثابت
//  * زیبا   باشند      → ایموجی + قالب‌بندی یکدست
//  * درست    باشند      → escape اجباری هر دادهٔ دیتابیس/کاربر
//  * کلیک‌پذیر باشند    → آدرس‌ها خودکار به لینک تبدیل می‌شوند
//  * قابل کپی باشند     → کد/نقل‌قول برای آدرس، توکن، خطا و …
//
// چرا یک لایه؟ قبلاً هر پیام با الحاق رشته ساخته می‌شد؛ نتیجه: فاصله‌ها نامنظم،
// آدرس‌ها متنِ مرده، و داده‌های کاربر بدون escape (که یعنی پیام خراب یا تزریق HTML).
//
// نکتهٔ مهم: تلگرام فقط تگ‌های محدودی را می‌پذیرد (b, i, u, s, a, code, pre,
// blockquote, tg-spoiler). هر چیز دیگری ⇒ خطای parse و پیامِ ازبین‌رفته.

class Ui
{
    /** جداکنندهٔ افقی ثابت — بین بلوک‌های یک پیام */
    public const SEP = '━━━━━━━━━━━━━━━━━━━━━━';

    /** escape استاندارد UTF-8 با ENT_QUOTES تا داخل <code> هم امن باشد */
    public static function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * لینک کلیک‌پذیر. برچسب پیش‌فرض = خودِ آدرس (بدون www/پروتکل اضافه).
     * آدرس فقط وقتی لینک می‌شود که واقعاً http(s) باشد؛ ورودی خراب escape می‌شود
     * و هرگز به‌صورت <a href="javascript:…"> رد نمی‌رود.
     */
    public static function link(string $url, string $label = ''): string
    {
        $url = trim($url);
        if ($url === '') return '';
        // اگر اسکیمای صریحی دارد، فقط http/https مجاز است؛ بقیه (javascript:، data:)
        // اصلاً لینک نمی‌شود تا هرگز وارد href نشود.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            if (!preg_match('#^https?://#i', $url)) return self::e($url);
        } else {
            $url = 'https://' . ltrim($url, '/');
        }
        if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) return self::e($url);
        $label = trim($label) !== '' ? trim($label) : self::prettyUrl($url);
        return '<a href="' . self::e($url) . '">' . self::e($label) . '</a>';
    }

    /** آدرس را برای نمایش کوتاه می‌کند: حذف http:// و اسلش آخر */
    public static function prettyUrl(string $url): string
    {
        $u = preg_replace('#^https?://#i', '', trim($url)) ?? $url;
        return rtrim($u, '/');
    }

    /** کد درون‌خطی (تک‌خطی، برای توکن/آیدی/مسیر) */
    public static function code(string $s): string
    {
        $s = self::e($s);
        if ($s === '') return '';
        // کد طولانی داخل پیام بد می‌شود ⇒ قطعه‌قطعه با فاصله‌گذاری نازک
        return '<code>' . $s . '</code>';
    }

    /** بلوک کد چندخطی (لاگ، پاسخ API، اسکریپت) */
    public static function pre(string $s, string $lang = 'text'): string
    {
        $s = self::e($s);
        if ($s === '') return '';
        return '<pre><code class="language-' . self::e(preg_replace('/[^a-z0-9]/i', '', $lang) ?: 'text') . '">'
            . $s . '</code></pre>';
    }

    /** نقل‌قول (blockquote) — برای متن ادمین/یادداشت/شرایط */
    public static function quote(string $s, bool $expandable = false): string
    {
        $s = trim(self::tidy($s));
        if ($s === '') return '';
        return '<blockquote' . ($expandable ? ' expandable' : '') . '>' . $s . '</blockquote>';
    }

    public static function sep(): string
    {
        return self::SEP;
    }

    /**
     * یک سطر «برچسب: مقدار» با فاصله‌گذاری و ایموجیِ ثابت.
     * $mono=true ⇒ مقدار داخل <code> (برای مسیر/شناسه/آدرس).
     */
    public static function kv(string $icon, string $label, string $value, bool $mono = false): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if ($mono) $value = self::code($value);
        else $value = self::e($value);
        return trim($icon . ' <b>' . self::e($label) . ':</b> ' . $value);
    }

    /**
     * مثل kv ولی برای مقداری که خودش HTML دارد (<b>، <i>، …).
     * kv مقدار را escape می‌کند، پس اگر مقدارت از قبل تگ داشته باشد
     * باید از این استفاده شود وگرنه تگ‌ها به‌صورت متنِ چاپ‌شده دیده می‌شوند.
     */
    public static function kvRaw(string $icon, string $label, string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        return trim($icon . ' <b>' . self::e($label) . ':</b> ' . $value);
    }

    /** یک سطر بولت‌دار با ایموجی */
    public static function bullet(string $icon, string $text): string
    {
        return trim($icon . ' ' . $text);
    }

    /**
     * چند آدرس زیر هم، هرکدام با برچسب خودش.
     * ورودی: ['عنوان' => 'https://…'] — خروجی سطرهای آمادهٔ پیام.
     */
    public static function links(array $map): string
    {
        $out = [];
        foreach ($map as $label => $url) {
            $url = trim((string)$url);
            if ($url === '') continue;
            $out[] = self::bullet('🔗', '<b>' . self::e((string)$label) . ':</b> ' . self::link($url));
        }
        return implode("\n", $out);
    }

    /**
     * تبدیل خودکار هر آدرسِ متنِ آزاد به لینک.
     *
     * **idempotent** است: بلوک‌های `<a …>…</a>` از قبل موجود کاملاً دست‌نخورده
     * می‌مانند. بدون این، متنی که دو بار از `out()` رد شود (مثلاً متنِ ماژولِ
     * متن‌ها که خودش `out()` شده و بعد در یک پیامِ بزرگ‌تر دوباره قالب می‌گیرد)
     * لینکِ تو‌در‌تو می‌ساخت ⇒ تلگرام با خطای parse کل پیام را دور می‌انداخت.
     */
    public static function autoLink(string $s): string
    {
        if ($s === '' || stripos($s, 'http') === false) return $s;
        $out = '';
        // ۱) بلوک‌های لینکِ کامل جدا می‌شوند و بازسازی می‌شوند
        $chunks = preg_split('#(<a\s[^>]*>.*?</a>)#is', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($chunks)) return $s;
        foreach ($chunks as $chunk) {
            if ($chunk === '') continue;
            if (stripos($chunk, '<a ') === 0) { $out .= $chunk; continue; }
            // ۲) داخل هر تکه، بقیهٔ تگ‌ها دست‌نخورده و فقط متن لینک می‌شود
            $parts = preg_split('#(</?[a-z][a-z0-9]*(?:\s[^<>]*)?>)#i', $chunk, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts)) { $out .= $chunk; continue; }
            foreach ($parts as $p) {
                if ($p === '') continue;
                if ($p[0] === '<' && substr($p, -1) === '>') { $out .= $p; continue; }
                $out .= preg_replace_callback(
                    '#(?<![\w@/])((?:https?://|www\.)[^\s<>"\']*[^\s<>"\'.,;:!?)\]}])#i',
                    static function (array $m): string {
                        $u = $m[1];
                        $prefix = '';
                        if (stripos($u, 'www.') === 0) { $prefix = 'https://'; }
                        return self::link($prefix . $u);
                    },
                    $p
                ) ?? $p;
            }
        }
        return $out;
    }

    /**
     * یکدست‌سازی فاصله‌ها — قبل از ارسال هر متنِ ساخته‌شده با الحاق رشته.
     *  • کرانگه‌های فارسی/عربی → فاصلهٔ معمولی (تا کلمه‌ها به هم نچسبند)
     *  • فاصلهٔ چندگانه → یکی  (ولی داخل <code>/<pre> دست‌نخورده)
     *  • خطوط خالیِ پشت‌سرهم → یکی
     *  • فاصلهٔ اضافه قبل از «:» و «،» حذف می‌شود
     */
    public static function tidy(string $s): string
    {
        if ($s === '') return '';
        // بخش‌های «verbatim» (کد/پیش) را موقتاً بیرون می‌کشیم تا فاصله‌هایشان نرود
        $keep = [];
        $s = preg_replace_callback('#<(code|pre|blockquote|a)\b[^>]*>.*?</\1>#is',
            static function (array $m) use (&$keep): string {
                $keep[] = $m[0];
                return "\x00" . (count($keep) - 1) . "\x00";
            }, $s) ?? $s;

        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/ *\n */u', "\n", $s) ?? $s;
        $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
        $s = preg_replace('/[ \t]+([:،؛\.])/u', '$1', $s) ?? $s;
        if ($keep !== []) {
            $s = preg_replace_callback('/\x00(\d+)\x00/u',
                static fn(array $m): string => $keep[(int)$m[1]] ?? '', $s) ?? $s;
        }
        return trim($s);
    }

    /** عدد با جداکنندهٔ هزارگان + علامت پلاس برای مقدارهای مثبت */
    public static function num($n, bool $sign = false): string
    {
        $i = (float)$n;
        $out = ($i == (int)$i) ? number_format((int)$i) : number_format($i, 0);
        return ($sign && $i > 0) ? '+' . $out : $out;
    }

    /** مبلغ تومان با علامت و جداکننده — همه‌جای ربات‌ساز از همین استفاده می‌کند */
    public static function toman($n): string
    {
        return number_format((float)$n) . ' تومان';
    }

    /**
     * متن نهایی پیش از ارسال: فاصله‌ها یکدست + آدرس‌ها لینک + محافظت از
     * پیام‌هایی که کاربر/ادمین نوشته‌اند.
     */
    public static function out(string $s, bool $links = true): string
    {
        $s = self::tidy($s);
        return $links ? self::autoLink($s) : $s;
    }
}