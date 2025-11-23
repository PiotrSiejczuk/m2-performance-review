<?php
namespace M2Performance\Service;

class XmlReader
{
    private string $magentoRoot;
    private array $coreConfig = [];
    private bool $isAdobeCommerce = false;

    public function __construct(string $magentoRoot)
    {
        $this->magentoRoot = $magentoRoot;
        $this->loadEnvPhp();
        $this->detectAdobeCommerce();
    }

    private function loadEnvPhp(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            $this->coreConfig = $env;
        }
    }

    private function detectAdobeCommerce(): void
    {
        $composerJson = $this->magentoRoot . '/composer.json';
        if (!is_readable($composerJson)) {
            return;
        }
        $data = json_decode(file_get_contents($composerJson), true);
        if (empty($data['require'])) {
            return;
        }
        foreach (array_keys($data['require']) as $pkg) {
            if (stripos($pkg, 'magento/enterprise') !== false
                || stripos($pkg, 'magento/module-customer-segment') !== false
            ) {
                $this->isAdobeCommerce = true;
                break;
            }
        }
    }

    public function getCoreConfig(): array
    {
        return $this->coreConfig;
    }

    public function isAdobeCommerce(): bool
    {
        return $this->isAdobeCommerce;
    }

    public function isModuleEnabled(string $moduleName): bool
    {
        $configFile = $this->magentoRoot . '/app/etc/config.php';
        if (!is_readable($configFile)) {
            return false;
        }
        $map = include $configFile;
        return isset($map[$moduleName]) && $map[$moduleName] === 1;
    }

    /**
     * Detect MageOS version if used
     *
     * @return string|null  MageOS version or null if not MageOS
     */
    public function detectMageOS(): ?string
    {
        $composerJsonPath = $this->magentoRoot . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return null;
        }

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        if (empty($composerJson)) {
            return null;
        }

        // 1) Check for MageOS packages in "require"
        if (!empty($composerJson['require']) && is_array($composerJson['require'])) {
            foreach ($composerJson['require'] as $package => $version) {
                if (
                    strpos($package, 'mage-os/') === 0
                    || $package === 'mage-os/project-community-edition'
                ) {
                    return $version;
                }
            }
        }

        // 2) Check for MageOS repositories in "repositories"
        if (!empty($composerJson['repositories']) && is_array($composerJson['repositories'])) {
            foreach ($composerJson['repositories'] as $repo) {
                if (isset($repo['url']) && stripos($repo['url'], 'mage-os') !== false) {
                    return 'Unknown Version (MageOS repository detected)';
                }
            }
        }

        return null;
    }
}
