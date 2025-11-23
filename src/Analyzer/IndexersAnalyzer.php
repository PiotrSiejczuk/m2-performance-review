<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Service\EnhancedConfigLoader;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class IndexersAnalyzer implements AnalyzerInterface
{
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
        // Quick Checks First (No External Calls)
        $this->detectSearchEngine();
        $this->checkSearchEngineConnection();

        $this->validateSearchEngineConfiguration();

        // Expensive Check Last (Only if Needed)
        $this->analyzeIndexingIfNeeded();
    }

    private function analyzeIndexingIfNeeded(): void
    {
        // Skip expensive indexer check if we already have critical search issues
        if ($this->hasSearchEngineIssues()) {
            return;
        }

        // Try faster alternative first - check database directly
        if ($this->checkIndexerStatusFromDatabase()) {
            return; // Successfully checked via DB
        }

        // Fallback to CLI if DB check fails
        $this->analyzeIndexingViaCLI();
    }

    private function hasSearchEngineIssues(): bool
    {
        $searchEngine = $this->coreConfig['catalog/search/engine'] ?? null;
        return !$searchEngine || $searchEngine === 'mysql';
    }

    private function checkIndexerStatusFromDatabase(): bool
    {
        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (!file_exists($envPath)) {
                return false;
            }

            $env = include $envPath;
            $dbConfig = $env['db']['connection']['default'] ?? null;
            if (!$dbConfig) {
                return false;
            }

            // Quick check if we can access the database
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['dbname'] ?? ''
            );

            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 1); // 1 second timeout

            // Check indexer_state table
            $stmt = $pdo->prepare("
                SELECT indexer_id, status, updated 
                FROM indexer_state 
                WHERE status != 'valid'
                LIMIT 10
            ");
            $stmt->execute();
            $invalidIndexers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($invalidIndexers)) {
                $this->collector->add(
                    'indexing',
                    'Invalid indexers detected',
                    Recommendation::PRIORITY_HIGH,
                    sprintf(
                        '%d indexers need reindexing. Run: bin/magento indexer:reindex %s',
                        count($invalidIndexers),
                        implode(' ', array_column($invalidIndexers, 'indexer_id'))
                    ),
                    'Invalid indexers cause outdated data in frontend. Products may not appear in search/categories. ' .
                    'Regular reindexing via cron is essential for data consistency.'
                );
            }

            // Quick check for customer_grid mode
            $stmt = $pdo->prepare("
                SELECT mode FROM mview_state 
                WHERE view_id = 'customer_grid' 
                LIMIT 1
            ");
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result && $result['mode'] !== 'disabled') {
                $this->collector->add(
                    'indexing',
                    'Set customer_grid indexer to "Update on Save"',
                    Recommendation::PRIORITY_HIGH,
                    'The customer_grid indexer must be set to "Update on Save" for proper functionality.',
                    'Adobe Commerce official documentation states that customer_grid indexer does not work correctly in schedule mode. ' .
                    'It can cause missing or outdated customer data in admin grids. ' .
                    'Run: bin/magento indexer:set-mode realtime customer_grid'
                );
            }

            return true; // Successfully checked via DB
        } catch (\Exception $e) {
            // Database check failed, will fallback to CLI
            return false;
        }
    }

    private function analyzeIndexingViaCLI(): void
    {
        // Original CLI-based implementation (expensive)
        try {
            $cliPath = $this->magentoRoot . '/bin/magento';
            if (!is_executable($cliPath)) {
                return;
            }

            // Use timeout to prevent hanging
            $output = [];
            $command = 'timeout 2s ' . escapeshellcmd($cliPath) . ' indexer:status 2>&1';
            @exec($command, $output, $returnVar);

            if ($returnVar === 0) {
                $this->processIndexerOutput($output);
            }
        } catch (\Exception $e) {
            // Skip if cannot execute
        }
    }

    private function processIndexerOutput(array $output): void
    {
        $lines = $output;
        $scheduleCount = 0;
        $indexersCount = 0;
        $customerGridScheduled = false;

        foreach ($lines as $line) {
            if (strpos($line, 'Update by Schedule') !== false) {
                $scheduleCount++;
                if (strpos($line, 'customer_grid') !== false) {
                    $customerGridScheduled = true;
                }
            }
            if (preg_match('/^[a-z0-9_]+\s+/', trim($line))) {
                $indexersCount++;
            }
        }

        if ($customerGridScheduled) {
            $this->collector->add(
                'indexing',
                'Set customer_grid indexer to "Update on Save"',
                Recommendation::PRIORITY_HIGH,
                'The customer_grid indexer is currently set to "Update by Schedule" but must be set to "Update on Save" for proper functionality.',
                'Adobe Commerce official documentation states that customer_grid indexer does not work correctly in schedule mode. ' .
                'It can cause missing or outdated customer data in admin grids. See: https://experienceleague.adobe.com/docs/commerce-operations/configuration-guide/cli/manage-indexers.html ' .
                'Run: bin/magento indexer:set-mode realtime customer_grid'
            );
        }

        if ($scheduleCount < $indexersCount - 1) { // -1 to account for customer_grid
            $this->collector->add(
                'indexing',
                'Set indexers to "Update by Schedule"',
                Recommendation::PRIORITY_HIGH,
                sprintf(
                    '%d out of %d indexers are not set to "Update by Schedule". This mode is more efficient for production.',
                    $indexersCount - $scheduleCount,
                    $indexersCount
                ),
                'Update by Schedule prevents locking issues during reindex operations. Manual mode blocks frontend operations during reindex. ' .
                'Schedule mode uses smaller batches and runs via cron, reducing impact on store performance.'
            );
        }
    }

    private function detectSearchEngine(): void
    {
        // Use enhanced config loader for proper hierarchy
        $configLoader = new EnhancedConfigLoader($this->magentoRoot);
        
        // Try multiple possible paths with proper hierarchy
        $searchEngine = $configLoader->getConfigValue('catalog/search/engine');
        
        // If not found in main config, check alternative paths
        if (empty($searchEngine)) {
            // Check for hostname configurations to infer engine type
            $es7Host = $configLoader->getConfigValue('catalog/search/elasticsearch7_server_hostname');
            $es8Host = $configLoader->getConfigValue('catalog/search/elasticsearch8_server_hostname'); 
            $osHost = $configLoader->getConfigValue('catalog/search/opensearch_server_hostname');
            
            if (!empty($osHost)) {
                $searchEngine = 'opensearch';
            } elseif (!empty($es8Host)) {
                $searchEngine = 'elasticsearch8';
            } elseif (!empty($es7Host)) {
                $searchEngine = 'elasticsearch7';
            }
        }

        // Validate search engine configuration
        if (empty($searchEngine)) {
            $this->collector->add(
                'search',
                'Configure search engine',
                Recommendation::PRIORITY_HIGH,
                'No search engine configured. Elasticsearch or OpenSearch required for optimal performance.',
                'Magento requires external search engine for production. MySQL search is deprecated and extremely slow. ' .
                'Install Elasticsearch 7.x/8.x or OpenSearch 1.x/2.x for 10-100x faster search with better relevance.'
            );
            return;
        }

        // Normalize search engine names
        $searchEngine = strtolower($searchEngine);
        
        // Valid modern search engines (including OpenSearch variants)
        $validEngines = [
            'elasticsearch7',
            'elasticsearch8', 
            'opensearch',
            'opensearch1',
            'opensearch2'
        ];

        if (in_array($searchEngine, $validEngines)) {
            // All these engines are valid - no issues to report
            if (strpos($searchEngine, 'opensearch') !== false) {
                $this->collector->add(
                    'search',
                    'OpenSearch detected - excellent choice',
                    Recommendation::PRIORITY_LOW,
                    'OpenSearch is a community-driven fork of Elasticsearch with identical performance characteristics.',
                    'OpenSearch maintains full API compatibility with Elasticsearch. Performance is identical. Main difference is licensing: ' .
                    'OpenSearch uses Apache 2.0 (fully open source) vs Elastic License. Both provide fast full-text search with same index structures.'
                );
            }
        } elseif (in_array($searchEngine, ['elasticsearch5', 'elasticsearch6'])) {
            $this->collector->add(
                'search',
                'Upgrade Elasticsearch version',
                Recommendation::PRIORITY_MEDIUM,
                "Using outdated {$searchEngine}. Upgrade to Elasticsearch 7.x/8.x or OpenSearch for better performance and security.",
                'Older Elasticsearch versions lack performance improvements and security patches. ES 7+ has 30% better indexing speed, ' .
                'reduced memory usage, and better relevance scoring. Consider OpenSearch as open-source alternative.'
            );
        } elseif ($searchEngine === 'mysql') {
            $this->collector->add(
                'search',
                'Upgrade from MySQL to Elasticsearch/OpenSearch',
                Recommendation::PRIORITY_HIGH,
                'MySQL search is deprecated and significantly slower than Elasticsearch/OpenSearch.',
                'MySQL FULLTEXT search is 10-100x slower than Elasticsearch/OpenSearch. ES/OS provide: relevance scoring, ' .
                'faceted search, typo tolerance, synonyms, and sub-100ms response times even with millions of products. ' .
                'MySQL search also causes database locks affecting overall site performance.'
            );
        } else {
            // Unknown search engine
            $this->collector->add(
                'search',
                'Unknown search engine detected',
                Recommendation::PRIORITY_MEDIUM,
                "Unknown search engine: {$searchEngine}. Verify configuration is correct.",
                'Supported search engines: Elasticsearch 7.x/8.x, OpenSearch 1.x/2.x. ' .
                'Ensure your search engine configuration matches your installed version.'
            );
        }
    }

    private function detectSearchEngineFromEnv(): ?string
    {
        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (!file_exists($envPath)) {
                return null;
            }

            $env = include $envPath;

            // Check for search engine in system config
            if (isset($env['system']['default']['catalog']['search']['engine'])) {
                return $env['system']['default']['catalog']['search']['engine'];
            }

            // Check for ElasticSuite or other search modules
            if (isset($env['modules'])) {
                if (isset($env['modules']['Smile_ElasticsuiteCore']) && $env['modules']['Smile_ElasticsuiteCore'] == 1) {
                    // ElasticSuite typically uses ES7/8
                    return 'elasticsearch7';
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function checkSearchEngineConnection(): void
    {
        $configLoader = new EnhancedConfigLoader($this->magentoRoot);
        $searchEngine = $configLoader->getConfigValue('catalog/search/engine');

        if (!$searchEngine || $searchEngine === 'mysql') {
            return; // Skip connection check for MySQL or unconfigured
        }

        // Get hostname and port with proper configuration hierarchy
        $searchEngine = strtolower($searchEngine);
        
        // Try engine-specific paths first
        $esHost = $configLoader->getConfigValue("catalog/search/{$searchEngine}_server_hostname");
        $esPort = $configLoader->getConfigValue("catalog/search/{$searchEngine}_server_port");
        
        // Fallback to generic paths
        if (empty($esHost)) {
            $esHost = $configLoader->getConfigValue('catalog/search/elasticsearch_server_hostname') ?:
                    $configLoader->getConfigValue('catalog/search/opensearch_server_hostname');
        }
        
        if (empty($esPort)) {
            $esPort = $configLoader->getConfigValue('catalog/search/elasticsearch_server_port') ?:
                    $configLoader->getConfigValue('catalog/search/opensearch_server_port') ?: '9200';
        }

        if (empty($esHost)) {
            $this->collector->add(
                'search',
                'Search engine hostname not configured',
                Recommendation::PRIORITY_HIGH,
                'Search engine is set but hostname is missing. Configure the server hostname.',
                'Without proper hostname configuration, Magento cannot connect to your search engine. ' .
                'Set the appropriate hostname and port in Stores > Configuration > Catalog > Catalog Search.'
            );
            return;
        }

        // Test connection with timeout
        $errno = 0;
        $errstr = '';
        $timeout = 1.0;

        $socket = @stream_socket_client(
            "tcp://{$esHost}:{$esPort}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            $this->collector->add(
                'search',
                'Search engine connection failed',
                Recommendation::PRIORITY_HIGH,
                "Unable to connect to search engine at {$esHost}:{$esPort}. Error: {$errstr} ({$errno})",
                'Search engine connectivity is critical for catalog functionality. Check: ' .
                '1) Service is running: systemctl status elasticsearch/opensearch, ' .
                '2) Firewall allows port 9200, 3) Correct hostname/IP configuration, ' .
                '4) Authentication if required. Without connection, search falls back to MySQL (10-100x slower).'
            );
        } else {
            fclose($socket);
            // Additional validation for engine type
            $this->validateSearchEngineType($esHost, $esPort, $searchEngine);
        }
    }

    private function validateSearchEngineType(string $host, string $port, string $configuredEngine): void
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 2.0,
                    'header' => "Accept: application/json\r\n"
                ]
            ]);

            $infoUrl = "http://{$host}:{$port}/";
            $response = @file_get_contents($infoUrl, false, $context);

            if ($response) {
                $info = json_decode($response, true);
                
                if (isset($info['version'])) {
                    $actualEngine = null;
                    $version = $info['version']['number'] ?? 'unknown';
                    
                    // Detect actual engine type
                    if (isset($info['version']['distribution']) && $info['version']['distribution'] === 'opensearch') {
                        $actualEngine = 'opensearch';
                    } elseif (isset($info['version']['lucene_version'])) {
                        // It's Elasticsearch
                        $majorVersion = intval(explode('.', $version)[0]);
                        $actualEngine = $majorVersion >= 8 ? 'elasticsearch8' : 'elasticsearch7';
                    }
                    
                    // Compare with configured engine
                    if ($actualEngine && !$this->enginesAreCompatible($configuredEngine, $actualEngine)) {
                        $this->collector->add(
                            'search',
                            'Search engine configuration mismatch',
                            Recommendation::PRIORITY_HIGH,
                            "Configured: {$configuredEngine}, but detected: {$actualEngine} {$version}. Update configuration to match actual engine.",
                            'Engine configuration must match the actual running service for proper API compatibility. ' .
                            'Mismatched configuration can cause indexing failures and search errors.'
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            // Silent fail - validation is optional
        }
    }

    private function enginesAreCompatible(string $configured, string $actual): bool
    {
        // All OpenSearch variants are compatible
        if (strpos($configured, 'opensearch') !== false && strpos($actual, 'opensearch') !== false) {
            return true;
        }
        
        // ES7 and ES8 can sometimes be compatible depending on features used
        if (strpos($configured, 'elasticsearch') !== false && strpos($actual, 'elasticsearch') !== false) {
            return true;
        }
        
        // Exact match
        return $configured === $actual;
    }

    private function detectActualSearchEngine(string $host, string $port): void
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 1.0,
                    'header' => "Accept: application/json\r\n"
                ]
            ]);

            $infoUrl = "http://{$host}:{$port}/";
            $response = @file_get_contents($infoUrl, false, $context);

            if ($response) {
                $info = json_decode($response, true);

                // Check version info to determine actual engine
                if (isset($info['version']['distribution']) && $info['version']['distribution'] === 'opensearch') {
                    // It's OpenSearch
                    $version = $info['version']['number'] ?? 'unknown';
                    $this->collector->add(
                        'search',
                        'OpenSearch detected and running',
                        Recommendation::PRIORITY_LOW,
                        "OpenSearch {$version} is running at {$host}:{$port}",
                        'OpenSearch provides identical performance to Elasticsearch with open-source licensing.'
                    );
                }
            }
        } catch (\Exception $e) {
            // Silent fail - detection is optional
        }
    }

    private function validateSearchEngineConfiguration(): void
    {
        $searchEngine = $this->coreConfig['catalog/search/engine'] ?? null;

        if (!in_array($searchEngine, ['elasticsearch7', 'elasticsearch8', 'opensearch'], true)) {
            return;
        }

        $esHost = $this->coreConfig['catalog/search/elasticsearch_server_hostname'] ??
            $this->coreConfig['catalog/search/opensearch_server_hostname'] ?? null;
        $esPort = $this->coreConfig['catalog/search/elasticsearch_server_port'] ??
            $this->coreConfig['catalog/search/opensearch_server_port'] ?? null;

        if (!$esHost || !$esPort) {
            return;
        }

        // Try to get cluster settings
        $this->validateElasticsearchSettings($esHost, $esPort);
    }

    private function validateElasticsearchSettings(string $host, string $port): void
    {
        try {
            // First check basic connectivity
            $errno = 0;
            $errstr = '';
            $timeout = 1.0;

            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT
            );

            if (!$socket) {
                return; // Already handled by checkSearchEngineConnection
            }
            fclose($socket);

            // Now check settings via HTTP
            $criticalSettings = [
                'index.max_result_window' => [
                    'recommended' => 10000,
                    'minimum' => 10000,
                    'description' => 'Maximum number of results for search queries'
                ],
                'index.mapping.total_fields.limit' => [
                    'recommended' => 1000,
                    'minimum' => 1000,
                    'description' => 'Maximum number of fields in an index'
                ],
                'indices.query.bool.max_clause_count' => [
                    'recommended' => 1024,
                    'minimum' => 1024,
                    'description' => 'Maximum number of boolean clauses in queries'
                ]
            ];

            // Get index prefix from config
            $indexPrefix = $this->coreConfig['catalog/search/elasticsearch_index_prefix'] ??
                $this->coreConfig['catalog/search/opensearch_index_prefix'] ??
                'magento2';

            // Try to get index settings
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 2.0,
                    'header' => "Accept: application/json\r\n"
                ]
            ]);

            // Check cluster settings
            $clusterSettingsUrl = "http://{$host}:{$port}/_cluster/settings?include_defaults=true";
            $clusterSettings = @file_get_contents($clusterSettingsUrl, false, $context);

            if ($clusterSettings) {
                $settings = json_decode($clusterSettings, true);
                $issues = [];

                foreach ($criticalSettings as $setting => $config) {
                    $currentValue = $this->getNestedValue($settings, $setting);

                    if ($currentValue !== null && $currentValue < $config['minimum']) {
                        $issues[] = sprintf(
                            "• %s is set to %s (recommended: %s) - %s",
                            $setting,
                            $currentValue,
                            $config['recommended'],
                            $config['description']
                        );
                    }
                }

                if (!empty($issues)) {
                    $this->collector->add(
                        'search',
                        'Elasticsearch/OpenSearch settings need optimization',
                        Recommendation::PRIORITY_HIGH,
                        "Critical search engine settings are below recommended values:\n\n" . implode("\n", $issues),
                        'These settings directly impact search functionality and performance. Low values can cause: ' .
                        '1) Search queries failing with "Result window too large" errors, ' .
                        '2) Indexing failures for products with many attributes, ' .
                        '3) Complex filter combinations not working. ' .
                        'Update via: PUT _cluster/settings with appropriate values. ' .
                        'Document these settings to prevent removal during upgrades!'
                    );
                }
            }

            // Check specific index settings
            $this->validateIndexSpecificSettings($host, $port, $indexPrefix);

        } catch (\Exception $e) {
            // Log error but don't fail
            $this->collector->add(
                'search',
                'Unable to validate Elasticsearch/OpenSearch settings',
                Recommendation::PRIORITY_LOW,
                'Could not connect to validate search engine settings. Manual verification recommended.',
                'Ensure your DevOps team maintains critical settings during upgrades: ' .
                'index.max_result_window (10000+), indices.query.bool.max_clause_count (1024+). ' .
                'Create documentation of required settings to prevent configuration loss.'
            );
        }
    }

    private function validateIndexSpecificSettings(string $host, string $port, string $indexPrefix): void
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 2.0,
                    'header' => "Accept: application/json\r\n"
                ]
            ]);

            // Get all Magento indices
            $indicesUrl = "http://{$host}:{$port}/{$indexPrefix}_*/_settings";
            $indicesSettings = @file_get_contents($indicesUrl, false, $context);

            if ($indicesSettings) {
                $indices = json_decode($indicesSettings, true);
                $indexIssues = [];

                foreach ($indices as $indexName => $indexData) {
                    $settings = $indexData['settings']['index'] ?? [];

                    // Check max_result_window
                    $maxResultWindow = $settings['max_result_window'] ?? 10000;
                    if ($maxResultWindow < 10000) {
                        $indexIssues[] = sprintf(
                            "%s: max_result_window=%d (should be ≥10000)",
                            $indexName,
                            $maxResultWindow
                        );
                    }

                    // Check number of shards (performance consideration)
                    $numberOfShards = $settings['number_of_shards'] ?? 1;
                    $numberOfReplicas = $settings['number_of_replicas'] ?? 1;

                    if ($numberOfShards > 5) {
                        $indexIssues[] = sprintf(
                            "%s: %d shards might be excessive for Magento catalog",
                            $indexName,
                            $numberOfShards
                        );
                    }
                }

                if (!empty($indexIssues)) {
                    $this->collector->add(
                        'search',
                        'Index-specific settings need adjustment',
                        Recommendation::PRIORITY_MEDIUM,
                        "Found index configuration issues:\n\n" . implode("\n", array_slice($indexIssues, 0, 5)),
                        'Per-index settings affect search behavior and performance. Incorrect settings cause pagination issues ' .
                        'and slow queries. Fix with: PUT /{index}/_settings {"index":{"max_result_window":10000}}. ' .
                        'Consider reindexing after changes: bin/magento indexer:reindex catalogsearch_fulltext'
                    );
                }
            }
        } catch (\Exception $e) {
            // Silent fail - settings check is optional
        }
    }

    private function getNestedValue(array $array, string $path)
    {
        $keys = explode('.', $path);
        $value = $array;

        foreach ($keys as $key) {
            // Check in defaults first, then persistent, then transient
            if (isset($value['defaults'][$key])) {
                $value = $value['defaults'][$key];
            } elseif (isset($value['persistent'][$key])) {
                $value = $value['persistent'][$key];
            } elseif (isset($value['transient'][$key])) {
                $value = $value['transient'][$key];
            } elseif (isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }
}
