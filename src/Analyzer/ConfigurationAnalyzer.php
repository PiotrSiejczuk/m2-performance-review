<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Trait\DevModeAwareTrait;

class ConfigurationAnalyzer implements AnalyzerInterface
{
    use DevModeAwareTrait;

    private string $magentoRoot;
    private array $coreConfig;
    private bool $isAdobeCommerce;
    private RecommendationCollector $collector;

    public function __construct(string $magentoRoot, array $coreConfig, bool $isAdobeCommerce, RecommendationCollector $collector)
    {
        $this->magentoRoot = $magentoRoot;
        $this->coreConfig = $coreConfig;
        $this->isAdobeCommerce = $isAdobeCommerce;
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $this->checkProductionMode();
        $this->checkCacheConfiguration();
        $this->checkAsyncEmailSending();
        $this->checkAsyncGridIndexing();
        $this->analyzeConfig();
    }

    private function checkProductionMode(): void
    {
        // Skip if already in production/default mode
        if ($this->getMagentoMode() !== 'developer') {
            return;
        }

        // Handle developer mode based on awareness
        if ($this->isDevModeAware) {
            // Acknowledged dev mode - provide helpful dev tips instead
            $this->collector->add(
                'config',
                'Developer Mode - Analysis adjusted',
                Recommendation::PRIORITY_LOW,
                'Running in Developer Mode with adjusted recommendations.',
                'Developer Mode active with awareness enabled. Key behaviors: ' .
                '• Automatic code generation (no compilation needed) ' .
                '• Detailed error reporting (good for debugging) ' .
                '• Symlinks for static content (instant updates) ' .
                '• Some caches disabled by default. ' .
                'For production deployment, use: bin/magento deploy:mode:set production'
            );
        } else {
            // Not dev-aware - standard warning
            $this->collector->add(
                'config',
                'Switch from developer mode to production mode',
                Recommendation::PRIORITY_HIGH,
                'Developer mode significantly impacts performance and should not be used in production.',
                'Developer mode causes 5-10x slower page loads due to: ' .
                '• No static content deployment (generated on-the-fly) ' .
                '• Disabled caching layers ' .
                '• Real-time compilation overhead ' .
                '• Verbose error logging. ' .
                'Switch with: bin/magento deploy:mode:set production ' .
                'Or if this is development, run with --allow-dev-mode flag.'
            );
        }
    }

    private function checkCacheConfiguration(): void
    {
        // Different recommendations based on mode
        if ($this->isInDeveloperMode()) {
            $this->checkDeveloperCacheSettings();
        } else {
            $this->checkProductionCacheSettings();
        }
    }

    private function checkAsyncEmailSending(): void
    {
        // Skip async email check in dev mode - emails often need immediate sending for testing
        if ($this->isInDeveloperMode()) {
            return;
        }

        if ($this->getConfigValue('sales_email/general/async_sending') !== '1') {
            $this->collector->add(
                'config',
                'Enable asynchronous sending of sales emails',
                Recommendation::PRIORITY_MEDIUM,
                'Synchronous email sending during checkout can slow down the process.',
                'Async email sending moves email processing to background queue, reducing checkout time by 1-3 seconds. ' .
                'Emails are sent via cron instead of during customer wait time. Improves conversion rates. ' .
                'Enable with: bin/magento config:set sales_email/general/async_sending 1'
            );
        }
    }

    private function checkAsyncGridIndexing(): void
    {
        // Async grid indexing is beneficial even in dev mode
        if ($this->getConfigValue('dev/grid/async_indexing') !== '1') {
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;

            $this->collector->add(
                'config',
                'Enable asynchronous grid indexing',
                $priority,
                'Async grid indexing improves admin performance by deferring data indexing.',
                'Admin grids (orders, products, customers) can be slow with large datasets. Async indexing moves ' .
                'grid preparation to background, making admin pages load faster. Especially important for stores ' .
                'with 10k+ orders. Enable with: bin/magento config:set dev/grid/async_indexing 1'
            );
        }
    }

    private function analyzeConfig(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return;
        }

        $env = include $envPath;

        // Skip mode check here since checkProductionMode() handles it

        // Session storage check
        if (($env['session']['save'] ?? '') === 'files') {
            $this->collector->add(
                'config',
                'Use Redis for session storage',
                Recommendation::PRIORITY_MEDIUM,
                'File-based session storage can be slow. Consider using Redis for better performance.',
                'File sessions cause I/O bottleneck and don\'t scale across multiple servers. Redis provides: ' .
                'in-memory speed, session locking support, automatic expiration, and horizontal scaling capability. ' .
                'Configure with: bin/magento setup:config:set --session-save=redis --session-save-redis-host=127.0.0.1'
            );
        }

        // Redis cache check
        $hasRedis = false;
        foreach ($env['cache']['frontend'] ?? [] as $frontend) {
            if (stripos($frontend['backend'] ?? '', 'redis') !== false) {
                $hasRedis = true;
                break;
            }
        }

        if (!$hasRedis) {
            $this->collector->add(
                'config',
                'Configure Redis for cache storage',
                Recommendation::PRIORITY_HIGH,
                'Using Redis for cache storage can significantly improve performance.',
                'Redis provides 10-50x faster cache operations than file storage. Benefits include: atomic operations, ' .
                'data persistence, memory efficiency, and built-in expiration. Essential for production performance. ' .
                'Configure with: bin/magento setup:config:set --cache-backend=redis --cache-backend-redis-server=127.0.0.1'
            );
        }

        $this->checkCoreConfigSettings();
    }

    private function checkCoreConfigSettings(): void
    {
        if (empty($this->coreConfig)) {
            return;
        }

        // Adobe Commerce specific checks
        if ($this->isAdobeCommerce) {
            if ($this->getConfigValue('customer/magento_customersegment/real_time_validation') === '1') {
                $this->collector->add(
                    'config',
                    'Disable real-time customer segment validation',
                    Recommendation::PRIORITY_MEDIUM,
                    'Real-time validation can impact performance if you have many segments.',
                    'Real-time segment validation runs complex queries on every page load. With 10+ segments, this adds ' .
                    '200-500ms per request. Batch processing via cron is more efficient. ' .
                    'Disable with: bin/magento config:set customer/magento_customersegment/real_time_validation 0'
                );
            }
        }
    }

    private function checkDeveloperCacheSettings(): void
    {
        // Get current cache status
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) return;

        $env = include $envPath;
        $cacheTypes = $env['cache_types'] ?? [];

        // In dev mode, some caches disabled is normal
        $recommendedEnabled = ['config', 'db_ddl', 'compiled_config', 'eav'];
        $canDisable = ['layout', 'block_html', 'full_page', 'view_files_fallback'];

        $shouldEnable = [];
        foreach ($recommendedEnabled as $cache) {
            if (!isset($cacheTypes[$cache]) || $cacheTypes[$cache] != 1) {
                $shouldEnable[] = $cache;
            }
        }

        if (!empty($shouldEnable)) {
            $this->collector->add(
                'config',
                'Enable recommended caches for development',
                Recommendation::PRIORITY_LOW,
                'Some caches should remain enabled even in development: ' . implode(', ', $shouldEnable),
                'Even in development, certain caches improve performance without hindering workflow: ' .
                '• config: Prevents XML parsing on every request ' .
                '• db_ddl: Caches database schema ' .
                '• compiled_config: Speeds up configuration loading. ' .
                'Enable with: bin/magento cache:enable ' . implode(' ', $shouldEnable)
            );
        }
    }

    private function checkProductionCacheSettings(): void
    {
        // Original production logic - all caches must be enabled
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) return;

        $env = include $envPath;
        $cacheTypes = $env['cache_types'] ?? [];

        $disabledCaches = [];
        foreach ($cacheTypes as $type => $enabled) {
            if ($enabled != 1) {
                $disabledCaches[] = $type;
            }
        }

        if (!empty($disabledCaches)) {
            $this->collector->add(
                'config',
                'Enable all cache types',
                Recommendation::PRIORITY_HIGH,
                'Disabled caches detected: ' . implode(', ', $disabledCaches),
                'All caches must be enabled in production for optimal performance. ' .
                'Disabled caches cause significant performance degradation. ' .
                'Enable all with: bin/magento cache:enable'
            );
        }
    }

    private function getConfigValue(string $path, mixed $default = null): mixed
    {
        return $this->coreConfig[$path] ?? $default;
    }

    private function checkMagentoSecureUrls(): void
    {
        $configLoader = new EnhancedConfigLoader($this->magentoRoot);
        
        // Check if HTTPS is enforced with proper configuration hierarchy
        $useSecureUrls = $configLoader->getConfigValue('web/secure/use_in_frontend', '0');
        $useSecureUrlsAdmin = $configLoader->getConfigValue('web/secure/use_in_adminhtml', '0');
        $baseUrl = $configLoader->getConfigValue('web/unsecure/base_url', '');
        $secureBaseUrl = $configLoader->getConfigValue('web/secure/base_url', '');

        // Debug information for troubleshooting
        $this->addDebugInfo('HTTPS Configuration Debug', [
            'web/secure/use_in_frontend' => $useSecureUrls,
            'web/secure/use_in_adminhtml' => $useSecureUrlsAdmin,
            'web/unsecure/base_url' => $baseUrl,
            'web/secure/base_url' => $secureBaseUrl
        ]);

        // Check frontend HTTPS enforcement
        if ($useSecureUrls !== '1') {
            $this->collector->add(
                'protocol',
                'Enable secure URLs for frontend',
                Recommendation::PRIORITY_HIGH,
                "HTTPS is not enforced for frontend (current value: '{$useSecureUrls}'). Set: bin/magento config:set web/secure/use_in_frontend 1",
                'Without secure frontend URLs, customer data (passwords, payment info, PII) transmits over HTTP. ' .
                'This violates PCI compliance, GDPR, and allows man-in-the-middle attacks. Modern browsers show ' .
                '"Not Secure" warnings. Google penalizes HTTP sites in search rankings. Always use HTTPS in production.'
            );
        }

        // Check admin HTTPS enforcement
        if ($useSecureUrlsAdmin !== '1') {
            $this->collector->add(
                'protocol',
                'Enable secure URLs for admin',
                Recommendation::PRIORITY_HIGH,
                "HTTPS is not enforced for admin (current value: '{$useSecureUrlsAdmin}'). Set: bin/magento config:set web/secure/use_in_adminhtml 1",
                'Admin panel must use HTTPS to protect administrative credentials and operations. Without HTTPS, ' .
                'admin sessions can be hijacked, allowing full store compromise. This is the #1 security requirement. ' .
                'Also enable admin URL secret key: bin/magento config:set admin/security/use_form_key 1'
            );
        }

        // Check base URL configuration
        if (!empty($baseUrl) && strpos($baseUrl, 'http://') === 0 && 
            (empty($secureBaseUrl) || strpos($secureBaseUrl, 'https://') !== 0)) {
            
            $this->collector->add(
                'protocol',
                'Configure secure base URL',
                Recommendation::PRIORITY_HIGH,
                "Secure base URL not properly configured. Current: '{$secureBaseUrl}'. Set: bin/magento config:set web/secure/base_url https://yourdomain.com/",
                'Magento needs separate secure URL configuration. Without it, HTTPS redirects fail, mixed content ' .
                'warnings appear, and payment gateways reject requests. Ensure URL ends with / and matches SSL certificate.'
            );
        }

        // Check additional HTTPS-related settings
        $this->checkAdditionalHttpsSettings($configLoader);
    }

    private function checkAdditionalHttpsSettings(EnhancedConfigLoader $configLoader): void
    {
        // Check upgrade insecure requests
        $upgradeInsecure = $configLoader->getConfigValue('web/secure/upgrade_insecure_requests', '0');
        $useSecureUrls = $configLoader->getConfigValue('web/secure/use_in_frontend', '0');

        if ($upgradeInsecure !== '1' && $useSecureUrls === '1') {
            $this->collector->add(
                'protocol',
                'Enable upgrade insecure requests',
                Recommendation::PRIORITY_MEDIUM,
                'Enable automatic HTTP→HTTPS upgrade: bin/magento config:set web/secure/upgrade_insecure_requests 1',
                'This adds Content-Security-Policy: upgrade-insecure-requests header, automatically converting ' .
                'HTTP resources to HTTPS. Prevents mixed content warnings and improves security. Works alongside HSTS ' .
                'for complete HTTPS enforcement. Supported by all modern browsers.'
            );
        }

        // Check offloader header configuration
        $offloaderHeader = $configLoader->getConfigValue('web/secure/offloader_header', '');

        if (empty($offloaderHeader) && $this->isUsingLoadBalancer()) {
            $this->collector->add(
                'protocol',
                'Configure HTTPS offloader header',
                Recommendation::PRIORITY_MEDIUM,
                'Set offloader header for load balancer/proxy: bin/magento config:set web/secure/offloader_header X-Forwarded-Proto',
                'When using load balancer/CDN with SSL termination, Magento must detect HTTPS from proxy headers. ' .
                'Without this, Magento thinks all requests are HTTP, causing redirect loops and insecure cookies. ' .
                'Common headers: X-Forwarded-Proto (standard), X-Forwarded-Ssl, X-Url-Scheme. Check your LB docs.'
            );
        }
    }

    private function addDebugInfo(string $title, array $data): void
    {
        // Only add debug info in development or when explicitly requested
        if (getenv('M2PERFORMANCE_DEBUG') === '1') {
            $details = "Debug information:\n";
            foreach ($data as $key => $value) {
                $details .= "  {$key}: " . var_export($value, true) . "\n";
            }
            
            $this->collector->add(
                'debug',
                $title,
                Recommendation::PRIORITY_LOW,
                $details,
                'This debug information helps troubleshoot configuration detection issues.'
            );
        }
    }
}
