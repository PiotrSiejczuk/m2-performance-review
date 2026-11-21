<?php

declare(strict_types=1);

namespace M2Performance\Service;

/**
 * Loads optional tool configuration from a .m2perf.json file.
 *
 * Lookup order:
 *   1. Explicit path passed via --config
 *   2. <magento-root>/.m2perf.json
 *   3. Current working directory ./.m2perf.json
 *
 * Example:
 * {
 *   "scoring": { "high_weight": 10.0, "medium_weight": 4.0, "low_weight": 1.0 },
 *   "analyzers": { "disabled": ["uptime"] },
 *   "profiles": { "production": ["config", "cache", "database", "security"] },
 *   "thresholds": { "min_score": 80 }
 * }
 */
class ToolConfigLoader
{
    public const DEFAULT_FILENAME = '.m2perf.json';

    /** @var array<string, mixed> */
    private array $config = [];

    private bool $isLoaded = false;

    private string $loadedFrom = '';

    public function __construct(?string $explicitPath = null, ?string $magentoRoot = null)
    {
        $candidates = [];

        if ($explicitPath !== null && $explicitPath !== '') {
            $candidates[] = $explicitPath;
        }

        if ($magentoRoot !== null && $magentoRoot !== '') {
            $candidates[] = rtrim($magentoRoot, '/') . '/' . self::DEFAULT_FILENAME;
        }

        $candidates[] = getcwd() . '/' . self::DEFAULT_FILENAME;

        foreach ($candidates as $candidate) {
            if (is_readable($candidate) === true) {
                $decoded = json_decode((string)file_get_contents($candidate), true);
                if (is_array($decoded) === true) {
                    $this->config = $decoded;
                    $this->isLoaded = true;
                    $this->loadedFrom = $candidate;
                    break;
                }
            }
        }
    }

    public function isLoaded(): bool
    {
        return $this->isLoaded;
    }

    public function getLoadedFrom(): string
    {
        return $this->loadedFrom;
    }

    public function getScoringWeight(string $level, float $default): float
    {
        $key = $level . '_weight';
        $value = $this->config['scoring'][$key] ?? $default;

        return is_numeric($value) === true ? (float)$value : $default;
    }

    /**
     * @return string[]
     */
    public function getDisabledAnalyzers(): array
    {
        $disabled = $this->config['analyzers']['disabled'] ?? [];

        return is_array($disabled) === true ? array_map('strval', $disabled) : [];
    }

    public function hasProfile(string $name): bool
    {
        return isset($this->config['profiles'][$name]) === true
            && is_array($this->config['profiles'][$name]) === true;
    }

    /**
     * @return string[]
     */
    public function getProfileAnalyzers(string $name): array
    {
        if ($this->hasProfile($name) === false) {
            return [];
        }

        return array_map('strval', $this->config['profiles'][$name]);
    }

    public function getMinScoreThreshold(): ?float
    {
        $value = $this->config['thresholds']['min_score'] ?? null;

        return is_numeric($value) === true ? (float)$value : null;
    }
}
