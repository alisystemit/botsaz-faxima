#!/bin/bash

# ===== نصب اتومات ربات‌ساز فاکسیما/میرزا =====
# استفاده: bash tools/install.sh
#
# این اسکریپت اول پیش‌نیازها را آماده می‌کند (PHP + اکستنشن‌ها، تست اتصال MySQL)،
# بعد config می‌سازد و در آخر بررسی می‌کند که ربات واقعاً اجراست یا نه.

set -e

# ===== سازگاری با زبان فارسی / UTF-8 =====
# اگر locale ترمینال UTF-8 نباشد، متن فارسی به‌صورت mojibake (گاربل) دیده می‌شود.
# توجه: پیام این بلوک عمداً انگلیسی/ASCII است؛ اگر فارسی خراب باشد، خودِ این
# هشدار فارسی هم خوانا نیست و باید به زبانی نوشته شود که همیشه سالم می‌ماند.
_eff_lc="${LC_ALL:-${LANG:-}}"
case "$_eff_lc" in
    *.[Uu][Tt][Ff]-8*|*.[Uu][Tt][Ff]8*|*[Uu][Tt][Ff]-8*|*[Uu][Tt][Ff]8*)
        ;;                                  # locale از قبل UTF-8 است (en_US.UTF-8 / C.UTF-8)
    *)
        _utf8_locale=""
        if command -v locale >/dev/null 2>&1; then
            # هر دو شکل خروجی locale -a:  C.UTF-8 و C.utf8
            _utf8_locale="$(locale -a 2>/dev/null | grep -iE '^(C|en_US|fa_IR)\.UTF-?8$' | head -n1 || true)"
        fi
        if [ -n "$_utf8_locale" ]; then
            export LC_ALL="$_utf8_locale"
            export LANG="$_utf8_locale"
        else
            echo "!! WARNING: current locale is NOT UTF-8 - Persian text may look garbled."
            echo "   Fix it first, then re-run this installer:"
            echo "     sudo apt-get install -y locales"
            echo "     sudo locale-gen en_US.UTF-8"
            echo "     export LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8"
            echo ""
        fi
        ;;
esac
unset _eff_lc _utf8_locale

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="php"

echo "========================================="
echo "  [SETUP] نصب ربات‌ساز فاکسیما/میرزا"
echo "========================================="
echo ""

# ---------- ابزارهای کمکی ----------
has_cmd() { command -v "$1" &>/dev/null; }

SUDO=""
if [ "$(id -u)" -ne 0 ]; then SUDO="sudo"; fi

# آیا می‌توانیم با apt نصب کنیم؟ (روت یا sudo موجود)
can_apt() {
    has_cmd apt-get && { [ "$(id -u)" -eq 0 ] || has_cmd sudo; }
}

ask_yes() { # $1 = متن سؤال (پیش‌فرض بله)
    local ans
    # چرا این‌طور؟ متن فارسی (RTL) داخل read -p باعث می‌شود الگوریتم bidi نویسه‌ها را
    # جابه‌جا کند، مکان‌نما در جای اشتباهی بنشیند و [Y/n] وسط متن فارسی به‌هم بریزد.
    # راه‌حل: سؤال در خطِ خودش (RTL درست رندر می‌شود) و پرامپتِ ورودی کاملاً ASCII.
    printf '%s\n' "$1"
    read -r -p "   [Y/n]: " ans
    [[ "$ans" =~ ^[Nn] ]] && return 1 || return 0
}

apt_install() {
    echo "   [..] نصب با apt (ممکن است sudo پسورد بخواهد)..."
    $SUDO apt-get update -qq
    # shellcheck disable=SC2068
    $SUDO apt-get install -y $@
    hash -r 2>/dev/null || true
}

# ---------- ۰) پیش‌نیاز: PHP ----------
echo "[✓] بررسی PHP..."
install_php_if_needed() {
    if has_cmd "$PHP_BIN"; then
        PHP_VER=$($PHP_BIN -r "echo PHP_VERSION_ID;")
        if [ "$PHP_VER" -ge 80100 ]; then
            echo "   PHP $($PHP_BIN -r 'echo PHP_VERSION;') ✓"
            return 0
        fi
        echo "   [!]  نسخه PHP قدیمی است: $($PHP_BIN -r 'echo PHP_VERSION;') (نیاز: 8.1+)"
    else
        echo "   [!]  PHP پیدا نشد."
    fi
    if can_apt && ask_yes "   نصب/ارتقای خودکار PHP با اکستنشن‌های لازم؟"; then
        apt_install php php-cli php-curl php-mbstring php-mysql php-sqlite3 php-xml php-zip
    fi
    if ! has_cmd "$PHP_BIN"; then
        echo "[X] PHP در دسترس نیست. دستی نصب کن:"
        echo "   sudo apt install php php-cli php-curl php-mbstring php-mysql php-sqlite3"
        exit 1
    fi
    PHP_VER=$($PHP_BIN -r "echo PHP_VERSION_ID;")
    if [ "$PHP_VER" -lt 80100 ]; then
        echo "[X] نسخه PHP کمتر از 8.1 است: $($PHP_BIN -r 'echo PHP_VERSION;')"
        echo "   روی Ubuntu قدیمی از مخزن ondrej/php نسخه 8.2+ نصب کن."
        exit 1
    fi
    echo "   PHP $($PHP_BIN -r 'echo PHP_VERSION;') ✓"
}
install_php_if_needed

# ---------- ۰) پیش‌نیاز: اکستنشن‌های PHP ----------
echo "[✓] بررسی اکستنشن‌های PHP..."
# جفت‌ها: نام_اکستنشن:نام_پکیج_apt
EXT_PKGS="curl:php-curl mbstring:php-mbstring pdo_mysql:php-mysql sqlite3:php-sqlite3"
missing_pkgs=""
for pair in $EXT_PKGS; do
    ext="${pair%%:*}"
    pkg="${pair##*:}"
    if ! $PHP_BIN -m | grep -qi "^${ext}$"; then
        echo "   [!]  اکستنشن ${ext} نیست."
        missing_pkgs="$missing_pkgs $pkg"
    fi
done
# این دو معمولاً داخلی‌اند؛ اگر نباشند نصب خراب است
for ext in openssl json; do
    if ! $PHP_BIN -m | grep -qi "^${ext}$"; then
        echo "[X] اکستنشن حیاتی ${ext} در PHP نیست — نصب PHP را تعمیر کن."
        exit 1
    fi
done
if [ -n "$missing_pkgs" ]; then
    if can_apt && ask_yes "   نصب خودکار اکستنشن‌ها؟ ($missing_pkgs )"; then
        # shellcheck disable=SC2086
        apt_install $missing_pkgs
    else
        echo "[X] اکستنشن‌های لازم نصب نیست. دستی نصب کن:"
        echo "   sudo apt install$missing_pkgs"
        exit 1
    fi
    # بررسی مجدد (سخت‌گیرانه تا جلوتر خطا نگیریم)
    still_missing=""
    for pair in $EXT_PKGS; do
        ext="${pair%%:*}"
        if ! $PHP_BIN -m | grep -qi "^${ext}$"; then still_missing="$still_missing $ext"; fi
    done
    if [ -n "$still_missing" ]; then
        echo "[X] هنوز این اکستنشن‌ها نیستند:$still_missing"
        echo "   وب‌سرور/CLI ممکن است php.ini جدا داشته باشند؛ بررسی کن."
        exit 1
    fi
fi
echo "   اکستنشن‌ها ✓ (curl, mbstring, pdo_mysql, sqlite3)"

# ---------- ۰) پیش‌نیاز: ابزارهای سیستمی ----------
for cmd in git curl; do
    if ! has_cmd "$cmd"; then
        echo "   [!]  ابزار $cmd نیست."
        if can_apt && ask_yes "   نصب خودکار $cmd؟"; then
            apt_install "$cmd"
        else
            echo "[X] بدون $cmd ادامه نمی‌دهم. نصبش کن و دوباره اجرا کن."
            exit 1
        fi
    fi
done

# ۲) توکن ربات اصلی
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  [KEY] توکن ربات اصلی (ربات‌ساز)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
while true; do
    printf '    توکن از @BotFather:\n'
    read -r -p "    > " MAIN_TOKEN
    if [[ "$MAIN_TOKEN" =~ ^[0-9]{6,12}:[A-Za-z0-9_-]{35}$ ]]; then break; fi
    printf '   [X] فرمت توکن اشتباه است (باید شبیه 123456:ABC... ۳۵ کاراکتری باشد). دوباره:\n'
done
# بررسی زنده بودن توکن (فقط هشدار؛ اگر شبکه قطع بود ادامه می‌دهیم)
echo "   [..] بررسی توکن در تلگرام..."
if ! MAIN_TOKEN="$MAIN_TOKEN" $PHP_BIN -r '
$tok = (string) getenv("MAIN_TOKEN");
$j = @json_decode((string) @file_get_contents("https://api.telegram.org/bot".$tok."/getMe"), true);
if (empty($j["ok"])) { fwrite(STDERR, "getMe failed\n"); exit(1); }
echo "   [BOT] @".$j["result"]["username"]." ✓\n";'; then
    echo "   [!]  تلگرام جواب نداد (توکن اشتباه است یا اینترنت/فیلتر مشکل دارد)."
    ask_yes "   با همین توکن ادامه بدهم؟" || exit 1
fi

# ۳) آیدی سوپرادمین
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  [ID] آیدی عددی سوپرادمین"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
printf '    آیدی عددی ادمین (از @userinfobot):\n'
read -r -p "    > " SUPER_ADMIN
if ! [[ "$SUPER_ADMIN" =~ ^[0-9]{5,}$ ]]; then
    printf '   [X] آیدی عددی معتبر وارد کنید.\n'
    exit 1
fi

# ۴) آدرس وبسایت
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  [URL] آدرس پایه پروژه (base_url)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
printf '    آدرس (مثلا https://domain.com/botsaz):\n'
read -r -p "    > " BASE_URL
if [ -z "$BASE_URL" ]; then
    BASE_URL="http://localhost/botsaz-faxima"
    echo "   [!]  استفاده از پیش‌فرض: $BASE_URL"
fi
BASE_URL="$(echo "$BASE_URL" | sed 's:/*$::')"
if ! [[ "$BASE_URL" =~ ^https:// ]]; then
    echo "   [!]  آدرس https نیست — تلگرام وبهوک http را قبول نمی‌کند و ربات اجرا نمی‌شود!"
    echo "   (اگر دامنه + SSL داری، حتماً همان را بزن.)"
fi

# ۵) مشخصات MySQL + تست واقعی اتصال و دسترسی ساخت دیتابیس
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  [DB]  مشخصات دیتابیس MySQL"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
while true; do
    read -r -p "    DB Host [127.0.0.1]: " DB_HOST
    DB_HOST=${DB_HOST:-127.0.0.1}
    read -r -p "    DB Port [3306]: " DB_PORT
    DB_PORT=${DB_PORT:-3306}
    read -r -p "    DB User [root]: " DB_USER
    DB_USER=${DB_USER:-root}
    read -r -s -p "    DB Password: " DB_PASS
    echo ""
    read -r -p "    DB Prefix [botsaz_]: " DB_PREFIX
    DB_PREFIX=${DB_PREFIX:-botsaz_}
    if ! [[ "$DB_PORT" =~ ^[0-9]+$ ]]; then DB_PORT=3306; fi

    echo "   [..] تست اتصال و دسترسی CREATE DATABASE..."
    if DBH="$DB_HOST" DBP="$DB_PORT" DBU="$DB_USER" DBPW="$DB_PASS" $PHP_BIN -r '
try {
    $pdo = new PDO("mysql:host=".getenv("DBH").";port=".(int)getenv("DBP").";charset=utf8mb4",
        getenv("DBU"), getenv("DBPW"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
    $tmp = "botsaz_installtest_" . bin2hex(random_bytes(3));
    $pdo->exec("CREATE DATABASE `$tmp` CHARACTER SET utf8mb4");
    $pdo->exec("DROP DATABASE `$tmp`");
    echo "OK";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }'; then
        echo "   اتصال + دسترسی ساخت دیتابیس ✓"
        break
    fi
    echo "   [X] اتصال یا دسترسی دیتابیس مشکل دارد (خطا بالا)."
    echo "   ربات‌ساز برای هر ربات فرزند یک دیتابیس جدا می‌سازد؛ بدون این دسترسی جلو نمی‌رود."
    if ask_yes "   مشخصات را دوباره وارد می‌کنی؟ (نه = ادامه بدون دیتابیس تأییدشده)"; then continue; fi
    echo "   [!]  بدون تأیید دیتابیس ادامه می‌دهم — ساخت ربات فرزند احتمالاً خطا می‌دهد."
    break
done
SECRET_KEY=$($PHP_BIN -r 'echo bin2hex(random_bytes(16));')

# ۶) ساخت config.php (بدون بازنویسی کانفیگ موجود)
# مقادیر از طریق env به PHP داده می‌شوند و با var_export نوشته می‌شوند تا کاراکترهای
# خاص مثل $ ' \ " در توکن/پسورد باعث خرابی یا تزریق در کانفیگ نشود.
echo ""
if [ -f "$ROOT_DIR/config.php" ]; then
    echo "[!] config.php از قبل وجود دارد — بازنویسی نشد. برای تغییر، دستی ویرایش کن."
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
$PHP_BIN "$ROOT_DIR/tools/set_webhook.php" "$WEBHOOK_URL" || echo "[!]  وبهوک ست نشد (احتمالاً آدرس https/عمومی نیست). بعداً دستی بزن."

# ۹) بررسی template ها
echo ""
echo "[✓] بررسی فایل‌های قالب..."
tpl_ok=1
for f in "templates/faxima/config.php" "templates/mirza/config.php" "templates/faxima/index.php" "templates/mirza/index.php"; do
    if [ -f "$ROOT_DIR/$f" ]; then
        echo "   ✓ $f"
    else
        echo "   [X] $f یافت نشد!"
        tpl_ok=0
    fi
done

# ۱۰) بررسی نهایی: آیا ربات واقعاً اجراست؟
echo ""
echo "[✓] بررسی نهایی اجرای ربات..."
MAIN_TOKEN="$MAIN_TOKEN" EXPECT_URL="$WEBHOOK_URL" $PHP_BIN -r '
$tok = (string) getenv("MAIN_TOKEN");
$expect = (string) getenv("EXPECT_URL");
$api = "https://api.telegram.org/bot".$tok."/";
$me = @json_decode((string) @file_get_contents($api."getMe"), true);
if (empty($me["ok"])) { echo "BOT_DOWN:token\n"; exit(2); }
$wh = @json_decode((string) @file_get_contents($api."getWebhookInfo"), true);
$url = (string) ($wh["result"]["url"] ?? "");
if ($url === "") { echo "BOT_DOWN:webhook\n"; exit(3); }
echo "BOT_UP @".$me["result"]["username"]." webhook=".$url."\n";' && bot_up=1 || bot_up=0

echo ""
echo "========================================="
if [ "$bot_up" = "1" ] && [ "$tpl_ok" = "1" ]; then
    echo "  [OK] نصب کامل شد و ربات اجراست!"
else
    echo "  [!]  نصب انجام شد ولی ربات هنوز بالا نیست:"
    [ "$bot_up" != "1" ] && echo "     - وبهوک ست نیست: اول https/دامنه را درست کن بعد بزن:"
    [ "$bot_up" != "1" ] && echo "       php tools/set_webhook.php ${BASE_URL}/bot.php"
    [ "$tpl_ok" != "1" ] && echo "     - فایل‌های قالب ناقص‌اند (ریپو را کامل clone کن)."
fi
echo "========================================="
echo ""
echo "[i] برای استفاده:"
echo "   1. ربات اصلی را در تلگرام /start بزنید"
echo "   2. آیدی شما به عنوان سوپرادمین ثبت شد"
echo "   3. برای هر کاربر: ابتدا درخواست بده، بعد ادمین تأیید کند"
echo "   4. کرون فرزندها: */5 * * * * php $ROOT_DIR/tools/cron_dispatcher.php"
echo "========================================="
