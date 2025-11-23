<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

class ConfigCommandGenerator extends BaseScriptGenerator
{
    public function generate(array $recommendations): ?string
    {
        $configRecommendations = array_filter($recommendations, function($rec) {
            return in_array($rec->getArea(), ['config', 'caching', 'cache', 'commerce', 'search', 'modules']);
        });
        
        if (empty($configRecommendations)) {
            return null;
        }
        
        $timestamp = date('Y-m-d_His');
        $filename = "fix_magento_config_{$timestamp}.sh";
        
        $script = $this->generateConfigScript($configRecommendations);
        
        if ($this->writeScript($filename, $script)) {
            return $filename;
        }
        
        return null;
    }
    
    private function generateConfigScript(array $recommendations): string
    {
        $script = $this->generateHeader('Magento Configuration Fix Script');
        
        $script .= "echo \"🔧 Magento Configuration Optimization\"\n";
        $script .= "echo \"====================================\"\n\n";
        
        // Check if we're in Magento root
        $script .= "# Verify we're in Magento root directory\n";
        $script .= "if [ ! -f \"bin/magento\" ]; then\n";
        $script .= "    echo \"❌ Error: bin/magento not found. Please run from Magento root directory.\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";
        
        $script .= "echo \"📍 Current directory: \$(pwd)\"\n";
        $script .= "echo \"🏃 Running as user: \$(whoami)\"\n\n";
        
        foreach ($recommendations as $rec) {
            $script .= $this->generateConfigCommands($rec);
        }
        
        $script .= "echo \"\"\n";
        $script .= "echo \"✅ Configuration optimization completed!\"\n";
        $script .= "echo \"Remember to:\"\n";
        $script .= "echo \"  1. Clear cache: bin/magento cache:clean\"\n";
        $script .= "echo \"  2. Reindex if needed: bin/magento indexer:reindex\"\n";
        $script .= "echo \"  3. Deploy static content if in production\"\n";
        
        return $script;
    }
    
    private function generateConfigCommands(Recommendation $rec): string
    {
        $script = "";
        $title = $rec->getTitle();
        $details = $rec->getDetails();
        
        $script .= "echo \"📋 {$title}\"\n";
        $script .= "echo \"" . str_repeat('-', strlen($title) + 5) . "\"\n";
        
        // Extract config commands from details
        if (preg_match_all('/bin\/magento config:set ([^\s]+) ([^\s\n]+)/', $details, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $path = $match[1];
                $value = $match[2];
                
                $script .= "echo \"  → Setting {$path} = {$value}\"\n";
                $script .= "if php bin/magento config:set \"{$path}\" \"{$value}\" --lock-config 2>/dev/null; then\n";
                $script .= "    echo \"    ✓ Successfully set {$path}\"\n";
                $script .= "else\n";
                $script .= "    echo \"    ⚠ Could not set {$path} (may already be locked or invalid)\"\n";
                $script .= "fi\n";
            }
        } else {
            // Generic handling for other config recommendations
            $script .= "echo \"  → {$title}\"\n";
            $script .= "echo \"    Manual action required. See details in analysis report.\"\n";
        }
        
        $script .= "echo \"\"\n\n";
        
        return $script;
    }
}
