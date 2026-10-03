#!/usr/bin/env bash
# ===== بروزرسانی ربات‌ساز از گیت‌هاب =====
# استفاده: bash tools/update.sh [--dry-run] [--no-restart] [--force]
#
# این اسکریپت فقط فایل‌های کد را آپدیت می‌کند.
# فایل‌های کانفیگ (config.php, bots/*/config.php) هرگز تغییر نمی‌کنند.
# فایل‌های داده (data/, bots/*/states) حفظ می‌شوند.
#
# مراحل:
#   ۰. پیش‌بینی (git، اینترنت، فضا) + قفل اجرا
#   ۱. بک‌آپ config files (۳ نسخه آخر نگه داشته می‌شود)
#   ۲. git fetch + merge با --ff-only (درخت کثیف = abort، نه merge کور)
#   ۳. بررسی و بازگردانی config
#   ۴. بازسازی .htaccess گمشده + اجرای install.php (مایگریشن‌ها)
#   ۵. فیکس /root permissions
#   ۶. ریلود vhost
#   ۷. فیکس systemd sandbox (همه یونیت‌ها، هر دو پراپرتی)
#   ۸. فیکس PCRE JIT
#   ۹. ریستارت سرویس‌ها (یک دور) + warmup و re-confirm وبهوک
#   ۱۰. تست سلامت
#   ۱۱. تأیید وبهوک
#   ۱۲. نوتیفیکیشن تلگرام به ادمین
#
# آپشن‌ها:
#   --dry-run     فقط نشان می‌دهد چه چیزی می‌آید، تغییری نمی‌دهد
#   --no-restart  سرویس‌ها را ری‌استارت نمی‌کند (و وبهوک re-confirm نمی‌شود)
#   --force       پیش‌بینی اینترنت/فضا را رد می‌کند (محافظت درخت کثیف همیشه فعال است)
#   --web         مثل --no-restart + بدون ریلود vhost/systemd/ری‌استارت سرویس؛
#                 برای اجرای داخل ربات (دکمهٔ ⬆️ آپدیت) تا خودِ درخواست نكشد

# ===== آپشن‌ها =====
DRY_RUN=0
NO_RESTART=0
FORCE=0
WEB=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --no-restart) NO_RESTART=1 ;;
        --force) FORCE=1 ;;
        # اجرا از داخل خودِ ربات (دکمهٔ ⬆️ آپدیت): ری‌استارت سرویس، ریلود
        # vhost و هر چیزی که درخواست HTTP فعلی را بکشد حتماً رد می‌شود.
        --web) WEB=1; NO_RESTART=1 ;;
    esac
done

# بدون set -e: هر خطای مهم صریح چک می‌شود تا رفتار در همه مسیرها معلوم باشد.

# ===== رنگ‌ها =====
R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; B='\033[1;34m'; NC='\033[0m'
ok()  { echo -e "${G}✔${NC} $1"; }
fail() { echo -e "${R}✘${NC} $1"; }
warn() { echo -e "${Y}⚠️${NC} $1"; }
step() { echo -e "\n${B}━━━ $1 ━━━${NC}"; }

# ===== تعیین ROOT_DIR =====
# حالت ۱: bash tools/update.sh  → BASH_SOURCE[0] دقیق مسیر رو می‌ده
# حالت ۲: ssh root@server 'bash -s' < tools/update.sh  → BASH_SOURCE تهی هست
# حالت ۳: ./tools/update.sh   → BASH_SOURCE[0] = ./tools/update.sh
if [ -z "${BASH_SOURCE[0]:-}" ] || [ ! -f "${BASH_SOURCE[0]}" ]; then
    # از stdin خوانده شده - از مسیر فعلی استفاده کن
    # باید از داخل پوشه پروژه اجرا شده باشد
    ROOT_DIR="$(pwd)"
    warn "Running from stdin - using PWD as ROOT_DIR: $ROOT_DIR"
else
    ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT_DIR" || exit 1

# ===== مالک متفاوت ریپو (اجرای وب به‌جای همان کاربر گیت) =====
# وقتی www-data/nginx اسکریپت را اجرا می‌کند، git همهٔ کارها را با
# «detected dubious ownership» رد می‌کند و آپدیت از وسط می‌شکند.
# صریح کردن safe.directory با env، بدون دست‌زدن به ~/.gitconfig، همین‌جا حلش می‌کند.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0="safe.directory"
export GIT_CONFIG_VALUE_0="$ROOT_DIR"

# ===== ۰. پیش‌بینی =====
step "Step 0: Pre-flight checks..."

if [ ! -d .git ]; then
    fail "No .git directory! Run install.sh first."
    exit 1
fi

# قفل اجرای همزمان (کرون هفتگی + اجرای دستی نباید روی هم بیفتند)
mkdir -p "$ROOT_DIR/data" 2>/dev/null || true
if command -v flock >/dev/null 2>&1 && exec 9>"$ROOT_DIR/data/update.lock" 2>/dev/null; then
    if ! flock -n 9 2>/dev/null; then
        fail "Another update is already running - exiting."
        exit 1
    fi
else
    warn "File lock unavailable - concurrent updates are not guarded on this server"
fi

if [ "$FORCE" -eq 0 ]; then
    if ! curl -s --max-time 5 https://github.com >/dev/null 2>&1; then
        warn "Cannot reach GitHub - update will likely fail"
    fi
fi

DISK_AVAIL=$(df "$ROOT_DIR" 2>/dev/null | awk 'NR==2{print $4}')
if [ -n "$DISK_AVAIL" ] && [ "$DISK_AVAIL" -lt 51200 ] 2>/dev/null; then
    fail "Low disk space ($DISK_AVAIL KB available) - need at least 50MB"
    exit 1
fi

# درخت detached یعنی کسی عمداً روی کامیت خاصی است؛ حدس زدن branch و
# pull کردن روی آن می‌تواند وضعیت عمدی را خراب کند - abort صریح.
if ! git symbolic-ref -q HEAD >/dev/null 2>&1; then
    fail "Detached HEAD - checkout a branch first (git checkout main)."
    exit 1
fi
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")

# ===== ۱. بک‌آپ config files =====
step "Step 1: Backing up configuration files..."
BACKUP_DIR="/tmp/botsaz-config-backup-$(date +%Y%m%d%H%M%S)"
mkdir -p "$BACKUP_DIR"

# nullglob: اگر پوشه bots خالی است، الگو به‌جای رشته خام، هیچی نشود
shopt -s nullglob

if [ -f "$ROOT_DIR/config.php" ]; then
    cp -a "$ROOT_DIR/config.php" "$BACKUP_DIR/config.php" 2>/dev/null && ok "Backed up: config.php" || warn "Could not backup config.php"
fi

for cf in "$ROOT_DIR"/bots/*/config.php; do
    slug="$(basename "$(dirname "$cf")")"
    mkdir -p "$BACKUP_DIR/bots/$slug" 2>/dev/null
    cp -a "$cf" "$BACKUP_DIR/bots/$slug/config.php" 2>/dev/null && ok "Backed up: bots/$slug/config.php" || warn "Could not backup bots/$slug/config.php"
done

shopt -u nullglob

# ===== ۲. git fetch + merge --ff-only =====
step "Step 2: Fetching from GitHub..."
if ! git fetch origin "$CURRENT_BRANCH" 2>/dev/null; then
    fail "Could not fetch origin/$CURRENT_BRANCH - check network and remote."
    echo "  Backup of your configs is at: $BACKUP_DIR (kept)"
    exit 1
fi

if [ "$DRY_RUN" -eq 1 ]; then
    ok "DRY RUN mode - no changes will be made"
    echo "  Branch: $CURRENT_BRANCH"
    echo "  Incoming commits (HEAD..origin/$CURRENT_BRANCH):"
    git log --oneline "HEAD..origin/$CURRENT_BRANCH" 2>/dev/null || echo "  (none - already up to date)"
    echo "  Local tracked modifications:"
    git status --porcelain --untracked-files=no 2>/dev/null || true
    rm -rf "$BACKUP_DIR" 2>/dev/null || true
    exit 0
fi

# درخت کثیف = کسی روی سرور دستی چیزی عوض کرده (هات‌فیکس). merge یا
# stash در این حالت یا هات‌فیکس را می‌پراند یا درگیری نصفه می‌گذارد و
# اسکریپت روی درخت خراب به ری‌استارت سرویس می‌رسد. پس صریح abort.
if [ -n "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]; then
    fail "Local tracked files were modified on this server - refusing to merge."
    git status --short --untracked-files=no 2>/dev/null || true
    echo "  Review them, then commit, revert (git checkout -- <file>),"
    echo "  or move them aside and re-run this updater."
    echo "  Backup of your configs is at: $BACKUP_DIR (kept)"
    exit 1
fi

step "Step 2b: Merging (fast-forward only)..."
if ! git merge --ff-only "origin/$CURRENT_BRANCH" 2>/dev/null; then
    fail "Cannot fast-forward (local branch diverged - e.g. a server-side commit)."
    echo "  Resolve by hand: cd $ROOT_DIR && git log --oneline -5 && git rebase origin/$CURRENT_BRANCH"
    echo "  Backup of your configs is at: $BACKUP_DIR (kept)"
    exit 1
fi

NEW_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
if [ "$CURRENT_COMMIT" = "$NEW_COMMIT" ]; then
    ok "Already up to date ($CURRENT_COMMIT)"
else
    ok "Updated: $CURRENT_COMMIT -> $NEW_COMMIT"
fi

# ===== ۳. بازرسی config files =====
step "Step 3: Verifying config files are intact..."
# config.php در .gitignore است پس pull نباید به آن دست بزند، ولی اگر زمانی
# track شده باشد (یا دستی وسط اجرا عوض شده باشد) اینجا برمی‌گردد.
restore_one() { # $1 = relative path (no `local`: called at top level)
    _r_rel="$1"; _r_bf="$BACKUP_DIR/$1"; _r_cf="$ROOT_DIR/$1"
    if [ -f "$_r_bf" ]; then
        if [ ! -f "$_r_cf" ] || ! diff -q "$_r_bf" "$_r_cf" >/dev/null 2>&1; then
            mkdir -p "$(dirname "$_r_cf")"
            if cp -a "$_r_bf" "$_r_cf"; then
                ok "$_r_rel restored from backup (overwrite prevented)"
            else
                fail "Failed to restore $_r_rel"
            fi
        else
            ok "$_r_rel unchanged ✓"
        fi
    fi
    unset _r_rel _r_bf _r_cf
}
restore_one "config.php"
shopt -s nullglob
for d in "$ROOT_DIR"/bots/*/; do
    [ -d "$d" ] || continue
    restore_one "bots/$(basename "$d")/config.php"
done
shopt -u nullglob

# ===== ۴. بازسازی .htaccess گمشده (لیست از خود مخزن) =====
step "Step 4: Checking .htaccess files..."
HTACCESS_RESTORED=0
while IFS= read -r -d '' f; do
    if [ ! -f "$ROOT_DIR/$f" ]; then
        # اول ثابت کن فایل در HEAD هست؛ وگرنه redirect یک فایل خالی می‌سازد!
        if git cat-file -e "HEAD:$f" 2>/dev/null && git show "HEAD:$f" > "$ROOT_DIR/$f" 2>/dev/null; then
            ok "Restored $f from HEAD"
            HTACCESS_RESTORED=$((HTACCESS_RESTORED + 1))
        else
            warn "$f is missing and not in git history - left alone"
        fi
    fi
done < <(git ls-files -z '*.htaccess' 2>/dev/null || true)
if [ "$HTACCESS_RESTORED" -gt 0 ]; then
    ok "$HTACCESS_RESTORED .htaccess file(s) restored"
else
    ok "All .htaccess files present ✓"
fi

# ===== ۴b. اجرای install.php (مایگریشن‌ها + پوشه‌ها؛ config را بازنویسی نمی‌کند) =====
step "Step 4b: Running installer bootstrap (migrations)..."
if [ -f "$ROOT_DIR/tools/install.php" ]; then
    php "$ROOT_DIR/tools/install.php" 2>/dev/null || warn "install.php had issues - check manually"
else
    warn "install.php not found"
fi

# ===== ۴c. گیت نسخه PHP (کد جدید ممکن است 8.2+ بخواهد) =====
if ! php -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' 2>/dev/null; then
    warn "PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null) is older than 8.2 - new code may fatal."
    warn "Upgrade PHP before relying on the updated bots."
fi

# ===== ۵. فیکس /root permissions =====
step "Step 5: Checking /root permissions..."
case "$ROOT_DIR" in
    /root|/root/*)
        # stat قابل‌حمل (GNU و BSD)
        if stat -c '%a' /root >/dev/null 2>&1; then
            ROOT_MODE=$(stat -c '%a' /root)
        elif stat -f '%Lp' /root >/dev/null 2>&1; then
            ROOT_MODE=$(stat -f '%Lp' /root)
        else
            ROOT_MODE="?"
        fi
        # ورود فقط x می‌خواهد (رقم یکان فرد): 701 هم معتبر است و نباید
        # حالت 700 عمدی سرورهای hardened را بی‌اجازه شل کرد.
        case "$ROOT_MODE" in
            *[1357]) ok "/root mode is $ROOT_MODE ✓ (traversable)" ;;
            *)
                warn "/root is mode $ROOT_MODE - fixing to 711..."
                chmod 711 /root 2>/dev/null && ok "/root fixed to 711" || fail "Could not fix /root (need root/sudo)"
                ;;
        esac
        ;;
    *)
        ok "Project is outside /root - nothing to fix"
        ;;
esac

# ===== ۶. ریلود vhost =====
step "Step 6: Reloading web server..."

if [ "$WEB" -eq 1 ]; then
    ok "Web mode (--web): vhost reload skipped - آپدیت از داخل ربات نمی‌تواند وب‌سرور را ریلود کند"
elif [ -f /etc/apache2/sites-available/botsaz.conf ]; then
    if [ "$NO_RESTART" -eq 0 ] && systemctl is-active --quiet apache2 2>/dev/null; then
        systemctl reload apache2 2>/dev/null && ok "Apache vhost reloaded" || warn "Could not reload Apache"
    else
        ok "Apache vhost exists (reload skipped)"
    fi
fi

if [ -f /etc/nginx/sites-available/botsaz.conf ]; then
    NGX_T="$(nginx -t 2>&1 || true)"
    if echo "$NGX_T" | grep -q "successful"; then
        if [ "$NO_RESTART" -eq 0 ] && systemctl is-active --quiet nginx 2>/dev/null; then
            systemctl reload nginx 2>/dev/null && ok "nginx vhost reloaded" || warn "Could not reload nginx"
        else
            systemctl start nginx 2>/dev/null && ok "nginx started" || warn "Could not start nginx"
        fi
    else
        warn "nginx config test failed - vhost may need rewriting:"
        echo "$NGX_T" | head -n 5
        echo "  Fix with: bash tools/install.sh (it rewrites the vhost with backup)"
    fi
fi

# هر دو وب‌سرور هم‌زمان روی ۸۰/۴۴۳ = تداخل حتمی
if [ -f /etc/apache2/sites-available/botsaz.conf ] && [ -f /etc/nginx/sites-available/botsaz.conf ]; then
    if systemctl is-active --quiet apache2 2>/dev/null && systemctl is-active --quiet nginx 2>/dev/null; then
        warn "Both Apache and nginx are running on port 80/443!"
        warn "Disable one of them to avoid conflicts"
    fi
fi

if [ ! -f /etc/apache2/sites-available/botsaz.conf ] && [ ! -f /etc/nginx/sites-available/botsaz.conf ]; then
    ok "No vhost found - may need to run install.sh first"
fi

# ===== ۷. فیکس systemd sandbox =====
step "Step 7: Fixing systemd sandbox..."
if [ "$WEB" -eq 1 ]; then
    ok "Web mode: systemd sandbox check skipped (نیاز به ری‌استارت یونیت دارد)"
elif command -v systemctl >/dev/null 2>&1; then
    # یونیت‌های واقعی (نه لیست هاردکد که نسخه php-fpm را جا می‌اندازد)
    _units=""
    for u in apache2 httpd nginx; do
        if systemctl show "$u" -p LoadState --value 2>/dev/null | grep -qx loaded; then
            _units="$_units $u"
        fi
    done
    for u in $(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
        u="${u%.service}"
        if systemctl show "$u" -p LoadState --value 2>/dev/null | grep -qx loaded; then
            _units="$_units $u"
        fi
    done
    for unit in $_units; do
        # هر دو پراپرتی می‌توانند /root را مخفی کنند؛ چک فقط InaccessiblePaths
        # حالت ProtectHome=yes را جا می‌انداخت و «OK» دروغ می‌گفت.
        _ph="$(systemctl show "$unit" -p ProtectHome --value 2>/dev/null || echo "")"
        _inacc="$(systemctl show "$unit" -p InaccessiblePaths --value 2>/dev/null || echo "")"
        _blocked=0
        case "$_ph" in yes|true|1|on) _blocked=1 ;; esac
        case "$_inacc" in */root*) _blocked=1 ;; esac
        if [ "$_blocked" = "1" ]; then
            case "$ROOT_DIR" in
                /root|/root/*|/home|/home/*)
                    warn "$unit hides the project (ProtectHome=$_ph InaccessiblePaths=$_inacc) - fixing..."
                    mkdir -p "/etc/systemd/system/${unit}.service.d" 2>/dev/null
                    printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' > "/etc/systemd/system/${unit}.service.d/botsaz.conf" 2>/dev/null
                    ;;
                *)
                    ok "$unit sandbox set but project is outside /root|/home - no action"
                    ;;
            esac
        else
            ok "$unit sandbox OK ✓"
        fi
    done
    unset _units unit _ph _inacc _blocked
    systemctl daemon-reload 2>/dev/null || true
else
    warn "systemctl not found - skipping sandbox check"
fi

# ===== ۸. فیکس PCRE JIT =====
step "Step 8: Checking PCRE JIT..."
PHP_INIS=""
CLI_INI=$(php -r 'echo php_ini_loaded_file();' 2>/dev/null || echo "")
[ -n "$CLI_INI" ] && PHP_INIS="$PHP_INIS $CLI_INI"
for f in /etc/php/*/cli/php.ini /etc/php/*/fpm/php.ini /etc/php/*/apache2/php.ini; do
    [ -f "$f" ] && PHP_INIS="$PHP_INIS $f"
done

PCRE_FIXED=0
for f in $PHP_INIS; do
    [ -f "$f" ] || continue
    # هر دو املا ('pcre.jit=1' و 'pcre.jit = 1') را می‌گیرد تا رکورد تکراری نسازد
    if grep -Eq '^[[:space:]]*pcre\.jit[[:space:]]*=' "$f" 2>/dev/null; then
        if ! grep -Eq '^[[:space:]]*pcre\.jit[[:space:]]*=[[:space:]]*0' "$f" 2>/dev/null; then
            sed -i -E 's/^[[:space:]]*pcre\.jit[[:space:]]*=.*/pcre.jit=0/' "$f"
            ok "pcre.jit=0 set in $f"
            PCRE_FIXED=$((PCRE_FIXED + 1))
        fi
    else
        echo 'pcre.jit=0' >> "$f"
        ok "pcre.jit=0 set in $f"
        PCRE_FIXED=$((PCRE_FIXED + 1))
    fi
done
if [ "$PCRE_FIXED" -eq 0 ]; then
    ok "PCRE JIT already configured everywhere ✓"
fi

# ===== ۹. ریستارت سرویس‌ها (فقط یک دور) =====
step "Step 9: Restarting services..."
RESTARTED=0
if [ "$NO_RESTART" -eq 1 ]; then
    ok "Skipping service restart (NO_RESTART=1)"
else
    for svc in apache2 httpd nginx; do
        if systemctl is-active --quiet "$svc" 2>/dev/null; then
            if systemctl restart "$svc" 2>/dev/null || service "$svc" restart 2>/dev/null; then
                ok "$svc restarted"
                RESTARTED=$((RESTARTED + 1))
            else
                warn "Could not restart $svc"
            fi
        fi
    done
    for svc in $(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
        if systemctl is-active --quiet "$svc" 2>/dev/null; then
            if systemctl restart "$svc" 2>/dev/null; then
                ok "$svc restarted"
                RESTARTED=$((RESTARTED + 1))
            else
                warn "Could not restart $svc"
            fi
        fi
    done
    if [ "$RESTARTED" -eq 0 ]; then
        warn "No services were running - start them manually"
    fi
fi

# ===== ۹b. warmup و re-confirm وبهوک (فقط اگر ری‌استارت شد) =====
# ری‌استارت اتصال‌های باز تلگرام را می‌اندازد؛ بدون re-confirm روی سرور
# زنده، تحویل‌ها با backoff عقب می‌افتند و ربات «مرده» به نظر می‌رسد.
if [ "$NO_RESTART" -eq 0 ] && [ "$RESTARTED" -gt 0 ] && [ -f "$ROOT_DIR/config.php" ]; then
    step "Step 9b: Warming up and re-confirming webhook..."
    _base=$(php -r '$c=@include "config.php"; echo isset($c["base_url"])?rtrim($c["base_url"],"/"):"";' 2>/dev/null || true)
    if [ -n "$_base" ]; then
        _code="$(curl -s -o /dev/null -w '%{http_code}' -X POST --max-time 15 "$_base/bot.php" 2>/dev/null || echo 0)"
        # 403 هم یعنی زنده است (رد secret) - فقط مسیر و PHP مهم‌اند
        if [ "$_code" = "200" ] || [ "$_code" = "403" ]; then
            ok "bot.php answers HTTP $_code"
            php "$ROOT_DIR/tools/set_webhook.php" "$_base/bot.php" >/dev/null 2>&1 \
                && ok "Webhook re-confirmed" \
                || warn "Webhook re-confirm failed - run: php tools/set_webhook.php"
        else
            warn "bot.php answers HTTP $_code right after restart - check the vhost"
            # 0000 یعنی اصلاً وصل نشد: یا base_url اشتباه/placeholder است،
            # یا vhost فعالِ دیگری از یک نصبِ قدیمی سرو می‌دهد (وب‌سرور
            # پیام‌ها را به /var/www/botsaz-faxima قدیمی می‌فرستد).
            [ "$_code" = "0000" ] && warn "HTTP 0000: یا base_url در config.php placeholder است، یا یک vhost قدیمی (DocumentRoot غیر از $ROOT_DIR) آپاچی را می‌چرخاند - با `apache2ctl -S` چک کن"
        fi
    fi
    unset _base _code
fi

# ===== ۱۰. تست سلامت =====
step "Step 10: Running health check..."
if [ -f "$ROOT_DIR/tools/install.sh" ]; then
    bash "$ROOT_DIR/tools/install.sh" --check 2>/dev/null || warn "Health check had issues (see above)"
else
    warn "install.sh not found - run it manually"
fi

# ===== ۱۱. تأیید وبهوک =====
step "Step 11: Verifying webhook..."
if [ -f "$ROOT_DIR/config.php" ]; then
    # فایل موقت تا مشکل escaping شل دور زده شود
    WEBHOOK_PHP=$(mktemp /tmp/botsaz-webhook-XXXXXX.php)
    cat > "$WEBHOOK_PHP" << 'PHPEOF'
<?php
$config = require '/ROOT_DIR_PLACEHOLDER/config.php';
if (!isset($config['main_token']) || empty($config['main_token'])) {
    echo 'NO_TOKEN'; exit;
}
if (!function_exists('curl_init')) { echo 'NO_CURL'; exit; }
$url = 'https://api.telegram.org/bot' . $config['main_token'] . '/getWebhookInfo';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$raw = curl_exec($ch);
curl_close($ch);
$r = is_string($raw) ? json_decode($raw, true) : null;
echo (is_array($r) && isset($r['result']['url']) && $r['result']['url'] !== '') ? 'OK:' . $r['result']['url'] : 'NOT_SET';
?>
PHPEOF
    sed -i "s|/ROOT_DIR_PLACEHOLDER|$(echo "$ROOT_DIR" | sed 's|/|\\\\/|g')|g" "$WEBHOOK_PHP"

    WEBHOOK_STATUS=$(php "$WEBHOOK_PHP" 2>/dev/null || echo "ERROR")
    rm -f "$WEBHOOK_PHP"
    echo "  Webhook: $WEBHOOK_STATUS"
fi

# ===== ۱۲. نوتیفیکیشن تلگرام به ادمین =====
if [ -f "$ROOT_DIR/config.php" ] && [ "$DRY_RUN" -eq 0 ]; then
    NOTIFY_PHP=$(mktemp /tmp/botsaz-notify-XXXXXX.php)
    cat > "$NOTIFY_PHP" << 'PHPEOF'
<?php
$config = require '/ROOT_DIR_PLACEHOLDER/config.php';
if (empty($config['main_token'])) exit;
if (empty($config['super_admins'])) exit;
$admin = is_array($config['super_admins']) ? $config['super_admins'][0] : $config['super_admins'];
$new_commit = exec('git rev-parse HEAD 2>/dev/null');
$new_branch = exec('git rev-parse --abbrev-ref HEAD 2>/dev/null');
$msg = urlencode("Botsaz-Faxima Update\nBranch: " . ($new_branch ?: '?') . "\nCommit: " . substr((string)$new_commit, 0, 8) . "\nStatus: Success");
$url = 'https://api.telegram.org/bot' . $config['main_token'] . '/sendMessage';
$ch = curl_init($url . '?chat_id=' . $admin . '&text=' . $msg);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_exec($ch);
curl_close($ch);
?>
PHPEOF
    sed -i "s|/ROOT_DIR_PLACEHOLDER|$(echo "$ROOT_DIR" | sed 's|/|\\\\/|g')|g" "$NOTIFY_PHP"
    php "$NOTIFY_PHP" 2>/dev/null && ok "Update notification sent to admin" || warn "Could not send notification"
    rm -f "$NOTIFY_PHP"
fi

# ===== Cleanup: فقط ۳ بکاپ آخر می‌ماند (rollback همیشه ممکن) =====
ls -dt /tmp/botsaz-config-backup-* 2>/dev/null | tail -n +4 | xargs -r rm -rf 2>/dev/null || true

echo ""
echo "========================================="
echo "  ✅ Update complete!"
echo "  Config files preserved: YES"
echo "  Last backups kept in: /tmp/botsaz-config-backup-* (newest 3)"
echo "========================================="
echo ""
if [ "$WEB" -eq 1 ]; then
    echo "  NOTE (--web mode): services were NOT restarted, so the running PHP"
    echo "  process still uses the OLD code. Restart manually (or let the weekly"
    echo "  cron run update.sh) to load the new code:"
    echo "    systemctl restart apache2  (or nginx + php-fpm)"
    echo ""
fi
echo "  NOTE: child bots run COPIES of the templates from build time -"
echo "  their code was NOT updated by this script. Rebuild a child bot"
echo "  (or patch its folder by hand) if the update fixed template code."
echo ""
echo "  If any [✘] appeared above:"
echo "    bash tools/install.sh --check  (detailed check)"
echo "    bash tools/install.sh --logs   (error logs)"
echo ""
echo "  To undo this update:"
echo "    cd $ROOT_DIR && git reset --hard $CURRENT_COMMIT"
echo "    git clean -fd"
echo ""
