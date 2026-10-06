#!/usr/bin/env bash
# ===== بروزرسانی ربات‌ساز از گیت‌هاب (حالت سرور /root → /var/www) =====
# استفاده: bash tools/update.sh [گزینه‌ها]      (راهنما: bash tools/update.sh --help)
#
# فلسفه طراحی (مطابق درخواست کاربر):
#   ۱. بعد از دانلود نسخه جدید در /root، مشخصات کامل از /var/www/botsaz-faxima گرفته می‌شود
#   ۲. تنظیمات زنده (config.php + bots/*/config.php) داخل فایل‌های سورس (/root/...) جایگذاری می‌شوند
#   ۳. سورس تمیز (/root) → کد به /var/www/botsaz-faxima کپی می‌شود (بدون دست‌زدن به config/data/bots زنده)
#   ۴. مشخصات دیتابیس بررسی/جایگذاری می‌شود (اگر در کد موجود است استفاده می‌شود، وگرنه از کاربر گرفته می‌شود)
#   ۵. کلیدهای تنظیماتِ تازه‌ی آپدیت (که هنوز در config زنده نیستند) با مقدار پیش‌فرض اضافه می‌شوند
#   ۶. وی‌هوست (Apache/Nginx) بررسی و اصلاح می‌شود تا به مسیر زنده اشاره کند
#   ۷. وب‌هوک بروزرسانی/تأیید می‌شود
#   ۸. بررسی نیازهای توسعه (TODO/FIXME) + اجرای مایگریشن‌ها + healthcheck داخل پوشه زنده
#   ۹. در انتها فایل‌های حساس داخل /root برای امنیت بیشتر پاک می‌شوند
#
# ===== روش استقرار (نسخهٔ جدید – امن‌تر از rsync --delete) =====
#   فقط فایل‌هایی که در گیت «ردیابی» می‌شوند به زنده کپی می‌شوند؛ یعنی هیچ‌وقت:
#     config.php زنده، data/ (دیتابیس/لاگ)، bots/، کش و لاگِ قالب‌ها، users.json و .env پاک نمی‌شوند.
#   فایل‌هایی که در آپدیت جدید از گیت «حذف» شده‌اند، به‌صورت هدفمند از زنده هم حذف می‌شوند.
#
# آپشن‌ها:
#   --dry-run, -n       فقط پیش‌نمایش؛ هیچ تغییری در فایل‌ها/گیت نمی‌دهد
#   --no-restart        سرویس‌ها ری‌استارت نمی‌شوند
#   --force, -f         ادامه حتی اگر دسترسی به گیت‌هاب قطع باشد
#   --web               مناسب اجرای از داخل ربات (ری‌استارت/ریلود محدود)
#   --templates-only    فقط پوشهٔ templates/ استقرار می‌یابد (کد ربات‌ساز،
#                       دیتابیس، وبهوک و وی‌هوست دست نمی‌خورند؛ ربات‌های
#                       ساخته‌شده هرگز تغییری نمی‌بینند)
#   --rollback          بازگشت کد به وضعیتِ قبل از آخرین بروزرسانی موفق
#   --help, -h          نمایش همین راهنما
#
# ===== باگ‌های رفع‌شده نسبت به نسخهٔ قبل =====
#   1. خط اولِ زائدِ «bash» قبل از #! → اسکریپت اصلاً اجرا نمی‌شد (شلِ تعاملیِ تو در تو باز می‌کرد)
#   2. --dry-run قبلاً reset --hard + git clean انجام می‌داد (مخرب!) → الان هیچ تغییری نمی‌دهد
#   3. تغییرات git محلی قبل از reset پشتیبان‌گیری نمی‌شد → الان در BACKUP_DIR ذخیره می‌شود
#   4. rsync --delete با excludeهای ناقص، داده‌های زنده را پاک می‌کرد (vendorِ قالب‌ها، کش،
#      users.json، .env، لاگ‌ها) → روش «کپی فقط فایل‌های ردیابی‌شده» جایگزین شد
#   5. Step 10 فایل‌های ردیابی‌شده (.htaccess/.gitignore/config.example.php) را از سورس پاک می‌کرد
#      و اگر سورس == زنده بود، config.php «زنده» را هم حذف می‌کرد (از بین رفتن تنظیمات!) → گارددار شد
#   6. تنظیمات DB فقط در سورس نوشته می‌شد و rsync آن را به زنده نمی‌برد → الان به هر دو نوشته می‌شود
#   7. پرسش DB با read وقتی stdin بسته است (cron/ربات/وب) → هنگ می‌کرد → فقط وقتی TTY باشد
#   8. ادغام config با eval روی var_exportِ تک‌خطی‌شده → رشته‌های چندخطی داخل آرایه خراب می‌شدند
#      → الان یک فراخوانی PHP ساده بدون eval و بدون دست‌زدن به متن مقادیر
#   9. «php» در بعضی جاها و «$PHP_BIN» در جاهای دیگر → یکدست شد + خواندن php_bin از config
#  10. خروجی همیشه exit 0 بود (خطاها پنهان می‌شد) → شمارش خطا/هشدار و کد خروجی درست
#  11. قفل فقط با flock (اگر نبود هیچ قفلی نبود) → قفل جایگزین با mkdir + پاک‌سازی با trap
#  12. نبودِ: راهنما (--help)، بازگشت نسخه (--rollback)، ثبت وضعیت، healthcheck بعد از استقرار
#  13. تشخیص live از روی هر وی‌هوستی (حتی وی‌هوستِ کهنه‌ای که config ندارد) → اولویت با نصبِ واقعی
#  14. کلیدهای تنظیماتِ جدیدِ آپدیت هرگز وارد config زنده نمی‌شدند → اکنون اضافه می‌شوند (بدون overwrite)

set +e

# ===== رنگ‌ها و پیام‌ها =====
R='\033[0;31m'; G='\033[0;32m'; Y='\033[1;33m'; B='\033[1;34m'; NC='\033[0m'
ERRORS=0
WARNINGS=0
ok()   { echo -e "${G}✔${NC} $1"; }
warn() { echo -e "${Y}⚠️${NC} $1"; WARNINGS=$((WARNINGS + 1)); }
fail() { echo -e "${R}✘${NC} $1"; ERRORS=$((ERRORS + 1)); }
step() { echo -e "\n${B}━━━ $1 ━━━${NC}"; }
info() { echo -e "   $1"; }
die()  { fail "$1"; exit "${2:-1}"; }

# ===== پاک‌سازی قفل‌های باقی‌ماندهٔ git =====
#
# اگر یک عملیات git وسطِ کار کشته شود (kill وبهوک، Ctrl+C، timeout و …)
# فایل‌هایی مثل .git/index.lock یا .git/refs/remotes/origin/*.lock باقی می‌مانند.
# از آن لحظه به بعد همه‌چیز «نصفه» کار می‌کند: git status و git fetch معمولاً
# سالم‌اند ولی هر git reset/merge با «Unable to create index.lock: File exists»
# می‌شکند — دقیقاً همان خطای گیج‌کننده‌ای که ادمین می‌بیند.
#
# فقط وقتی قفل «بی‌صاحب» است پاک می‌شود:
#   • اگر پروسهٔ git دیگری واقعاً روی همین مخزن کار می‌کند، دست نمی‌زند.
#   • اگر نمی‌شود پروسه‌ها را بازرسی کرد (غیر از لینوکس)، فقط قفلِ ≥۶۰ ثانیه‌ای.
#   • حذف هم که نشد، فقط هشدار می‌دهد تا خطای واقعیِ git بعداً دیده شود.
_stale_git_busy() {
  # خروجی 0 یعنی الان git دیگری روی همین مخزن زنده است (فقط لینوکس با /proc)
  local p cwd exe cl
  [ "$(uname -s 2>/dev/null)" = "Linux" ] && [ -d /proc ] || return 1
  for p in /proc/[0-9]*; do
    exe="$(basename "$(readlink -f "$p/exe" 2>/dev/null)" 2>/dev/null)"
    case "$exe" in git*) ;; *) continue ;; esac
    cwd="$(readlink -f "$p/cwd" 2>/dev/null)"
    case "$cwd" in
      "$SRC_DIR"|"$SRC_DIR"/*) return 0 ;;
    esac
    # «git -C مسیر» یا «--git-dir=…» ممکن است از بیرونِ مخزن اجرا شده باشد
    cl="$(tr '\0' ' ' < "$p/cmdline" 2>/dev/null)"
    case "$cl" in
      *"$SRC_DIR"*) return 0 ;;
    esac
  done
  return 1
}

clear_stale_git_locks() {
  local lock now mt age left=0 have_proc=0

  if _stale_git_busy; then
    warn "Another git process is running on $SRC_DIR – leaving .git lock files alone"
    return 1
  fi
  # جایی که نمی‌شود پروسه‌ها را بازرسی کرد فقط قفلِ مشخصاً کهنه برمی‌داشته می‌شود
  [ "$(uname -s 2>/dev/null)" = "Linux" ] && [ -d /proc ] && have_proc=1
  now="$(date +%s 2>/dev/null || echo '')"

  for lock in "$SRC_DIR/.git/index.lock" \
              "$SRC_DIR/.git/HEAD.lock" \
              "$SRC_DIR/.git/packed-refs.lock" \
              "$SRC_DIR"/.git/refs/remotes/*.lock \
              "$SRC_DIR"/.git/refs/remotes/*/*.lock \
              "$SRC_DIR"/.git/refs/heads/*.lock; do
    [ -f "$lock" ] || continue
    age='?'
    mt="$(stat -c %Y "$lock" 2>/dev/null || echo '')"
    if [ -n "$now" ] && [ -n "$mt" ] && [ "$mt" -le "$now" ] 2>/dev/null; then
      age=$((now - mt))
      if [ "$have_proc" -eq 0 ] && [ "$age" -lt 60 ]; then
        warn "$(basename "$lock") is only ${age}s old – leaving it (a git run may have just started)"
        left=$((left + 1))
        continue
      fi
    elif [ "$have_proc" -eq 0 ]; then
      warn "Cannot read $(basename "$lock") age – leaving it in place"
      left=$((left + 1))
      continue
    fi
    if rm -f "$lock" 2>/dev/null; then
      ok "Removed stale ${lock#"$SRC_DIR"/} (age ${age}s) left by an interrupted git run"
    else
      warn "Cannot remove $lock (permissions?) – git will keep failing until it is gone"
      left=$((left + 1))
    fi
  done
  [ "$left" -eq 0 ] && return 0
  return 1
}

print_help() {
  cat <<'HELP'
  ===== بروزرسانی ربات‌ساز (tools/update.sh) =====

  استفاده:
    bash tools/update.sh [--dry-run] [--no-restart] [--force] [--web]
                         [--templates-only] [--rollback]

  گزینه‌ها:
    --dry-run, -n       فقط پیش‌نمایش؛ هیچ تغییری در فایل‌ها یا گیت نمی‌دهد
    --no-restart        سرویس‌ها (apache/nginx/php-fpm) ری‌استارت نمی‌شوند
    --force, -f         ادامهٔ کار حتی اگر دسترسی به گیت‌هاب قطع باشد
    --web               حالت اجرا از داخل ربات (ری‌استارت محدود)
    --templates-only    فقط پوشهٔ templates/ به‌روز می‌شود؛ کد ربات‌ساز،
                        دیتابیس، وبهوک و وی‌هوست دست نمی‌خورند و ربات‌های
                        ساخته‌شده تغییری نمی‌بینند (نصبِ بعدی نسخهٔ جدید می‌گیرد)
    --rollback          بازگشت کد به وضعیتِ قبل از آخرین بروزرسانی موفق
    --help, -h          نمایش همین راهنما

  متغیر محیطی:
    BOTSAZ_LIVE_DIR=/path   مسیرِ زنده را دستی تعیین می‌کند (تست یا نصب سفارشی)

  خروجی:
    0 = اجرا شد (ممکن است هشدار داشته باشد – پیام‌ها را ببین)
    1 = خطا (fetch، استقرار، قفل همزمان، نبود گیت و …)
    2 = گزینهٔ ناشناخته
HELP
}

# ===== آپشن‌ها =====
DRY_RUN=0
NO_RESTART=0
FORCE=0
WEB=0
ROLLBACK=0
TPL_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run|-n) DRY_RUN=1 ;;
    --no-restart) NO_RESTART=1 ;;
    --force|-f)   FORCE=1 ;;
    --web)        WEB=1; NO_RESTART=1 ;;
    --templates-only) TPL_ONLY=1 ;;
    --rollback)   ROLLBACK=1 ;;
    --help|-h)    print_help; exit 0 ;;
    # گزینهٔ ناشناخته خطاست نه هشدار: تایپ‌اشتباه (مثلاً --dryrun) نباید آپدیت واقعی را اجرا کند!
    *)            die "Unknown option: $1  (run with --help)" 2 ;;
  esac
  shift
done

# ===== ROOT_DIR (سورس git) =====
if [ -z "${BASH_SOURCE[0]:-}" ] || [ ! -f "${BASH_SOURCE[0]}" ]; then
  ROOT_DIR="$(pwd)"
  warn "Running from stdin - using PWD as ROOT_DIR: $ROOT_DIR"
else
  ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT_DIR" || die "Cannot cd to $ROOT_DIR"

command -v git >/dev/null 2>&1 || die "git not found in PATH"

# Git safe.directory
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0="safe.directory"
export GIT_CONFIG_VALUE_0="$ROOT_DIR"

# ===== پیدا کردن PHP (CLI) =====
find_php() {
  local c p
  for c in php php8.5 php8.4 php8.3 php8.2 php8.1 /usr/bin/php /usr/local/bin/php; do
    p="$(command -v "$c" 2>/dev/null)" || p=""
    if [ -n "$p" ] && [ -x "$p" ]; then printf '%s\n' "$p"; return 0; fi
    if [ -x "$c" ]; then printf '%s\n' "$c"; return 0; fi
  done
  return 1
}

# php_bin داخلِ یک config (فایل یا پوشه داده می‌شود).
# فقط خطوطِ فعال خوانده می‌شوند تا مثالِ داخل کامنت انتخاب نشود؛ مسیرِ کامل ویندوز/لاراگون هم پشتیبانی می‌شود.
php_from_config() {
  local _cfg _p
  for _cfg in "$@"; do
    [ -n "$_cfg" ] || continue
    [ -d "$_cfg" ] && _cfg="$_cfg/config.php"
    [ -f "$_cfg" ] || continue
    _p="$(grep -E "^[[:space:]]*['\"]php_bin['\"][[:space:]]*=>" "$_cfg" 2>/dev/null | head -1 \
          | sed -n "s/.*=>[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p")"
    [ -n "$_p" ] || continue
    if command -v "$_p" >/dev/null 2>&1 || [ -x "$_p" ]; then
      printf '%s\n' "$(command -v "$_p" 2>/dev/null || printf '%s' "$_p")"
      return 0
    fi
  done
  return 1
}

have_php() { [ -n "${PHP_BIN:-}" ] && [ -x "$PHP_BIN" ]; }

PHP_BIN="$(find_php 2>/dev/null || true)"
if [ -z "$PHP_BIN" ]; then
  PHP_BIN="$(php_from_config "$ROOT_DIR/config.php" "$ROOT_DIR/config.example.php" \
             "/var/www/botsaz-faxima/config.php" "${BOTSAZ_LIVE_DIR:-}" 2>/dev/null || true)"
fi
# اگر باز هم پیدا نشد، بعد از تشخیص LIVE_DIR دوباره تلاش می‌شود (config زنده مهم‌ترین منبع است)

# ===== تشخیص LIVE_DIR (مسیر زنده وب‌سرور) =====
detect_web_user() {
  for u in www-data nginx apache httpd; do
    id -u "$u" >/dev/null 2>&1 && { printf '%s' "$u"; return 0; }
  done
  return 1
}

# همهٔ DocumentRoot/root هایی که به این پروژه تعلق دارند
candidate_docroots() {
  local c d
  for c in /etc/apache2/sites-available/*.conf /etc/apache2/sites-enabled/*.conf \
           /etc/nginx/sites-available/*.conf /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [ -f "$c" ] || continue
    grep -qE "botsaz|faxima|/var/www/botsaz" "$c" 2>/dev/null || continue
    d="$(sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}\([^[:space:]#]*\).*/\1/p' "$c" 2>/dev/null | head -n1)"
    [ -z "$d" ] && d="$(sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;#]*\);.*/\1/p' "$c" 2>/dev/null | head -n1)"
    [ -n "$d" ] && printf '%s\n' "$d"
  done
  printf '%s\n' "/var/www/botsaz-faxima"
}

# اولویت با مسیری است که bot.php + config.php دارد (نصبِ واقعی)، نه صرفاً اولین وی‌هوست
pick_live_dir() {
  local cand best=""
  while IFS= read -r cand; do
    [ -n "$cand" ] && [ -d "$cand" ] || continue
    cand="$(cd "$cand" 2>/dev/null && pwd -P)" || continue
    [ -f "$cand/bot.php" ] || continue
    if [ -f "$cand/config.php" ]; then printf '%s' "$cand"; return 0; fi
    [ -z "$best" ] && best="$cand"
  done <<EOF
$(candidate_docroots | awk '!seen[$0]++')
EOF
  [ -n "$best" ] && { printf '%s' "$best"; return 0; }
  return 1
}

SRC_DIR="$(cd "$ROOT_DIR" && pwd -P)"
WEB_USER="$(detect_web_user || true)"

_live=""
if [ -n "${BOTSAZ_LIVE_DIR:-}" ]; then
  # تخصیص دستیِ مسیر زنده (برای تست یا نصب سفارشی)
  _live="$BOTSAZ_LIVE_DIR"
  info "LIVE_DIR override (BOTSAZ_LIVE_DIR): $_live"
else
  _live="$(pick_live_dir 2>/dev/null || true)"
fi
if [ -n "$_live" ]; then
  LIVE_DIR="$_live"
else
  LIVE_DIR="$SRC_DIR"
fi

# تلاش دوباره برای PHP: مهم‌ترین منبع، config زنده است (php_bin ممکن است فقط همان‌جا باشد)
if ! have_php; then
  PHP_BIN="$(php_from_config "$LIVE_DIR/config.php" "$SRC_DIR/config.php" "$SRC_DIR/config.example.php" 2>/dev/null || true)"
fi
if ! have_php; then
  PHP_BIN=""
  warn "PHP CLI not found – config merge / installer / healthcheck will be skipped"
  info "Fix: install php-cli, or set 'php_bin' in $LIVE_DIR/config.php to the full path"
fi

# هشدار: وی‌هوستِ کهنه/دیگری که مسیری متفاوت و قدیمی سرو می‌کند
while IFS= read -r _other; do
  [ -n "$_other" ] || continue
  [ -f "$_other/bot.php" ] || continue
  [ "$_other" = "$LIVE_DIR" ] && continue
  warn "Another docroot serves an old copy: $_other  (fix its DocumentRoot/root to $LIVE_DIR)"
done <<EOF
$(candidate_docroots | awk '!seen[$0]++')
EOF

# ===== قفل همزمان =====
mkdir -p "$SRC_DIR/data" 2>/dev/null || true
LOCK_DIR="$SRC_DIR/data/update.lock.d"
LOCK_FD_OPEN=0
ACQUIRED_LOCK=0
if command -v flock >/dev/null 2>&1; then
  # { exec 9>...; } داخل آکولاد است تا فقط stderrِ همین بلاک خفّ شود؛
  # نوشتنِ exec 9>file 2>/dev/null باعث می‌شد stderrِ کل اسکریپت برای همیشه به /dev/null برود!
  if { exec 9>"$SRC_DIR/data/update.lock"; } 2>/dev/null; then
    LOCK_FD_OPEN=1
    if flock -n 9 2>/dev/null; then
      ACQUIRED_LOCK=1
    fi
  fi
fi
if [ "$ACQUIRED_LOCK" -ne 1 ]; then
  if mkdir "$LOCK_DIR" 2>/dev/null; then
    ACQUIRED_LOCK=1
  fi
fi
if [ "$ACQUIRED_LOCK" -ne 1 ]; then
  die "Another update is already running (lock: $SRC_DIR/data/update.lock) – exiting."
fi
cleanup_lock() {
  [ -d "$LOCK_DIR" ] && rmdir "$LOCK_DIR" 2>/dev/null
  if [ "$LOCK_FD_OPEN" = "1" ]; then
    exec 9>&- 2>/dev/null || true
  fi
  return 0
}
trap cleanup_lock EXIT

# ===== پیش‌بینی =====
step "Step 0: Pre-flight checks"
if [ ! -d "$SRC_DIR/.git" ]; then
  die "No .git directory in $SRC_DIR"
fi
if ! command -v curl >/dev/null 2>&1; then
  warn "curl not found – cannot check GitHub reachability"
elif [ "$FORCE" -eq 0 ]; then
  curl -s --max-time 3 https://github.com >/dev/null 2>&1 || warn "Cannot reach GitHub (continuing with local state)"
fi
DISK_AVAIL="$(df -Pk "$SRC_DIR" 2>/dev/null | awk 'NR==2{print $4}')"
case "$DISK_AVAIL" in ''|*[!0-9]*) DISK_AVAIL="" ;; esac
if [ -n "$DISK_AVAIL" ] && [ "$DISK_AVAIL" -lt 51200 ]; then
  die "Low disk space ($DISK_AVAIL KB) < 50MB"
fi
if ! git symbolic-ref -q HEAD >/dev/null 2>&1; then
  die "Detached HEAD - checkout a branch first (git checkout main)"
fi
if ! git remote get-url origin >/dev/null 2>&1; then
  [ "$FORCE" -eq 1 ] && warn "No 'origin' remote – continuing with local commits only" || die "No 'origin' remote configured"
fi
CUR_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)"
CUR_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo unknown)"

step "Step 0b: Layout detection"
info "source (git)  : $SRC_DIR"
info "live (served) : $LIVE_DIR"
[ -n "$PHP_BIN" ] && info "php binary    : $PHP_BIN"
if [ "$TPL_ONLY" -eq 1 ]; then
  info "mode          : templates-only (only templates/ will be deployed)"
fi
if [ "$LIVE_DIR" = "$SRC_DIR" ]; then
  ok "Single-directory layout"
  [ "$TPL_ONLY" -eq 1 ] && info "--templates-only in a single directory: git syncs the whole tree (only templates/ is reported)"
else
  ok "Split layout (source → live): $SRC_DIR → $LIVE_DIR"
  [ -f "$LIVE_DIR/config.php" ] || warn "No config.php in live dir – it will be bootstrapped from source"
fi
[ -n "$WEB_USER" ] && info "web user      : $WEB_USER" || warn "No web user detected"
APP_VER_SRC="$(grep -m1 "APP_VERSION" "$SRC_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
[ -n "$APP_VER_SRC" ] && info "source version: $APP_VER_SRC" || warn "APP_VERSION not found in src/Manager.php"

# ===== ۱. بک‌آپ کامل تنظیمات زنده + تغییرات محلی گیت =====
step "Step 1: Backing up live configuration files"
BACKUP_DIR="/tmp/botsaz-config-backup-$(date +%Y%m%d%H%M%S)"
mkdir -p "$BACKUP_DIR" 2>/dev/null || BACKUP_DIR="$(mktemp -d /tmp/botsaz-config-backup.XXXXXX 2>/dev/null || echo /tmp)"
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

# پشتیبان از تغییرات git محلی، قبل از هر reset/clean
git status --porcelain > "$BACKUP_DIR/local_git_status.txt" 2>/dev/null
git diff > "$BACKUP_DIR/local_changes.patch" 2>/dev/null
git diff --cached >> "$BACKUP_DIR/local_changes.patch" 2>/dev/null
if [ -s "$BACKUP_DIR/local_changes.patch" ]; then
  info "Local git changes found – saved to $BACKUP_DIR/local_changes.patch"
fi
info "Backup dir: $BACKUP_DIR"

# قفلِ باقی‌ماندهٔ index.lock را قبل از هر نوشتنی در git برمی‌داریم. اگر یک اجرای
# قبلی وسطِ کار کشته شده باشد این فایل می‌ماند و بعداً هر reset/merge را با
# «Unable to create index.lock: File exists» می‌کُشد، در حالی که status و fetch
# همچنان سالم‌اند. در dry-run فقط هشدار می‌دهیم و چیزی حذف نمی‌کنیم.
if [ "$DRY_RUN" -eq 1 ]; then
  if [ -f "$SRC_DIR/.git/index.lock" ]; then
    warn "A stale .git/index.lock exists – a real run would remove it first"
  fi
else
  clear_stale_git_locks
fi

# فایل وضعیت (برای --rollback) – مسیرش را زودتر تعریف می‌کنیم
STATE_FILE="$SRC_DIR/data/last_update.state"
# آخرین نسخه‌ای که واقعاً روی زنده استقرار یافته (برای حذفِ فایل‌های حذف‌شده در آپدیت جدید)
LAST_DEPLOYED="$(sed -n 's/^NEW=//p' "$STATE_FILE" 2>/dev/null | head -1)"
if [ -n "$LAST_DEPLOYED" ]; then
  git cat-file -e "${LAST_DEPLOYED}^{commit}" 2>/dev/null || LAST_DEPLOYED=""
fi

# ===== ۲. fetch و پاک‌سازی درخت git (جلوگیری از «Local tracked files modified») =====
step "Step 2: Fetching latest code (clean git tree)"
DO_RESET=0
RESET_OCCURRED=0
ROLLBACK_TARGET=""
if [ "$ROLLBACK" -eq 1 ]; then
  # ===== حالت بازگشت: کد به آخرین نسخهٔ قبل از آپدیت برمی‌گردد =====
  [ -f "$STATE_FILE" ] || die "--rollback: no recorded state ($STATE_FILE) – nothing to roll back"
  REC_PREV="$(sed -n 's/^PREV=//p' "$STATE_FILE" | head -1)"
  REC_NEW="$(sed -n 's/^NEW=//p' "$STATE_FILE" | head -1)"
  REC_BACKUP="$(sed -n 's/^BACKUP=//p' "$STATE_FILE" | head -1)"
  [ -n "$REC_PREV" ] || die "--rollback: invalid state file ($STATE_FILE)"
  git cat-file -e "${REC_PREV}^{commit}" 2>/dev/null || die "--rollback: commit $REC_PREV not found locally"
  info "Rolling back: ${REC_NEW:-?} → $REC_PREV"
  [ -n "$REC_BACKUP" ] && [ -d "$REC_BACKUP" ] && info "Config backup of that update: $REC_BACKUP"
  ROLLBACK_TARGET="$REC_PREV"
  if [ "$DRY_RUN" -eq 1 ]; then
    info "DRY-RUN: would reset source to $REC_PREV and redeploy it"
    git --no-pager diff --stat "$REC_PREV" "${REC_NEW:-$REC_PREV}" 2>/dev/null | tail -10 | sed 's/^/   /'
  else
    if [ -s "$BACKUP_DIR/local_changes.patch" ]; then
      warn "Local changes were backed up before rollback: $BACKUP_DIR/local_changes.patch"
    fi
    _rb_err="$(git reset --hard "$REC_PREV" 2>&1)"
    if [ $? -ne 0 ]; then
      fail "git reset --hard $REC_PREV failed:"
      [ -n "$_rb_err" ] && printf '%s\n' "$_rb_err" | sed 's/^/   /'
      die "git reset --hard $REC_PREV failed"
    fi
    RESET_OCCURRED=1
    ok "Source reset to $REC_PREV"
  fi
else
  _fetch_err="$(git fetch --prune origin 2>&1)"
  if [ $? -eq 0 ]; then
    ok "Fetched origin/$CUR_BRANCH"
  else
    [ -n "$_fetch_err" ] && { warn "git fetch output:"; printf '%s\n' "$_fetch_err" | sed 's/^/   /'; }
    if [ "$FORCE" -eq 1 ]; then
      warn "git fetch failed – continuing with local commits (--force)"
    else
      die "git fetch failed for origin/$CUR_BRANCH (use --force to continue offline)"
    fi
  fi
  if ! git rev-parse --verify "origin/$CUR_BRANCH" >/dev/null 2>&1; then
    [ "$FORCE" -eq 1 ] && warn "origin/$CUR_BRANCH not found – using local HEAD" || die "origin/$CUR_BRANCH not found"
  else
    DO_RESET=1
  fi
fi

if [ "$DO_RESET" -eq 1 ]; then
  if [ "$DRY_RUN" -eq 1 ]; then
    _pending="$(git rev-list --count "HEAD..origin/$CUR_BRANCH" 2>/dev/null || echo 0)"
    info "DRY-RUN: $_pending new commit(s) pending on origin/$CUR_BRANCH"
    git --no-pager log --oneline "HEAD..origin/$CUR_BRANCH" 2>/dev/null | head -10 | sed 's/^/   /'
    info "DRY-RUN: would reset local tracked changes and copy code to live"
  else
    LOCAL_MOD="$(git status --porcelain --untracked-files=no 2>/dev/null | grep -c .)"
    case "$LOCAL_MOD" in ''|*[!0-9]*) LOCAL_MOD=0 ;; esac
    # برنامهٔ فایل‌های untracked را قبل از هر چیز ذخیره می‌کنیم (data/bots هرگز حذف نمی‌شوند)
    git clean -fdn -e data -e bots > "$BACKUP_DIR/clean_plan.txt" 2>/dev/null
    if [ "$LOCAL_MOD" -gt 0 ]; then
      warn "$LOCAL_MOD tracked file(s) modified locally – resetting to origin/$CUR_BRANCH"
      info "Locally modified tracked files:"
      git status --short --untracked-files=no 2>/dev/null | head -10 | sed 's/^/   /'
      info "Their diff is saved: $BACKUP_DIR/local_changes.patch"
      _reset_err="$(git reset --hard "origin/$CUR_BRANCH" 2>&1)"
      _reset_rc=$?
      if [ "$_reset_rc" -ne 0 ]; then
        # خطای واقعیِ git را حتماً نشان بده (قبلاً پشت 2>/dev/null پنهان می‌شد)
        fail "git reset --hard origin/$CUR_BRANCH failed:"
        [ -n "$_reset_err" ] && printf '%s\n' "$_reset_err" | sed 's/^/   /'
        _first_err="$(printf '%s' "$_reset_err" | head -1)"
        case "$_first_err" in
          *'File exists'*)
            die "git could not create .git/index.lock (another git run left it behind). Remove it manually: rm -f $SRC_DIR/.git/index.lock" ;;
          *Permission*|*permission*|*'Read-only'*|*denied*)
            # خودکارسازیِ دسترسی: اگر sudo بدون رمز در دسترس است، مالکیتِ
            # درخت را به کاربرِ وب (WEB_USER) برگردانید — نه به کاربرِ فعلی.
            # آپدیت‌های وبهوک به‌عنوانِ کاربرِ وب اجرا می‌شوند؛ اگر مالک
            # فردِ اجراکننده (مثلاً root یا کاربرِ SSH) شود، همان خطای
            # «unable to unlink … Permission denied» برای وبهوک دوباره
            # تکرار می‌شود. chown به WEB_USER هر دو مسیر را سالم نگه می‌دارد.
            if command -v sudo >/dev/null 2>&1 && [ -n "$WEB_USER" ] \
               && sudo -n chown -R "$WEB_USER:$WEB_USER" "$SRC_DIR" 2>/dev/null; then
              info "Ownership of $SRC_DIR set to $WEB_USER:$WEB_USER — retrying reset"
              _reset_err2="$(git reset --hard "origin/$CUR_BRANCH" 2>&1)"
              if [ $? -eq 0 ]; then
                RESET_OCCURRED=1
                _self_heal_done=1
                ok "Source tree reset to clean origin/$CUR_BRANCH (after ownership self-heal)"
              else
                printf '%s\n' "$_reset_err2" | sed 's/^/   /'
                die "git reset --hard still failed after self-heal"
              fi
            else
              warn "sudo -n chown to $WEB_USER not possible (webhook runs as $WEB_USER without sudo)"
              die "No write permission on the source tree ($SRC_DIR). Run once as root: sudo bash tools/fix_source_update_permissions.sh $SRC_DIR"
            fi
            ;;
        esac
        # اگر محتوای فایل‌ها از قبل دقیقاً با origin یکی است، نوشتنِ فایل‌ها لازم
        # نیست؛ کافی است HEAD و index جلو بروند (reset معمولی بدون --hard).
        if [ "${_self_heal_done:-0}" -eq 0 ]; then
          if git diff --quiet "origin/$CUR_BRANCH" 2>/dev/null; then
            info "Working tree already matches origin/$CUR_BRANCH – only fast-forwarding HEAD"
            _ff_err="$(git reset "origin/$CUR_BRANCH" 2>&1)"
            if [ $? -ne 0 ]; then
              fail "git reset (mixed) also failed:"
              printf '%s\n' "$_ff_err" | sed 's/^/   /'
              die "git reset failed"
            fi
            RESET_OCCURRED=1
            ok "Source HEAD fast-forwarded to origin/$CUR_BRANCH (no file had to be rewritten)"
          else
            die "git reset --hard failed: ${_first_err:-unknown git error}"
          fi
        fi
      else
        RESET_OCCURRED=1
        ok "Source tree reset to clean origin/$CUR_BRANCH"
      fi
    else
      ok "Working tree clean"
    fi
    # فایل‌های untracked فقط با --force حذف می‌شوند (پاک‌سازیِ بی‌اجازه ممکن است کارِ انجام‌شدهٔ کاربر را ببرد)
    if [ -s "$BACKUP_DIR/clean_plan.txt" ]; then
      _clean_n="$(grep -c . "$BACKUP_DIR/clean_plan.txt" 2>/dev/null)"
      if [ "$FORCE" -eq 1 ]; then
        git clean -fd -e data -e bots >/dev/null 2>&1 || warn "git clean failed"
        ok "Removed $_clean_n untracked file(s) (listed in $BACKUP_DIR/clean_plan.txt)"
      else
        info "$_clean_n untracked file(s) kept (use --force to remove). First few:"
        head -5 "$BACKUP_DIR/clean_plan.txt" | sed 's/^/   /'
      fi
    fi
    # HEAD را با origin جلو می‌بریم. خطا را قبلاً کاملاً بی‌صدا نادیده می‌گرفتیم
    # (|| true) و آپدیتِ بی‌نتیجه «موفق» گزارش می‌شد؛ حالا هشدار دیده می‌شود.
    _merge_err="$(git merge --ff-only "origin/$CUR_BRANCH" 2>&1)"
    if [ $? -ne 0 ]; then
      case "$_merge_err" in
        *'not something we can merge'*|*'Already up to date'*|*'Already up-to-date'*) : ;;
        *) warn "git merge --ff-only origin/$CUR_BRANCH did not fast-forward:"; printf '%s\n' "$_merge_err" | sed 's/^/   /' ;;
      esac
    fi
  fi
fi

NEW_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
if [ "$ROLLBACK" -eq 1 ] && [ "$DRY_RUN" -eq 1 ]; then
  # در dry-run بازگشت، reset انجام نشده → هدف را دستی می‌گذاریم تا بقیهٔ محاسبات درست بماند
  NEW_COMMIT="$ROLLBACK_TARGET"
fi
if [ "$ROLLBACK" -eq 1 ]; then
  [ "$CUR_COMMIT" != "$NEW_COMMIT" ] && ok "Rolling back code: $CUR_COMMIT → $NEW_COMMIT" || warn "Already at $NEW_COMMIT"
elif [ "$CUR_COMMIT" != "$NEW_COMMIT" ]; then
  ok "Updated: $CUR_COMMIT → $NEW_COMMIT"
else
  ok "Already up to date ($NEW_COMMIT)"
fi

# ===== ۳. جایگذاری تنظیمات زنده داخل سورس =====
# در حالت --templates-only هیچ فایلِ کدِ ربات‌ساز استقرار نمی‌یابد؛ پس ادغام config،
# پرسشِ دیتابیس و افزودنِ کلیدهای جدید همگی بی‌معنی شده و کلاً رد می‌شوند.
if [ "$TPL_ONLY" -eq 0 ]; then
step "Step 3: Extract live settings and merge into source ($SRC_DIR)"

# ادغام config زنده در config سورس (یک فراخوانی PHP – بدون eval و بدون خراب‌شدن رشته‌های چندخطی)
merge_config_into_source() {
  local src_cfg="$1" live_cfg="$2" out rc
  [ -f "$live_cfg" ] || return 1
  have_php || return 1
  if [ ! -f "$src_cfg" ]; then
    cp "$SRC_DIR/config.example.php" "$src_cfg" 2>/dev/null || touch "$src_cfg"
  fi
  out="$("$PHP_BIN" -r '
    $src = $argv[1]; $live = $argv[2];
    $c = @include $src;  if (!is_array($c)) $c = [];
    $l = @include $live; if (!is_array($l)) { fwrite(STDERR, "live config is not an array\n"); exit(1); }
    foreach ($l as $k => $v) { $c[$k] = $v; }
    $txt = "<?php\nreturn " . var_export($c, true) . ";\n";
    if (file_put_contents($src, $txt) === false) { fwrite(STDERR, "cannot write source config\n"); exit(1); }
    echo count($l);
  ' "$src_cfg" "$live_cfg" 2>"$BACKUP_DIR/merge_cfg.err")"
  rc=$?
  if [ $rc -eq 0 ] && [ -n "$out" ]; then
    MERGED_KEYS="$out"
    return 0
  fi
  [ -s "$BACKUP_DIR/merge_cfg.err" ] && info "merge error: $(head -3 "$BACKUP_DIR/merge_cfg.err")"
  return 1
}

# اضافه‌کردن کلیدهای جدیدِ آپدیت به config زنده (فقط کلیدهای غایب؛ مقدارهای موجود دست نمی‌خورند)
ensure_live_config_keys() {
  local live_cfg="$1" def_cfg="$2" added
  [ -f "$live_cfg" ] && [ -f "$def_cfg" ] || return 0
  have_php || return 0
  added="$("$PHP_BIN" -r '
    $live = $argv[1]; $def = $argv[2];
    $l = @include $live; if (!is_array($l)) exit(0);
    $d = @include $def;  if (!is_array($d)) exit(0);
    $added = [];
    foreach ($d as $k => $v) { if (!array_key_exists($k, $l)) { $l[$k] = $v; $added[] = $k; } }
    if (!$added) { echo "0"; exit(0); }
    $txt = "<?php\nreturn " . var_export($l, true) . ";\n";
    if (file_put_contents($live, $txt) === false) { fwrite(STDERR, "cannot write live config\n"); exit(1); }
    echo implode(", ", $added);
  ' "$live_cfg" "$def_cfg" 2>"$BACKUP_DIR/ensure_cfg.err")"
  _erc=$?
  case "$added" in
    "")  if [ "$_erc" -eq 0 ]; then
           info "Live config already has every upstream key"
         else
           warn "Could not add new keys to live config (php exit $_erc)"
           [ -s "$BACKUP_DIR/ensure_cfg.err" ] && info "$(head -3 "$BACKUP_DIR/ensure_cfg.err")"
         fi ;;
    "0") info "Live config already has every upstream key" ;;
    *)   ok "Added new config key(s) to live config: $added" ;;
  esac
  return 0
}

MERGED_KEYS=0
if [ "$LIVE_DIR" = "$SRC_DIR" ]; then
  ok "Single-directory layout – live config IS the source config (merge skipped, comments kept)"
elif [ "$DRY_RUN" -eq 1 ]; then
  info "DRY-RUN: would merge live config keys into $SRC_DIR/config.php"
else
  if [ ! -f "$LIVE_DIR/config.php" ]; then
    info "No live config.php yet – source keeps config.example.php defaults"
  elif merge_config_into_source "$SRC_DIR/config.php" "$LIVE_DIR/config.php"; then
    ok "Live config.php merged into source tree ($MERGED_KEYS key(s))"
  elif ! have_php; then
    cp -a "$LIVE_DIR/config.php" "$SRC_DIR/config.php" 2>/dev/null \
      && info "PHP CLI missing – live config copied to source instead of merging" \
      || warn "Could not copy live config.php to source"
  else
    cp -a "$LIVE_DIR/config.php" "$SRC_DIR/config.php" 2>/dev/null \
      && ok "Live config.php copied to source (merge failed)" \
      || warn "Could not copy live config.php to source"
  fi

  # ۳.۲ ربات‌های فرزند
  shopt -s nullglob
  _cbc=0
  for cf_live in "$LIVE_DIR"/bots/*/config.php; do
    slug="$(basename "$(dirname "$cf_live")")"
    mkdir -p "$SRC_DIR/bots/$slug"
    cf_src="$SRC_DIR/bots/$slug/config.php"
    if merge_config_into_source "$cf_src" "$cf_live"; then
      _cbc=$((_cbc + 1))
    else
      cp -a "$cf_live" "$cf_src" 2>/dev/null && _cbc=$((_cbc + 1))
    fi
  done
  shopt -u nullglob
  [ "$_cbc" -gt 0 ] && ok "Merged $_cbc child bot config(s) into source" || info "No child bot configs found in live"
fi

# ===== ۴. بررسی/جایگذاری مشخصات دیتابیس =====
step "Step 4: Database configuration – verify & ensure"
# منبعِ بررسی: config زنده (مهم‌تر) وگرنه config سورس
CHECK_CFG=""
if [ -f "$LIVE_DIR/config.php" ]; then
  CHECK_CFG="$LIVE_DIR/config.php"
elif [ -f "$SRC_DIR/config.php" ]; then
  CHECK_CFG="$SRC_DIR/config.php"
fi

NEED_DB_PROMPT=0
if [ -z "$CHECK_CFG" ]; then
  NEED_DB_PROMPT=1
elif have_php; then
  HAS_DB_KEYS="$("$PHP_BIN" -r '$c=@include $argv[1]; echo (is_array($c)&&(isset($c["db_host"])||isset($c["db_user"])||isset($c["db_pass"])))?"1":"0";' "$CHECK_CFG" 2>/dev/null)"
  [ "$HAS_DB_KEYS" != "1" ] && NEED_DB_PROMPT=1
else
  grep -q "db_host" "$CHECK_CFG" 2>/dev/null || NEED_DB_PROMPT=1
fi

write_db_settings() {
  local cfg="$1"
  have_php || return 1
  "$PHP_BIN" -r '
    $src = $argv[1];
    $c = @include $src; if (!is_array($c)) $c = [];
    $c["db_host"]   = $argv[2];
    $c["db_port"]   = intval($argv[3]);
    $c["db_user"]   = $argv[4];
    $c["db_pass"]   = $argv[5];
    $c["db_prefix"] = $argv[6];
    if (file_put_contents($src, "<?php\nreturn " . var_export($c, true) . ";\n") === false) exit(1);
  ' "$cfg" "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" "$DB_PREF" 2>/dev/null
}

if [ "$NEED_DB_PROMPT" -eq 1 ]; then
  if [ "$DRY_RUN" -eq 1 ]; then
    info "DRY-RUN: DB settings missing – would ask for them interactively"
  elif [ ! -t 0 ]; then
    warn "Database settings missing in config and stdin is not a terminal (cron/web) – skipping prompt"
    info "Run interactively: bash tools/update.sh"
  else
    warn "Database settings missing in config.php"
    read -r -p "  MySQL Host [127.0.0.1]: " DB_HOST; DB_HOST=${DB_HOST:-127.0.0.1}
    read -r -p "  MySQL Port [3306]: "      DB_PORT; DB_PORT=${DB_PORT:-3306}
    read -r -p "  MySQL User [root]: "      DB_USER; DB_USER=${DB_USER:-root}
    read -r -s -p "  MySQL Password (hidden): " DB_PASS; echo ""
    read -r -p "  DB Prefix [botsaz_]: "    DB_PREF; DB_PREF=${DB_PREF:-botsaz_}

    # در حالت دوپوشه باید هم سورس و هم زنده تنظیم شوند (کپی‌کردنِ بعدی، config را جا نمی‌اندازد)
    _wrote=0
    if [ -f "$SRC_DIR/config.php" ] || cp "$SRC_DIR/config.example.php" "$SRC_DIR/config.php" 2>/dev/null; then
      write_db_settings "$SRC_DIR/config.php" && _wrote=1 && ok "DB settings written to source/config.php"
    fi
    if [ "$LIVE_DIR" != "$SRC_DIR" ]; then
      if [ -f "$LIVE_DIR/config.php" ]; then
        write_db_settings "$LIVE_DIR/config.php" && _wrote=1 && ok "DB settings written to live/config.php"
      fi
    fi
    [ "$_wrote" -eq 1 ] || warn "Failed to write DB settings"
  fi
else
  ok "Database keys present in $CHECK_CFG"
fi

# کلیدهای جدیدِ آپدیت را به config زنده اضافه کن (قبل از استقرار/مایگریشن)
if [ "$LIVE_DIR" != "$SRC_DIR" ] && [ "$DRY_RUN" -eq 0 ] && [ -f "$LIVE_DIR/config.php" ]; then
  if have_php; then
    _def="$SRC_DIR/config.php"
    [ -f "$_def" ] || _def="$SRC_DIR/config.example.php"
    ensure_live_config_keys "$LIVE_DIR/config.php" "$_def"
  else
    info "PHP CLI missing – cannot add new upstream keys to live config"
  fi
fi
else
  info "--templates-only: config merge and database checks skipped (no app code is deployed)"
fi  # end of: not --templates-only

# ===== ۵. کپی کد از سورس → زنده (فقط فایل‌های ردیابی‌شدهٔ گیت) =====
step "Step 5: Deploy code from $SRC_DIR → $LIVE_DIR"

TRACKED_LIST="$BACKUP_DIR/tracked_files.list"
# --templates-only ⇒ فقط فایل‌های ردیابی‌شدهٔ templates/ استقرار می‌یابند
if [ "$TPL_ONLY" -eq 1 ]; then
  git ls-files -z -- templates/ > "$TRACKED_LIST" 2>/dev/null
else
  git ls-files -z > "$TRACKED_LIST" 2>/dev/null
fi
TRACKED_COUNT="$(tr -cd '\0' < "$TRACKED_LIST" 2>/dev/null | wc -c | tr -d ' ')"

# فایل‌هایی که در کامیت جدید از گیت حذف شده‌اند و باید از زنده هم بروند
plan_removed_files() { # $1=from-commit  $2=to-commit
  if [ "$TPL_ONLY" -eq 1 ]; then
    # فقط حذف‌های داخل templates/؛ کدِ ربات‌ساز نباید دست بخورد
    git diff --no-renames --name-only --diff-filter=D "$1" "$2" -- templates/ 2>/dev/null | sed '/^$/d' > "$BACKUP_DIR/removed_from_live.txt"
  else
    git diff --no-renames --name-only --diff-filter=D "$1" "$2" 2>/dev/null | sed '/^$/d' > "$BACKUP_DIR/removed_from_live.txt"
  fi
  [ -s "$BACKUP_DIR/removed_from_live.txt" ] || : > "$BACKUP_DIR/removed_from_live.txt"
}

apply_removed_files() {
  local p n=0
  [ -s "$BACKUP_DIR/removed_from_live.txt" ] || { REMOVED_COUNT=0; return 0; }
  while IFS= read -r p; do
    [ -n "$p" ] || continue
    case "$p" in /*|*..*) continue ;; esac        # مسیر ناامن را نادیده بگیر
    case "$p" in data/*|bots/*|config.php|config.example.php) continue ;; esac  # دست‌نخورده
    [ -f "$LIVE_DIR/$p" ] || continue
    if [ "$DRY_RUN" -eq 1 ]; then
      info "[dry-run] would remove from live: $p"
    else
      rm -f "$LIVE_DIR/$p" 2>/dev/null && n=$((n + 1))
    fi
  done < "$BACKUP_DIR/removed_from_live.txt"
  REMOVED_COUNT="$n"
}

if [ "$LIVE_DIR" = "$SRC_DIR" ]; then
  # تک‌پوشه: گیت خودش درختِ کاری را همگام می‌کند (فایل‌های حذف‌شده هم با reset پاک می‌شوند)
  if [ "$TPL_ONLY" -eq 1 ]; then
    info "--templates-only: single directory – git already synced the tree above (no copy step)"
  fi
  if [ "$DRY_RUN" -eq 1 ]; then
    info "DRY-RUN: source == live – would sync the working tree with git (no file copy)"
    plan_removed_files "${LAST_DEPLOYED:-$CUR_COMMIT}" "$NEW_COMMIT"
    apply_removed_files
    if [ "${REMOVED_COUNT:-0}" -gt 0 ]; then
      info "$REMOVED_COUNT file(s) would disappear from the working tree on the real run"
    fi
  else
    ok "Source == Live – no copy needed (git already synced the working tree)"
  fi
elif [ "$DRY_RUN" -eq 1 ]; then
  info "DRY-RUN: would copy $TRACKED_COUNT tracked file(s) to $LIVE_DIR (config/data/bots untouched)"
  plan_removed_files "${LAST_DEPLOYED:-$CUR_COMMIT}" "$NEW_COMMIT"
  apply_removed_files
else
  mkdir -p "$LIVE_DIR" 2>/dev/null
  _deploy_rc=1
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --from0 --files-from="$TRACKED_LIST" "$SRC_DIR/" "$LIVE_DIR/" > "$BACKUP_DIR/rsync.log" 2>&1
    _deploy_rc=$?
    [ "$_deploy_rc" -ne 0 ] && warn "rsync failed (rc=$_deploy_rc): $(tail -3 "$BACKUP_DIR/rsync.log" 2>/dev/null | tr '\n' ' ')"
  else
    # tar همیشه هست؛ لیست فایل‌ها NUL جدا شده تا مسیرهای دارای فاصله/آپاستروف سالم بمانند
    ( cd "$SRC_DIR" && tar --null -T "$TRACKED_LIST" -cf - ) 2>"$BACKUP_DIR/tar.log" | tar -xf - -C "$LIVE_DIR" 2>>"$BACKUP_DIR/tar.log"
    _st=( "${PIPESTATUS[@]}" )
    _deploy_rc=0
    [ "${_st[0]:-1}" = "0" ] || { _deploy_rc=1; warn "tar (read) failed: $(tail -3 "$BACKUP_DIR/tar.log" 2>/dev/null | tr '\n' ' ')"; }
    [ "${_st[1]:-1}" = "0" ] || { _deploy_rc=1; warn "tar (write) failed: $(tail -3 "$BACKUP_DIR/tar.log" 2>/dev/null | tr '\n' ' ')"; }
  fi

  if [ "$_deploy_rc" -eq 0 ]; then
    ok "Deployed $TRACKED_COUNT tracked file(s) to live (config/data/bots untouched)"
  else
    fail "Code deployment failed – live directory may be partially updated"
    info "Backup + tracked file list kept in $BACKUP_DIR"
  fi

  # حذف فایل‌هایی که در آپدیت جدید از گیت حذف شده‌اند
  # مبنا: آخرین نسخهٔ استقراریافته (از state) وگرنه HEAD قبل از این اجرا
  plan_removed_files "${LAST_DEPLOYED:-$CUR_COMMIT}" "$NEW_COMMIT"
  apply_removed_files
  [ "${REMOVED_COUNT:-0}" -gt 0 ] && ok "Removed $REMOVED_COUNT file(s) deleted upstream from live"

  # اگر config.php ای در زنده نیست (نصب تازه)، از سورس بساز
  if [ ! -f "$LIVE_DIR/config.php" ] && [ -f "$SRC_DIR/config.php" ]; then
    cp -a "$SRC_DIR/config.php" "$LIVE_DIR/config.php" 2>/dev/null && ok "Bootstrapped live/config.php from source"
  fi
  # گارددار: هرگز نباید بدون config.php بمانیم
  if [ ! -f "$LIVE_DIR/config.php" ] && [ -s "$BACKUP_DIR/live/config.php" ]; then
    cp -a "$BACKUP_DIR/live/config.php" "$LIVE_DIR/config.php" 2>/dev/null && warn "Live config was missing – restored from backup"
  fi
  [ -f "$LIVE_DIR/config.php" ] || fail "Live config.php is missing – restore it from $BACKUP_DIR/live/"

  # بازنویسیِ ایمن تنظیمات زنده (کمربند ایمنی؛ rsync/لیست فایل‌ها آن‌ها را دست نزده‌اند)
  shopt -s nullglob
  for cf_b in "$BACKUP_DIR/live/bots"/*/config.php; do
    slug="$(basename "$(dirname "$cf_b")")"
    mkdir -p "$LIVE_DIR/bots/$slug"
    [ -f "$LIVE_DIR/bots/$slug/config.php" ] || cp -a "$cf_b" "$LIVE_DIR/bots/$slug/config.php" 2>/dev/null
  done
  shopt -u nullglob

  # بررسی صحت استقرار
  if [ "$TPL_ONLY" -eq 1 ]; then
    # فقط templates/ مستقر شده و عمداً کدِ ربات‌ساز به‌روز نشده ⇒ باید همان قالب‌ها
    # یکی‌یکی با سورس یکی باشند (کپیِ ناقص یا نیمه‌کاره همین‌جا لو می‌رود)
    _m=0; _n=0
    while IFS= read -r -d '' _p; do
      [ -n "$_p" ] || continue
      _n=$((_n + 1))
      cmp -s "$SRC_DIR/$_p" "$LIVE_DIR/$_p" || _m=$((_m + 1))
    done < "$TRACKED_LIST"
    if [ "$_m" -eq 0 ] && [ "$_n" -gt 0 ]; then
      ok "Verified: $_n template file(s) identical to source"
    else
      fail "$_m of $_n template file(s) differ from source after deploy"
    fi
  elif [ -f "$SRC_DIR/bot.php" ] && [ -f "$LIVE_DIR/bot.php" ]; then
    if cmp -s "$SRC_DIR/bot.php" "$LIVE_DIR/bot.php"; then
      ok "Verified: live/bot.php is identical to source"
    else
      fail "live/bot.php differs from source after deploy"
    fi
  fi
fi

# بررسی نسخه (در هر دو حالت تک‌پوشه/دوپوشه)
_v_s="$(grep -m1 "APP_VERSION" "$SRC_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
_v_l="$(grep -m1 "APP_VERSION" "$LIVE_DIR/src/Manager.php" 2>/dev/null | sed -n "s/.*'\([^']*\)'.*/\1/p")"
if [ "$TPL_ONLY" -eq 1 ]; then
  # کدِ ربات‌ساز تغییری نکرده؛ ناهمخوانی نسخه انتظار می‌رود و خطا نیست
  info "Live version stays at ${_v_l:-?} (templates-only; app code not deployed)"
elif [ -n "$_v_l" ] && [ "$_v_l" = "$_v_s" ]; then
  ok "Live version verified: $_v_l"
elif [ "$DRY_RUN" -eq 1 ]; then
  info "DRY-RUN: live version '${_v_l:-?}' vs source '${_v_s:-?}'"
else
  warn "Version mismatch: live='${_v_l:-?}' source='${_v_s:-?}'"
fi

# ===== ۶. تنظیم وی‌هوست (Apache/Nginx) → اشاره به LIVE_DIR =====
# --templates-only: وی‌هوست، وبهوک، دیتابیس، مایگریشن، healthcheck و ری‌استارت هیچ‌کدام
# لازم نیستند؛ چون کدِ ربات‌ساز تغییری نمی‌کند و فقط قالب‌ها عوض می‌شوند.
if [ "$TPL_ONLY" -eq 0 ]; then
step "Step 6: Configure web server vhost (point to live path)"
if [ "$DRY_RUN" -eq 0 ] && [ "$(id -u)" -eq 0 ] && [ "$LIVE_DIR" != "$SRC_DIR" ]; then
  LIVE_ABS="$LIVE_DIR"
  # حذف نقل‌قول‌ها تا مقایسه درست شود: DocumentRoot "/var/www/x"  ==  /var/www/x
  unquote() {
    local v="$1"
    v="${v#\"}"; v="${v%\"}"
    v="${v#\'}"; v="${v%\'}"
    printf '%s' "$v"
  }
  trim() { printf '%s' "$1" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'; }
  _seen=" "
  # Apache
  for c in /etc/apache2/sites-available/*.conf /etc/apache2/sites-enabled/*.conf; do
    [ -f "$c" ] || continue
    # sites-enabled معمولاً symlink است → همان فایل را دو بار ویرایش نکن
    _real="$(readlink -f "$c" 2>/dev/null || printf '%s' "$c")"
    case "$_seen" in *" $_real "*) continue ;; esac
    _seen="$_seen$_real "
    grep -qE "botsaz|faxima" "$c" 2>/dev/null || continue
    grep -q "DocumentRoot" "$c" 2>/dev/null || continue
    CURDR="$(sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}\([^[:space:]#]*\).*/\1/p' "$c" | head -n1)"
    CURDR="$(unquote "$CURDR")"
    # فقط به فایل‌هایی دست می‌زنیم که نام یا DocumentRoot شان واقعاً متعلق به همین پروژه است
    case "$(basename "$c")|$CURDR" in
      *botsaz*|*faxima*) : ;;
      *) info "Skipping $(basename "$c"): DocumentRoot '$CURDR' does not look like this project"; continue ;;
    esac
    if [ -z "$CURDR" ]; then
      warn "No DocumentRoot parsed in $(basename "$c")"
    elif [ "$CURDR" != "$LIVE_ABS" ]; then
      sed -i "s|^[[:space:]]*DocumentRoot[[:space:]]\{1,\}[^[:space:]#]*|	DocumentRoot $LIVE_ABS|" "$c" 2>/dev/null \
        && ok "Apache DocumentRoot updated in $(basename "$c"): $CURDR → $LIVE_ABS" \
        || warn "Could not update DocumentRoot in $c"
    else
      ok "Apache DocumentRoot correct in $(basename "$c")"
    fi
  done
  # Nginx
  for c in /etc/nginx/sites-available/*.conf /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [ -f "$c" ] || continue
    _real="$(readlink -f "$c" 2>/dev/null || printf '%s' "$c")"
    case "$_seen" in *" $_real "*) continue ;; esac
    _seen="$_seen$_real "
    grep -qE "botsaz|faxima" "$c" 2>/dev/null || continue
    grep -qE "^[[:space:]]*root[[:space:]]" "$c" 2>/dev/null || continue
    CURR="$(sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;#]*\);.*/\1/p' "$c" | head -n1)"
    CURR="$(unquote "$(trim "$CURR")")"
    case "$(basename "$c")|$CURR" in
      *botsaz*|*faxima*) : ;;
      *) info "Skipping $(basename "$c"): root '$CURR' does not look like this project"; continue ;;
    esac
    if [ -z "$CURR" ]; then
      warn "No root parsed in $(basename "$c")"
    elif [ "$CURR" != "$LIVE_ABS" ]; then
      sed -i "s|^[[:space:]]*root[[:space:]]\{1,\}[^;#]*;|	root $LIVE_ABS;|" "$c" 2>/dev/null \
        && ok "Nginx root updated in $(basename "$c"): $CURR → $LIVE_ABS" \
        || warn "Could not update root in $c"
    else
      ok "Nginx root correct in $(basename "$c")"
    fi
  done
else
  info "Skipping vhost edit (not root, DRY-RUN, or source == live)"
fi

# ===== ۷. وب‌هوک بروزرسانی/تأیید =====
step "Step 7: Update/verify Telegram webhook"
if [ "$DRY_RUN" -eq 1 ]; then
  info "DRY-RUN: would set/verify the Telegram webhook"
elif [ -f "$LIVE_DIR/config.php" ] && have_php; then
  TOKEN="$("$PHP_BIN" -r '$c=@include $argv[1]; $t=$c["main_token"]??""; if ($t==="PUT_MAIN_BOT_TOKEN_HERE") $t=""; echo $t;' "$LIVE_DIR/config.php" 2>/dev/null)"
  BASE="$("$PHP_BIN" -r '$c=@include $argv[1]; echo rtrim((string)($c["base_url"]??""),"/");' "$LIVE_DIR/config.php" 2>/dev/null)"
  if [ -n "$TOKEN" ] && [ -n "$BASE" ]; then
    if [ -f "$LIVE_DIR/tools/set_webhook.php" ]; then
      "$PHP_BIN" "$LIVE_DIR/tools/set_webhook.php" > "$BACKUP_DIR/set_webhook.log" 2>&1
      _whc=$?
      if [ "$_whc" -eq 0 ]; then
        ok "Webhook checked/updated via tools/set_webhook.php"
      else
        warn "tools/set_webhook.php failed (exit $_whc):"
        tail -8 "$BACKUP_DIR/set_webhook.log" 2>/dev/null | sed 's/^/   /'
        info "Run manually: $PHP_BIN $LIVE_DIR/tools/set_webhook.php"
      fi
    elif command -v curl >/dev/null 2>&1; then
      WH_URL="${BASE}/bot.php"
      RESP="$(curl -s --max-time 10 "https://api.telegram.org/bot${TOKEN}/setWebhook?url=${WH_URL}" 2>/dev/null)"
      if echo "$RESP" | grep -q '"ok":true'; then
        ok "Webhook set successfully: $WH_URL"
      else
        warn "Failed to set webhook via API. Response: ${RESP:0:120}"
        if [ -f "$LIVE_DIR/tools/set_webhook.php" ]; then
          info "Run manually: $PHP_BIN $LIVE_DIR/tools/set_webhook.php"
        else
          info "tools/set_webhook.php not deployed – set the webhook manually via the Bot API"
        fi
      fi
    else
      warn "Neither tools/set_webhook.php nor curl available – webhook not verified"
    fi
  else
    # دقیقاً بگو کدام مقدار خالی است تا سریع حل شود
    if [ -z "$TOKEN" ]; then
      warn "Webhook SKIPPED: main_token is empty or still the placeholder in $LIVE_DIR/config.php"
      info "Fix: put the real @BotFather token in 'main_token' then run: $PHP_BIN $LIVE_DIR/tools/set_webhook.php"
    else
      info "main_token: OK ($(printf '%s' "$TOKEN" | wc -c) chars, hidden)"
    fi
    if [ -z "$BASE" ]; then
      warn "Webhook SKIPPED: base_url is empty in $LIVE_DIR/config.php"
      info "Fix: set 'base_url' to your public https URL (e.g. https://domain.com/botsaz-faxima)"
    else
      info "base_url: $BASE"
    fi
  fi
else
  info "Skipping webhook (no config.php or no PHP CLI)"
fi

# ===== ۸. بررسی نیازهای توسعه + مایگریشن‌ها + healthcheck (داخل LIVE_DIR) =====
step "Step 8: Dev checks & migrations (run in live dir)"
# فقط فایل‌های PHP بررسی می‌شوند (تا خودِ این اسکریپت و لاگ‌ها false-positive ندهند)
TODO_CNT="$(grep -rE "TODO|FIXME" --include='*.php' "$SRC_DIR"/*.php "$SRC_DIR"/src "$SRC_DIR"/tools 2>/dev/null | grep -v "config.example" | grep -c .)"
case "$TODO_CNT" in ''|*[!0-9]*) TODO_CNT=0 ;; esac
if [ "$TODO_CNT" -gt 0 ]; then
  warn "Found $TODO_CNT TODO/FIXME item(s) (first 5):"
  grep -rE "TODO|FIXME" --include='*.php' "$SRC_DIR"/*.php "$SRC_DIR"/src "$SRC_DIR"/tools 2>/dev/null | grep -v "config.example" | head -5 | sed 's/^/   /'
else
  ok "No TODO/FIXME found"
fi

# مایگریشن‌ها داخل پوشه زنده
if [ -f "$LIVE_DIR/tools/install.php" ] && have_php; then
  if [ "$DRY_RUN" -eq 1 ]; then
    info "DRY-RUN: would run $PHP_BIN $LIVE_DIR/tools/install.php"
  else
    "$PHP_BIN" "$LIVE_DIR/tools/install.php" > "$BACKUP_DIR/install_out.log" 2>&1
    _irc=$?
    tail -15 "$BACKUP_DIR/install_out.log" 2>/dev/null | sed 's/^/   /'
    if [ "$_irc" -eq 0 ]; then
      ok "Installer/migrations executed in $LIVE_DIR"
    else
      warn "tools/install.php exited with $_irc (see $BACKUP_DIR/install_out.log)"
    fi
  fi
elif [ -f "$LIVE_DIR/tools/install.php" ]; then
  warn "tools/install.php present but PHP CLI missing – migrations skipped"
else
  warn "tools/install.php not found in $LIVE_DIR"
fi

# healthcheck بعد از استقرار (فقط هشدار می‌دهد، خطا نیست)
if [ "$DRY_RUN" -eq 0 ] && [ -f "$LIVE_DIR/tools/healthcheck.php" ] && have_php; then
  "$PHP_BIN" "$LIVE_DIR/tools/healthcheck.php" > "$BACKUP_DIR/healthcheck.log" 2>&1
  _hc=$?
  if [ "$_hc" -eq 0 ]; then
    ok "Healthcheck: healthy (no hard errors)"
  else
    warn "Healthcheck reported errors (exit $_hc):"
    grep -E "✗|❌|ERRORS \(" "$BACKUP_DIR/healthcheck.log" 2>/dev/null | head -6 | sed 's/^/   /'
    info "full output: $BACKUP_DIR/healthcheck.log"
    info "re-run: $PHP_BIN $LIVE_DIR/tools/healthcheck.php"
  fi
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
restart_service() {
  local s="$1"
  if command -v systemctl >/dev/null 2>&1; then
    systemctl reload-or-restart "$s" >/dev/null 2>&1 && ok "Restarted $s" && return 0
    return 1
  fi
  if command -v service >/dev/null 2>&1; then
    service "$s" reload >/dev/null 2>&1 || service "$s" restart >/dev/null 2>&1 && ok "Restarted $s" && return 0
  fi
  return 1
}

if [ "$NO_RESTART" -eq 1 ]; then
  step "Step 9: Restarting web/services"
  info "NO_RESTART/--web: skipping service restarts"
elif [ "$DRY_RUN" -eq 1 ]; then
  step "Step 9: Restarting web/services"
  info "DRY-RUN: would restart web services"
elif [ "$(id -u)" -ne 0 ]; then
  step "Step 9: Restarting web/services"
  info "Not root: skipping service restarts"
else
  step "Step 9: Restarting web/services (safe)"
  _restarted=0
  _targets=""
  if command -v systemctl >/dev/null 2>&1; then
    # واحد‌های واقعاً در حال اجرا را پیدا کن (با --plain/--no-legend تا کاراکتر درختی grep را خراب نکند)
    _targets="$(systemctl list-units --type=service --state=running --no-pager --plain --no-legend -l 2>/dev/null \
                | awk 'NF {print $1}' \
                | grep -E '^(apache2|httpd|nginx|php[0-9.]*-fpm|php-fpm|lighttpd|caddy|openlitespeed|litespeed)\.service$' \
                | tr '\n' ' ')"
    if [ -z "$_targets" ]; then
      _web_units="$(systemctl list-units --type=service --state=running --no-pager --plain --no-legend -l 2>/dev/null \
                    | awk 'NF {print $1}' \
                    | grep -iE 'apache|httpd|nginx|php|caddy|litespeed|web' | tr '\n' ' ')"
      if [ -n "$_web_units" ]; then
        info "Known web units not matched, but these are running:"
        for _u in $_web_units; do info "   $_u"; done
        _targets="$_web_units"
      else
        info "No web-related running unit found under systemd."
        info "systemd state: $(systemctl is-system-running 2>&1 | head -1)"
        info "First running units:"
        systemctl list-units --type=service --state=running --no-pager --plain --no-legend -l 2>/dev/null | head -8 | sed 's/^/   /'
      fi
    fi
  else
    for s in apache2 httpd nginx php8.5-fpm php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
      [ -x "/etc/init.d/$s" ] && _targets="$_targets $s "
    done
    [ -z "$_targets" ] && info "No systemctl and no matching /etc/init.d scripts"
  fi
  for s in $_targets; do
    s="${s%.service}"
    if restart_service "$s"; then
      _restarted=$((_restarted + 1))
    else
      warn "Could not restart $s"
    fi
  done
  [ "$_restarted" -eq 0 ] && info "No web service was restarted"
fi
else
  info "--templates-only: vhost / webhook / migrations / healthcheck / restarts skipped"
fi  # end of: not --templates-only

# ===== ۱۰. پاک‌سازی فایل‌های حساس در /root برای امنیت بیشتر =====
step "Step 10: Security cleanup in source (remove secrets only)"
# فقط فایل‌های «حساس/تولیدی» پاک می‌شوند؛ هرگز فایلِ ردیابی‌شدهٔ گیت (.htaccess/.gitignore/...)
if [ "$DRY_RUN" -eq 1 ]; then
  info "DRY-RUN: would clean secrets from source (if it lives under /root)"
elif [[ "$SRC_DIR" == /root/* ]]; then
  if [ "$LIVE_DIR" = "$SRC_DIR" ]; then
    warn "Source == live and both live under /root – skipping cleanup so live config is not deleted"
  else
    rm -f "$SRC_DIR/config.php" 2>/dev/null
    rm -f "$SRC_DIR/.env" "$SRC_DIR/.env.local" "$SRC_DIR/.env.prod" "$SRC_DIR/.env.dev" 2>/dev/null
    rm -f "$SRC_DIR"/*.bak "$SRC_DIR"/*.swp "$SRC_DIR"/*~ 2>/dev/null
    rm -f "$SRC_DIR"/*.zip "$SRC_DIR"/*.tar "$SRC_DIR"/*.tar.gz 2>/dev/null
    rm -rf "$SRC_DIR"/__pycache__ "$SRC_DIR"/.cache 2>/dev/null
    chmod 711 /root 2>/dev/null
    ok "Secrets/temp files removed from $SRC_DIR (tracked files untouched)"
    info "Note: Live files remain intact at $LIVE_DIR"
  fi
else
  info "Source is not under /root – no cleanup needed"
fi

# بک‌آپ‌های قدیمی در /tmp (همیشه، مستقل از root)
find /tmp -maxdepth 1 -name "botsaz-config-backup-*" -mtime +7 -exec rm -rf {} + 2>/dev/null || true

# ===== ۱۰ب. ثبت وضعیت برای --rollback =====
if [ "$DRY_RUN" -eq 1 ]; then
  : # dry-run هرگز وضعیت را عوض نمی‌کند
elif [ "$TPL_ONLY" -eq 1 ]; then
  # فقط قالب‌ها مستقر شدند؛ این فایل مخصوص استقرارِ «کامل» است و نباید عوض شود
  # (وگرنه بعداً فایل‌های حذف‌شدهٔ کدِ ربات‌ساز از زنده پاک نمی‌شوند)
  info "Rollback state untouched (templates-only deploy)"
else
  cat > "$STATE_FILE" 2>/dev/null <<STATE
PREV=${LAST_DEPLOYED:-$CUR_COMMIT}
NEW=$NEW_COMMIT
BRANCH=$CUR_BRANCH
BACKUP=$BACKUP_DIR
TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)
STATE
  [ -f "$STATE_FILE" ] && info "Rollback state saved: $STATE_FILE (undo with --rollback)"
fi

# ===== ۱۰ج. فایلِ اشاره‌گر: زنده باید سورسِ گیت را پیدا کند =====
# دکمهٔ «🔄 دریافت سورس بروز» داخل ربات، از درونِ پوشهٔ زنده اجرا می‌شود و باید بداند
# tools/update.sh در کجا است (حالت دوپوشه: /root/botsaz-faxima).
if [ "$DRY_RUN" -eq 0 ] && [ "$LIVE_DIR" != "$SRC_DIR" ] && [ -d "$SRC_DIR/.git" ]; then
  if mkdir -p "$LIVE_DIR/data" 2>/dev/null && printf '%s\n' "$SRC_DIR" > "$LIVE_DIR/data/source_dir.txt" 2>/dev/null; then
    ok "Source pointer written: $LIVE_DIR/data/source_dir.txt → $SRC_DIR"
  else
    warn "Could not write source pointer ($LIVE_DIR/data/source_dir.txt) – in-bot update will need 'source_dir' in config.php"
  fi
fi

# ===== ۱۱. جمع‌بندی =====
step "Step 11: Summary"
if [ "$ROLLBACK" -eq 1 ]; then
  [ "$DRY_RUN" -eq 1 ] && ok "Rollback dry-run finished: $CUR_COMMIT → $NEW_COMMIT" || ok "Rollback completed: $CUR_COMMIT → $NEW_COMMIT"
else
  ok "Update completed"
fi
info "Source  : $SRC_DIR"
info "Live    : $LIVE_DIR"
[ "$TPL_ONLY" -eq 1 ] && info "Mode    : templates-only (templates/ deployed; app code, DB, webhook and vhosts untouched)"
[ -n "$APP_VER_SRC" ] && info "Version : ${_v_l:-$APP_VER_SRC}"
info "Branch  : $CUR_BRANCH ($NEW_COMMIT)"
[ "$DRY_RUN" -eq 0 ] && info "Configs preserved: YES (only git-tracked files were touched)"
[ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ] && info "Backups : $BACKUP_DIR"
if [ "$RESET_OCCURRED" -eq 1 ] && [ -s "$BACKUP_DIR/local_changes.patch" ]; then
  warn "Local tracked edits were reset by this update – review their diff: $BACKUP_DIR/local_changes.patch"
fi
if [ "$CUR_COMMIT" != "$NEW_COMMIT" ] && [ "$DRY_RUN" -eq 0 ] && [ "$ROLLBACK" -eq 0 ]; then
  info "Changes:"
  if [ "$TPL_ONLY" -eq 1 ]; then
    git --no-pager diff --stat "$CUR_COMMIT" "$NEW_COMMIT" -- templates/ 2>/dev/null | tail -8 | sed 's/^/   /'
    info "Only templates/ was deployed; the app code stays at its previous version until a full update."
  else
    git --no-pager diff --stat "$CUR_COMMIT" "$NEW_COMMIT" 2>/dev/null | tail -5 | sed 's/^/   /'
    info "Undo with: bash tools/update.sh --rollback"
  fi
fi
echo
if [ "$ERRORS" -gt 0 ]; then
  fail "Finished with $ERRORS error(s) and $WARNINGS warning(s)"
  info "Re-check with: bash tools/install.sh --check"
  exit 1
fi
if [ "$WARNINGS" -gt 0 ]; then
  warn "Finished with $WARNINGS warning(s) – review messages above"
  info "Re-check with: bash tools/install.sh --check"
  exit 0
fi
exit 0
