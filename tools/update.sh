#!/usr/bin/env bash
# ===== بروزرسانی ربات‌ساز از گیت‌هاب =====
# استفاده: bash tools/update.sh [--dry-run] [--no-restart] [--force]
#
# این اسکریپت فقط فایل‌های کد را آپدیت می‌کند.
# فایل‌های کانفیگ (config.php, bots/*/config.php) هرگز تغییر نمی‌کنند.
# فایل‌های داده (data/, bots/*/states) حفظ می‌شوند.
#
# مراحل:
#   ۰. پیش‌بینی (git، اینترنت، فضا) + قفل اجرا
#   ۱. بک‌آپ config files (۳ نسخه آخر نگه داشته می‌شود)
#   ۲. git fetch + merge با --ff-only (درخت کثیف = abort، نه merge کور)
#   ۳. بررسی و بازگردانی config
#   ۳c. استقرار به پوشهٔ وب‌سرور (اگر جداست): rsync از سورس به /var/www/...
#   ۴. بازسازی .htaccess گمشده + اجرای install.php (مایگریشن‌ها)
#   ۵. فیکس /root permissions
#   ۶. ریلود vhost
#   ۷. فیکس systemd sandbox (همه یونیت‌ها، هر دو پراپرتی)
#   ۸. فیکس PCRE JIT
#   ۹. ریستارت سرویس‌ها (یک دور) + warmup و re-confirm وبهوک
#   ۱۰. تست سلامت
#   ۱۱. تأیید وبهوک
#   ۱۲. نوتیفیکیشن تلگرام به ادمین
#
# آپشن‌ها:
#   --dry-run     فقط نشان می‌دهد چه چیزی می‌آید، تغییری نمی‌دهد
#   --no-restart  سرویس‌ها را ری‌استارت نمی‌کند (و وبهوک re-confirm نمی‌شود)
#   --force       پیش‌بینی اینترنت/فضا را رد می‌کند (محافظت درخت کثیف همیشه فعال است)
#   --web         مثل --no-restart + بدون ریلود vhost/systemd/ری‌استارت سرویس؛
#                 برای اجرای داخل ربات (دکمهٔ ⬆️ آپدیت) تا خودِ درخواست نكشد

# ===== آپشن‌ها =====
DRY_RUN=0
NO_RESTART=0
FORCE=0
WEB=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --no-restart) NO_RESTART=1 ;;
        --force) FORCE=1 ;;
        # اجرا از داخل خودِ ربات (دکمهٔ ⬆️ آپدیت): ری‌استارت سرویس، ریلود
        # vhost و هر چیزی که درخواست HTTP فعلی را بکشد حتماً رد می‌شود.
        --web) WEB=1; NO_RESTART=1 ;;
    esac
done

# بدون set -e: هر خطای مهم صریح چک می‌شود تا رفتار در همه مسیرها معلوم باشد.

# ===== رنگ‌ها =====
R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; B='\033[1;34m'; NC='\033[0m'
ok()  { echo -e "${G}✔${NC} $1"; }
fail() { echo -e "${R}✘${NC} $1"; }
warn() { echo -e "${Y}⚠️${NC} $1"; }
step() { echo -e "\n${B}━━━ $1 ━━━${NC}"; }

# ===== تعیین ROOT_DIR =====
# حالت ۱: bash tools/update.sh  → BASH_SOURCE[0] دقیق مسیر رو می‌ده
# حالت ۲: ssh root@server 'bash -s' < tools/update.sh  → BASH_SOURCE تهی هست
# حالت ۳: ./tools/update.sh   → BASH_SOURCE[0] = ./tools/update.sh
if [ -z "${BASH_SOURCE[0]:-}" ] || [ ! -f "${BASH_SOURCE[0]}" ]; then
    # از stdin خوانده شده - از مسیر فعلی استفاده کن
    # باید از داخل پوشه پروژه اجرا شده باشد
    ROOT_DIR="$(pwd)"
    warn "Running from stdin - using PWD as ROOT_DIR: $ROOT_DIR"
else
    ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT_DIR" || exit 1

# ===== مالک متفاوت ریپو (اجرای وب به‌جای همان کاربر گیت) =====
# وقتی www-data/nginx اسکریپت را اجرا می‌کند، git همهٔ کارها را با
# «detected dubious ownership» رد می‌کند و آپدیت از وسط می‌شکند.
# صریح کردن safe.directory با env، بدون دست‌زدن به ~/.gitconfig، همین‌جا حلش می‌کند.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0="safe.directory"
export GIT_CONFIG_VALUE_0="$ROOT_DIR"

# ===== ۰b. تشخیص چیدمان استقرار (کجا زنده است؟ کجا سورس است؟) =====
# معماری‌ای که این آپدیتر پشتیبانی می‌کند (و دلیل «آپدیت شد ولی ربات عوض نشد»):
#
#   SRC_DIR  = پوشه‌ای که این اسکریپت از آن اجرا شده و git دارد
#              (معمولاً /root/botsaz-faxima — فقط برای گرفتن سورس تازه)
#   LIVE_DIR = پوشه‌ای که وب‌سرور واقعاً سرو می‌کند
#              (معمولاً /var/www/botsaz-faxima — جایی که تلگرام می‌زندش)
#
# اگر این دو یکی باشند، همه‌چیز مثل قبل تک‌پوشه‌ای کار می‌کند. اگر دو تا باشند:
#   • تنظیمات و دیتابیس از LIVE گرفته و داخل SRC جایگذاری می‌شود
#   • کدِ SRC با rsync به LIVE می‌رود (config.php و data/ و bots/ استثنا)
#   • مایگریشن‌ها و پرمیشن‌ها داخل LIVE اجرا می‌شوند، نه SRC
#   • در پایان می‌شود SRC را پاک کرد (--purge-src) تا سورس+رازها زیر /root نماند
step "Step 0b: Detecting the live deployment directory..."
SRC_DIR="$ROOT_DIR"
LIVE_DIR=""
WEB_USER=""

detect_web_user() {
    for u in www-data nginx apache httpd; do
        id -u "$u" >/dev/null 2>&1 && { printf '%s' "$u"; return 0; }
    done
    return 1
}
WEB_USER="$(detect_web_user || true)"

# DocumentRoot ویhostهای این پروژه (آپاچی، بعد nginx)
docroot_of() {
    local c d
    for c in /etc/apache2/sites-available/botsaz.conf /etc/apache2/sites-available/*.conf; do
        [ -f "$c" ] || continue
        grep -q "botsaz\|$SRC_DIR" "$c" 2>/dev/null || continue
        d="$(sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' "$c" 2>/dev/null | head -n1)"
        [ -n "$d" ] && { printf '%s' "$d"; return 0; }
    done
    for c in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
        [ -f "$c" ] || continue
        grep -q "botsaz\|$SRC_DIR" "$c" 2>/dev/null || continue
        d="$(sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;]*\);.*/\1/p' "$c" 2>/dev/null | head -n1)"
        [ -n "$d" ] && { printf '%s' "$d"; return 0; }
    done
    return 1
}
_doc="$(docroot_of 2>/dev/null || true)"
if [ -n "$_doc" ] && [ -d "$_doc" ] && [ -f "$_doc/bot.php" ]; then
    LIVE_DIR="$(cd "$_doc" && pwd -P)"
elif [ -f /var/www/botsaz-faxima/bot.php ]; then
    # vhost پیدا نشد ولی یک کپیِ کامل در مسیر متداول آپاچی هست
    LIVE_DIR="$(cd /var/www/botsaz-faxima && pwd -P)"
fi
SRC_REAL="$(cd "$SRC_DIR" && pwd -P)"
[ -z "$LIVE_DIR" ] && LIVE_DIR="$SRC_REAL"

if [ "$LIVE_DIR" = "$SRC_REAL" ]; then
    LIVE_DIR="$SRC_REAL"          # حالت تک‌پوشه‌ای: استقرار و کانفیگ یکی است
    ok "single-directory layout: the web server already serves $LIVE_DIR"
else
    warn "split layout detected"
    echo "    source (git)  : $SRC_REAL"
    echo "    live (served) : $LIVE_DIR"
    echo "    the bot reads $LIVE_DIR - that is the copy that must get the new code,"
    echo "    and $LIVE_DIR/config.php is the settings that must survive every update."
fi
[ -n "$WEB_USER" ] || warn "no www-data/nginx/apache user found - permission fixes will be skipped"

# نسخهٔ روی دیسک (برای گزارش و تشخیص کدِ کهنه در انتها)
APP_VER="$(grep -m1 'APP_VERSION' "$SRC_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
[ -n "$APP_VER" ] && echo "    version in the source tree: $APP_VER"

# ===== ۰c. واردکردن تنظیمات زنده به سورس (تا استقرار، کانفیگ را پاک نکند) =====
# کاربرِ این مسیر: «مشخصات کامل را از پوشهٔ زنده بگیر و داخل فایل‌های تازه
# جایگذاری کن». چون rsync کانفیگ را استثنا می‌کند، لازم است سورس هم همان
# تنظیمات را داشته باشد تا اگر روزی همین پوشه شد زنده، ربات بی‌تنظیمات نماند.
if [ "$LIVE_DIR" != "$SRC_REAL" ]; then
    if [ -f "$LIVE_DIR/config.php" ]; then
        if [ -f "$SRC_DIR/config.php" ] && cmp -s "$LIVE_DIR/config.php" "$SRC_DIR/config.php"; then
            ok "source config.php already identical to the live one"
        else
            cp -a "$LIVE_DIR/config.php" "$SRC_DIR/config.php" 2>/dev/null \
                && ok "imported the live config.php into the source tree" \
                || warn "could not import the live config.php"
        fi
    else
        warn "$LIVE_DIR/config.php does not exist - the live site has no settings to copy"
    fi
    shopt -s nullglob
    _n=0
    for cf in "$LIVE_DIR"/bots/*/config.php; do
        _slug="$(basename "$(dirname "$cf")")"
        mkdir -p "$SRC_DIR/bots/$_slug" 2>/dev/null
        cp -a "$cf" "$SRC_DIR/bots/$_slug/config.php" 2>/dev/null && _n=$((_n + 1))
    done
    shopt -u nullglob
    [ "$_n" -gt 0 ] && ok "imported $_n child bot config.php from the live tree"
    unset _n _slug cf
fi
# ===== ۰. پیش‌بینی =====
step "Step 0: Pre-flight checks..."

# ===== ۰-Added: دریافت مشخصات و تنظیمات قبل از آپدیت =====
# این بخش مراحل کاربر درخواست شده را انجام می‌دهد:
#   ۱. دریافت مشخصات از /var/www و جایگزینی کانفیگ‌ها
#   ۲. کپی از /root/botsaz-faxima به /var/www/botsaz-faxima
#   ۳. جایگزینی مشخصات دیتابیس
#   ۴. تنظیمات وی‌هاست
#   ۵. وب‌هوک آنلاک
#   ۶. بررسی نیازهای توسعه
#   ۷. پاک کردن فایل‌های روت برای امنیت
step "Step 0-Added: User-requested update procedure..."

# Detect layout first (needed for paths)
if [ -z "${LIVE_DIR:-}" ] || [ -z "${SRC_DIR:-}" ]; then
    # Re-run detection if not set
    SRC_DIR="$ROOT_DIR"
    LIVE_DIR=""
    WEB_USER=""
    detect_web_user() {
        for u in www-data nginx apache httpd; do
            id -u "$u" >/dev/null 2>&1 && { printf '%s' "$u"; return 0; }
        done
        return 1
    }
    WEB_USER="$(detect_web_user || true)"
    docroot_of() {
        local c d
        for c in /etc/apache2/sites-available/botsaz.conf /etc/apache2/sites-available/*.conf; do
            [ -f "$c" ] || continue
            grep -q "botsaz\|$SRC_DIR" "$c" 2>/dev/null || continue
            d="$(sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' "$c" 2>/dev/null | head -n1)"
            [ -n "$d" ] && { printf '%s' "$d"; return 0; }
        done
        for c in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
            [ -f "$c" ] || continue
            grep -q "botsaz\|$SRC_DIR" "$c" 2>/dev/null || continue
            d="$(sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;]*\);.*/\1/p' "$c" 2>/dev/null | head -n1)"
            [ -n "$d" ] && { printf '%s' "$d"; return 0; }
        done
        return 1
    }
    _doc="$(docroot_of 2>/dev/null || true)"
    if [ -n "$_doc" ] && [ -d "$_doc" ] && [ -f "$_doc/bot.php" ]; then
        LIVE_DIR="$(cd "$_doc" && pwd -P)"
    elif [ -f /var/www/botsaz-faxima/bot.php ]; then
        LIVE_DIR="$(cd /var/www/botsaz-faxima && pwd -P)"
    fi
fi
SRC_REAL="$(cd "$SRC_DIR" && pwd -P)"
[ -z "$LIVE_DIR" ] && LIVE_DIR="$SRC_REAL"

# Step 1: Get specs from LIVE_DIR and replace configs in SRC
if [ "$LIVE_DIR" != "$SRC_REAL" ] && [ -f "$LIVE_DIR/config.php" ]; then
    step "Importing live config.php settings into source tree..."
    
    # Extract DB config from live config
    LIVE_DB_HOST=$(grep -oP "db_host' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "127.0.0.1")
    LIVE_DB_PORT=$(grep -oP "db_port' => \K[0-9]+" "$LIVE_DIR/config.php" 2>/dev/null || echo "3306")
    LIVE_DB_USER=$(grep -oP "db_user' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "root")
    LIVE_DB_PASS=$(grep -oP "db_pass' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "")
    LIVE_DB_PREFIX=$(grep -oP "db_prefix' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "botsaz_")
    
    # Apply DB settings to source config if placeholders exist
    if [ -f "$SRC_DIR/config.example.php" ]; then
        # Check if source config has placeholders that need filling
        if grep -q "PUT_MAIN_BOT_TOKEN_HERE" "$SRC_DIR/config.php" 2>/dev/null; then
            warn "Source config.php has placeholder tokens - consider running install.php"
        fi
        
        # Update DB settings in source config.php if they differ
        if [ "$LIVE_DB_HOST" != "127.0.0.1" ] || [ "$LIVE_DB_PORT" != "3306" ] || [ "$LIVE_DB_USER" != "root" ]; then
            # Apply database host/port/user changes using sed
            sed -i "s|'db_host' => 'K[^']*'|'db_host' => '$LIVE_DB_HOST',|" "$SRC_DIR/config.php" 2>/dev/null || \
                sed -i 's|db_host => .*|db_host => "'$LIVE_DB_HOST'",|' "$SRC_DIR/config.php" 2>/dev/null
            sed -i "s|'db_port' => K[0-9]*|'db_port' => $LIVE_DB_PORT,|" "$SRC_DIR/config.php" 2>/dev/null || \
                sed -i 's|db_port => .*|db_port => '$LIVE_DB_PORT',|' "$SRC_DIR/config.php" 2>/dev/null
            sed -i "s|'db_user' => 'K[^']*'|'db_user' => '$LIVE_DB_USER',|" "$SRC_DIR/config.php" 2>/dev/null || \
                sed -i "s|db_user => .*|db_user => '$LIVE_DB_USER',|" "$SRC_DIR/config.php" 2>/dev/null
            sed -i "s|'db_pass' => K[^']*'|'db_pass' => '$LIVE_DB_PASS',|" "$SRC_DIR/config.php" 2>/dev/null || \
                sed -i "s|db_pass => .*|db_pass => '$LIVE_DB_PASS',|" "$SRC_DIR/config.php" 2>/dev/null
            sed -i "s|'db_prefix' => K[^']*'|'db_prefix' => '$LIVE_DB_PREFIX',|" "$SRC_DIR/config.php" 2>/dev/null || \
                sed -i "s|db_prefix => .*|db_prefix => '$LIVE_DB_PREFIX',|" "$SRC_DIR/config.php" 2>/dev/null
            ok "Database settings imported from $LIVE_DIR to $SRC_DIR"
        fi
    fi
    
    # Also copy child bot configs from live to source
    shopt -s nullglob
    _n=0
    for cf in "$LIVE_DIR"/bots/*/config.php; do
        _slug="$(basename "$(dirname "$cf")")"
        mkdir -p "$SRC_DIR/bots/$_slug" 2>/dev/null
        if [ -f "$cf" ]; then
            cp -a "$cf" "$SRC_DIR/bots/$_slug/config.php" 2>/dev/null && _n=$((_n + 1))
        fi
    done
    shopt -u nullglob
    [ "$_n" -gt 0 ] && ok "imported $_n child bot config files from live tree"
    unset _n _slug cf
fi

# Step 2: Copy from /root/botsaz-faxima to /var/www/botsaz-faxima
# (only if SRC is /root and LIVE is /var/www, or as explicit step)
if [ "$SRC_DIR" = "/root/botsaz-faxima" ] || [ "$SRC_DIR" = "/root" ]; then
    step "Step 2: Copying from $SRC_DIR to $LIVE_DIR..."
    
    if [ -d "$SRC_DIR" ]; then
        # Remove existing .git from live if present (fresh deploy)
        if [ -d "$LIVE_DIR/.git" ]; then
            rm -rf "$LIVE_DIR/.git"
            ok "Removed existing .git from live directory"
        fi
        
        # Copy all contents from root source to live
        cp -r "$SRC_DIR"/.[!.]* "$LIVE_DIR"/ 2>/dev/null || true
        cp -r "$SRC_DIR"/* "$LIVE_DIR"/ 2>/dev/null || true
        
        # Remove config.php and data from copy (they should come from live)
        rm -f "$LIVE_DIR/config.php" 2>/dev/null || true
        rm -rf "$LIVE_DIR/data" 2>/dev/null || true
        rm -rf "$LIVE_DIR/bots" 2>/dev/null || true
        
        ok "Copied source files from $SRC_DIR to $LIVE_DIR"
        warn "config.php, data/ and bots/ directories should be restored from live directory"
    else
        warn "Source directory $SRC_DIR does not exist"
    fi
fi

# Step 3: Database specs replacement
step "Step 3: Database configuration verification and replacement..."

# Check if database config functions exist in the codebase
HAS_DB_FUNCS=0
if grep -r "db_host\|db_user\|db_pass" "$SRC_DIR"/*.php "$SRC_DIR"/src/*.php 2>/dev/null | grep -v "config.example" | grep -v "PUT_MAIN" | grep -v "change-this"; then
    HAS_DB_FUNCS=1
    ok "Database configuration functions found in codebase"
else
    warn "No database config functions found in code - will prompt for input"
fi

# If live config has DB settings and source doesn't, import them
if [ "$HAS_DB_FUNCS" -eq 0 ] && [ -f "$LIVE_DIR/config.php" ]; then
    # Import DB settings from live to source
    LIVE_DB_HOST=$(grep -oP "db_host' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "127.0.0.1")
    LIVE_DB_PORT=$(grep -oP "db_port' => \K[0-9]+" "$LIVE_DIR/config.php" 2>/dev/null || echo "3306")
    LIVE_DB_USER=$(grep -oP "db_user' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "root")
    LIVE_DB_PASS=$(grep -oP "db_pass' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "")
    LIVE_DB_PREFIX=$(grep -oP "db_prefix' => 'K\K[^']+" "$LIVE_DIR/config.php" 2>/dev/null || echo "botsaz_")
    
    # Apply to source config
    if [ -f "$SRC_DIR/config.php" ]; then
        sed -i "s|'db_host' => [^']*'|'db_host' => '$LIVE_DB_HOST',|" "$SRC_DIR/config.php" 2>/dev/null
        sed -i "s|'db_port' => [0-9]*|'db_port' => $LIVE_DB_PORT,|" "$SRC_DIR/config.php" 2>/dev/null
        sed -i "s|'db_user' => [^']*'|'db_user' => '$LIVE_DB_USER',|" "$SRC_DIR/config.php" 2>/dev/null
        sed -i "s|'db_pass' => [^']*'|'db_pass' => '$LIVE_DB_PASS',|" "$SRC_DIR/config.php" 2>/dev/null
        sed -i "s|'db_prefix' => [^']*'|'db_prefix' => '$LIVE_DB_PREFIX',|" "$SRC_DIR/config.php" 2>/dev/null
        ok "Database settings imported from live config to source"
    fi
fi

# If still no DB config, prompt user
if [ ! -f "$SRC_DIR/config.php" ] || ! grep -q "db_host" "$SRC_DIR/config.php" 2>/dev/null; then
    warn "Database configuration not found - please run config setup"
    read -p "Enter MySQL host [127.0.0.1]: " INPUT_HOST
    INPUT_HOST=${INPUT_HOST:-127.0.0.1}
    read -p "Enter MySQL port [3306]: " INPUT_PORT
    INPUT_PORT=${INPUT_PORT:-3306}
    read -p "Enter MySQL user [root]: " INPUT_USER
    INPUT_USER=${INPUT_USER:-root}
    read -p "Enter MySQL password [empty]: " INPUT_PASS
    read -p "Enter DB prefix [botsaz_]: " INPUT_PREFIX
    INPUT_PREFIX=${INPUT_PREFIX:-botsaz_}
    
    # Update config.php
    if [ ! -f "$SRC_DIR/config.php" ]; then
        cp "$SRC_DIR/config.example.php" "$SRC_DIR/config.php" 2>/dev/null || true
    fi
    
    if [ -f "$SRC_DIR/config.php" ]; then
        # Use php to update the config
        php -r '
            $cfg = @include "'$SRC_DIR/config.php'";
            if (is_array($cfg)) {
                $cfg["db_host"] = "'$INPUT_HOST'";
                $cfg["db_port"] = '"$INPUT_PORT"';
                $cfg["db_user"] = "'$INPUT_USER'";
                $cfg["db_pass"] = "'$INPUT_PASS'";
                $cfg["db_prefix"] = "'$INPUT_PREFIX'";
                $content = "<?php\nreturn " . var_export($cfg, true) . ";\n";
                file_put_contents("'$SRC_DIR/config.php'", $content);
                ok "Config updated with database settings";
            }
        ' || warn "Failed to update config.php automatically"
    fi
fi

# Step 4: VHost settings
step "Step 4: Web server (VHost) configuration check..."

# Detect web server and ensure vhost is configured
WEB_SERVER=""
if [ -f /etc/apache2/sites-available/botsaz.conf ] 2>/dev/null; then
    WEB_SERVER="apache"
    # Check if vhost DocumentRoot points to LIVE_DIR
    VHOST_DOMAIN=$(grep -oP 'ServerName \K[^ ]+' /etc/apache2/sites-available/botsaz.conf 2>/dev/null || echo "")
    VHOST_DOCROOT=$(grep -oP 'DocumentRoot \K[^ ]+' /etc/apache2/sites-available/botsaz.conf 2>/dev/null || echo "")
    if [ "$VHOST_DOCROOT" != "$(cd "$LIVE_DIR" && pwd -P)" ] && [ -n "$VHOST_DOCROOT" ]; then
        warn "Apache vhost DocumentRoot ($VHOST_DOCROOT) does not match $LIVE_DIR"
        echo "  To fix: sudo sed -i 's|DocumentRoot [^ ]*|DocumentRoot $(cd "$LIVE_DIR" && pwd -P)|' /etc/apache2/sites-available/botsaz.conf"
    fi
elif [ -f /etc/nginx/sites-available/botsaz.conf ] 2>/dev/null; then
    WEB_SERVER="nginx"
    NGINX_ROOT=$(grep -oP 'root \K[^;]+' /etc/nginx/sites-available/botsaz.conf 2>/dev/null || echo "")
    if [ "$NGINX_ROOT" != "$(cd "$LIVE_DIR" && pwd -P)" ] && [ -n "$NGINX_ROOT" ]; then
        warn "Nginx vhost root ($NGINX_ROOT) does not match $LIVE_DIR"
        echo "  To fix: sudo sed -i 's|root [^;]*|root $(cd "$LIVE_DIR" && pwd -P);|' /etc/nginx/sites-available/botsaz.conf"
    fi
else
    warn "No vhost configuration found for botsaz-faxima"
    echo "  Run install.sh or manually configure your web server to point to $LIVE_DIR"
fi

# Step 5: Webhook update
step "Step 5: Webhook configuration update..."

if [ -f "$LIVE_DIR/bot.php" ] || [ -f "$SRC_DIR/bot.php" ]; then
    # Get main bot token
    MAIN_TOKEN=""
    if [ -f "$LIVE_DIR/config.php" ]; then
        MAIN_TOKEN=$(php -r '$c=@include "config.php"; echo $c["main_token"] ?? ""' 2>/dev/null || echo "")
    fi
    
    if [ -n "$MAIN_TOKEN" ] && [ "$MAIN_TOKEN" != "PUT_MAIN_BOT_TOKEN_HERE" ]; then
        WEBHOOK_URL="$LIVE_DIR/bot.php"  # local testing
        echo "Main bot token found: $MAIN_TOKEN"
        echo "To update webhook via Telegram API:"
        echo "  curl -F 'url=http://yourdomain.com/botsaz-faxima/bots/main/webhook' https://api.telegram.org/bot$MAIN_TOKEN/setWebhook"
        echo ""
        echo "Or use the built-in set_webhook.php tool:"
        if [ -f "$LIVE_DIR/tools/set_webhook.php" ]; then
            php "$LIVE_DIR/tools/set_webhook.php" "$MAIN_TOKEN" >/dev/null 2>&1 && ok "Webhook re-confirmed via tool" || warn "Webhook tool had issues"
        fi
    else
        warn "Main bot token not configured - skipping webhook update"
        echo "  Set main_token in config.php first, then run: php tools/set_webhook.php"
    fi
else
    warn "bot.php not found - skipping webhook update"
fi

# Step 6: Check for development needs
step "Step 6: Checking for development needs and TODOs..."

TODO_COUNT=0
if [ -f "$SRC_DIR/src/Manager.php" ]; then
    TODO_COUNT=$(grep -r "TODO\|FIXME" "$SRC_DIR"/*.php "$SRC_DIR"/src/*.php 2>/dev/null | grep -v "config.example" | wc -l)
fi
if [ "$TODO_COUNT" -gt 0 ]; then
    warn "Found $TODO_COUNT TODO/FIXME items in the codebase"
    # Show first few TODOs
    grep -r "TODO\|FIXME" "$SRC_DIR"/*.php "$SRC_DIR"/src/*.php 2>/dev/null | grep -v "config.example" | head -5 | while read -r line; do
        echo "  - $line"
    done
else
    ok "No TODO/FIXME items found"
fi

# Check for any pending migration or install steps
if [ -f "$LIVE_DIR/tools/install.php" ]; then
    _migration_status=$(php "$LIVE_DIR/tools/install.php" --check 2>/dev/null | grep -E "(migration|database)" || echo "unknown")
    ok "Installer available - migration check: $_migration_status"
else
    warn "install.php not found - manual migration may be needed"
fi

# Step 7: Clean root files for security
step "Step 7: Cleaning root directory for security..."

if [ "$SRC_DIR" = "/root/botsaz-faxima" ] || [ "$SRC_DIR" = "/root" ]; then
    # Remove sensitive files from root
    rm -f "$SRC_DIR/config.php" 2>/dev/null || true
    rm -f "$SRC_DIR/config.example.php" 2>/dev/null || true
    rm -f "$SRC_DIR/.htaccess" 2>/dev/null || true
    rm -f "$SRC_DIR/.gitattributes" 2>/dev/null || true
    rm -f "$SRC_DIR/.gitignore" 2>/dev/null || true
    rm -f "$SRC_DIR/FIX_*.sh" 2>/dev/null || true
    rm -f "$SRC_DIR/*.lock" 2>/dev/null || true
    rm -f "$SRC_DIR"/*.zip 2>/dev/null || true
    rm -f "$SRC_DIR"/*.tar.gz 2>/dev/null || true
    ok "Sensitive files removed from $SRC_DIR"
    
    # Remove backup archives
    rm -rf /tmp/botsaz-config-backup-* 2>/dev/null || true
    ok "Old backup archives cleaned from /tmp"
    
    # Set /root permissions
    if [ -d /root ]; then
        chmod 711 /root 2>/dev/null && ok "/root permissions fixed to 711" || warn "Could not fix /root permissions"
    fi
else
    ok "Source directory is not under /root - no cleanup needed"
fi

# ===== ۰. پیش‌بینی اصلی =====
step "Step 0: Pre-flight checks..."

if [ ! -d .git ]; then
    fail "No .git directory! Run install.sh first."
    exit 1
fi

# قفل اجرای همزمان (کرون هفتگی + اجرای دستی نباید روی هم بیفتند)
mkdir -p "$ROOT_DIR/data" 2>/dev/null || true
if command -v flock >/dev/null 2>&1 && exec 9>"$ROOT_DIR/data/update.lock" 2>/dev/null; then
    if ! flock -n 9 2>/dev/null; then
        fail "Another update is already running - exiting."
        exit 1
    fi
else
    warn "File lock unavailable - concurrent updates are not guarded on this server"
fi

if [ "$FORCE" -eq 0 ]; then
    if ! curl -s --max-time 5 https://github.com >/dev/null 2>&1; then
        warn "Cannot reach GitHub - update will likely fail"
    fi
fi

DISK_AVAIL=$(df "$ROOT_DIR" 2>/dev/null | awk 'NR==2{print $4}')
if [ -n "$DISK_AVAIL" ] && [ "$DISK_AVAIL" -lt 51200 ] 2>/dev/null; then
    fail "Low disk space ($DISK_AVAIL KB available) - need at least 50MB"
    exit 1
fi

# درخت detached یعنی کسی عمداً روی کامیت خاصی است؛ حدس زدن branch و
# pull کردن روی آن می‌تواند وضعیت عمدی را خراب کند - abort صریح.
if ! git symbolic-ref -q HEAD >/dev/null 2>&1; then
    fail "Detached HEAD - checkout a branch first (git checkout main)."
    exit 1
fi
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")

# ===== ۱. بک‌آپ config files =====
step "Step 1: Backing up configuration files..."
BACKUP_DIR="/tmp/botsaz-config-backup-$(date +%Y%m%d%H%M%S)"
mkdir -p "$BACKUP_DIR"

# nullglob: اگر پوشه bots خالی است، الگو به‌جای رشته خام، هیچی نشود
shopt -s nullglob

if [ -f "$ROOT_DIR/config.php" ]; then
    cp -a "$ROOT_DIR/config.php" "$BACKUP_DIR/config.php" 2>/dev/null && ok "Backed up: config.php" || warn "Could not backup config.php"
fi

for cf in "$ROOT_DIR"/bots/*/config.php; do
    slug="$(basename "$(dirname "$cf")")"
    mkdir -p "$BACKUP_DIR/bots/$slug" 2>/dev/null
    cp -a "$cf" "$BACKUP_DIR/bots/$slug/config.php" 2>/dev/null && ok "Backed up: bots/$slug/config.php" || warn "Could not backup bots/$slug/config.php"
done

shopt -u nullglob

# ===== ۲. git fetch + merge --ff-only =====
step "Step 2: Fetching from GitHub..."
if ! git fetch origin "$CURRENT_BRANCH" 2>/dev/null; then
    fail "Could not fetch origin/$CURRENT_BRANCH - check network and remote."
    echo "  Backup of your configs is at: $BACKUP_DIR (kept)"
    exit 1
fi

if [ "$DRY_RUN" -eq 1 ]; then
    ok "DRY RUN mode - no changes will be made"
    echo "  Branch: $CURRENT_BRANCH"
    echo "  Incoming commits (HEAD..origin/$CURRENT_BRANCH):"
    git log --oneline "HEAD..origin/$CURRENT_BRANCH" 2>/dev/null || echo "  (none - already up to date)"
    echo "  Local tracked modifications:"
    git status --porcelain --untracked-files=no 2>/dev/null || true
    rm -rf "$BACKUP_DIR" 2>/dev/null || true
    exit 0
fi

# درخت کثیف = کسی روی سرور دستی چیزی عوض کرده (هات‌فیکس). merge یا
# stash در این حالت یا هات‌فیکس را می‌پراند یا درگیری نصفه می‌گذارد و
# اسکریپت روی درخت خراب به ری‌استارت سرویس می‌رسد. پس صریح abort.
if [ -n "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]; then
    fail "Local tracked files were modified on this server - refusing to merge."
    git status --short --untracked-files=no 2>/dev/null || true
    echo "  Review them, then commit, revert (git checkout -- <file>),"
    echo "  or move them aside and re-run this updater."
    echo "  Backup of your configs is at: $BACKUP_DIR (kept)"
    exit 1
fi

step "Step 2b: Merging (fast-forward only)..."
if ! git merge --ff-only "origin/$CURRENT_BRANCH" 2>/dev/null; then
    fail "Cannot fast-forward (local branch diverged - e.g. a server-side commit)."
    echo "  Resolve by hand: cd $ROOT_DIR && git log --oneline -5 && git rebase origin/$CURRENT_BRANCH"
    echo "  Backup of your configs is at: $BACKUP_DIR (kept)"
    exit 1
fi

NEW_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
if [ "$CURRENT_COMMIT" = "$NEW_COMMIT" ]; then
    ok "Already up to date ($CURRENT_COMMIT)"
else
    ok "Updated: $CURRENT_COMMIT -> $NEW_COMMIT"
fi

# ===== ۳. بازرسی config files =====
step "Step 3: Verifying config files are intact..."
# config.php در .gitignore است پس pull نباید به آن دست بزند، ولی اگر زمانی
# track شده باشد (یا دستی وسط اجرا عوض شده باشد) اینجا برمی‌گردد.
restore_one() { # $1 = relative path (no `local`: called at top level)
    _r_rel="$1"; _r_bf="$BACKUP_DIR/$1"; _r_cf="$ROOT_DIR/$1"
    if [ -f "$_r_bf" ]; then
        if [ ! -f "$_r_cf" ] || ! diff -q "$_r_bf" "$_r_cf" >/dev/null 2>&1; then
            mkdir -p "$(dirname "$_r_cf")"
            if cp -a "$_r_bf" "$_r_cf"; then
                ok "$_r_rel restored from backup (overwrite prevented)"
            else
                fail "Failed to restore $_r_rel"
            fi
        else
            ok "$_r_rel unchanged ✓"
        fi
    fi
    unset _r_rel _r_bf _r_cf
}
restore_one "config.php"
shopt -s nullglob
for d in "$ROOT_DIR"/bots/*/; do
    [ -d "$d" ] || continue
    restore_one "bots/$(basename "$d")/config.php"
done
shopt -u nullglob

# ===== ۳c. استقرار: کدِ سورس ⇒ پوشهٔ زنده =====
# اینجا جایی است که «آپدیت گرفتم ولی ربات همان نسخهٔ قبلی است» حل می‌شود.
# اگر پوشهٔ زنده با پوشهٔ سورس فرق دارد، فقط git pull کافی نیست: وب‌سرور
# پوشهٔ دیگری را سرو می‌کند و اصلاً فایل‌های تازه را نمی‌بیند.
#
# چیزهایی که هرگز از سورس روی زنده نوشته نمی‌شوند:
#   config.php  → توکن ربات، کلید رمزنگاری، مشخصات دیتابیس (از زنده می‌آید)
#   data/       → دیتابیس مدیریتی + لاگ‌ها
#   bots/       → ربات‌های فرزند (کدشان با دکمهٔ «🔄 دریافت سورس بروز» و با
#                 بکاپ کامل به‌روز می‌شود، نه با یک rsync کور)
step "Step 3c: Deploying the new code to the live directory..."

# ---------- ۳c-۱) گزارش کانفیگ: کلیدهای لازمی که در کانفیگ زنده نیست ----------
# «مشخصات دیتابیس جایگذاری بشه؛ اگر در کد هست اضافه کن و اگر نیست از کاربر بگیر.»
# یعنی: هر کلیدی که config.example.php لازم دارد ولی در کانفیگ واقعی نیست،
# دقیقاً گفته می‌شود کدام است و چه باید کرد — به‌جای اینکه بعداً ربات با
# دیتابیس خراب بالا بیاید.
_cfg_report="$(CONFIG_FILE="$( [ "$LIVE_DIR" = "$SRC_REAL" ] && echo "$SRC_REAL/config.php" || echo "$LIVE_DIR/config.php" )" \
              EXAMPLE_FILE="$SRC_REAL/config.example.php" "$PHP_BIN" -r '
$f  = getenv("CONFIG_FILE");
$ex = getenv("EXAMPLE_FILE");
$have = is_file($f) ? @include $f : null;
if (!is_array($have)) { echo "ERR: config.php is missing or unreadable\n"; exit; }
$want = is_file($ex) ? @include $ex : [];
if (!is_array($want) || $want === []) { echo "OK:\n"; exit; }
// کلیدهای نمونه/فقط‌مستندی که لازم نیستند
$skip = ["main_token", "super_admins", "base_url", "manager_db", "php_bin", "secret_key",
         "db_backup", "payment", "nowpayments"];
$need = [];
foreach ($want as $k => $v) {
    if (in_array($k, $skip, true)) continue;
    if (!array_key_exists($k, $have) || $have[$k] === "" || $have[$k] === null) $need[] = $k;
}
if ($need === []) { echo "OK:\n"; exit; }
foreach ($need as $k) {
    $sample = var_export($want[$k] ?? "", true);
    echo "MISS: $k = $sample\n";
}' 2>/dev/null || true)"
if printf '%s' "$_cfg_report" | grep -q '^ERR:'; then
    fail "the live config.php is missing or unreadable - nothing was deployed over it"
    echo "  Restore it from the backup taken in Step 1, or copy your own file there."
elif printf '%s' "$_cfg_report" | grep -q '^MISS:'; then
    fail "the live config.php is missing settings that the new code expects:"
    printf '%s\n' "$_cfg_report" | sed -n 's/^MISS: /    - /p'
    echo "  They were NOT invented automatically (a wrong DB password is worse than a"
    echo "  clear error). Add them to $LIVE_DIR/config.php and re-run this script:"
    echo "    nano $LIVE_DIR/config.php      # or take the values from config.example.php"
else
    ok "the live config.php has every setting the new code needs"
fi
unset _cfg_report

# ---------- ۳c-۲) کپی کد ----------
if [ "$LIVE_DIR" = "$SRC_REAL" ]; then
    ok "source and live directory are the same - git merge was the deployment"
elif [ ! -d "$LIVE_DIR" ]; then
    fail "the live directory $LIVE_DIR does not exist - refusing to create it blindly"
    echo "  Create it and put your config.php there, then re-run:"
    echo "    mkdir -p $LIVE_DIR && cp $BACKUP_DIR/config.php $LIVE_DIR/config.php"
else
    if command -v rsync >/dev/null 2>&1; then
        _rsync_ex=(
            --exclude '.git/' --exclude '.gitattributes'
            --exclude 'config.php'
            --exclude 'data/'
            --exclude 'bots/'
            --exclude '*.bak' --exclude '*.log' --exclude '*.lock'
        )
        if rsync -a --delete "${_rsync_ex[@]}" "$SRC_DIR/" "$LIVE_DIR/"; then
            ok "code deployed: $SRC_DIR/ → $LIVE_DIR/ (config.php, data/ and bots/ untouched)"
        else
            fail "rsync to $LIVE_DIR failed - the live site still runs the old code"
            echo "  By hand:  sudo rsync -a --delete --exclude '.git/' --exclude 'config.php' \\"
            echo "        --exclude 'data/' --exclude 'bots/' '$SRC_DIR/' '$LIVE_DIR/'"
        fi
        unset _rsync_ex
    else
        warn "rsync is not installed - deploying with cp (files removed upstream are kept)"
        for _i in bot.php index.php nowpayments_ipn.php .htaccess; do
            [ -f "$SRC_DIR/$_i" ] && cp -a "$SRC_DIR/$_i" "$LIVE_DIR/" 2>/dev/null
        done
        for _d in src tools templates; do
            [ -d "$SRC_DIR/$_d" ] && cp -a "$SRC_DIR/$_d/." "$LIVE_DIR/$_d/" 2>/dev/null
        done
        ok "code copied to $LIVE_DIR (config.php, data/ and bots/ untouched)"
        echo "  Tip: sudo apt-get install -y rsync"
    fi

    # ---------- ۳c-۳) اثبات: نسخهٔ روی دیسخِ زنده باید تازه باشد ----------
    _v_src="$(grep -m1 'APP_VERSION' "$SRC_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
    _v_dst="$(grep -m1 'APP_VERSION' "$LIVE_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
    if [ -n "$_v_dst" ] && [ "$_v_dst" = "$_v_src" ]; then
        ok "the deployed copy reports the same version ($_v_dst)"
    else
        fail "the deployed copy reports '${_v_dst:-unknown}' but the source is '${_v_src:-unknown}'"
        echo "  The live site will NOT get the new code until this is fixed."
    fi
    # کانفیگِ زنده نباید جایگزین شده باشد
    if [ -f "$BACKUP_DIR/config.php" ] && [ -f "$LIVE_DIR/config.php" ]; then
        if cmp -s "$BACKUP_DIR/config.php" "$LIVE_DIR/config.php" 2>/dev/null; then
            ok "the live config.php is byte-for-byte the one that was already live ✓"
        else
            warn "$LIVE_DIR/config.php differs from the backup taken at the start of this run"
            echo "  diff it if you did not edit it on purpose:"
            echo "    diff <(php -r 'var_export(include \"$BACKUP_DIR/config.php\");') \\"
            echo "         <(php -r 'var_export(include \"$LIVE_DIR/config.php\");')"
        fi
    else
        warn "$LIVE_DIR/config.php could not be compared with the backup"
    fi
    unset _v_src _v_dst _i _d
fi
# ===== ۴. بازسازی .htaccess گمشده (لیست از خود مخزن) =====
step "Step 4: Checking .htaccess files..."
HTACCESS_RESTORED=0
while IFS= read -r -d '' f; do
    if [ ! -f "$ROOT_DIR/$f" ]; then
        # اول ثابت کن فایل در HEAD هست؛ وگرنه redirect یک فایل خالی می‌سازد!
        if git cat-file -e "HEAD:$f" 2>/dev/null && git show "HEAD:$f" > "$ROOT_DIR/$f" 2>/dev/null; then
            ok "Restored $f from HEAD"
            HTACCESS_RESTORED=$((HTACCESS_RESTORED + 1))
        else
            warn "$f is missing and not in git history - left alone"
        fi
    fi
done < <(git ls-files -z '*.htaccess' 2>/dev/null || true)
if [ "$HTACCESS_RESTORED" -gt 0 ]; then
    ok "$HTACCESS_RESTORED .htaccess file(s) restored"
else
    ok "All .htaccess files present ✓"
fi

# ===== ۴b. اجرای install.php (مایگریشن‌ها + پوشه‌ها؛ config را بازنویسی نمی‌کند) =====
# در چیدمانِ دوتایی، مایگریشن باید داخل پوشهٔ زنده اجرا شود: دیتابیسِ
# مدیریتی و لاگ‌ها آنجاست. اجرای آن در پوشهٔ سورس یعنی «مایگریشنِ یک
# دیتابیسِ خالیِ دیگر» — یعنی همان [FAIL] های «migrations not recorded».
step "Step 4b: Running installer bootstrap (migrations)..."
if [ -f "$LIVE_DIR/tools/install.php" ]; then
    php "$LIVE_DIR/tools/install.php" 2>/dev/null || warn "install.php had issues - check manually"
    [ "$LIVE_DIR" = "$SRC_REAL" ] || ok "migrations ran inside the live directory ($LIVE_DIR)"
else
    warn "install.php not found in $LIVE_DIR"
fi

# ===== ۴b-۲. پرمیشن پوشه‌های نوشتنی داخل پوشهٔ زنده =====
# www-data باید بتواند data/ و bots/ زنده را بنویسد، وگرنه نه لاگی می‌رود،
# نه دیتابیس مدیریتی کار می‌کند و نه ربات تازه‌ای ساخته می‌شود.
if [ -n "$WEB_USER" ] && [ "$(id -u)" -eq 0 ]; then
    for _d in data bots; do
        [ -d "$LIVE_DIR/$_d" ] || mkdir -p "$LIVE_DIR/$_d" 2>/dev/null
        if [ -d "$LIVE_DIR/$_d" ]; then
            if ! sudo -u "$WEB_USER" test -w "$LIVE_DIR/$_d" 2>/dev/null; then
                chown "$WEB_USER:$WEB_USER" "$LIVE_DIR/$_d" 2>/dev/null \
                    && ok "chowned $LIVE_DIR/$_d to $WEB_USER (bot can write logs + build bots)" \
                    || warn "could not chown $LIVE_DIR/$_d to $WEB_USER - run it by hand:"
                [ -w "$LIVE_DIR/$_d" ] || echo "        sudo chown -R $WEB_USER:$WEB_USER '$LIVE_DIR/$_d'"
            else
                ok "$LIVE_DIR/$_d is writable by $WEB_USER"
            fi
        fi
    done
    unset _d
fi

# ===== ۴c. گیت نسخه PHP (کد جدید ممکن است 8.2+ بخواهد) =====
if ! php -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' 2>/dev/null; then
    warn "PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null) is older than 8.2 - new code may fatal."
    warn "Upgrade PHP before relying on the updated bots."
fi

# ===== ۵. فیکس /root permissions =====
step "Step 5: Checking /root permissions..."
case "$ROOT_DIR" in
    /root|/root/*)
        # stat قابل‌حمل (GNU و BSD)
        if stat -c '%a' /root >/dev/null 2>&1; then
            ROOT_MODE=$(stat -c '%a' /root)
        elif stat -f '%Lp' /root >/dev/null 2>&1; then
            ROOT_MODE=$(stat -f '%Lp' /root)
        else
            ROOT_MODE="?"
        fi
        # ورود فقط x می‌خواهد (رقم یکان فرد): 701 هم معتبر است و نباید
        # حالت 700 عمدی سرورهای hardened را بی‌اجازه شل کرد.
        case "$ROOT_MODE" in
            *[1357]) ok "/root mode is $ROOT_MODE ✓ (traversable)" ;;
            *)
                warn "/root is mode $ROOT_MODE - fixing to 711..."
                chmod 711 /root 2>/dev/null && ok "/root fixed to 711" || fail "Could not fix /root (need root/sudo)"
                ;;
        esac
        ;;
    *)
        ok "Project is outside /root - nothing to fix"
        ;;
esac

# ===== ۶. ریلود vhost =====
step "Step 6: Reloading web server..."

if [ "$WEB" -eq 1 ]; then
    ok "Web mode (--web): vhost reload skipped - آپدیت از داخل ربات نمی‌تواند وب‌سرور را ریلود کند"
elif [ -f /etc/apache2/sites-available/botsaz.conf ]; then
    if [ "$NO_RESTART" -eq 0 ] && systemctl is-active --quiet apache2 2>/dev/null; then
        systemctl reload apache2 2>/dev/null && ok "Apache vhost reloaded" || warn "Could not reload Apache"
    else
        ok "Apache vhost exists (reload skipped)"
    fi
fi

if [ -f /etc/nginx/sites-available/botsaz.conf ]; then
    NGX_T="$(nginx -t 2>&1 || true)"
    if echo "$NGX_T" | grep -q "successful"; then
        if [ "$NO_RESTART" -eq 0 ] && systemctl is-active --quiet nginx 2>/dev/null; then
            systemctl reload nginx 2>/dev/null && ok "nginx vhost reloaded" || warn "Could not reload nginx"
        else
            systemctl start nginx 2>/dev/null && ok "nginx started" || warn "Could not start nginx"
        fi
    else
        warn "nginx config test failed - vhost may need rewriting:"
        echo "$NGX_T" | head -n 5
        echo "  Fix with: bash tools/install.sh (it rewrites the vhost with backup)"
    fi
fi

# هر دو وب‌سرور هم‌زمان روی ۸۰/۴۴۳ = تداخل حتمی
if [ -f /etc/apache2/sites-available/botsaz.conf ] && [ -f /etc/nginx/sites-available/botsaz.conf ]; then
    if systemctl is-active --quiet apache2 2>/dev/null && systemctl is-active --quiet nginx 2>/dev/null; then
        warn "Both Apache and nginx are running on port 80/443!"
        warn "Disable one of them to avoid conflicts"
    fi
fi

if [ ! -f /etc/apache2/sites-available/botsaz.conf ] && [ ! -f /etc/nginx/sites-available/botsaz.conf ]; then
    ok "No vhost found - may need to run install.sh first"
fi

# ===== ۷. فیکس systemd sandbox =====
step "Step 7: Fixing systemd sandbox..."
if [ "$WEB" -eq 1 ]; then
    ok "Web mode: systemd sandbox check skipped (نیاز به ری‌استارت یونیت دارد)"
elif command -v systemctl >/dev/null 2>&1; then
    # یونیت‌های واقعی (نه لیست هاردکد که نسخه php-fpm را جا می‌اندازد)
    _units=""
    for u in apache2 httpd nginx; do
        if systemctl show "$u" -p LoadState --value 2>/dev/null | grep -qx loaded; then
            _units="$_units $u"
        fi
    done
    for u in $(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
        u="${u%.service}"
        if systemctl show "$u" -p LoadState --value 2>/dev/null | grep -qx loaded; then
            _units="$_units $u"
        fi
    done
    for unit in $_units; do
        # هر دو پراپرتی می‌توانند /root را مخفی کنند؛ چک فقط InaccessiblePaths
        # حالت ProtectHome=yes را جا می‌انداخت و «OK» دروغ می‌گفت.
        _ph="$(systemctl show "$unit" -p ProtectHome --value 2>/dev/null || echo "")"
        _inacc="$(systemctl show "$unit" -p InaccessiblePaths --value 2>/dev/null || echo "")"
        _blocked=0
        case "$_ph" in yes|true|1|on) _blocked=1 ;; esac
        case "$_inacc" in */root*) _blocked=1 ;; esac
        if [ "$_blocked" = "1" ]; then
            case "$ROOT_DIR" in
                /root|/root/*|/home|/home/*)
                    warn "$unit hides the project (ProtectHome=$_ph InaccessiblePaths=$_inacc) - fixing..."
                    mkdir -p "/etc/systemd/system/${unit}.service.d" 2>/dev/null
                    printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' > "/etc/systemd/system/${unit}.service.d/botsaz.conf" 2>/dev/null
                    ;;
                *)
                    ok "$unit sandbox set but project is outside /root|/home - no action"
                    ;;
            esac
        else
            ok "$unit sandbox OK ✓"
        fi
    done
    unset _units unit _ph _inacc _blocked
    systemctl daemon-reload 2>/dev/null || true
else
    warn "systemctl not found - skipping sandbox check"
fi

# ===== ۸. فیکس PCRE JIT =====
step "Step 8: Checking PCRE JIT..."
PHP_INIS=""
CLI_INI=$(php -r 'echo php_ini_loaded_file();' 2>/dev/null || echo "")
[ -n "$CLI_INI" ] && PHP_INIS="$PHP_INIS $CLI_INI"
for f in /etc/php/*/cli/php.ini /etc/php/*/fpm/php.ini /etc/php/*/apache2/php.ini; do
    [ -f "$f" ] && PHP_INIS="$PHP_INIS $f"
done

PCRE_FIXED=0
for f in $PHP_INIS; do
    [ -f "$f" ] || continue
    # هر دو املا ('pcre.jit=1' و 'pcre.jit = 1') را می‌گیرد تا رکورد تکراری نسازد
    if grep -Eq '^[[:space:]]*pcre\.jit[[:space:]]*=' "$f" 2>/dev/null; then
        if ! grep -Eq '^[[:space:]]*pcre\.jit[[:space:]]*=[[:space:]]*0' "$f" 2>/dev/null; then
            sed -i -E 's/^[[:space:]]*pcre\.jit[[:space:]]*=.*/pcre.jit=0/' "$f"
            ok "pcre.jit=0 set in $f"
            PCRE_FIXED=$((PCRE_FIXED + 1))
        fi
    else
        echo 'pcre.jit=0' >> "$f"
        ok "pcre.jit=0 set in $f"
        PCRE_FIXED=$((PCRE_FIXED + 1))
    fi
done
if [ "$PCRE_FIXED" -eq 0 ]; then
    ok "PCRE JIT already configured everywhere ✓"
fi

# ===== ۹. ریستارت سرویس‌ها (فقط یک دور) =====
step "Step 9: Restarting services..."
RESTARTED=0
if [ "$NO_RESTART" -eq 1 ]; then
    ok "Skipping service restart (NO_RESTART=1)"
else
    for svc in apache2 httpd nginx; do
        if systemctl is-active --quiet "$svc" 2>/dev/null; then
            if systemctl restart "$svc" 2>/dev/null || service "$svc" restart 2>/dev/null; then
                ok "$svc restarted"
                RESTARTED=$((RESTARTED + 1))
            else
                warn "Could not restart $svc"
            fi
        fi
    done
    for svc in $(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
        if systemctl is-active --quiet "$svc" 2>/dev/null; then
            if systemctl restart "$svc" 2>/dev/null; then
                ok "$svc restarted"
                RESTARTED=$((RESTARTED + 1))
            else
                warn "Could not restart $svc"
            fi
        fi
    done
    if [ "$RESTARTED" -eq 0 ]; then
        warn "No services were running - start them manually"
    fi
fi

# ===== ۹b. warmup و re-confirm وبهوک (فقط اگر ری‌استارت شد) =====
# ری‌استارت اتصال‌های باز تلگرام را می‌اندازد؛ بدون re-confirm روی سرور
# زنده، تحویل‌ها با backoff عقب می‌افتند و ربات «مرده» به نظر می‌رسد.
if [ "$NO_RESTART" -eq 0 ] && [ "$RESTARTED" -gt 0 ] && [ -f "$ROOT_DIR/config.php" ]; then
    step "Step 9b: Warming up and re-confirming webhook..."
    _base=$(php -r '$c=@include "config.php"; echo isset($c["base_url"])?rtrim($c["base_url"],"/"):"";' 2>/dev/null || true)
    if [ -n "$_base" ]; then
        _code="$(curl -s -o /dev/null -w '%{http_code}' -X POST --max-time 15 "$_base/bot.php" 2>/dev/null || echo 0)"
        # 403 هم یعنی زنده است (رد secret) - فقط مسیر و PHP مهم‌اند
        if [ "$_code" = "200" ] || [ "$_code" = "403" ]; then
            ok "bot.php answers HTTP $_code"
            php "$ROOT_DIR/tools/set_webhook.php" "$_base/bot.php" >/dev/null 2>&1 \
                && ok "Webhook re-confirmed" \
                || warn "Webhook re-confirm failed - run: php tools/set_webhook.php"
        else
            warn "bot.php answers HTTP $_code right after restart - check the vhost"
            # 0000 یعنی اصلاً وصل نشد: یا base_url اشتباه/placeholder است،
            # یا vhost فعالِ دیگری از یک نصبِ قدیمی سرو می‌دهد (وب‌سرور
            # پیام‌ها را به /var/www/botsaz-faxima قدیمی می‌فرستد).
            [ "$_code" = "0000" ] && warn "HTTP 0000: یا base_url در config.php placeholder است، یا یک vhost قدیمی (DocumentRoot غیر از $ROOT_DIR) آپاچی را می‌چرخاند - با `apache2ctl -S` چک کن"
        fi
    fi
    unset _base _code
fi

# ===== ۱۰. تست سلامت =====
step "Step 10: Running health check..."
if [ -f "$ROOT_DIR/tools/install.sh" ]; then
    bash "$ROOT_DIR/tools/install.sh" --check 2>/dev/null || warn "Health check had issues (see above)"
else
    warn "install.sh not found - run it manually"
fi

# ===== ۱۱. تأیید وبهوک =====
step "Step 11: Verifying webhook..."
if [ -f "$ROOT_DIR/config.php" ]; then
    # فایل موقت تا مشکل escaping شل دور زده شود
    WEBHOOK_PHP=$(mktemp /tmp/botsaz-webhook-XXXXXX.php)
    cat > "$WEBHOOK_PHP" << 'PHPEOF'
<?php
$config = require '/ROOT_DIR_PLACEHOLDER/config.php';
if (!isset($config['main_token']) || empty($config['main_token'])) {
    echo 'NO_TOKEN'; exit;
}
if (!function_exists('curl_init')) { echo 'NO_CURL'; exit; }
$url = 'https://api.telegram.org/bot' . $config['main_token'] . '/getWebhookInfo';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$raw = curl_exec($ch);
curl_close($ch);
$r = is_string($raw) ? json_decode($raw, true) : null;
echo (is_array($r) && isset($r['result']['url']) && $r['result']['url'] !== '') ? 'OK:' . $r['result']['url'] : 'NOT_SET';
?>
PHPEOF
    sed -i "s|/ROOT_DIR_PLACEHOLDER|$(echo "$ROOT_DIR" | sed 's|/|\\\\/|g')|g" "$WEBHOOK_PHP"

    WEBHOOK_STATUS=$(php "$WEBHOOK_PHP" 2>/dev/null || echo "ERROR")
    rm -f "$WEBHOOK_PHP"
    echo "  Webhook: $WEBHOOK_STATUS"
fi

# ===== ۱۲. نوتیفیکیشن تلگرام به ادمین =====
if [ -f "$ROOT_DIR/config.php" ] && [ "$DRY_RUN" -eq 0 ]; then
    NOTIFY_PHP=$(mktemp /tmp/botsaz-notify-XXXXXX.php)
    cat > "$NOTIFY_PHP" << 'PHPEOF'
<?php
$config = require '/ROOT_DIR_PLACEHOLDER/config.php';
if (empty($config['main_token'])) exit;
if (empty($config['super_admins'])) exit;
$admin = is_array($config['super_admins']) ? $config['super_admins'][0] : $config['super_admins'];
$new_commit = exec('git rev-parse HEAD 2>/dev/null');
$new_branch = exec('git rev-parse --abbrev-ref HEAD 2>/dev/null');
$msg = urlencode("Botsaz-Faxima Update\nBranch: " . ($new_branch ?: '?') . "\nCommit: " . substr((string)$new_commit, 0, 8) . "\nStatus: Success");
$url = 'https://api.telegram.org/bot' . $config['main_token'] . '/sendMessage';
$ch = curl_init($url . '?chat_id=' . $admin . '&text=' . $msg);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_exec($ch);
curl_close($ch);
?>
PHPEOF
    sed -i "s|/ROOT_DIR_PLACEHOLDER|$(echo "$ROOT_DIR" | sed 's|/|\\\\/|g')|g" "$NOTIFY_PHP"
    php "$NOTIFY_PHP" 2>/dev/null && ok "Update notification sent to admin" || warn "Could not send notification"
    rm -f "$NOTIFY_PHP"
fi

# ===== Cleanup: فقط ۳ بکاپ آخر می‌ماند (rollback همیشه ممکن) =====
ls -dt /tmp/botsaz-config-backup-* 2>/dev/null | tail -n +4 | xargs -r rm -rf 2>/dev/null || true

echo ""
echo "========================================="
echo "  ✅ Update complete!"
echo "  Config files preserved: YES"
echo "  Last backups kept in: /tmp/botsaz-config-backup-* (newest 3)"
echo "========================================="
echo ""
if [ "$WEB" -eq 1 ]; then
    echo "  NOTE (--web mode): services were NOT restarted, so the running PHP"
    echo "  process still uses the OLD code. Restart manually (or let the weekly"
    echo "  cron run update.sh) to load the new code:"
    echo "    systemctl restart apache2  (or nginx + php-fpm)"
    echo ""
elif [ "$NO_RESTART" -eq 1 ]; then
    # --no-restart یعنی «کد روی دیسک تازه شد ولی ورکرهای PHP هنوز کهنه‌اند».
    # اگر opcache بی‌نهایت کش کند، ربات تا ری‌استارت دستی همان نسخهٔ قبلی است —
    # و همان چیزی است که «آپدیت کردم ولی ربات عوض نشد» را می‌سازد.
    _vt="$(php -i 2>/dev/null | sed -n 's/^opcache\.validate_timestamps => \(.*\)/\1/p' | head -n1)"
    if [ -z "$_vt" ]; then
        _vt="$(php -r 'echo (ini_get("opcache.validate_timestamps") === false || ini_get("opcache.validate_timestamps")) ? "1" : "0";' 2>/dev/null)"
    fi
    echo "  NOTE (--no-restart): the files on disk are new, the running PHP is NOT."
    if [ "${_vt:-1}" = "0" ]; then
        echo "  ⚠️  opcache.validate_timestamps=0 ⇒ PHP never re-reads a changed file."
        echo "      The bot WILL keep behaving like the old version until you run:"
        echo "        systemctl restart php*-fpm apache2"
    else
        echo "  opcache.validate_timestamps=${_vt:-1} ⇒ PHP re-reads changed files within ~2s,"
        echo "  but if the panel still looks old, restart anyway:"
        echo "    systemctl restart php*-fpm apache2"
    fi
    unset _vt
    echo ""
fi
echo "  Child bots run COPIES of the templates from build time - this script"
echo "  never touches bots/<slug>/. When the template code changed, sync the"
echo "  child bots from inside the bot itself:"
echo "    «⬆️ آپدیت ربات‌ساز» (git pull = this script)  →  «🔄 دریافت سورس بروز» (bots/<slug>/)"
echo "  Each sync takes a full backup of the bot folder first and never touches"
echo "  the child's config.php or database."
echo ""
echo "  Sanity check after any update:"
echo "    bash tools/install.sh --check   # section 12 compares disk vs running code"
echo ""
echo "  If any [✘] appeared above:"
echo "    bash tools/install.sh --check  (detailed check)"
echo "    bash tools/install.sh --logs   (error logs)"
echo ""
echo "  To undo this update:"
echo "    cd $ROOT_DIR && git reset --hard $CURRENT_COMMIT"
echo "    git clean -fd"
echo ""
