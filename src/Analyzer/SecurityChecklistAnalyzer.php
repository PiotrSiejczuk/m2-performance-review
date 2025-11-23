<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class SecurityChecklistAnalyzer implements AnalyzerInterface
{
    private string $magentoRoot;
    private RecommendationCollector $collector;

    public function __construct(string $magentoRoot, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $this->checkFilePermissions();
        $this->checkAdminSecurity();
        $this->checkSecurityHeaders();
        $this->checkSSLConfiguration();
        $this->checkBackupSecurity();
        $this->checkPublicFiles();
    }

    private function checkFilePermissions(): void
    {
        $criticalPaths = [
            '/app/etc/env.php' => ['writable' => false, 'sensitive' => true],
            '/var' => ['writable' => true, 'sensitive' => false],
            '/generated' => ['writable' => true, 'sensitive' => false],
            '/pub/static' => ['writable' => true, 'sensitive' => false],
            '/pub/media' => ['writable' => true, 'sensitive' => false]
        ];

        $phpUser = $this->getPhpProcessUser();

        foreach ($criticalPaths as $path => $requirements) {
            $fullPath = $this->magentoRoot . $path;
            if (!file_exists($fullPath)) {
                continue;
            }

            $fileOwner = fileowner($fullPath);
            $fileGroup = filegroup($fullPath);
            $ownerInfo = $this->getOwnerName($fileOwner);
            $groupInfo = $this->getGroupName($fileGroup);

            // Check writability
            $isWritableByPhp = is_writable($fullPath);

            if ($requirements['writable'] && !$isWritableByPhp) {
                $this->collector->add(
                    'security',
                    'Fix file permissions - not writable',
                    Recommendation::PRIORITY_HIGH,
                    sprintf(
                        '%s should be writable by PHP process (user: %s). Current owner: %s:%s',
                        $path,
                        $phpUser,
                        $ownerInfo,
                        $groupInfo
                    )
                );
            } elseif (!$requirements['writable'] && $isWritableByPhp && $requirements['sensitive']) {
                $this->collector->add(
                    'security',
                    'Fix file permissions - too permissive',
                    Recommendation::PRIORITY_HIGH,
                    sprintf(
                        '%s contains sensitive data and should NOT be writable by PHP process. Current owner: %s:%s',
                        $path,
                        $ownerInfo,
                        $groupInfo
                    )
                );
            }
        }

        // Optimized writable directory check
        $this->checkWritableDirectoriesOptimized();
    }

    private function getPhpProcessUser(): string
    {
        return get_current_user() ?: 'www-data';
    }

    private function checkWritableDirectoriesOptimized(): void
    {
        $allowedWritable = ['/var', '/pub/media', '/pub/static', '/generated'];
        $skipDirs = ['/vendor', '/node_modules', '/.git'];
        $threshold = 15; // Increased threshold
        $maxScan = 1000; // Limit total directories scanned

        $rootLen = strlen($this->magentoRoot);
        $unexpected = [];
        $scanned = 0;

        try {
            $dirIter = new \RecursiveDirectoryIterator(
                $this->magentoRoot,
                \RecursiveDirectoryIterator::SKIP_DOTS
            );

            $filter = new \RecursiveCallbackFilterIterator($dirIter, function ($file, $key, $iterator) use (
                $allowedWritable, $skipDirs, $rootLen
            ) {
                if (!$file->isDir()) {
                    return false; // Skip files entirely
                }

                $rel = substr($file->getPathname(), $rootLen);

                // Skip allowed writable zones
                foreach ($allowedWritable as $allowed) {
                    if (strpos($rel, $allowed) === 0) {
                        return false;
                    }
                }

                // Skip big directories entirely
                foreach ($skipDirs as $skip) {
                    if (strpos($rel, $skip) === 0) {
                        return false;
                    }
                }

                return true;
            });

            $iterator = new \RecursiveIteratorIterator(
                $filter,
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (++$scanned > $maxScan) {
                    break; // Stop after scanning limit
                }

                if ($item->isDir() && is_writable($item->getPathname())) {
                    $rel = substr($item->getPathname(), $rootLen);
                    $unexpected[] = $rel;

                    if (count($unexpected) > $threshold) {
                        break; // Stop early if threshold exceeded
                    }
                }
            }
        } catch (\Exception $e) {
            // Skip on permission errors
            return;
        }

        if (count($unexpected) > $threshold) {
            $this->collector->add(
                'security',
                'Review writable directories',
                Recommendation::PRIORITY_MEDIUM,
                sprintf(
                    'Found %d writable directories outside recommended areas (var/, pub/media/, pub/static/, generated/). Review file permissions.',
                    count($unexpected)
                )
            );
        }
    }

    private function checkAdminSecurity(): void
    {
        // Check for common security issues
        $configPhp = $this->magentoRoot . '/app/etc/config.php';
        if (file_exists($configPhp)) {
            $config = include $configPhp;

            // Check if 2FA is disabled
            if (isset($config['modules']['Magento_TwoFactorAuth']) && $config['modules']['Magento_TwoFactorAuth'] == 0) {
                $this->collector->add(
                    'security',
                    'Enable Two-Factor Authentication',
                    Recommendation::PRIORITY_HIGH,
                    'Two-Factor Authentication module is disabled. This is a critical security feature.'
                );
            }
        }

        // Check admin URL
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            $adminUrl = $env['backend']['frontName'] ?? 'admin';

            if (in_array($adminUrl, ['admin', 'backend', 'administrator'])) {
                $this->collector->add(
                    'security',
                    'Change default admin URL',
                    Recommendation::PRIORITY_HIGH,
                    sprintf('Admin URL "%s" is predictable. Use a unique, hard-to-guess URL.', $adminUrl)
                );
            }
        }
    }

    private function checkSecurityHeaders(): void
    {
        // Check nginx config for security headers
        $nginxConfigs = [
            '/etc/nginx/sites-available/magento',
            '/etc/nginx/conf.d/magento.conf'
        ];

        $hasSecurityHeaders = false;
        foreach ($nginxConfigs as $config) {
            if (file_exists($config) && is_readable($config)) {
                $content = file_get_contents($config);
                if (preg_match('/add_header\s+X-Frame-Options/i', $content)) {
                    $hasSecurityHeaders = true;
                    break;
                }
            }
        }

        if (!$hasSecurityHeaders) {
            $this->collector->add(
                'security',
                'Add security headers',
                Recommendation::PRIORITY_HIGH,
                "Configure security headers in web server:\n" .
                "X-Frame-Options: SAMEORIGIN\n" .
                "X-Content-Type-Options: nosniff\n" .
                "X-XSS-Protection: 1; mode=block\n" .
                "Strict-Transport-Security: max-age=31536000"
            );
        }
    }

    private function checkSSLConfiguration(): void
    {
        // Check if SSL is enforced
        $this->collector->add(
            'security',
            'Verify SSL configuration',
            Recommendation::PRIORITY_MEDIUM,
            'Ensure SSL is enforced for all pages and use strong TLS configuration (TLS 1.2+)'
        );
    }

    private function checkBackupSecurity(): void
    {
        $pubPath = $this->magentoRoot . '/pub';
        if (!is_dir($pubPath)) {
            return;
        }

        $dangerousPatterns = ['*.sql', '*.sql.gz', '*.tar', '*.tar.gz', '*.zip', '*.bak'];
        $foundFiles = [];

        foreach ($dangerousPatterns as $pattern) {
            $files = glob($pubPath . '/' . $pattern, GLOB_NOSORT);
            if (!empty($files)) {
                foreach ($files as $file) {
                    $foundFiles[] = str_replace($this->magentoRoot, '', $file);
                }
            }
        }

        if (!empty($foundFiles)) {
            $fileCount = count($foundFiles);
            $details = sprintf("Found %d backup/database files in public directory. These must be removed immediately.\n\n", $fileCount);

            $details .= "Dangerous files found:\n";
            foreach (array_slice($foundFiles, 0, 5) as $file) {
                $details .= "  - " . basename($file) . "\n";
            }

            if ($fileCount > 5) {
                $details .= sprintf("  - ... and %d more\n", $fileCount - 5);
            }

            $details .= "\n⚠️  CRITICAL SECURITY RISK: These files are publicly accessible!\n";
            $details .= "\n💡 Full list of %d files available with --export";

            $this->collector->addWithFiles(
                'security',
                'Remove backup files from public directory',
                Recommendation::PRIORITY_HIGH,
                sprintf($details, $fileCount),
                $foundFiles,
                'Backup files in public directory expose sensitive data including database credentials, customer data, and source code. ' .
                'Attackers actively scan for these files. Move backups outside document root or use secure cloud storage. ' .
                'Set up .htaccess rules to block access to backup file extensions.',
                [
                    'total_files' => $fileCount,
                    'file_types' => array_unique(array_map(function($file) {
                        return pathinfo($file, PATHINFO_EXTENSION);
                    }, $foundFiles))
                ]
            );
        }
    }

    private function checkPublicFiles(): void
    {
        $dangerousFiles = [
            '/info.php',
            '/phpinfo.php',
            '/.git',
            '/.gitignore',
            '/.env',
            '/composer.json',
            '/composer.lock',
            '/.user.ini'
        ];

        $foundFiles = [];
        foreach ($dangerousFiles as $file) {
            $fullPath = $this->magentoRoot . '/pub' . $file;
            if (file_exists($fullPath)) {
                $foundFiles[] = '/pub' . $file;
            }

            // Also check root directory
            $rootPath = $this->magentoRoot . $file;
            if (file_exists($rootPath) && is_readable($rootPath)) {
                // Check if accessible via web
                if ($this->isWebAccessible($file)) {
                    $foundFiles[] = $file;
                }
            }
        }

        if (!empty($foundFiles)) {
            $fileCount = count($foundFiles);
            $details = sprintf("Found %d sensitive files that may be publicly accessible:\n\n", $fileCount);

            foreach ($foundFiles as $file) {
                $details .= "  - " . $file . "\n";
            }

            $details .= "\n🔧 Action required:\n";
            $details .= "1. Remove or protect these files immediately\n";
            $details .= "2. Configure web server to block access\n";
            $details .= "3. Move files outside document root if needed\n";
            $details .= "\n💡 Full list available with --export";

            $this->collector->addWithFiles(
                'security',
                'Remove sensitive files from public access',
                Recommendation::PRIORITY_HIGH,
                $details,
                $foundFiles,
                'These files expose sensitive information: phpinfo reveals server config, .git exposes source history, ' .
                'composer files show dependencies and versions, .env may contain credentials. Attackers use these for reconnaissance. ' .
                'Block access via .htaccess or nginx config, or better: remove from web-accessible locations.',
                [
                    'total_files' => $fileCount,
                    'critical_files' => array_filter($foundFiles, function($file) {
                        return in_array(basename($file), ['info.php', 'phpinfo.php', '.env']);
                    })
                ]
            );
        }
    }

    private function isWebAccessible(string $file): bool
    {
        // Simple heuristic - in real implementation would check web server config
        $webRoot = $this->magentoRoot . '/pub';
        $filePath = $this->magentoRoot . $file;

        // If file is under pub/ it's definitely accessible
        if (strpos($filePath, $webRoot) === 0) {
            return true;
        }

        // Check common Magento web server configs
        $protectedPaths = ['/app', '/bin', '/dev', '/lib', '/setup', '/var', '/vendor'];
        foreach ($protectedPaths as $protected) {
            if (strpos($file, $protected) === 0) {
                return false;
            }
        }

        // Assume root level files might be accessible
        return substr_count($file, '/') === 1;
    }

    private function getOwnerName(int $uid): string
    {
        static $cache = [];

        if (!isset($cache[$uid])) {
            if (function_exists('posix_getpwuid')) {
                $info = posix_getpwuid($uid);
                $cache[$uid] = $info['name'] ?? (string)$uid;
            } else {
                $cache[$uid] = (string)$uid;
            }
        }

        return $cache[$uid];
    }

    private function getGroupName(int $gid): string
    {
        static $cache = [];

        if (!isset($cache[$gid])) {
            if (function_exists('posix_getgrgid')) {
                $info = posix_getgrgid($gid);
                $cache[$gid] = $info['name'] ?? (string)$gid;
            } else {
                $cache[$gid] = (string)$gid;
            }
        }

        return $cache[$gid];
    }
}
