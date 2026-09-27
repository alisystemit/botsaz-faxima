#!/bin/bash
# ============================================================
# FIX_SERVER.sh - همه مشکلات رو یکجا حل کن
# استفاده: ssh root@65.109.209.61 'bash -s' < FIX_SERVER.sh
# یا: cp FIX_SERVER.sh /root/FIX_SERVER.sh && bash /root/FIX_SERVER.sh
# ============================================================
set -e

echo "========================================="
echo "  🔧 Botsaz-Faxima - Complete Fix"
echo "========================================="
echo ""

# ---- 1. /root permissions ----
echo "[1/7] Fixing /root permissions..."
_current=$(stat -c '%a' /root)
chmod 711 /root
echo "   ✔ /root: $_current → $(stat -c '%a' /root)"

# ---- 2. data/ ownership ----
echo "[2/7] Fixing data/ ownership..."
if [ -d /root/botsaz-faxima/data ]; then
    chown -R www-data:www-data /root/botsaz-faxima/data
    echo "   ✔ data/ → www-data:www-data"
fi

# ---- 3. bots/ ownership ----
echo "[3/7] Fixing bots/ ownership..."
if [ -d /root/botsaz-faxima/bots ]; then
    chown www-data:www-data /root/botsaz-faxima/bots
    echo "   ✔ bots/ → www-data:www-data"
fi

# ---- 4. PCRE JIT fix ----
echo "[4/7] Fixing PCRE JIT..."
# Apache uses a different .ini than CLI - update BOTH
_pcre_fixed=0
for _ini in $(php -r 'echo php_ini_loaded_file();' 2>/dev/null) /etc/php/*/apache2/php.ini /etc/php/*/fpm/php.ini; do
    [ -z "$_ini" ] && continue
    [ ! -f "$_ini" ] && continue
    if ! grep -q '^pcre.jit=' "$_ini" 2>/dev/null; then
        echo 'pcre.jit=0' >> "$_ini"
        echo "   ✔ pcre.jit=0 added to $_ini"
        _pcre_fixed=1
    elif ! grep -q '^pcre.jit=0' "$_ini" 2>/dev/null; then
        sed -i "s/^pcre\.jit=.*/pcre.jit=0/" "$_ini"
        echo "   ✔ pcre.jit=0 set in $_ini"
        _pcre_fixed=1
    fi
done
[ "$_pcre_fixed" = "0" ] && echo "   ✔ pcre.jit=0 already set everywhere"

# ---- 5. Apache vhost check ----
echo "[5/7] Checking Apache vhost..."
if [ -f /etc/apache2/sites-available/botsaz.conf ]; then
    _docroot=$(grep -i 'DocumentRoot' /etc/apache2/sites-available/botsaz.conf | head -1)
    echo "   DocumentRoot: $_docroot"
    if echo "$_docroot" | grep -q '/var/www/html'; then
        echo "   ⚠️  Vhost points to /var/www/html - fixing..."
        sed -i 's#/var/www/html#/root/botsaz-faxima#g' /etc/apache2/sites-available/botsaz.conf
        echo "   ✔ Fixed DocumentRoot to /root/botsaz-faxima"
    else
        echo "   ✔ Vhost points to correct path"
    fi
fi

# ---- 6. systemd sandbox fix (PERSISTENT - survives apt upgrade) ----
# chmod 711 بی‌اثر است وقتی انکار داخل سرویس است نه روی دیسک:
# ProtectHome=true یا InaccessiblePaths=/root داخل mount namespace آپاچی،
# /root را مخفی می‌کند. namei سبز است ولی error.log پر از AH00035 است.
echo "[6/8] Fixing systemd sandbox (ProtectHome/InaccessiblePaths)..."
_UNIT=""
if systemctl show apache2 -p LoadState --value 2>/dev/null | grep -qx loaded; then _UNIT="apache2"; fi
if [ -n "$_UNIT" ]; then
    systemctl show "$_UNIT" -p ProtectHome,InaccessiblePaths 2>/dev/null || true
    mkdir -p "/etc/systemd/system/${_UNIT}.service.d"
    printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' > "/etc/systemd/system/${_UNIT}.service.d/botsaz.conf"
    echo "   ✔ wrote /etc/systemd/system/${_UNIT}.service.d/botsaz.conf"
    systemctl daemon-reload
    echo "   ✔ daemon-reload done"
else
    echo "   (apache2 unit not found - skipping)"
fi

# ---- 7. Restart Apache ----
echo "[7/8] Restarting Apache..."
systemctl restart apache2 2>/dev/null || service apache2 restart 2>/dev/null
echo "   ✔ Apache restarted"

# ---- 8. AppArmor check ----
echo "[8/8] Checking AppArmor..."
if command -v aa-status >/dev/null 2>&1; then
    if aa-status 2>/dev/null | grep -q 'apparmor module is loaded'; then
        echo "   ⚠️  AppArmor is ACTIVE - Apache may be blocked from /root"
        echo "   Moving project to /var/www/botsaz-faxima..."
        mkdir -p /var/www
        cp -a /root/botsaz-faxima /var/www/ 2>/dev/null
        chown -R www-data:www-data /var/www/botsaz-faxima 2>/dev/null
        sed -i 's#/root/botsaz-faxima#/var/www/botsaz-faxima#g' /etc/apache2/sites-available/botsaz.conf 2>/dev/null
        systemctl restart apache2 2>/dev/null
        echo "   ✔ Project moved to /var/www/botsaz-faxima"
    else
        echo "   ✔ AppArmor not active"
    fi
fi

# ---- Verification ----

# ---- Verification ----
echo ""
echo "========================================="
echo "  ✅ Verification"
echo "========================================="
echo ""
echo "/root permissions:      $(stat -c '%a' /root)"
echo "bots/ owner:            $(stat -c '%U:%G' /botsaz-faxima/bots)"
echo "data/ owner:            $(stat -c '%U:%G' /botsaz-faxima/data)"
echo "PHP PCRE JIT:           $(php -r 'echo ini_get(\"pcre.jit\");' 2>/dev/null)"
echo ""
echo "Testing Apache access:"
sudo -u www-data test -x /root && echo "  ✔ www-data CAN traverse /root" || echo "  ❌ www-data CANNOT traverse /root"
sudo -u www-data test -w /botsaz-faxima/data && echo "  ✔ www-data CAN write data/" || echo "  ❌ www-data CANNOT write data/"
sudo -u www-data test -w /botsaz-faxima/bots && echo "  ✔ www-data CAN write bots/" || echo "  ❌ www-data CANNOT write bots/"
echo ""
echo "Testing webhook:"
curl -sI https://alibat.api-system.top/bot.php 2>/dev/null || echo "  (curl not available)"
echo ""
echo "========================================="
echo "  ✅ Fix complete!"
echo "  Run: bash tools/install.sh --check"
echo "========================================="
