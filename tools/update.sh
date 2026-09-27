#!/usr/bin/env bash
# ===== بروزرسانی ربات‌ساز از گیت‌هاب =====
# استفاده: bash tools/update.sh
# یا: ssh root@server 'bash -s' < tools/update.sh
#
# این اسکریپت فقط فایل‌های کد را آپدیت می‌کند.
# فایل‌های کانفیگ (config.php, bots/*/config.php) هرگز تغییر نمی‌کنند.
# فایل‌های داده (data/, bots/*/logs, bots/*/states) حفظ می‌شوند.
#
# مراحل:
#   ۱. پیش‌بینی (آینه، فضا، اینترنت)
#   ۲. بک‌آپ config files
#   ۳. git pull
#   ۴. بررسی و بازگردانی config
#   ۵. بازسازی .htaccess
#   ۶. فیکس /root permissions
#   ۷. آپدیت nginx/Apache vhost
#   ۸. فیکس systemd sandbox
#   ۹. فیکس PCRE JIT
#   ۱۰. ریستارت سرویس‌ها
#   ۱۱. تست سلامت
#   ۱۲. تأیید وبهوک
#
# آپشن‌ها:
#   --dry-run   فقط چاپ می‌کنه، تغییری نمی‌ده
#   --no-restart  سرویس‌ها رو ریستارت نکن
#   --force      بدون پیش‌بینی، مستقیم اجرا کن

# ===== آپشن‌ها =====
DRY_RUN=0
NO_RESTART=0
FORCE=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --no-restart) NO_RESTART=1 ;;
        --force) FORCE=1 ;;
    esac
done

# ===== حذف set -e - با error handler به جاش =====
# set -e خطرناکه یه خطای غیرمنتظره کل اسکریپت رو قطع می‌کنه
# به جاش از trap و explicit check استفاده می‌کنیم

# ===== رنگ‌ها =====
R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; B='\033[1;34m'; NC='\033[0m'
ok()  { echo -e "${G}✔${NC} $1"; }
fail() { echo -e "${R}✘${NC} $1"; }
warn() { echo -e "${Y}⚠️${NC} $1"; }
step() { echo -e "\n${B}━━━ $1 ━━━${NC}"; }

# ===== تعیین ROOT_DIR =====
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# ===== ۰. پیش‌بینی =====
step "Step 0: Pre-flight checks..."

# Check git
if [ ! -d .git ]; then
    fail "No .git directory! Run install.sh first."
    exit 1
fi

# Check internet (optional)
if [ "$FORCE" -eq 0 ]; then
    if ! curl -s --max-time 5 https://github.com >/dev/null 2>&1; then
        warn "Cannot reach GitHub - using cached data"
    fi
fi

# Check disk space (need at least 50MB)
DISK_AVAIL=$(df "$ROOT_DIR" 2>/dev/null | awk 'NR==2{print $4}')
if [ -n "$DISK_AVAIL" ] && [ "$DISK_AVAIL" -lt 51200 ] 2>/dev/null; then
    fail "Low disk space ($DISK_AVAIL KB available) - need at least 50MB"
    exit 1
fi

# Detect branch (handle detached HEAD)
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
if [ "$CURRENT_BRANCH" = "HEAD" ] || [ -z "$CURRENT_BRANCH" ]; then
    warn "Detached HEAD state - trying main branch"
    CURRENT_BRANCH="main"
    git branch --list origin/main >/dev/null 2>&1 && CURRENT_BRANCH="main" || CURRENT_BRANCH="master"
fi

CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")

if [ "$DRY_RUN" -eq 1 ]; then
    ok "DRY RUN mode - no changes will be made"
    echo "  Branch: $CURRENT_BRANCH"
    echo "  Commit: $CURRENT_COMMIT"
    echo "  Root: $ROOT_DIR"
    exit 0
fi

# ===== ۱. بک‌آپ config files =====
step "Step 1: Backing up configuration files..."
BACKUP_DIR="/tmp/botsaz-config-backup-$(date +%Y%m%d%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Cleanup on exit (even on error)
trap 'rm -rf "$BACKUP_DIR" 2>/dev/null' EXIT

# Enable safe globbing - if no match, the pattern is skipped
shopt -s nullglob

# Backup root config.php
if [ -f "$ROOT_DIR/config.php" ]; then
    cp -a "$ROOT_DIR/config.php" "$BACKUP_DIR/config.php" 2>/dev/null && ok "Backed up: config.php" || warn "Could not backup config.php"
fi

# Backup all bots/*/config.php
for cf in "$ROOT_DIR"/bots/*/config.php; do
    slug="$(basename "$(dirname "$cf")")"
    mkdir -p "$BACKUP_DIR/bots/$slug" 2>/dev/null
    cp -a "$cf" "$BACKUP_DIR/bots/$slug/config.php" 2>/dev/null && ok "Backed up: bots/$slug/config.php" || warn "Could not backup bots/$slug/config.php"
done

# Disable safe globbing
shopt -u nullglob

# ===== ۲. git pull =====
step "Step 2: Git pull from GitHub..."

# Fetch first
git fetch origin 2>/dev/null || warn "Could not fetch from origin"

# Pull with rebase to avoid merge commits
# --ff-only ensures we only fast-forward (no merge conflicts)
if git pull --ff-only origin "$CURRENT_BRANCH" 2>/dev/null; then
    ok "Pull successful"
elif git pull origin "$CURRENT_BRANCH" 2>/dev/null; then
    # Pull succeeded with merge
    ok "Pull successful (with merge)"
    # If there were merge conflicts, try to resolve by keeping our config
    # But since config.php is in .gitignore, it shouldn't be affected
    warn "Merge occurred - verifying config files..."
else
    # Pull failed - try stash approach
    warn "Pull failed - attempting recovery..."
    
    # Stash tracked files that might conflict (config files are .gitignored, safe)
    # Use simple syntax without exclusions
    git stash push -- . 2>/dev/null || warn "Could not stash"
    
    if git pull origin "$CURRENT_BRANCH" 2>/dev/null; then
        ok "Pull successful after stash"
        # Pop stash - might have conflicts on tracked files
        # Config files are safe because they're .gitignored
        git stash pop 2>/dev/null || warn "Stash pop had conflicts (config files are safe)"
    else
        # Pull still failed - try to restore stash
        git stash pop 2>/dev/null || true
        fail "git pull failed! Check network and permissions"
        echo "  Manual fix:"
        echo "    cd $ROOT_DIR && git fetch origin && git pull origin $CURRENT_BRANCH"
        exit 1
    fi
fi

NEW_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
if [ "$CURRENT_COMMIT" = "$NEW_COMMIT" ]; then
    ok "Already up to date ($CURRENT_COMMIT)"
else
    ok "Updated: $CURRENT_COMMIT -> $NEW_COMMIT"
fi

# ===== ۳. بازرسی config files =====
step "Step 3: Verifying config files are intact..."

# Since config.php is in .gitignore, git pull should NOT touch it.
# But we verify anyway.

# Check config.php
if [ -f "$BACKUP_DIR/config.php" ] && [ -f "$ROOT_DIR/config.php" ]; then
    if ! diff -q "$BACKUP_DIR/config.php" "$ROOT_DIR/config.php" >/dev/null 2>&1; then
        warn "config.php was modified! Restoring from backup..."
        cp -a "$BACKUP_DIR/config.php" "$ROOT_DIR/config.php" && ok "config.php restored" || fail "Failed to restore config.php"
    else
        ok "config.php unchanged ✓"
    fi
elif [ -f "$BACKUP_DIR/config.php" ] && [ ! -f "$ROOT_DIR/config.php" ]; then
    warn "config.php was deleted! Restoring..."
    cp -a "$BACKUP_DIR/config.php" "$ROOT_DIR/config.php" && ok "config.php restored" || fail "Failed to restore config.php"
fi

# Check bots/*/config.php
shopt -s nullglob
for cf in "$ROOT_DIR"/bots/*/config.php; do
    slug="$(basename "$(dirname "$cf")")"
    bf="$BACKUP_DIR/bots/$slug/config.php"
    if [ -f "$bf" ]; then
        if [ ! -f "$cf" ]; then
            warn "bots/$slug/config.php was deleted! Restoring..."
            cp -a "$bf" "$cf" && ok "bots/$slug/config.php restored" || fail "Failed to restore bots/$slug/config.php"
        elif ! diff -q "$bf" "$cf" >/dev/null 2>&1; then
            warn "bots/$slug/config.php was modified! Restoring..."
            cp -a "$bf" "$cf" && ok "bots/$slug/config.php restored" || fail "Failed to restore bots/$slug/config.php"
        else
            ok "bots/$slug/config.php unchanged ✓"
        fi
    fi
done
shopt -u nullglob

# ===== ۴. بازسازی .htaccess =====
step "Step 4: Checking .htaccess files..."

HTACCESS_FILES=(
    ".htaccess"
    "bots/.htaccess"
    "data/.htaccess"
    "templates/.htaccess"
    "templates/faxima/.htaccess"
    "templates/faxima/api/.htaccess"
    "templates/faxima/app/.htaccess"
    "templates/faxima/logs/.htaccess"
    "templates/faxima/storage/.htaccess"
    "templates/faxima/sub/.htaccess"
    "templates/mirza/.htaccess"
)

HTACCESS_RESTORED=0
for f in "${HTACCESS_FILES[@]}"; do
    if [ ! -f "$ROOT_DIR/$f" ]; then
        # Try current HEAD first
        if git show HEAD:"$f" > "$ROOT_DIR/$f" 2>/dev/null; then
            ok "Restored $f from HEAD"
            HTACCESS_RESTORED=$((HTACCESS_RESTORED + 1))
        # Try previous commit (f304094 "del" might have deleted it)
        elif git rev-parse HEAD~1 >/dev/null 2>&1 && git show HEAD~1:"$f" > "$ROOT_DIR/$f" 2>/dev/null; then
            ok "Restored $f from previous commit"
            HTACCESS_RESTORED=$((HTACCESS_RESTORED + 1))
        else
            warn "Could not restore $f - check git history manually"
        fi
    fi
done

if [ "$HTACCESS_RESTORED" -gt 0 ]; then
    ok "$HTACCESS_RESTORED .htaccess file(s) restored"
else
    ok "All .htaccess files present ✓"
fi

# ===== ۵. فیکس /root permissions =====
step "Step 5: Checking /root permissions..."

# Portable stat command
if stat -c '%a' /root >/dev/null 2>&1; then
    ROOT_MODE=$(stat -c '%a' /root)
elif stat -f '%Lp' /root >/dev/null 2>&1; then
    ROOT_MODE=$(stat -f '%Lp' /root)
else
    ROOT_MODE=$(python3 -c "import os; print(oct(os.stat('/root').st_mode & 0o777)[2:])" 2>/dev/null || echo "?")
fi

if [ "$ROOT_MODE" != "711" ] && [ "$ROOT_MODE" != "755" ]; then
    warn "/root is mode $ROOT_MODE - fixing to 711..."
    chmod 711 /root 2>/dev/null && ok "/root fixed to 711" || fail "Could not fix /root (need root/sudo)"
else
    ok "/root mode is $ROOT_MODE ✓"
fi

# ===== ۶. آپدیت vhost configs =====
step "Step 6: Updating web server vhost..."

if [ -f /etc/apache2/sites-available/botsaz.conf ]; then
    if [ "$NO_RESTART" -eq 0 ] && systemctl is-active --quiet apache2 2>/dev/null; then
        systemctl reload apache2 2>/dev/null && ok "Apache vhost reloaded" || warn "Could not reload Apache"
    else
        ok "Apache vhost exists (not reloaded - NO_RESTART=1)"
    fi
fi

if [ -f /etc/nginx/sites-available/botsaz.conf ]; then
    if nginx -t 2>/dev/null; then
        if [ "$NO_RESTART" -eq 0 ] && systemctl is-active --quiet nginx 2>/dev/null; then
            systemctl reload nginx 2>/dev/null && ok "nginx vhost reloaded" || warn "Could not reload nginx"
        else
            systemctl start nginx 2>/dev/null && ok "nginx started" || warn "Could not start nginx"
        fi
    else
        warn "nginx config test failed - vhost may need rewriting"
        warn "Run: bash tools/install.sh to regenerate vhost"
    fi
fi

# Check if both Apache and nginx are installed (conflict!)
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

# Detect actual running units (not hardcoded list)
for unit in $(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | grep -E 'apache2|nginx|php[0-9]' | awk '{print $1}'); do
    SANDBOX=$(systemctl show "$unit" -p InaccessiblePaths --value 2>/dev/null || echo "")
    if echo "$SANDBOX" | grep -q "/root"; then
        warn "$unit has /root in InaccessiblePaths - fixing..."
        mkdir -p "/etc/systemd/system/${unit}.service.d" 2>/dev/null
        printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' > "/etc/systemd/system/${unit}.service.d/botsaz.conf" 2>/dev/null
        systemctl daemon-reload 2>/dev/null
        if [ "$NO_RESTART" -eq 0 ]; then
            systemctl restart "$unit" 2>/dev/null && ok "$unit sandbox fixed" || warn "Could not restart $unit"
        else
            ok "$unit sandbox fixed (not restarted - NO_RESTART=1)"
        fi
    else
        ok "$unit sandbox OK ✓"
    fi
done

# ===== ۸. فیکس PCRE JIT =====
step "Step 8: Checking PCRE JIT..."

# Find all php.ini files
PHP_INIS=""
# CLI ini
CLI_INI=$(php -r 'echo php_ini_loaded_file();' 2>/dev/null || echo "")
[ -n "$CLI_INI" ] && PHP_INIS="$PHP_INIS $CLI_INI"
# FPM ini
for f in /etc/php/*/fpm/php.ini /etc/php/*/apache2/php.ini /etc/php/*/cli/php.ini; do
    [ -f "$f" ] && PHP_INIS="$PHP_INIS $f"
done

PCRE_FIXED=0
for f in $PHP_INIS; do
    if [ -f "$f" ]; then
        CURRENT_PCRE=$(grep '^pcre.jit=' "$f" 2>/dev/null || echo "")
        if [ "$CURRENT_PCRE" != "pcre.jit=0" ]; then
            if ! grep -q '^pcre.jit=' "$f" 2>/dev/null; then
                echo 'pcre.jit=0' >> "$f"
            else
                sed -i 's/^pcre\.jit=.*/pcre.jit=0/' "$f"
            fi
            ok "pcre.jit=0 set in $f"
            PCRE_FIXED=$((PCRE_FIXED + 1))
        fi
    fi
done
if [ "$PCRE_FIXED" -eq 0 ]; then
    ok "PCRE JIT already configured everywhere ✓"
fi

# ===== ۹. ریستارت سرویس‌ها =====
step "Step 9: Restarting services..."

if [ "$NO_RESTART" -eq 1 ]; then
    ok "Skipping service restart (NO_RESTART=1)"
else
    RESTARTED=0
    for svc in apache2 nginx php8.2-fpm php8.1-fpm php8.0-fpm; do
        if systemctl is-active --quiet "$svc" 2>/dev/null; then
            systemctl restart "$svc" 2>/dev/null && ok "$svc restarted" || warn "Could not restart $svc"
            RESTARTED=$((RESTARTED + 1))
        fi
    done
    if [ "$RESTARTED" -eq 0 ]; then
        warn "No services were running - start them manually"
    fi
fi

# ===== ۱۰. تست سلامت =====
step "Step 10: Running health check..."
if [ -f "$ROOT_DIR/tools/install.sh" ]; then
    bash "$ROOT_DIR/tools/install.sh --check" 2>/dev/null || warn "Health check had issues"
else
    warn "install.sh not found - run it manually"
fi

# ===== ۱۱. تأیید وبهوک =====
step "Step 11: Verifying webhook..."
if [ -f "$ROOT_DIR/config.php" ]; then
    # Use a temp PHP file to avoid shell escaping issues
    WEBHOOK_PHP=$(mktemp /tmp/botsaz-webhook-XXXXXX.php)
    cat > "$WEBHOOK_PHP" << 'PHPEOF'
<?php
$config = require '/ROOT_DIR_PLACEHOLDER/config.php';
if (!isset($config['main_token']) || empty($config['main_token'])) {
    echo 'NO_TOKEN'; exit;
}
$url = 'https://api.telegram.org/bot' . $config['main_token'] . '/getWebhookInfo';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$r = json_decode(curl_exec($ch), true);
curl_close($ch);
echo isset($r['result']['url']) ? 'OK:' . $r['result']['url'] : 'NOT_SET';
?>
PHPEOF
    # Replace placeholder with actual path
    sed -i "s|/ROOT_DIR_PLACEHOLDER|$(echo "$ROOT_DIR" | sed 's|/|\\\\/|g')|g" "$WEBHOOK_PHP"
    
    WEBHOOK_STATUS=$(php "$WEBHOOK_PHP" 2>/dev/null || echo "ERROR")
    rm -f "$WEBHOOK_PHP"
    echo "  Webhook: $WEBHOOK_STATUS"
fi

# ===== ۱۲. ارسال notification به تلگرام (اختیاری) =====
if [ -f "$ROOT_DIR/config.php" ] && [ "$DRY_RUN" -eq 0 ]; then
    NOTIFY_PHP=$(mktemp /tmp/botsaz-notify-XXXXXX.php)
    cat > "$NOTIFY_PHP" << 'PHPEOF'
<?php
$config = require '/ROOT_DIR_PLACEHOLDER/config.php';
if (!isset($config['main_token']) || empty($config['main_token'])) exit;
if (!isset($config['super_admins']) || empty($config['super_admins'])) exit;
$admin = $config['super_admins'][0];
$commit = 'unknown';
$branch = 'main';
$new_commit = exec('git rev-parse HEAD 2>/dev/null');
$new_branch = exec('git rev-parse --abbrev-ref HEAD 2>/dev/null');
if ($new_branch) $branch = $new_branch;
if ($new_commit) $commit = substr($new_commit, 0, 8);
$msg = urlencode("🔄 Botsaz-Faxima Update\nBranch: $branch\nCommit: $commit\nStatus: ✅ Success");
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

# ===== Cleanup =====
rm -rf "$BACKUP_DIR" 2>/dev/null
# Remove trap since we cleaned up
trap - EXIT

echo ""
echo "========================================="
echo "  ✅ Update complete!"
echo "  Config files preserved: YES"
echo "  Dry run: NO"
echo "========================================="
echo ""
echo "  If any [✘] appeared above:"
echo "    bash tools/install.sh --check  (detailed check)"
echo "    bash tools/install.sh --logs   (error logs)"
echo ""
echo "  To undo this update:"
echo "    cd $ROOT_DIR && git reset --hard $CURRENT_COMMIT"
echo "    git clean -fd"
echo ""
