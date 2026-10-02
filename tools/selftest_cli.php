<?php
// ===== تست رفتار ابزارهای CLI در برابر خطای کرش‌کننده =====
//
// رگرسیون: registerExceptionHandler() در src/Logger.php در سطح فایل اجرا می‌شود،
// پس هر ابزار CLI که آن را require کند یک exception handler داشت که خطا را می‌بلعید
// و PHP با exit code 0 بیرون می‌آمد. یعنی selftest / dryrun / healthcheck می‌توانستند
// وسط کار بمیرند و CI باز هم «سبز» گزارش بگیرد.
//
// این تست با زیرپروسه اجرا می‌شود چون رفتارِ مورد نظر خودِ exit code است و درون
// همان پروسه قابل سنجش نیست. اگر exec غیرفعال باشد (هاست اشتراکی) SKIPPED می‌شود.

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else { $fail++; echo "  ✗ {$name}" . ($extra !== '' ? "  ({$extra})" : '') . "\n"; }
}

$root = dirname(__DIR__);
$php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY)) ? PHP_BINARY : 'php';

/**
 * یک اسکریپت موقت می‌نویسد و اجرا می‌کند.
 * خروجی: [exitCode, combinedStdoutStderr] یا [-1, ''] اگر اجرا ممکن نبود.
 *
 * از proc_open استفاده می‌شود چون تنها راه گرفتن exit code واقعی است. با
 * shell_exec و `echo $?` روی ویندوز اصلاً کار نمی‌کرد چون آنجا cmd.exe است و
 * `$?` را literal چاپ می‌کند — یعنی تست بی‌سروصدا «همه‌چیز سبز» می‌شد.
 */
function runSnippet(string $body): array
{
    global $php, $root;
    $f = tempnam(sys_get_temp_dir(), 'clicrash_') . '.php';
    file_put_contents($f, str_replace('__ROOT__', var_export($root, true), $body));

    $cmd = '"' . $php . '" "' . $f . '"';
    // cmd.exe کل رشتهٔ بعد از /c را باید داخل یک جفت کوتیشن بگیرد، وگرنه مسیرِ
    // دارای فاصله (مثل Program Files) «syntax is incorrect» می‌دهد.
    if (PHP_OS_FAMILY === 'Windows') $cmd = 'cmd /d /c ""' . $php . '" "' . $f . '""';

    if (function_exists('proc_open')) {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (is_resource($proc)) {
            $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            @unlink($f);
            return [$code, $out];
        }
    }

    // fallback: فقط می‌توانیم ببینیم اجرا اصلاً انجام شد یا نه (بدون exit code)
    if (function_exists('shell_exec')) {
        $out = (string)@shell_exec('"' . $php . '" "' . $f . '" 2>&1');
        @unlink($f);
        return [-1, $out];
    }

    @unlink($f);
    return [-1, ''];
}

/** خروجی را برای نمایش در پیام خطا تک‌خطی و کوتاه می‌کند */
function squash(string $s): string
{
    return substr(preg_replace('/\s+/', ' ', $s) ?? '', 0, 140);
}

echo "\n--- رفتار CLI در برابر خطای کرش‌کننده ---\n";

// اگر نتوانستیم exit code را بخوانیم، تست بی‌فایده است: «کرش ⇒ غیرصفر» با کدِ
// ناشناخته (-1) همیشه true می‌شود و یک باگ واقعی را سبز گزارش می‌کند. حتی بدتر:
// اگر خودِ دستور اشتباه باشد (مثلاً quoting در cmd) هر اجرا «شکست» می‌دهد و
// تست همه‌چیز را درست می‌بیند. پس اول خودِ توانِ سنجش را می‌سنجیم.
[$probeCode, $probeOut] = runSnippet("<?php echo 'probe';\n");
if (!str_contains($probeOut, 'probe')) {
    echo "  ⏭  SKIPPED (زیرپروسه اجرا نشد: " . squash($probeOut) . ")\n";
} else {
    // سناریوی ۱: exception بلاع catching ⇒ باید exit 1 و پیام روی خروجی باشد
    [$code, $out] = runSnippet(
        "<?php\n"
        . "require __ROOT__ . '/src/Logger.php';\n"
        . "echo 'REACHED-A';\n"
        . "throw new RuntimeException('boom');\n"
        . "echo 'REACHED-B';\n"
    );

    check('exception بلاع catching ⇒ exit code غیرصفر', $code !== 0, 'exit=' . $code);
    check(
        'پیام خطا روی خروجی دیده می‌شود (نه فقط فایل لاگ)',
        str_contains($out, 'boom'),
        squash($out)
    );
    check('کدِ بعد از استثنا اجرا نمی‌شود', !str_contains($out, 'REACHED-B'), squash($out));

    // سناریوی ۲: TypeError هم باید مثل exception رفتار کند (Error است نه Exception)
    [$code2, $out2] = runSnippet(
        "<?php\n"
        . "require __ROOT__ . '/src/Logger.php';\n"
        . "array_map('rtrim', [], '/');\n"
        . "echo 'REACHED-A';\n"
    );
    check('TypeError ⇒ exit code غیرصفر', $code2 !== 0, 'exit=' . $code2);
    check('TypeError هم گزارش می‌شود', str_contains($out2, 'TypeError'), squash($out2));

    // سناریوی ۳: warning باید روی ترمینال دیده شود (CI فقط stderr را می‌بیند)
    // ولی نباید اسکریپت را بکشد یا exit code را خراب کند
    [$code3, $out3] = runSnippet(
        "<?php\n"
        . "require __ROOT__ . '/src/Logger.php';\n"
        . "\$x = [];\n"
        . "echo \$x['missing'];\n"
        . "echo 'REACHED-A';\n"
    );
    check(
        'warning روی خروجی دیده می‌شود',
        str_contains($out3, 'Undefined'),
        squash($out3)
    );
    check('warning کل اسکریپت را نمی‌کشد', str_contains($out3, 'REACHED-A'), squash($out3));
    check('warning تنها ⇒ exit code صفر (رفتار درست PHP)', $code3 === 0, 'exit=' . $code3);

    // سناریوی ۴: اسکریپت سالم باید بی‌سروصدا exit 0 بدهد (false positive نگیریم)
    [$code4, $out4] = runSnippet(
        "<?php\n"
        . "require __ROOT__ . '/src/Logger.php';\n"
        . "echo 'OK';\n"
    );
    check('اسکریپت سالم ⇒ exit 0', $code4 === 0, 'exit=' . $code4);
    check('اسکریپت سالم ⇒ بدون پیام 💥', !str_contains($out4, '💥'), squash($out4));
}

echo "\n" . str_repeat('=', 52) . "\n";
echo "  CLI crash: {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 52) . "\n";
exit($fail === 0 ? 0 : 1);