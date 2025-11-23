<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class LayoutCacheAnalyzer implements AnalyzerInterface
{
    private string $magentoRoot;
    private RecommendationCollector $collector;

    private array $coreVendors = [
        'magento',
        'adobe',
        'temando',
        'klarna',
        'dotmailer',
        'vertex',
        'yotpo'
    ];

    public function __construct(string $magentoRoot, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $this->checkCacheableFalse();
    }

    private function checkCacheableFalse(): void
    {
        $layoutPaths = [
            '/app/code/*/*/view/*/layout/*.xml',    // Custom modules
            '/app/design/*/*/*/layout/*.xml',       // Themes
        ];

        $problematicFiles = [];

        // Check custom modules and themes
        foreach ($layoutPaths as $pattern) {
            $files = glob($this->magentoRoot . $pattern, GLOB_NOSORT);
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                $this->analyzeLayoutFile($file, $problematicFiles);
            }
        }

        // Check third-party vendor modules (excluding core)
        $this->checkThirdPartyVendorLayouts($problematicFiles);

        // Report findings
        $this->reportProblematicFiles($problematicFiles);
    }

    private function checkThirdPartyVendorLayouts(array &$problematicFiles): void
    {
        $vendorPath = $this->magentoRoot . '/vendor';
        if (!is_dir($vendorPath)) {
            return;
        }

        foreach (glob($vendorPath . '/*', GLOB_ONLYDIR) as $vendorDir) {
            $vendorName = basename($vendorDir);

            // Skip Magento/Adobe core vendors
            if (in_array(strtolower($vendorName), $this->coreVendors)) {
                continue;
            }

            // Look for layout files in this vendor's packages
            foreach (glob($vendorDir . '/*/view/*/layout/*.xml', GLOB_NOSORT) as $file) {
                $this->analyzeLayoutFile($file, $problematicFiles);
            }
        }
    }

    private function analyzeLayoutFile(string $file, array &$problematicFiles): void
    {
        if (!is_readable($file)) {
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }

        // Check for cacheable="false"
        if (preg_match('/cacheable\s*=\s*["\']false["\']/i', $content)) {
            $relativePath = str_replace($this->magentoRoot, '', $file);

            // Categorize by file type and severity
            if (basename($file) === 'default.xml') {
                $problematicFiles['critical'][] = [
                    'path' => $relativePath,
                    'type' => 'default.xml',
                    'source' => $this->getSourceType($relativePath)
                ];
            } else {
                $problematicFiles['warning'][] = [
                    'path' => $relativePath,
                    'type' => 'layout',
                    'source' => $this->getSourceType($relativePath)
                ];
            }
        }
    }

    private function getSourceType(string $path): string
    {
        if (strpos($path, '/app/code/') !== false) {
            return 'custom_module';
        } elseif (strpos($path, '/app/design/') !== false) {
            return 'theme';
        } elseif (strpos($path, '/vendor/') !== false) {
            return 'third_party_extension';
        }
        return 'unknown';
    }

    private function reportProblematicFiles(array $problematicFiles): void
    {
        // Report critical issues (default.xml files)
        if (!empty($problematicFiles['critical'])) {
            $details = "⚠️  CRITICAL: Using cacheable=\"false\" in default.xml DISABLES CACHING FOR ALL PAGES!\n\n";
            $details .= "Files affected:\n";

            foreach ($problematicFiles['critical'] as $file) {
                $details .= "  - {$file['path']} ({$file['source']})\n";
            }

            $details .= "\n🔧 This is a critical performance issue that affects your entire site.\n";
            $details .= "Action required:\n";
            $details .= "1. Remove cacheable=\"false\" from default.xml files\n";
            $details .= "2. Move non-cacheable blocks to specific page layouts\n";
            $details .= "3. Use private content or AJAX for dynamic data\n";
            $details .= "4. Test thoroughly after changes";

            $this->collector->add(
                'caching',
                'Critical: cacheable="false" in default.xml',
                Recommendation::PRIORITY_HIGH,
                $details
            );
        }

        // Report other cacheable="false" usage
        if (!empty($problematicFiles['warning'])) {
            $fileCount = count($problematicFiles['warning']);

            // Group by source type
            $bySource = [];
            foreach ($problematicFiles['warning'] as $file) {
                $bySource[$file['source']][] = $file['path'];
            }

            $details = "Found cacheable=\"false\" in $fileCount layout files from custom/third-party code.\n";
            $details .= "This disables Full Page Cache for affected pages.\n\n";

            foreach ($bySource as $sourceType => $files) {
                $details .= ucwords(str_replace('_', ' ', $sourceType)) . ":\n";
                foreach (array_slice($files, 0, 3) as $file) {
                    $details .= "  - $file\n";
                }
                if (count($files) > 3) {
                    $details .= "  - ... and " . (count($files) - 3) . " more\n";
                }
                $details .= "\n";
            }

            $details .= "💡 Better alternatives:\n";
            $details .= "- Use private content for user-specific data\n";
            $details .= "- Use AJAX for dynamic content\n";
            $details .= "- Use ESI blocks with Varnish\n";
            $details .= "- Consider if caching is really incompatible";

            $this->collector->add(
                'caching',
                'Review cacheable="false" in custom code',
                Recommendation::PRIORITY_MEDIUM,
                $details
            );
        }
    }
}
