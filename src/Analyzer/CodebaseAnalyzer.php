<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Trait\DevModeAwareTrait;

class CodebaseAnalyzer implements AnalyzerInterface
{
    use DevModeAwareTrait;

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
        $this->analyzeCodebase();
    }

    private function analyzeCodebase(): void
    {
        // Check custom code volume in app/code (less critical in dev mode)
        $appCodePath = $this->magentoRoot . '/app/code';
        if (is_dir($appCodePath)) {
            $customNamespaces = array_diff(scandir($appCodePath), ['.', '..']);
            if (count($customNamespaces) > 0) {
                $totalFiles = $this->countFilesInDirectory($appCodePath);

                // Higher threshold in dev mode, lower priority
                $threshold = $this->isInDeveloperMode() ? 2000 : 1000;
                $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;

                if ($totalFiles > $threshold) {
                    $message = $this->isInDeveloperMode()
                        ? 'Large custom codebase detected - consider reviewing for production readiness'
                        : 'Review custom code volume';

                    $this->collector->add(
                        'codebase',
                        $message,
                        $priority,
                        "You have around $totalFiles files in app/code. Large amounts of custom code can impact performance.",
                        $this->isInDeveloperMode()
                            ? 'In development mode, large codebases mainly affect deployment time. For production, focus on code optimization, caching strategies, and removing unused modules.'
                            : 'Large custom codebases can slow down autoloading, class compilation, and deployment processes.'
                    );
                }
            }
        }

        // Check for observers (events.xml) - compilation warnings not relevant in dev
        $observerCount = $this->countCustomObservers();
        $observerThreshold = $this->isInDeveloperMode() ? 25 : 15;

        if ($observerCount > $observerThreshold) {
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;
            $this->collector->add(
                'codebase',
                'Optimize event observers in custom code',
                $priority,
                "Found $observerCount event configuration files in custom/third-party code. Excessive event observers can impact performance.",
                $this->isInDeveloperMode()
                    ? 'Event observers in dev mode mainly affect page generation time. Review for production optimization.'
                    : 'Each observer adds processing time to events. Consider consolidating observers or making them more efficient.'
            );
        }

        // Check for plugins (di.xml) - less critical in dev mode
        $pluginCount = $this->countCustomPlugins();
        $pluginThreshold = $this->isInDeveloperMode() ? 35 : 20;

        if ($pluginCount > $pluginThreshold) {
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;
            $this->collector->add(
                'codebase',
                'Review plugin usage in custom code',
                $priority,
                "Found $pluginCount plugin configurations in custom/third-party code. Excessive plugins can impact performance.",
                $this->isInDeveloperMode()
                    ? 'Plugins in dev mode affect method interception overhead. For production, focus on optimizing plugin logic and reducing unnecessary interceptions.'
                    : 'Each plugin adds method interception overhead. Review if all plugins are necessary and optimize their logic.'
            );
        }

        // Check for preferences (di.xml) - same logic applies
        $preferencesCount = $this->countCustomPreferences();
        $preferencesThreshold = $this->isInDeveloperMode() ? 15 : 10;

        if ($preferencesCount > $preferencesThreshold) {
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;
            $this->collector->add(
                'codebase',
                'Review preference usage in custom code',
                $priority,
                "Found $preferencesCount preference configurations in custom/third-party code. Excessive class preferences can cause performance issues.",
                $this->isInDeveloperMode()
                    ? 'Class preferences in dev mode affect object instantiation. Review for potential conflicts and ensure compatibility.'
                    : 'Class preferences override core classes and can cause unexpected behavior and performance issues.'
            );
        }

        // Check for large PHP files (>50KB) - less critical in dev mode
        if (!$this->isInDeveloperMode()) {
            $this->checkLargeCustomFiles();
        }
    }

    private function countCustomObservers(): int
    {
        $count = 0;

        // Check app/code
        $count += $this->countFilesMatchingPattern($this->magentoRoot . '/app/code', '/events\.xml$/i');

        // Check third-party vendor modules (excluding core)
        $count += $this->countThirdPartyFiles('/events\.xml$/i');

        return $count;
    }

    private function countCustomPlugins(): int
    {
        $count = 0;

        // Check app/code for di.xml files and count plugins
        $count += $this->countPluginsInPath($this->magentoRoot . '/app/code');

        // Check third-party vendor modules (excluding core)
        $count += $this->countThirdPartyPlugins();

        return $count;
    }

    private function countCustomPreferences(): int
    {
        $count = 0;

        // Check app/code for di.xml files and count preferences
        $count += $this->countPreferencesInPath($this->magentoRoot . '/app/code');

        // Check third-party vendor modules (excluding core)
        $count += $this->countThirdPartyPreferences();

        return $count;
    }

    private function countPluginsInPath(string $path): int
    {
        $count = 0;
        $diFiles = glob($path . '/*/*/etc/di.xml', GLOB_NOSORT);

        foreach ($diFiles as $file) {
            if (!is_readable($file)) continue;

            $content = file_get_contents($file);
            if ($content === false) continue;

            // Count <plugin> tags properly
            preg_match_all('/<plugin\s+[^>]*name\s*=\s*["\'][^"\']+["\'][^>]*>/i', $content, $matches);
            $count += count($matches[0]);
        }

        return $count;
    }

    private function countPreferencesInPath(string $path): int
    {
        $count = 0;
        $diFiles = glob($path . '/*/*/etc/di.xml', GLOB_NOSORT);

        foreach ($diFiles as $file) {
            if (!is_readable($file)) continue;

            $content = file_get_contents($file);
            if ($content === false) continue;

            // Count <preference> tags
            preg_match_all('/<preference\s+[^>]*for\s*=\s*["\'][^"\']+["\'][^>]*>/i', $content, $matches);
            $count += count($matches[0]);
        }

        return $count;
    }

    private function countThirdPartyFiles(string $pattern): int
    {
        $count = 0;
        $vendorPath = $this->magentoRoot . '/vendor';

        if (!is_dir($vendorPath)) {
            return 0;
        }

        foreach (glob($vendorPath . '/*', GLOB_ONLYDIR) as $vendorDir) {
            $vendorName = basename($vendorDir);

            // Skip Magento/Adobe core vendors
            if (in_array(strtolower($vendorName), $this->coreVendors)) {
                continue;
            }

            // Look for files in this vendor's packages
            $files = glob($vendorDir . '/*' . str_replace('/\\', '/', $pattern), GLOB_NOSORT);
            $count += count($files);
        }

        return $count;
    }

    private function countThirdPartyPlugins(): int
    {
        $count = 0;
        $vendorPath = $this->magentoRoot . '/vendor';

        if (!is_dir($vendorPath)) {
            return 0;
        }

        foreach (glob($vendorPath . '/*', GLOB_ONLYDIR) as $vendorDir) {
            $vendorName = basename($vendorDir);

            // Skip Magento/Adobe core vendors
            if (in_array(strtolower($vendorName), $this->coreVendors)) {
                continue;
            }

            $count += $this->countPluginsInPath($vendorDir);
        }

        return $count;
    }

    private function countThirdPartyPreferences(): int
    {
        $count = 0;
        $vendorPath = $this->magentoRoot . '/vendor';

        if (!is_dir($vendorPath)) {
            return 0;
        }

        foreach (glob($vendorPath . '/*', GLOB_ONLYDIR) as $vendorDir) {
            $vendorName = basename($vendorDir);

            // Skip Magento/Adobe core vendors
            if (in_array(strtolower($vendorName), $this->coreVendors)) {
                continue;
            }

            $count += $this->countPreferencesInPath($vendorDir);
        }

        return $count;
    }

    private function checkLargeCustomFiles(): void
    {
        $largeFiles = [];
        $largeFileThreshold = 50000; // 50KB

        // Check app/code
        $this->findLargeFiles($this->magentoRoot . '/app/code', $largeFileThreshold, $largeFiles);

        // Check third-party vendors
        $this->findLargeThirdPartyFiles($largeFileThreshold, $largeFiles);

        if (count($largeFiles) > 5) {
            // Sort by size descending
            usort($largeFiles, fn($a, $b) => $b['size'] - $a['size']);

            $filePaths = array_column($largeFiles, 'path');
            $examples = array_slice($largeFiles, 0, 3);

            $details = sprintf("Found %d PHP files larger than 50KB in custom/third-party code.\n\n", count($largeFiles));
            $details .= "Largest files:\n";
            foreach ($examples as $file) {
                $details .= sprintf("  - %s (%.1fKB)\n", basename($file['path']), $file['size'] / 1024);
            }

            if (count($largeFiles) > 3) {
                $details .= sprintf("  - ... and %d more\n", count($largeFiles) - 3);
            }

            $details .= "\n💡 Full list of %d files available with --export";

            $this->collector->addWithFiles(
                'codebase',
                'Optimize large PHP files in custom code',
                Recommendation::PRIORITY_MEDIUM,
                sprintf($details, count($largeFiles)),
                $filePaths,
                'Large PHP files increase memory usage and can slow down autoloading. Consider breaking them into smaller, more focused classes. ' .
                'Files over 100KB are particularly problematic and should be refactored.',
                [
                    'total_large_files' => count($largeFiles),
                    'largest_file_kb' => $largeFiles[0]['size'] / 1024,
                    'threshold_kb' => $largeFileThreshold / 1024
                ]
            );
        }
    }

    private function countLargeFilesInPath(string $path, int $threshold): int
    {
        $count = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() &&
                    $file->getExtension() === 'php' &&
                    $file->getSize() > $threshold) {
                    $count++;
                }
            }
        } catch (\Exception $e) {
            // Skip on permission issues
        }

        return $count;
    }

    private function countLargeThirdPartyFiles(int $threshold): int
    {
        $count = 0;
        $vendorPath = $this->magentoRoot . '/vendor';

        if (!is_dir($vendorPath)) {
            return 0;
        }

        foreach (glob($vendorPath . '/*', GLOB_ONLYDIR) as $vendorDir) {
            $vendorName = basename($vendorDir);

            // Skip Magento/Adobe core vendors
            if (in_array(strtolower($vendorName), $this->coreVendors)) {
                continue;
            }

            $count += $this->countLargeFilesInPath($vendorDir, $threshold);
        }

        return $count;
    }

    private function countFilesInDirectory(string $directory): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    private function countFilesMatchingPattern(string $directory, string $pattern): int
    {
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filename = $file->getFilename();
                if (preg_match($pattern, $filename)) {
                    $count++;
                }
            }
        } catch (\Exception $e) {
            // Skip on any error
        }

        return $count;
    }

    private function findLargeFiles(string $path, int $threshold, array &$largeFiles): void
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() &&
                    $file->getExtension() === 'php' &&
                    $file->getSize() > $threshold) {

                    $largeFiles[] = [
                        'path' => str_replace($this->magentoRoot, '', $file->getPathname()),
                        'size' => $file->getSize()
                    ];
                }
            }
        } catch (\Exception $e) {
            // Skip on permission issues
        }
    }

    private function findLargeThirdPartyFiles(int $threshold, array &$largeFiles): void
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

            $this->findLargeFiles($vendorDir, $threshold, $largeFiles);
        }
    }
}
