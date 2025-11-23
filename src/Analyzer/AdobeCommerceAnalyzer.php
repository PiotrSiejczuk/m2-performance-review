<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Service\EnvironmentLoader;

class AdobeCommerceAnalyzer implements AnalyzerInterface
{
    private string $magentoRoot;
    private array $coreConfig;
    private bool $isAdobeCommerce;
    private EnvironmentLoader $envLoader;
    private RecommendationCollector $collector;

    public function __construct(
        string $magentoRoot,
        array $coreConfig,
        bool $isAdobeCommerce,
        EnvironmentLoader $envLoader,
        RecommendationCollector $collector
    ) {
        $this->magentoRoot       = rtrim($magentoRoot, "/\\");
        $this->coreConfig        = $coreConfig;
        $this->isAdobeCommerce   = $isAdobeCommerce;
        $this->envLoader         = $envLoader;
        $this->collector         = $collector;
    }

    public function analyze(): void
    {
        $this->analyzeCommerce();
    }

    private function analyzeCommerce(): void
    {
        // Only analyze if this is Adobe Commerce
        if (!$this->isAdobeCommerce) {
            return;
        }

        // 1) Check B2B modules
        $b2bModules = [
        'Magento_SharedCatalog',
        'Magento_NegotiableQuote',
        'Magento_CompanyCredit',
        'Magento_Company',
    ];
        $enabledB2bModules = [];
        $configPath = $this->magentoRoot . '/app/etc/config.php';

        if (file_exists($configPath)) {
        $config = include $configPath;
            if (!empty($config['modules'])) {
            foreach ($b2bModules as $module) {
                if (!empty($config['modules'][$module])) {
                    $enabledB2bModules[] = $module;
                    }
                }
            }
        }

        if (!empty($enabledB2bModules)) {
        $this->collector->add(
            'commerce',
            'Optimize B2B module configuration',
            Recommendation::PRIORITY_MEDIUM,
            'You have the following B2B modules enabled which may impact performance: '
            . implode(', ', $enabledB2bModules)
                . '. Consider optimizing shared catalog and company indexing.'
            );
        }

        // 2) Check Order Archive feature
        $orderArchiveActive = $this->coreConfig['sales/magento_salesarchive/active'] ?? null;
        if ($orderArchiveActive !== '1') {
        $orderCount = $this->getOrderCount();
            if ($orderCount !== null) {
            if ($orderCount > 50000) {
                $this->collector->add(
                    'commerce',
                    'Enable Order Archive feature',
                    Recommendation::PRIORITY_HIGH,
                    "You have approximately {$orderCount} orders. Enabling the Order Archive feature can improve admin performance by moving older completed orders to an archive table."
                );
                } elseif ($orderCount > 10000) {
                $this->collector->add(
                    'commerce',
                    'Consider enabling Order Archive feature',
                    Recommendation::PRIORITY_MEDIUM,
                    "You have approximately {$orderCount} orders. Consider enabling the Order Archive feature to improve admin grid performance."
                );
                }
            } else {
            $this->collector->add(
                'commerce',
                'Consider enabling Order Archive feature',
                Recommendation::PRIORITY_LOW,
                'The Order Archive feature can improve admin performance by moving older completed orders to an archive table.'
            );
            }
        }

        // 3) Check Page Builder usage
        $pageBuilderEnabled = false;
        if (file_exists($configPath)) {
        $config = include $configPath;
            if (!empty($config['modules']['Magento_PageBuilder'])) {
            $pageBuilderEnabled = true;
            }
        }

        if ($pageBuilderEnabled) {
        $lazyLoadEnabled = $this->coreConfig['cms/pagebuilder/lazy_loading'] ?? null;
            if ($lazyLoadEnabled !== '1') {
            $this->collector->add(
                'commerce',
                'Enable Page Builder lazy loading',
                Recommendation::PRIORITY_MEDIUM,
                'Page Builder lazy loading can improve performance by deferring the loading of off-screen content.'
            );
            }
        }

        // 4) Check Content Staging
        $contentStagingEnabled = false;
        if (file_exists($configPath)) {
        $config = include $configPath;
            if (!empty($config['modules']['Magento_Staging'])) {
            $contentStagingEnabled = true;
            }
        }

        if ($contentStagingEnabled) {
        $updateCount = $this->getStagingUpdateCount();
            if ($updateCount !== null) {
            if ($updateCount > 1000) {
                $this->collector->add(
                    'commerce',
                    'Cleanup Content Staging updates',
                    Recommendation::PRIORITY_HIGH,
                    "You have {$updateCount} content staging updates. A large number of updates can impact performance. Consider removing old or unnecessary updates."
                );
                } elseif ($updateCount > 500) {
                $this->collector->add(
                    'commerce',
                    'Review Content Staging updates',
                    Recommendation::PRIORITY_MEDIUM,
                    "You have {$updateCount} content staging updates. Consider regularly cleaning up old or unnecessary updates to maintain performance."
                );
                }
            }
        }
    }

    /**
     * Get total order count from sales_order table
     *
     * @return int|null Number of orders or null if unable
     */
    private function getOrderCount(): ?int
    {
        try {
            $details = $this->envLoader->getDbConfig();
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
                $details['host'] ?? '127.0.0.1',
                $details['port'] ?? '3306',
                $details['dbname'] ?? ''
            );
            $pdo = new \PDO($dsn, $details['username'] ?? '', $details['password'] ?? '');
            $stmt = $pdo->query("SELECT COUNT(*) FROM sales_order");
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get count of non-rollback content staging updates
     *
     * @return int|null Number of staging updates or null if unable
     */
    private function getStagingUpdateCount(): ?int
    {
        try {
            $details = $this->envLoader->getDbConfig();
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
                $details['host'] ?? '127.0.0.1',
                $details['port'] ?? '3306',
                $details['dbname'] ?? ''
            );
            $pdo = new \PDO($dsn, $details['username'] ?? '', $details['password'] ?? '');

            // Check if staging_update table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'staging_update'");
            if (!$stmt->fetchColumn()) {
                return 0;
            }

            $stmt = $pdo->query("SELECT COUNT(*) FROM staging_update WHERE is_rollback = 0");
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return null;
        }
    }
}
