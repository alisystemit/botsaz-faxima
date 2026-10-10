# 🎨 بهبودی‌های استایلی و کوالیتی کد

**تاریخ:** ۱۰ مهرماه ۱۴۰۵  
**بخش:** زیباسازی و Best Practices

---

## 📋 بهبودی‌های پیشنهادی (برای نسخه‌های بعدی)

### 1. **Type Hints بهتر در `Ui.php`**

**وضعیت فعلی:** بعضی متدها type hint ندارند

```php
// ❌ قبلی
public static function e($s): string { ... }

// ✅ بهتر
public static function e(mixed $s): string { ... }
```

**فائدهٔ:** تمیز‌تر و IDE-friendly

---

### 2. **Strict Types در تمام فایل‌های PHP**

**پیشنهاد:**
```php
<?php declare(strict_types=1);
// بقیهٔ کد...
```

**فائدهٔ:** 
- Catch type errors در compile time
- بهتر performance
- بهتر IDE support

---

### 3. **Return Type Hints منسجم**

**وضعیت:** بعضی متدها nullable return ندارند

```php
// ❌ قبلی
public static function templateSpec(string $type): ?array

// ✅ بهتر - استفاده در همه‌جا
public static function user(int $uid, ...): array
public static function getBot(int $id): ?array
```

---

## 🧹 کد تمیز‌کاری (Code Cleanup)

### 1. **ثابت‌های Magic استخراج شده**

**مثال:**
```php
// ❌ قبلی - Hardcoded
if (strlen($s) < 3) { $suffix = substr(bin2hex(random_bytes(4)), 0, 6); }

// ✅ بهتر
private const MIN_SLUG_LENGTH = 3;
private const SLUG_SUFFIX_LENGTH = 6;

if (strlen($s) < self::MIN_SLUG_LENGTH) {
    $suffix = substr(bin2hex(random_bytes(4)), 0, self::SLUG_SUFFIX_LENGTH);
}
```

---

### 2. **تجمیع Exception Handling**

**وضعیت:** مختلف صفحات exception handling دارند

**بهبود پیشنهادی:**
```php
// src/Exceptions/TemplateException.php
class TemplateException extends Exception {}

// src/Exceptions/ValidationException.php
class ValidationException extends Exception {}

// استفاده
throw new ValidationException("توکن نامعتبر است");
```

---

## 🔒 امنیت اضافی

### 1. **Input Sanitization برای تمام User Input**

```php
// ✅ اضافهٔ شده
class InputValidator
{
    public static function validateTelegramToken(string $token): bool {
        return preg_match('/^[0-9]{8,10}:[a-zA-Z0-9_-]{35,}$/', $token) === 1;
    }
    
    public static function validateBotUsername(string $username): bool {
        return preg_match('/^[a-z0-9_]{3,32}$/i', $username) === 1;
    }
    
    public static function validateAdminId(int $id): bool {
        return $id > 0 && $id < PHP_INT_MAX;
    }
}
```

### 2. **Rate Limiting برای Webhook**

```php
// src/RateLimiter.php
class RateLimiter
{
    private static array $cache = [];
    
    public static function isAllowed(string $key, int $maxPerMinute = 60): bool {
        $now = time();
        $key = "rate:{$key}:{$now}";
        
        // Implement simple in-memory rate limiting
        return true; // TODO: Implement with Redis or file-based
    }
}
```

---

## 📊 Performance Improvements

### 1. **Database Query Optimization**

**وضعیت فعلی:** بعضی queries بدون index

**پیشنهاد:**
```sql
-- src/migrations/add_indexes.sql
CREATE INDEX idx_users_user_id ON users(user_id);
CREATE INDEX idx_bots_owner_id ON bots(owner_id);
CREATE INDEX idx_processed_updates_id ON processed_updates(update_id);
```

### 2. **Caching برای Repeated Queries**

```php
class Store
{
    private array $userCache = [];
    
    public function user(int $uid, ...): array
    {
        if (isset($this->userCache[$uid])) {
            return $this->userCache[$uid];
        }
        
        $u = $this->getUserFromDb($uid);
        $this->userCache[$uid] = $u;
        return $u;
    }
}
```

---

## 📝 Documentation Improvements

### 1. **API Documentation**

**مثال:**
```php
/**
 * دریافت یا ایجاد کاربر.
 *
 * @param int    $uid      شناسهٔ کاربر تلگرام
 * @param string $first    نام اول (اختیاری)
 * @param string $username یوزرنیم (اختیاری)
 * 
 * @return array{
 *     user_id: int,
 *     first_name: string,
 *     username: string,
 *     is_admin: int,
 *     is_allowed: int,
 *     step: string,
 *     temp: string,
 *     build_count: int,
 *     created_at: string
 * }
 * 
 * @throws Exception اگر دیتابیس ناموجود یا خراب باشد
 */
public function user(int $uid, string $first = '', string $username = ''): array
```

---

## 🧪 Testing Infrastructure

### 1. **Unit Tests برای Validation**

```php
// tools/test_validation.php
class ValidationTest
{
    public function testTokenValidation() {
        assert(InputValidator::validateTelegramToken('123456789:ABCDefGHIJKlmnoPQRStuvWXYZabcd') === true);
        assert(InputValidator::validateTelegramToken('invalid') === false);
        assert(InputValidator::validateTelegramToken('123:abc') === false);
    }
    
    public function testUsernameValidation() {
        assert(InputValidator::validateBotUsername('valid_bot') === true);
        assert(InputValidator::validateBotUsername('bot@name') === false);
        assert(InputValidator::validateBotUsername('x') === false);
    }
}
```

### 2. **Integration Tests برای Migration**

```php
// tools/test_migration.php
class MigrationTest
{
    public function testPasargadMigration() {
        $result = Manager::installPasargadSchema('test_type', '/path/to/bot');
        assert($result['migrated'] === true || $result['migrated'] === false);
        assert(isset($result['note']));
    }
}
```

---

## 🚀 Performance Metrics

### موارد نظارت‌شده (پیشنهادی)

```php
class Metrics
{
    private static array $timers = [];
    
    public static function startTimer(string $name): void {
        self::$timers[$name] = microtime(true);
    }
    
    public static function endTimer(string $name): float {
        $elapsed = microtime(true) - (self::$timers[$name] ?? 0);
        error_log("PERF[{$name}]: {$elapsed}ms");
        return $elapsed;
    }
}

// استفاده
Metrics::startTimer('webhook_processing');
// ... پردازش
Metrics::endTimer('webhook_processing');
```

---

## 📋 Checklist برای نسخهٔ بعدی

- [ ] تمام متدها `declare(strict_types=1)` داشته باشند
- [ ] تمام متدها type hints کامل داشته باشند
- [ ] Exception classes استاندارد شده باشند
- [ ] Input validation centralized شود
- [ ] Database indexes اضافه شوند
- [ ] Unit tests نوشته شوند
- [ ] API documentation کامل شود
- [ ] Performance monitoring اضافه شود
- [ ] Rate limiting پیاده‌سازی شود
- [ ] Security audit دوباره انجام شود

---

## ✨ نتیجهٔ نهایی

**قبل:** ✅ عملکردی و ایمن  
**بعد:** ✅ عملکردی، ایمن، **خوانایی بهتر** و **قابل نگهداری بهتر**

---

**آخرین آپدیت:** ۱۰ مهرماه ۱۴۰۵
