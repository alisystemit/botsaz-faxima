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

# Same shape, opposite default. Reserved for anything that widens access or
# changes permissions: an empty answer (or a closed stdin) must mean NO there,
# which is exactly what ask_yes cannot give.
ask_no() { # $1 = question text (default: no)
    local ans
    printf '%s\n' "$1"
    read -r -p "   [y/N]: " ans || ans=""
    [[ "$ans" =~ ^[Yy] ]] && return 0 || return 1
}

apt_install() {
    echo "   ⏳ Installing with apt (sudo may ask for a password)..."
    $SUDO apt-get update -qq
    # shellcheck disable=SC2068
    $SUDO apt-get install -y $@
    hash -r 2>/dev/null || true
}

# Remove packages via apt (opposite of apt_install)
apt_remove() {
    echo "   ⏳ Removing with apt (sudo may ask for a password)..."
    # shellcheck disable=SC2068
    $SUDO apt-get remove -y $@ 2>/dev/null || true
    $SUDO apt-get autoremove -y 2>/dev/null || true
    hash -r 2>/dev/null || true
}

# ==================================================================
# SHARED: health check + error-log report
# Everything below is defined BEFORE the installer starts, because
#     bash tools/install.sh --check
# has to run the whole report and exit without executing a single install step.
# The install path calls exactly the same functions at the end, so what you read
# months later with --check is what the installer itself verified on day one.
# ==================================================================

# Can the web-server user (www-data) walk down to DocumentRoot?
# The repo is normally cloned as root and /root is 0700, so www-data cannot
# traverse it: Apache logs "DocumentRoot ... does not exist" at start-up and
# "Permission denied ... search permissions are missing" on every request - a
# bot that answers nothing while every check around it still looks green.
DOCROOT_OK=1

# The account that actually serves requests - read, not guessed. Debian and
# Ubuntu write it into /etc/apache2/envvars; www-data is the fallback.
apache_run_user() {
    local u=""
    if [ -f /etc/apache2/envvars ]; then
        u="$(sed -n 's/^[[:space:]]*export[[:space:]]\{1,\}APACHE_RUN_USER=//p' /etc/apache2/envvars | head -n1 | tr -d "\"'")"
    fi
    # nginx + php-fpm: PHP runs as the POOL user, which need not be www-data.
    # Read it from the pool config so the impersonation tests below ask as the
    # account that really executes bot.php - otherwise a custom pool user gets
    # a green report while PHP itself cannot read a single file.
    if [ -z "$u" ] && ! has_cmd apache2 && ! has_cmd httpd && has_cmd nginx; then
        local _pf=""
        for _pf in /etc/php/*/fpm/pool.d/www.conf /etc/php/*/fpm/pool.d/*.conf; do
            [ -f "$_pf" ] || continue
            u="$(sed -n 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*//p' "$_pf" | head -n1 | tr -d "\"'")"
            [ -n "$u" ] && break
        done
        unset _pf
    fi
    printf '%s' "${u:-www-data}"
}

# Which web server actually SERVES right now? An installed-but-dead apache2
# next to a running nginx must NOT win: writing the Apache vhost while nginx
# holds :80 leaves Telegram talking to the wrong server, and installing
# libapache2-mod-php drags apache2 back in as an apt dependency - re-enabled
# at boot, failing forever on the busy port. Prints: apache2 | nginx | ""
# (empty = none active or no systemctl; callers fall back to installed binaries).
active_web_server() {
    local a=0 n=0
    has_cmd systemctl || return 0
    if systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; then a=1; fi
    if systemctl is-active --quiet nginx 2>/dev/null; then n=1; fi
    if [ "$a" = "1" ] && [ "$n" = "0" ]; then printf 'apache2'; return 0; fi
    if [ "$n" = "1" ] && [ "$a" = "0" ]; then printf 'nginx'; return 0; fi
    return 0
}

# Who listens on $1? Used to name the process behind "Address already in use"
# (almost always the OTHER web server). Prints the process name or nothing.
port_owner() { # $1 = port
    local p="$1" line=""
    has_cmd ss || return 0
    line="$(ss -tlnp 2>/dev/null | grep -E "[:.]$p[[:space:]]" | head -n1 || true)"
    [ -z "$line" ] && return 0
    printf '%s' "$line" | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | head -n1
    return 0
}

# What that account can REALLY do.
# Why this exists: parsing mode bits said "/root/botsaz-faxima is reachable"
# on a server where Apache was still logging AH00035 for the very same path a
# second later. Bits cannot see an ACL, a group-only grant, a different
# DocumentRoot or a restriction on the Apache service - so once they look
# fine, ask the OS as the account itself.
#   echoes the directory the account cannot search (empty = whole chain OK)
#   0 = answered, 3 = cannot impersonate (caller keeps the stat answer)
webuser_blocker() {
    local u d="${1%/}" runner
    u="$(apache_run_user)"
    id -u "$u" >/dev/null 2>&1 || return 3
    [ -d "$d" ] || { printf '%s' "$d"; return 0; }
    if [ "$(id -u)" -eq 0 ] && has_cmd sudo; then
        runner="sudo -n -u $u"
    elif [ "$(id -u)" -eq 0 ] && has_cmd runuser; then
        runner="runuser -u $u --"
    elif has_cmd sudo; then
        runner="sudo -n -u $u"
    else
        return 3
    fi
    while [ -n "$d" ] && [ "$d" != "/" ]; do
        # shellcheck disable=SC2086
        if ! $runner test -x "$d" 2>/dev/null; then
            printf '%s' "$d"
            return 0
        fi
        d="$(dirname "$d")"
    done
    return 0
}

# Show every component of a path with its owner and mode. This is the one
# command that answers "which part is closed" without anybody guessing.
show_path_chain() { # $1 = file path
    if has_cmd namei; then
        ${SUDO:-} namei -l "$1" 2>/dev/null | sed 's/^/        /'
    else
        local c="$1"
        while [ -n "$c" ] && [ "$c" != "/" ]; do
            printf '        %-30s %s\n' "$c" "$(stat -c '%A %U:%G %a' "$c" 2>/dev/null || echo '?')"
            c="$(dirname "$c")"
        done
    fi
}

# What in systemd keeps the web server out of this path?
#
# ProtectHome= mounts /root, /home and /run/user as empty inaccessible
# directories INSIDE the service's own mount namespace. Every command a human
# runs - namei, stat, even `sudo -u www-data test -x` - happens OUTSIDE that
# namespace and answers "the path is walkable", while Apache inside it gets
# (13) Permission denied on every request. Nothing about the mode bits
# changed between those two answers, which is why the chmod advice printed
# here for months did nothing: the bits were never the problem, and the tools
# reporting them cannot see the sandbox the server actually runs in.
#   echoes the reason (empty = nothing hides the path)
#   0 = answered, 3 = cannot tell
# Why ONE unit is checked here is wrong on nginx: there PHP runs in php-fpm,
# a DIFFERENT unit with its own sandbox. nginx serving statics while php-fpm
# 403s every bot.php is exactly the split symptom - so every unit that can
# touch the path is asked. Echoes "unit:reason" (empty = nothing hides it).
_unit_sandbox_blocker() { # $1 = unit, $2 = path
    local unit="$1" p="$2" v="" d=""
    v="$(systemctl show "$unit" -p ProtectHome --value 2>/dev/null)" || v=""
    case "$v" in
        yes|true|1|on)
            printf '%s:ProtectHome=%s' "$unit" "$v"
            return 0 ;;
    esac
    v="$(systemctl show "$unit" -p InaccessiblePaths --value 2>/dev/null)" || v=""
    for d in $v; do
        case "$p" in
            "$d"|"$d"/*)
                printf '%s:InaccessiblePaths=%s' "$unit" "$d"
                return 0 ;;
        esac
    done
    return 0
}

systemd_sandbox_blocker() { # $1 = project path
    local p="$1" v="" f d hit unit="" _sb_units="" _sb_u="" _sb_hit=""
    # ProtectHome only guards these three roots; a project in /var/www can
    # never be hidden this way, so do not pretend it can.
    case "$p" in
        /root|/root/*|/home|/home/*|/run/user|/run/user/*) ;;
        *) return 0 ;;
    esac
    # systemctl show returns the MERGED value (base unit plus every drop-in),
    # i.e. what the running service really sees. Parsing the files by hand
    # would reimplement systemd's merge rules and get them subtly wrong on
    # exactly the servers this check exists for.
    if has_cmd systemctl; then
        for _sb_u in apache2 httpd nginx; do
            if systemctl show "$_sb_u" -p LoadState --value 2>/dev/null | grep -qx loaded; then
                _sb_units="$_sb_units $_sb_u"
            fi
        done
        # With nginx, PHP runs in php-fpm - its own unit, its own sandbox.
        for _sb_u in $(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
            _sb_u="${_sb_u%.service}"
            if systemctl show "$_sb_u" -p LoadState --value 2>/dev/null | grep -qx loaded; then
                _sb_units="$_sb_units $_sb_u"
            fi
        done
        if [ -n "$_sb_units" ]; then
            for _sb_u in $_sb_units; do
                _sb_hit="$(_unit_sandbox_blocker "$_sb_u" "$p")"
                if [ -n "$_sb_hit" ]; then
                    printf '%s' "$_sb_hit"
                    unset _sb_units _sb_u _sb_hit
                    return 0
                fi
            done
            unset _sb_units _sb_u _sb_hit
            return 0
        fi
        unset _sb_units _sb_u _sb_hit
    fi
    if [ -n "$unit" ]; then
        v="$(systemctl show "$unit" -p ProtectHome --value 2>/dev/null)" || v=""
        case "$v" in
            yes|true|1|on)
                printf '%s:ProtectHome=%s' "$unit" "$v"
                return 0 ;;
        esac
        # ProtectHome can be off while an explicit entry hides the path just
        # as hard - and it needs a DIFFERENT line in the drop-in, so it has to
        # be reported on its own instead of folded into the answer above.
        v="$(systemctl show "$unit" -p InaccessiblePaths --value 2>/dev/null)" || v=""
        for d in $v; do
            case "$p" in
                "$d"|"$d"/*)
                    printf '%s:InaccessiblePaths=%s' "$unit" "$d"
                    return 0 ;;
            esac
        done
        return 0
    fi
    # No usable systemctl: read the base unit, then every drop-in on top of
    # it, last one wins. Cruder than the merged value above, but it still
    # catches a missing or hand-edited drop-in - which is what goes wrong.
    for f in /lib/systemd/system/apache2.service \
             /usr/lib/systemd/system/apache2.service \
             /etc/systemd/system/apache2.service; do
        if [ -f "$f" ]; then
            v="$(sed -n 's/^ProtectHome[[:space:]]*=[[:space:]]*//p' "$f" | tail -n1)"
            break
        fi
    done
    if [ -d /etc/systemd/system/apache2.service.d ]; then
        for f in /etc/systemd/system/apache2.service.d/*.conf; do
            if [ -f "$f" ]; then
                d="$(sed -n 's/^ProtectHome[[:space:]]*=[[:space:]]*//p' "$f" | tail -n1)"
                if [ -n "$d" ]; then v="$d"; fi
            fi
        done
    fi
    case "$v" in
        yes|true|1|on)
            printf 'ProtectHome=%s' "$v"
            return 0 ;;
    esac
    for f in /etc/systemd/system/apache2.service.d/*.conf; do
        if [ -f "$f" ]; then
            d="$(sed -n 's/^[[:space:]]*InaccessiblePaths[[:space:]]*=[[:space:]]*//p' "$f" | tail -n1)"
            for hit in $d; do
                case "$p" in
                    "$hit"|"$hit"/*)
                        printf 'InaccessiblePaths=%s' "$hit"
                        return 0 ;;
                esac
            done
        fi
    done
    return 0
}

# First ancestor of $1 that the web-server user cannot walk through, printed
# without a trailing newline (empty when the whole chain is traversable).
# Split out from the reporting below so the repair path can simply ask again
# after fixing it instead of printing the whole explanation twice.
_docroot_blocker() {
    local p="${1%/}" perm last
    if [ ! -d "$p" ]; then printf '%s' "$p"; return 0; fi
    while [ -n "$p" ] && [ "$p" != "/" ]; do
        perm="$(stat -c '%a' "$p" 2>/dev/null)" || return 0
        last="${perm: -1}"          # the "others" digit: odd means +x
        case "$last" in
            1|3|5|7) ;;
            *) printf '%s' "$p"; return 0 ;;
        esac
        # No special case for /root. There used to be one, rejecting every
        # mode except 711/755/705, and it was wrong in both directions: it
        # called 701 "not traversable" - a mode that grants exactly the bit
        # this loop is testing - and the fix it then printed, chmod 711, is a
        # no-op on a mode that already has o+x, so the reader was sent in a
        # circle. Walking into a directory needs x only; r is for listing it,
        # which Apache never does to a parent. When x is present but Apache
        # still refuses, that disagreement is a fact about something else
        # (ACL, group, service) and webuser_blocker below reports it.
        p="$(dirname "$p")"
    done
    return 0
}

# $1 = DocumentRoot.  $2 = "apply" lets the INSTALL path repair the permissions
# in place; --check never passes it and therefore stays strictly read-only.
docroot_reachable() {
    local p="$1" bad perm origin
    if [ ! -d "$p" ]; then
        echo "   ❌ DocumentRoot does not exist: $p"
        DOCROOT_OK=0
        return 1
    fi
    bad="$(_docroot_blocker "$p")"
    origin="stat"
    if [ -z "$bad" ]; then
        # The bits look fine - now confirm with the account Apache runs as.
        # A disagreement here is worth more than either answer alone.
        bad="$(webuser_blocker "$p")" || bad=""
        [ -n "$bad" ] && origin="webuser"
    fi
    [ -n "$bad" ] || return 0
    perm="$(stat -c '%a' "$bad" 2>/dev/null)"
    if [ "$origin" = "webuser" ]; then
        echo "   ❌ $(apache_run_user) cannot search '$bad' although its mode is ${perm:-?}."
        echo "      The permission bits look right, so this is NOT a plain chmod: a group-only"
        echo "      grant, an ACL, or a restriction on the Apache service all look like this."
        echo "      Show every component, then ask the account itself:"
        echo "        namei -l '${p%/}/bot.php'"
        echo "        sudo -u $(apache_run_user) test -x '${p%/}' && echo yes || echo no"
        show_path_chain "${p%/}/bot.php"
    else
        echo "   ❌ $bad is not traversable by the web-server user (mode $perm)."
        echo "      This is exactly what a project under /root runs into: Apache"
        echo "      cannot reach it, so every request is 403 and Telegram never"
        echo "      gets a reply. Fix it before the vhost can answer (as root):"
        echo "        allow the path:  chmod 711 '$bad'"
        echo "        then make data writable:"
        echo "                          chown -R www-data:www-data '${p%/}/data'"
        echo "        or move it out:  mkdir -p /var/www && cp -a '${p%/}' /var/www/ \\"
        echo "                          && chown -R www-data:www-data /var/www/$(basename "${p%/}")/data"
        echo "                          (then point DocumentRoot there and re-run this installer)"
    fi
    DOCROOT_OK=0

    # Widening access to /root is security-relevant, so this uses ask_no: the
    # question defaults to NO, the commands are already on screen, and a
    # closed stdin can only ever answer NO. Only the plain-permission case is
    # offered - when the bits are already right, chmod would change nothing
    # and the real cause has to be found with the commands printed above.
    if [ "$origin" = "stat" ] && [ "${2:-}" = "apply" ] && [ -t 0 ] \
        && ask_no "   Fix these permissions now (chmod 711 + chown data)?"; then
        if $SUDO chmod 711 "$bad"; then
            echo "   ✔ chmod 711 '$bad'"
            if [ -d "${p%/}/data" ]; then
                if $SUDO chown -R www-data:www-data "${p%/}/data"; then
                    echo "   ✔ chown -R www-data:www-data '${p%/}/data'"
                else
                    echo "   ⚠️  chown data/ failed - run it by hand (logs need it)."
                fi
            fi
            if [ -z "$(_docroot_blocker "$p")" ]; then
                DOCROOT_OK=1
                echo "   ✔ www-data can reach '$p' now"
                return 0
            fi
            echo "   ⚠️  still blocked after chmod - check the path printed above."
        else
            echo "   ⚠️  chmod failed - run the commands above by hand."
        fi
    fi
    return 1
}

# ==================================================================
# systemd hardening apply - persistent drop-in for /root|/home projects
# Detection lives in ONE place: systemd_sandbox_blocker() above (it reads
# the MERGED `systemctl show` value, so drop-ins are honoured).
# This function only WRITES the drop-in (install mode). --check never
# calls it - it reports via systemd_sandbox_blocker in report_health.
#   /etc/systemd/system/apache2.service.d/botsaz.conf:
#     [Service]
#     InaccessiblePaths=
#     ProtectHome=false
# Hand-editing /lib/systemd/system/apache2.service would be lost on the
# next apt upgrade; a drop-in survives it.
# ==================================================================
fix_apache_systemd_hardening() { # $1 = "apply" to write, else dry-run report
    local mode="${1:-}" dropdir dropfile u blk="" changed=0 _units=""
    case "$ROOT_DIR" in
        /root|/root/*|/home|/home/*|/var/www|/var/www/*) ;;
        *) return 0 ;;
    esac
    has_cmd systemctl || return 0
    # Every unit that can touch the path: the web server itself plus php-fpm
    # (with nginx, PHP runs there - an nginx-only drop-in would leave bot.php
    # sandboxed while statics look fine).
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
    for u in $_units; do
        blk="$(_unit_sandbox_blocker "$u" "$ROOT_DIR")"
        [ -z "$blk" ] && continue
        dropdir="/etc/systemd/system/${u}.service.d"
        dropfile="$dropdir/botsaz.conf"
        if [ -f "$dropfile" ] && grep -q '^ProtectHome=false' "$dropfile" 2>/dev/null; then
            continue
        fi
        if [ "$mode" != "apply" ]; then
            echo "   ❌ $u systemd sandbox blocks $ROOT_DIR ($blk)."
            echo "      chmod cannot fix this - run: sudo bash tools/fix_systemd_apache.sh"
            continue
        fi
        echo "   ⏳ $u is sandboxed ($blk) - writing persistent override..."
        $SUDO mkdir -p "$dropdir" 2>/dev/null || true
        printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' | $SUDO tee "$dropfile" >/dev/null
        echo "   ✔ wrote $dropfile"
        changed=1
    done
    unset _units
    if [ "$changed" = "1" ]; then
        $SUDO systemctl daemon-reload 2>/dev/null || true
        for u in apache2 httpd nginx; do
            $SUDO systemctl cat "$u" >/dev/null 2>&1 || continue
            [ -f "/etc/systemd/system/${u}.service.d/botsaz.conf" ] || continue
            $SUDO systemctl restart "$u" 2>/dev/null && echo "   ✔ $u restarted with new sandboxing" || \
                echo "   ⚠️  $u restart failed - restart manually"
        done
        for u in $(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
            u="${u%.service}"
            [ -f "/etc/systemd/system/${u}.service.d/botsaz.conf" ] || continue
            $SUDO systemctl restart "$u" 2>/dev/null && echo "   ✔ $u restarted with new sandboxing" || true
        done
    fi
    return 0
}

# Does any OTHER enabled vhost claim the same ServerName?
# certbot --apache leaves its own vhost behind (000-default-le-ssl.conf). It
# sorts before ours and Apache serves the FIRST vhost matching the name, so it
# answers from /var/www/html instead of the project and every update comes back
# "Wrong response from the webhook: 404".
#   $3 = "apply"  -> actually disable the competitors (install mode)
#   anything else -> only report them (--check is strictly read-only)
# Sets VHOST_CONFLICTS to how many competitors were found.
drop_vhost_conflicts() {
    local host="$1" mine="$2" apply="${3:-}" link tgt base minebase esc
    VHOST_CONFLICTS=0
    # ---- Apache conflicts ----
    [ -d /etc/apache2/sites-enabled ] || true
    esc="$(printf '%s' "$host" | sed 's/[.[\*^$\\/]/\\&/g')"
    minebase="$(basename "${mine%.conf}")"
    for link in /etc/apache2/sites-enabled/*; do
        [ -e "$link" ] || continue
        base="$(basename "$link")"
        base="${base%.conf}"
        tgt="$(readlink -f "$link" 2>/dev/null || true)"
        [ -n "$tgt" ] || tgt="$link"
        if [ "$base" = "$minebase" ] || [ "$tgt" = "$mine" ] || [ "$link" = "$mine" ]; then
            continue
        fi
        grep -Eq "^[[:space:]]*ServerName[[:space:]]+${esc}([[:space:]]|$)" "$tgt" 2>/dev/null || continue
        VHOST_CONFLICTS=$((VHOST_CONFLICTS + 1))
        echo "   ⚠️  Another enabled vhost also claims $host:"
        echo "        $tgt"
        if [ "$apply" = "apply" ]; then
            echo "      It loads first, so it would serve its own DocumentRoot instead of"
            echo "      this project - disabling it (both use the same certificate)."
            if has_cmd a2dissite; then $SUDO a2dissite "$base" >/dev/null 2>&1 || true; fi
            $SUDO rm -f "$link" 2>/dev/null || true
        else
            echo "      It loads first, so it answers from its own DocumentRoot and"
            echo "      Telegram gets 404. Disable it with:"
            echo "        sudo a2dissite $base && sudo systemctl reload apache2"
        fi
    done
    # ---- nginx conflicts ----
    if [ -d /etc/nginx/sites-enabled ]; then
        for link in /etc/nginx/sites-enabled/*; do
            [ -e "$link" ] || continue
            base="$(basename "$link")"
            base="${base%.conf}"
            if [ "$base" = "$minebase" ] || [ "$base" = "botsaz" ]; then
                continue
            fi
            if grep -qE "server_name[[:space:]]+${esc};" "$link" 2>/dev/null; then
                VHOST_CONFLICTS=$((VHOST_CONFLICTS + 1))
                echo "   ⚠️  Another enabled nginx vhost also claims $host:"
                echo "        $link"
                if [ "$apply" = "apply" ]; then
                    echo "      Removing nginx conflict..."
                    $SUDO rm -f "$link" 2>/dev/null || true
                else
                    echo "      Disable it with: sudo rm $link && sudo systemctl reload nginx"
                fi
            fi
        done
    fi
    return 0
}

# Ask a URL and print ONLY its HTTP status (0 = nothing answered at all).
# Used three times: is the webhook alive, is the path right, is a sensitive
# file blocked. One implementation so all three answers mean the same thing.
wh_probe() { # $1 = url -> echoes the HTTP status
    WEBHOOK_URL="$1" ROOT_DIR="$ROOT_DIR" $PHP_BIN -r '
$u = getenv("WEBHOOK_URL");
$root = getenv("ROOT_DIR");
$secret = "";
if (is_file($root."/config.php") && is_file($root."/src/Manager.php")) {
    $cfg = @include $root."/config.php";
    if (is_array($cfg)) {
        require_once $root."/src/Manager.php";
        if (class_exists("Manager") && method_exists("Manager","faximaWebhookSecret")) {
            $secret = Manager::faximaWebhookSecret((string)($cfg["main_token"] ?? ""));
        }
    }
}
$hdr = "Content-Type: application/json\r\n";
if ($secret !== "") $hdr .= "X-Telegram-Bot-Api-Secret-Token: ".$secret."\r\n";
$ctx = stream_context_create(["http" => [
    "method" => "POST", "header" => $hdr, "content" => "{}",
    "timeout" => 10, "ignore_errors" => true,
]]);
@file_get_contents($u, false, $ctx);
$code = 0;
if (isset($http_response_header[0]) && preg_match("#HTTP/\S+\s+(\d+)#", $http_response_header[0], $m)) $code = (int)$m[1];
echo $code;' 2>/dev/null || true
}

# Read the bot's status the way THE BOT reads Telegram.
# src/BotApi.php talks to api.telegram.org over cURL (30s total / 15s connect).
# Both call sites below used file_get_contents() instead, so the checker and
# the thing being checked ran two different HTTP stacks - on this very server
# getMe answered while getWebhookInfo did not, and the report could only say
# "no answer from api.telegram.org", which nobody can act on. Same stack, same
# timeouts, one short retry, and the reason comes back with the failure.
#
# It also never stops halfway. The old code did `exit` as soon as getMe failed,
# so WHURL was never printed - and "WHURL is absent" is read below as "no
# webhook registered, run set_webhook". One blip from Telegram therefore
# became an instruction to re-register a webhook that was perfectly fine.
#
# tg_status <token> -> prints ME=/WH... lines; empty output means PHP died here
tg_status() { # $1 = bot token
    TG_TOKEN="$1" "$PHP_BIN" -r '
function tg_get($url) {
    $last = "";
    for ($i = 0; $i < 2; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => "",
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ["Content-Type: application/x-www-form-urlencoded"],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            $j = json_decode($body, true);
            if (is_array($j)) return $j;
            $last = "telegram answered with something that is not JSON";
        } elseif ($body === false) {
            $last = "curl: " . $err;
        } else {
            // Telegram explains its own failures - 401/429/500 must not all
            // collapse into "no answer", because only one of them is a blip.
            $j = json_decode((string) $body, true);
            $last = (is_array($j) && !empty($j["description"]))
                ? $j["description"] . " (HTTP " . $code . ")"
                : "HTTP " . $code;
        }
        usleep(300000);
    }
    return ["ok" => false, "description" => $last];
}
$api = "https://api.telegram.org/bot" . getenv("TG_TOKEN") . "/";
$me  = tg_get($api . "getMe");
if (!empty($me["ok"])) {
    echo "ME=OK @" . ($me["result"]["username"] ?? "?") . "\n";
    $wh = tg_get($api . "getWebhookInfo");
} else {
    // Deliberately does NOT stop here - see the note above the function.
    // getWebhookInfo would ask the same host the same question over the same
    // connection, so there is no point paying the timeout for it again; but a
    // MISSING WHURL line is read by BOTH callers as "no webhook registered",
    // so the WH= line must still be printed, carrying this same reason.
    $reason = $me["description"] ?? "no reason given";
    echo "ME=ERR:" . $reason . "\n";
    $wh = ["ok" => false, "description" => $reason];
}
if (!empty($wh["ok"])) {
    $r = $wh["result"];
    echo "WHURL=" . ($r["url"] ?? "") . "\n";
    echo "WHPENDING=" . (int)($r["pending_update_count"] ?? 0) . "\n";
    echo "WHERRDATE=" . (int)($r["last_error_date"] ?? 0) . "\n";
    echo "WHERR=" . ($r["last_error_message"] ?? "") . "\n";
} else {
    echo "WH=ERR:" . ($wh["description"] ?? "no reason given") . "\n";
}' 2>/dev/null || true
}

# Read one value out of config.php without booting the whole application.
cfg_get() { # $1 = key -> value (empty if it cannot be read)
    [ -f "$ROOT_DIR/config.php" ] || return 0
    CFG_KEY="$1" CFG_FILE="$ROOT_DIR/config.php" "$PHP_BIN" -r '
$c = @include getenv("CFG_FILE");
if (is_array($c) && array_key_exists(getenv("CFG_KEY"), $c)) {
    $v = $c[getenv("CFG_KEY")];
    if (is_scalar($v)) echo (string)$v;
    elseif (is_array($v)) echo json_encode($v);
}' 2>/dev/null || true
}

# One PHP round-trip for everything that lives in the databases, so a broken
# server is never probed more times than necessary.
db_probe() {
    CFG_FILE="$ROOT_DIR/config.php" "$PHP_BIN" -r '
$c = @include getenv("CFG_FILE");
if (!is_array($c)) { echo "cfg=missing\n"; exit; }
$host = $c["db_host"] ?? "127.0.0.1"; $port = $c["db_port"] ?? 3306;
try {
    $pdo = new PDO("mysql:host=$host;port=$port",
        (string)($c["db_user"] ?? ""), (string)($c["db_pass"] ?? ""),
        [PDO::ATTR_TIMEOUT => 6, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "db=OK\n";
} catch (Throwable $e) {
    echo "db=ERR: ".str_replace("\n", " ", $e->getMessage())."\n"; exit;
}
// CREATE DATABASE is what Manager::createDatabase needs for every new child bot
$probe = "botsaz_probe_".getmypid();
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$probe`");
    $pdo->exec("DROP DATABASE `$probe`");
    echo "createdb=OK\n";
} catch (Throwable $e) { echo "createdb=ERR: ".str_replace("\n", " ", $e->getMessage())."\n"; }
// Manager SQLite DB. Migrations live HERE (tools/install.php builds a Store on
// manager_db), not in MySQL - reading them from MySQL would report "none" on a
// perfectly healthy installation.
$md = $c["manager_db"] ?? "";
$s = null; $mstate = "missing\n";
if ($md !== "" && is_file($md)) {
    try { $s = new PDO("sqlite:$md", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    catch (Throwable $e) { $mstate = "ERR: ".str_replace("\n", " ", $e->getMessage())."\n"; }
}
if ($s === null) {
    echo "mgrdb=".$mstate;
    echo "mig=none\n";
} else {
    try { echo "mig=".(int)$s->query("SELECT COALESCE(MAX(version),0) FROM schema_versions")->fetchColumn()."\n"; }
    catch (Throwable $e) { echo "mig=none\n"; }
    try {
        $all = (int)$s->query("SELECT COUNT(*) FROM bots")->fetchColumn();
        $act = (int)$s->query("SELECT COUNT(*) FROM bots WHERE status = \"active\"")->fetchColumn();
        echo "mgrdb=OK all=$all active=$act\n";
    } catch (Throwable $e) { echo "mgrdb=ERR: ".str_replace("\n", " ", $e->getMessage())."\n"; }
}
' 2>/dev/null || true
}

# ---- result counters (globals: the installer banner reads them) ----
HC_PASS=0; HC_WARN=0; HC_FAIL=0
h_ok()   { HC_PASS=$((HC_PASS + 1)); printf '  [OK]   %s\n' "$1"; }
h_warn() { HC_WARN=$((HC_WARN + 1)); printf '  [WARN] %s\n' "$1"; }
h_fail() { HC_FAIL=$((HC_FAIL + 1)); printf '  [FAIL] %s\n' "$1"; }
h_note() { printf '         %s\n' "$1"; }   # context only, not a result

# Is mod_rewrite really enabled?
# `a2enmod -l` does not exist in every Apache build - where it is missing it
# prints usage on stderr and exits non-zero, and the old test redirected that
# to /dev/null, so the check reported "OFF" on servers that had it ON. Ask
# four different ways and take "yes" from any of them.
apache_rewrite_on() {
    [ -e /etc/apache2/mods-enabled/rewrite.load ] && return 0
    [ -e /etc/apache2/mods-enabled/rewrite.conf ] && return 0
    if has_cmd a2query;    then a2query -m rewrite    >/dev/null 2>&1 && return 0; fi
    if has_cmd apache2ctl; then apache2ctl -M 2>/dev/null | grep -q '^rewrite_module' && return 0; fi
    if has_cmd apachectl;  then apachectl  -M 2>/dev/null | grep -q '^rewrite_module' && return 0; fi
    a2enmod -l 2>/dev/null | grep -qx 'rewrite' && return 0
    return 1
}

# ---------------------------------------------------------------
# report_health - "is everything actually in place?"
# Read-only: never writes, never prompts, never changes the system.
# ---------------------------------------------------------------
report_health() {
    HC_PASS=0; HC_WARN=0; HC_FAIL=0
    bot_up=0; tpl_ok=0; wh_ok=0; DOCROOT_OK=1
    local cfg="$ROOT_DIR/config.php" base_url="" main_token="" host=""
    local v code f m mods missing pv

    printf '\n==========================================\n'
    printf '  🔍  Health check - every part of the bot\n'
    printf '==========================================\n'

    # ---------- 1) PHP ----------
    printf '\n  --- 1) PHP ---\n'
    pv="$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    if [ -z "$pv" ]; then
        h_fail "PHP CLI not found - install php8.2-cli or fix PATH"
    else
        if "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' 2>/dev/null; then
            h_ok "PHP $pv (project needs >= 8.2)"
        else
            h_fail "PHP $pv is older than the 8.2 this project needs"
        fi
        mods="$("$PHP_BIN" -m 2>/dev/null || true)"
        missing=""
        for m in pdo_mysql pdo_sqlite curl mbstring openssl json; do
            printf '%s\n' "$mods" | grep -qi "^$m$" || missing="$missing $m"
        done
        if [ -z "$missing" ]; then
            h_ok "extensions loaded: pdo_mysql pdo_sqlite curl mbstring openssl json"
        else
            h_fail "missing PHP extensions:$missing"
        fi
    fi

    # ---------- 2) web server ----------
    printf '\n  --- 2) Web server ---\n'
    local ws=""
    if has_cmd apache2ctl || has_cmd apache2; then ws="apache2"; fi
    if [ -z "$ws" ] && has_cmd nginx; then ws="nginx"; fi
    if [ -z "$ws" ]; then
        h_fail "neither Apache nor nginx is installed - nothing can serve the webhook"
    else
        h_ok "$ws is installed"
        if has_cmd systemctl && systemctl is-active --quiet "$ws" 2>/dev/null; then
            h_ok "$ws service is running"
        else
            h_warn "$ws service is not running - sudo systemctl start $ws"
        fi
        if [ "$ws" = "apache2" ] || [ "$ws" = "httpd" ]; then
            if apache_rewrite_on; then
                h_ok "mod_rewrite enabled (.htaccess protection is in force)"
            else
                h_fail "mod_rewrite is OFF - .htaccess is ignored and config.php/src/data become public"
                h_note "sudo a2enmod rewrite && sudo systemctl reload apache2"
            fi
        fi
        # nginx serves statics itself; PHP only runs if php-fpm behind it is alive.
        # Without this, a dead php-fpm looks like a healthy server until every
        # webhook answers 502 - which no other check would connect to php-fpm.
        if [ "$ws" = "nginx" ]; then
            local _fpm_svc=""
            if has_cmd systemctl; then
                _fpm_svc="$(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' | head -n1 || true)"
            fi
            if [ -z "$_fpm_svc" ]; then
                h_fail "no php-fpm service found - nginx serves statics but PHP never executes"
            elif systemctl is-active --quiet "$_fpm_svc" 2>/dev/null; then
                h_ok "$_fpm_svc is running"
            else
                h_fail "$_fpm_svc is not running - sudo systemctl start $_fpm_svc"
            fi
            unset _fpm_svc
        fi
    fi

    # ---------- 3) project files ----------
    printf '\n  --- 3) Project files ---\n'
    if [ -f "$cfg" ]; then h_ok "config.php exists"; else h_fail "config.php is missing - the bot cannot start"; fi
    missing=""
    for f in bot.php index.php src/Manager.php src/BotApi.php src/Store.php \
             templates/faxima/config.php templates/mirza/config.php \
             templates/faxima/index.php templates/mirza/index.php; do
        [ -f "$ROOT_DIR/$f" ] || missing="$missing $f"
    done
    if [ -z "$missing" ]; then tpl_ok=1; h_ok "core files and both bot templates present"
    else tpl_ok=0; h_fail "missing files:$missing (re-clone the repository)"; fi

    if [ ! -d "$ROOT_DIR/data" ]; then
        h_warn "data/ does not exist yet - it is created on first run"
    elif [ "$(id -u)" -eq 0 ]; then
        if has_cmd sudo && sudo -u www-data test -w "$ROOT_DIR/data" 2>/dev/null; then
            h_ok "www-data can write data/ (logs + manager database)"
        else
            h_fail "www-data cannot write data/ - sudo chown -R www-data:www-data '$ROOT_DIR/data'"
        fi
    elif [ -w "$ROOT_DIR/data" ]; then
        h_ok "data/ is writable"
    else
        h_fail "data/ is not writable - no logs and no manager database"
    fi

    # ----- bots/ directory check (child bot creation) -----
    if [ ! -d "$ROOT_DIR/bots" ]; then
        h_warn "bots/ does not exist yet - it is created on first bot build"
    elif [ "$(id -u)" -eq 0 ]; then
        _bots_owner="$(stat -c '%U:%G' "$ROOT_DIR/bots" 2>/dev/null)"
        if [ "$_bots_owner" = "www-data:www-data" ]; then
            h_ok "bots/ is writable (www-data owns it)"
        else
            h_warn "bots/ owner is $_bots_owner - child bots can't be built"
            h_note "sudo chown www-data:www-data '$ROOT_DIR/bots'"
        fi
    elif [ -w "$ROOT_DIR/bots" ]; then
        h_ok "bots/ is writable"
    else
        h_warn "bots/ is not writable - child bot builds will fail"
        h_note "sudo chown www-data:www-data '$ROOT_DIR/bots'"
    fi

    # ---------- 4) config.php values ----------
    printf '\n  --- 4) config.php values ---\n'
    base_url="$(cfg_get base_url)"
    main_token="$(cfg_get main_token)"
    host="$(printf '%s' "$base_url" | sed -E 's#^[A-Za-z][A-Za-z0-9+.-]*://##' | cut -d/ -f1 | cut -d: -f1)"

    if [ -z "$base_url" ]; then
        h_fail "base_url is empty"
    elif [ "${base_url#https://}" = "$base_url" ]; then
        h_fail "base_url is '$base_url' - it must start with https:// (Telegram refuses http)"
    else
        h_ok "base_url = $base_url"
    fi
    if [ -z "$main_token" ] || ! printf '%s' "$main_token" | grep -Eq '^[0-9]+:[A-Za-z0-9_-]{30,}$'; then
        h_fail "main_token is missing or still a placeholder - take it from @BotFather"
    else
        h_ok "main_token is a real Telegram token"
    fi
    v="$(cfg_get super_admins)"
    if [ -z "$v" ] || [ "$v" = "[123456789]" ]; then
        h_fail "super_admins is still [123456789] - nobody can control the bot"
    else
        h_ok "super_admins = $v"
    fi
    v="$(cfg_get secret_key)"
    if [ -z "$v" ] || [ "$v" = "change-this-to-a-random-string-32bytes!" ]; then
        h_fail "secret_key is still the default - child bot tokens are not encrypted"
    else
        h_ok "secret_key has been replaced with your own value"
    fi

    # ---------- 5) vhost / DocumentRoot ----------
    printf '\n  --- 5) Vhost and DocumentRoot ---\n'
    if [ -n "$host" ]; then
        DOCROOT_OK=1
        if docroot_reachable "$ROOT_DIR"; then
            h_ok "$ROOT_DIR is reachable by the web-server user"
        else
            h_fail "DocumentRoot is not reachable - see the explanation printed above"
        fi

        # The check above walks the path as the account, from THIS shell.
        # Apache walks it from inside its own mount namespace, and that is a
        # different answer: ProtectHome hides /root and /home there, so the
        # account test passes, namei passes, every mode bit passes - and the
        # server still answers 403 to every request. Nothing else in this
        # report can see the sandbox, so ask about it separately, or this
        # disagreement is reported as a healthy server forever.
        local _sb="" _sb_rc=0
        _sb="$(systemd_sandbox_blocker "$ROOT_DIR")" || _sb_rc=$?
        if [ "$_sb_rc" = "3" ]; then
            h_warn "cannot ask systemd whether it hides $ROOT_DIR from the web server"
            h_note "if that service runs under ProtectHome, every request is 403 while"
            h_note "all the checks above still pass - verify with:"
            h_note "  systemctl show apache2 nginx 'php*-fpm' -p ProtectHome --value"
        elif [ -n "$_sb" ]; then
            # _sb looks like "unit:reason" (e.g. php8.2-fpm:ProtectHome=yes),
            # so the manual fix below names the RIGHT unit, not always apache2.
            local _sb_unit="${_sb%%:*}"
            [ -n "$_sb_unit" ] || _sb_unit="apache2"
            h_fail "systemd keeps the web server out of $ROOT_DIR ($_sb)"
            h_note "this is why every permission check passed and requests still failed:"
            h_note "those run outside the service sandbox, $_sb_unit runs inside it"
            h_note "install mode writes the drop-in for you; by hand it is:"
            h_note "  sudo mkdir -p /etc/systemd/system/${_sb_unit}.service.d"
            h_note "  printf '[Service]\nInaccessiblePaths=\nProtectHome=false\n' | sudo tee /etc/systemd/system/${_sb_unit}.service.d/botsaz.conf"
            h_note "  sudo systemctl daemon-reload && sudo systemctl restart $_sb_unit"
        else
            h_ok "systemd does not hide $ROOT_DIR from the web server"
        fi
        unset _sb _sb_rc _sb_unit

        if [ -d /etc/apache2/sites-enabled ]; then
            drop_vhost_conflicts "$host" "/etc/apache2/sites-available/botsaz.conf"
            if [ "${VHOST_CONFLICTS:-0}" = "0" ]; then
                h_ok "no other vhost competes for $host"
            else
                h_fail "$VHOST_CONFLICTS other vhost(s) also claim $host - they win and cause 404"
            fi

            # Our own vhost must answer the name config.php asks for, and it
            # must have an HTTPS block. Without any SSL vhost, Apache falls
            # back to the first non-TLS vhost on port 443, answers the
            # handshake with plain text, and Telegram reports that as
            #   SSL error {error:0A0000C6:SSL routines::packet length too long}
            # instead of an HTTP status - which nothing else in this report
            # would ever connect to a missing <VirtualHost *:443>.
            local own_a="/etc/apache2/sites-available/botsaz.conf"
            local own_e="/etc/apache2/sites-enabled/botsaz.conf"
            if [ ! -f "$own_a" ]; then
                h_warn "no vhost of our own was written - bash tools/install.sh"
            elif [ ! -e "$own_e" ]; then
                h_fail "$own_a exists but is not enabled - sudo a2ensite botsaz && sudo systemctl reload apache2"
            else
                local vhost_h=""
                vhost_h="$(sed -n 's/^[[:space:]]*ServerName[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' "$own_a" | head -n1)"
                if [ "$vhost_h" = "$host" ]; then
                    h_ok "our vhost claims $host itself"
                else
                    h_fail "our vhost claims '${vhost_h:-no ServerName}' but base_url is '$host'"
                    h_note "a re-run of this installer offers to rewrite it (with a backup):"
                    h_note "  bash tools/install.sh"
                    h_note "or by hand: sudo nano $own_a   -> ServerName $host in both blocks"
                fi
                if grep -qE '^[[:space:]]*<VirtualHost[^>]*:443' "$own_a"; then
                    if grep -q "letsencrypt/live/$host/" "$own_a"; then
                        h_ok "HTTPS block present and its certificate belongs to $host"
                    else
                        h_fail "HTTPS block present but its certificate belongs to another domain"
                        h_note "a re-run of this installer rewrites the vhost with the right one"
                    fi
                else
                    h_fail "no <VirtualHost *:443> block - https://$host cannot be answered at all"
                    h_note "Apache replies with plain text on 443 in this state; Telegram reports:"
                    h_note "  SSL error {error:0A0000C6:SSL routines::packet length too long}"
                    h_note "fix: once the certificate exists, run bash tools/install.sh again"
                fi
            fi
        elif has_cmd nginx && [ -f /etc/nginx/sites-available/botsaz.conf ]; then
            # nginx-only server: same questions as the Apache branch above, but
            # answered from the nginx syntax (server_name/root/listen/ssl_certificate).
            local own_n="/etc/nginx/sites-available/botsaz.conf"
            local own_ne="/etc/nginx/sites-enabled/botsaz.conf"
            if [ ! -e "$own_ne" ]; then
                h_fail "$own_n exists but is not enabled - sudo ln -s $own_n $own_ne && sudo systemctl reload nginx"
            else
                local ng_h="" ng_root="" ng_sock="" ng_fpm="" _nf _dup
                ng_h="$(sed -n 's/^[[:space:]]*server_name[[:space:]]\{1,\}\([^;[:space:]]*\).*/\1/p' "$own_n" | head -n1)"
                ng_root="$(sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}"\?\([^";]*\)"\?;.*/\1/p' "$own_n" | head -n1)"
                if [ "$ng_h" = "$host" ]; then
                    h_ok "our nginx vhost claims $host itself"
                else
                    h_fail "our nginx vhost claims '${ng_h:-no server_name}' but base_url is '$host'"
                    h_note "re-run bash tools/install.sh to rewrite it (a backup is taken first)"
                fi
                if [ "$ng_root" = "$ROOT_DIR" ]; then
                    h_ok "our nginx vhost serves $ROOT_DIR"
                else
                    h_fail "our nginx vhost serves '${ng_root:-no root}' instead of $ROOT_DIR"
                    h_note "Telegram delivers to a DocumentRoot that is not this project"
                fi
                if grep -qE 'listen[[:space:]]+(\[::\]:)?443 ssl' "$own_n"; then
                    if grep -q "letsencrypt/live/$host/" "$own_n"; then
                        h_ok "HTTPS block present and its certificate belongs to $host"
                    else
                        h_fail "HTTPS block present but its certificate belongs to another domain"
                    fi
                else
                    h_fail "no listen 443 ssl block - https://$host cannot be answered at all"
                    h_note "fix: once the certificate exists, run bash tools/install.sh again"
                fi
                # another enabled server with the same name wins by file order
                _dup=""
                for _nf in /etc/nginx/sites-enabled/*; do
                    [ -e "$_nf" ] || continue
                    case "$_nf" in *botsaz.conf) continue ;; esac
                    if grep -Eq "server_name[^;]*[[:space:]]$(printf '%s' "$host" | sed 's/[.[\*^$\\/]/\\&/g')([;[:space:]]|$)" "$_nf" 2>/dev/null; then
                        _dup="$_dup $(basename "$_nf")"
                    fi
                done
                if [ -z "$_dup" ]; then
                    h_ok "no other nginx server competes for $host"
                else
                    h_fail "other nginx server(s) also claim $host:$_dup - first file wins, Telegram may 404"
                    h_note "sudo rm /etc/nginx/sites-enabled/default (if unused) && sudo systemctl reload nginx"
                fi
                unset _nf _dup
                # PHP only runs if the baked-in socket still exists
                ng_sock="$(sed -n 's/^[[:space:]]*fastcgi_pass[[:space:]]\{1,\}unix:\([^;]*\);.*/\1/p' "$own_n" | head -n1)"
                if [ -z "$ng_sock" ]; then
                    h_fail "no fastcgi_pass socket in $own_n - PHP never executes (502 for every bot)"
                elif [ -S "$ng_sock" ]; then
                    h_ok "php-fpm socket $ng_sock exists"
                else
                    h_fail "php-fpm socket $ng_sock is missing - every PHP request is 502"
                    h_note "the PHP version changed after the vhost was written: re-run bash tools/install.sh"
                fi
                # ...and the service behind the socket has to be alive
                ng_fpm="$(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' | head -n1 || true)"
                if [ -z "$ng_fpm" ]; then
                    h_warn "no php-fpm service found - nginx cannot execute PHP"
                elif systemctl is-active --quiet "$ng_fpm" 2>/dev/null; then
                    h_ok "$ng_fpm is running"
                else
                    h_fail "$ng_fpm is not running - sudo systemctl start $ng_fpm"
                fi
                unset ng_h ng_root ng_sock ng_fpm
            fi
        fi
    else
        h_warn "no hostname in base_url - cannot inspect the vhost"
    fi

    # ---------- 6) SSL ----------
    printf '\n  --- 6) SSL certificate ---\n'
    local cert="/etc/letsencrypt/live/$host/fullchain.pem" endd t_end days_left=-1
    if [ -z "$host" ]; then
        h_warn "no hostname - cannot check the certificate"
    elif [ ! -f "$cert" ]; then
        if has_cmd apache2 || has_cmd httpd; then
            h_warn "no Let's Encrypt certificate for $host - certbot --apache -d $host --non-interactive"
        elif has_cmd nginx; then
            h_warn "no Let's Encrypt certificate for $host - certbot --nginx -d $host --non-interactive"
        else
            h_warn "no Let's Encrypt certificate for $host - install a web server and run certbot"
        fi
    else
        endd="$(openssl x509 -enddate -noout -in "$cert" 2>/dev/null | cut -d= -f2 || true)"
        t_end="$(date -d "$endd" +%s 2>/dev/null || true)"
        if [ -n "$t_end" ]; then
            days_left=$(( (t_end - $(date +%s)) / 86400 ))
            if [ "$days_left" -lt 0 ]; then
                h_fail "certificate for $host EXPIRED on $endd"
            elif [ "$days_left" -lt 14 ]; then
                h_warn "certificate for $host expires in $days_left day(s) - renew now"
            else
                h_ok "certificate for $host is valid for $days_left more days"
            fi
        else
            h_ok "certificate for $host is present"
        fi
    fi

    # ---------- 7) DNS ----------
    printf '\n  --- 7) DNS ---\n'
    local ip="" aaaa=""
    if [ -z "$host" ]; then
        h_warn "no hostname - cannot check DNS"
    else
        if has_cmd getent; then ip="$(getent ahostsv4 "$host" 2>/dev/null | awk '{print $1; exit}')" || ip=""; fi
        if [ -z "$ip" ] && has_cmd dig; then ip="$(dig +short "$host" A 2>/dev/null | grep -E '^[0-9.]+$' | head -n1)" || ip=""; fi
        if [ -z "$ip" ]; then
            h_warn "$host does not resolve - Telegram will never reach the webhook"
        elif printf '%s' "$ip" | grep -qE '^(127\.|0\.0\.0\.0)'; then
            h_warn "$host resolves to $ip (local only) - a public webhook cannot use that"
        else
            h_ok "$host resolves to $ip"
        fi
        # An AAAA record is not decoration: Telegram speaks IPv6 too, and if it
        # lands on another machine the webhook 404s THERE while every probe made
        # from this server still looks green - two different DocumentRoots
        # answering the same URL within the same second.
        if has_cmd getent; then
            aaaa="$(getent ahostsv6 "$host" 2>/dev/null | awk '{print $1}' | grep -v '^::ffff:' | head -n1)" || aaaa=""
        fi
        if [ -z "$aaaa" ] && has_cmd dig; then
            aaaa="$(dig +short "$host" AAAA 2>/dev/null | grep -E '^[0-9a-fA-F:]+:[0-9a-fA-F:]+$' | head -n1)" || aaaa=""
        fi
        if ! has_cmd getent && ! has_cmd dig; then
            h_warn "cannot check for an AAAA record (need getent or dig)"
        elif [ -z "$aaaa" ]; then
            h_ok "no AAAA record - Telegram reaches this server over IPv4 ($ip)"
        elif ! has_cmd ip; then
            h_warn "$host has AAAA $aaaa - confirm it points at this server"
        elif ip -6 -o addr show 2>/dev/null | grep -qF "$aaaa"; then
            h_ok "AAAA $aaaa is configured on this server - IPv6 reaches us too"
        else
            h_warn "$host has AAAA $aaaa, which is NOT an address of this server"
            h_note "Telegram would then reach a different machine: our own probe gets"
            h_note "403 from this vhost while Telegram gets 404 from another one."
            h_note "getent ahosts $host"
            h_note "curl -6 -sI https://$host/ -o /dev/null -w '%{http_code}\\n'"
        fi
    fi

    # ---------- 8) databases ----------
    printf '\n  --- 8) Database ---\n'
    # ONE probe for all four answers: a server with a broken database must not
    # be hit six times just to build one report.
    local dbv migv crv mgv dbr
    dbr="$(db_probe)"
    dbv="$(printf '%s\n' "$dbr" | sed -n 's/^db=//p' | head -n1)"
    migv="$(printf '%s\n' "$dbr" | sed -n 's/^mig=//p' | head -n1)"
    crv="$(printf '%s\n' "$dbr" | sed -n 's/^createdb=//p' | head -n1)"
    mgv="$(printf '%s\n' "$dbr" | sed -n 's/^mgrdb=//p' | head -n1)"
    case "$dbv" in
        OK) h_ok "MySQL connection works ($(cfg_get db_user)@$(cfg_get db_host))" ;;
        missing) h_fail "config.php could not be read - cannot test the database" ;;
        ERR:*) h_fail "MySQL connection failed: $dbv" ;;
        *) h_fail "MySQL gave no answer at all (service down? wrong port?)" ;;
    esac
    if [ -n "$migv" ] && [ "$migv" != "none" ] && [ "$migv" != "0" ]; then
        h_ok "migrations applied through v$migv"
    else
        h_warn "no migrations recorded - php tools/install.php"
    fi
    case "$crv" in
        OK) h_ok "this account can CREATE DATABASE (child bots can be built)" ;;
        ERR:*) h_fail "CREATE DATABASE denied: $crv" ;;
        *) h_warn "could not test CREATE DATABASE" ;;
    esac
    case "$mgv" in
        OK*) h_ok "manager database: ${mgv#OK }" ;;
        missing) h_warn "manager database not created yet - php tools/install.php" ;;
        ERR:*) h_fail "manager database error: $mgv" ;;
        *) h_warn "manager database could not be read" ;;
    esac

    # ---------- 9) Telegram main bot ----------
    printf '\n  --- 9) Telegram main bot ---\n'
    if [ -z "$main_token" ] || ! printf '%s' "$main_token" | grep -Eq '^[0-9]+:[A-Za-z0-9_-]{30,}$'; then
        h_warn "skipped - main_token is not usable yet"
    else
        # One shared probe (tg_status) instead of an inline file_get_contents:
        # it retries, it uses the bot's own HTTP stack, and it reports the real
        # reason - and it always comes back with the WH* keys, so a failure
        # reads as "could not read", never as "no webhook registered".
        v="$(tg_status "$main_token")"

        local meline whurl pend wherrd wherr whinfo expected who probe_missing
        local have_whurl
        meline="$(printf '%s\n' "$v" | sed -n 's/^ME=//p')"
        whinfo="$(printf '%s\n' "$v" | sed -n 's/^WH=//p')"
        whurl="$(printf '%s\n' "$v" | sed -n 's/^WHURL=//p')"
        pend="$(printf '%s\n' "$v" | sed -n 's/^WHPENDING=//p')"
        wherrd="$(printf '%s\n' "$v" | sed -n 's/^WHERRDATE=//p')"
        wherr="$(printf '%s\n' "$v" | sed -n 's/^WHERR=//p')"

        # WHURL can legitimately be EMPTY (no webhook recorded) - which is a
        # completely different fact from the line being ABSENT (the probe died
        # before it could be printed). Only the second one may never be read as
        # "no webhook registered", so test for the line itself.
        have_whurl=0
        if printf '%s\n' "$v" | grep -q '^WHURL='; then have_whurl=1; fi

        # getMe prints "ME=OK @username" (a space before the @), so the match
        # has to allow that space - matching only "OK@" made every healthy bot
        # fall into the "unreachable" branch and the banner never verified it.
        case "$meline" in
            OK\ *|OK@*)
                bot_up=1
                who="$(printf '%s' "${meline#OK}" | sed 's/^[ @]*//')"
                h_ok "bot is alive${who:+ @$who}"
                ;;
            ERR:*) bot_up=0; h_fail "Telegram rejected the token: ${meline#ERR:}" ;;
            *)
                bot_up=0
                # Empty $v is NOT "Telegram did not answer": nothing left this
                # machine at all, which is a local php/curl failure. Saying
                # otherwise would send the reader off to debug the network.
                if [ -z "$v" ]; then
                    h_fail "the Telegram probe printed nothing - php or curl failed locally"
                else
                    h_fail "api.telegram.org unreachable - cannot verify the bot"
                fi
                ;;
        esac

        # getWebhookInfo can fail on its own (rate limit, a blip) while getMe
        # succeeds. Then WHURL is simply absent - and reading that as "no
        # webhook registered" sends the reader to re-run set_webhook on a
        # server whose webhook was perfectly fine, which is what happened.
        if [ -n "$whinfo" ]; then
            h_warn "could not read getWebhookInfo: ${whinfo#ERR:}"
            h_note "the webhook status is UNKNOWN right now, not missing - nothing to fix here"
        elif [ "$have_whurl" != "1" ]; then
            # Neither a value nor an explanation came back - the probe itself
            # failed here. That is a fact about THIS server, and it says
            # nothing whatever about what Telegram has on record.
            h_fail "the Telegram probe returned no webhook data at all"
            h_note "the webhook status is UNKNOWN, not missing - nothing to fix here"
        else
        if [ -z "$whurl" ]; then
            h_fail "no webhook registered - php tools/set_webhook.php"
        else
            h_ok "webhook registered: $whurl"
        fi
        if [ -n "$wherr" ]; then
            h_fail "Telegram last error: $wherr"
            [ -n "$wherrd" ] && h_note "at $(date -d "@$wherrd" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "$wherrd")"
        else
            h_ok "Telegram reports no webhook error"
        fi
        if [ -n "$pend" ] && [ "$pend" -gt 0 ] 2>/dev/null; then
            h_warn "$pend update(s) queued - the bot is not consuming them"
        else
            h_ok "no updates waiting"
        fi
        fi

        # ...and the question Telegram actually asks: does that URL answer?
        expected="${base_url%/}/bot.php"
        if [ -z "$base_url" ]; then
            h_warn "cannot probe the webhook URL without base_url"
        else
            code="$(wh_probe "$expected")"
            case "$code" in
                200)      wh_ok=1; h_ok "webhook answers HTTP 200 at $expected" ;;
                403)
                    # A 403 has two very different causes: .htaccess rejecting
                    # the secret, or Apache never reaching the path at all
                    # (AH00035 "search permissions are missing"). Ask for a
                    # file that does not exist to tell them apart - an
                    # unreachable path answers 403, a reachable one 404.
                    probe_missing="$(wh_probe "${base_url%/}/.botsaz-no-such-file")"
                    if [ "$probe_missing" = "403" ] || [ "$DOCROOT_OK" != "1" ]; then
                        wh_ok=0
                        h_fail "HTTP 403 at $expected - Apache never reaches the project"
                        h_note "this is Apache's own answer, so it outranks any mode-bit walk above"
                        h_note "the secret has nothing to do with it; Telegram sees exactly this 403"
                        h_note "which component is closed - every one of them, with owner and mode:"
                        show_path_chain "${ROOT_DIR}/bot.php"
                        h_note "namei -l ${ROOT_DIR}/bot.php   and   sudo -u $(apache_run_user) test -x $ROOT_DIR"
                        # namei only prints mode bits, and this very server was
                        # seen with a fully traversable chain on screen while
                        # Apache was answering AH00035 for the same path. So
                        # walk the evidence instead of guessing from the bits:
                        # the disk, then the account, then everything above it.
                        local _blk_disk _blk_acct
                        _blk_disk="$(_docroot_blocker "$ROOT_DIR")"
                        if [ -n "$_blk_disk" ]; then
                            h_note "verdict: '$_blk_disk' really is closed on disk - section 5 printed the chmod for it"
                        else
                            _blk_acct="$(webuser_blocker "$ROOT_DIR")" || _blk_acct=""
                            if [ -n "$_blk_acct" ]; then
                                h_note "verdict: every mode bit allows it, yet $(apache_run_user) cannot search '$_blk_acct'"
                                h_note "so it is an ACL, a group grant, or a restriction on the service - not a chmod:"
                                h_note "  getfacl -p / /root ${ROOT_DIR}"
                                h_note "  sudo -u $(apache_run_user) test -x ${ROOT_DIR} && echo yes || echo no"
                            else
                                h_note "verdict: the path AND the account both say yes, yet Apache answers 403."
                                h_note "then the refusal is not on disk at all - it is on the Apache process,"
                                h_note "or the request never reaches this project. In order of likelihood:"
                                h_note "  cat /sys/module/apparmor/parameters/enabled ; aa-status 2>&1 | head -20"
                                h_note "  dmesg | grep -i 'apparmor.*DENIED' | tail"
                                h_note "  apachectl -S      (which vhost really answers this name?)"
                                h_note "  grep -rn 'Require\\|Deny\\|Allow' /etc/apache2/sites-enabled/ ${ROOT_DIR}/.htaccess"
                            fi
                        fi
                    else
                        wh_ok=1
                        h_ok "webhook answers HTTP 403 (secret rejected, route is alive) at $expected"
                        h_note "a 403 means Apache+PHP+path all work; only the secret token differs"
                    fi
                    ;;
                404)
                    wh_ok=0
                    h_fail "HTTP 404 at $expected - Telegram gets exactly this and drops every update"
                    local origin origin_code
                    origin="$(printf '%s' "${BASE_URL:-}" | sed -E 's#^(https?://[^/]+).*#\1#')"
                    origin="${origin:-$(printf '%s' "$expected" | sed -E 's#^(https?://[^/]+).*#\1#')}"
                    origin_code="$(wh_probe "$origin/bot.php")"
                    if [ "$origin_code" = "200" ] || [ "$origin_code" = "403" ]; then
                        h_note "$origin/bot.php answers (HTTP $origin_code), so the PATH in base_url is wrong:"
                        h_note "set 'base_url' => '$origin' in config.php, then php tools/set_webhook.php"
                    else
                        h_note "$origin/bot.php answered HTTP ${origin_code:-0} too - no vhost serves this project:"
                        h_note "apachectl -S   and   grep -n DocumentRoot /etc/apache2/sites-enabled/*.conf"
                    fi
                    ;;
                0)        wh_ok=0; h_fail "nothing answers $expected (DNS / vhost / web server down)" ;;
                500|502|503) wh_ok=0; h_fail "HTTP $code at $expected - PHP is failing, see the log report below" ;;
                *)        wh_ok=0; h_warn "HTTP $code at $expected" ;;
            esac

            # "Telegram last error: Connection refused" three lines above,
            # next to "webhook answers HTTP 200", is a contradiction the
            # reader is left to resolve alone - and it is exactly the shape
            # of this report right after the script itself restarts Apache:
            # Telegram's delivery fails inside that restart, and the probe
            # above succeeds a second later. Say how old the error is and
            # what our own successful request proves about it. The FAIL is
            # kept: an error that outlives a working probe means Telegram
            # reaches this server by a different route than we do.
            local _now_ts _age
            if [ -n "$wherr" ] && [ -n "$wherrd" ] && [ "$wh_ok" = "1" ] \
                && [ "$wherrd" -gt 0 ] 2>/dev/null \
                && printf '%s' "$wherrd" | grep -Eq '^[0-9]+$'; then
                _now_ts="$(date +%s 2>/dev/null || echo 0)"
                if printf '%s' "$_now_ts" | grep -Eq '^[0-9]+$' \
                    && [ "$_now_ts" -gt "$wherrd" ]; then
                    _age=$((_now_ts - wherrd))
                    h_note "that error is ${_age}s old, and our own request to this same URL succeeded after it"
                    h_note "so it describes an older moment: Telegram clears it on the next SUCCESSFUL delivery, not with time"
                    h_note "send the bot any message (/start), then re-run --check - if the bot answers, this line is gone"
                    h_note "if it stays while the probe keeps answering 200 AND new messages get no reply, Telegram cannot reach us the way we reach ourselves"
                fi
            fi
            if [ -n "$whurl" ] && [ "$whurl" != "$expected" ]; then
                h_warn "Telegram has '$whurl' but config expects '$expected' - re-run php tools/set_webhook.php"
            fi
        fi
    fi

    # ---------- 10) is anything private leaking? ----------
    printf '\n  --- 10) Private files exposed to the internet ---\n'
    if [ "$wh_ok" = "1" ] && [ "$DOCROOT_OK" = "1" ] && [ -n "$base_url" ]; then
        for f in "config.php" "src/" "tools/" "data/"; do
            code="$(wh_probe "${base_url%/}/$f")"
            case "$code" in
                200) h_fail "PUBLIC: ${base_url%/}/$f returns 200 - the file is downloadable!" ;;
                0)   h_warn "could not test ${base_url%/}/$f (no answer)" ;;
                *)   h_ok "blocked: /$f -> HTTP $code" ;;
            esac
        done
    elif [ "$DOCROOT_OK" != "1" ]; then
        # Every probe would come back 403 for the wrong reason (AH00035), so a
        # green result here would be a lie. Say so instead.
        h_warn "skipped - Apache cannot reach DocumentRoot yet, so nothing proves .htaccess is doing the blocking"
    else
        h_warn "skipped - the site has to answer before this means anything"
    fi

    # ---------- 11) child bots ----------
    printf '\n  --- 11) Child bots ---\n'
    local allb actb
    allb="$(printf '%s\n' "$dbr" | sed -n 's/^mgrdb=OK all=\([0-9]*\).*/\1/p')"
    actb="$(printf '%s\n' "$dbr" | sed -n 's/^mgrdb=OK all=[0-9]* active=\([0-9]*\).*/\1/p')"
    if [ -z "$allb" ]; then
        h_warn "cannot list child bots (manager database unreadable)"
    elif [ "$allb" = "0" ]; then
        h_ok "no child bots created yet - nothing else to check here"
    else
        h_ok "$allb child bot(s) registered, $actb active"
        # every active child must have a webhook and a token that still works
        local child_bad=0 child_shown=0
        while IFS='|' read -r cf ct cu cw; do
            [ -n "$cf" ] || continue
            child_shown=$((child_shown + 1))
            if [ -z "$cw" ]; then
                h_warn "$cf ($ct): no webhook URL stored"
                child_bad=$((child_bad + 1))
                continue
            fi
            code="$(wh_probe "$cw")"
            case "$code" in
                200|403) : ;;
                404)
                    h_fail "$cf ($ct): webhook 404 at $cw"
                    child_bad=$((child_bad + 1)) ;;
                0)
                    h_fail "$cf ($ct): webhook does not answer at $cw"
                    child_bad=$((child_bad + 1)) ;;
                *)
                    h_warn "$cf ($ct): webhook answered HTTP $code at $cw"
                    child_bad=$((child_bad + 1)) ;;
            esac
        done <<CHILDREN
$(db_probe_children)
CHILDREN
        if [ "$child_bad" = "0" ] && [ "$child_shown" -gt 0 ]; then
            h_ok "all $child_shown checked child bot webhooks answer"
        fi
    fi

    # ---------- summary ----------
    printf '\n==========================================\n'
    if [ "$HC_FAIL" = "0" ] && [ "$HC_WARN" = "0" ]; then
        printf '  ✅ All %d checks passed.\n' "$HC_PASS"
    elif [ "$HC_FAIL" = "0" ]; then
        printf '  ✅ %d OK, %d warning(s), no failures.\n' "$HC_PASS" "$HC_WARN"
    else
        printf '  ❌ %d OK, %d warning(s), %d FAILURE(S).\n' "$HC_PASS" "$HC_WARN" "$HC_FAIL"
        printf '     Fix the [FAIL] lines above, then re-run:\n'
        printf '       bash tools/install.sh --check\n'
    fi
    printf '==========================================\n'
    return 0
}

# folder|type|username|webhook_url for every active child bot (one per line)
db_probe_children() {
    CFG_FILE="$ROOT_DIR/config.php" "$PHP_BIN" -r '
$c = @include getenv("CFG_FILE");
if (!is_array($c)) exit;
$md = $c["manager_db"] ?? "";
if ($md === "" || !is_file($md)) exit;
try {
    $s = new PDO("sqlite:$md", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($s->query("SELECT folder, type, bot_username, webhook_url FROM bots WHERE status = \"active\" ORDER BY id") as $r) {
        echo $r["folder"]."|".$r["type"]."|".$r["bot_username"]."|".$r["webhook_url"]."\n";
    }
} catch (Throwable $e) {}' 2>/dev/null || true
}

# ---------------------------------------------------------------
# report_logs - "what went wrong, from every place it can be written"
# Read-only. Only problems are shown; INFO/DEBUG traffic is never printed.
#   $1 = how many days back to look (default 7)
# ---------------------------------------------------------------
report_logs() {
    local days="${1:-7}" n total=0 lines f src
    local any_log=0

    printf '\n==========================================\n'
    printf '  📜  Errors in the last %s days\n' "$days"
    printf '==========================================\n'

    # ---- [1] the application's own log (src/Logger.php -> data/logs/) ----
    printf '\n  [1] Application log   data/logs/*.log (last %s days)\n' "$days"
    if ! ls "$ROOT_DIR/data/logs"/*.log >/dev/null 2>&1; then
        printf '      (no log file - the bot has never logged anything)\n'
        any_log=0
    else
        any_log=1
        # file mtime is the honest filter here: the file name is a date, and
        # Logger only keeps the newest 10 of them anyway
        lines="$(find "$ROOT_DIR/data/logs" -maxdepth 1 -name '*.log' -mtime "-$days" \
                     -exec grep -hE '\] \[(ERROR|WARN|CRITICAL|FATAL)\]' {} + 2>/dev/null | tail -n 40 || true)"
        n="$(printf '%s\n' "$lines" | grep -c . 2>/dev/null || true)"
        n="${n:-0}"
        if [ "$n" -gt 0 ] 2>/dev/null; then
            printf '%s\n' "$lines" | sed 's/^/      /'
            total=$((total + n))
        else
            printf '      (nothing at WARN/ERROR level in the last %s days)\n' "$days"
        fi
    fi

    # ---- [2] web server error log ----
    printf '\n  [2] Web server errors\n'
    local found_ws=0
    for f in /var/log/apache2/error.log /var/log/nginx/error.log /var/log/httpd/error_log; do
        [ -r "$f" ] || continue
        found_ws=1
        any_log=1
        src="$(basename "$f")"
        lines="$(grep -aE '\[[a-z_0-9]+:(error|warn|crit|alert|emerg)\]|PHP (Fatal error|Parse error|Warning)' "$f" 2>/dev/null | tail -n 30 || true)"
        n="$(printf '%s\n' "$lines" | grep -c . 2>/dev/null || true)"
        n="${n:-0}"
        if [ "$n" -gt 0 ] 2>/dev/null; then
            printf '      %s:\n' "$src"
            printf '%s\n' "$lines" | sed 's/^/        /'
            total=$((total + n))
        else
            printf '      %s: clean\n' "$src"
        fi
    done
    [ "$found_ws" = "0" ] && printf '      (no readable web-server error log)\n'

    # ---- [3] deliveries Telegram made that came back wrong ----
    printf '\n  [3] Webhook requests that failed (access log)\n'
    local found_acc=0 acc_bad=0
    for f in /var/log/apache2/access.log /var/log/nginx/access.log; do
        [ -r "$f" ] || continue
        found_acc=1
        any_log=1
        src="$(basename "$f")"
        lines="$(grep -aE '(bot\.php|/bots/)[^"]*" (4|5)[0-9]{2}' "$f" 2>/dev/null | tail -n 30 || true)"
        n="$(printf '%s\n' "$lines" | grep -c . 2>/dev/null || true)"
        n="${n:-0}"
        if [ "$n" -gt 0 ] 2>/dev/null; then
            printf '      %s - Telegram reached us but we answered wrongly:\n' "$src"
            printf '%s\n' "$lines" | sed 's/^/        /'
            total=$((total + n))
            acc_bad=1
        else
            # A quiet access log is not evidence of health. Apache refuses the
            # request during the path walk - BEFORE any delivery line is
            # written - so a server that 403s every single call keeps an
            # immaculate access.log. Printing "every webhook delivery
            # answered 2xx/3xx" over an outage would make this the one
            # reassuring sentence in a report that exists to expose exactly
            # that outage, and error.log above would be contradicting it in
            # the same run. So ask the URL what it answers right now, and let
            # the silence explain itself when the answer is not a 2xx.
            local live_now live_base
            live_base="$(cfg_get base_url || true)"
            live_now="-"
            if [ -n "$live_base" ]; then
                live_now="$(wh_probe "${live_base%/}/bot.php" || true)"
                live_now="${live_now:-0}"   # never leave the status empty: 0 says "nothing answered"
            fi
            case "$live_now" in
                2*|3*)
                    printf '      %s: every webhook delivery answered 2xx/3xx (and it answers %s right now)\n' "$src" "$live_now"
                    ;;
                -)
                    printf '      %s: every webhook delivery answered 2xx/3xx\n' "$src"
                    ;;
                *)
                    printf '      %s: no failure is RECORDED, but %s/bot.php answers HTTP %s right now\n' "$src" "${live_base%/}" "$live_now"
                    printf '                Apache refuses the request before it logs one, so this log is\n'
                    printf '                silent by construction - the refusal is written in error.log,\n'
                    printf '                which is where the [FAIL] lines above came from.\n'
                    total=$((total + 1))
                    ;;
            esac
        fi
    done
    [ "$found_acc" = "0" ] && printf '      (no readable access log)\n'

    # "we answered wrongly" only means something next to what we answer NOW.
    # Certbot's own vhost serves /var/www/html until this project's vhost is
    # written, and in that window every Telegram delivery lands on it and
    # comes back 404 - so a run of real failures gets printed here long after
    # the project answers 200, sitting right beside a health check that says
    # exactly that. Without this line the reader has two contradictory
    # answers and nothing to tell which one is live.
    if [ "$acc_bad" = "1" ]; then
        local nowc ourb
        nowc="-"
        ourb="$(cfg_get base_url)"
        if [ -n "$ourb" ]; then
            nowc="$(wh_probe "${ourb%/}/bot.php" || true)"
        fi
        case "$nowc" in
            200|403) printf '      right now %s/bot.php answers HTTP %s -> the lines above are history\n' "${ourb%/}" "$nowc" ;;
            -)       printf '      (no base_url in config.php, so it cannot tell whether those still happen)\n' ;;
            *)       printf '      right now %s/bot.php answers HTTP %s -> NOT history, this is still failing\n' "${ourb%/}" "$nowc" ;;
        esac
    fi

    # ---- [4] PHP's own error_log ----
    printf '\n  [4] PHP error_log   (last 20 lines)\n'
    local pe
    pe="$("$PHP_BIN" -r 'echo (string)ini_get("error_log");' 2>/dev/null || true)"
    if [ -n "$pe" ] && [ -r "$pe" ] && [ -s "$pe" ]; then
        any_log=1
        lines="$(tail -n 20 "$pe")"
        printf '%s\n' "$lines" | sed 's/^/      /'
        n="$(printf '%s\n' "$lines" | grep -c . 2>/dev/null || true)"
        total=$((total + ${n:-0}))
    else
        printf '      (empty or unset)\n'
    fi

    # ---- [5] what Telegram itself saw ----
    printf '\n  [5] Telegram webhook status\n'
    local tok wi url pend edate errmsg whreason
    tok="$(cfg_get main_token)"
    if [ -z "$tok" ] || ! printf '%s' "$tok" | grep -Eq '^[0-9]+:[A-Za-z0-9_-]{30,}$'; then
        printf '      (no usable main_token in config.php)\n'
    else
        # The SAME probe as the health check above, so the two reports can
        # never disagree about whether Telegram answered - they used to run
        # two different HTTP stacks and did exactly that.
        wi="$(tg_status "$tok")"
        url="$(printf '%s\n' "$wi" | sed -n 's/^WHURL=//p')"
        pend="$(printf '%s\n' "$wi" | sed -n 's/^WHPENDING=//p')"
        edate="$(printf '%s\n' "$wi" | sed -n 's/^WHERRDATE=//p')"
        errmsg="$(printf '%s\n' "$wi" | sed -n 's/^WHERR=//p')"
        whreason="$(printf '%s\n' "$wi" | sed -n 's/^WH=ERR://p')"
        [ -n "$whreason" ] || whreason="$(printf '%s\n' "$wi" | sed -n 's/^ME=ERR://p')"
        if ! printf '%s\n' "$wi" | grep -q '^WHURL='; then
            # "did not answer" without a reason was undiagnosable; the probe
            # now returns WHY (timeout, TLS, HTTP status, Telegram's own text).
            printf '      could not read the webhook status: %s\n' "${whreason:-the probe printed nothing at all}"
        else
            any_log=1
            printf '      url     : %s\n' "${url:-(none)}"
            printf '      queued  : %s\n' "${pend:-0}"
            if [ -n "$errmsg" ]; then
                printf '      last err: %s\n' "$errmsg"
                [ -n "$edate" ] && printf '      at      : %s\n' "$(date -d "@$edate" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "$edate")"
                total=$((total + 1))
            else
                printf '      last err: none - Telegram has no complaint\n'
            fi
        fi
    fi

    # ---- summary ----
    printf '\n==========================================\n'
    if [ "$total" = "0" ]; then
        if [ "$any_log" = "0" ]; then
            printf '  ⚠️  No logs at all - this bot has never received an update.\n'
            printf '     Check the webhook with:  bash tools/install.sh --check\n'
        else
            printf '  ✅ No errors found in the last %s days.\n' "$days"
        fi
    else
        printf '  ⚠️  %s error/warning line(s) above - start with the [FAIL] items\n' "$total"
        printf '     from the health check, then re-read this report.\n'
    fi
    printf '==========================================\n'
    return 0
}

# ==================================================================
# MODES - the installer doubles as the post-install checker
#   bash tools/install.sh                 full install (ends with both reports)
#   bash tools/install.sh --check         read-only health check + error logs
#   bash tools/install.sh --logs          only the error logs
#   bash tools/install.sh --logs --days=3 only the last 3 days
# ==================================================================
MODE="install"
LOG_DAYS=7
for _arg in "$@"; do
    case "$_arg" in
        --check|check)        MODE="check" ;;
        --logs|--log|logs)    MODE="logs" ;;
        --days=*)             LOG_DAYS="${_arg#--days=}" ;;
        -h|--help)
            echo "Usage: bash tools/install.sh [--check | --logs [--days=N]]"
            echo ""
            echo "  (no option)   install / update everything"
            echo "  --check       verify every part of the system, change nothing"
            echo "  --logs        show every error the bot logged, change nothing"
            exit 0
            ;;
        *)
            echo "Unknown option: $_arg"
            echo "Usage: bash tools/install.sh [--check | --logs [--days=N]]"
            exit 2
            ;;
    esac
done
unset _arg

# ===== Auto-fix /root before any check or install =====
# The project lives under /root, so one question decides everything: can the
# account Apache runs as walk into it? Ask THAT, instead of comparing mode
# numbers - comparing numbers is how this ended up with a fix that fixed
# nothing. The old test wanted 711 or 755 while the command it ran was
# chmod 711: on 700 that yields 701, on 701 it changes nothing, so the target
# was unreachable and every --check reprinted the warning forever. 701 is a
# trap in the other direction too - it grants o+x, so namei shows a perfectly
# traversable chain, but it leaves the GROUP digit at 0, and an account that
# is a member of group root is refused by exactly the bits that look fine.
# So: test the account, repair what the test reports, then test again.
if [ "$(id -u)" -eq 0 ] && [ -d /root ]; then
    _wu="$(apache_run_user 2>/dev/null || true)"
    _wu="${_wu:-www-data}"
    _blk="$(webuser_blocker /root 2>/dev/null || true)"
    if [ -n "$_blk" ]; then
        _root_perm="$(stat -c '%a' /root 2>/dev/null || true)"
        if [ "$MODE" = "check" ]; then
            echo "   ⚠️  $_wu cannot search /root (mode ${_root_perm:-?}) - Apache answers 403 to everything."
            echo "      prove it first, then set whichever digit is actually missing:"
            echo "        sudo -u $_wu test -x /root && echo yes || echo no   # this prints 'no'"
            echo "        chmod 711 /root     # others lack x"
            echo "        chmod g+x /root     # $_wu is in group root and the group digit is 0"
            echo "        getfacl -p /root    # both already set -> it is not the mode at all"
        elif [ "$MODE" = "install" ]; then
            echo ""
            echo "========================================="
            echo "  🔧 Auto-fix: /root permissions"
            echo "========================================="
            chmod 711 /root
            if [ -z "$(webuser_blocker /root 2>/dev/null || true)" ]; then
                echo "   ✔ $_wu can search /root now - the 403s are over"
            else
                echo "   ⚠️  /root is now $(stat -c '%a' /root 2>/dev/null || echo '?') and $_wu is STILL refused."
                echo "      The mode bits are therefore not the cause. Look at what else denies it:"
                echo "        id $_wu      getfacl -p /root      sudo -u $_wu test -x /root && echo yes || echo no"
                echo "        cat /sys/module/apparmor/parameters/enabled"
            fi
            # ---- AppArmor fix: move project out of /root ----
            # If mode bits are fine but Apache still gets 403,
            # AppArmor is likely blocking Apache from /root.
            # Fix: move the project to /var/www/ where AppArmor allows access.
            if [ "$MODE" = "install" ] && command -v aa-status >/dev/null 2>&1; then
                if aa-status 2>/dev/null | grep -q 'apparmor module is loaded'; then
                    echo ""
                    echo "========================================="
                    echo "  🔧 AppArmor detected - moving project"
                    echo "========================================="
                    echo "   AppArmor restricts Apache from /root."
                    echo "   Moving project to /var/www/botsaz-faxima..."
                    if [ ! -d /var/www/botsaz-faxima ]; then
                        mkdir -p /var/www
                        cp -a /root/botsaz-faxima /var/www/
                        echo "   ✔ Copied to /var/www/botsaz-faxima"
                    else
                        echo "   ⚠️  /var/www/botsaz-faxima already exists"
                    fi
                    chown -R www-data:www-data /var/www/botsaz-faxima 2>/dev/null
                    echo "   ✔ chown www-data:www-data /var/www/botsaz-faxima"
                    # Update DocumentRoot in vhost
                    _vhost="/etc/apache2/sites-available/botsaz.conf"
                    if [ -f "$_vhost" ]; then
                        sed -i 's#/root/botsaz-faxima#/var/www/botsaz-faxima#g' "$_vhost"
                        echo "   ✔ Updated DocumentRoot in $_vhost"
                    fi
                    # CRITICAL: Update ROOT_DIR so all subsequent paths use /var/www
                    if [ "$ROOT_DIR" = "/root/botsaz-faxima" ] || [ "$ROOT_DIR" = "/root/botsaz-faxima" ]; then
                        ROOT_DIR="/var/www/botsaz-faxima"
                        echo "   ✔ ROOT_DIR updated to: $ROOT_DIR"
                    fi
                    echo "   ⚠️  Run: bash tools/install.sh --check"
                    echo "        to verify the move worked"
                fi
            fi
        fi
    fi
    unset _wu _blk _root_perm _try _before _after
fi

# ===== Auto-fix systemd sandbox (persistent drop-in) =====
# chmod on /root does nothing when the denial lives inside the service,
# not on disk.
# --check only reports (inside report_health); install mode writes it.
if [ "$MODE" = "install" ] && [ "$(id -u)" -eq 0 ]; then
    fix_apache_systemd_hardening apply || true
fi

if [ "$MODE" = "check" ] || [ "$MODE" = "logs" ]; then
    # A report must never be cut short by one failing probe, and it must never
    # ask a question - it is meant to be run on a server that is already down.
    set +e
    if [ "$MODE" = "check" ]; then
        report_health
        report_logs "$LOG_DAYS"
        if [ "$HC_FAIL" = "0" ]; then exit 0; else exit 1; fi
    fi
    report_logs "$LOG_DAYS"
    exit 0
fi

# ---------- 0) fresh server preflight ----------
# Think about a brand new Debian/Ubuntu box: no base tools, no web server, no
# database - and the steps below assume all three exist. So they go first, one
# question at a time. On an already-configured server every check here finds
# what it needs and this block changes nothing.
# Run SQL as the MySQL administrator.
# With an empty DB_ADMIN_PW this is a socket login as OS root, which works while
# the account is still auth_socket; once a password exists it is used instead.
mysql_root() {
    if [ -n "${DB_ADMIN_PW:-}" ]; then
        "$MYSQL_BIN" -uroot -h127.0.0.1 -p"$DB_ADMIN_PW" "$@"
    else
        $SUDO "$MYSQL_BIN" -uroot "$@"
    fi
}

# Can this MySQL account log in over TCP with this password?
# Kept separate from the privilege test on purpose: a freshly created account
# always logs in but has no GRANT yet, and conflating the two made the setup
# mistake "brand new" for "pre-exists with a different password".
app_db_login_ok() { # $1 = user, $2 = password
    "$MYSQL_BIN" -h127.0.0.1 -u"$1" -p"$2" -e "SELECT 1;" >/dev/null 2>&1
}

# ...and can it do the one thing every child bot needs: CREATE DATABASE.
# This is the final gate - only a green light here reaches the credentials step.
app_db_can_createdb() { # $1 = user, $2 = password
    local tmp="botsaz_pretest_$$"
    "$MYSQL_BIN" -h127.0.0.1 -u"$1" -p"$2" \
        -e "CREATE DATABASE \`$tmp\`; DROP DATABASE \`$tmp\`;" >/dev/null 2>&1
}

# Give the application its own MySQL account instead of depending on root.
# A fresh Ubuntu keeps root on auth_socket, which refuses exactly the TCP login
# the installer and every bot use - so a dedicated password-authenticated
# account is both simpler and testable. NOTHING is reported as done until a real
# TCP login WITH CREATE DATABASE succeeds, so a green line here really means the
# credentials step below will work.
setup_app_db_account() {
    local app_user="botsaz"
    local app_pw="" h=""
    # hex only: no quotes/backslashes, so it is safe in SQL and in argv
    app_pw="$(od -An -tx1 -N16 /dev/urandom | tr -d ' \n')"
    if [ -z "$app_pw" ]; then
        echo "   ❌ Could not generate a database password - skipping the account setup."
        return 0
    fi
    for h in localhost 127.0.0.1; do
        # both host spellings: with name resolution on, 127.0.0.1 becomes
        # 'localhost'; with skip_name_resolve only the literal IP matches
        mysql_root -e "CREATE USER IF NOT EXISTS '$app_user'@'$h' IDENTIFIED BY '$app_pw';" >/dev/null 2>&1 || true
    done
    # Step 1: does our password work at all? (login only - no GRANT has run yet)
    if ! app_db_login_ok "$app_user" "$app_pw"; then
        # it pre-existed with another password; only overwrite when nothing
        # depends on it yet, otherwise config.php would be left holding a
        # password that no longer works
        if [ -f "$ROOT_DIR/config.php" ]; then
            echo "   ✔ Account '$app_user' already exists with a different password - left untouched."
            return 0
        fi
        for h in localhost 127.0.0.1; do
            mysql_root -e "ALTER USER '$app_user'@'$h' IDENTIFIED BY '$app_pw';" >/dev/null 2>&1 || true
        done
        if ! app_db_login_ok "$app_user" "$app_pw"; then
            echo "   ❌ Could not create a working MySQL account."
            echo "      The credentials step below will ask you for the database details."
            return 0
        fi
    fi
    # Step 2: now grant, then prove the account can actually CREATE DATABASE
    for h in localhost 127.0.0.1; do
        mysql_root -e "GRANT ALL PRIVILEGES ON *.* TO '$app_user'@'$h';" >/dev/null 2>&1 || true
    done
    mysql_root -e "FLUSH PRIVILEGES;" >/dev/null 2>&1 || true

    if app_db_can_createdb "$app_user" "$app_pw"; then
        DB_PREFILL_USER="$app_user"
        DB_PREFILL_PASS="$app_pw"
        echo "   ✔ MySQL account '$app_user' created and VERIFIED over TCP (login + CREATE DATABASE)"
        echo "   ┌─────────────────────────────────────────────────────"
        echo "   │ Host      : 127.0.0.1"
        echo "   │ User      : $app_user"
        echo "   │ Password  : $app_pw"
        echo "   │ Privileges: ALL (one database is created per bot)"
        echo "   └─────────────────────────────────────────────────────"
        echo "   Save these - they are also written into config.php later."
    else
        echo "   ❌ Could not create a working MySQL account."
        echo "      The credentials step below will ask you for the database details."
    fi
}

preflight_fresh_server() {
    echo "✅ Fresh-server preflight (base packages, web server, database)..."

    if ! has_cmd apt-get; then
        echo "   ⚠️  apt-get not found - this installer targets Debian/Ubuntu."
        echo "      Install PHP 8.1+, a MySQL server and a web server yourself, then re-run."
        return 0
    fi
    if [ "$(id -u)" -ne 0 ] && ! has_cmd sudo; then
        echo "   ⚠️  Neither root nor sudo is available - nothing can be installed automatically."
        return 0
    fi

    # --- 1) base packages everything else depends on ---------------------
    local base_pkgs="ca-certificates curl wget git unzip gnupg lsb-release software-properties-common locales"
    local missing="" p
    for p in $base_pkgs; do
        dpkg -s "$p" >/dev/null 2>&1 || missing="$missing $p"
    done
    if [ -n "$missing" ]; then
        echo "   ⚠️  Missing base packages:$missing"
        if ask_yes "   Install them? (needed before anything else on a fresh server)"; then
            apt_install $missing || true
        fi
    fi

    # --- 2) web server ----------------------------------------------------
    local ws_apache=0 ws_nginx=0
    has_cmd apache2 && ws_apache=1
    has_cmd nginx && ws_nginx=1

    if [ "$ws_apache" -eq 0 ] && [ "$ws_nginx" -eq 0 ]; then
        echo "   ⚠️  No web server - Telegram cannot reach the webhook without one."
        local pick=""
        printf '   Install: [1] Apache (default)   [2] nginx   [3] none\n'
        read -r -p "   > " pick || true
        case "$pick" in
            2) PICKED="nginx" ;;
            3) PICKED="none" ;;
            *) PICKED="apache" ;;
        esac
    elif [ "$ws_nginx" -eq 1 ] && [ "$ws_apache" -eq 0 ]; then
        echo "   nginx is already installed."
        printf '   Switch to: [1] Apache   [2] keep nginx   [3] none\n'
        read -r -p "   > " pick || true
        case "$pick" in
            1) PICKED="apache" ;;
            3) PICKED="none" ;;
            *) PICKED="nginx" ;;
        esac
    elif [ "$ws_apache" -eq 1 ] && [ "$ws_nginx" -eq 0 ]; then
        echo "   Apache is already installed."
        printf '   Switch to: [1] nginx   [2] keep apache   [3] none\n'
        read -r -p "   > " pick || true
        case "$pick" in
            1) PICKED="nginx" ;;
            3) PICKED="none" ;;
            *) PICKED="apache" ;;
        esac
    else
        echo "   ⚠️  Both Apache AND nginx are installed! Port 80 conflict!"
        printf '   Choose one: [1] nginx   [2] apache   [3] none\n'
        read -r -p "   > " pick || true
        case "$pick" in
            1) PICKED="nginx" ;;
            2) PICKED="apache" ;;
            3) PICKED="none" ;;
            *) PICKED="nginx" ;;
        esac
    fi

    # --- Handle the chosen web server: install chosen, REMOVE other ---
    if [ "$PICKED" = "nginx" ]; then
        # Stop and remove Apache if it exists
        if [ "$ws_apache" -eq 1 ]; then
            warn "   Stopping and removing Apache (port conflict with nginx)..."
            $SUDO systemctl stop apache2 2>/dev/null || true
            $SUDO systemctl disable apache2 2>/dev/null || true
            $SUDO apt_remove apache2 apache2-bin apache2-data apache2-utils libapache2-mod-php8.5 libapache2-mod-php libapache2-mod-php8.5 2>/dev/null || true
            $SUDO apt_remove apache2 2>/dev/null || true
            ok "   Apache removed"
        fi
        # Install nginx if not present
        if [ "$ws_nginx" -eq 0 ]; then
            ok "   Installing nginx..."
            apt_install nginx || true
        fi
        $SUDO systemctl enable --now nginx 2>/dev/null || true
        ok "   nginx is ready"

    elif [ "$PICKED" = "apache" ]; then
        # Stop and remove nginx if it exists
        if [ "$ws_nginx" -eq 1 ]; then
            warn "   Stopping and removing nginx (port conflict with Apache)..."
            $SUDO systemctl stop nginx 2>/dev/null || true
            $SUDO systemctl disable nginx 2>/dev/null || true
            $SUDO apt_remove nginx nginx-common 2>/dev/null || true
            ok "   nginx removed"
        fi
        # Install Apache if not present
        if [ "$ws_apache" -eq 0 ]; then
            ok "   Installing Apache..."
            apt_install apache2 || true
        fi
        $SUDO systemctl enable --now apache2 2>/dev/null || true
        ok "   Apache is ready"

    else
        # none - stop and disable both
        warn "   No web server selected."
        if [ "$ws_apache" -eq 1 ]; then
            $SUDO systemctl stop apache2 2>/dev/null || true
            $SUDO systemctl disable apache2 2>/dev/null || true
            ok "   Apache stopped"
        fi
        if [ "$ws_nginx" -eq 1 ]; then
            $SUDO systemctl stop nginx 2>/dev/null || true
            $SUDO systemctl disable nginx 2>/dev/null || true
            ok "   nginx stopped"
        fi
        warn "   Telegram cannot reach the webhook without a web server!"
        warn "   You can add one later with: bash tools/install.sh"
    fi

    # --- 3) database ------------------------------------------------------
    local db_bin=""
    if has_cmd mysql; then db_bin="mysql"; elif has_cmd mariadb; then db_bin="mariadb"; fi
    if [ -z "$db_bin" ]; then
        echo "   ⚠️  No MySQL/MariaDB - nothing could store the bots' data."
        if ask_yes "   Install the MySQL server? (required)"; then
            apt_install mysql-server || apt_install mariadb-server || true
        fi
        if has_cmd mysql; then db_bin="mysql"; elif has_cmd mariadb; then db_bin="mariadb"; fi
    fi
    if [ -n "$db_bin" ]; then
        MYSQL_BIN="$db_bin"
        echo "   ⏳ Starting and enabling the database service..."
        $SUDO systemctl enable --now mysql  >/dev/null 2>&1 \
            || $SUDO systemctl enable --now mariadb >/dev/null 2>&1 \
            || $SUDO service mysql start >/dev/null 2>&1 \
            || $SUDO service mariadb start >/dev/null 2>&1 || true

        # Can we administer MySQL? OS root gets in free while the account is
        # still auth_socket; once a password exists (set by hand, or by an
        # earlier run) it is needed instead.
        DB_ADMIN_PW=""
        DB_ADMIN_SKIP=0
        if ! mysql_root -e "SELECT 1" >/dev/null 2>&1; then
            echo "   ⚠️  Cannot administer MySQL as OS root - a root password is already set."
            read -r -s -p "   MySQL root password (Enter to skip the account setup): " DB_ADMIN_PW || true
            echo ""
            if [ -z "$DB_ADMIN_PW" ] || ! mysql_root -e "SELECT 1" >/dev/null 2>&1; then
                echo "   ❌ No usable MySQL administrator - skipping the account setup."
                DB_ADMIN_PW=""
                DB_ADMIN_SKIP=1
            fi
        fi

        # Show the accounts BEFORE touching anything: this is what explains any
        # later "Access denied" - the plugin column says whether the account can
        # ever work over TCP.
        echo "   MySQL 'root' accounts (host -> auth plugin):"
        mysql_root -N -e "SELECT CONCAT('      ', host, ' -> ', plugin) FROM mysql.user WHERE user='root';" 2>/dev/null \
            || echo "      (could not read mysql.user)"

        if [ "$DB_ADMIN_SKIP" = "1" ]; then
            :   # already reported above
        elif [ -f "$ROOT_DIR/config.php" ]; then
            echo "   ✔ config.php already exists - MySQL accounts left untouched."
        else
            setup_app_db_account
        fi
    fi
}
preflight_fresh_server

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

# ---------- Fix: PCRE JIT memory allocation warning ----------
# Some Ubuntu/Debian configs block PCRE JIT memory allocation,
# causing "preg_match(): Allocation of JIT memory failed" warnings.
# Fix: set pcre.jit=0 in ALL PHP ini files (CLI + Apache).
# Apache may use a different .ini than CLI, so both must be updated.
_pcre_jit_ok=$($PHP_BIN -r 'echo ini_get("pcre.jit");' 2>/dev/null || echo "unknown")
if [ "$_pcre_jit_ok" != "0" ]; then
    # Find all PHP ini files: CLI + Apache modules
    _all_inis="$($PHP_BIN -r 'echo php_ini_loaded_file();' 2>/dev/null || echo '')"
    # Also check common Apache ini locations
    for _ap in /etc/php/*/apache2/php.ini /etc/php/*/apache2php.ini /etc/php/*/fpm/php.ini; do
        [ -f "$_ap" ] && _all_inis="$_all_inis $_ap"
    done
    # Deduplicate
    _unique_inis=$(echo "$_all_inis" | tr ' ' '\n' | sort -u | grep -v '^$')
    for _ini in $_unique_inis; do
        [ -z "$_ini" ] && continue
        if [ ! -f "$_ini" ]; then continue; fi
        if ! grep -q '^pcre.jit=' "$_ini" 2>/dev/null; then
            echo 'pcre.jit=0' >> "$_ini"
            echo "   ✔ Disabled PCRE JIT in $_ini"
        elif ! grep -q '^pcre.jit=0' "$_ini" 2>/dev/null; then
            # pcre.jit exists but is not 0 → change it
            sed -i "s/^pcre\.jit=.*/pcre.jit=0/" "$_ini"
            echo "   ✔ Changed pcre.jit to 0 in $_ini"
        else
            echo "   ✔ pcre.jit=0 already set in $_ini"
        fi
    done
fi

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

# ---------- 0) prerequisite: the PHP module for the chosen web server ----------
# The extensions above only help the CLI. Without the matching SAPI the web
# server hands .php files to the browser as plain text, so the webhook URL
# would return the source code (or a download) instead of answering Telegram.
echo "✅ Checking the PHP web-server module..."
# PICKED (from preflight) and the actually-active server outrank installed
# binaries here: offering libapache2-mod-php on an nginx box installs it AND
# drags apache2 back in as a dependency - re-enabled at boot, failing forever
# on nginx's busy port. That is exactly how a running nginx install ends up
# with a dead apache2 beside it.
if [ "${PICKED:-}" = "nginx" ] || { [ -z "${PICKED:-}" ] && ! has_cmd apache2 && ! has_cmd httpd && has_cmd nginx; }; then
    if dpkg -s php-fpm >/dev/null 2>&1; then
        echo "   nginx PHP-FPM ✔"
    elif ask_yes "   Install php-fpm so nginx can execute PHP?"; then
        if apt_install php-fpm; then
            # Enable whatever php-fpm version actually got installed - the
            # version differs per release (8.1/8.2/8.3/...), so a hardcoded
            # name enables nothing on most servers and PHP stays down.
            for _fpm_new in $(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
                $SUDO systemctl enable --now "$_fpm_new" 2>/dev/null || true
            done
            unset _fpm_new
            echo "   nginx PHP-FPM installed ✔"
        else
            echo "   ⚠️  Could not install php-fpm - nginx will not run PHP."
        fi
    fi
elif has_cmd apache2 || has_cmd httpd; then
    if dpkg -s libapache2-mod-php >/dev/null 2>&1; then
        echo "   Apache PHP module ✔"
    elif ask_yes "   Install libapache2-mod-php so Apache can execute PHP?"; then
        if apt_install libapache2-mod-php; then
            echo "   Apache PHP module installed ✔"
        else
            echo "   ⚠️  Could not install libapache2-mod-php - Apache will not run PHP."
        fi
    fi
    # The package install restarts Apache - if it did not come up, say why
    # instead of leaving a green line above a dead server. The usual cause is
    # the other web server holding :80 (apt re-enabled apache2 on install).
    if has_cmd systemctl && ! systemctl is-active --quiet apache2 2>/dev/null && ! systemctl is-active --quiet httpd 2>/dev/null; then
        _po80="$(port_owner 80)"
        echo "   ⚠️  Apache is installed but NOT running."
        [ -n "$_po80" ] && echo "      Port 80 is held by: $_po80"
        echo "      If nginx should serve this box: sudo systemctl stop apache2 && sudo systemctl disable apache2"
        echo "      If Apache should serve it: sudo systemctl stop nginx && sudo systemctl disable nginx && sudo systemctl restart apache2"
        unset _po80
    fi
elif has_cmd nginx; then
    if dpkg -s php-fpm >/dev/null 2>&1; then
        echo "   nginx PHP-FPM ✔"
    elif ask_yes "   Install php-fpm so nginx can execute PHP?"; then
        if apt_install php-fpm; then
            # Enable whatever php-fpm version actually got installed - the
            # version differs per release (8.1/8.2/8.3/...), so a hardcoded
            # name enables nothing on most servers and PHP stays down.
            for _fpm_new in $(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
                $SUDO systemctl enable --now "$_fpm_new" 2>/dev/null || true
            done
            unset _fpm_new
            echo "   nginx PHP-FPM installed ✔"
        else
            echo "   ⚠️  Could not install php-fpm - nginx will not run PHP."
        fi
    fi
else
    echo "   ⚠️  No Apache/nginx detected - skipping the PHP web-server module."
fi

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
# Same probe as every other Telegram question in this script (tg_status): the
# bot's own cURL stack, one retry, and the reason. A bare "did not answer"
# cannot tell a typo in the token from a firewall in front of api.telegram.org,
# and the reader is being asked to decide whether to continue anyway.
echo "   ⏳ Checking the token against Telegram..."
_tk="$(tg_status "$MAIN_TOKEN")"
if printf '%s\n' "$_tk" | grep -q '^ME=OK '; then
    echo "   🤖 @$(printf '%s\n' "$_tk" | sed -n 's/^ME=OK @//p') ✔"
else
    _why="$(printf '%s\n' "$_tk" | sed -n 's/^ME=ERR://p')"
    if [ -n "$_why" ]; then
        echo "   ⚠️  Telegram did not accept this token: $_why"
    else
        echo "   ⚠️  The token check printed nothing - php or curl failed locally."
    fi
    echo "       (a wrong token, no network, or a filter in front of api.telegram.org)"
    ask_yes "   Continue with this token anyway?" || exit 1
fi
unset _tk _why

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
# This used to only print a warning for a non-https address and carry on, so an
# install could finish "green" while Telegram could never deliver a single
# update (it later reports "Wrong response from the webhook: 404"). Every child
# bot's webhook, the certificate request and the vhost are all derived from this
# one value, so it has to be right before anything is built from it.
echo ""
echo "========================================="
echo "  🌐 Project base URL (base_url)"
echo "========================================="
printf '    Full public address of this project, e.g.\n'
printf '        https://example.com\n'
printf '    (no trailing slash; add a path only if you really serve it under one)\n'
while true; do
    read -r -p "    > " BASE_URL
    BASE_URL="$(printf '%s' "$BASE_URL" | sed 's:/*$::')"
    if [ -z "$BASE_URL" ]; then
        echo "   [X] The address is required - continue without it is not possible."
        continue
    fi
    if [ "$BASE_URL" = "${BASE_URL#https://}" ]; then
        if [ "$BASE_URL" != "${BASE_URL#http://}" ]; then
            # http:// is only tolerable on this machine itself, where no real
            # Telegram webhook is expected to work anyway (README: use ngrok)
            _hostpart="$(printf '%s' "$BASE_URL" | sed -E 's#^https?://##' | cut -d/ -f1 | cut -d: -f1)"
            case "$_hostpart" in
                localhost|127.0.0.1|::1)
                    echo "   ⚠️  Local address - a real Telegram webhook needs a public https domain."
                    break
                    ;;
            esac
            echo "   [X] http:// - Telegram rejects http webhooks, the bot would never run."
            echo "       Use https:// instead (the next step issues the certificate),"
            echo "       or on a local machine use http://localhost/..."
            continue
        fi
        _hostpart="$(printf '%s' "$BASE_URL" | sed -E 's#^[A-Za-z][A-Za-z0-9+.-]*://##' | cut -d/ -f1 | cut -d: -f1)"
        echo "   [X] Missing the scheme - write the whole address, starting with https://"
        echo "       example: https://${_hostpart}"
        continue
    fi
    break
done
echo "   ✔ $BASE_URL"

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
    local rc=0 _ssl_active=""
    _ssl_active="$(active_web_server)"
    # The plugin must match the server that ANSWERS port 80 (http-01 challenge),
    # not merely an installed binary - a dead apache2 next to a live nginx
    # means --apache can never complete the challenge.
    if [ "$_ssl_active" = "nginx" ] || { [ -z "$_ssl_active" ] && ! has_cmd apache2 && ! has_cmd httpd && has_cmd nginx; }; then
        certbot --nginx -d "$host" --non-interactive --agree-tos --register-unsafely-without-email || rc=$?
    elif has_cmd apache2 || has_cmd httpd; then
        certbot --apache -d "$host" --non-interactive --agree-tos --register-unsafely-without-email || rc=$?
    else
        certbot certonly --webroot -w "$ROOT_DIR" -d "$host" --non-interactive --agree-tos --register-unsafely-without-email || rc=$?
    fi
    unset _ssl_active

    if [ "$rc" = "0" ]; then
        echo "   🔒 Certificate issued for $host ✔"
        echo "      Renewal is automatic (certbot installs its own timer/cron)."
    else
        # Name the same plugin the attempt above really used - a hint that
        # always says --apache sends an nginx operator to a command that fails
        # (and the --check report already branches correctly, so the two would
        # disagree about the same server).
        local redo="--webroot -w $ROOT_DIR" _redo_active=""
        _redo_active="$(active_web_server)"
        if [ "$_redo_active" = "nginx" ] || { [ -z "$_redo_active" ] && ! has_cmd apache2 && ! has_cmd httpd && has_cmd nginx; }; then redo="--nginx"
        elif has_cmd apache2 || has_cmd httpd; then redo="--apache"
        fi
        unset _redo_active
        echo "   ⚠️  Could not issue the certificate (exit $rc)."
        echo "      Usual causes: DNS not pointing here, port 80 blocked, or a local machine."
        echo "      Re-run it later with: sudo certbot $redo -d $host"
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

# DocumentRoot reachability and ServerName conflicts are decided by the shared
# helpers at the top of this file (docroot_reachable / drop_vhost_conflicts), so
# that `install.sh --check` can ask exactly the same questions months later.

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

    # The RUNNING server wins over installed binaries: with a live nginx and
    # a dead-but-installed apache2, the Apache branch would write a vhost no
    # request ever reaches (nginx answers :80/:443 instead).
    local _vhost_active=""
    _vhost_active="$(active_web_server)"

    if [ "$_vhost_active" = "apache2" ] || { [ -z "$_vhost_active" ] && { has_cmd apache2 || has_cmd httpd; }; }; then
        unset _vhost_active
        local ap_conf="/etc/apache2/sites-available/botsaz.conf"
        # Check (and offer to repair) the path BEFORE the early return below.
        # A re-run finds the vhost already written, skips everything - and
        # that is precisely the state where the vhost is live while its
        # DocumentRoot is still closed, so every update comes back 403 and
        # nothing in the run would ever mention it again.
        docroot_reachable "$ROOT_DIR" apply || true

        # ...but a re-run must not be waved through blindly. If the domain
        # changed, or a certificate just arrived while no HTTPS block was ever
        # written, this very "vhost already exists" is what hides the breakage:
        # ServerName stays on the old name, and with no SSL vhost for port 443
        # Apache falls back to the first non-TLS vhost, so the client's
        # handshake is answered with plain text and Telegram reports exactly
        # this instead of an HTTP status:
        #   SSL error {error:0A0000C6:SSL routines::packet length too long}
        local cur_host="" has_443=0 need_write=0 why="" bak=""
        if [ -f "$ap_conf" ]; then
            cur_host="$(sed -n 's/^[[:space:]]*ServerName[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' "$ap_conf" | head -n1)"
            if grep -qE '^[[:space:]]*<VirtualHost[^>]*:443' "$ap_conf"; then has_443=1; fi
            if [ "$cur_host" != "$host" ]; then
                need_write=1
                why="it serves '${cur_host:-no ServerName}' but base_url is '$host'"
            elif [ "$cert_ok" = "1" ] && [ "$has_443" = "0" ]; then
                need_write=1
                why="it has no HTTPS block although a certificate for $host exists"
            elif [ "$cert_ok" = "1" ] && ! grep -q "letsencrypt/live/$host/" "$ap_conf"; then
                need_write=1
                why="its certificate belongs to another domain, not $host"
            fi
        fi
        if [ -f "$ap_conf" ] && [ "$need_write" = "0" ]; then
            echo "   ✔ Apache vhost already exists ($ap_conf) and matches $host - left untouched."
            return 0
        fi
        if [ "$need_write" = "1" ]; then
            echo "   ⚠️  the existing vhost has to be updated: $why"
            if ! ask_yes "   Rewrite $ap_conf (a backup is taken first)?"; then
                echo "   ⚠️  vhost left as it is - it will keep answering '${cur_host:-?}', not $host."
                return 0
            fi
            bak="$ap_conf.bak.$(date +%Y%m%d%H%M%S)"
            if $SUDO cp -a "$ap_conf" "$bak" 2>/dev/null; then
                echo "   backup: $bak"
            fi
        elif ! ask_yes "   Create the Apache vhost for $host -> $ROOT_DIR ?"; then
            echo "   vhost skipped"
            return 0
        fi
        # a vhost pointing at an unreachable directory is worse than none:
        # Apache starts, reports success, and 403s every request
        if [ "$DOCROOT_OK" != "1" ]; then
            echo "   ⚠️  Writing the vhost anyway - it cannot answer until the path above is fixed."
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
            # rewrite powers .htaccess, ssl powers :443. Never swallow the
            # output: a silently failing a2enmod is what leaves a "green"
            # install with .htaccess protection turned off.
            aem_out=""
            if aem_out="$($SUDO a2enmod ssl rewrite headers 2>&1)"; then
                echo "   ✔ Apache modules enabled: ssl, rewrite, headers"
            else
                echo "   ⚠️  a2enmod could not enable ssl/rewrite/headers:"
                printf '%s\n' "$aem_out" | sed -n '1,3p'
                echo "      Enable them by hand: sudo a2enmod rewrite ssl headers"
            fi
        fi
        if has_cmd a2ensite; then $SUDO a2ensite botsaz >/dev/null 2>&1 || true; fi
        # before testing/reloading: any duplicate ServerName must go away now,
        # otherwise Apache keeps answering from the other DocumentRoot
        drop_vhost_conflicts "$host" "$ap_conf" apply
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

    if [ "$_vhost_active" = "nginx" ] || { [ -z "$_vhost_active" ] && has_cmd nginx; }; then
        unset _vhost_active
        local ng_conf="/etc/nginx/sites-available/botsaz.conf"
        # Like the Apache branch: repair the path BEFORE any early return, and
        # never wave a stale vhost through blindly. In particular a re-run
        # after the certificate arrived must ADD the :443 block - the old code
        # returned here and left plain HTTP forever, so Telegram kept failing
        # with nothing in the run explaining why.
        docroot_reachable "$ROOT_DIR" apply || true
        local cur_host="" has_443=0 need_write=0 why="" bak=""
        if [ -f "$ng_conf" ]; then
            cur_host="$(sed -n 's/^[[:space:]]*server_name[[:space:]]\{1,\}\([^;[:space:]]*\).*/\1/p' "$ng_conf" | head -n1)"
            if grep -qE 'listen[[:space:]]+(\[::\]:)?443 ssl' "$ng_conf"; then has_443=1; fi
            if [ "$cur_host" != "$host" ]; then
                need_write=1
                why="it serves '${cur_host:-no server_name}' but base_url is '$host'"
            elif [ "$cert_ok" = "1" ] && [ "$has_443" = "0" ]; then
                need_write=1
                why="it has no HTTPS block although a certificate for $host exists"
            elif [ "$cert_ok" = "1" ] && ! grep -q "letsencrypt/live/$host/" "$ng_conf"; then
                need_write=1
                why="its certificate belongs to another domain, not $host"
            fi
        fi
        if [ -f "$ng_conf" ] && [ "$need_write" = "0" ]; then
            echo "   ✔ nginx vhost already exists ($ng_conf) and matches $host - left untouched."
            return 0
        fi
        if [ "$need_write" = "1" ]; then
            echo "   ⚠️  the existing vhost has to be updated: $why"
            if ! ask_yes "   Rewrite $ng_conf (a backup is taken first)?"; then
                echo "   ⚠️  vhost left as it is - it will keep answering '${cur_host:-?}', not $host."
                return 0
            fi
            bak="$ng_conf.bak.$(date +%Y%m%d%H%M%S)"
            if $SUDO cp -a "$ng_conf" "$bak" 2>/dev/null; then
                echo "   backup: $bak"
            fi
        elif ! ask_yes "   Create the nginx vhost for $host -> $ROOT_DIR ?"; then
            echo "   vhost skipped"
            return 0
        fi
        if [ "$DOCROOT_OK" != "1" ]; then
            echo "   ⚠️  Writing the vhost anyway - it cannot answer until the path above is fixed."
        fi
        if [ -z "$fpm_sock" ]; then
            echo "   ⚠️  No PHP-FPM socket under /run/php - install php-fpm or PHP will not execute."
        fi
        $SUDO mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled 2>/dev/null || true

        # the application block is reused for :80 (no cert yet) and :443 (cert present)
        # Include mime.types for correct MIME types on static files
        _nginx_app() {
            echo "    include /etc/nginx/mime.types;"
            echo "    default_type application/octet-stream;"
            echo "    root \"$ROOT_DIR\";"
            echo "    index index.php index.html;"
            echo "    client_max_body_size 64m;"
            echo "    autoindex off;"
            echo "    location / { try_files \$uri \$uri/ /index.php?\$query_string; }"
            # Block sensitive directories and files (equivalent of .htaccess rules)
            echo "    location ~ ^/(tools|src|templates|data|docs)/ { deny all; return 404; }"
            echo "    location ~ ^/config\.php$ { deny all; return 404; }"
            # .htaccess blocks these by NAME (<FilesMatch
            # "^(\.env|\.git|README|composer\.(json|lock)|config\.example\.php)">).
            # The dotfile rule below covers the first two; without these nginx
            # would hand out the README and the composer files, and would EXECUTE
            # config.example.php - which lays out every database column.
            echo "    location ~ ^/(README[^/]*|composer\.(json|lock)|config\.example\.php)\$ { deny all; return 404; }"
            # Let's Encrypt serves the http-01 challenge out of
            # /.well-known/acme-challenge/ and re-checks it on EVERY renewal.
            # .htaccess allows that path (it only blocks .git), so this rule has
            # to sit before the dotfile rule - otherwise the first certificate is
            # issued while no vhost blocks it yet, and every renewal 90 days later
            # returns 404. Apache renews, nginx does not, and nothing in the logs
            # connects the two.
            echo "    location ~ ^/\.well-known/ { }"
            echo "    location ~ /\. { deny all; return 404; }"
            # bots/ entry points (index.php, table.php, cron/*.php) must stay accessible"
            echo "    location ~ ^/bots/.*\.(env|json|log|sqlite|sql|bak|txt|lock)$ { deny all; return 404; }"
            echo "    location ~ ^/bots/(hash\.txt|info|error_log)$ { deny all; return 404; }"
            echo "    location ~ ^/bots/.*config\.php$ { deny all; return 404; }"
            if [ -n "$fpm_sock" ]; then
                echo "    location ~ \.php\$ {"
                echo "        try_files \$uri =404;"
                echo "        include snippets/fastcgi-php.conf;"
                echo "        fastcgi_pass unix:$fpm_sock;"
                echo "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;"
                echo "        fastcgi_index index.php;"
                echo "    }"
            fi
            echo "    location ~ /\.ht { deny all; return 404; }"
        }
        # SSL settings for the HTTPS server block
        _nginx_ssl_settings() {
            echo "    ssl_protocols TLSv1.2 TLSv1.3;"
            echo "    ssl_ciphers HIGH:!aNULL:!MD5;"
            echo "    ssl_prefer_server_ciphers on;"
            echo "    ssl_session_cache shared:SSL:10m;"
            echo "    ssl_session_timeout 10m;"
        }
        # NO `default_server` on any listen line. Ubuntu's stock
        # /etc/nginx/sites-enabled/default already claims it, and two
        # default_server on one address make nginx refuse to load ANY config:
        #   nginx: [emerg] a duplicate listen 80 default_server
        # `nginx -t` then fails and the rollback below deletes the vhost we
        # just wrote - an install would end with a warning and no site at all.
        # It buys nothing here either: Telegram addresses us by Host header,
        # which `server_name` already matches.
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
                _nginx_ssl_settings
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
            # Start nginx if not running, reload if running
            if ! systemctl is-active --quiet nginx 2>/dev/null; then
                $SUDO systemctl start nginx 2>/dev/null || $SUDO service nginx start 2>/dev/null || true
                echo "   ✔ nginx started"
            else
                $SUDO systemctl reload nginx >/dev/null 2>&1 || $SUDO service nginx reload >/dev/null 2>&1 || true
                echo "   ✔ nginx vhost installed and reloaded"
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
# If the fresh-server preflight created a verified MySQL account, both halves
# are handed over here: pressing Enter reuses them, so nothing is typed twice.
DB_USER_DEF="${DB_PREFILL_USER:-root}"
DB_PW_HINT=""
if [ -n "${DB_PREFILL_PASS:-}" ]; then
    DB_PW_HINT=" [press Enter to reuse the one set during server setup]"
fi
while true; do
    read -r -p "    DB Host [127.0.0.1]: " DB_HOST
    DB_HOST=${DB_HOST:-127.0.0.1}
    read -r -p "    DB Port [3306]: " DB_PORT
    DB_PORT=${DB_PORT:-3306}
    read -r -p "    DB User [$DB_USER_DEF]: " DB_USER
    DB_USER=${DB_USER:-$DB_USER_DEF}
    read -r -s -p "    DB Password${DB_PW_HINT}: " DB_PASS
    DB_PASS="${DB_PASS:-${DB_PREFILL_PASS:-}}"
    echo ""
    read -r -p "    DB Prefix [botsaz_]: " DB_PREFIX
    DB_PREFIX=${DB_PREFIX:-botsaz_}
    if ! [[ "$DB_PORT" =~ ^[0-9]+$ ]]; then DB_PORT=3306; fi

    echo "   ⏳ Testing connection and CREATE DATABASE permission..."
    DB_TEST_OUT=""
    if DB_TEST_OUT="$(DBH="$DB_HOST" DBP="$DB_PORT" DBU="$DB_USER" DBPW="$DB_PASS" $PHP_BIN -r '
try {
    $pdo = new PDO("mysql:host=".getenv("DBH").";port=".(int)getenv("DBP").";charset=utf8mb4",
        getenv("DBU"), getenv("DBPW"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
    $tmp = "botsaz_installtest_" . bin2hex(random_bytes(3));
    $pdo->exec("CREATE DATABASE `$tmp` CHARACTER SET utf8mb4");
    $pdo->exec("DROP DATABASE `$tmp`");
    echo "OK";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }' 2>&1)"; then
        echo "   Connection + CREATE DATABASE permission ✔"
        break
    fi
    [ -n "$DB_TEST_OUT" ] && printf '%s\n' "$DB_TEST_OUT"
    DB_PW_STATE="EMPTY"
    if [ -n "$DB_PASS" ]; then DB_PW_STATE="supplied"; fi
    echo "   ❌ Connection or database permission failed."
    echo "      Tried: host=$DB_HOST:$DB_PORT  user=$DB_USER  password sent: $DB_PW_STATE"
    # Turn the raw SQLSTATE into an actual cause - the codes differ a lot and
    # only one of them tells you to change the password.
    case "$DB_TEST_OUT" in
        *"[1698]"*)
            echo "      1698 = this account still uses socket auth (auth_socket);"
            echo "             it can NEVER log in over TCP, no matter the password."
            echo "             The account list printed during server setup shows this."
            ;;
        *"[1045]"*)
            echo "      1045 = wrong user name or wrong password."
            ;;
        *"2002"*|*"2003"*)
            echo "      2002/2003 = nothing is listening on $DB_HOST:$DB_PORT - is MySQL running?"
            ;;
        *"[1044]"*)
            echo "      1044 = logged in, but this user is not allowed to CREATE DATABASE."
            ;;
        *"[1049]"*)
            echo "      1049 = unknown database name."
            ;;
    esac
    if [ "$DB_PW_STATE" = "EMPTY" ] && [ -n "${DB_PREFILL_PASS:-}" ]; then
        echo "      The password came out EMPTY although a verified one is available."
        echo "      Press Enter at the password prompt to use it, or type it."
    fi
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
    "db_backup" => array("enabled" => true, "times" => array("03:00", "15:00")),
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

# A re-run with a DIFFERENT address gets stuck right here: the base_url prompt
# takes the new domain, but because we never overwrite config.php it stays on
# the old one - while the webhook, the certificate request and the vhost are
# all built from what was just typed. Telegram then delivers to one name and
# the health check probes another, and neither of them answers. Only this
# single key moves; the database, the secret and any other manual edit in the
# file stay byte-for-byte where they are.
old_base="$(cfg_get base_url)"
if [ -n "$old_base" ] && [ "$old_base" != "$BASE_URL" ]; then
    echo ""
    echo "⚠️  config.php says base_url = $old_base"
    echo "      but you just entered       = $BASE_URL"
    if ask_yes "   Update base_url inside config.php to $BASE_URL too?"; then
        if CFG_FILE="$ROOT_DIR/config.php" NEW_BASE="$BASE_URL" "$PHP_BIN" -r '
$f = (string) getenv("CFG_FILE");
$raw = @file_get_contents($f);
if ($raw === false) { fwrite(STDERR, "config.php read failed\n"); exit(1); }
$new = (string) getenv("NEW_BASE");
$n = 0;
$out = preg_replace_callback(
    "/^([ \t]*)(?:\x27base_url\x27|\x22base_url\x22)[ \t]*=>[ \t]*.*$/m",
    function ($m) use ($new) {
        return $m[1] . "\x27base_url\x27 => " . var_export($new, true) . ",";
    },
    $raw, 1, $n
);
if ($out === null || $n !== 1) { fwrite(STDERR, "base_url not found in config.php\n"); exit(1); }
if (file_put_contents($f, $out) === false) { fwrite(STDERR, "config.php write failed\n"); exit(1); }
'; then
            echo "   base_url updated inside config.php ✔"
        else
            echo "   ❌ could not change base_url - edit it by hand in config.php."
            echo "      Until then everything else stays on $old_base."
        fi
    fi
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
# The webhook has to come from the same place the health check compares it
# against: config.php. It used to be built from the just-typed $BASE_URL, so
# when config could not be updated Telegram was pointed at one name and the
# checker at another - with nothing printed to say so.
_cfg_base="$(cfg_get base_url)"
if [ -n "$_cfg_base" ] && [ "$_cfg_base" != "$BASE_URL" ]; then
    echo "⚠️  config.php is on $_cfg_base; the webhook will be built from that."
    echo "      To move it, change base_url in config.php and run:"
    echo "      php tools/set_webhook.php"
    BASE_URL="$_cfg_base"
fi
WEBHOOK_URL="${BASE_URL}/bot.php"
$PHP_BIN "$ROOT_DIR/tools/set_webhook.php" "$WEBHOOK_URL" || echo "⚠️  Webhook not set (the URL is probably not https/public). Set it manually later."

# ===== FIX: data/ ownership and Apache restart =====
# If the project is under /root, Apache (www-data) needs write access to data/ and bots/
# and the vhost needs to be properly configured.
if [ "$(id -u)" -eq 0 ] && [ -d "$ROOT_DIR/data" ]; then
    if [ "$(stat -c '%U:%G' "$ROOT_DIR/data")" != "www-data:www-data" ]; then
        chown -R www-data:www-data "$ROOT_DIR/data" 2>/dev/null && \
            echo "   ✔ chown -R www-data:www-data $ROOT_DIR/data" || \
            echo "   ⚠️  Could not chown data/ - run manually"
    fi
fi
# bots/ directory needs write access for child bot creation
if [ -d "$ROOT_DIR/bots" ]; then
    if [ "$(stat -c '%U:%G' "$ROOT_DIR/bots")" != "www-data:www-data" ]; then
        chown www-data:www-data "$ROOT_DIR/bots" 2>/dev/null && \
            echo "   ✔ chown www-data:www-data $ROOT_DIR/bots" || \
            echo "   ⚠️  Could not chown bots/ - run manually"
    fi
fi
# Ensure Apache is running with the correct config
# NOTE: if the project lives under /root and a systemd drop-in was just
# written above, the restart below is what applies that new sandbox.
fix_apache_systemd_hardening apply || true
if has_cmd systemctl && systemctl is-active --quiet apache2 2>/dev/null; then
    systemctl restart apache2 2>/dev/null && echo "   ✔ Apache restarted" || \
        echo "   ⚠️  Apache restart failed - reload manually"
fi
# nginx path: a new vhost needs (at most) a reload, but php-fpm needs a real
# restart - both for a freshly written systemd drop-in AND for php.ini changes
# (pcre.jit=0 above is not picked up by running workers otherwise).
if has_cmd nginx; then
    if $SUDO systemctl reload nginx 2>/dev/null || $SUDO service nginx reload 2>/dev/null; then
        echo "   ✔ nginx reloaded"
    else
        echo "   ⚠️  nginx reload failed - reload manually"
    fi
    for _fpm in $(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$' || true); do
        if $SUDO systemctl restart "$_fpm" 2>/dev/null; then
            echo "   ✔ $_fpm restarted"
        fi
    done
    unset _fpm
fi

# ===== Post-restart: prove the server answers, then re-register the webhook ==
# Why this exists: step 8 called set_webhook BEFORE fix_apache_systemd_hardening
# and the restart below, so it registered the URL against an Apache that could
# still be sandboxed. Every delivery Telegram attempts in that window gets a
# 403, Telegram "gives up after a few attempts", and the webhook is then
# registered but dead - /start stays silent no matter what the user does.
# Re-registering only after the server has actually answered 200 is what makes
# the next delivery succeed.
#
# Opening the site in a browser - what used to make the bot come alive - never
# reaches Telegram at all. It only looked like the fix because a retry happened
# to land in the same minute; nothing about visiting / re-registers anything.
# Registering here means the user never has to.
#
# Probe repeatedly instead of sleeping a fixed amount: systemctl restart
# returns as soon as the unit is ACTIVE, which can still precede the first
# accepted connection. The `[ ] || [ ]` test stays inside `if` on purpose -
# this script runs with `set -e` and a bare AND-OR list would abort it.
_warm="0"
for _i in 1 2 3 4 5 6; do
    _warm="$(wh_probe "${BASE_URL%/}/bot.php" || true)"
    if [ "$_warm" = "200" ] || [ "$_warm" = "403" ]; then break; fi
    sleep 1
done
_ws_label="Apache"
if ! has_cmd apache2 && ! has_cmd httpd && has_cmd nginx; then _ws_label="nginx/php-fpm"; fi
if [ "$_warm" = "200" ] || [ "$_warm" = "403" ]; then
    echo "   ✔ $_ws_label answers HTTP $_warm on ${BASE_URL%/}/bot.php after restart"
    if $PHP_BIN "$ROOT_DIR/tools/set_webhook.php" "${BASE_URL%/}/bot.php" 2>/dev/null; then
        echo "   ✔ Webhook re-confirmed against the live server"
    else
        echo "   ⚠️  Webhook re-confirm failed - run: php tools/set_webhook.php"
    fi
else
    echo "   ⚠️  bot.php answered HTTP ${_warm:-0} on all 6 tries - fix the vhost before trusting Telegram"
fi
unset _warm _i

# 9) verify EVERYTHING and show every error the bot has produced
# These are the same two reports `bash tools/install.sh --check` prints later, so
# what you read here is exactly what you can re-run at any moment afterwards.
report_health
report_logs 7

# ===== Install crontab entries =====
# This is essential for child bots to work (cron_dispatcher runs every 5 min)
# and for daily database backups.
setup_crontab() {
    echo ""
    echo "========================================="
    echo "  ⏰ Setting up crontab..."
    echo "========================================="
    
    has_cmd crontab || { echo "   ⚠️  crontab not found - skipping"; return 0; }
    
    # Use the PHP binary path from config.php if available, else 'php'
    local php_cmd="php"
    if [ -f "$ROOT_DIR/config.php" ]; then
        local cfg_php="$($PHP_BIN -r "require '$ROOT_DIR/config.php'; echo isset(\$config['php_bin']) ? \$config['php_bin'] : 'php';" 2>/dev/null)"
        [ -n "$cfg_php" ] && [ "$cfg_php" != "php" ] && php_cmd="$cfg_php"
    fi
    
    # Build the crontab content
    local cron_content=""
    local cron_line=""
    
    # Child bot cron dispatcher (every 5 minutes) - ESSENTIAL for bot functionality
    cron_line="*/5 * * * * cd $ROOT_DIR && $php_cmd tools/cron_dispatcher.php >/dev/null 2>&1"
    echo "   ➕ Adding: $cron_line"
    cron_content="${cron_content}${cron_line}\n"
    
    # Database backup (daily at 3:15 and 15:15) - if backup_dispatcher exists
    if [ -f "$ROOT_DIR/tools/backup_dispatcher.php" ]; then
        cron_line="0 3,15 * * * cd $ROOT_DIR && $php_cmd tools/backup_dispatcher.php >/dev/null 2>&1"
        echo "   ➕ Adding: $cron_line"
        cron_content="${cron_content}${cron_line}\n"
    fi
    
    # Weekly update check (Sundays at 6 AM) - optional
    cron_line="0 6 * * 0 cd $ROOT_DIR && bash tools/update.sh >/dev/null 2>&1"
    echo "   ➕ Adding: $cron_line"
    cron_content="${cron_content}${cron_line}\n"
    
    # Write to crontab - preserve existing entries
    local existing_crontab=""
    existing_crontab=$($SUDO crontab -l 2>/dev/null || true)
    
    # Remove old botsaz entries first (avoid duplicates)
    local new_crontab="$existing_crontab"
    if [ -n "$existing_crontab" ]; then
        new_crontab=$(echo -e "$existing_crontab" | grep -v 'botsaz-faxima' | grep -v '^$' || true)
    fi
    
    # Add our entries
    if [ -n "$new_crontab" ]; then
        printf '%s\n%s' "$new_crontab" "$cron_content" | $SUDO crontab - 2>/dev/null || true
    else
        echo -e "$cron_content" | $SUDO crontab - 2>/dev/null || true
    fi
    
    # Verify
    local installed=$($SUDO crontab -l 2>/dev/null | grep -c 'botsaz-faxima' || true)
    if [ "$installed" -gt 0 ]; then
        ok "   Crontab installed! ($installed entries)"
        echo "      Run: $SUDO crontab -l"
    else
        warn "   Could not install crontab - add manually:"
        echo "      crontab -e"
        echo "      Then add:"
        echo "      */5 * * * * cd $ROOT_DIR && $php_cmd tools/cron_dispatcher.php"
    fi
}

# Set up crontab before the final messages
setup_crontab

echo ""
echo "========================================="
if [ "$HC_FAIL" = "0" ] && [ "$bot_up" = "1" ]; then
    echo "  ✅ Install finished and the bot is running!"
else
    echo "  ⚠️  Install finished - now fix the [FAIL] lines printed above."
    [ "$bot_up" != "1" ] && echo "     - The main bot was not verified. Once config.php is correct run:"
    [ "$bot_up" != "1" ] && echo "       php tools/set_webhook.php ${BASE_URL}/bot.php"
    [ "$HC_FAIL" != "0" ] && echo "     - Repeat this whole report at any time with:"
    [ "$HC_FAIL" != "0" ] && echo "       bash tools/install.sh --check"
fi
echo "========================================="
echo ""
echo "📌 How to use it:"
echo "   1. Open the main bot in Telegram and press /start"
echo "   2. Your ID was registered as the super admin"
echo "   3. Per user: request access first, then approve it as admin"
echo "   4. Child cron: */5 * * * * php $ROOT_DIR/tools/cron_dispatcher.php"
echo "========================================="
