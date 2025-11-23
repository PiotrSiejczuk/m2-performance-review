<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

class SecurityCommandGenerator extends BaseScriptGenerator
{
    public function generateSecurityCommands(array $recommendations): array
    {
        $commands = [
            'File Permissions' => [],
            'Admin Security' => [],
            'Web Server Security' => [],
            'SSL/TLS Configuration' => [],
            'System Hardening' => []
        ];

        foreach ($recommendations as $recommendation) {
            if ($recommendation->getArea() !== 'security') {
                continue;
            }

            $message = $recommendation->getMessage();
            $details = $recommendation->getDetail();

            // File Permissions
            if (stripos($message, 'file permission') !== false) {
                $commands['File Permissions'] = array_merge(
                    $commands['File Permissions'],
                    $this->generateFilePermissionCommands($message, $details)
                );
            }

            // Admin Security
            if (stripos($message, 'admin') !== false || stripos($message, '2fa') !== false || stripos($message, 'two-factor') !== false) {
                $commands['Admin Security'] = array_merge(
                    $commands['Admin Security'],
                    $this->generateAdminSecurityCommands($message, $details)
                );
            }

            // Web Server Security
            if (stripos($message, 'security header') !== false || stripos($message, 'nginx') !== false || stripos($message, 'apache') !== false) {
                $commands['Web Server Security'] = array_merge(
                    $commands['Web Server Security'],
                    $this->generateWebServerSecurityCommands($message, $details)
                );
            }

            // SSL/TLS
            if (stripos($message, 'ssl') !== false || stripos($message, 'https') !== false || stripos($message, 'tls') !== false) {
                $commands['SSL/TLS Configuration'] = array_merge(
                    $commands['SSL/TLS Configuration'],
                    $this->generateSSLCommands($message, $details)
                );
            }

            // System Hardening
            if (stripos($message, 'backup') !== false || stripos($message, 'sensitive file') !== false) {
                $commands['System Hardening'] = array_merge(
                    $commands['System Hardening'],
                    $this->generateSystemHardeningCommands($message, $details, $recommendation->getFileList())
                );
            }
        }

        // Remove empty categories
        return array_filter($commands, fn($commandList) => !empty($commandList));
    }

    private function generateFilePermissionCommands(string $message, string $details): array
    {
        $commands = [];

        if (stripos($message, 'not writable') !== false) {
            $commands[] = '# Fix writable permissions for Magento directories';
            $commands[] = 'chown -R www-data:www-data var/ generated/ pub/media/ pub/static/';
            $commands[] = 'chmod -R 755 var/ generated/ pub/media/ pub/static/';
        }

        if (stripos($message, 'too permissive') !== false) {
            $commands[] = '# Secure sensitive configuration files';
            $commands[] = 'chmod 600 app/etc/env.php app/etc/config.php';
            $commands[] = 'chown root:root app/etc/env.php app/etc/config.php';
        }

        if (stripos($details, 'writable directories') !== false) {
            $commands[] = '# Review and fix excessive writable permissions';
            $commands[] = 'find . -type d -perm 777 -not -path "./var/*" -not -path "./generated/*" -not -path "./pub/media/*" -not -path "./pub/static/*" -exec chmod 755 {} \\;';
        }

        return $commands;
    }

    private function generateAdminSecurityCommands(string $message, string $details): array
    {
        $commands = [];

        if (stripos($message, 'two-factor') !== false || stripos($message, '2fa') !== false) {
            $commands[] = '# Enable Two-Factor Authentication';
            $commands[] = 'bin/magento module:enable Magento_TwoFactorAuth';
            $commands[] = 'bin/magento setup:upgrade';
            $commands[] = 'bin/magento cache:clean';
        }

        if (stripos($message, 'admin url') !== false) {
            $commands[] = '# Change admin URL to custom path (replace "secure-admin-123" with your choice)';
            $commands[] = 'bin/magento setup:config:set --backend-frontname=secure-admin-123';
        }

        if (stripos($details, 'form key') !== false) {
            $commands[] = '# Enable admin form key security';
            $commands[] = 'bin/magento config:set admin/security/use_form_key 1';
        }

        $commands[] = '# Additional admin security settings';
        $commands[] = 'bin/magento config:set admin/security/password_lifetime 90';
        $commands[] = 'bin/magento config:set admin/security/lockout_failures 3';
        $commands[] = 'bin/magento config:set admin/security/lockout_threshold 60';

        return $commands;
    }

    private function generateWebServerSecurityCommands(string $message, string $details): array
    {
        $commands = [];

        if (stripos($message, 'security header') !== false) {
            $commands[] = '# Add security headers to Nginx configuration';
            $commands[] = 'cat >> /etc/nginx/conf.d/security-headers.conf << EOF';
            $commands[] = 'add_header X-Frame-Options "SAMEORIGIN" always;';
            $commands[] = 'add_header X-Content-Type-Options "nosniff" always;';
            $commands[] = 'add_header X-XSS-Protection "1; mode=block" always;';
            $commands[] = 'add_header Referrer-Policy "strict-origin-when-cross-origin" always;';
            $commands[] = 'add_header Content-Security-Policy "default-src \'self\'; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data: https:;" always;';
            $commands[] = 'EOF';
            $commands[] = '';
            $commands[] = '# For Apache, add to .htaccess or virtual host:';
            $commands[] = '# Header always set X-Frame-Options "SAMEORIGIN"';
            $commands[] = '# Header always set X-Content-Type-Options "nosniff"';
            $commands[] = '# Header always set X-XSS-Protection "1; mode=block"';
        }

        return $commands;
    }

    private function generateSSLCommands(string $message, string $details): array
    {
        $commands = [];

        if (stripos($message, 'https') !== false || stripos($message, 'secure url') !== false) {
            $commands[] = '# Configure Magento to use HTTPS';
            $commands[] = 'bin/magento config:set web/secure/use_in_frontend 1';
            $commands[] = 'bin/magento config:set web/secure/use_in_adminhtml 1';
            $commands[] = 'bin/magento config:set web/secure/base_url https://yourdomain.com/';
            $commands[] = 'bin/magento config:set web/cookie/cookie_secure 1';
            $commands[] = 'bin/magento config:set web/cookie/cookie_httponly 1';
        }

        if (stripos($message, 'upgrade insecure') !== false) {
            $commands[] = '# Enable automatic HTTP to HTTPS redirects';
            $commands[] = 'bin/magento config:set web/secure/upgrade_insecure_requests 1';
        }

        if (stripos($details, 'load balancer') !== false || stripos($details, 'offloader') !== false) {
            $commands[] = '# Configure for load balancer SSL termination';
            $commands[] = 'bin/magento config:set web/secure/offloader_header X-Forwarded-Proto';
        }

        return $commands;
    }

    private function generateSystemHardeningCommands(string $message, string $details, ?array $fileList): array
    {
        $commands = [];

        if (stripos($message, 'backup') !== false && $fileList) {
            $commands[] = '# Remove dangerous backup files from public directory';
            foreach ($fileList as $file) {
                $commands[] = "rm -f \"$file\"";
            }
        }

        if (stripos($message, 'sensitive file') !== false) {
            $commands[] = '# Remove/protect sensitive files';
            $sensitiveFiles = ['/pub/info.php', '/pub/phpinfo.php', '/pub/.env', '/pub/composer.json'];
            foreach ($sensitiveFiles as $file) {
                $commands[] = "rm -f \".{$file}\"";
            }
        }

        if (stripos($details, '.git') !== false) {
            $commands[] = '# Protect .git directory';
            $commands[] = 'echo "deny from all" > .git/.htaccess';
        }

        return $commands;
    }

    public function generateSecurityScript(array $recommendations): string
    {
        $commands = $this->generateSecurityCommands($recommendations);

        if (empty($commands)) {
            return '';
        }

        $script = "#!/bin/bash\n";
        $script .= "# Magento 2 Security Configuration Script\n";
        $script .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";

        $script .= "set -e  # Exit on any error\n\n";

        $script .= "echo \"🔒 Applying Magento 2 security configurations...\"\n";
        $script .= "echo \"============================================\"\n\n";

        $script .= "# Check if we're in Magento root\n";
        $script .= "if [ ! -f \"bin/magento\" ]; then\n";
        $script .= "    echo \"❌ Error: bin/magento not found. Please run this script from Magento root directory.\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";

        $script .= "# Check if running as root for file permission changes\n";
        $script .= "if [[ \$EUID -ne 0 ]] && [[ \"\$1\" != \"--no-root\" ]]; then\n";
        $script .= "    echo \"⚠️ Some operations require root privileges.\"\n";
        $script .= "    echo \"Run with sudo or add --no-root to skip file permission changes.\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";

        foreach ($commands as $category => $categoryCommands) {
            $script .= "echo \"📋 Configuring: $category\"\n";
            $script .= "echo \"" . str_repeat('-', strlen($category) + 15) . "\"\n\n";

            foreach ($categoryCommands as $command) {
                if (strpos($command, '#') === 0) {
                    $script .= "$command\n";
                } else {
                    $script .= "echo \"Executing: $command\"\n";
                    $script .= "$command 2>/dev/null || echo \"⚠️ Command failed: $command\"\n";
                }
            }
            $script .= "\n";
        }

        $script .= "echo \"🧹 Clearing cache after security changes...\"\n";
        $script .= "bin/magento cache:clean\n";
        $script .= "bin/magento cache:flush\n\n";

        $script .= "echo \"✅ Security configuration complete!\"\n";
        $script .= "echo \"🔍 Run './m2-performance.phar review --profile=security' to verify improvements.\"\n";

        return $script;
    }
}
