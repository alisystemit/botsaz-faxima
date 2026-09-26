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

# ---------- 4b) SSL certificate for the domain found in base_url ----------
# Telegram only accepts a webhook over https, so as soon as a real domain is
# known we try to get a certificate for it - right after the address was typed.
# Everything here is best-effort on purpose: DNS may not be pointing at this
# server yet, so a failure warns and the installer continues instead of dying.
echo ""
echo "==========================================="
echo "  🔒 SSL certificate (Let's Encrypt)"
echo "==========================================="
issue_ssl() {
    # hostname = scheme and path stripped away: https://a.com/x -> a.com
    local host
    host="$(printf '%s' "$BASE_URL" | sed -E 's#^[A-Za-z][A-Za-z0-9+.-]*://##' | cut -d/ -f1 | cut -d: -f1)"

    if [ -z "$host" ] || [ "$host" = "localhost" ] || [ "$host" = "127.0.0.1" ] \
        || printf '%s' "$host" | grep -Eq '^[0-9]+(\.[0-9]+){3}$'; then
        echo "   ⚠️  '$host' is not a public domain - no certificate requested."
        echo "   Set base_url to a real domain (https://example.com/botsaz) to get SSL."
        return 0
    fi

    # certbot can be installed here too, so this step still works on its own
    if ! has_cmd certbot; then
        echo "   ⚠️  certbot is not installed."
        if can_apt && ask_yes "   Install certbot now? (required to issue the certificate)"; then
            apt_install certbot || true
            # the matching plugin is what lets certbot edit the vhost for us
            if has_cmd apache2 || has_cmd httpd; then apt_install python3-certbot-apache || true; fi
            if has_cmd nginx; then apt_install python3-certbot-nginx || true; fi
        fi
    fi
    if ! has_cmd certbot; then
        echo "   ⚠️  Skipping SSL for now - install certbot and re-run to get a certificate."
        return 0
    fi

    if certbot certificates 2>/dev/null | grep -q "Domains:.*$host"; then
        echo "   ✔ A certificate for $host already exists - skipping issuance."
        return 0
    fi

    echo "   ⏳ Requesting a Let's Encrypt certificate for $host ..."
    echo "      The domain must already point to this server and port 80 must be reachable."
    local rc=0
    if has_cmd apache2 || has_cmd httpd; then
        certbot --apache -d "$host" --non-interactive --agree-tos --register-unsafely-without-email || rc=$?
    elif has_cmd nginx; then
        certbot --nginx -d "$host" --non-interactive --agree-tos --register-unsafely-without-email || rc=$?
    else
        certbot certonly --webroot -w "$ROOT_DIR" -d "$host" --non-interactive --agree-tos --register-unsafely-without-email || rc=$?
    fi

    if [ "$rc" = "0" ]; then
        echo "   🔒 Certificate issued for $host ✔"
        echo "      Renewal is automatic (certbot installs its own timer/cron)."
    else
        echo "   ⚠️  Could not issue the certificate (exit $rc)."
        echo "      Usual causes: DNS not pointing here, port 80 blocked, or a local machine."
        echo "      Re-run it later with: sudo certbot --apache -d $host"
    fi
}
issue_ssl

# ---------- 5b) web server vhost + SSL (Apache or nginx) ----------
# One vhost serves EVERY bot: Manager::webhookUrl() builds child webhooks as
#     <base_url>/bots/<folder>/<entry>
# so the main bot and every faxima/mirza child hang off this same domain and
# this same DocumentRoot. Configure it once and all of them can deliver
# commands to Telegram.
#
# AllowOverride All and mod_rewrite are not cosmetic here - the project's
# .htaccess files are what block /config.php, /src, /tools, /templates and
# /data. Without them the database file and the source code become publicly
# downloadable, and the child webhook paths would 404.
echo ""
echo "==========================================="
echo "  🌍 Web server vhost + SSL"
echo "==========================================="
configure_vhost() {
    local host cert_ok=0 fpm_sock="" s
    host="$(printf '%s' "$BASE_URL" | sed -E 's#^[A-Za-z][A-Za-z0-9+.-]*://##' | cut -d/ -f1 | cut -d: -f1)"
    [ -z "$host" ] && host="localhost"

    if [ -f "/etc/letsencrypt/live/$host/fullchain.pem" ] && [ -f "/etc/letsencrypt/live/$host/privkey.pem" ]; then
        cert_ok=1
        echo "   ✔ Let's Encrypt certificate found for $host"
    else
        echo "   ⚠️  No certificate for $host yet - only the HTTP block will be written."
        echo "      Re-run this installer once SSL succeeds to add the HTTPS block."
    fi

    # nginx must be pointed at the real php-fpm socket; the path differs per version
    for s in /run/php/*.sock /var/run/php/*.sock; do
        if [ -S "$s" ]; then fpm_sock="$s"; break; fi
    done

    if has_cmd apache2 || has_cmd httpd; then
        local ap_conf="/etc/apache2/sites-available/botsaz.conf"
        if [ -f "$ap_conf" ]; then
            echo "   ✔ Apache vhost already exists ($ap_conf) - left untouched."
            return 0
        fi
        if ! ask_yes "   Create the Apache vhost for $host -> $ROOT_DIR ?"; then
            echo "   vhost skipped"
            return 0
        fi
        $SUDO mkdir -p /etc/apache2/sites-available 2>/dev/null || true
        {
            echo "<VirtualHost *:80>"
            echo "    ServerName $host"
            echo "    DocumentRoot \"$ROOT_DIR\""
            echo "    <Directory \"$ROOT_DIR\">"
            echo "        Options Indexes FollowSymLinks"
            echo "        AllowOverride All"
            echo "        Require all granted"
            echo "    </Directory>"
            echo "</VirtualHost>"
        } | $SUDO tee "$ap_conf" > /dev/null
        if [ "$cert_ok" = "1" ]; then
            {
                echo "<VirtualHost *:443>"
                echo "    ServerName $host"
                echo "    DocumentRoot \"$ROOT_DIR\""
                echo "    SSLEngine on"
                echo "    SSLCertificateFile      /etc/letsencrypt/live/$host/fullchain.pem"
                echo "    SSLCertificateKeyFile   /etc/letsencrypt/live/$host/privkey.pem"
                echo "    SSLCertificateChainFile /etc/letsencrypt/live/$host/chain.pem"
                echo "    <Directory \"$ROOT_DIR\">"
                echo "        Options Indexes FollowSymLinks"
                echo "        AllowOverride All"
                echo "        Require all granted"
                echo "    </Directory>"
                echo "</VirtualHost>"
            } | $SUDO tee -a "$ap_conf" > /dev/null
        fi
        if has_cmd a2enmod; then
            # rewrite powers .htaccess, ssl powers :443
            $SUDO a2enmod ssl rewrite headers >/dev/null 2>&1 || true
        fi
        if has_cmd a2ensite; then $SUDO a2ensite botsaz >/dev/null 2>&1 || true; fi
        # config test FIRST - a bad vhost must never take a running server down
        local ap_out="" ap_rc=1
        if has_cmd apache2ctl; then
            ap_out="$($SUDO apache2ctl configtest 2>&1)" && ap_rc=0 || ap_rc=1
        elif has_cmd apachectl; then
            ap_out="$($SUDO apachectl configtest 2>&1)" && ap_rc=0 || ap_rc=1
        else
            ap_out="apache2ctl/apachectl not found"
        fi
        if [ "$ap_rc" = "0" ]; then
            if $SUDO systemctl reload apache2 >/dev/null 2>&1 \
                || $SUDO systemctl reload httpd >/dev/null 2>&1 \
                || $SUDO service apache2 reload >/dev/null 2>&1; then
                echo "   ✔ Apache vhost installed and reloaded"
            else
                echo "   ⚠️  Vhost written but no reload command worked - reload Apache manually."
            fi
        else
            echo "   ⚠️  Apache config test failed - undoing the vhost so the server keeps running:"
            printf '%s\n' "$ap_out" | head -n 5
            if has_cmd a2dissite; then $SUDO a2dissite -f botsaz >/dev/null 2>&1 || true; fi
            $SUDO rm -f "$ap_conf"
        fi
        return 0
    fi

    if has_cmd nginx; then
        local ng_conf="/etc/nginx/sites-available/botsaz.conf"
        if [ -f "$ng_conf" ]; then
            echo "   ✔ nginx vhost already exists ($ng_conf) - left untouched."
            return 0
        fi
        if ! ask_yes "   Create the nginx vhost for $host -> $ROOT_DIR ?"; then
            echo "   vhost skipped"
            return 0
        fi
        if [ -z "$fpm_sock" ]; then
            echo "   ⚠️  No PHP-FPM socket under /run/php - install php-fpm or PHP will not execute."
        fi
        $SUDO mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled 2>/dev/null || true

        # the application block is reused for :80 (no cert yet) and :443 (cert present)
        _nginx_app() {
            echo "    root \"$ROOT_DIR\";"
            echo "    index index.php index.html;"
            echo "    client_max_body_size 64m;"
            echo "    location / { try_files \$uri \$uri/ /index.php?\$query_string; }"
            if [ -n "$fpm_sock" ]; then
                echo "    location ~ \.php\$ {"
                echo "        include snippets/fastcgi-php.conf;"
                echo "        fastcgi_pass unix:$fpm_sock;"
                echo "    }"
            fi
            echo "    location ~ /\.ht { deny all; }"
        }
        {
            echo "# Managed by botsaz install.sh - do not edit by hand"
            if [ "$cert_ok" = "1" ]; then
                echo "server {"
                echo "    listen 80;"
                echo "    listen [::]:80;"
                echo "    server_name $host;"
                echo "    return 301 https://$host\$request_uri;"
                echo "}"
                echo "server {"
                echo "    listen 443 ssl;"
                echo "    listen [::]:443 ssl;"
                echo "    server_name $host;"
                echo "    ssl_certificate     /etc/letsencrypt/live/$host/fullchain.pem;"
                echo "    ssl_certificate_key /etc/letsencrypt/live/$host/privkey.pem;"
                _nginx_app
                echo "}"
            else
                echo "server {"
                echo "    listen 80;"
                echo "    listen [::]:80;"
                echo "    server_name $host;"
                _nginx_app
                echo "}"
            fi
        } | $SUDO tee "$ng_conf" > /dev/null
        $SUDO ln -sf "$ng_conf" /etc/nginx/sites-enabled/botsaz.conf 2>/dev/null || true
        # never reload nginx on a broken config - roll back instead
        local ng_out="" ng_rc=1
        ng_out="$($SUDO nginx -t 2>&1)" && ng_rc=0 || ng_rc=1
        if [ "$ng_rc" = "0" ]; then
            if $SUDO systemctl reload nginx >/dev/null 2>&1 || $SUDO service nginx reload >/dev/null 2>&1; then
                echo "   ✔ nginx vhost installed and reloaded"
            else
                echo "   ⚠️  Vhost written but nginx could not be reloaded - reload it manually."
            fi
        else
            echo "   ⚠️  nginx config test failed - undoing the vhost so the server keeps running:"
            printf '%s\n' "$ng_out" | head -n 5
            $SUDO rm -f /etc/nginx/sites-enabled/botsaz.conf "$ng_conf"
        fi
        return 0
    fi

    echo "   ⚠️  Neither Apache nor nginx is installed - skipping the vhost."
}
configure_vhost

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

# ---------- 6b) SHOW the MySQL credentials and VERIFY them from the file ----------
# Why read them back from config.php instead of echoing $DB_* here? Because
# config.php is the single source of truth: Manager::createDatabase(),
# patchFaximaConfig() and patchMirzaConfig() all copy db_host / db_port /
# db_user / db_pass out of that file into every child bot. Printing and testing
# exactly what the file contains guarantees that what you see is what the bots
# will use - and a wrong credential fails HERE instead of halfway through a
# bot build.
echo ""
echo "==========================================="
echo "  🔐 MySQL credentials stored in config.php"
echo "==========================================="
if CFG_FILE="$ROOT_DIR/config.php" $PHP_BIN -r '
$f = (string) getenv("CFG_FILE");
if (!is_file($f)) { fwrite(STDERR, "[X] config.php not found\n"); exit(1); }
$cfg = @require $f;
if (!is_array($cfg)) { fwrite(STDERR, "[X] config.php does not return an array\n"); exit(1); }
$labels = array("db_host"=>"Host", "db_port"=>"Port", "db_user"=>"User",
                "db_pass"=>"Password", "db_prefix"=>"Prefix");
foreach ($labels as $k => $lab) {
    $v = (string)($cfg[$k] ?? "");
    if ($v === "") $v = "(empty)";
    printf("  %-9s: %s\n", $lab, $v);
}
echo "  ----------------------------------------------------\n";
try {
    $pdo = new PDO(
        "mysql:host=".($cfg["db_host"] ?? "").";port=".(int)($cfg["db_port"] ?? 3306).";charset=utf8mb4",
        (string)($cfg["db_user"] ?? ""), (string)($cfg["db_pass"] ?? ""),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8)
    );
    $tmp = "botsaz_cfgtest_" . bin2hex(random_bytes(3));
    $pdo->exec("CREATE DATABASE `$tmp` CHARACTER SET utf8mb4");
    $pdo->exec("DROP DATABASE `$tmp`");
    echo "  [OK] config.php connects AND can CREATE DATABASE\n";
    echo "  These exact credentials are copied into both child bots\n";
    echo "  (faxima + mirza), so their installs will not fail on MySQL.\n";
} catch (Throwable $e) {
    echo "  [X] config.php credentials do NOT work: " . $e->getMessage() . "\n";
    echo "  Child bot builds WILL fail - fix config.php and re-run this installer.\n";
    exit(1);
}'; then
    echo "==========================================="
else
    echo "==========================================="
    echo "  ❌ config.php verification FAILED - aborting."
    echo "==========================================="
    exit 1
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
