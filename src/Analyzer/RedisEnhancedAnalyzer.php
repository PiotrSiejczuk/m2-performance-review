<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class RedisEnhancedAnalyzer implements AnalyzerInterface
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
        $this->analyzeRedisSession();
        $this->analyzeRedisCache();
        $this->analyzeRedisPerformance();
    }

    private function analyzeRedisSession(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return;
        }

        $env = include $envPath;
        $sessionConfig = $env['session']['redis'] ?? [];

        if (empty($sessionConfig)) {
            $this->collector->add(
                'redis',
                'Configure Redis for session storage',
                Recommendation::PRIORITY_HIGH,
                'Redis session storage is not configured. This significantly improves session handling performance.'
            );
            return;
        }

        // Critical: Check disable_locking
        if (!isset($sessionConfig['disable_locking']) || $sessionConfig['disable_locking'] != '1') {
            $this->collector->add(
                'redis',
                'Disable Redis session locking',
                Recommendation::PRIORITY_HIGH,
                'Session locking is enabled which causes blocking on concurrent requests. Set disable_locking=1 for major performance improvement.'
            );
        }

        // Check compression
        if (!isset($sessionConfig['compression_lib']) || $sessionConfig['compression_lib'] == 'none') {
            $this->collector->add(
                'redis',
                'Enable Redis session compression',
                Recommendation::PRIORITY_MEDIUM,
                'Session compression is disabled. Use lz4 for best performance: compression_lib=lz4'
            );
        } elseif ($sessionConfig['compression_lib'] == 'gzip') {
            $this->collector->add(
                'redis',
                'Switch to LZ4 compression',
                Recommendation::PRIORITY_LOW,
                'Currently using gzip compression. LZ4 is faster: compression_lib=lz4'
            );
        }

        // Check timeout
        if (isset($sessionConfig['timeout']) && $sessionConfig['timeout'] < 2.5) {
            $this->collector->add(
                'redis',
                'Increase Redis timeout',
                Recommendation::PRIORITY_MEDIUM,
                "Redis timeout is too low: {$sessionConfig['timeout']}s. Set to at least 2.5s"
            );
        }

        // Check persistent connections
        if (!isset($sessionConfig['persistent_identifier'])) {
            $this->collector->add(
                'redis',
                'Enable Redis persistent connections',
                Recommendation::PRIORITY_MEDIUM,
                'Persistent connections reduce connection overhead. Add persistent_identifier parameter.'
            );
        }

        // Check max_concurrency
        if (!isset($sessionConfig['max_concurrency']) || $sessionConfig['max_concurrency'] < 6) {
            $this->collector->add(
                'redis',
                'Increase Redis max_concurrency',
                Recommendation::PRIORITY_LOW,
                'Set max_concurrency to at least 6 for better concurrent request handling'
            );
        }
    }

    private function analyzeRedisCache(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return;
        }

        $env = include $envPath;
        $cacheConfig = $env['cache']['frontend']['default']['backend_options'] ?? [];

        if (empty($cacheConfig) || !str_contains($env['cache']['frontend']['default']['backend'] ?? '', 'Redis')) {
            $this->collector->add(
                'redis',
                'Configure Redis for cache storage',
                Recommendation::PRIORITY_HIGH,
                'Redis cache storage is not configured. This provides significant performance improvement over file-based cache.'
            );
            return;
        }

        // Check if separate Redis instances are used
        $sessionDb = $env['session']['redis']['database'] ?? 0;
        $cacheDb = $cacheConfig['database'] ?? 0;

        if ($sessionDb == $cacheDb && !empty($env['session']['redis'])) {
            $this->collector->add(
                'redis',
                'Use separate Redis databases',
                Recommendation::PRIORITY_MEDIUM,
                'Sessions and cache share the same Redis database. Use different database numbers for better isolation.'
            );
        }

        // Check if preload_keys is set
        if (!isset($cacheConfig['preload_keys']) || empty($cacheConfig['preload_keys'])) {
            $this->collector->add(
                'redis',
                'Configure Redis cache preloading',
                Recommendation::PRIORITY_LOW,
                'Consider preloading frequently used cache keys for better performance'
            );
        }
    }

    private function analyzeRedisPerformance(): void
    {
        if (!extension_loaded('redis')) {
            $this->collector->add(
                'redis',
                'Install phpredis extension',
                Recommendation::PRIORITY_HIGH,
                'The phpredis extension is not installed. It provides better performance than predis.'
            );
            return;
        }

        // Try to connect and get Redis info
        try {
            $redis = new \Redis();
            if (!@$redis->connect('127.0.0.1', 6379, 0.5)) {
                return;
            }

            $info = $redis->info();

            // Check memory usage
            if (isset($info['used_memory']) && isset($info['maxmemory']) && $info['maxmemory'] > 0) {
                $usagePercent = ($info['used_memory'] / $info['maxmemory']) * 100;
                if ($usagePercent > 80) {
                    $this->collector->add(
                        'redis',
                        'Redis memory usage high',
                        Recommendation::PRIORITY_HIGH,
                        sprintf('Redis memory usage is %.1f%%. Consider increasing maxmemory or enabling eviction.', $usagePercent)
                    );
                }
            }

            // Check evicted keys
            if (isset($info['evicted_keys']) && $info['evicted_keys'] > 1000) {
                $this->collector->add(
                    'redis',
                    'Redis evicting keys',
                    Recommendation::PRIORITY_HIGH,
                    sprintf('%d keys have been evicted. Increase Redis memory allocation.', $info['evicted_keys'])
                );
            }

            // Check persistence
            if (isset($info['rdb_last_save_time'])) {
                $lastSave = time() - $info['rdb_last_save_time'];
                if ($lastSave > 86400) { // More than 24 hours
                    $this->collector->add(
                        'redis',
                        'Redis persistence outdated',
                        Recommendation::PRIORITY_MEDIUM,
                        'Redis last save was more than 24 hours ago. Check persistence configuration.'
                    );
                }
            }

            $redis->close();
        } catch (\Exception $e) {
            // Redis connection failed, skip performance checks
        }
    }

    private function checkL2CacheArchitecture(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return;
        }

        $env = include $envPath;
        $cacheConfig = $env['cache']['frontend']['default'] ?? [];
        
        // Check if L2 cache is configured
        $backend = $cacheConfig['backend'] ?? '';
        
        if ($backend !== 'Magento\\Framework\\Cache\\Backend\\RemoteSynchronizedCache') {
            $this->collector->add(
                'redis',
                'L2 cache architecture not implemented',
                Recommendation::PRIORITY_MEDIUM,
                'Consider implementing L2 cache with Redis + local file system for reduced latency.',
                'L2 cache architecture uses local file system (preferably /dev/shm/ RAM disk) as first level ' .
                'and Redis as second level. This reduces network latency for frequently accessed keys. ' .
                'Benefits: 30-50% reduction in cache retrieval time, reduced Redis network traffic. ' .
                'Configure with RemoteSynchronizedCache backend and local_backend options.'
            );
            
            // Provide configuration example
            $this->collector->addWithMetadata(
                'redis',
                'L2 cache configuration example',
                Recommendation::PRIORITY_LOW,
                'Implement two-tier caching for optimal performance',
                'Use RemoteSynchronizedCache with Redis as remote backend and file cache in RAM as local backend.',
                [
                    'config_example' => [
                        'cache' => [
                            'frontend' => [
                                'default' => [
                                    'backend' => 'Magento\\Framework\\Cache\\Backend\\RemoteSynchronizedCache',
                                    'backend_options' => [
                                        'remote_backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                                        'remote_backend_options' => [
                                            'server' => '127.0.0.1',
                                            'database' => '0',
                                            'compress_data' => '4',
                                            'compression_lib' => 'gzip'
                                        ],
                                        'local_backend' => 'Cm_Cache_Backend_File',
                                        'local_backend_options' => [
                                            'cache_dir' => '/dev/shm/magento_cache/'
                                        ],
                                        'use_stale_cache' => true
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            );
        }

        // Check if using RAM disk for local cache
        $localOptions = $cacheConfig['backend_options']['local_backend_options'] ?? [];
        $cacheDir = $localOptions['cache_dir'] ?? '';
        
        if ($cacheDir && strpos($cacheDir, '/dev/shm') === false && strpos($cacheDir, '/tmp') === false) {
            $this->collector->add(
                'redis',
                'L2 local cache not using RAM disk',
                Recommendation::PRIORITY_LOW,
                'Local cache directory not in RAM. Consider using /dev/shm/ for better performance.',
                'Storing L2 local cache on RAM disk eliminates disk I/O completely. ' .
                '/dev/shm/ is a tmpfs mount that uses system RAM. This provides microsecond-level ' .
                'access times compared to milliseconds for SSD. Ensure adequate RAM allocation.'
            );
        }

        // Check compression settings
        $remoteOptions = $cacheConfig['backend_options']['remote_backend_options'] ?? [];
        $compressData = $remoteOptions['compress_data'] ?? '0';
        
        if ($compressData === '0' || $compressData === false) {
            $this->collector->add(
                'redis',
                'Redis compression disabled',
                Recommendation::PRIORITY_LOW,
                'Enable Redis compression to reduce memory usage and network traffic.',
                'Compression reduces Redis memory usage by 60-80% for typical Magento cache data. ' .
                'Use compress_data=4 (gzip level 4) for optimal balance between CPU usage and compression ratio. ' .
                'This also reduces network traffic between application and Redis servers.'
            );
        }
    }
}
