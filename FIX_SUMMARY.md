# 🔧 Botsaz-Faxima Fix Summary

## Problem Diagnosis

The server has **2 critical issues** that need to be fixed:

### Issue 1: `/root` directory permissions (PRIMARY FAILURE)
- `/root` has `drwx------` (700) permissions
- Apache runs as `www-data` and **cannot traverse** through `/root`
- This causes every HTTP request to return **403 Forbidden**
- Telegram webhook fails with "Connection refused"
- 11 updates are queued unprocessed

### Issue 2: Apache can't serve the project path
- Apache's DocumentRoot appears to be `/var/www/html` (based on error logs)
- The project is at `/root/botsaz-faxima`
- Even if DocumentRoot is correct, Apache can't reach it because of `/root` permissions

## Fix Commands (Run on Server as Root)

Connect to the server via SSH and run these commands:

```bash
# === FIX 1: Allow Apache to traverse /root ===
# This is the PRIMARY fix - it allows www-data to enter /root
chmod o+x /root
echo "✔ /root permissions changed to $(stat -c '%a' /root)"

# === FIX 2: Make data/ writable by Apache ===
chown -R www-data:www-data /root/botsaz-faxima/data
echo "✔ data/ ownership changed to www-data:www-data"

# === FIX 3: Verify Apache vhost points to correct path ===
# Check the vhost file
cat /etc/apache2/sites-available/botsaz.conf | grep -i DocumentRoot
# If DocumentRoot is /var/www/html, change it to /root/botsaz-faxima:
# sed -i 's#/var/www/html#/root/botsaz-faxima#g' /etc/apache2/sites-available/botsaz.conf

# === FIX 4: Disable conflicting vhosts ===
# Check if any other vhost claims alibat.api-system.top
# If 000-default-le-ssl.conf conflicts, disable it:
# sudo a2dissite 000-default-le-ssl.conf

# === FIX 5: Restart Apache ===
systemctl restart apache2
echo "✔ Apache restarted"

# === FIX 6: Set the Telegram webhook ===
cd /root/botsaz-faxima
php tools/set_webhook.php
echo "✔ Webhook set"

# === VERIFICATION ===
echo ""
echo "=== Verification ==="
echo "/root permissions: $(stat -c '%a' /root)"
echo "/root/botsaz-faxima permissions: $(stat -c '%a' /root/botsaz-faxima)"
echo "data/ permissions: $(stat -c '%a' /root/botsaz-faxima/data)"
sudo -u www-data test -x /root && echo "✔ www-data CAN traverse /root" || echo "❌ www-data CANNOT traverse /root"
sudo -u www-data test -r /root/botsaz-faxima/bot.php && echo "✔ www-data CAN read bot.php" || echo "❌ www-data CANNOT read bot.php"

# Test with curl
curl -sI https://alibat.api-system.top/bot.php

# Re-run health check
bash tools/install.sh --check
```

## Alternative Fix (Move Project to /var/www)

If you prefer not to change `/root` permissions for security reasons, move the project:

```bash
mkdir -p /var/www
cp -a /root/botsaz-faxima /var/www/
chown -R www-data:www-data /var/www/botsaz-faxima/data
# Update DocumentRoot in vhost to /var/www/botsaz-faxima
# sed -i 's#/root/botsaz-faxima#/var/www/botsaz-faxima#g' /etc/apache2/sites-available/botsaz.conf
systemctl restart apache2
```

## Expected Outcome After Fix

1. `https://alibat.api-system.top/bot.php` should return HTTP 200
2. Telegram webhook should consume the 11 queued updates
3. `bash tools/install.sh --check` should show all ✅
4. The bot should be fully operational

## Additional Notes

- The `.htaccess` file blocks access to `config.php`, `tools/`, `src/`, `templates/`, `data/` directories - this is the security layer
- Once `/root` permissions are fixed, the `.htaccess` rules will work correctly
- The webhook secret token is verified by `bot.php` - make sure `config.php` has the correct `secret_key`
- The bot token and super_admins in the server's `config.php` should already be correct (the health check confirmed this)
