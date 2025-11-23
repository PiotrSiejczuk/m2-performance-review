<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

class SystemCommandGenerator extends BaseScriptGenerator
{
    public function generate(array $recommendations): ?string
    {
        $systemRecommendations = array_filter($recommendations, function($rec) {
            return in_array($rec->getArea(), ['opcache', 'system', 'protocol', 'security', 'api-security']);
        });
        
        if (empty($systemRecommendations)) {
            return null;
        }
        
        $timestamp = date('Y-m-d_His');
        $filename = "fix_system_config_{$timestamp}.sh";
        
        $script = $this->generateSystemScript($systemRecommendations);
        
        if ($this->writeScript($filename, $script)) {
            return $filename;
        }
        
        return null;
    }
    
    private function generateSystemScript(array $recommendations): string
    {
        $script = $this->generateHeader('System Configuration Fix Script');
        
        $script .= "echo \"🔧 System Configuration Optimization\"\n";
        $script .= "echo \"===================================\"\n\n";
        
        // Check if user has sudo privileges
        $script .= "# Check if running as root or with sudo\n";
        $script .= "if [[ \$EUID -ne 0 ]]; then\n";
        $script .= "    echo \"⚠ This script requires root privileges for system-level changes.\"\n";
        $script .= "    echo \"Run with: sudo \$0\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";
        
        foreach ($recommendations as $rec) {
            $script .= $this->generateSystemCommands($rec);
        }
        
        $script .= "echo \"\"\n";
        $script .= "echo \"✅ System optimization completed!\"\n";
        $script .= "echo \"Remember to:\"\n";
        $script .= "echo \"  1. Restart web server: systemctl restart apache2/nginx\"\n";
        $script .= "echo \"  2. Restart PHP-FPM: systemctl restart php*-fpm\"\n";
        $script .= "echo \"  3. Test configuration changes\"\n";
        
        return $script;
    }
    
    private function generateSystemCommands(Recommendation $rec): string
    {
        $script = "";
        $title = $rec->getTitle();
        $details = $rec->getDetails();
        
        $script .= "echo \"📋 {$title}\"\n";
        $script .= "echo \"" . str_repeat('-', strlen($title) + 5) . "\"\n";
        
        if (stripos($title, 'opcache') !== false) {
            $script .= $this->generateOpcacheCommands($rec);
        } elseif (stripos($title, 'nginx') !== false) {
            $script .= $this->generateNginxCommands($rec);
        } elseif (stripos($title, 'apache') !== false) {
            $script .= $this->generateApacheCommands($rec);
        } else {
            $script .= "echo \"  → Manual configuration required\"\n";
            $script .= "echo \"    See analysis report for details\"\n";
        }
        
        $script .= "echo \"\"\n\n";
        
        return $script;
    }
    
    private function generateOpcacheCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# OPcache configuration\n";
        $script .= "echo \"  → Updating OPcache settings...\"\n";
        $script .= "\n";
        $script .= "# Find PHP INI files\n";
        $script .= "PHP_VERSION=\$(php -r \"echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;\")\n";
        $script .= "INI_PATHS=(\n";
        $script .= "    \"/etc/php/\$PHP_VERSION/fpm/conf.d/10-opcache.ini\"\n";
        $script .= "    \"/etc/php/\$PHP_VERSION/cli/conf.d/10-opcache.ini\"\n";
        $script .= "    \"/etc/php/\$PHP_VERSION/apache2/conf.d/10-opcache.ini\"\n";
        $script .= ")\n";
        $script .= "\n";
        $script .= "for ini_path in \"\${INI_PATHS[@]}\"; do\n";
        $script .= "    if [ -f \"\$ini_path\" ]; then\n";
        $script .= "        echo \"    → Updating \$ini_path\"\n";
        $script .= "        \n";
        $script .= "        # Backup original\n";
        $script .= "        cp \"\$ini_path\" \"\$ini_path.backup.\$(date +%Y%m%d_%H%M%S)\"\n";
        $script .= "        \n";
        $script .= "        # Apply recommended settings\n";
        $script .= "        cat >> \"\$ini_path\" << 'EOF'\n";
        $script .= "; Performance optimizations\n";
        $script .= "opcache.memory_consumption=1024\n";
        $script .= "opcache.interned_strings_buffer=64\n";
        $script .= "opcache.max_accelerated_files=0\n";
        $script .= "opcache.validate_timestamps=0\n";
        $script .= "opcache.save_comments=1\n";
        $script .= "opcache.enable_file_override=0\n";
        $script .= "EOF\n";
        $script .= "        \n";
        $script .= "        echo \"      ✓ Updated \$ini_path\"\n";
        $script .= "    fi\n";
        $script .= "done\n";
        
        return $script;
    }
    
    private function generateNginxCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Nginx configuration\n";
        $script .= "echo \"  → Checking Nginx configuration...\"\n";
        $script .= "\n";
        $script .= "if command -v nginx >/dev/null 2>&1; then\n";
        $script .= "    nginx -t\n";
        $script .= "    if [ \$? -eq 0 ]; then\n";
        $script .= "        echo \"    ✓ Nginx configuration is valid\"\n";
        $script .= "    else\n";
        $script .= "        echo \"    ❌ Nginx configuration has errors\"\n";
        $script .= "    fi\n";
        $script .= "else\n";
        $script .= "    echo \"    ⚠ Nginx not found\"\n";
        $script .= "fi\n";
        
        return $script;
    }
    
    private function generateApacheCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Apache configuration\n";
        $script .= "echo \"  → Checking Apache configuration...\"\n";
        $script .= "\n";
        $script .= "if command -v apache2ctl >/dev/null 2>&1; then\n";
        $script .= "    apache2ctl configtest\n";
        $script .= "    if [ \$? -eq 0 ]; then\n";
        $script .= "        echo \"    ✓ Apache configuration is valid\"\n";
        $script .= "    else\n";
        $script .= "        echo \"    ❌ Apache configuration has errors\"\n";
        $script .= "    fi\n";
        $script .= "elif command -v httpd >/dev/null 2>&1; then\n";
        $script .= "    httpd -t\n";
        $script .= "    if [ \$? -eq 0 ]; then\n";
        $script .= "        echo \"    ✓ Apache configuration is valid\"\n";
        $script .= "    else\n";
        $script .= "        echo \"    ❌ Apache configuration has errors\"\n";
        $script .= "    fi\n";
        $script .= "else\n";
        $script .= "    echo \"    ⚠ Apache not found\"\n";
        $script .= "fi\n";
        
        return $script;
    }
}
