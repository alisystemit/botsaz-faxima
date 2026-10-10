#!/bin/bash
# Debug Script - بررسی تمام فایل‌های جدید

cd "$(dirname "$0")/.."

echo ""
echo "╔════════════════════════════════════════════════════════════════════════════════╗"
echo "║ 🔍 DEBUG COMPLETE - تست تمام 10 فایل جدید                                      ║"
echo "╚════════════════════════════════════════════════════════════════════════════════╝"
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

FILES=(
  "UiPremium.php"
  "Theme.php"
  "RateLimiter.php"
  "AuditLog.php"
  "WebhookManager.php"
  "i18n.php"
  "Analytics.php"
  "PluginManager.php"
  "CacheManager.php"
  "Monitor.php"
)

echo "📋 STEP 1: بررسی وجود فایل‌ها"
echo "─────────────────────────────────────────────────────────────────────────────────"

missing=0
for file in "${FILES[@]}"; do
  path="src/$file"
  if [ -f "$path" ]; then
    size=$(du -h "$path" | cut -f1)
    echo -e "${GREEN}✅${NC} $file ($size)"
  else
    echo -e "${RED}❌${NC} $file (موجود نیست)"
    missing=$((missing + 1))
  fi
done

echo ""
echo "🔧 STEP 2: بررسی Syntax PHP"
echo "─────────────────────────────────────────────────────────────────────────────────"

syntax_errors=0
for file in "${FILES[@]}"; do
  path="src/$file"
  if [ -f "$path" ]; then
    output=$(php -l "$path" 2>&1)
    if echo "$output" | grep -q "No syntax errors\|parsed successfully"; then
      echo -e "${GREEN}✅${NC} $file"
    else
      echo -e "${RED}❌${NC} $file"
      echo "   $output"
      syntax_errors=$((syntax_errors + 1))
    fi
  fi
done

echo ""
echo "🏗️ STEP 3: بررسی Class Definitions"
echo "─────────────────────────────────────────────────────────────────────────────────"

declare -A classes=(
  ["UiPremium.php"]="UiPremium"
  ["Theme.php"]="Theme"
  ["RateLimiter.php"]="RateLimiter"
  ["AuditLog.php"]="AuditLog"
  ["WebhookManager.php"]="WebhookManager"
  ["i18n.php"]="i18n"
  ["Analytics.php"]="Analytics"
  ["PluginManager.php"]="PluginManager"
  ["CacheManager.php"]="CacheManager"
  ["Monitor.php"]="Monitor"
)

class_errors=0
for file in "${!classes[@]}"; do
  path="src/$file"
  classname="${classes[$file]}"
  if [ -f "$path" ]; then
    if grep -q "class $classname" "$path"; then
      methods=$(grep -c "public.*function" "$path" || true)
      echo -e "${GREEN}✅${NC} $classname ($methods methods)"
    else
      echo -e "${RED}❌${NC} $classname (Class not found)"
      class_errors=$((class_errors + 1))
    fi
  fi
done

echo ""
echo "📊 Summary"
echo "─────────────────────────────────────────────────────────────────────────────────"
echo "فایل‌های موجود:   $((${#FILES[@]} - missing)) / ${#FILES[@]}"
echo "خطاهای Syntax:   $syntax_errors"
echo "خطاهای Class:    $class_errors"

total_errors=$((missing + syntax_errors + class_errors))

echo ""
if [ $total_errors -eq 0 ]; then
  echo -e "${GREEN}✅ تمام تست‌ها موفق بودند!${NC}"
  exit 0
else
  echo -e "${RED}❌ $total_errors خطا شناسایی شد${NC}"
  exit 1
fi
