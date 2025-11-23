<?php
namespace M2Performance\Service;

class EnvironmentLoader
{
    private string $magentoRoot;
    private array $dbConfig;

    public function __construct(string $magentoRoot = null)
    {
        $this->magentoRoot = $magentoRoot ?? getcwd();
        $this->loadEnvConfig();
    }

    public function setMagentoRoot(string $root): void
    {
        $this->magentoRoot = $root;
        $this->loadEnvConfig();
    }

    private function loadEnvConfig(): void
    {
        $envPhp = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPhp)) {
            throw new \RuntimeException("Cannot find env.php at: $envPhp");
        }

        $config = require $envPhp;
        if (!isset($config['db']['connection']['default'])) {
            throw new \RuntimeException('Invalid env.php: missing db.connection.default');
        }

        $this->dbConfig = $config['db']['connection']['default'];
    }

    public function getDbConfig(): array
    {
        return $this->dbConfig;
    }

    public function getMagentoRoot(): string
    {
        return $this->magentoRoot;
    }
}
