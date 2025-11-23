<?php

namespace M2Performance\Service;

class EnhancedConfigLoader
{
    private string $magentoRoot;
    private ?int $storeId = null;
    private ?int $websiteId = null;
    private ?\PDO $pdo = null;

    public function __construct(string $magentoRoot, ?int $storeId = null, ?int $websiteId = null)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->storeId = $storeId;
        $this->websiteId = $websiteId;
    }

    /**
     * Get configuration value respecting Magento's hierarchy
     */
    public function getConfigValue(string $path, $default = null)
    {
        // 1. Try database first (highest priority)
        $dbValue = $this->getConfigFromDatabase($path);
        if ($dbValue !== null) {
            return $dbValue;
        }

        // 2. Try config.php
        $configPhpValue = $this->getConfigFromConfigPhp($path);
        if ($configPhpValue !== null) {
            return $configPhpValue;
        }

        // 3. Try env.php
        $envValue = $this->getConfigFromEnvPhp($path);
        if ($envValue !== null) {
            return $envValue;
        }

        // 4. Try environment variables
        $envVarValue = $this->getConfigFromEnvVar($path);
        if ($envVarValue !== null) {
            return $envVarValue;
        }

        return $default;
    }

    /**
     * Load all core configuration with proper hierarchy
     */
    public function getAllConfig(): array
    {
        $config = [];

        // Start with env.php (lowest priority)
        $config = array_merge($config, $this->loadEnvPhpConfig());

        // Merge config.php
        $config = array_merge($config, $this->loadConfigPhpConfig());

        // Merge database config (highest priority)
        $config = array_merge($config, $this->loadDatabaseConfig());

        return $config;
    }

    private function getConfigFromDatabase(string $path): ?string
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return null;
        }

        try {
            // Check store view level first (if store ID provided)
            if ($this->storeId !== null) {
                $stmt = $pdo->prepare("
                    SELECT value FROM core_config_data 
                    WHERE path = ? AND scope = 'stores' AND scope_id = ?
                ");
                $stmt->execute([$path, $this->storeId]);
                $result = $stmt->fetchColumn();
                if ($result !== false) {
                    return $result;
                }
            }

            // Check website level (if website ID provided)
            if ($this->websiteId !== null) {
                $stmt = $pdo->prepare("
                    SELECT value FROM core_config_data 
                    WHERE path = ? AND scope = 'websites' AND scope_id = ?
                ");
                $stmt->execute([$path, $this->websiteId]);
                $result = $stmt->fetchColumn();
                if ($result !== false) {
                    return $result;
                }
            }

            // Check global level
            $stmt = $pdo->prepare("
                SELECT value FROM core_config_data 
                WHERE path = ? AND scope = 'default' AND scope_id = 0
            ");
            $stmt->execute([$path]);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    private function loadDatabaseConfig(): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return [];
        }

        try {
            $config = [];

            // Load in priority order: global → website → store
            $scopes = [
                ['scope' => 'default', 'scope_id' => 0],
            ];

            if ($this->websiteId !== null) {
                $scopes[] = ['scope' => 'websites', 'scope_id' => $this->websiteId];
            }

            if ($this->storeId !== null) {
                $scopes[] = ['scope' => 'stores', 'scope_id' => $this->storeId];
            }

            foreach ($scopes as $scope) {
                $stmt = $pdo->prepare("
                    SELECT path, value FROM core_config_data 
                    WHERE scope = ? AND scope_id = ?
                ");
                $stmt->execute([$scope['scope'], $scope['scope_id']]);
                
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $config[$row['path']] = $row['value'];
                }
            }

            return $config;

        } catch (\Exception $e) {
            return [];
        }
    }

    private function getConfigFromEnvPhp(string $path): ?string
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return null;
        }

        $env = include $envPath;
        return $this->getNestedArrayValue($env, $path);
    }

    private function getConfigFromConfigPhp(string $path): ?string
    {
        $configPath = $this->magentoRoot . '/app/etc/config.php';
        if (!file_exists($configPath)) {
            return null;
        }

        $config = include $configPath;
        return $this->getNestedArrayValue($config, $path);
    }

    private function getConfigFromEnvVar(string $path): ?string
    {
        // Convert path to environment variable format
        // e.g., catalog/search/engine → CATALOG_SEARCH_ENGINE
        $envVar = 'MAGENTO_' . strtoupper(str_replace('/', '_', $path));
        return getenv($envVar) ?: null;
    }

    private function getNestedArrayValue(array $array, string $path)
    {
        // Handle both flat keys and nested paths
        if (isset($array[$path])) {
            return $array[$path];
        }

        // Try nested structure (system/default/...)
        $keys = explode('/', $path);
        $value = $array;

        // Check if it's in system/default structure
        if (isset($array['system']['default'])) {
            $value = $array['system']['default'];
        }

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function getPdo(): ?\PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (!file_exists($envPath)) {
                return null;
            }

            $env = include $envPath;
            $dbConfig = $env['db']['connection']['default'] ?? null;
            if (!$dbConfig) {
                return null;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['dbname'] ?? ''
            );

            $this->pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 2);

            return $this->pdo;

        } catch (\Exception $e) {
            return null;
        }
    }

    private function loadEnvPhpConfig(): array
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return [];
        }

        $env = include $envPath;
        $config = [];

        // Extract system/default configs
        if (isset($env['system']['default'])) {
            $config = $this->flattenArray($env['system']['default']);
        }

        return $config;
    }

    private function loadConfigPhpConfig(): array
    {
        $configPath = $this->magentoRoot . '/app/etc/config.php';
        if (!file_exists($configPath)) {
            return [];
        }

        $config = include $configPath;
        
        // Only return actual config values, not module states
        return $this->flattenArray($config['system']['default'] ?? []);
    }

    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix ? $prefix . '/' . $key : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        return $result;
    }
}
