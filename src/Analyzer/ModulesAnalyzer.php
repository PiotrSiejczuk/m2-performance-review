<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class ModulesAnalyzer implements AnalyzerInterface
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
        $this->analyzeModules();
    }

    private function analyzeModules(): void
    {
        $configPath = $this->magentoRoot . '/app/etc/config.php';
        if (!file_exists($configPath)) {
            return;
        }

        $config = include $configPath;
        if (empty($config['modules'])) {
            return;
        }

        $disabledModules = [];
        $unusedModules = [];
        $devModules = [];

        foreach ($config['modules'] as $moduleName => $enabled) {
            if ($enabled == 0) {
                $disabledModules[] = $moduleName;

                // Check if it's a dev module that shouldn't be in production
                if ($this->isDevModule($moduleName)) {
                    $devModules[] = $moduleName;
                }
            }
        }

        // Check composer.json for modules that can be removed
        $composerPath = $this->magentoRoot . '/composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);

            foreach ($disabledModules as $module) {
                $packageName = $this->getComposerPackageName($module);
                if ($packageName && isset($composer['require'][$packageName])) {
                    $unusedModules[$module] = $packageName;
                }
            }
        }

        // Report findings
        if (!empty($disabledModules)) {
            $this->reportDisabledModules($disabledModules, $unusedModules);
        }

        if (!empty($devModules)) {
            $this->reportDevModules($devModules);
        }

        // Check for known performance-impacting modules
        $this->checkProblematicModules($config['modules']);
    }

    private function reportDisabledModules(array $disabledModules, array $unusedModules): void
    {
        $count = count($disabledModules);

        if ($count > 5) {
            $details = sprintf("You have %d disabled modules. These MUST be removed completely as they slow down deployment and composer operations.\n\n", $count);

            // Show modules that can be removed via composer
            if (!empty($unusedModules)) {
                $details .= "Modules to remove with composer:\n";
                $commands = [];
                foreach (array_slice($unusedModules, 0, 5, true) as $module => $package) {
                    $details .= sprintf("  - %s\n", $module);
                    $commands[] = sprintf("composer remove %s", $package);
                }

                if (count($unusedModules) > 5) {
                    $details .= sprintf("  - ... and %d more\n", count($unusedModules) - 5);
                }

                $details .= "\nRun: " . implode(' ', array_slice($commands, 0, 3));
                if (count($commands) > 3) {
                    $details .= " ...";
                }
            }

            $details .= "\n\n💡 Full list of " . count($disabledModules) . " disabled modules available with --export";

            $this->collector->addWithFiles(
                'modules',
                'Remove disabled modules completely',
                Recommendation::PRIORITY_HIGH,
                $details,
                $disabledModules,
                'Disabled modules still exist in vendor/ and affect: composer operations, deployment time, static content deployment, and DI compilation. They provide no value while consuming resources.',
                [
                    'total_disabled' => count($disabledModules),
                    'removable_via_composer' => $unusedModules,
                    'composer_commands' => array_map(function($package) {
                        return "composer remove $package";
                    }, $unusedModules)
                ]
            );
        }
    }

    private function reportDevModules(array $devModules): void
    {
        if (!empty($devModules)) {
            $details = "Found development/sample modules that should not be in production:\n";
            foreach ($devModules as $module) {
                $details .= "  - $module\n";
            }

            $this->collector->add(
                'modules',
                'Remove development modules',
                Recommendation::PRIORITY_HIGH,
                $details,
                'Development and sample data modules increase attack surface and can expose sensitive information. Remove them from production environments.'
            );
        }
    }

    private function checkProblematicModules(array $modules): void
    {
        $problematicModules = [
            'Magento_AdminAnalytics' => 'Sends usage data to Adobe, can be disabled for privacy/performance',
            'Magento_NewRelicReporting' => 'Only needed if using New Relic APM',
            'Magento_GoogleAnalytics' => 'Legacy module, use Google Tag Manager instead',
            'Magento_GoogleAdwords' => 'Legacy module, use Google Tag Manager instead',
            'Magento_Marketplace' => 'Only needed for Marketplace vendors',
            'Magento_AdminNotification' => 'Can be disabled if not using admin notifications',
            'Magento_ProductVideo' => 'Heavy module, only keep if using video features',
            'Magento_Swagger' => 'API documentation, not needed in production',
            'Magento_SwaggerWebapi' => 'API documentation, not needed in production',
            'Magento_SwaggerWebapiAsync' => 'API documentation, not needed in production',
            'Magento_Version' => 'Exposes version information, security risk'
        ];

        $foundProblematic = [];
        foreach ($problematicModules as $module => $reason) {
            if (isset($modules[$module]) && $modules[$module] == 1) {
                $foundProblematic[$module] = $reason;
            }
        }

        if (count($foundProblematic) > 3) {
            $details = "Found modules that may impact performance or security:\n\n";
            foreach (array_slice($foundProblematic, 0, 5, true) as $module => $reason) {
                $details .= sprintf("  - %s\n    %s\n\n", $module, $reason);
            }

            if (count($foundProblematic) > 5) {
                $details .= sprintf("... and %d more modules to review\n", count($foundProblematic) - 5);
            }

            $this->collector->add(
                'modules',
                'Review potentially unnecessary modules',
                Recommendation::PRIORITY_MEDIUM,
                $details,
                'These modules may not be needed in your setup. Each active module adds overhead to deployment, compilation, and runtime performance.'
            );
        }
    }

    private function isDevModule(string $moduleName): bool
    {
        $devPatterns = [
            'SampleData',
            'DevelopmentTools',
            'TestModule',
            'Debug',
            'Demo'
        ];

        foreach ($devPatterns as $pattern) {
            if (stripos($moduleName, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getComposerPackageName(string $moduleName): ?string
    {
        // Convert module name to likely composer package name
        // Magento_Catalog -> magento/module-catalog
        if (strpos($moduleName, 'Magento_') === 0) {
            $name = substr($moduleName, 8);
            $name = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
            return 'magento/module-' . $name;
        }

        // For third-party modules, this would need more complex logic
        // or reading from composer.json
        return null;
    }
}
