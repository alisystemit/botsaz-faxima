#!/bin/bash
# ===================================================================
# fix_systemd_apache.sh - فیکس دائمی سندباکس systemd برای پروژه زیر /root
#
# مشکل: حتی با chmod 711 روی /root، آپاچی 403 می‌دهد چون یونیت systemd
# (apache2 / php-fpm) داخل mount namespace خودش /root را مخفی کرده:
#   ProtectHome=true  یا  InaccessiblePaths=/root
# در این حالت namei و `sudo -u www-data test -x /root` سبزند ولی
# error.log پر از AH00035 است.
#
# راه‌حل دائمی (روی apt upgrade نمی‌پرد):
#   /etc/systemd/system/apache2.service.d/botsaz.conf:
#     [Service]
#     InaccessiblePaths=
#     ProtectHome=false
#
# استفاده:
#   sudo bash tools/fix_systemd_apache.sh
#   sudo bash tools/fix_systemd_apache.sh --check   (فقط گزارش، بدون تغییر)
# ===================================================================
set -e

MODE="apply"
for a in "$@"; do
    case "$a" in
        --check|check) MODE="check" ;;
        -h|--help)
            echo "Usage: sudo bash tools/fix_systemd_apache.sh [--check]"
            exit 0 ;;
    esac
done

SUDO=""
[ "$(id -u)" -ne 0 ] && SUDO="sudo"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

UNIT=""
if systemctl show apache2 -p LoadState --value 2>/dev/null | grep -qx loaded; then
    UNIT="apache2"
elif systemctl show httpd -p LoadState --value 2>/dev/null | grep -qx loaded; then
    UNIT="httpd"
fi

echo "ROOT_DIR: $ROOT_DIR"
case "$ROOT_DIR" in
    /root|/root/*|/home|/home/*)
        echo "-> پروژه زیر ProtectHome است، بررسی لازم است." ;;
    *)
        echo "-> پروژه بیرون /root و /home است؛ سندباکس systemd ربطی ندارد. خروج."
        exit 0 ;;
esac

if [ -z "$UNIT" ]; then
    echo "-> یونیت apache2/httpd پیدا نشد (شاید nginx+php-fpm داری)."
fi

if [ -n "$UNIT" ]; then
    echo "--- merged systemd view ($UNIT) ---"
    systemctl show "$UNIT" -p ProtectHome,InaccessiblePaths 2>/dev/null || true
fi

needs_fix() {
    local u="$1" ph inacc
    ph="$(systemctl show "$u" -p ProtectHome --value 2>/dev/null || true)"
    case "$ph" in
        yes|true|1|on) return 0 ;;
    esac
    inacc="$(systemctl show "$u" -p InaccessiblePaths --value 2>/dev/null || true)"
    case "$inacc" in
        */root*|*/home*) return 0 ;;
    esac
    return 1
}

if [ -n "$UNIT" ]; then
    if needs_fix "$UNIT"; then
        echo "❌ $UNIT پروژه را مخفی کرده (ProtectHome/InaccessiblePaths)."
    else
        echo "✔ $UNIT پروژه را مخفی نکرده."
        [ "$MODE" = "check" ] && exit 0
        # override قبلی هم اگر هست، نگهش می‌داریم
        exit 0
    fi
fi

if [ "$MODE" = "check" ]; then
    echo ""
    echo "فیکس دستی:"
    echo "  sudo mkdir -p /etc/systemd/system/${UNIT:-apache2}.service.d"
    echo "  printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' | sudo tee /etc/systemd/system/${UNIT:-apache2}.service.d/botsaz.conf"
    echo "  sudo systemctl daemon-reload && sudo systemctl restart ${UNIT:-apache2}"
    exit 1
fi

if [ -n "$UNIT" ]; then
    D="/etc/systemd/system/${UNIT}.service.d"
    $SUDO mkdir -p "$D"
    printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' | $SUDO tee "$D/botsaz.conf" >/dev/null
    echo "✔ wrote $D/botsaz.conf"
    $SUDO systemctl daemon-reload
    $SUDO systemctl restart "$UNIT" && echo "✔ $UNIT restarted" || echo "⚠️ restart دستی لازم است"
fi

# php-fpm هم اگر هست، همان override را می‌خواهد
for f in $(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
    u="${f%.service}"
    if needs_fix "$u"; then
        D="/etc/systemd/system/${u}.service.d"
        $SUDO mkdir -p "$D"
        printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' | $SUDO tee "$D/botsaz.conf" >/dev/null
        echo "✔ wrote $D/botsaz.conf"
        $SUDO systemctl restart "$u" || true
    fi
done

echo ""
echo "تست:"
echo "  systemctl show ${UNIT:-apache2} -p ProtectHome,InaccessiblePaths"
echo "  curl -sI $(php -r '$c=@include \"'$ROOT_DIR'/config.php\"; echo rtrim(is_array($c)?($c[\"base_url\"]??\"\"):\"\", \"/\");' 2>/dev/null)/bot.php"
