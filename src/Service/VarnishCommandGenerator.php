<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

class VarnishCommandGenerator extends BaseScriptGenerator
{
    private array $recommendations = [];
    
    public function generate(array $recommendations): ?string
    {
        $this->recommendations = $recommendations;
        
        $varnishRecommendations = array_filter($recommendations, function($rec) {
            return $rec->getArea() === 'varnish';
        });
        
        if (empty($varnishRecommendations)) {
            return null;
        }
        
        $timestamp = date('Y-m-d_His');
        $filename = "fix_varnish_config_{$timestamp}.sh";
        
        $script = $this->generateVarnishScript($varnishRecommendations);
        
        // Use Unix line endings (LF only)
        $script = str_replace("\r\n", "\n", $script);
        $script = str_replace("\r", "\n", $script);
        
        file_put_contents($filename, $script);
        chmod($filename, 0755);
        
        return $filename;
    }
    
    private function generateVarnishScript(array $recommendations): string
    {
        $script = "#!/bin/bash\n";
        $script .= "# Varnish Configuration Optimization Script\n";
        $script .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
        $script .= "# WARNING: Review all commands before execution\n\n";
        
        $script .= "set -e  # Exit on any error\n\n";
        
        $script .= "echo \"🔧 Varnish Configuration Optimization\"\n";
        $script .= "echo \"====================================\"\n\n";
        
        // Check if user is root
        $script .= "if [[ \$EUID -ne 0 ]]; then\n";
        $script .= "    echo \"This script requires root privileges. Run with sudo.\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";
        
        foreach ($recommendations as $rec) {
            $title = $rec->getTitle();
            $details = $rec->getDetails();
            
            $script .= "echo \"📋 {$title}\"\n";
            $script .= "echo \"" . str_repeat('-', strlen($title) + 5) . "\"\n";
            
            if (stripos($title, 'vcl') !== false) {
                $script .= $this->generateVCLCommands($rec);
            } elseif (stripos($title, 'connection') !== false) {
                $script .= $this->generateConnectionCommands($rec);
            } elseif (stripos($title, 'cache warming') !== false) {
                $script .= $this->generateCacheWarmingCommands($rec);
            } elseif (stripos($title, 'configuration') !== false) {
                $script .= $this->generateConfigCommands($rec);
            } else {
                $script .= $this->generateGenericVarnishCommands($rec);
            }
            
            $script .= "echo \"\"\n\n";
        }
        
        $script .= "echo \"✅ Varnish optimization completed!\"\n";
        $script .= "echo \"Remember to:\"\n";
        $script .= "echo \"  1. Test your VCL configuration\"\n";
        $script .= "echo \"  2. Restart Varnish service\"\n";
        $script .= "echo \"  3. Monitor cache hit rates\"\n";
        $script .= "echo \"  4. Configure cache warming if needed\"\n";
        
        return $script;
    }
    
    private function generateVCLCommands(Recommendation $rec): string
    {
        $script = "";
        
        // Backup existing VCL
        $script .= "# Backup existing VCL configuration\n";
        $script .= "if [ -f /etc/varnish/default.vcl ]; then\n";
        $script .= "    cp /etc/varnish/default.vcl /etc/varnish/default.vcl.backup.\$(date +%Y%m%d_%H%M%S)\n";
        $script .= "    echo \"  ✓ Backed up existing VCL\"\n";
        $script .= "fi\n\n";
        
        // Generate Magento VCL
        $script .= "# Generate Magento-optimized VCL\n";
        $script .= "if command -v magento >/dev/null 2>&1; then\n";
        $script .= "    echo \"  → Generating Magento VCL...\"\n";
        $script .= "    php bin/magento varnish:vcl:generate --export-version=6 > /etc/varnish/magento.vcl\n";
        $script .= "    \n";
        $script .= "    # Apply additional optimizations\n";
        $script .= "    echo \"  → Applying performance optimizations...\"\n";
        $script .= "    \n";
        $script .= "    # Add grace mode configuration\n";
        $script .= "    sed -i '/beresp.ttl/a\\    set beresp.grace = 24h;' /etc/varnish/magento.vcl\n";
        $script .= "    sed -i '/beresp.grace/a\\    set beresp.keep = 8h;' /etc/varnish/magento.vcl\n";
        $script .= "    \n";
        $script .= "    # Optimize cookie handling\n";
        $script .= "    sed -i 's/# unset req.http.Cookie;/unset req.http.Cookie;/' /etc/varnish/magento.vcl\n";
        $script .= "    \n";
        $script .= "    echo \"  ✓ Generated optimized VCL\"\n";
        $script .= "else\n";
        $script .= "    echo \"  ⚠ Magento CLI not found. Manual VCL configuration required.\"\n";
        $script .= "fi\n";
        
        return $script;
    }
    
    private function generateConnectionCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Test Varnish connectivity\n";
        $script .= "echo \"  → Testing Varnish connection...\"\n";
        $script .= "if systemctl is-active --quiet varnish; then\n";
        $script .= "    echo \"  ✓ Varnish service is running\"\n";
        $script .= "    \n";
        $script .= "    # Test HTTP connection\n";
        $script .= "    if curl -s -o /dev/null -w \"%{http_code}\" http://localhost:80 | grep -q \"200\\|301\\|302\"; then\n";
        $script .= "        echo \"  ✓ Varnish is responding to HTTP requests\"\n";
        $script .= "    else\n";
        $script .= "        echo \"  ⚠ Varnish not responding properly\"\n";
        $script .= "        echo \"    Check Varnish configuration and logs\"\n";
        $script .= "    fi\n";
        $script .= "else\n";
        $script .= "    echo \"  ❌ Varnish service is not running\"\n";
        $script .= "    echo \"    Start with: systemctl start varnish\"\n";
        $script .= "    echo \"    Enable: systemctl enable varnish\"\n";
        $script .= "fi\n";
        
        return $script;
    }
    
    private function generateCacheWarmingCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Cache warming setup\n";
        $script .= "echo \"  → Setting up cache warming...\"\n";
        $script .= "\n";
        $script .= "# Create cache warming script\n";
        $script .= "cat > /usr/local/bin/magento-cache-warm << 'EOF'\n";
        $script .= "#!/bin/bash\n";
        $script .= "# Magento Cache Warming Script\n";
        $script .= "\n";
        $script .= "MAGENTO_ROOT=\"/var/www/html\"  # Adjust this path\n";
        $script .= "BASE_URL=\"http://localhost\"   # Adjust this URL\n";
        $script .= "\n";
        $script .= "cd \$MAGENTO_ROOT\n";
        $script .= "\n";
        $script .= "# Warm homepage\n";
        $script .= "curl -s \$BASE_URL >/dev/null\n";
        $script .= "\n";
        $script .= "# Warm category pages\n";
        $script .= "php bin/magento catalog:category:list --format=json | jq -r '.[].url_path' | head -20 | while read url; do\n";
        $script .= "    curl -s \$BASE_URL/\$url >/dev/null\n";
        $script .= "done\n";
        $script .= "\n";
        $script .= "# Warm product pages\n";
        $script .= "php bin/magento catalog:product:list --format=json | jq -r '.[].url_key' | head -50 | while read url; do\n";
        $script .= "    curl -s \$BASE_URL/\$url.html >/dev/null\n";
        $script .= "done\n";
        $script .= "EOF\n";
        $script .= "\n";
        $script .= "chmod +x /usr/local/bin/magento-cache-warm\n";
        $script .= "echo \"  ✓ Created cache warming script\"\n";
        $script .= "\n";
        $script .= "# Add to crontab\n";
        $script .= "echo \"  → Adding to crontab...\"\n";
        $script .= "(crontab -l 2>/dev/null; echo \"*/30 * * * * /usr/local/bin/magento-cache-warm >/dev/null 2>&1\") | crontab -\n";
        $script .= "echo \"  ✓ Added cache warming to crontab (runs every 30 minutes)\"\n";
        
        return $script;
    }
    
    private function generateConfigCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Configure Varnish settings\n";
        $script .= "echo \"  → Configuring Varnish settings...\"\n";
        $script .= "\n";
        $script .= "# Update Varnish systemd configuration\n";
        $script .= "if [ -f /etc/systemd/system/varnish.service ]; then\n";
        $script .= "    VARNISH_SERVICE=\"/etc/systemd/system/varnish.service\"\n";
        $script .= "elif [ -f /lib/systemd/system/varnish.service ]; then\n";
        $script .= "    VARNISH_SERVICE=\"/lib/systemd/system/varnish.service\"\n";
        $script .= "else\n";
        $script .= "    echo \"  ⚠ Varnish systemd service file not found\"\n";
        $script .= "    VARNISH_SERVICE=\"\"\n";
        $script .= "fi\n";
        $script .= "\n";
        $script .= "if [ ! -z \"\$VARNISH_SERVICE\" ]; then\n";
        $script .= "    # Backup original service file\n";
        $script .= "    cp \"\$VARNISH_SERVICE\" \"\${VARNISH_SERVICE}.backup.\$(date +%Y%m%d_%H%M%S)\"\n";
        $script .= "    \n";
        $script .= "    # Update memory allocation (adjust -s malloc size as needed)\n";
        $script .= "    sed -i 's/-s malloc,256m/-s malloc,1g/' \"\$VARNISH_SERVICE\"\n";
        $script .= "    \n";
        $script .= "    # Reload systemd and restart\n";
        $script .= "    systemctl daemon-reload\n";
        $script .= "    echo \"  ✓ Updated Varnish configuration\"\n";
        $script .= "fi\n";
        
        return $script;
    }
    
    private function generateGenericVarnishCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Generic Varnish optimization\n";
        $script .= "echo \"  → Applying optimization...\"\n";
        $script .= "\n";
        $script .= "# Check Varnish status\n";
        $script .= "varnishstat -1 | head -20\n";
        $script .= "\n";
        $script .= "# Show cache hit rate\n";
        $script .= "echo \"  Current cache stats:\"\n";
        $script .= "varnishstat -1 -f cache_hit,cache_miss | while read line; do\n";
        $script .= "    echo \"    \$line\"\n";
        $script .= "done\n";
        $script .= "\n";
        $script .= "echo \"  ✓ Varnish status displayed\"\n";
        
        return $script;
    }
}
