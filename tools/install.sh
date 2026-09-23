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

# اعتبارسنجی پورت (escape مقادیر دیگر را PHP با var_export انجام می‌دهد؛ نیازی به escape دستی نیست)
if ! [[ "$DB_PORT" =~ ^[0-9]+$ ]]; then DB_PORT=3306; fi
SECRET_KEY=$($PHP_BIN -r 'echo bin2hex(random_bytes(16));')

# ۶) ساخت config.php (بدون بازنویسی کانفیگ موجود)
# مقادیر از طریق env به PHP داده می‌شوند و با var_export نوشته می‌شوند تا کاراکترهای
# خاص مثل $ ' \ " در توکن/پسورد باعث خرابی یا تزریق در کانفیگ نشود.
echo ""
if [ -f "$ROOT_DIR/config.php" ]; then
    echo "[⚠️] config.php از قبل وجود دارد — بازنویسی نشد. برای تغییر، دستی ویرایش کن."
else
    echo "[✓] ساخت config.php..."
    CFG_OUT="$ROOT_DIR/config.php" \
    MAIN_TOKEN="$MAIN_TOKEN" \
    SUPER_ADMIN="$SUPER_ADMIN" \
    BASE_URL="$BASE_URL" \
    DB_HOST="$DB_HOST" \
    DB_PORT="$DB_PORT" \
    DB_USER="$DB_USER" \
    DB_PASS="$DB_PASS" \
    DB_PREFIX="$DB_PREFIX" \
    CFG_PHP_BIN="$PHP_BIN" \
    SECRET_KEY="$SECRET_KEY" \
    "$PHP_BIN" -r '
$cfg = array(
    "main_token" => (string) getenv("MAIN_TOKEN"),
    "super_admins" => array((int) getenv("SUPER_ADMIN")),
    "base_url" => (string) getenv("BASE_URL"),
    "db_host" => (string) getenv("DB_HOST"),
    "db_port" => (int) getenv("DB_PORT"),
    "db_user" => (string) getenv("DB_USER"),
    "db_pass" => (string) getenv("DB_PASS"),
    "db_prefix" => (string) getenv("DB_PREFIX"),
    "manager_db" => null,
    "php_bin" => (string) getenv("CFG_PHP_BIN"),
    "secret_key" => (string) getenv("SECRET_KEY"),
);
$out = "<?php\nreturn " . var_export($cfg, true) . ";\n";
$out = str_replace("\x27manager_db\x27 => NULL,", "\x27manager_db\x27 => __DIR__ . \x27/data/botsaz.sqlite\x27,", $out);
if (file_put_contents((string) getenv("CFG_OUT"), $out) === false) {
    fwrite(STDERR, "config.php write failed\n");
    exit(1);
}
'
    echo "   config.php ساخته شد ✓"
fi

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
