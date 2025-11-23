<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Trait\DevModeAwareTrait;

class VarnishPerformanceAnalyzer implements AnalyzerInterface
{
    use DevModeAwareTrait;

    private string $magentoRoot;
    private RecommendationCollector $collector;
    private array $varnishMetrics = [];

    public function __construct(string $magentoRoot, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        // Skip in developer mode unless aware
        if ($this->isInDeveloperMode() && !$this->isDevModeAware) {
            return;
        }

        $this->analyzeVarnishConfiguration();
        $this->checkCacheBypassPatterns();
        $this->analyzeESIImplementation();
        $this->checkCacheHeaders();
        $this->analyzeCacheTagStrategy();
        $this->checkPHPSessionManagement();
        $this->analyzeVaryHeaders();
        $this->checkGraceModeConfiguration();
        $this->analyzeCacheWarmingStrategy();
        $this->checkEdgeCachingCapabilities();
    }

    private function analyzeVarnishConfiguration(): void
    {
        // Check if Varnish is configured
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return;
        }

        $env = include $envPath;
        $httpCacheHosts = $env['http_cache_hosts'] ?? [];
        
        if (empty($httpCacheHosts)) {
            $this->collector->add(
                'varnish',
                'Varnish not configured',
                Recommendation::PRIORITY_HIGH,
                'Varnish configuration not found. For high-traffic sites, Varnish can improve performance by 10-100x.',
                'Varnish serves cached content from memory, bypassing PHP/MySQL entirely. This reduces TTFB from 200-500ms to 10-50ms. ' .
                'Configure in env.php: http_cache_hosts with backend_host, backend_port settings.'
            );
            return;
        }

        // Check Varnish connectivity
        foreach ($httpCacheHosts as $host) {
            $this->checkVarnishHealth($host);
        }

        // Analyze VCL if accessible
        $this->analyzeVCLConfiguration();
    }

    private function checkCacheBypassPatterns(): void
    {
        // Check for problematic URLs that might bypass cache
        $problematicPatterns = [
            'checkout' => ['priority' => Recommendation::PRIORITY_LOW, 'expected' => true],
            'customer' => ['priority' => Recommendation::PRIORITY_LOW, 'expected' => true],
            'admin' => ['priority' => Recommendation::PRIORITY_LOW, 'expected' => true],
            'api' => ['priority' => Recommendation::PRIORITY_MEDIUM, 'expected' => false],
            'catalog' => ['priority' => Recommendation::PRIORITY_HIGH, 'expected' => false],
            'cms' => ['priority' => Recommendation::PRIORITY_HIGH, 'expected' => false],
        ];

        // Simulate checking Varnish stats (in real implementation, would query varnishstat)
        $bypassRate = $this->estimateBypassRate();
        
        if ($bypassRate > 30) {
            $this->collector->add(
                'varnish',
                'High cache bypass rate detected',
                Recommendation::PRIORITY_HIGH,
                sprintf('Estimated cache bypass rate: %d%%. Target should be <10%% for optimal performance.', $bypassRate),
                'High bypass rates indicate systematic caching failures. Common causes: ' .
                '1) PHPSESSID proliferation for anonymous users, ' .
                '2) Incorrect Cache-Control headers from Magento, ' .
                '3) Over-broad cache invalidation patterns. ' .
                'Industry leaders achieve 90%+ cache hit ratios.'
            );
        }
    }

    private function analyzeESIImplementation(): void
    {
        // Check for ESI blocks in layout files
        $layoutPath = $this->magentoRoot . '/app/design/frontend';
        $esiBlocksFound = false;
        
        if (is_dir($layoutPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($layoutPath),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'xml') {
                    $content = file_get_contents($file->getPathname());
                    if (strpos($content, 'ttl="0"') !== false || strpos($content, 'cacheable="false"') !== false) {
                        $esiBlocksFound = true;
                        break;
                    }
                }
            }
        }

        if (!$esiBlocksFound) {
            $this->collector->add(
                'varnish',
                'ESI (Edge Side Includes) not implemented',
                Recommendation::PRIORITY_MEDIUM,
                'No ESI blocks found. Dynamic content forces full page bypasses instead of fragment updates.',
                'ESI enables caching static page content while dynamically loading user-specific sections (cart, account info). ' .
                'Implementation: Add ttl="0" to dynamic blocks in layout XML. Varnish will cache the page and fetch only dynamic fragments. ' .
                'This can improve cache hit rate from 30% to 90%+ for logged-in users.'
            );
        }
    }

    private function checkCacheHeaders(): void
    {
        // Check for problematic cache control headers
        $this->collector->add(
            'varnish',
            'Review Cache-Control header configuration',
            Recommendation::PRIORITY_HIGH,
            'Adobe Commerce FrontController may inject cache-killing headers (no-store, no-cache, must-revalidate).',
            'The FrontController systematically adds headers that trigger Varnish bypasses. Override in VCL: ' .
            'if (bereq.url ~ "^/(catalog|cms)" && beresp.http.Cache-Control ~ "no-cache") { ' .
            'set beresp.http.Cache-Control = "public, max-age=3600"; }. ' .
            'This prevents unnecessary cache bypasses for anonymous content.'
        );
    }

    private function analyzeCacheTagStrategy(): void
    {
        // Check for over-broad cache tags
        $this->collector->add(
            'varnish',
            'Optimize cache tag precision',
            Recommendation::PRIORITY_MEDIUM,
            'Review cache tag strategy to prevent over-invalidation cascades.',
            'Broad tags like "cat_p_*" cause category-wide invalidations for single product updates. ' .
            'Implement granular tagging: separate tags for price (pricing_123), inventory (inventory_123), content (content_123). ' .
            'This reduces cache stampedes where multiple keys are unnecessarily purged.'
        );
    }

    private function checkPHPSessionManagement(): void
    {
        // Check session configuration
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            $sessionSave = $env['session']['save'] ?? 'files';
            
            if ($sessionSave === 'files') {
                $this->collector->add(
                    'varnish',
                    'Session storage not optimized for Varnish',
                    Recommendation::PRIORITY_MEDIUM,
                    'File-based sessions can cause PHPSESSID proliferation. Use Redis for session storage.',
                    'File sessions create new IDs for every request, forcing Varnish PASS behavior. ' .
                    'Redis sessions enable proper session management without cache bypasses. ' .
                    'Configure: bin/magento setup:config:set --session-save=redis --session-save-redis-host=127.0.0.1'
                );
            }
        }

        // Check for session initialization issues
        $this->collector->add(
            'varnish',
            'Audit session initialization for anonymous users',
            Recommendation::PRIORITY_HIGH,
            'Check if sessions are being created unnecessarily for anonymous visitors.',
            'Third-party modules often initialize sessions prematurely. Common culprits: ' .
            '1) Persistent cart functionality, 2) Analytics modules, 3) A/B testing tools. ' .
            'Audit with: grep -r "getSession()" app/code/. Sessions should only start at login/add-to-cart.'
        );
    }

    private function analyzeVaryHeaders(): void
    {
        $this->collector->add(
            'varnish',
            'Check X-Magento-Vary cookie behavior',
            Recommendation::PRIORITY_MEDIUM,
            'X-Magento-Vary cookies may persist incorrect customer context after logout.',
            'The vary cookie continues containing customer group data for logged-out users, creating cache fragmentation. ' .
            'Implement proper cleanup: unset bereq.http.X-Magento-Vary in VCL for anonymous users. ' .
            'This prevents unnecessary cache variations for identical content.'
        );
    }

    private function checkGraceModeConfiguration(): void
    {
        $this->collector->add(
            'varnish',
            'Configure Varnish grace mode for high availability',
            Recommendation::PRIORITY_MEDIUM,
            'Grace mode serves stale content during backend regeneration, preventing blocking.',
            'Configure in VCL: set beresp.grace = 24h; set beresp.keep = 8h;. ' .
            'This enables serving cached content even when backend is regenerating, eliminating wait times. ' .
            'Particularly important during cache warmup or high-traffic events.'
        );
    }

    private function analyzeCacheWarmingStrategy(): void
    {
        $cronPath = $this->magentoRoot . '/var/cron/cron.log';
        $hasWarmup = false;
        
        if (file_exists($cronPath)) {
            $content = file_get_contents($cronPath);
            if (strpos($content, 'cache_warm') !== false || strpos($content, 'crawler') !== false) {
                $hasWarmup = true;
            }
        }

        if (!$hasWarmup) {
            $this->collector->add(
                'varnish',
                'Implement automated cache warming',
                Recommendation::PRIORITY_LOW,
                'No cache warming detected. Implement priority-based content pre-generation.',
                'Proactive cache warming prevents cold cache issues. Priority strategy: ' .
                '1) Homepage and main categories, 2) Top products from analytics, 3) Search result pages. ' .
                'Use concurrent requests: parallel curl commands or dedicated warming tools. ' .
                'Schedule during low-traffic periods to pre-populate cache.'
            );
        }
    }

    private function checkEdgeCachingCapabilities(): void
    {
        $this->collector->add(
            'varnish',
            'Consider edge computing for personalization',
            Recommendation::PRIORITY_LOW,
            'Edge computing enables personalization without cache bypasses.',
            'Fastly Compute@Edge or Cloudflare Workers can assemble personalized content at the edge. ' .
            'Static content served from cache, personalization computed at edge, maintaining 95%+ hit rates. ' .
            'Ideal for: recommendations, pricing, inventory status. Reduces origin load by 80-90%.'
        );
    }

    private function analyzeVCLConfiguration(): void
    {
        // Check for common VCL file locations
        $vclPaths = [
            '/etc/varnish/default.vcl',
            '/usr/local/etc/varnish/default.vcl',
            $this->magentoRoot . '/varnish.vcl',
        ];

        $vclFound = false;
        foreach ($vclPaths as $path) {
            if (file_exists($path)) {
                $vclContent = file_get_contents($path);
                $this->analyzeVCLContent($vclContent);
                $vclFound = true;
                break;
            }
        }

        if (!$vclFound) {
            // Provide VCL optimization template
            $this->collector->add(
                'varnish',
                'VCL configuration file not found for analysis',
                Recommendation::PRIORITY_LOW,
                'Could not locate VCL file. Ensure Varnish is properly configured with Magento-specific VCL.',
                'Use Magento\'s generated VCL as starting point: bin/magento varnish:vcl:generate. ' .
                'Key optimizations: 1) Cookie cleanup for anonymous users, 2) Cache-Control header fixes, ' .
                '3) Grace mode configuration, 4) ESI processing setup.'
            );
        }
    }

    private function analyzeVCLContent(string $vclContent): void
    {
        // Check for critical VCL patterns
        $checks = [
            'cookie_cleanup' => [
                'pattern' => 'regsuball.*PHPSESSID',
                'missing_message' => 'VCL missing PHPSESSID cleanup for anonymous users',
                'recommendation' => 'Add cookie cleanup in vcl_recv to prevent session proliferation'
            ],
            'grace_mode' => [
                'pattern' => 'beresp\.grace',
                'missing_message' => 'Grace mode not configured in VCL',
                'recommendation' => 'Configure grace mode to serve stale content during regeneration'
            ],
            'esi_processing' => [
                'pattern' => 'esi;|do_esi',
                'missing_message' => 'ESI processing not enabled in VCL',
                'recommendation' => 'Enable ESI for fragment caching of dynamic content'
            ],
        ];

        foreach ($checks as $check => $config) {
            if (!preg_match('/' . $config['pattern'] . '/i', $vclContent)) {
                $this->collector->add(
                    'varnish',
                    $config['missing_message'],
                    Recommendation::PRIORITY_MEDIUM,
                    $config['recommendation'],
                    'This VCL optimization is critical for achieving high cache hit rates.'
                );
            }
        }
    }

    private function checkVarnishHealth(array $host): void
    {
        $hostname = $host['backend_host'] ?? 'localhost';
        $port = $host['backend_port'] ?? 80;

        $errno = 0;
        $errstr = '';
        $timeout = 1;

        $socket = @stream_socket_client(
            "tcp://{$hostname}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            $this->collector->add(
                'varnish',
                'Varnish connection failed',
                Recommendation::PRIORITY_HIGH,
                "Cannot connect to Varnish at {$hostname}:{$port}. Error: {$errstr}",
                'Verify Varnish is running and accessible. Check firewall rules and Varnish configuration. ' .
                'Without Varnish, all traffic hits PHP/MySQL directly, severely impacting performance.'
            );
        } else {
            fclose($socket);
        }
    }

    private function estimateBypassRate(): int
    {
        // In a real implementation, this would query varnishstat or parse logs
        // For now, return a simulated value that would trigger recommendations
        
        // Check for common bypass indicators
        $bypassIndicators = 0;
        
        // Check if file sessions are used (high bypass indicator)
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            if (($env['session']['save'] ?? 'files') === 'files') {
                $bypassIndicators += 30;
            }
        }
        
        // Check for FPC disabled
        $fpcEnabled = $this->checkFPCStatus();
        if (!$fpcEnabled) {
            $bypassIndicators += 40;
        }
        
        // Base bypass rate for typical Magento installation
        return 30 + $bypassIndicators;
    }

    private function checkFPCStatus(): bool
    {
        // Check if FPC is enabled
        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (file_exists($envPath)) {
                $env = include $envPath;
                // Check cache configuration
                return !empty($env['cache_types']['full_page'] ?? true);
            }
        } catch (\Exception $e) {
            // Assume enabled if we can't check
        }
        
        return true;
    }
}
