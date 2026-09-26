#!/bin/bash

# ===== Automatic installer for Botsaz-Faxima (faxima / mirza bots) =====
# Usage: bash tools/install.sh
#
# This script prepares the prerequisites first (PHP + extensions, MySQL connection
# test), then writes config.php and finally verifies the bot is really running.

set -e

# ===== UTF-8 guard (emoji and box-drawing need it) =====
# On a non-UTF-8 terminal every multi-byte character is printed as garbage.
# This message is deliberately ASCII-only so it stays readable even when the
# locale is broken - a Persian warning here would be just as unreadable.
_eff_lc="${LC_ALL:-${LANG:-}}"
case "$_eff_lc" in
    *.[Uu][Tt][Ff]-8*|*.[Uu][Tt][Ff]8*|*[Uu][Tt][Ff]-8*|*[Uu][Tt][Ff]8*)
        ;;                                  # already UTF-8 (en_US.UTF-8 / C.UTF-8)
    *)
        _utf8_locale=""
        if command -v locale >/dev/null 2>&1; then
            # both spellings printed by `locale -a`: C.UTF-8 and C.utf8
            _utf8_locale="$(locale -a 2>/dev/null | grep -iE '^(C|en_US|fa_IR)\.UTF-?8$' | head -n1 || true)"
        fi
        if [ -n "$_utf8_locale" ]; then
            export LC_ALL="$_utf8_locale"
            export LANG="$_utf8_locale"
        else
            echo "!! WARNING: current locale is NOT UTF-8 - emoji/text may look garbled."
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
echo "  🔧 Botsaz-Faxima installer"
echo "========================================="
echo ""

# ---------- helpers ----------
has_cmd() { command -v "$1" &>/dev/null; }

SUDO=""
if [ "$(id -u)" -ne 0 ]; then SUDO="sudo"; fi

# Can we install packages with apt? (root, or sudo available)
can_apt() {
    has_cmd apt-get && { [ "$(id -u)" -eq 0 ] || has_cmd sudo; }
}

ask_yes() { # $1 = question text (default: yes)
    local ans
    # Why two steps? RTL text inside `read -p` is re-ordered by the bidi
    # algorithm: the cursor lands in the wrong place and [Y/n] ends up in the
    # middle of the prompt. Printing the question on its own line and keeping
    # the input prompt pure ASCII makes the cursor position stable.
    printf '%s\n' "$1"
    read -r -p "   [Y/n]: " ans
    [[ "$ans" =~ ^[Nn] ]] && return 1 || return 0
}

apt_install() {
    echo "   ⏳ Installing with apt (sudo may ask for a password)..."
    $SUDO apt-get update -qq
    # shellcheck disable=SC2068
    $SUDO apt-get install -y $@
    hash -r 2>/dev/null || true
}

# ---------- 0) prerequisite: PHP ----------
echo "✅ Checking PHP..."
install_php_if_needed() {
    if has_cmd "$PHP_BIN"; then
        PHP_VER=$($PHP_BIN -r "echo PHP_VERSION_ID;")
        if [ "$PHP_VER" -ge 80100 ]; then
            echo "   PHP $($PHP_BIN -r 'echo PHP_VERSION;') ✔"
            return 0
        fi
        echo "   ⚠️  PHP version is too old: $($PHP_BIN -r 'echo PHP_VERSION;') (need 8.1+)"
    else
        echo "   ⚠️  PHP not found."
    fi
    if can_apt && ask_yes "   Install/upgrade PHP automatically with the required extensions?"; then
        apt_install php php-cli php-curl php-mbstring php-mysql php-sqlite3 php-xml php-zip
    fi
    if ! has_cmd "$PHP_BIN"; then
        echo "❌ PHP is not available. Install it manually:"
        echo "   sudo apt install php php-cli php-curl php-mbstring php-mysql php-sqlite3"
        exit 1
    fi
    PHP_VER=$($PHP_BIN -r "echo PHP_VERSION_ID;")
    if [ "$PHP_VER" -lt 80100 ]; then
        echo "❌ PHP version is lower than 8.1: $($PHP_BIN -r 'echo PHP_VERSION;')"
        echo "   On old Ubuntu install 8.2+ from the ondrej/php PPA."
        exit 1
    fi
    echo "   PHP $($PHP_BIN -r 'echo PHP_VERSION;') ✔"
}
install_php_if_needed

# ---------- 0) prerequisite: PHP extensions ----------
echo "✅ Checking PHP extensions..."
# pairs: extension_name:apt_package_name
# mysqli is listed too: the child templates open MySQL through mysqli (with an
# explicit port), so a missing mysqli breaks bot builds later on.
EXT_PKGS="curl:php-curl mbstring:php-mbstring pdo_mysql:php-mysql mysqli:php-mysql sqlite3:php-sqlite3"
missing_pkgs=""
for pair in $EXT_PKGS; do
    ext="${pair%%:*}"
    pkg="${pair##*:}"
    if ! $PHP_BIN -m | grep -qi "^${ext}$"; then
        echo "   ⚠️  Extension ${ext} is missing."
        missing_pkgs="$missing_pkgs $pkg"
    fi
done
# these two are usually built-in; without them the install is broken
for ext in openssl json; do
    if ! $PHP_BIN -m | grep -qi "^${ext}$"; then
        echo "❌ Critical extension ${ext} is missing from PHP - repair the PHP install."
        exit 1
    fi
done
if [ -n "$missing_pkgs" ]; then
    if can_apt && ask_yes "   Install the missing extensions automatically? ($missing_pkgs )"; then
        # shellcheck disable=SC2086
        apt_install $missing_pkgs
    else
        echo "❌ Required extensions are not installed. Install them manually:"
        echo "   sudo apt install$missing_pkgs"
        exit 1
    fi
    # strict re-check so we do not fail further down
    still_missing=""
    for pair in $EXT_PKGS; do
        ext="${pair%%:*}"
        if ! $PHP_BIN -m | grep -qi "^${ext}$"; then still_missing="$still_missing $ext"; fi
    done
    if [ -n "$still_missing" ]; then
        echo "❌ Still missing these extensions:$still_missing"
        echo "   The web server / CLI may use a different php.ini - check it."
        exit 1
    fi
fi
echo "   Extensions ✔ (curl, mbstring, pdo_mysql, mysqli, sqlite3)"

# ---------- 0) optional: phpMyAdmin ----------
# Installs the web UI for MySQL. It is optional and it asks first, because a
# publicly reachable phpMyAdmin is an attack surface - the installer says so.
install_phpmyadmin() {
    # already there?
    if has_cmd phpmyadmin || [ -d /usr/share/phpmyadmin ]; then
        echo "✅ phpMyAdmin already installed ✔"
        return 0
    fi
    if ! can_apt; then
        echo "⚠️  apt is not available - skipping phpMyAdmin."
        return 0
    fi
    if ! ask_yes "   Install phpMyAdmin? (optional web UI for MySQL - keep it access-restricted)"; then
        echo "   phpMyAdmin skipped"
        return 0
    fi
    echo "   ⏳ Installing phpMyAdmin..."
    # Pre-seed debconf so the package never blocks on an interactive question.
    # We deliberately do NOT let it reconfigure a web server here - step 6 wires
    # the vhost itself, which keeps the script deterministic.
    if command -v debconf-set-selections >/dev/null 2>&1; then
        printf '%s\n' \
            'phpmyadmin phpmyadmin/reconfigure-webserver select none' \
            'phpmyadmin phpmyadmin/mysql-admin-install boolean false' \
            'phpmyadmin phpmyadmin/mysql/app-pass password' \
            'phpmyadmin phpmyadmin/app-password-confirm password' \
            | $SUDO debconf-set-selections || true
    fi
    if DEBIAN_FRONTEND=noninteractive $SUDO apt-get install -y phpmyadmin; then
        echo "   phpMyAdmin installed ✔ (served at /phpmyadmin once the vhost exists)"
    else
        echo "   ⚠️  phpMyAdmin package failed to install - skipping it (not fatal)."
    fi
}
install_phpmyadmin

# ---------- 0) prerequisite: system tools ----------
for cmd in git curl; do
    if ! has_cmd "$cmd"; then
        echo "   ⚠️  Required tool $cmd is missing."
        if can_apt && ask_yes "   Install $cmd automatically?"; then
            apt_install "$cmd"
        else
            echo "❌ Cannot continue without $cmd. Install it and re-run."
            exit 1
        fi
    fi
done

# 2) main bot token
echo ""
echo "========================================="
echo "  🔑 Main bot token (the bot-builder)"
echo "========================================="
while true; do
    printf '    Token from @BotFather:\n'
    read -r -p "    > " MAIN_TOKEN
    if [[ "$MAIN_TOKEN" =~ ^[0-9]{6,12}:[A-Za-z0-9_-]{35}$ ]]; then break; fi
    printf '   [X] Wrong token format (it must look like 123456:ABC... - 35 chars). Try again:\n'
done
# live token check (warning only - we continue if the network is down)
echo "   ⏳ Checking the token against Telegram..."
if ! MAIN_TOKEN="$MAIN_TOKEN" $PHP_BIN -r '
$tok = (string) getenv("MAIN_TOKEN");
$j = @json_decode((string) @file_get_contents("https://api.telegram.org/bot".$tok."/getMe"), true);
if (empty($j["ok"])) { fwrite(STDERR, "getMe failed\n"); exit(1); }
echo "   🤖 @".$j["result"]["username"]." ✔\n";'; then
    echo "   ⚠️  Telegram did not answer (wrong token, or network/filter problem)."
    ask_yes "   Continue with this token anyway?" || exit 1
fi

# 3) super admin numeric id
echo ""
echo "========================================="
echo "  👤 Super admin numeric ID"
echo "========================================="
printf '    Numeric admin ID (from @userinfobot):\n'
read -r -p "    > " SUPER_ADMIN
if ! [[ "$SUPER_ADMIN" =~ ^[0-9]{5,}$ ]]; then
    printf '   [X] Enter a valid numeric ID.\n'
    exit 1
fi

# 4) base URL
echo ""
echo "========================================="
echo "  🌐 Project base URL (base_url)"
echo "========================================="
printf '    URL (e.g. https://domain.com/botsaz):\n'
read -r -p "    > " BASE_URL
if [ -z "$BASE_URL" ]; then
    BASE_URL="http://localhost/botsaz-faxima"
    echo "   ⚠️  Using the default: $BASE_URL"
fi
BASE_URL="$(echo "$BASE_URL" | sed 's:/*$::')"
if ! [[ "$BASE_URL" =~ ^https:// ]]; then
    echo "   ⚠️  The URL is not https - Telegram rejects http webhooks and the bot will NOT run!"
    echo "   (If you have a domain + SSL, make sure to use exactly that.)"
fi

# 5) MySQL credentials + real connection / CREATE DATABASE test
echo ""
echo "========================================="
echo "  🗄️  MySQL database credentials"
echo "========================================="
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

    echo "   ⏳ Testing connection and CREATE DATABASE permission..."
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
        echo "   Connection + CREATE DATABASE permission ✔"
        break
    fi
    echo "   ❌ Connection or database permission failed (error above)."
    echo "   Botsaz builds a separate database per child bot; without this right we cannot continue."
    if ask_yes "   Re-enter the credentials? (no = continue without a verified database)"; then continue; fi
    echo "   ⚠️  Continuing without a verified database - child bot builds will probably fail."
    break
done
SECRET_KEY=$($PHP_BIN -r 'echo bin2hex(random_bytes(16));')

# 6) write config.php (never overwrites an existing config)
# Values are passed to PHP through the environment and written with var_export so
# special characters like $ ' \ " in the token/password cannot break or inject
# into the generated config.
echo ""
if [ -f "$ROOT_DIR/config.php" ]; then
    echo "⚠️ config.php already exists - NOT overwritten. Edit it manually to change anything."
else
    echo "✅ Writing config.php..."
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
    echo "   config.php written ✔"
fi

# 7) run the initial installer
echo ""
echo "✅ Running the initial installer..."
$PHP_BIN "$ROOT_DIR/tools/install.php"

# 8) register the main bot webhook
echo ""
echo "✅ Setting the webhook..."
WEBHOOK_URL="${BASE_URL}/bot.php"
$PHP_BIN "$ROOT_DIR/tools/set_webhook.php" "$WEBHOOK_URL" || echo "⚠️  Webhook not set (the URL is probably not https/public). Set it manually later."

# 9) template files check
echo ""
echo "✅ Checking template files..."
tpl_ok=1
for f in "templates/faxima/config.php" "templates/mirza/config.php" "templates/faxima/index.php" "templates/mirza/index.php"; do
    if [ -f "$ROOT_DIR/$f" ]; then
        echo "   ✔ $f"
    else
        echo "   ❌ $f not found!"
        tpl_ok=0
    fi
done

# 10) final check: is the bot really running?
echo ""
echo "✅ Final bot check..."
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
    echo "  ✅ Install finished and the bot is running!"
else
    echo "  ⚠️  Install finished but the bot is not up yet:"
    [ "$bot_up" != "1" ] && echo "     - Webhook not set: fix https/domain first, then run:"
    [ "$bot_up" != "1" ] && echo "       php tools/set_webhook.php ${BASE_URL}/bot.php"
    [ "$tpl_ok" != "1" ] && echo "     - Template files are incomplete (clone the repo fully)."
fi
echo "========================================="
echo ""
echo "📌 How to use it:"
echo "   1. Open the main bot in Telegram and press /start"
echo "   2. Your ID was registered as the super admin"
echo "   3. Per user: request access first, then approve it as admin"
echo "   4. Child cron: */5 * * * * php $ROOT_DIR/tools/cron_dispatcher.php"
echo "========================================="
