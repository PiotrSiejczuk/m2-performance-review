<?php

namespace M2Performance\Service;

class CacheMetricsService
{
    private string $magentoRoot;
    private array $metrics = [];

    public function __construct(string $magentoRoot)
    {
        $this->magentoRoot = $magentoRoot;
    }

    /**
     * Analyze Varnish metrics if varnishstat is available
     */
    public function getVarnishMetrics(): array
    {
        $metrics = [
            'available' => false,
            'hit_rate' => 0,
            'bypass_rate' => 0,
            'miss_rate' => 0,
            'backend_connections' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'client_requests' => 0
        ];

        // Check if varnishstat is available
        $varnishstatPath = $this->findExecutable('varnishstat');
        if (!$varnishstatPath) {
            return $metrics;
        }

        // Get varnish statistics
        $output = [];
        exec($varnishstatPath . ' -1 -j 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            $stats = json_decode(implode('', $output), true);
            if ($stats) {
                $metrics['available'] = true;
                
                // Calculate metrics from varnish stats
                $cacheHits = $stats['MAIN.cache_hit']['value'] ?? 0;
                $cacheMisses = $stats['MAIN.cache_miss']['value'] ?? 0;
                $cacheHitpass = $stats['MAIN.cache_hitpass']['value'] ?? 0;
                $clientReqs = $stats['MAIN.client_req']['value'] ?? 0;
                
                if ($clientReqs > 0) {
                    $metrics['hit_rate'] = round(($cacheHits / $clientReqs) * 100, 2);
                    $metrics['miss_rate'] = round(($cacheMisses / $clientReqs) * 100, 2);
                    $metrics['bypass_rate'] = round(($cacheHitpass / $clientReqs) * 100, 2);
                }
                
                $metrics['cache_hits'] = $cacheHits;
                $metrics['cache_misses'] = $cacheMisses;
                $metrics['client_requests'] = $clientReqs;
                $metrics['backend_connections'] = $stats['MAIN.backend_conn']['value'] ?? 0;
            }
        }

        return $metrics;
    }

    /**
     * Analyze Redis metrics
     */
    public function getRedisMetrics(): array
    {
        $metrics = [
            'available' => false,
            'memory_used' => 0,
            'memory_peak' => 0,
            'hit_rate' => 0,
            'evicted_keys' => 0,
            'connected_clients' => 0,
            'total_commands' => 0
        ];

        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (!file_exists($envPath)) {
                return $metrics;
            }

            $env = include $envPath;
            
            // Check session Redis
            if (isset($env['session']['save']) && $env['session']['save'] === 'redis') {
                $host = $env['session']['redis']['host'] ?? '127.0.0.1';
                $port = $env['session']['redis']['port'] ?? 6379;
                $password = $env['session']['redis']['password'] ?? null;
                
                $redisMetrics = $this->queryRedisInfo($host, $port, $password);
                if ($redisMetrics) {
                    $metrics = array_merge($metrics, $redisMetrics);
                    $metrics['available'] = true;
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        return $metrics;
    }

    /**
     * Estimate cache performance based on various indicators
     */
    public function estimateCachePerformance(): array
    {
        $performance = [
            'overall_score' => 0,
            'recommendations' => [],
            'metrics' => []
        ];

        // Get Varnish metrics
        $varnishMetrics = $this->getVarnishMetrics();
        if ($varnishMetrics['available']) {
            $performance['metrics']['varnish'] = $varnishMetrics;
            
            // Score based on hit rate
            if ($varnishMetrics['hit_rate'] >= 90) {
                $performance['overall_score'] += 40;
            } elseif ($varnishMetrics['hit_rate'] >= 70) {
                $performance['overall_score'] += 25;
            } elseif ($varnishMetrics['hit_rate'] >= 50) {
                $performance['overall_score'] += 15;
            } else {
                $performance['overall_score'] += 5;
                $performance['recommendations'][] = 'Critical: Varnish hit rate below 50%';
            }

            // Check bypass rate
            if ($varnishMetrics['bypass_rate'] > 30) {
                $performance['recommendations'][] = 'High bypass rate detected: ' . $varnishMetrics['bypass_rate'] . '%';
            }
        }

        // Get Redis metrics
        $redisMetrics = $this->getRedisMetrics();
        if ($redisMetrics['available']) {
            $performance['metrics']['redis'] = $redisMetrics;
            
            // Score based on Redis hit rate
            if ($redisMetrics['hit_rate'] >= 95) {
                $performance['overall_score'] += 30;
            } elseif ($redisMetrics['hit_rate'] >= 85) {
                $performance['overall_score'] += 20;
            } else {
                $performance['overall_score'] += 10;
                $performance['recommendations'][] = 'Redis hit rate below optimal: ' . $redisMetrics['hit_rate'] . '%';
            }

            // Check for evictions
            if ($redisMetrics['evicted_keys'] > 0) {
                $performance['recommendations'][] = 'Redis memory pressure detected: ' . $redisMetrics['evicted_keys'] . ' keys evicted';
            }
        }

        // Check cache configuration
        $cacheConfig = $this->analyzeCacheConfiguration();
        $performance['overall_score'] += $cacheConfig['score'];
        $performance['recommendations'] = array_merge($performance['recommendations'], $cacheConfig['issues']);

        return $performance;
    }

    private function findExecutable(string $command): ?string
    {
        $paths = [
            '/usr/bin/' . $command,
            '/usr/local/bin/' . $command,
            '/bin/' . $command,
            '/usr/sbin/' . $command
        ];

        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // Try which command
        $output = [];
        exec('which ' . escapeshellarg($command) . ' 2>/dev/null', $output);
        if (!empty($output[0]) && is_executable($output[0])) {
            return $output[0];
        }

        return null;
    }

    private function queryRedisInfo(string $host, int $port, ?string $password): ?array
    {
        try {
            $redis = new \Redis();
            if (!$redis->connect($host, $port, 1.0)) {
                return null;
            }

            if ($password) {
                $redis->auth($password);
            }

            $info = $redis->info();
            $redis->close();

            if (!$info) {
                return null;
            }

            $metrics = [];

            // Memory metrics
            $metrics['memory_used'] = $info['used_memory'] ?? 0;
            $metrics['memory_peak'] = $info['used_memory_peak'] ?? 0;
            $metrics['memory_rss'] = $info['used_memory_rss'] ?? 0;

            // Performance metrics
            $keyspaceHits = $info['keyspace_hits'] ?? 0;
            $keyspaceMisses = $info['keyspace_misses'] ?? 0;
            $totalHitsMisses = $keyspaceHits + $keyspaceMisses;
            
            if ($totalHitsMisses > 0) {
                $metrics['hit_rate'] = round(($keyspaceHits / $totalHitsMisses) * 100, 2);
            }

            $metrics['evicted_keys'] = $info['evicted_keys'] ?? 0;
            $metrics['connected_clients'] = $info['connected_clients'] ?? 0;
            $metrics['total_commands'] = $info['total_commands_processed'] ?? 0;

            return $metrics;

        } catch (\Exception $e) {
            return null;
        }
    }

    private function analyzeCacheConfiguration(): array
    {
        $score = 0;
        $issues = [];

        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (!file_exists($envPath)) {
                return ['score' => 0, 'issues' => ['Cannot read env.php']];
            }

            $env = include $envPath;

            // Check cache types
            $cacheTypes = $env['cache_types'] ?? [];
            $disabledCaches = [];
            
            foreach ($cacheTypes as $type => $enabled) {
                if (!$enabled) {
                    $disabledCaches[] = $type;
                }
            }

            if (empty($disabledCaches)) {
                $score += 15;
            } else {
                $issues[] = 'Disabled cache types: ' . implode(', ', $disabledCaches);
            }

            // Check backend cache
            $cacheBackend = $env['cache']['frontend']['default']['backend'] ?? 'file';
            if (strpos($cacheBackend, 'Redis') !== false) {
                $score += 10;
            } else {
                $issues[] = 'Not using Redis for cache backend';
            }

            // Check page cache
            $pageCacheBackend = $env['cache']['frontend']['page_cache']['backend'] ?? 'file';
            if (strpos($pageCacheBackend, 'Redis') !== false) {
                $score += 5;
            } else {
                $issues[] = 'Not using Redis for page cache';
            }

        } catch (\Exception $e) {
            $issues[] = 'Error analyzing cache configuration';
        }

        return ['score' => $score, 'issues' => $issues];
    }
}
