#!/bin/bash

# ===== نصب اتومات ربات‌ساز فاکسیما/میرزا =====
# استفاده: bash tools/install.sh

set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="php"

echo "========================================="
echo "  🔧 نصب ربات‌ساز فاکسیما/میرزا"
echo "========================================="
echo ""

# ۱) بررسی PHP
echo "[✓] بررسی PHP..."
if ! command -v "$PHP_BIN" &>/dev/null; then
    echo "❌ PHP پیدا نشد. لطفاً PHP را نصب کنید."
    exit 1
fi
PHP_VER=$($PHP_BIN -r "echo PHP_VERSION_ID;")
if [ "$PHP_VER" -lt 80100 ]; then
    echo "❌ نیاز به PHP 8.1 یا بالاتر. نسخه فعلی: $($PHP_BIN -r 'echo PHP_VERSION;')"
    exit 1
fi
echo "   PHP $($PHP_BIN -r 'echo PHP_VERSION;') ✓"

# ۲) توکن ربات اصلی
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🔑 توکن ربات اصلی (ربات‌ساز)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
read -p "    توکن از @BotFather: " MAIN_TOKEN
if [ -z "$MAIN_TOKEN" ]; then
    echo "❌ توکن خالی است."
    exit 1
fi

# ۳) آیدی سوپرادمین
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  👤 آیدی عددی سوپرادمین"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
read -p "    آیدی عددی ادمین: " SUPER_ADMIN
if ! [[ "$SUPER_ADMIN" =~ ^[0-9]{5,}$ ]]; then
    echo "❌ آیدی عددی معتبر وارد کنید."
    exit 1
fi

# ۴) آدرس وبسایت
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🌐 آدرس پایه پروژه (base_url)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
read -p "    آدرس (مثلا https://domain.com): " BASE_URL
if [ -z "$BASE_URL" ]; then
    BASE_URL="http://localhost/botsaz-faxima"
    echo "   ⚠️  استفاده از پیش‌فرض: $BASE_URL"
fi

# ۵) مشخصات MySQL
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🗄️  مشخصات دیتابیس MySQL"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
read -p "    DB Host [127.0.0.1]: " DB_HOST
DB_HOST=${DB_HOST:-127.0.0.1}
read -p "    DB Port [3306]: " DB_PORT
DB_PORT=${DB_PORT:-3306}
read -p "    DB User [root]: " DB_USER
DB_USER=${DB_USER:-root}
read -p "    DB Password: " DB_PASS
read -p "    DB Prefix [botsaz_]: " DB_PREFIX
DB_PREFIX=${DB_PREFIX:-botsaz_}

# ۶) ساخت config.php
echo ""
echo "[✓] ساخت config.php..."
cat > "$ROOT_DIR/config.php" << CONFIG_EOF
<?php
return [
    'main_token' => '$MAIN_TOKEN',
    'super_admins' => [$SUPER_ADMIN],
    'base_url' => '$BASE_URL',
    'db_host' => '$DB_HOST',
    'db_port' => $DB_PORT,
    'db_user' => '$DB_USER',
    'db_pass' => '$DB_PASS',
    'db_prefix' => '$DB_PREFIX',
    'fallback_db_name' => '',
    'manager_db' => __DIR__ . '/data/botsaz.sqlite',
    'secret_key' => 'change-this-to-a-random-string',
];
CONFIG_EOF
echo "   config.php ساخته شد ✓"

# ۷) اجرای نصب اولیه
echo ""
echo "[✓] اجرای نصب اولیه..."
$PHP_BIN "$ROOT_DIR/tools/install.php"

# ۸) ست وبهوک ربات اصلی
echo ""
echo "[✓] ست وبهوک..."
WEBHOOK_URL="${BASE_URL}/bot.php"
$PHP_BIN "$ROOT_DIR/tools/set_webhook.php" "$WEBHOOK_URL" || echo "⚠️  وبهوک ست نشد. از ngrok یا هاست واقعی استفاده کنید."

# ۹) بررسی template ها
echo ""
echo "[✓] بررسی فایل‌های قالب..."
for f in "templates/faxima/config.php" "templates/mirza/config.php" "templates/faxima/index.php" "templates/mirza/index.php"; do
    if [ -f "$ROOT_DIR/$f" ]; then
        echo "   ✓ $f"
    else
        echo "   ❌ $f یافت نشد!"
    fi
done

echo ""
echo "========================================="
echo "  ✅ نصب کامل شد!"
echo "========================================="
echo ""
echo "📌 برای استفاده:"
echo "   1. ربات اصلی را در تلگرام /start بزنید"
echo "   2. آیدی شما به عنوان سوپرادمین ثبت شد"
echo "   3. برای هر کاربر: ابتدا درخواست بده، بعد ادمین تأیید کند"
echo "========================================="
