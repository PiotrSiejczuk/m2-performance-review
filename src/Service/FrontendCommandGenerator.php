<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

class FrontendCommandGenerator extends BaseScriptGenerator
{
    public function generate(array $recommendations): ?string
    {
        $frontendRecommendations = array_filter($recommendations, function($rec) {
            return in_array($rec->getArea(), ['frontend', 'codebase']) ||
                   ($rec->getArea() === 'caching' && stripos($rec->getTitle(), 'cacheable') !== false);
        });
        
        if (empty($frontendRecommendations)) {
            return null;
        }
        
        $timestamp = date('Y-m-d_His');
        $filename = "fix_frontend_performance_{$timestamp}.sh";
        
        $script = $this->generateFrontendScript($frontendRecommendations);
        
        if ($this->writeScript($filename, $script)) {
            return $filename;
        }
        
        return null;
    }
    
    private function generateFrontendScript(array $recommendations): string
    {
        $script = $this->generateHeader('Frontend Performance Optimization Script');
        
        $script .= "echo \"🎨 Frontend Performance Optimization\"\n";
        $script .= "echo \"===================================\"\n\n";
        
        // Check if we're in Magento root
        $script .= "# Verify we're in Magento root directory\n";
        $script .= "if [ ! -f \"bin/magento\" ]; then\n";
        $script .= "    echo \"❌ Error: bin/magento not found. Please run from Magento root directory.\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";
        
        $script .= "echo \"📍 Current directory: \$(pwd)\"\n";
        $script .= "echo \"🏃 Running as user: \$(whoami)\"\n\n";
        
        foreach ($recommendations as $rec) {
            $script .= $this->generateFrontendCommands($rec);
        }
        
        $script .= "echo \"\"\n";
        $script .= "echo \"✅ Frontend optimization completed!\"\n";
        $script .= "echo \"Remember to:\"\n";
        $script .= "echo \"  1. Deploy static content: bin/magento setup:static-content:deploy\"\n";
        $script .= "echo \"  2. Clear cache: bin/magento cache:clean\"\n";
        $script .= "echo \"  3. Test frontend performance\"\n";
        $script .= "echo \"  4. Monitor Core Web Vitals\"\n";
        
        return $script;
    }
    
    private function generateFrontendCommands(Recommendation $rec): string
    {
        $script = "";
        $title = $rec->getTitle();
        $details = $rec->getDetails();
        
        $script .= $this->echoLine('📋 ' . $title);
        $script .= "echo \"" . str_repeat('-', strlen($title) + 5) . "\"\n";
        
        if (stripos($title, 'minif') !== false) {
            $script .= $this->generateMinificationCommands($rec);
        } elseif (stripos($title, 'bundl') !== false) {
            $script .= $this->generateBundlingCommands($rec);
        } elseif (stripos($title, 'static content') !== false) {
            $script .= $this->generateStaticContentCommands($rec);
        } elseif (stripos($title, 'image') !== false) {
            $script .= $this->generateImageOptimizationCommands($rec);
        } elseif (stripos($title, 'cacheable') !== false) {
            $script .= $this->generateCacheableCommands($rec);
        } else {
            $script .= $this->generateGenericFrontendCommands($rec);
        }
        
        $script .= "echo \"\"\n\n";
        
        return $script;
    }
    
    private function generateMinificationCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Configure minification settings\n";
        $script .= "echo \"  → Enabling minification...\"\n";
        $script .= "\n";
        $script .= "# Enable CSS minification\n";
        $script .= "php bin/magento config:set dev/css/minify_files 1\n";
        $script .= "echo \"    ✓ CSS minification enabled\"\n";
        $script .= "\n";
        $script .= "# Enable JS minification\n";
        $script .= "php bin/magento config:set dev/js/minify_files 1\n";
        $script .= "echo \"    ✓ JS minification enabled\"\n";
        $script .= "\n";
        $script .= "# Enable HTML minification\n";
        $script .= "php bin/magento config:set dev/template/minify_html 1\n";
        $script .= "echo \"    ✓ HTML minification enabled\"\n";
        
        return $script;
    }
    
    private function generateBundlingCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Configure bundling settings\n";
        $script .= "echo \"  → Reviewing bundling configuration...\"\n";
        $script .= "\n";
        
        if (stripos($rec->getDetails(), 'disable') !== false || stripos($rec->getDetails(), 'reconsider') !== false) {
            $script .= "# Disable problematic bundling\n";
            $script .= "php bin/magento config:set dev/js/merge_files 0\n";
            $script .= "echo \"    ✓ JS merging disabled (better for HTTP/2)\"\n";
            $script .= "\n";
            $script .= "php bin/magento config:set dev/css/merge_css_files 0\n";
            $script .= "echo \"    ✓ CSS merging disabled (better for HTTP/2)\"\n";
            $script .= "\n";
            $script .= "php bin/magento config:set dev/js/enable_js_bundling 0\n";
            $script .= "echo \"    ✓ JS bundling disabled (prevents large bundles)\"\n";
        } else {
            $script .= "# Configure optimal bundling\n";
            $script .= "echo \"    → Manual bundling configuration required\"\n";
            $script .= "echo \"    → Consider RequireJS optimization or modern bundling tools\"\n";
        }
        
        return $script;
    }
    
    private function generateStaticContentCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Static content optimization\n";
        $script .= "echo \"  → Optimizing static content deployment...\"\n";
        $script .= "\n";
        $script .= "# Disable on-demand generation in production\n";
        $script .= "if [ \"\$MAGE_MODE\" = \"production\" ] || [ \"\$(php bin/magento deploy:mode:show)\" = \"production\" ]; then\n";
        $script .= "    echo \"    → Production mode detected\"\n";
        $script .= "    \n";
        $script .= "    # Deploy static content\n";
        $script .= "    echo \"    → Deploying static content...\"\n";
        $script .= "    php bin/magento setup:static-content:deploy -f\n";
        $script .= "    echo \"    ✓ Static content deployed\"\n";
        $script .= "    \n";
        $script .= "    # Set proper permissions\n";
        $script .= "    echo \"    → Setting permissions...\"\n";
        $script .= "    find pub/static -type f -exec chmod 644 {} \\;\n";
        $script .= "    find pub/static -type d -exec chmod 755 {} \\;\n";
        $script .= "    echo \"    ✓ Permissions set\"\n";
        $script .= "else\n";
        $script .= "    echo \"    → Development mode - static content deployment optional\"\n";
        $script .= "fi\n";
        
        return $script;
    }
    
    private function generateImageOptimizationCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Image optimization\n";
        $script .= "echo \"  → Checking for image optimization opportunities...\"\n";
        $script .= "\n";
        
        if ($rec->hasFiles()) {
            $script .= "# Found images to optimize\n";
            $script .= "echo \"    → Found images that need optimization\"\n";
            $script .= "echo \"    → Consider using tools like:\"\n";
            $script .= "echo \"      - imagemin for automated optimization\"\n";
            $script .= "echo \"      - WebP conversion for modern browsers\"\n";
            $script .= "echo \"      - Proper responsive image implementation\"\n";
        } else {
            $script .= "echo \"    → Manual review of images recommended\"\n";
            $script .= "echo \"      Check: pub/media/, pub/static/ for large images\"\n";
        }
        
        $script .= "\n";
        $script .= "# Enable image optimization in Magento\n";
        $script .= "if php bin/magento module:status | grep -q \"Magento_WebP\"; then\n";
        $script .= "    echo \"    → WebP module available\"\n";
        $script .= "    php bin/magento config:set system/upload_configuration/webp_quality 85\n";
        $script .= "    echo \"    ✓ WebP quality configured\"\n";
        $script .= "fi\n";
        
        return $script;
    }
    
    private function generateCacheableCommands(Recommendation $rec): string
    {
        $script = "";
        
        $script .= "# Fix cacheable=\"false\" issues\n";
        $script .= "echo \"  → Addressing caching issues...\"\n";
        $script .= "\n";
        
        if ($rec->hasFiles()) {
            $script .= "# Files with cacheable=\"false\" found\n";
            $script .= "echo \"    ⚠ Found files with cacheable='false' - manual review required\"\n";
            $script .= "echo \"    → Check these files:\"\n";
            
            foreach (array_slice($rec->getFiles(), 0, 5) as $file) {
                $script .= "echo \"      - " . basename($file) . "\"\n";
            }
            
            if (count($rec->getFiles()) > 5) {
                $script .= "echo \"      - ... and " . (count($rec->getFiles()) - 5) . " more files\"\n";
            }
            
            $script .= "echo \"\"\n";
            $script .= "echo \"    → Action required:\"\n";
            $script .= "echo \"      1. Remove cacheable='false' where possible\"\n";
            $script .= "echo \"      2. Use private content for user-specific data\"\n";
            $script .= "echo \"      3. Consider AJAX for dynamic content\"\n";
        }
        
        return $script;
    }
    
    private function generateGenericFrontendCommands(Recommendation $rec): string
    {
        $script = "";
        
        // No automated action: deleting pub/static/_cache and var/view_preprocessed
        // for unrelated findings (N+1 queries, plugin counts...) fixed nothing and
        // reported a misleading "✓"
        $script .= "echo \"  → Manual review required for this optimization\"\n";
        $script .= "echo \"      See analysis report for specific details\"\n";
        
        return $script;
    }
}
