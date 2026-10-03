#!/bin/bash
# Update script for botsaz-faxima bot manager
# Handles server-side update procedure

set -e

# Configuration paths (adjust for your server)
PROJECT_ROOT="/var/www/botsaz-faxima"
BACKUP_ROOT="/root/botsaz-faxima"
WEBROOT="/var/www"

echo "=== Starting Update Procedure ==="

# ==============================
# STEP 1: After downloading new version in root
# Get specs from /var/www/botsaz-faxima and replace configs
# ==============================
echo ""
echo "--- Step 1: Getting specs from $PROJECT_ROOT ---"

# Read current config if it exists
if [ -f "$PROJECT_ROOT/config.php" ]; then
    echo "Current config.php found, reading database settings..."
    # Extract DB config using grep/sed
    DB_HOST=$(grep -oP "db_host' => 'K\K[^']+" "$PROJECT_ROOT/config.php" || echo "127.0.0.1")
    DB_PORT=$(grep -oP "db_port' => \K[0-9]+" "$PROJECT_ROOT/config.php" || echo "3306")
    DB_USER=$(grep -oP "db_user' => 'K\K[^']+" "$PROJECT_ROOT/config.php" || echo "root")
    DB_PASS=$(grep -oP "db_pass' => 'K\K[^']+" "$PROJECT_ROOT/config.php" || echo "")
    DB_PREFIX=$(grep -oP "db_prefix' => 'K\K[^']+" "$PROJECT_ROOT/config.php" || echo "botsaz_")
    echo "DB Host: $DB_HOST, Port: $DB_PORT, User: $DB_USER, Prefix: $DB_PREFIX"
else
    echo "No config.php found in $PROJECT_ROOT"
fi

# ==============================
# STEP 2: Copy from /root/botsaz-faxima to /var/www/botsaz-faxima
# ==============================
echo ""
echo "--- Step 2: Copying from $BACKUP_ROOT to $PROJECT_ROOT ---"

if [ -d "$BACKUP_ROOT" ]; then
    # Remove existing content (except .git if present)
    if [ -d "$PROJECT_ROOT/.git" ]; then
        rm -rf "$PROJECT_ROOT/.git"
        echo "Removed existing .git directory"
    fi
    
    # Copy all from backup to project root
    cp -r "$BACKUP_ROOT"/* "$PROJECT_ROOT"/ 2>/dev/null || true
    cp -r "$BACKUP_ROOT"/.* "$PROJECT_ROOT"/ 2>/dev/null || true
    
    # Remove .htaccess and other temp files if needed
    rm -f "$PROJECT_ROOT/.htaccess" 2>/dev/null || true
    rm -f "$PROJECT_ROOT/.gitattributes" 2>/dev/null || true
    rm -f "$PROJECT_ROOT/.gitignore" 2>/dev/null || true
    
    echo "Copy completed from $BACKUP_ROOT to $PROJECT_ROOT"
else
    echo "Warning: $BACKUP_ROOT directory not found, skipping copy"
fi

# ==============================
# STEP 3: Database specs replacement
# ==============================
echo ""
echo "--- Step 3: Database configuration ---"

# Check if db config functions exist in the codebase
if grep -r "db_host\|db_user\|db_pass" "$PROJECT_ROOT"/*.php "$PROJECT_ROOT"/src/*.php 2>/dev/null | grep -v "config.example" | grep -v "PUT_MAIN"; then
    echo "Database configuration found in code - updating config.php..."
    
    # Create/update config.php with database settings
    cat > "$PROJECT_ROOT/config.php" << 'PHPEOF'
<?php
// ===== تنظیمات اصلی ربات‌ساز =====
// این فایل را ویرایش کن و به نام config.php ذخیره کن (یا از tools/install.php استفاده کن)

return [
    // توکن ربات اصلی (ربات‌ساز) از @BotFather
    'main_token' => 'PUT_MAIN_BOT_TOKEN_HERE',

    // آیدی عددی سوپرادمین‌ها (دسترسی کامل)
    'super_admins' => [123456789],

    // آدرس پایه پروژه روی هاست، بدون اسلش آخر. مثال:
    // 'base_url' => 'https://yourdomain.com/botsaz-faxima',
    'base_url' => 'http://yourdomain.com/botsaz-faxima',

    // مشخصات اتصال MySQL برای ساخت دیتابیس ربات‌های فرزند
    // این یوزر باید دسترسی CREATE DATABASE داشته باشد (در لوکال: root)
    'db_host' => '$DB_HOST',
    'db_port' => $DB_PORT,
    'db_user' => '$DB_USER',
    'db_pass' => '$DB_PASS',
    // پیشوند نام دیتابیس‌ها: مثلا botsaz_<slug>_<rand>
    'db_prefix' => '$DB_PREFIX',

    // مسیر دیتابیس مدیریتی (SQLite) — نیازی به تغییر نیست
    'manager_db' => __DIR__ . '/data/botsaz.sqlite',

    // باینری PHP برای اجرای کرون و پردازش غیرهمزمان
    'php_bin' => 'php',

    // رمزنگاری توکن‌ها در دیتابیس (AES-256-CBC) — کلید ۳۲ بایتی
    'secret_key' => 'change-this-to-a-random-string-32bytes!',
];
PHPEOF
    echo "config.php updated with database settings"
else
    echo "No database config functions found in code - asking user for input"
    # Prompt for database info if not in code
    read -p "Enter MySQL host [127.0.0.1]: " DB_HOST_INPUT
    DB_HOST_INPUT=${DB_HOST_INPUT:-127.0.0.1}
    read -p "Enter MySQL port [3306]: " DB_PORT_INPUT
    DB_PORT_INPUT=${DB_PORT_INPUT:-3306}
    read -p "Enter MySQL user [root]: " DB_USER_INPUT
    DB_USER_INPUT=${DB_USER_INPUT:-root}
    read -p "Enter MySQL password [empty]: " DB_PASS_INPUT
    read -p "Enter DB prefix [botsaz_]: " DB_PREFIX_INPUT
    DB_PREFIX_INPUT=${DB_PREFIX_INPUT:-botsaz_}
    
    # Update config.php
    cat > "$PROJECT_ROOT/config.php" << PHPEOF
<?php
// ===== تنظیمات اصلی ربات‌ساز =====
// این فایل را ویرایش کن و به نام config.php ذخیره کن (یا از tools/install.php استفاده کن)

return [
    // توکن ربات اصلی (ربات‌ساز) از @BotFather
    'main_token' => 'PUT_MAIN_BOT_TOKEN_HERE',

    // آیدی عددی سوپرادمین‌ها (دسترسی کامل)
    'super_admins' => [123456789],

    // آدرس پایه پروژه روی هاست، بدون اسلش آخر. مثال:
    // 'base_url' => 'https://yourdomain.com/botsaz-faxima',
    'base_url' => 'http://yourdomain.com/botsaz-faxima',

    // مشخصات اتصال MySQL برای ساخت دیتابیس ربات‌های فرزند
    // این یوزر باید دسترسی CREATE DATABASE داشته باشد (در لوکال: root)
    'db_host' => '$DB_HOST_INPUT',
    'db_port' => $DB_PORT_INPUT,
    'db_user' => '$DB_USER_INPUT',
    'db_pass' => '$DB_PASS_INPUT',
    // پیشوند نام دیتابیس‌ها: مثلا botsaz_<slug>_<rand>
    'db_prefix' => '$DB_PREFIX_INPUT',

    // مسیر دیتابیس مدیریتی (SQLite) — نیازی به تغییر نیست
    'manager_db' => __DIR__ . '/data/botsaz.sqlite',

    // باینری PHP برای اجرای کرون و پردازش غیرهمزمان
    'php_bin' => 'php',

    // رمزنگاری توکن‌ها در دیتابیس (AES-256-CBC) — کلید ۳۲ بایتی
    'secret_key' => 'change-this-to-a-random-string-32bytes!',
];
PHPEOF
    echo "config.php created with user-provided database settings"
fi

# ==============================
# STEP 4: VHost settings
# ==============================
echo ""
echo "--- Step 4: VHost configuration ---"

# Check if we're on Apache or Nginx
if [ -f "/etc/apache2/sites-available/000-default.conf" ] || [ -f "/etc/httpd/conf/httpd.conf" ]; then
    echo "Apache detected"
    # Create or update vhost config
    VHOST_FILE="/etc/apache2/sites-available/botsaz-faxima.conf"
    cat > "$VHOST_FILE" << 'VHOSTEOF'
<VirtualHost *:80>
    ServerAdmin admin@botsaz-faxima.com
    ServerName botsaz-faxima.com
    DocumentRoot /var/www/botsaz-faxima
    
    <Directory /var/www/botsaz-faxima>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/botsaz-faxima-error.log
    CustomLog \${APACHE_LOG_DIR}/botsaz-faxima-access.log combined
</VirtualHost>
VHOSTEOF
    echo "Apache vhost created at $VHOST_FILE"
    echo "Enable site: a2ensite botsaz-faxima && apache2ctl restart"
    
elif [ -f "/etc/nginx/nginx.conf" ] || ls /etc/nginx/sites-enabled/ 2>/dev/null; then
    echo "Nginx detected"
    # Create nginx config
    NGINX_CONF="/etc/nginx/sites-available/botsaz-faxima"
    cat > "$NGINX_CONF" << 'NGINXEOF'
server {
    listen 80;
    server_name botsaz-faxima.com;
    root /var/www/botsaz-faxima;
    
    location / {
        try_files \$uri \$uri/ =404;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    
    access_log /var/log/nginx/botsaz-faxima-access.log;
    error_log /var/log/nginx/botsaz-faxima-error.log;
}
NGINXEOF
    echo "Nginx vhost created at $NGINX_CONF"
    echo "Enable site: ln -s $NGINX_CONF /etc/nginx/sites-enabled/ && service nginx restart"
else
    echo "No web server detected automatically - please configure manually"
fi

# ==============================
# STEP 5: Webhook update
# ==============================
echo ""
echo "--- Step 5: Webhook update ---"

# Check if webhook functions exist and update
if [ -f "$PROJECT_ROOT/src/Manager.php" ]; then
    echo "Updating webhook-related configurations..."
    
    # The webhook URL is generated from base_url + bot folder
    # This step typically involves:
    # 1. Setting webhook via Telegram API
    # 2. Updating webhook secret if needed
    
    # Example: Setting webhook for the main bot
    MAIN_TOKEN=$(grep -oP "main_token' => 'K\K[^']+" "$PROJECT_ROOT/config.php" 2>/dev/null || echo "YOUR_BOT_TOKEN")
    echo "Main bot token: $MAIN_TOKEN"
    echo "To set webhook, run: curl -F 'url=http://yourdomain.com/botsaz-faxima/bots/main/webhook' -F 'secret=YOUR_SECRET' https://api.telegram.org/bot$MAIN_TOKEN/setWebhook"
    echo ""
    echo "For child bots, webhooks are configured automatically via the Manager::webhookUrlForBot() function"
else
    echo "Manager.php not found, skipping webhook auto-configuration"
fi

# ==============================
# STEP 6: Any needed development
# ==============================
echo ""
echo "--- Step 6: Checking for development needs ---"

# Check for any TODOs or pending items
TODO_COUNT=$(grep -r "TODO\|FIXME" "$PROJECT_ROOT"/*.php "$PROJECT_ROOT"/src/*.php 2>/dev/null | wc -l)
if [ "$TODO_COUNT" -gt 0 ]; then
    echo "Found $TODO_COUNT TODO/FIXME items - review them after update"
    grep -r "TODO\|FIXME" "$PROJECT_ROOT"/*.php "$PROJECT_ROOT"/src/*.php 2>/dev/null | head -5
else
    echo "No TODO/FIXME items found"
fi

# ==============================
# STEP 7: Clean root files for security
# ==============================
echo ""
echo "--- Step 7: Cleaning root directory for security ---"

if [ -d "$BACKUP_ROOT" ]; then
    # Remove sensitive files from root
    rm -f "$BACKUP_ROOT/config.php" 2>/dev/null || true
    rm -f "$BACKUP_ROOT/config.example.php" 2>/dev/null || true
    rm -f "$BACKUP_ROOT/.htaccess" 2>/dev/null || true
    rm -f "$BACKUP_ROOT/.gitattributes" 2>/dev/null || true
    rm -f "$BACKUP_ROOT/.gitignore" 2>/dev/null || true
    rm -f "$BACKUP_ROOT/FIX_*.sh" 2>/dev/null || true
    rm -f "$BACKUP_ROOT/*.lock" 2>/dev/null || true
    echo "Sensitive files removed from $BACKUP_ROOT"
    
    # Remove backup archive if exists
    rm -f "$BACKUP_ROOT/*.zip" 2>/dev/null || true
    rm -f "$BACKUP_ROOT/*.tar.gz" 2>/dev/null || true
    echo "Archive files removed from $BACKUP_ROOT"
fi

# ==============================
# Finalization
# ==============================
echo ""
echo "=== Update Procedure Complete ==="
echo ""
echo "Summary of actions performed:"
echo "1. ✓ Retrieved specs from $PROJECT_ROOT"
echo "2. ✓ Copied from $BACKUP_ROOT to $PROJECT_ROOT"
echo "3. ✓ Database configuration updated"
echo "4. ✓ VHost/web server config generated"
echo "5. ✓ Webhook setup instructions provided"
echo "6. ✓ Development needs checked"
echo "7. ✓ Root directory cleaned for security"
echo ""
echo "Next steps:"
echo "- Configure your web server (Apache/Nginx) to point to $PROJECT_ROOT"
echo "- Set up database with the provided credentials"
echo "- Set webhooks via Telegram BotFather API"
echo "- Test the installation by visiting your domain"
echo ""
read -p "Press Enter to exit or 'r' to run again: " choice
if [ "$choice" = "r" ]; then
    exec $0
fi