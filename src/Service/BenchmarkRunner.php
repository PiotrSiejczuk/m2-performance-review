<?php

namespace M2Performance\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\OutputInterface;

class BenchmarkRunner
{
    private string $magentoRoot;
    private array $results = [];

    public function __construct(string $magentoRoot)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
    }

    public function runBenchmarks(OutputInterface $output): array
    {
        $benchmarks = [
            'homepage' => $this->benchmarkHomepage(),
            'category' => $this->benchmarkCategory(),
            'product' => $this->benchmarkProduct(),
            'search' => $this->benchmarkSearch(),
            'api' => $this->benchmarkApi(),
            'admin' => $this->benchmarkAdmin(),
            'cache' => $this->benchmarkCachePerformance(),
            'database' => $this->benchmarkDatabase()
        ];

        return $benchmarks;
    }

    private function benchmarkHomepage(): array
    {
        $url = $this->getBaseUrl();
        return $this->runUrlBenchmark($url, 'Homepage');
    }

    private function benchmarkCategory(): array
    {
        // Try to find a category URL from sitemap or use default
        $url = $this->getBaseUrl() . '/women.html';
        return $this->runUrlBenchmark($url, 'Category Page');
    }

    private function benchmarkProduct(): array
    {
        $url = $this->getBaseUrl() . '/sample-product.html';
        return $this->runUrlBenchmark($url, 'Product Page');
    }

    private function benchmarkSearch(): array
    {
        $url = $this->getBaseUrl() . '/catalogsearch/result/?q=shirt';
        return $this->runUrlBenchmark($url, 'Search Results');
    }

    private function benchmarkApi(): array
    {
        $url = $this->getBaseUrl() . '/rest/V1/products?searchCriteria[pageSize]=10';

        // Test API performance
        $results = [];
        $iterations = 10;

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_close($ch);

            $results[] = [
                'time' => $totalTime,
                'status' => $httpCode,
                'success' => $httpCode === 200
            ];
        }

        $avgTime = array_sum(array_column($results, 'time')) / count($results);
        $successRate = (array_sum(array_column($results, 'success')) / count($results)) * 100;

        return [
            'name' => 'REST API',
            'avg_response_time' => round($avgTime * 1000, 2),
            'success_rate' => $successRate,
            'iterations' => $iterations,
            'status' => $avgTime < 0.5 ? 'good' : ($avgTime < 1 ? 'warning' : 'critical'),
            'recommendation' => $avgTime > 0.5 ? 'API response time is slow. Consider implementing caching or optimizing queries.' : null
        ];
    }

    private function benchmarkAdmin(): array
    {
        $adminUrl = $this->getAdminUrl();
        $url = $this->getBaseUrl() . '/' . $adminUrl;

        // Basic admin login page benchmark
        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_exec($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'name' => 'Admin Login Page',
            'response_time' => round($totalTime * 1000, 2),
            'status_code' => $httpCode,
            'status' => $totalTime < 1 ? 'good' : ($totalTime < 2 ? 'warning' : 'critical'),
            'recommendation' => $totalTime > 1 ? 'Admin page load time is slow. Check for heavy modules or database queries.' : null
        ];
    }

    private function benchmarkCachePerformance(): array
    {
        $results = [];

        // Test file cache performance
        $cacheDir = $this->magentoRoot . '/var/cache';
        if (is_writable($cacheDir)) {
            $testFile = $cacheDir . '/benchmark_' . uniqid();
            $testData = str_repeat('A', 1024 * 10); // 10KB

            // Write test
            $writeStart = microtime(true);
            for ($i = 0; $i < 100; $i++) {
                file_put_contents($testFile . $i, $testData);
            }
            $writeTime = microtime(true) - $writeStart;

            // Read test
            $readStart = microtime(true);
            for ($i = 0; $i < 100; $i++) {
                file_get_contents($testFile . $i);
            }
            $readTime = microtime(true) - $readStart;

            // Cleanup
            for ($i = 0; $i < 100; $i++) {
                @unlink($testFile . $i);
            }

            $results['file_cache'] = [
                'write_time' => round($writeTime * 1000, 2),
                'read_time' => round($readTime * 1000, 2),
                'operations' => 100,
                'status' => ($writeTime + $readTime) < 0.5 ? 'good' : 'warning'
            ];
        }

        // Test Redis if available
        if (extension_loaded('redis')) {
            try {
                $redis = new \Redis();
                if ($redis->connect('127.0.0.1', 6379, 0.5)) {
                    $testData = str_repeat('A', 1024);

                    $start = microtime(true);
                    for ($i = 0; $i < 1000; $i++) {
                        $redis->set('benchmark_' . $i, $testData);
                    }
                    $setTime = microtime(true) - $start;

                    $start = microtime(true);
                    for ($i = 0; $i < 1000; $i++) {
                        $redis->get('benchmark_' . $i);
                    }
                    $getTime = microtime(true) - $start;

                    // Cleanup
                    for ($i = 0; $i < 1000; $i++) {
                        $redis->del('benchmark_' . $i);
                    }

                    $results['redis'] = [
                        'set_time' => round($setTime * 1000, 2),
                        'get_time' => round($getTime * 1000, 2),
                        'operations' => 1000,
                        'status' => ($setTime + $getTime) < 0.1 ? 'good' : 'warning'
                    ];

                    $redis->close();
                }
            } catch (\Exception $e) {
                // Redis not available
            }
        }

        return [
            'name' => 'Cache Performance',
            'results' => $results,
            'status' => !empty($results) ? 'completed' : 'skipped',
            'recommendation' => $this->getCacheRecommendation($results)
        ];
    }

    private function benchmarkDatabase(): array
    {
        $results = [];

        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (file_exists($envPath)) {
                $env = include $envPath;
                $dbConfig = $env['db']['connection']['default'] ?? [];

                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s',
                    $dbConfig['host'] ?? '127.0.0.1',
                    $dbConfig['port'] ?? '3306',
                    $dbConfig['dbname'] ?? ''
                );

                $pdo = new \PDO($dsn, $dbConfig['username'] ?? '', $dbConfig['password'] ?? '');

                // Simple query benchmark
                $queries = [
                    'simple_select' => 'SELECT 1',
                    'catalog_product' => 'SELECT COUNT(*) FROM catalog_product_entity',
                    'sales_order' => 'SELECT COUNT(*) FROM sales_order',
                    'customer' => 'SELECT COUNT(*) FROM customer_entity'
                ];

                foreach ($queries as $name => $query) {
                    $start = microtime(true);
                    try {
                        $stmt = $pdo->query($query);
                        $stmt->fetchAll();
                        $time = microtime(true) - $start;

                        $results[$name] = [
                            'time' => round($time * 1000, 2),
                            'status' => $time < 0.01 ? 'good' : ($time < 0.1 ? 'warning' : 'critical')
                        ];
                    } catch (\Exception $e) {
                        $results[$name] = [
                            'time' => 0,
                            'status' => 'error',
                            'error' => $e->getMessage()
                        ];
                    }
                }

                // Check slow query log
                try {
                    $stmt = $pdo->query("SHOW VARIABLES LIKE 'slow_query_log'");
                    $slowQueryLog = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $results['slow_query_log'] = $slowQueryLog['Value'] ?? 'OFF';
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        } catch (\Exception $e) {
            return [
                'name' => 'Database Performance',
                'error' => $e->getMessage(),
                'status' => 'error'
            ];
        }

        return [
            'name' => 'Database Performance',
            'results' => $results,
            'status' => 'completed',
            'recommendation' => $this->getDatabaseRecommendation($results)
        ];
    }

    private function runUrlBenchmark(string $url, string $name): array
    {
        // Use curl for simple benchmark
        $iterations = 5;
        $results = [];

        for ($i = 0; $i < $iterations; $i++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $start = microtime(true);
            $content = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);

            $results[] = [
                'total_time' => $info['total_time'],
                'connect_time' => $info['connect_time'],
                'starttransfer_time' => $info['starttransfer_time'],
                'size' => $info['size_download'],
                'status' => $info['http_code']
            ];

            // Small delay between requests
            usleep(100000); // 100ms
        }

        // Calculate averages
        $avgTotalTime = array_sum(array_column($results, 'total_time')) / count($results);
        $avgConnectTime = array_sum(array_column($results, 'connect_time')) / count($results);
        $avgTTFB = array_sum(array_column($results, 'starttransfer_time')) / count($results);
        $avgSize = array_sum(array_column($results, 'size')) / count($results);

        return [
            'name' => $name,
            'url' => $url,
            'iterations' => $iterations,
            'avg_total_time' => round($avgTotalTime, 3),
            'avg_connect_time' => round($avgConnectTime, 3),
            'avg_ttfb' => round($avgTTFB, 3),
            'avg_size_kb' => round($avgSize / 1024, 2),
            'status' => $this->getPerformanceStatus($avgTotalTime),
            'recommendation' => $this->getPerformanceRecommendation($avgTotalTime, $avgTTFB, $avgSize)
        ];
    }

    private function getBaseUrl(): string
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            // Try to get from env.php (not standard but sometimes stored)
        }

        // Try to detect from nginx config
        $nginxConfigs = [
            '/etc/nginx/sites-available/magento',
            '/etc/nginx/conf.d/magento.conf'
        ];

        foreach ($nginxConfigs as $config) {
            if (file_exists($config)) {
                $content = file_get_contents($config);
                if (preg_match('/server_name\s+([^;]+);/', $content, $matches)) {
                    $serverName = trim($matches[1]);
                    if ($serverName !== '_' && $serverName !== 'localhost') {
                        return 'https://' . $serverName;
                    }
                }
            }
        }

        return 'http://localhost';
    }

    private function getAdminUrl(): string
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            return $env['backend']['frontName'] ?? 'admin';
        }
        return 'admin';
    }

    private function getPerformanceStatus(float $time): string
    {
        if ($time < 1) return 'good';
        if ($time < 3) return 'warning';
        return 'critical';
    }

    private function getPerformanceRecommendation(float $totalTime, float $ttfb, float $size): ?string
    {
        $recommendations = [];

        if ($totalTime > 3) {
            $recommendations[] = 'Page load time exceeds 3 seconds';
        }

        if ($ttfb > 0.8) {
            $recommendations[] = 'Time to first byte (TTFB) is high, indicating server-side performance issues';
        }

        if ($size > 1024 * 1024) { // 1MB
            $recommendations[] = 'Page size exceeds 1MB. Consider optimizing images and assets';
        }

        return !empty($recommendations) ? implode('. ', $recommendations) : null;
    }

    private function getCacheRecommendation(array $results): ?string
    {
        $recommendations = [];

        if (isset($results['file_cache']) && $results['file_cache']['status'] === 'warning') {
            $recommendations[] = 'File cache performance is slow. Consider using Redis or memcached';
        }

        if (!isset($results['redis'])) {
            $recommendations[] = 'Redis is not configured or not accessible for benchmarking';
        } elseif ($results['redis']['status'] === 'warning') {
            $recommendations[] = 'Redis performance is suboptimal. Check memory allocation and persistence settings';
        }

        return !empty($recommendations) ? implode('. ', $recommendations) : null;
    }

    private function getDatabaseRecommendation(array $results): ?string
    {
        $recommendations = [];

        foreach ($results as $name => $result) {
            if (is_array($result) && isset($result['status']) && $result['status'] === 'critical') {
                $recommendations[] = "Query '$name' is slow ({$result['time']}ms)";
            }
        }

        if (isset($results['slow_query_log']) && $results['slow_query_log'] === 'OFF') {
            $recommendations[] = 'Enable slow query log to identify performance issues';
        }

        return !empty($recommendations) ? implode('. ', $recommendations) : null;
    }
}
