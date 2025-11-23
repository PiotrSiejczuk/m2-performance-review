<?php

namespace M2Performance\Trait;

trait DevModeAwareTrait
{
    protected bool $isDevModeAware = false;

    public function setDevModeAware(bool $aware): void
    {
        $this->isDevModeAware = $aware;
    }

    public function isDevModeAware(): bool
    {
        return $this->isDevModeAware;
    }

    public function isInDeveloperMode(): bool
    {
        return $this->getMagentoMode() === 'developer' && $this->isDevModeAware;
    }

    protected function getMagentoMode(): string
    {
        // Check Magento mode from env.php or MAGE_MODE environment variable
        $mageMode = getenv('MAGE_MODE');
        if ($mageMode) {
            return $mageMode;
        }

        // Check from env.php if available
        if (property_exists($this, 'magentoRoot')) {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (file_exists($envPath)) {
                $env = include $envPath;
                return $env['MAGE_MODE'] ?? 'default';
            }
        }

        return 'default';
    }
}
