<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Trait\DevModeAwareTrait;

class CacheAnalyzer implements AnalyzerInterface
{
    use DevModeAwareTrait;

    private string $magentoRoot;
    private array $coreConfig;
    private RecommendationCollector $collector;

    public function __construct(string $magentoRoot, array $coreConfig, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->coreConfig  = $coreConfig;
        $this->collector   = $collector;
    }

    public function analyze(): void
    {
        $this->analyzeCaching();
        $this->checkCacheTypes();
    }


    private function analyzeCaching(): void
    {
        $configLoader = new EnhancedConfigLoader($this->magentoRoot);
        
        // Check for Fastly first - if present, skip Varnish recommendations
        if ($this->detectFastly()) {
            $this->collector->add(
                'caching',
                'Fastly CDN detected - excellent choice',
                Recommendation::PRIORITY_LOW,
                'Fastly provides enterprise-grade edge caching with built-in Varnish. Traditional Varnish setup not needed.',
                'Fastly is a fully managed CDN service built on Varnish with global edge locations. Benefits: ' .
                '1) No server maintenance required, 2) Global edge caching, 3) Real-time purging, ' .
                '4) Advanced VCL customization, 5) Built-in DDoS protection. Adobe Commerce Cloud includes Fastly by default.'
            );
            
            // Still check TTL and cache types, but skip Varnish-specific recommendations
            $this->checkTtl();
            return;
        }

        // In dev mode, Varnish is often not needed
        if ($this->isInDeveloperMode()) {
            $this->collector->add(
                'caching',
                'Varnish not required in development',
                Recommendation::PRIORITY_LOW,
                'Full page caching is often disabled in development for immediate changes visibility.',
                'In development, you typically want to see changes immediately without cache invalidation. ' .
                'Varnish adds complexity without benefit in dev. For production, Varnish is essential for performance.'
            );
            return;
        }

        // Production mode checks for non-Fastly setups
        $this->checkVarnishConfiguration($configLoader);
        $this->checkTtl();
    }

    private function checkCacheTypes(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return;
        }

        $env = include $envPath;
        $cacheTypes = $env['cache_types'] ?? [];

        if ($this->isInDeveloperMode()) {
            // Dev mode specific cache recommendations
            $this->checkDeveloperCacheTypes($cacheTypes);
        } else {
            // Production mode - all caches should be enabled
            $this->checkProductionCacheTypes($cacheTypes);
        }
    }

    private function checkDeveloperCacheTypes(array $cacheTypes): void
    {
        // Caches that should stay enabled even in dev
        $alwaysEnable = [
            'config' => 'Configuration cache - prevents XML parsing overhead',
            'db_ddl' => 'Database DDL cache - speeds up schema checks',
            'compiled_config' => 'Compiled config - faster DI resolution',
            'eav' => 'EAV cache - speeds up attribute loading'
        ];

        // Caches commonly disabled in dev for immediate updates
        $canDisable = [
            'layout' => 'Layout cache - disable to see template changes',
            'block_html' => 'Block HTML cache - disable for block updates',
            'full_page' => 'Full page cache - disable to bypass caching',
            'view_files_fallback' => 'View files - disable for theme development',
            'view_files_preprocessing' => 'Preprocessed views - disable for LESS/CSS work'
        ];

        $shouldEnable = [];
        foreach ($alwaysEnable as $cache => $description) {
            if (!isset($cacheTypes[$cache]) || $cacheTypes[$cache] != 1) {
                $shouldEnable[$cache] = $description;
            }
        }

        if (!empty($shouldEnable)) {
            $details = "Recommended caches for development:\n";
            foreach ($shouldEnable as $cache => $desc) {
                $details .= "• {$cache}: {$desc}\n";
            }

            $this->collector->add(
                'caching',
                'Enable performance caches for development',
                Recommendation::PRIORITY_LOW,
                trim($details),
                'Even in development, certain caches improve performance without hindering workflow. ' .
                'These caches speed up backend operations without affecting frontend development. ' .
                'Enable with: bin/magento cache:enable ' . implode(' ', array_keys($shouldEnable))
            );
        }

        // Provide info about disabled caches
        $disabledForDev = [];
        foreach ($canDisable as $cache => $description) {
            if (!isset($cacheTypes[$cache]) || $cacheTypes[$cache] != 1) {
                $disabledForDev[] = $cache;
            }
        }

        if (!empty($disabledForDev)) {
            $this->collector->add(
                'caching',
                'Development cache configuration detected',
                Recommendation::PRIORITY_LOW,
                'Caches disabled for development: ' . implode(', ', $disabledForDev) . '. This is normal for active development.',
                'These caches are commonly disabled during development to see changes immediately. ' .
                'Remember to enable all caches before deploying to production with: bin/magento cache:enable'
            );
        }
    }

    private function checkProductionCacheTypes(array $cacheTypes): void
    {
        $disabledCaches = [];
        foreach ($cacheTypes as $type => $enabled) {
            if ($enabled != 1) {
                $disabledCaches[] = $type;
            }
        }

        if (!empty($disabledCaches)) {
            $this->collector->add(
                'caching',
                'Enable all cache types for production',
                Recommendation::PRIORITY_HIGH,
                'Disabled caches detected: ' . implode(', ', $disabledCaches) . '. All caches must be enabled in production.',
                'Disabled caches cause severe performance degradation in production. Each disabled cache type ' .
                'forces Magento to regenerate data on every request. Impact: 2-10x slower page loads, ' .
                'increased server load, poor user experience. Enable all with: bin/magento cache:enable'
            );
        }
    }

    private function checkTtl(): void
    {
        // Skip TTL check in dev mode
        if ($this->isInDeveloperMode()) {
            return;
        }

        $pageCacheTtl = (int) ($this->getConfigValue('system/full_page_cache/ttl') ?? 0);
        // TTL=0 means permanent cache, which is good
        if ($pageCacheTtl > 0 && $pageCacheTtl < 86400) { // > 0 but < 24h
            $this->collector->add(
                'caching',
                'Increase Full Page Cache TTL',
                Recommendation::PRIORITY_MEDIUM,
                'Consider increasing FPC TTL to at least 86400 seconds (24 hours) or 0 for permanent. Current: ' . $pageCacheTtl,
                'Short TTL causes unnecessary cache regeneration. Magento invalidates cache on changes automatically, ' .
                'so long TTL is safe. Permanent cache (TTL=0) with proper invalidation gives best performance. ' .
                'Set with: bin/magento config:set system/full_page_cache/ttl 86400'
            );
        }
    }

    /**
     * Only run cache-warmer check when $fpcBackend === '2' (Varnish).
     * Accepts null in case the config path was missing.
     */
    private function checkCacheWarmer(?string $fpcBackend): void
    {
        // Only relevant if Varnish is the backend and not in dev mode
        if ($fpcBackend !== '2' || $this->isInDeveloperMode()) {
            return;
        }

        try {
            $cliPath = $this->magentoRoot . '/bin/magento';
            $hasVarnishWarmer = false;

            if (is_executable($cliPath)) {
                $output = [];
                @exec(escapeshellcmd($cliPath) . ' module:status 2>&1', $output, $returnVar);
                if ($returnVar === 0) {
                    $modulesList = implode("\n", $output);
                    if (strpos($modulesList, 'Magento_CacheWarmup') !== false
                        || strpos($modulesList, 'WarmCache') !== false) {
                        $hasVarnishWarmer = true;
                    }
                }
            }

            if (!$hasVarnishWarmer && $this->checkComposerForPackage('cache-warmer')) {
                $hasVarnishWarmer = true;
            }

            if (!$hasVarnishWarmer) {
                $this->collector->add(
                    'caching',
                    'Consider implementing a cache warmer',
                    Recommendation::PRIORITY_MEDIUM,
                    'A cache warmer can pre-populate Varnish cache, improving performance for first-time visitors.',
                    'Cache warmers crawl your site to pre-generate cached pages. Benefits: No cold cache penalty, ' .
                    'consistent fast response times, improved SEO (faster bot crawling). Popular options: ' .
                    'Magento Commerce Page Builder cache warmer, magerun2 cache:warmup, custom crawlers.'
                );
            }
        } catch (\Exception $e) {
            // Skip on errors
        }
    }

    private function detectFastly(): bool
    {
        // Method 1: Check composer.json for Fastly module
        $composerJsonPath = $this->magentoRoot . '/composer.json';
        if (file_exists($composerJsonPath)) {
            $composerJson = json_decode(file_get_contents($composerJsonPath), true);
            if (!empty($composerJson['require'])) {
                foreach ($composerJson['require'] as $package => $version) {
                    if (stripos($package, 'fastly') !== false) {
                        return true;
                    }
                }
            }
        }

        // Method 2: Check env.php for Fastly configuration
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            
            // Check for Fastly-specific cache configuration
            $cache = $env['cache']['frontend']['default'] ?? [];
            if (isset($cache['backend']) && stripos($cache['backend'], 'fastly') !== false) {
                return true;
            }
            
            // Check for Fastly environment variables
            if (isset($env['system']['default']['system']['fastly'])) {
                return true;
            }
        }

        // Method 3: Check HTTP headers for Fastly
        $baseUrl = $this->detectBaseUrl();
        if ($baseUrl) {
            $headers = $this->getHttpHeaders($baseUrl);
            if ($headers) {
                // Check for Fastly-specific headers
                foreach ($headers as $name => $value) {
                    $name = strtolower($name);
                    if (in_array($name, ['x-served-by', 'x-cache', 'x-cache-hits']) && 
                        stripos($value, 'fastly') !== false) {
                        return true;
                    }
                    
                    // Check Via header for Fastly
                    if ($name === 'via' && stripos($value, 'fastly') !== false) {
                        return true;
                    }
                }
                
                // Check server header
                if (isset($headers['server']) && stripos($headers['server'], 'fastly') !== false) {
                    return true;
                }
            }
        }

        // Method 4: Check modules configuration
        $configPath = $this->magentoRoot . '/app/etc/config.php';
        if (file_exists($configPath)) {
            $config = include $configPath;
            if (isset($config['modules']['Fastly_Cdn']) && $config['modules']['Fastly_Cdn'] == 1) {
                return true;
            }
        }

        return false;
    }

    private function checkVarnishConfiguration(EnhancedConfigLoader $configLoader): void
    {
        // Check for Varnish in env.php
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            $hasVarnish = !empty($env['http_cache_hosts']);
            
            if (!$hasVarnish) {
                $this->collector->add(
                    'caching',
                    'Consider implementing Varnish Cache',
                    Recommendation::PRIORITY_HIGH,
                    'Varnish Cache can dramatically improve page load times for your store.',
                    'Varnish serves cached pages from memory, bypassing PHP/MySQL entirely. Benefits: ' .
                    '10-100x faster page delivery, handles thousands of concurrent users, reduces server load by 80-90%. ' .
                    'Essential for production e-commerce sites. Configure with HTTP cache hosts in env.php. ' .
                    'Alternative: Consider Fastly for managed CDN with built-in Varnish capabilities.'
                );
            }
        }

        // Check Full Page Cache backend
        $fpcBackend = $configLoader->getConfigValue('system/full_page_cache/caching_application');
        if ($fpcBackend !== '2') { // 2 => Varnish
            $current = $fpcBackend === '1' ? 'Built-in Application Cache' : 'Not specified';
            $this->collector->add(
                'caching',
                'Use Varnish for Full Page Cache',
                Recommendation::PRIORITY_HIGH,
                'Varnish provides better performance than built-in FPC. Current setting: ' . $current,
                'Built-in FPC still hits PHP application layer. Varnish serves from separate cache layer, ' .
                'providing true edge caching. Performance difference: Built-in ~200-500ms vs Varnish ~10-50ms TTFB. ' .
                'Set with: bin/magento config:set system/full_page_cache/caching_application 2. ' .
                'Alternative: Use Fastly for enterprise-grade managed CDN.'
            );
        }

        // Check cache warmer only for Varnish setups
        if ($fpcBackend === '2') {
            $this->checkCacheWarmer();
        }
    }

    private function detectBaseUrl(): ?string
    {
        $configLoader = new EnhancedConfigLoader($this->magentoRoot);
        
        // Try secure URL first, then unsecure
        return $configLoader->getConfigValue('web/secure/base_url') ?: 
            $configLoader->getConfigValue('web/unsecure/base_url');
    }

    private function getHttpHeaders(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 5,
                'user_agent' => 'M2Performance/1.0'
            ]
        ]);

        $headers = @get_headers($url, 1, $context);
        if (!$headers) return null;

        // Normalize header keys to lowercase
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_string($key)) {
                $normalized[strtolower($key)] = is_array($value) ? end($value) : $value;
            }
        }

        return $normalized;
    }

    private function getConfigValue(string $path, $default = null): mixed
    {
        return $this->coreConfig[$path] ?? $default;
    }

    private function checkComposerForPackage(string $needle): bool
    {
        $composerJsonPath = $this->magentoRoot . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return false;
        }

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        if (empty($composerJson['require'])) {
            return false;
        }
        foreach ($composerJson['require'] as $package => $version) {
            if (stripos($package, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
