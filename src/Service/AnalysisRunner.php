<?php

declare(strict_types=1);

namespace M2Performance\Service;

use M2Performance\Analyzer\AdobeCommerceAnalyzer;
use M2Performance\Analyzer\APIRateLimitingAnalyzer;
use M2Performance\Analyzer\CacheAnalyzer;
use M2Performance\Analyzer\CodebaseAnalyzer;
use M2Performance\Analyzer\ConfigurationAnalyzer;
use M2Performance\Analyzer\DatabaseAnalyzer;
use M2Performance\Analyzer\FrontendAnalyzer;
use M2Performance\Analyzer\HttpProtocolAnalyzer;
use M2Performance\Analyzer\IndexersAnalyzer;
use M2Performance\Analyzer\LayoutCacheAnalyzer;
use M2Performance\Analyzer\ModulesAnalyzer;
use M2Performance\Analyzer\OpCacheAnalyzer;
use M2Performance\Analyzer\RedisEnhancedAnalyzer;
use M2Performance\Analyzer\SecurityChecklistAnalyzer;
use M2Performance\Analyzer\ServerUptimeAnalyzer;
use M2Performance\Analyzer\StaticCodeAnalyzer;
use M2Performance\Analyzer\VarnishPerformanceAnalyzer;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

/**
 * Reusable analysis orchestration extracted from M2PerformanceCommand.
 *
 * Provides a single entry point for running analyzers so the CLI command,
 * the MCP server, and future API surfaces share identical behaviour.
 */
class AnalysisRunner
{
    private string $magentoRoot;

    private RecommendationCollector $collector;

    /** @var array<string, object> */
    private array $allAvailableAnalyzers = [];

    /** @var array<string, float> */
    private array $analyzerTimings = [];

    private float $totalExecutionTime = 0.0;

    private bool $isAdobeCommerce = false;

    private ?ToolConfigLoader $toolConfig;

    private bool $isDevModeAware = false;

    public function __construct(string $magentoRoot, ?ToolConfigLoader $toolConfig = null)
    {
        $this->magentoRoot = $magentoRoot;
        $this->collector = new RecommendationCollector();
        $this->toolConfig = $toolConfig;
        $this->initializeAnalyzers();
    }

    private function initializeAnalyzers(): void
    {
        $configLoader = new EnhancedConfigLoader($this->magentoRoot);
        $coreConfig = $configLoader->getAllConfig();

        $xmlReader = new XmlReader($this->magentoRoot);
        $this->isAdobeCommerce = $xmlReader->isAdobeCommerce();

        $envLoader = new EnvironmentLoader($this->magentoRoot);

        $this->allAvailableAnalyzers = [
            'config' => new ConfigurationAnalyzer(
                $this->magentoRoot,
                $coreConfig,
                $this->isAdobeCommerce,
                $this->collector
            ),
            'cache' => new CacheAnalyzer($this->magentoRoot, $coreConfig, $this->collector),
            'database' => new DatabaseAnalyzer($this->magentoRoot, $this->collector),
            'modules' => new ModulesAnalyzer($this->magentoRoot, $this->collector),
            'codebase' => new CodebaseAnalyzer($this->magentoRoot, $this->collector),
            'static-code' => new StaticCodeAnalyzer($this->magentoRoot, $this->collector),
            'frontend' => new FrontendAnalyzer($this->magentoRoot, $coreConfig, $this->collector),
            'indexers' => new IndexersAnalyzer($this->magentoRoot, $coreConfig, $this->collector),
            'opcache' => new OpCacheAnalyzer($this->collector, $this->magentoRoot),
            'redis' => new RedisEnhancedAnalyzer($this->magentoRoot, $this->collector),
            'api-security' => new APIRateLimitingAnalyzer($this->magentoRoot, $this->collector),
            'security' => new SecurityChecklistAnalyzer($this->magentoRoot, $this->collector),
            'layout-cache' => new LayoutCacheAnalyzer($this->magentoRoot, $this->collector),
            'uptime' => new ServerUptimeAnalyzer($this->collector),
            'protocol' => new HttpProtocolAnalyzer($this->magentoRoot, $coreConfig, $this->collector),
            'varnish' => new VarnishPerformanceAnalyzer($this->magentoRoot, $this->collector),
        ];

        if ($this->isAdobeCommerce === true) {
            $this->allAvailableAnalyzers['commerce'] = new AdobeCommerceAnalyzer(
                $this->magentoRoot,
                $coreConfig,
                true,
                $envLoader,
                $this->collector
            );
        }

        // Apply tool configuration: disabled analyzers
        if ($this->toolConfig !== null) {
            foreach ($this->toolConfig->getDisabledAnalyzers() as $disabled) {
                unset($this->allAvailableAnalyzers[$disabled]);
            }
        }
    }

    /**
     * @return string[] Available analyzer identifiers.
     */
    public function getAvailableAnalyzerNames(): array
    {
        return array_keys($this->allAvailableAnalyzers);
    }

    public function isAdobeCommerce(): bool
    {
        return $this->isAdobeCommerce;
    }

    /**
     * Propagate the Magento mode to analyzers. Developer-mode awareness
     * relaxes production-only checks, so it must be identical between a
     * baseline and the run compared against it.
     */
    public function configureMode(string $magentoMode, bool $isDevModeAware): void
    {
        $this->isDevModeAware = $magentoMode === 'developer' && $isDevModeAware === true;

        foreach ($this->allAvailableAnalyzers as $analyzer) {
            if (method_exists($analyzer, 'setDevModeAware') === true) {
                $analyzer->setDevModeAware($isDevModeAware);
            }
            if (method_exists($analyzer, 'setMagentoMode') === true) {
                $analyzer->setMagentoMode($magentoMode);
            }
        }
    }

    /**
     * Analysis mode label stored with baselines for like-for-like comparisons.
     */
    public function getAnalysisMode(): string
    {
        return $this->isDevModeAware === true ? 'developer-aware' : 'production-focused';
    }

    /**
     * Resolve analyzers for a given profile, honouring custom profiles
     * defined in the tool configuration file.
     *
     * @return array<string, object>
     */
    public function resolveProfile(string $profile): array
    {
        if ($this->toolConfig !== null && $this->toolConfig->hasProfile($profile) === true) {
            return $this->filterByNames($this->toolConfig->getProfileAnalyzers($profile));
        }

        return match ($profile) {
            'basic' => $this->filterByNames(['config', 'cache', 'redis', 'opcache', 'uptime', 'varnish']),
            'security' => $this->filterByNames(['security', 'api-security', 'uptime']),
            default => $this->allAvailableAnalyzers,
        };
    }

    /**
     * @param string[] $names
     * @return array<string, object>
     */
    private function filterByNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $name = trim($name);
            if (isset($this->allAvailableAnalyzers[$name]) === true) {
                $result[$name] = $this->allAvailableAnalyzers[$name];
            }
        }

        return $result;
    }

    /**
     * Run the analysis and return recommendations.
     *
     * @param string $profile Profile name (basic|full|security|custom).
     * @param string[]|null $areas Optional explicit analyzer subset (overrides profile).
     * @param bool $continueOnError When true, a failing analyzer is recorded but does not abort the run.
     * @param callable|null $onAnalyzerDone Progress hook, receives the analyzer identifier.
     * @return Recommendation[]
     */
    public function run(
        string $profile = 'full',
        ?array $areas = null,
        bool $continueOnError = true,
        ?callable $onAnalyzerDone = null
    ): array {
        $this->collector->clear();
        $this->analyzerTimings = [];

        $analyzers = $this->resolveProfile($profile);
        if ($areas !== null && count($areas) > 0) {
            $analyzers = $this->filterByNames($areas);
        }

        $totalStart = microtime(true);

        foreach ($analyzers as $name => $analyzer) {
            $start = microtime(true);
            try {
                $analyzer->analyze();
            } catch (\Throwable $e) {
                if ($continueOnError === false) {
                    throw $e;
                }
                $this->collector->add(
                    'tool',
                    sprintf('Analyzer "%s" failed and was skipped', $name),
                    Recommendation::PRIORITY_LOW,
                    $e->getMessage(),
                    'The analysis continued with the remaining analyzers (--continue-on-error). '
                    . 'Investigate the underlying service availability (Redis, MySQL, OpenSearch).'
                );
            }
            $this->analyzerTimings[$name] = (microtime(true) - $start) * 1000;
            if ($onAnalyzerDone !== null) {
                $onAnalyzerDone($name);
            }
        }

        $this->totalExecutionTime = (microtime(true) - $totalStart) * 1000;

        $recommendations = $this->collector->getRecommendations();
        usort($recommendations, fn($a, $b) => $b->getPriority() <=> $a->getPriority());

        return $recommendations;
    }

    /**
     * @return array<string, float> Per-analyzer wall time in milliseconds.
     */
    public function getAnalyzerTimings(): array
    {
        return $this->analyzerTimings;
    }

    public function getTotalExecutionTime(): float
    {
        return $this->totalExecutionTime;
    }

    /**
     * Compute the weighted performance score for a set of recommendations.
     *
     * Weights default to High=10 / Medium=4 / Low=1 and can be overridden
     * via the tool configuration file (scoring section).
     *
     * @param Recommendation[] $recommendations
     * @return array{score: float, grade: string, penalty: float, counts: array<string,int>}
     */
    public function computeScore(array $recommendations): array
    {
        $weights = [
            Recommendation::PRIORITY_HIGH => 10.0,
            Recommendation::PRIORITY_MEDIUM => 4.0,
            Recommendation::PRIORITY_LOW => 1.0,
        ];

        if ($this->toolConfig !== null) {
            $weights[Recommendation::PRIORITY_HIGH] = $this->toolConfig->getScoringWeight('high', 10.0);
            $weights[Recommendation::PRIORITY_MEDIUM] = $this->toolConfig->getScoringWeight('medium', 4.0);
            $weights[Recommendation::PRIORITY_LOW] = $this->toolConfig->getScoringWeight('low', 1.0);
        }

        $penalty = 0.0;
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($recommendations as $rec) {
            $priority = $rec->getPriority();
            $penalty += $weights[$priority] ?? 1.0;

            if ($priority === Recommendation::PRIORITY_HIGH) {
                $counts['high']++;
            } elseif ($priority === Recommendation::PRIORITY_MEDIUM) {
                $counts['medium']++;
            } else {
                $counts['low']++;
            }
        }

        $score = max(0.0, 100.0 - $penalty);

        return [
            'score' => round($score, 1),
            'grade' => $this->resolveGrade($score),
            'penalty' => $penalty,
            'counts' => $counts,
        ];
    }

    private function resolveGrade(float $score): string
    {
        return match (true) {
            $score >= 95.0 => 'A+',
            $score >= 90.0 => 'A',
            $score >= 80.0 => 'B',
            $score >= 70.0 => 'C',
            $score >= 60.0 => 'D',
            default => 'F',
        };
    }

    /**
     * Serialize recommendations to a plain array (shared by JSON export,
     * MCP responses and baseline snapshots).
     *
     * @param Recommendation[] $recommendations
     * @return array<int, array<string, mixed>>
     */
    public function toArray(array $recommendations): array
    {
        return array_map(function (Recommendation $rec): array {
            $result = [
                'area' => $rec->getArea(),
                'priority' => $rec->getPriority(),
                'priority_label' => match ($rec->getPriority()) {
                    Recommendation::PRIORITY_HIGH => 'High',
                    Recommendation::PRIORITY_MEDIUM => 'Medium',
                    Recommendation::PRIORITY_LOW => 'Low',
                    default => 'Unknown',
                },
                'title' => $rec->getTitle(),
                'details' => $rec->getDetails(),
                'explanation' => $rec->getExplanation(),
            ];

            if ($rec->hasFiles() === true) {
                $result['affected_files'] = $rec->getFiles();
            }

            if ($rec->hasMetadata() === true) {
                $result['metadata'] = $rec->getMetadata();
            }

            return $result;
        }, array_values($recommendations));
    }
}
