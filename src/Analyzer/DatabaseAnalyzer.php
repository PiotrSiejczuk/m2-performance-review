<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class DatabaseAnalyzer implements AnalyzerInterface
{
    private string $magentoRoot;
    private RecommendationCollector $collector;
    private array $dbConfig;
    private ?\PDO $pdo = null;

    public function __construct(string $magentoRoot, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->collector = $collector;
        $this->loadDbConfig();
    }

    public function analyze(): void
    {
        $this->analyzeDatabase();
        $this->checkForInnoDBDeadlocks();
    }

    private function loadDbConfig(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            $this->dbConfig = [];
            return;
        }
        $env = include $envPath;
        $connection = $env['db']['connection']['default'] ?? [];
        $this->dbConfig = [
            'host' => $connection['host'] ?? '127.0.0.1',
            'port' => $connection['port'] ?? '3306',
            'dbname' => $connection['dbname'] ?? '',
            'username' => $connection['username'] ?? '',
            'password' => $connection['password'] ?? '',
            'charset' => $connection['charset'] ?? 'utf8',
        ];
    }

    private function getPdo(): ?\PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (empty($this->dbConfig['dbname'])) {
            return null;
        }
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $this->dbConfig['host'],
                $this->dbConfig['port'],
                $this->dbConfig['dbname'],
                $this->dbConfig['charset']
            );
            $this->pdo = new \PDO($dsn, $this->dbConfig['username'], $this->dbConfig['password']);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $this->pdo;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function analyzeDatabase(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;

            if (isset($env['db']['connection'])) {
                foreach ($env['db']['connection'] as $connection) {
                    if (!empty($connection['profiler'])) {
                        $this->collector->add(
                            'database',
                            'Disable database profiler',
                            Recommendation::PRIORITY_HIGH,
                            'Database profiler is enabled, which significantly impacts performance and should be disabled in production.'
                        );
                    }
                }
            }
        }

        $this->analyzeLargeDatabaseTables();
    }

    private function analyzeLargeDatabaseTables(): void
    {
        $cliPath = $this->magentoRoot . '/bin/magento';
        if (!is_executable($cliPath)) {
            return;
        }

        $command = escapeshellcmd($cliPath) . ' info:database:tables';
        $output = [];
        $returnVar = 0;
        @exec($command . ' 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            return;
        }

        $largeTables = [];
        foreach ($output as $line) {
            if (preg_match('/^\|\s*([^|]+?)\s*\|\s*([0-9.]+)\s*MB\s*\|/i', $line, $m)) {
                $sizeMB = (float)$m[2];
                if ($sizeMB > 1024) {
                    $sizeGB = $sizeMB / 1024;
                    $largeTables[] = ['name' => trim($m[1]), 'size' => number_format($sizeGB, 2) . ' GB'];
                }
            }
        }

        if (count($largeTables) > 5) {
            $details = array_map(fn($t) => "  - {$t['name']}: {$t['size']}", $largeTables);
            $detailText = "Found " . count($largeTables) . " tables larger than 1GB. Tables:\n" . implode("\n", $details);
            $this->collector->add(
                'database',
                'Optimize large database tables',
                Recommendation::PRIORITY_HIGH,
                $detailText
            );
        }
    }

    private function checkForInnoDBDeadlocks(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $stmt = $pdo->query('SHOW ENGINE INNODB STATUS');
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $status = $row['Status'] ?? '';
            if (stripos($status, 'LATEST DETECTED DEADLOCK') !== false) {
                $deadlock = $this->extractDeadlockDetails($status);
                $this->collector->add(
                    'database',
                    'Investigate recent InnoDB Deadlocks',
                    Recommendation::PRIORITY_HIGH,
                    "Recent InnoDB Deadlock detected:\n" . $deadlock
                );
            }
        } catch (\Exception $e) {
            // @ToDo: Gracefull Exception
        }
    }

    private function extractDeadlockDetails(string $text): string
    {
        $lines = explode("\n", $text);
        $capture = false;
        $out = [];
        foreach ($lines as $line) {
            if (stripos($line, 'LATEST DETECTED DEADLOCK') !== false) {
                $capture = true;
            }
            if ($capture) {
                // stop at next section marker
                if (preg_match('/^[A-Z]+\s+\d+/', trim($line)) && stripos($line, 'LATEST DETECTED DEADLOCK') === false) {
                    break;
                }
                $out[] = '  ' . rtrim($line);
            }
        }
        return implode("\n", $out) ?: '  (deadlock details unavailable)';
    }

    private function detectSkuTypeMismatches(): void
    {
        try {
            $db = $this->getConnection();
            if (!$db) return;

            // Check MySQL general log or slow query log for SKU type mismatches
            // This is a heuristic check - in production, you'd analyze actual queries

            // Sample problematic query pattern
            $problematicPattern = "WHERE.*sku.*IN.*\([0-9, ]+\)";

            // Check if we can access the slow query log
            $result = $db->query("SHOW VARIABLES LIKE 'slow_query_log_file'");
            $logFile = $result->fetch(\PDO::FETCH_ASSOC);

            if ($logFile && file_exists($logFile['Value'])) {
                $this->analyzeQueryLog($logFile['Value'], $problematicPattern);
            }

            // Also check current processlist for active bad queries
            $this->checkActiveQueries($db);

        } catch (\Exception $e) {
            // Silently handle - not critical
        }
    }

    private function checkActiveQueries(\PDO $db): void
    {
        try {
            $stmt = $db->query("SHOW FULL PROCESSLIST");
            $processes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $problematicQueries = [];
            foreach ($processes as $process) {
                if (empty($process['Info'])) continue;

                $query = $process['Info'];
                // Pattern: SKU IN (numeric values without quotes)
                if (preg_match("/catalog_product_entity.*sku\s+IN\s*\(\s*[0-9]+/i", $query)) {
                    $problematicQueries[] = substr($query, 0, 100) . '...';
                }
            }

            if (!empty($problematicQueries)) {
                $this->collector->add(
                    'database',
                    'SKU type mismatch detected in queries',
                    Recommendation::PRIORITY_HIGH,
                    'Found queries comparing VARCHAR SKU column with unquoted integers, forcing full table scans.',
                    'MySQL cannot use SKU index when comparing VARCHAR to integers. This forces type conversion on every row. ' .
                    'Example: WHERE sku IN (42,166) scans entire table. Fix: WHERE sku IN (\'42\',\'166\') uses index. ' .
                    'Impact: Query time reduced from seconds to milliseconds. Check your ProductRepository usage and API calls.'
                );
            }
        } catch (\Exception $e) {
            // Ignore
        }
    }

    private function analyzeSampleQueries(\PDO $db): void
    {
        // Demonstrate the performance difference
        try {
            // Test query 1: Bad practice (INT comparison)
            $start = microtime(true);
            $stmt1 = $db->prepare("EXPLAIN SELECT sku FROM catalog_product_entity WHERE sku IN (42, 166, 281) LIMIT 1");
            $stmt1->execute();
            $explain1 = $stmt1->fetch(\PDO::FETCH_ASSOC);

            // Test query 2: Good practice (STRING comparison)
            $stmt2 = $db->prepare("EXPLAIN SELECT sku FROM catalog_product_entity WHERE sku IN ('42', '166', '281') LIMIT 1");
            $stmt2->execute();
            $explain2 = $stmt2->fetch(\PDO::FETCH_ASSOC);

            if ($explain1['type'] === 'ALL' && $explain2['type'] === 'ref') {
                $this->collector->add(
                    'database',
                    'Optimize SKU query patterns',
                    Recommendation::PRIORITY_HIGH,
                    'Database is vulnerable to SKU type mismatch performance issues.',
                    'Test confirmed: INT SKU lookups use type=ALL (full scan), STRING lookups use type=ref (index). ' .
                    'Common in: REST API filters, GraphQL queries, custom modules. Fix: Ensure all SKU values are quoted strings. ' .
                    'Performance impact: 100-1000x faster queries, reduced CPU usage, better concurrent request handling.'
                );
            }
        } catch (\Exception $e) {
            // Test queries might fail on some setups
        }
    }
}
