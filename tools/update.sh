#!/bin/bash
# ===== بروزرسانی ربات‌ساز از گیت‌هاب =====
# استفاده: bash tools/update.sh
# یا: ssh root@server 'bash -s' < tools/update.sh
#
# مراحل:
#   ۱. git pull از origin/main
#   ۲. بازسازی .htaccess اگر حذف شدن
#   ۳. فیکس /root permissions
#   ۴. آپدیت nginx/Apache vhost
#   ۵. فیکس systemd sandbox
#   ۶. فیکس PCRE JIT
#   ۷. ریستارت سرویس‌ها
#   ۸. تست سلامت

set -e

echo "========================================="
echo "  🔄 Botsaz-Faxima Updater"
echo "========================================="
echo ""

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# ===== رنگ‌ها =====
R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; NC='\033[0m'
ok()  { echo -e "${G}✔${NC} $1"; }
fail() { echo -e "${R}✘${NC} $1"; }
warn() { echo -e "${Y}⚠️${NC} $1"; }
step() { echo -e "\n${Y}━━━ $1 ━━━${NC}"; }

# ===== ۱. git pull =====
step "Step 1: Git pull from GitHub..."
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")

if [ -d .git ]; then
    git fetch origin 2>/dev/null || warn "Could not fetch from origin"
    git pull origin "$CURRENT_BRANCH" 2>/dev/null || {
        fail "git pull failed!"
        echo "  Try manually: cd $ROOT_DIR && git pull origin $CURRENT_BRANCH"
        exit 1
    }
    NEW_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
    if [ "$CURRENT_COMMIT" = "$NEW_COMMIT" ]; then
        ok "Already up to date ($CURRENT_COMMIT)"
    else
        ok "Updated: $CURRENT_COMMIT -> $NEW_COMMIT"
    fi
else
    fail "No .git directory! Run install.sh first."
    exit 1
fi

# ===== ۲. Restore .htaccess files =====
step "Step 2: Checking .htaccess files..."
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
        # Try to restore from git
        if git show HEAD:"$f" > "$ROOT_DIR/$f" 2>/dev/null; then
            ok "Restored $f"
            HTACCESS_RESTORED=$((HTACCESS_RESTORED + 1))
        elif git show HEAD~1:"$f" > "$ROOT_DIR/$f" 2>/dev/null; then
            ok "Restored $f from previous commit"
            HTACCESS_RESTORED=$((HTACCESS_RESTORED + 1))
        else
            warn "Could not restore $f - may need manual action"
        fi
    fi
done
if [ "$HTACCESS_RESTORED" -gt 0 ]; then
    ok "$HTACCESS_RESTORED .htaccess file(s) restored"
else
    ok "All .htaccess files present"
fi

# ===== ۳. Fix /root permissions =====
step "Step 3: Checking /root permissions..."
ROOT_MODE=$(stat -c '%a' /root 2>/dev/null || echo "?")
if [ "$ROOT_MODE" != "711" ] && [ "$ROOT_MODE" != "755" ]; then
    warn "/root is mode $ROOT_MODE - fixing to 711..."
    chmod 711 /root 2>/dev/null && ok "/root fixed to 711" || fail "Could not fix /root"
else
    ok "/root mode is $ROOT_MODE"
fi

# ===== ۴. Update vhost configs =====
step "Step 4: Updating web server vhost..."
if [ -f /etc/apache2/sites-available/botsaz.conf ] || [ -f /etc/nginx/sites-available/botsaz.conf ]; then
    # Re-run vhost configuration from install.sh
    # This will detect if Apache or nginx is installed and update accordingly
    echo "  Running vhost update..."
    
    # Update Apache vhost if exists
    if [ -f /etc/apache2/sites-available/botsaz.conf ]; then
        echo "  Apache vhost exists - checking..."
        # The vhost should already have the correct DocumentRoot
        # Just verify and reload
        if systemctl is-active --quiet apache2 2>/dev/null; then
            systemctl reload apache2 2>/dev/null && ok "Apache vhost reloaded" || warn "Could not reload Apache"
        fi
    fi
    
    # Update nginx vhost if exists
    if [ -f /etc/nginx/sites-available/botsaz.conf ]; then
        echo "  nginx vhost exists - checking..."
        # Test nginx config
        if nginx -t 2>/dev/null; then
            if systemctl is-active --quiet nginx 2>/dev/null; then
                systemctl reload nginx 2>/dev/null && ok "nginx vhost reloaded" || warn "Could not reload nginx"
            else
                systemctl start nginx 2>/dev/null && ok "nginx started" || warn "Could not start nginx"
            fi
        else
            warn "nginx config test failed - vhost may need rewriting"
        fi
    fi
else
    ok "No vhost found - may need to run install.sh first"
fi

# ===== ۵. Fix systemd sandbox =====
step "Step 5: Fixing systemd sandbox..."
# Check if /root is hidden by systemd
for unit in apache2 nginx php8.2-fpm php8.1-fpm; do
    if systemctl is-active --quiet "$unit" 2>/dev/null; then
        # Check if InaccessiblePaths includes /root
        SANDBOX=$(systemctl show "$unit" -p InaccessiblePaths --value 2>/dev/null || echo "")
        if echo "$SANDBOX" | grep -q "/root"; then
            warn "$unit has /root in InaccessiblePaths - fixing..."
            mkdir -p "/etc/systemd/system/${unit}.service.d" 2>/dev/null
            printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' > "/etc/systemd/system/${unit}.service.d/botsaz.conf" 2>/dev/null
            systemctl daemon-reload 2>/dev/null
            systemctl restart "$unit" 2>/dev/null && ok "$unit sandbox fixed" || warn "Could not restart $unit"
        else
            ok "$unit sandbox OK"
        fi
    fi
done

# ===== ۶. Fix PCRE JIT =====
step "Step 6: Checking PCRE JIT..."
PHP_INIS=$(php -r 'echo php_ini_loaded_file();' 2>/dev/null || echo "")
# Also check nginx PHP-FPM ini
for f in /etc/php/*/fpm/php.ini /etc/php/*/apache2/php.ini; do
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
    ok "PCRE JIT already configured everywhere"
fi

# ===== ۷. Restart services =====
step "Step 7: Restarting services..."
RESTARTED=0
for svc in apache2 nginx php8.2-fpm php8.1-fpm; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then
        systemctl restart "$svc" 2>/dev/null && ok "$svc restarted" || warn "Could not restart $svc"
        RESTARTED=$((RESTARTED + 1))
    fi
done
if [ "$RESTARTED" -eq 0 ]; then
    warn "No services were running - start them manually"
fi

# ===== ۸. Run health check =====
step "Step 8: Running health check..."
if [ -f "$ROOT_DIR/tools/install.sh" ]; then
    bash "$ROOT_DIR/tools/install.sh --check" 2>/dev/null || warn "Health check had issues"
else
    warn "install.sh not found - run it manually"
fi

# ===== ۹. Verify webhook =====
step "Step 9: Verifying webhook..."
if [ -f "$ROOT_DIR/config.php" ]; then
    WEBHOOK_STATUS=$(php -r "
        \$c = require '$ROOT_DIR/config.php';
        if (!isset(\$c['main_token']) || empty(\$c['main_token'])) { echo 'NO_TOKEN'; exit; }
        \$url = 'https://api.telegram.org/bot' . \$c['main_token'] . '/getWebhookInfo';
        \$ch = curl_init(\$url);
        curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(\$ch, CURLOPT_TIMEOUT, 10);
        \$r = json_decode(curl_exec(\$ch), true);
        curl_close(\$ch);
        echo isset(\$r['result']['url']) ? 'OK:' . \$r['result']['url'] : 'NOT_SET';
    " 2>/dev/null || echo "ERROR")
    echo "  Webhook: $WEBHOOK_STATUS"
fi

echo ""
echo "========================================="
echo "  ✅ Update complete!"
echo "========================================="
echo ""
echo "  If any [FAIL] appeared above:"
echo "    bash tools/install.sh --check  (detailed check)"
echo "    bash tools/install.sh --logs   (error logs)"
echo ""
echo "  If the bot still doesn't work:"
echo "    ssh root@server"
echo "    cd /root/botsaz-faxima"
echo "    bash tools/install.sh"
echo ""
