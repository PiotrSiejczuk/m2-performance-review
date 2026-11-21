<?php

declare(strict_types=1);

namespace M2Performance\Command;

use M2Performance\ToolVersion;
use M2Performance\Service\AnalysisRunner;
use M2Performance\Service\BaselineManager;
use M2Performance\Service\SarifExporter;
use M2Performance\Service\ToolConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MCP (Model Context Protocol) server mode.
 *
 * Exposes the analyzer suite, scoring, baseline management and fix
 * generation as MCP tools over stdio (JSON-RPC 2.0), so AI coding agents
 * (Claude Code, Cursor, Copilot) can run Magento 2 performance analysis
 * inside a developer workflow.
 *
 * Claude Code registration:
 *   claude mcp add m2-performance -- php m2-performance.phar mcp --magento-root=/var/www/magento2
 */
class McpServerCommand extends Command
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private string $magentoRoot = '';

    private ?AnalysisRunner $runner = null;

    private ?ToolConfigLoader $toolConfig = null;

    /** @var array<int, array<string, mixed>>|null Cached last analysis (serialized). */
    private ?array $lastResults = null;

    /** @var array{score: float, grade: string, penalty: float, counts: array<string,int>}|null */
    private ?array $lastScore = null;

    private string $lastProfile = 'full';

    protected function configure(): void
    {
        $this
            ->setName('mcp')
            ->setDescription('Run as an MCP (Model Context Protocol) server over stdio for AI coding agents')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root', getcwd())
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to .m2perf.json configuration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->magentoRoot = (string)$input->getOption('magento-root');
        $configPath = $input->getOption('config');
        $this->toolConfig = new ToolConfigLoader(
            is_string($configPath) === true ? $configPath : null,
            $this->magentoRoot
        );

        // stdio transport: newline-delimited JSON-RPC 2.0 on stdin/stdout.
        // All diagnostics go to stderr to keep the protocol channel clean.
        $stdin = fopen('php://stdin', 'rb');
        if ($stdin === false) {
            fwrite(STDERR, "Unable to open stdin\n");
            return Command::FAILURE;
        }

        fwrite(STDERR, sprintf(
            "[m2-performance] MCP server started (root: %s)\n",
            $this->magentoRoot
        ));

        while (feof($stdin) === false) {
            $line = fgets($stdin);
            if ($line === false) {
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);
            if (is_array($message) === false) {
                $this->send($this->error(null, -32700, 'Parse error'));
                continue;
            }

            $response = $this->dispatch($message);
            if ($response !== null) {
                $this->send($response);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null Null for notifications (no response).
     */
    private function dispatch(array $message): ?array
    {
        $method = (string)($message['method'] ?? '');
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) === true ? $message['params'] : [];

        // Notifications require no response
        if (array_key_exists('id', $message) === false) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->result($id, [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => [
                        'name' => 'm2-performance-review',
                        'version' => ToolVersion::VERSION,
                    ],
                ]),
                'ping' => $this->result($id, new \stdClass()),
                'tools/list' => $this->result($id, ['tools' => $this->getToolDefinitions()]),
                'tools/call' => $this->handleToolCall($id, $params),
                default => $this->error($id, -32601, 'Method not found: ' . $method),
            };
        } catch (\Throwable $e) {
            return $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'run_analysis',
                'description' => 'Run Magento 2 performance analysis. Returns weighted score, letter grade and issue counts. '
                    . 'Use get_recommendations afterwards to inspect individual findings.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'profile' => [
                            'type' => 'string',
                            'enum' => ['basic', 'full', 'security'],
                            'description' => 'Analysis profile (default: full)',
                        ],
                        'areas' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional subset of analyzers (e.g. ["cache","redis","database"])',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'list_analyzers',
                'description' => 'List available analyzer identifiers and whether the target is Adobe Commerce.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_recommendations',
                'description' => 'Return findings from the last run_analysis call, optionally filtered by priority or area. '
                    . 'Each finding includes title, details, explanation and affected files.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'min_priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'medium', 'high'],
                            'description' => 'Minimum priority filter',
                        ],
                        'area' => ['type' => 'string', 'description' => 'Filter by analyzer area'],
                        'limit' => ['type' => 'integer', 'description' => 'Max findings to return (default 25)'],
                    ],
                ],
            ],
            [
                'name' => 'export_sarif',
                'description' => 'Export the last analysis as SARIF 2.1.0 (GitHub Code Scanning format). '
                    . 'Writes the file and returns its path.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'output_path' => ['type' => 'string', 'description' => 'Target file path (default: m2-performance.sarif)'],
                    ],
                ],
            ],
            [
                'name' => 'save_baseline',
                'description' => 'Save the last analysis as a named baseline for later regression comparison.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Baseline name (e.g. "pre-deploy-2.4.7")'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'compare_baseline',
                'description' => 'Compare the last analysis against a stored baseline. Returns score delta, '
                    . 'resolved issues, introduced issues and a regression flag.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Baseline name to compare against'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'list_baselines',
                'description' => 'List stored performance baselines with their scores and creation dates.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolCall($id, array $params): array
    {
        $toolName = (string)($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) === true ? $params['arguments'] : [];

        $payload = match ($toolName) {
            'run_analysis' => $this->toolRunAnalysis($args),
            'list_analyzers' => $this->toolListAnalyzers(),
            'get_recommendations' => $this->toolGetRecommendations($args),
            'export_sarif' => $this->toolExportSarif($args),
            'save_baseline' => $this->toolSaveBaseline($args),
            'compare_baseline' => $this->toolCompareBaseline($args),
            'list_baselines' => $this->toolListBaselines(),
            default => ['error' => 'Unknown tool: ' . $toolName],
        };

        $isError = isset($payload['error']) === true;

        return $this->result($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'isError' => $isError,
        ]);
    }

    private function getRunner(): AnalysisRunner
    {
        if ($this->runner === null) {
            $this->runner = new AnalysisRunner($this->magentoRoot, $this->toolConfig);
        }

        return $this->runner;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function toolRunAnalysis(array $args): array
    {
        $profile = (string)($args['profile'] ?? 'full');
        $areas = null;
        if (isset($args['areas']) === true && is_array($args['areas']) === true && count($args['areas']) > 0) {
            $areas = array_map('strval', $args['areas']);
        }

        // Rebuild the runner for every analysis: agents typically fix
        // env.php/config.php between calls, and EnhancedConfigLoader reads
        // configuration at construction time. A cached runner would keep
        // reporting the pre-fix state (analyze -> fix -> verify would lie).
        $this->runner = new AnalysisRunner($this->magentoRoot, $this->toolConfig);
        $runner = $this->runner;
        $recommendations = $runner->run($profile, $areas, true);
        $this->lastProfile = $profile;

        $this->lastResults = $runner->toArray($recommendations);
        $this->lastScore = $runner->computeScore($recommendations);

        return [
            'score' => $this->lastScore['score'],
            'grade' => $this->lastScore['grade'],
            'issue_counts' => $this->lastScore['counts'],
            'total_issues' => count($this->lastResults),
            'execution_time_ms' => round($runner->getTotalExecutionTime(), 1),
            'analyzer_timings_ms' => array_map(fn($t) => round($t, 1), $runner->getAnalyzerTimings()),
            'next_step' => 'Call get_recommendations to inspect findings (filter with min_priority=high first).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListAnalyzers(): array
    {
        $runner = $this->getRunner();

        return [
            'analyzers' => $runner->getAvailableAnalyzerNames(),
            'is_adobe_commerce' => $runner->isAdobeCommerce(),
            'magento_root' => $this->magentoRoot,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function toolGetRecommendations(array $args): array
    {
        if ($this->lastResults === null) {
            return ['error' => 'No analysis results yet. Call run_analysis first.'];
        }

        $results = $this->lastResults;

        $minPriority = strtolower((string)($args['min_priority'] ?? ''));
        if ($minPriority !== '') {
            $threshold = match ($minPriority) {
                'high' => 3,
                'medium' => 2,
                default => 1,
            };
            $results = array_values(array_filter(
                $results,
                fn($r) => (int)($r['priority'] ?? 1) >= $threshold
            ));
        }

        $area = (string)($args['area'] ?? '');
        if ($area !== '') {
            $results = array_values(array_filter(
                $results,
                fn($r) => ($r['area'] ?? '') === $area
            ));
        }

        $limit = (int)($args['limit'] ?? 25);
        $total = count($results);

        return [
            'total_matching' => $total,
            'returned' => min($limit, $total),
            'recommendations' => array_slice($results, 0, $limit),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function toolExportSarif(array $args): array
    {
        if ($this->lastResults === null) {
            return ['error' => 'No analysis results yet. Call run_analysis first.'];
        }

        $path = (string)($args['output_path'] ?? 'm2-performance.sarif');
        $exporter = new SarifExporter($this->magentoRoot);
        file_put_contents($path, $exporter->export($this->lastResults, ToolVersion::VERSION));

        return ['sarif_path' => $path, 'results' => count($this->lastResults)];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function toolSaveBaseline(array $args): array
    {
        if ($this->lastResults === null || $this->lastScore === null) {
            return ['error' => 'No analysis results yet. Call run_analysis first.'];
        }

        $name = (string)($args['name'] ?? '');
        if ($name === '') {
            return ['error' => 'Baseline name is required.'];
        }

        $manager = new BaselineManager($this->magentoRoot);
        $path = $manager->save($name, $this->lastResults, $this->lastScore, [
            'magento_root' => $this->magentoRoot,
            'profile' => $this->lastProfile,
            'analysis_mode' => $this->getRunner()->getAnalysisMode(),
        ]);

        return ['saved' => true, 'name' => $name, 'path' => $path, 'score' => $this->lastScore['score']];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function toolCompareBaseline(array $args): array
    {
        if ($this->lastResults === null || $this->lastScore === null) {
            return ['error' => 'No analysis results yet. Call run_analysis first.'];
        }

        $name = (string)($args['name'] ?? '');
        if ($name === '') {
            return ['error' => 'Baseline name is required.'];
        }

        $manager = new BaselineManager($this->magentoRoot);

        return $manager->compare(
            $name,
            $this->lastResults,
            $this->lastScore,
            $this->lastProfile,
            $this->getRunner()->getAnalysisMode()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListBaselines(): array
    {
        $manager = new BaselineManager($this->magentoRoot);

        return [
            'storage_dir' => $manager->getStorageDir(),
            'baselines' => $manager->listBaselines(),
        ];
    }

    /**
     * @param mixed $id
     * @param mixed $result
     * @return array<string, mixed>
     */
    private function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param mixed $id
     * @return array<string, mixed>
     */
    private function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function send(array $response): void
    {
        fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES) . "\n");
        // fflush keeps the agent-facing channel responsive on line-buffered pipes
        fflush(STDOUT);
    }
}
