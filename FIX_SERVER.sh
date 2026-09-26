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
echo "[1/6] Fixing /root permissions..."
_current=$(stat -c '%a' /root)
chmod 711 /root
echo "   ✔ /root: $_current → $(stat -c '%a' /root)"

# ---- 2. data/ ownership ----
echo "[2/6] Fixing data/ ownership..."
if [ -d /botsaz-faxima/data ]; then
    chown -R www-data:www-data /botsaz-faxima/data
    echo "   ✔ data/ → www-data:www-data"
fi

# ---- 3. bots/ ownership ----
echo "[3/6] Fixing bots/ ownership..."
if [ -d /botsaz-faxima/bots ]; then
    chown www-data:www-data /botsaz-faxima/bots
    echo "   ✔ bots/ → www-data:www-data"
fi

# ---- 4. PCRE JIT fix ----
echo "[4/6] Fixing PCRE JIT..."
_php_ini=$(php -r 'echo php_ini_loaded_file();' 2>/dev/null || echo '')
if [ -n "$_php_ini" ] && [ -f "$_php_ini" ]; then
    if ! grep -q '^pcre.jit=' "$_php_ini" 2>/dev/null; then
        echo 'pcre.jit=0' >> "$_php_ini"
        echo "   ✔ pcre.jit=0 added to $_php_ini"
    else
        echo "   ✔ pcre.jit already set"
    fi
fi

# ---- 5. Apache vhost check ----
echo "[5/6] Checking Apache vhost..."
if [ -f /etc/apache2/sites-available/botsaz.conf ]; then
    _docroot=$(grep -i 'DocumentRoot' /etc/apache2/sites-available/botsaz.conf | head -1)
    echo "   DocumentRoot: $_docroot"
    if echo "$_docroot" | grep -q '/var/www/html'; then
        echo "   ⚠️  Vhost points to /var/www/html - fixing..."
        sed -i 's#/var/www/html#/botsaz-faxima#g' /etc/apache2/sites-available/botsaz.conf
        echo "   ✔ Fixed DocumentRoot to /botsaz-faxima"
    else
        echo "   ✔ Vhost points to correct path"
    fi
fi

# ---- 6. Restart Apache ----
echo "[6/6] Restarting Apache..."
systemctl restart apache2 2>/dev/null || service apache2 restart 2>/dev/null
echo "   ✔ Apache restarted"

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
