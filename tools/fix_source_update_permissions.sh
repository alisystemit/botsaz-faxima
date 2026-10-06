#!/usr/bin/env bash
#
# fix_source_update_permissions.sh
#
# وقتی «دریافت سورس بروز» یا «⬆️ آپدیت ربات‌ساز» با خطای زیر متوقف می‌شود:
#
#   [0;31m✘ [0m git reset --hard origin/main failed:
#     error: unable to unlink old '...': Permission denied
#   [0;31m✘ [0m No write permission on the source tree (...)
#
# این اسکریپت مالکیت/دسترسی درخت را به کاربری که وب‌سرور با آن بت‌ها کار
# می‌کند برمی‌گرداند و بعد آپدیت را اجرا می‌کند.
#
# استفاده:
#   sudo bash tools/fix_source_update_permissions.sh [live_dir]
#
# live_dir اختیاری است؛ پیش‌فرض: همان پوشهٔ این اسکریپت.

set -euo pipefail

LIVE_DIR="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
WEB_USER=""

# ---------- ۱) تشخیص کاربرِ وب ----------
for candidate in www-data wwwrun nginx apache nobody; do
  if id "$candidate" >/dev/null 2>&1; then
    WEB_USER="$candidate"
    break
  fi
done
# کنترل چه کاربری روی پروسسِ php-fpm یا apache نشسته است
for pid in $(pgrep -f 'php-fpm|apache2|httpd' 2>/dev/null || true); do
  u=$(ps -o user= -p "$pid" 2>/dev/null | tr -d ' ')
  if [ -n "$u" ] && [ "$u" != "root" ]; then WEB_USER="$u"; break; fi
done

if [ -z "$WEB_USER" ]; then
  echo "✘ کاربرِ وب پیدا نشد. کاربرِ مالکِ پوشه را دستی بده: WEB_USER=www-data sudo -E bash $0"
  exit 1
fi

echo "LIVE_DIR=$LIVE_DIR"
echo "کاربرِ وب: $WEB_USER"

# ---------- ۲) مالکیت ----------
echo "━━━ chown tree to $WEB_USER:$WEB_USER ━━━"
chown -R "$WEB_USER:$WEB_USER" "$LIVE_DIR"

# اطمینان از write برای مالک و اجرای اسکریپت‌ها
find "$LIVE_DIR" -type d -exec chmod u+rwx {} +
find "$LIVE_DIR" -type f -exec chmod u+rw {} +
find "$LIVE_DIR/tools" -maxdepth 1 -type f -name '*.sh' -exec chmod u+x {} +

# پاک‌کردن قفلِ git که ممکن است از قبل مانده باشد
if [ -f "$LIVE_DIR/.git/index.lock" ]; then
  rm -f "$LIVE_DIR/.git/index.lock"
  echo "cleared stale .git/index.lock"
fi

# ---------- ۳) تستِ نوشتن ----------
if sudo -u "$WEB_USER" touch "$LIVE_DIR/.write_test" 2>/dev/null; then
  rm -f "$LIVE_DIR/.write_test"
  echo "✔ تستِ نوشتن برای $WEB_USER OK"
else
  echo "✘ هنوز $WEB_USER روی $LIVE_DIR نوشتن ندارد."
  echo "  بررسی کن: ls -ld $LIVE_DIR و id $WEB_USER"
  exit 1
fi

# ---------- ۴) آپدیت ----------
echo "━━━ اجرا: sudo -u $WEB_USER bash tools/update.sh ━━━"
cd "$LIVE_DIR"
sudo -u "$WEB_USER" bash tools/update.sh --force || {
  echo "✘ update.sh اجرا نشد؛ خروجی بالا را ببین."
  exit 1
}

echo "✔ تمام. اگر خطای «Permission denied» برگشت، مالکِ واقعیِ پوشه/کاربرِ وب را دوباره چک کن."
