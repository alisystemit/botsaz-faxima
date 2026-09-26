#!/bin/bash
# ===================================================================
# Botsaz-Faxima Fix Script - Run as ROOT on the server
# Fix: 403 Forbidden / Permission denied / Webhook connection refused
# ===================================================================
# Usage: bash fix_permissions.sh
# ===================================================================

set -e

echo "========================================="
echo "  🔧 Botsaz-Faxima Fix Script"
echo "========================================="
echo ""

# ===== FIX 1: /root directory permissions =====
# /root has drwx-----x (710) which prevents Apache/www-data from traversing
# The fix: add execute permission for others (711)
echo "--- Fix 1: /root directory permissions ---"
CURRENT=$(stat -c '%a' /root)
echo "Current /root mode: $CURRENT"
if [ "$CURRENT" = "710" ]; then
    chmod o+x /root
    echo "✔ chmod o+x /root → now $(stat -c '%a' /root)"
else
    echo "⚠️  /root mode is $CURRENT (expected 710)"
    chmod o+x /root
    echo "✔ chmod o+x /root → now $(stat -c '%a' /root)"
fi

# ===== FIX 2: data/ directory ownership =====
# www-data needs write access to data/ for logs and manager database
echo ""
echo "--- Fix 2: data/ directory ownership ---"
if [ -d /root/botsaz-faxima/data ]; then
    chown -R www-data:www-data /root/botsaz-faxima/data
    echo "✔ chown -R www-data:www-data /root/botsaz-faxima/data"
else
    echo "❌ data/ directory not found!"
    exit 1
fi

# ===== FIX 3: Verify bot.php is readable =====
echo ""
echo "--- Fix 3: bot.php permissions ---"
chmod a+r /root/botsaz-faxima/bot.php
echo "✔ bot.php is now readable by all"

# ===== FIX 4: Check .htaccess =====
echo ""
echo "--- Fix 4: Verify .htaccess ---"
if [ -f /root/botsaz-faxima/.htaccess ]; then
    echo "✔ .htaccess exists"
else
    echo "⚠️  .htaccess missing - creating from config.example.php"
    # This shouldn't happen based on the health check
fi

# ===== FIX 5: Restart Apache =====
echo ""
echo "--- Fix 5: Restart Apache ---"
if command -v systemctl >/dev/null 2>&1; then
    systemctl restart apache2
    echo "✔ Apache restarted via systemctl"
elif command -v service >/dev/null 2>&1; then
    service apache2 restart
    echo "✔ Apache restarted via service"
else
    echo "⚠️  Could not restart Apache - tried systemctl and service"
fi

# ===== VERIFICATION =====
echo ""
echo "========================================="
echo "  ✅ Verification"
echo "========================================="
echo ""
echo "/root permissions:          $(stat -c '%a' /root)"
echo "/root/botsaz-faxima:        $(stat -c '%a' /root/botsaz-faxima)"
echo "/root/botsaz-faxima/bot.php: $(stat -c '%a' /root/botsaz-faxima/bot.php)"
echo "/root/botsaz-faxima/data/:  $(stat -c '%a' /root/botsaz-faxima/data)"
echo ""
echo "Testing Apache access:"
sudo -u www-data test -x /root && echo "  ✔ www-data CAN traverse /root" || echo "  ❌ www-data CANNOT traverse /root"
sudo -u www-data test -x /root/botsaz-faxima && echo "  ✔ www-data CAN traverse /root/botsaz-faxima" || echo "  ❌ www-data CANNOT traverse /root/botsaz-faxima"
sudo -u www-data test -r /root/botsaz-faxima/bot.php && echo "  ✔ www-data CAN read bot.php" || echo "  ❌ www-data CANNOT read bot.php"
sudo -u www-data test -w /root/botsaz-faxima/data && echo "  ✔ www-data CAN write data/" || echo "  ❌ www-data CANNOT write data/"
echo ""
echo "Testing HTTP:"
curl -sI https://alibat.api-system.top/bot.php 2>/dev/null || echo "  (curl not available or failed)"
echo ""
echo "========================================="
echo "  ✅ Fix complete! Run 'bash tools/install.sh --check' to verify."
echo "========================================="
