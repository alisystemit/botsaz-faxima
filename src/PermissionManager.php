<?php
// ===== مدیریت خودکار Permissions (755) =====
// در هر نصب، تمام دسترسی‌ها صحیح تنظیم می‌شوند

class PermissionManager
{
    // دسترسی‌های استاندارد
    private const PERM_DIR = 0755;   // پوشه‌ها: rwxr-xr-x
    private const PERM_FILE = 0644;  // فایل‌ها: rw-r--r--
    private const PERM_EXEC = 0755;  // فایل‌های اجرایی: rwxr-xr-x
    
    // فایل‌های اجرایی (.sh, بعضی .php)
    private static array $executableFiles = [
        'tools/install.sh',
        'tools/update.sh',
        'fix_permissions.sh',
        'FIX_SERVER.sh',
    ];
    
    /**
     * تنظیم دسترسی‌های تمام پوشه‌ها و فایل‌ها
     * استفاده: PermissionManager::fixAll('/path/to/botsaz-faxima')
     */
    public static function fixAll(string $rootPath): array
    {
        $rootPath = rtrim($rootPath, '/\\');
        if (!is_dir($rootPath)) {
            return ['success' => false, 'error' => "مسیر وجود ندارد: {$rootPath}"];
        }
        
        $results = [
            'success' => true,
            'fixed_dirs' => 0,
            'fixed_files' => 0,
            'errors' => [],
        ];
        
        // تنظیم تمام دایرکتوری‌ها
        self::recursiveChmod($rootPath, self::PERM_DIR, self::PERM_FILE, $results);
        
        // تنظیم دسترسی فایل‌های اجرایی
        foreach (self::$executableFiles as $file) {
            $fullPath = $rootPath . '/' . $file;
            if (is_file($fullPath)) {
                if (@chmod($fullPath, self::PERM_EXEC)) {
                    $results['fixed_files']++;
                } else {
                    $results['errors'][] = "نمی‌توان اجرایی کند: {$file}";
                }
            }
        }
        
        return $results;
    }
    
    /**
     * تنظیم دسترسی‌های بازگشتی برای پوشه و محتویات آن
     */
    private static function recursiveChmod(
        string $path,
        int $dirPerm,
        int $filePerm,
        array &$results,
        int $depth = 0
    ): void {
        // محدودیت عمق برای جلوگیری از loops
        if ($depth > 50) return;
        
        // تنظیم دسترسی خود پوشه
        if ($depth > 0 && !@chmod($path, $dirPerm)) {
            $results['errors'][] = "نمی‌توان دسترسی تنظیم کند: {$path}";
            $results['success'] = false;
        }
        
        if ($depth > 0) {
            $results['fixed_dirs']++;
        }
        
        if (!is_readable($path)) {
            $results['errors'][] = "پوشه خوانایی نیست: {$path}";
            return;
        }
        
        // بررسی محتویات
        $items = @scandir($path);
        if ($items === false) {
            $results['errors'][] = "نمی‌توان پوشه را خواند: {$path}";
            $results['success'] = false;
            return;
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $fullPath = $path . '/' . $item;
            
            // Skip symbolic links
            if (is_link($fullPath)) continue;
            
            if (is_dir($fullPath)) {
                // بازگشتی برای پوشه‌های تو‌در‌تو
                self::recursiveChmod($fullPath, $dirPerm, $filePerm, $results, $depth + 1);
            } elseif (is_file($fullPath)) {
                // تنظیم دسترسی فایل
                $perm = self::shouldBeExecutable($fullPath) ? self::PERM_EXEC : $filePerm;
                if (!@chmod($fullPath, $perm)) {
                    $results['errors'][] = "نمی‌توان دسترسی فایل تنظیم کند: {$fullPath}";
                    $results['success'] = false;
                } else {
                    $results['fixed_files']++;
                }
            }
        }
    }
    
    /**
     * تشخیص اینکه فایل باید اجرایی باشد یا نه
     */
    private static function shouldBeExecutable(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // فایل‌های shell
        if ($ext === 'sh') return true;
        
        // فایل‌های PHP اجرایی
        if (in_array($filePath, array_map(
            fn($f) => rtrim(dirname($f), '/') . '/' . basename($f),
            self::$executableFiles
        ))) {
            return true;
        }
        
        // فایل‌های CLI
        if (basename($filePath) === 'cli.php') return true;
        if (basename($filePath) === 'console.php') return true;
        
        return false;
    }
    
    /**
     * تنظیم دسترسی پوشهٔ خاص
     */
    public static function fixDirectory(string $dirPath, int $perm = self::PERM_DIR): bool
    {
        if (!is_dir($dirPath)) return false;
        return @chmod($dirPath, $perm);
    }
    
    /**
     * تنظیم دسترسی فایل خاص
     */
    public static function fixFile(string $filePath, int $perm = self::PERM_FILE): bool
    {
        if (!is_file($filePath)) return false;
        return @chmod($filePath, $perm);
    }
    
    /**
     * بررسی و گزارش دسترسی‌های نادرست
     */
    public static function audit(string $rootPath): array
    {
        $rootPath = rtrim($rootPath, '/\\');
        $issues = [];
        
        self::auditRecursive($rootPath, $issues);
        
        return [
            'total_issues' => count($issues),
            'issues' => $issues,
        ];
    }
    
    /**
     * بررسی بازگشتی
     */
    private static function auditRecursive(string $path, array &$issues, int $depth = 0): void
    {
        if ($depth > 50) return;
        
        if (!is_readable($path)) {
            $issues[] = "پوشه غیرخوانایی: {$path}";
            return;
        }
        
        $items = @scandir($path);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $fullPath = $path . '/' . $item;
            if (is_link($fullPath)) continue;
            
            $perms = @fileperms($fullPath);
            if ($perms === false) continue;
            
            $currentPerms = substr(sprintf('%o', $perms), -3);
            
            if (is_dir($fullPath)) {
                if ($currentPerms !== '755') {
                    $issues[] = "دسترسی نادرست (dir): {$fullPath} ({$currentPerms} بجای 755)";
                }
                self::auditRecursive($fullPath, $issues, $depth + 1);
            } elseif (is_file($fullPath)) {
                $shouldBeExec = self::shouldBeExecutable($fullPath);
                $expectedPerms = $shouldBeExec ? '755' : '644';
                
                if ($currentPerms !== $expectedPerms) {
                    $issues[] = "دسترسی نادرست (file): {$fullPath} ({$currentPerms} بجای {$expectedPerms})";
                }
            }
        }
    }
    
    /**
     * آمار کلی دسترسی‌ها
     */
    public static function stats(string $rootPath): array
    {
        $stats = [
            'total_dirs' => 0,
            'total_files' => 0,
            'correct_perms' => 0,
            'incorrect_perms' => 0,
        ];
        
        self::statsRecursive($rootPath, $stats);
        
        return $stats;
    }
    
    private static function statsRecursive(string $path, array &$stats, int $depth = 0): void
    {
        if ($depth > 50) return;
        if (!is_readable($path)) return;
        
        $items = @scandir($path);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $fullPath = $path . '/' . $item;
            if (is_link($fullPath)) continue;
            
            $perms = @fileperms($fullPath);
            if ($perms === false) continue;
            
            $currentPerms = substr(sprintf('%o', $perms), -3);
            
            if (is_dir($fullPath)) {
                $stats['total_dirs']++;
                if ($currentPerms === '755') {
                    $stats['correct_perms']++;
                } else {
                    $stats['incorrect_perms']++;
                }
                self::statsRecursive($fullPath, $stats, $depth + 1);
            } elseif (is_file($fullPath)) {
                $stats['total_files']++;
                $shouldBeExec = self::shouldBeExecutable($fullPath);
                $expectedPerms = $shouldBeExec ? '755' : '644';
                
                if ($currentPerms === $expectedPerms) {
                    $stats['correct_perms']++;
                } else {
                    $stats['incorrect_perms']++;
                }
            }
        }
    }
}
