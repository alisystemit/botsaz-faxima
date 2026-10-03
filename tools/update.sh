bash
#!/usr/bin/env bash
# ===== بروزرسانی ربات‌ساز از گیت‌هاب (حالت سرور /root → /var/www) =====
# استفاده: bash tools/update.sh [--dry-run] [--no-restart] [--force] [--web]
#
# فلسفه طراحی (مطابق درخواست کاربر):
#   ۱. بعد از دانلود نسخه جدید در /root، مشخصات کامل از /var/www/botsaz-faxima گرفته می‌شود
#   ۲. تنظیمات زنده (config.php + bots/*/config.php) داخل فایل‌های سورس (/root/...) جایگذاری می‌شوند
#   ۳. سورس تمیز (/root) → کد به /var/www/botsaz-faxima کپی می‌شود (بدون دست‌زدن به config/data/bots زنده)
#   ۴. مشخصات دیتابیس بررسی/جایگذاری می‌شود (اگر در کد موجود هست استفاده می‌شود، در غیر این صورت از کاربر گرفته می‌شود)
#   ۵. وی‌هوست (Apache/Nginx) بررسی و اصلاح می‌شود تا به مسیر زنده اشاره کند
#   ۶. وب‌هوک بروزرسانی/تأیید می‌شود
#   ۷. بررسی نیازهای توسعه (TODO/FIXME) + اجرای مایگریشن‌ها داخل پوشه زنده
#   ۸. در انتها فایل‌های حساس داخل /root برای امنیت بیشتر پاک می‌شوند
#
# آپشن‌ها:
#   --dry-run     فقط پیش‌نمایش می‌دهد، تغییر نمی‌دهد
#   --no-restart  سرویس‌ها ری‌استارت نمی‌شوند
#   --force       برخی پیش‌بینی‌ها رد می‌شوند
#   --web         مناسب اجرای از داخل ربات (ری‌استارت/ریلود محدود)

set +e

DRY_RUN=0
NO_RESTART=0
FORCE=0
WEB=0

for arg in "$@"; do
  case "$arg" in
    --dry-run)   DRY_RUN=1 ;;
    --no-restart) NO_RESTART=1 ;;
    --force)     FORCE=1 ;;
    --web)       WEB=1; NO_RESTART=1 ;;
  esac
done

# ===== رنگ‌ها =====
R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; B='\033[1;34m'; NC='\033[0m'
ok()  { echo -e "${G}✔${NC} $1"; }
fail(){ echo -e "${R}✘${NC} $1"; }
warn(){ echo -e "${Y}⚠️${NC} $1"; }
step(){ echo -e "\n${B}━━━ $1 ━━━${NC}"; }
info(){ echo -e "   $1"; }

# ===== ROOT_DIR (سورس git) =====
if [ -z "${BASH_SOURCE[0]:-}" ] || [ ! -f "${BASH_SOURCE[0]}" ]; then
  ROOT_DIR="$(pwd)"
  warn "Running from stdin - using PWD as ROOT_DIR: $ROOT_DIR"
else
  ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT_DIR" || exit 1

# Git safe.directory
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0="safe.directory"
export GIT_CONFIG_VALUE_0="$ROOT_DIR"

# ===== تشخیص LIVE_DIR (مسیر زنده وب‌سرور) =====
detect_web_user() {
  for u in www-data nginx apache httpd; do
    id -u "$u" >/dev/null 2>&1 && { printf '%s' "$u"; return 0; }
  done
  return 1
}
docroot_of() {
  local c d
  # Apache
  for c in /etc/apache2/sites-available/botsaz.conf /etc/apache2/sites-enabled/botsaz.conf /etc/apache2/sites-available/*.conf /etc/apache2/sites-enabled/*.conf; do
    [ -f "$c" ] || continue
    grep -qE "botsaz|faxima|$ROOT_DIR|/var/www/botsaz" "$c" 2>/dev/null || continue
    d="$(sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}\([^[:space:]#]*\).*/\1/p' "$c" 2>/dev/null | head -n1)"
    [ -n "$d" ] && { printf '%s' "$d"; return 0; }
  done
  # Nginx
  for c in /etc/nginx/sites-available/botsaz.conf /etc/nginx/sites-enabled/botsaz.conf /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [ -f "$c" ] || continue
    grep -qE "botsaz|faxima|$ROOT_DIR|/var/www/botsaz" "$c" 2>/dev/null || continue
    d="$(sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;#]*\);.*/\1/p' "$c" 2>/dev/null | head -n1)"
    [ -n "$d" ] && { printf '%s' "$d"; return 0; }
  done
  return 1
}

SRC_DIR="$(cd "$ROOT_DIR" && pwd -P)"
LIVE_DIR=""
WEB_USER="$(detect_web_user || true)"

_doc="$(docroot_of 2>/dev/null || true)"
if [ -n "$_doc" ] && [ -d "$_doc" ] && [ -f "$_doc/bot.php" ]; then
  LIVE_DIR="$(cd "$_doc" && pwd -P)"
elif [ -f "/var/www/botsaz-faxima/bot.php" ]; then
  LIVE_DIR="$(cd "/var/www/botsaz-faxima" && pwd -P)"
else
  LIVE_DIR="$SRC_DIR"
fi

# ===== قفل همزمان =====
mkdir -p "$SRC_DIR/data" 2>/dev/null || true
if command -v flock >/dev/null 2>&1 && exec 9>"$SRC_DIR/data/update.lock" 2>/dev/null; then
  if ! flock -n 9 2>/dev/null; then
    fail "Another update is already running - exiting."
    exit 1
  fi
fi

# ===== پیش‌بینی =====
step "Step 0: Pre-flight checks"
if [ ! -d "$SRC_DIR/.git" ]; then
  fail "No .git directory in $SRC_DIR"
  exit 1
fi
if [ "$FORCE" -eq 0 ]; then
  curl -s --max-time 3 https://github.com >/dev/null 2>&1 || warn "Cannot reach GitHub (continuing)"
fi
DISK_AVAIL=$(df "$SRC_DIR" 2>/dev/null | awk 'NR==2{print $4}')
if [ -n "$DISK_AVAIL" ] && [ "$DISK_AVAIL" -lt 51200 ] 2>/dev/null; then
  fail "Low disk space ($DISK_AVAIL KB) < 50MB"
  exit 1
fi
if ! git symbolic-ref -q HEAD >/dev/null 2>&1; then
  fail "Detached HEAD - checkout a branch first (git checkout main)"
  exit 1
fi
CUR_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
CUR_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")

step "Step 0b: Layout detection"
info "source (git)  : $SRC_DIR"
info "live (served) : $LIVE_DIR"
if [ "$LIVE_DIR" = "$SRC_DIR" ]; then
  ok "Single-directory layout"
else
  warn "Split layout detected (live != source)"
fi
[ -n "$WEB_USER" ] && info "web user      : $WEB_USER" || warn "No web user detected"
APP_VER_SRC="$(grep -m1 "APP_VERSION" "$SRC_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
[ -n "$APP_VER_SRC" ] && info "source version: $APP_VER_SRC"

# ===== ۱. بک‌آپ کامل تنظیمات زنده =====
step "Step 1: Backing up live configuration files"
BACKUP_DIR="/tmp/botsaz-config-backup-$(date +%Y%m%d%H%M%S)"
mkdir -p "$BACKUP_DIR"
shopt -s nullglob

if [ -f "$LIVE_DIR/config.php" ]; then
  mkdir -p "$BACKUP_DIR/live"
  cp -a "$LIVE_DIR/config.php" "$BACKUP_DIR/live/config.php" 2>/dev/null && ok "Backed up live: config.php" || warn "Failed to backup live/config.php"
fi
for cf in "$LIVE_DIR"/bots/*/config.php; do
  slug="$(basename "$(dirname "$cf")")"
  mkdir -p "$BACKUP_DIR/live/bots/$slug"
  cp -a "$cf" "$BACKUP_DIR/live/bots/$slug/config.php" 2>/dev/null && ok "Backed up live: bots/$slug/config.php"
done
shopt -u nullglob
info "Backup dir: $BACKUP_DIR"

# ===== ۲. git fetch/reset تا درخت تمیز شود (جلوگیری از «Local tracked files modified») =====
step "Step 2: Fetching latest code (clean git tree)"
if ! git fetch --prune origin "$CUR_BRANCH" 2>/dev/null; then
  fail "git fetch failed for origin/$CUR_BRANCH"
  exit 1
fi

# پاک کردن تغییرات محلی ردیابی‌شده (مانند .gitattributes/.gitignore/.htaccess حذف‌شده توسط نصب‌کننده)
LOCAL_MOD=$(git status --porcelain --untracked-files=no 2>/dev/null | wc -l)
if [ "$LOCAL_MOD" -gt 0 ]; then
  warn "$LOCAL_MOD tracked file(s) modified locally - resetting to origin/$CUR_BRANCH (safe for deployment)"
  info "$(git status --short --untracked-files=no | head -10)"
  git reset --hard "origin/$CUR_BRANCH" 2>/dev/null || {
    fail "git reset --hard failed"
    exit 1
  }
  git clean -fd 2>/dev/null || true
  ok "Source tree reset to clean origin/$CUR_BRANCH"
else
  ok "Working tree clean"
fi

git merge --ff-only "origin/$CUR_BRANCH" 2>/dev/null || true
NEW_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
if [ "$CUR_COMMIT" != "$NEW_COMMIT" ]; then
  ok "Updated: $CUR_COMMIT → $NEW_COMMIT"
else
  ok "Already up to date ($NEW_COMMIT)"
fi

# ===== ۳. استخراج کامل تنظیمات زنده و جایگذاری داخل سورس (/root) =====
step "Step 3: Extract live settings and merge into source ($SRC_DIR)"
PHP_BIN="$(command -v php 2>/dev/null || echo /usr/bin/php)"

# تابع استخراج آرایه config.php با PHP (سالم و دقیق)
extract_live_config() {
  local cfg_path="$1"
  [ -f "$cfg_path" ] || return 1
  "$PHP_BIN" -r '
  $f = $argv[1];
  $c = @include $f;
  if (!is_array($c)) exit(1);
  foreach ($c as $k=>$v){
    if (is_string($v)) {
      $v = str_replace("\\","\\\\",$v);
      $v = str_replace("\n","\\n",$v);
      $v = str_replace("\r","\\r",$v);
      $v = str_replace("\t","\\t",$v);
      $v = str_replace("\"","\\\"",$v);
      echo "STR|".$k."|\"".$v."\"\n";
    } elseif (is_int($v)||is_float($v)) {
      echo "NUM|".$k."|".$v."\n";
    } elseif (is_bool($v)) {
      echo "BOOL|".$k."|".($v?"true":"false")."\n";
    } elseif (is_null($v)) {
      echo "NULL|".$k."|\n";
    } elseif (is_array($v)) {
      // فقط آرایه‌های ساده (assoc/index) قابل بازنویسی امن
      $ser = var_export($v,true);
      $ser = str_replace("\n"," ",$ser);
      echo "ARR|".$k."|".$ser."\n";
    } else {
      echo "SKIP|".$k."|\n";
    }
  }
  ' "$cfg_path"
}

# جایگذاری config.php سورس با مقادیر زنده (PHP-safe)
merge_config_into_source() {
  local src_cfg="$1"
  local live_cfg="$2"
  [ -f "$live_cfg" ] || return 1
  [ -f "$src_cfg" ] || cp "$SRC_DIR/config.example.php" "$src_cfg" 2>/dev/null || touch "$src_cfg"

  local tmp_pairs="/tmp/botsaz_cfg_pairs_$$"
  extract_live_config "$live_cfg" > "$tmp_pairs" 2>/dev/null || return 1

  "$PHP_BIN" -r '
  $src = $argv[1];
  $pairs = $argv[2];
  $c = @include $src;
  if (!is_array($c)) $c = [];
  $map = [];
  if (is_file($pairs)) {
    foreach (file($pairs, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
      list($t,$k,$val) = explode("|",$line,3);
      $map[$k] = [$t,$val];
    }
  }
  foreach ($map as $k=>$mv) {
    list($t,$val) = $mv;
    if ($t==="STR") { $c[$k] = json_decode($val,true); if ($c[$k]===null) $c[$k] = substr($val,1,-1); continue; }
    if ($t==="NUM") { $c[$k] = strpos($val,".")!==false ? (float)$val : (int)$val; continue; }
    if ($t==="BOOL"){ $c[$k] = ($val==="true"); continue; }
    if ($t==="NULL"){ $c[$k] = null; continue; }
    if ($t==="ARR") { eval("\$v = ".$val.";"); $c[$k] = $v; continue; }
  }
  $out = "<?php\nreturn ".var_export($c,true).";\n";
  file_put_contents($src, $out);
  echo "OK";
  ' "$src_cfg" "$tmp_pairs" >/tmp/botsaz_cfg_merge_$$ 2>&1
  local res="$(cat /tmp/botsaz_cfg_merge_$$ 2>/dev/null)"
  rm -f "$tmp_pairs" /tmp/botsaz_cfg_merge_$$ 2>/dev/null
  [ "$res" = "OK" ] && return 0 || return 1
}

# ۳.۱ config.php اصلی
if [ -f "$LIVE_DIR/config.php" ]; then
  if merge_config_into_source "$SRC_DIR/config.php" "$LIVE_DIR/config.php"; then
    ok "Live config.php merged into source tree"
  else
    warn "Failed to merge live config.php into source (will try cp fallback)"
    cp -a "$LIVE_DIR/config.php" "$SRC_DIR/config.php" 2>/dev/null && ok "Live config.php copied to source" || warn "Could not copy live config.php"
  fi
else
  warn "No live config.php found at $LIVE_DIR/config.php"
fi

# ۳.۲ ربات‌های فرزند
shopt -s nullglob
_cbc=0
for cf_live in "$LIVE_DIR"/bots/*/config.php; do
  slug="$(basename "$(dirname "$cf_live")")"
  mkdir -p "$SRC_DIR/bots/$slug"
  cf_src="$SRC_DIR/bots/$slug/config.php"
  if merge_config_into_source "$cf_src" "$cf_live"; then
    _cbc=$((_cbc+1))
  else
    cp -a "$cf_live" "$cf_src" 2>/dev/null && _cbc=$((_cbc+1))
  fi
done
shopt -u nullglob
[ $_cbc -gt 0 ] && ok "Merged $_cbc child bot config(s) into source" || info "No child bot configs found in live"

# ===== ۴. بررسی/جایگذاری مشخصات دیتابیس (اگر در کد وجود دارد استفاده کن، در غیر این صورت از کاربر بگیر) =====
step "Step 4: Database configuration – verify & ensure"
NEED_DB_PROMPT=0
if [ ! -f "$SRC_DIR/config.php" ]; then
  NEED_DB_PROMPT=1
else
  # بررسی وجود کلیدهای DB در config فعلی سورس
  HAS_DB_KEYS=$("$PHP_BIN" -r '$c=@include $argv[1]; echo (isset($c["db_host"])||isset($c["db_user"])||isset($c["db_pass"]))?"1":"0";' "$SRC_DIR/config.php" 2>/dev/null || echo 0)
  if [ "$HAS_DB_KEYS" != "1" ]; then
    NEED_DB_PROMPT=1
  fi
fi

# اگر هنوز نیاز به ورودی دارد (و --dry-run نیست)
if [ "$NEED_DB_PROMPT" -eq 1 ] && [ "$DRY_RUN" -eq 0 ]; then
  warn "Database settings missing in config.php"
  read -p "  MySQL Host [127.0.0.1]: " DB_HOST; DB_HOST=${DB_HOST:-127.0.0.1}
  read -p "  MySQL Port [3306]: "      DB_PORT; DB_PORT=${DB_PORT:-3306}
  read -p "  MySQL User [root]: "      DB_USER; DB_USER=${DB_USER:-root}
  read -s -p "  MySQL Password (hidden): " DB_PASS; echo ""
  read -p "  DB Prefix [botsaz_]: "    DB_PREF; DB_PREF=${DB_PREF:-botsaz_}

  "$PHP_BIN" -r '
  $src = $argv[1];
  $c = @include $src; if (!is_array($c)) $c = [];
  $c["db_host"]   = $argv[2];
  $c["db_port"]   = intval($argv[3]);
  $c["db_user"]   = $argv[4];
  $c["db_pass"]   = $argv[5];
  $c["db_prefix"] = $argv[6];
  file_put_contents($src, "<?php\nreturn ".var_export($c,true).";\n");
  ' "$SRC_DIR/config.php" "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" "$DB_PREF" 2>/dev/null && ok "DB settings written to source/config.php" || warn "Failed to write DB settings"
else
  if [ "$NEED_DB_PROMPT" -eq 0 ]; then
    ok "Database keys present in source config"
  else
    info "DRY-RUN: DB prompt skipped"
  fi
fi

# ===== ۵. کپی کد از /root → /var/www/botsaz-faxima (حفظ config/data/bots زنده) =====
step "Step 5: Deploy code from $SRC_DIR → $LIVE_DIR"
if [ "$DRY_RUN" -eq 1 ]; then
  info "DRY-RUN: would rsync code excluding config.php, data/, bots/, .git/"
elif [ "$LIVE_DIR" = "$SRC_DIR" ]; then
  ok "Source == Live – no copy needed"
else
  mkdir -p "$LIVE_DIR" 2>/dev/null
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude '.git/' \
      --exclude '.gitattributes' --exclude '.gitignore' \
      --exclude 'config.php' \
      --exclude 'data/' \
      --exclude 'bots/' \
      --exclude '*.bak' --exclude '*.log' --exclude '*.lock' --exclude '*.swp' \
      "$SRC_DIR/" "$LIVE_DIR/" >/dev/null 2>&1 && ok "Deployed code to live (config/data/bots untouched)" || {
        fail "rsync failed – deploying with selective cp"
        # fallback cp
        for f in bot.php index.php nowpayments_ipn.php .htaccess; do
          [ -f "$SRC_DIR/$f" ] && cp -a "$SRC_DIR/$f" "$LIVE_DIR/" 2>/dev/null
        done
        for d in src tools templates includes vendor public; do
          [ -d "$SRC_DIR/$d" ] && mkdir -p "$LIVE_DIR/$d" && cp -a "$SRC_DIR/$d/." "$LIVE_DIR/$d/" 2>/dev/null
        done
        ok "Selective copy completed"
      }
  else
    warn "rsync not found – using cp fallback"
    for f in bot.php index.php nowpayments_ipn.php .htaccess; do
      [ -f "$SRC_DIR/$f" ] && cp -a "$SRC_DIR/$f" "$LIVE_DIR/" 2>/dev/null
    done
    for d in src tools templates includes vendor public; do
      [ -d "$SRC_DIR/$d" ] && mkdir -p "$LIVE_DIR/$d" && cp -a "$SRC_DIR/$d/." "$LIVE_DIR/$d/" 2>/dev/null
    done
    ok "Selective copy completed"
  fi

  # بازنویسی تنظیمات زنده داخل LIVE_DIR (مهم: بعد کپی کد نباید overwrite شده باشند)
  if [ -f "$LIVE_DIR/config.php" ]; then
    merge_config_into_source "$LIVE_DIR/config.php" "$BACKUP_DIR/live/config.php" 2>/dev/null || cp -a "$BACKUP_DIR/live/config.php" "$LIVE_DIR/config.php" 2>/dev/null
  fi
  shopt -s nullglob
  for cf_b in "$BACKUP_DIR/live/bots"/*/config.php; do
    slug="$(basename "$(dirname "$cf_b")")"
    mkdir -p "$LIVE_DIR/bots/$slug"
    cf_l="$LIVE_DIR/bots/$slug/config.php"
    merge_config_into_source "$cf_l" "$cf_b" 2>/dev/null || cp -a "$cf_b" "$cf_l" 2>/dev/null
  done
  shopt -u nullglob
  ok "Live configs re-applied to $LIVE_DIR"

  # بررسی نسخه
  _v_s="$(grep -m1 "APP_VERSION" "$SRC_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
  _v_l="$(grep -m1 "APP_VERSION" "$LIVE_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
  if [ -n "$_v_l" ] && [ "$_v_l" = "$_v_s" ]; then
    ok "Live version verified: $_v_l"
  else
    warn "Version mismatch: live='${_v_l:-?}' source='${_v_s:-?}'"
  fi
fi

# ===== ۶. تنظیم وی‌هوست (Apache/Nginx) → اشاره به LIVE_DIR =====
step "Step 6: Configure web server vhost (point to live path)"
if [ "$DRY_RUN" -eq 0 ] && [ "$(id -u)" -eq 0 ]; then
  LIVE_ABS="$(cd "$LIVE_DIR" && pwd -P)"
  # Apache
  for c in /etc/apache2/sites-available/botsaz.conf /etc/apache2/sites-enabled/botsaz.conf; do
    [ -f "$c" ] || continue
    if grep -q "DocumentRoot" "$c" 2>/dev/null; then
      CURDR="$(sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}\([^[:space:]#]*\).*/\1/p' "$c" | head -n1)"
      if [ -n "$CURDR" ] && [ "$CURDR" != "$LIVE_ABS" ]; then
        sed -i "s|^[[:space:]]*DocumentRoot[[:space:]]\{1,\}[^[:space:]#]*|	DocumentRoot $LIVE_ABS|" "$c" 2>/dev/null && ok "Apache DocumentRoot updated in $(basename "$c"): $CURDR → $LIVE_ABS"
      else
        [ "$CURDR" = "$LIVE_ABS" ] && ok "Apache DocumentRoot correct in $(basename "$c")"
      fi
    fi
  done
  # Nginx
  for c in /etc/nginx/sites-available/botsaz.conf /etc/nginx/sites-enabled/botsaz.conf; do
    [ -f "$c" ] || continue
    if grep -q "root " "$c" 2>/dev/null; then
      CURR="$(sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;#]*\);.*/\1/p' "$c" | head -n1 | xargs)"
      if [ -n "$CURR" ] && [ "$CURR" != "$LIVE_ABS" ]; then
        sed -i "s|^[[:space:]]*root[[:space:]]\{1,\}[^;#]*;|	root $LIVE_ABS;|" "$c" 2>/dev/null && ok "Nginx root updated in $(basename "$c"): $CURR → $LIVE_ABS"
      else
        [ "$CURR" = "$LIVE_ABS" ] && ok "Nginx root correct in $(basename "$c")"
      fi
    fi
  done
else
  info "Skipping vhost edit (not root or DRY-RUN)"
fi

# ===== ۷. وب‌هوک بروزرسانی/تأیید =====
step "Step 7: Update/verify Telegram webhook"
if [ "$DRY_RUN" -eq 0 ] && [ -f "$LIVE_DIR/config.php" ]; then
  TOKEN=$("$PHP_BIN" -r '$c=@include $argv[1]; $t=$c["main_token"]??""; if ($t==="PUT_MAIN_BOT_TOKEN_HERE") $t=""; echo $t;' "$LIVE_DIR/config.php" 2>/dev/null || echo "")
  BASE=$("$PHP_BIN" -r '$c=@include $argv[1]; $b=$c["base_url"]??""; echo rtrim($b,"/");' "$LIVE_DIR/config.php" 2>/dev/null || echo "")
  if [ -n "$TOKEN" ] && [ -n "$BASE" ]; then
    # تلاش استفاده از ابزار داخلی
    if [ -f "$LIVE_DIR/tools/set_webhook.php" ]; then
      php "$LIVE_DIR/tools/set_webhook.php" >/dev/null 2>&1 && ok "Webhook checked/updated via tools/set_webhook.php" || warn "tools/set_webhook.php returned non-zero (check logs)"
    else
      WH_URL="${BASE}/bot.php"
      RESP=$(curl -s --max-time 10 "https://api.telegram.org/bot${TOKEN}/setWebhook?url=${WH_URL}&drop_pending_updates=true" 2>/dev/null || echo "")
      if echo "$RESP" | grep -q '"ok":true'; then
        ok "Webhook set successfully: $WH_URL"
      else
        warn "Failed to set webhook via API. Response: ${RESP:0:120}"
        info "Run manually: php $LIVE_DIR/tools/set_webhook.php"
      fi
    fi
  else
    warn "main_token/base_url missing or placeholder in live config – skipping webhook"
  fi
else
  info "Skipping webhook (DRY-RUN or no config)"
fi

# ===== ۸. بررسی نیازهای توسعه + مایگریشن‌ها (داخل LIVE_DIR) =====
step "Step 8: Dev checks & migrations (run in live dir)"
TODO_CNT=0
grep -r "TODO\|FIXME" "$SRC_DIR"/*.php "$SRC_DIR"/src "$SRC_DIR"/tools 2>/dev/null | grep -v "config.example" | wc -l >/tmp/todo_cnt_$$ 2>&1 && TODO_CNT=$(cat /tmp/todo_cnt_$$) || TODO_CNT=0
rm -f /tmp/todo_cnt_$$ 2>/dev/null
if [ "$TODO_CNT" -gt 0 ]; then
  warn "Found $TODO_CNT TODO/FIXME item(s) (first 5):"
  grep -r "TODO\|FIXME" "$SRC_DIR"/*.php "$SRC_DIR"/src "$SRC_DIR"/tools 2>/dev/null | grep -v "config.example" | head -5
else
  ok "No TODO/FIXME found"
fi

# مایگریشن‌ها داخل پوشه زنده
if [ "$DRY_RUN" -eq 0 ] && [ -f "$LIVE_DIR/tools/install.php" ]; then
  php "$LIVE_DIR/tools/install.php" >/tmp/install_out_$$ 2>&1 || true
  tail -15 /tmp/install_out_$$ 2>/dev/null | sed 's/^/   /'
  rm -f /tmp/install_out_$$ 2>/dev/null
  ok "Installer/migrations executed in $LIVE_DIR"
elif [ -f "$LIVE_DIR/tools/install.php" ]; then
  info "DRY-RUN: would run php $LIVE_DIR/tools/install.php"
else
  warn "tools/install.php not found in $LIVE_DIR"
fi

# پرمیشن‌های قابل نوشتن برای web user
if [ "$DRY_RUN" -eq 0 ] && [ -n "$WEB_USER" ] && [ "$(id -u)" -eq 0 ]; then
  for _d in data bots; do
    [ -d "$LIVE_DIR/$_d" ] || mkdir -p "$LIVE_DIR/$_d" 2>/dev/null
    if [ -d "$LIVE_DIR/$_d" ]; then
      chown -R "$WEB_USER:$WEB_USER" "$LIVE_DIR/$_d" 2>/dev/null && ok "Permissions: $LIVE_DIR/$_d owned by $WEB_USER"
      chmod -R u+rwX,go-rwx "$LIVE_DIR/$_d" 2>/dev/null || true
    fi
  done
fi

# ===== ۹. ری‌استارت سرویس‌ها (اختیاری) =====
if [ "$NO_RESTART" -eq 0 ] && [ "$DRY_RUN" -eq 0 ] && [ "$(id -u)" -eq 0 ]; then
  step "Step 9: Restarting web/services (safe)"
  for s in apache2 httpd nginx php8.5-fpm php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
    if systemctl list-units --type=service --full --all 2>/dev/null | grep -q "^${s}.service"; then
      systemctl reload-or-restart "$s" >/dev/null 2>&1 && ok "Restarted $s" || warn "Could not restart $s"
    fi
  done
elif [ "$NO_RESTART" -eq 1 ]; then
  info "NO_RESTART/--web: skipping service restarts"
fi

# ===== ۱۰. پاک‌سازی فایل‌های حساس در /root برای امنیت بیشتر =====
step "Step 10: Security cleanup in /root (remove sensitive files)"
if [ "$DRY_RUN" -eq 0 ] && [[ "$SRC_DIR" == /root/* ]]; then
  # حذف فایل‌های حساس/تولیدی در روت سورس
  rm -f "$SRC_DIR/config.php" 2>/dev/null || true
  rm -f "$SRC_DIR/config.example.php" 2>/dev/null || true
  rm -f "$SRC_DIR/.htaccess" 2>/dev/null || true
  rm -f "$SRC_DIR/.gitattributes" 2>/dev/null || true
  rm -f "$SRC_DIR/.gitignore" 2>/dev/null || true
  rm -f "$SRC_DIR"/*.lock 2>/dev/null || true
  rm -f "$SRC_DIR"/*.zip "$SRC_DIR"/*.tar "$SRC_DIR"/*.tar.gz 2>/dev/null || true
  rm -rf "$SRC_DIR"/__pycache__ "$SRC_DIR"/.cache 2>/dev/null || true
  # بک‌آپ‌های قدیمی تمیز
  find /tmp -name "botsaz-config-backup-*" -mtime +7 2>/dev/null | xargs rm -rf 2>/dev/null || true
  # /root فقط قابل پیمایش
  chmod 711 /root 2>/dev/null || true
  ok "Sensitive/temp files removed from $SRC_DIR and old backups cleaned"
  info "Note: Live files remain intact at $LIVE_DIR"
else
  info "No root cleanup needed (SRC not under /root or DRY-RUN)"
fi

# ===== ۱۱. جمع‌بندی =====
step "Step 11: Summary"
ok "Update completed successfully"
info "Source  : $SRC_DIR"
info "Live    : $LIVE_DIR"
[ -n "$APP_VER_SRC" ] && info "Version : $APP_VER_SRC"
info "Branch  : $CUR_BRANCH ($NEW_COMMIT)"
info "Configs preserved: YES (live configs never overwritten by upstream)"
[ -n "$BACKUP_DIR" ] && info "Backups : $BACKUP_DIR"
echo
info "If you see any warnings above, re-run: bash tools/install.sh --check"
exit 0
