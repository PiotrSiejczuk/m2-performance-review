<?php
namespace M2Performance\Command;

use M2Performance\Analyzer\AdobeCommerceAnalyzer;
use M2Performance\Analyzer\APIRateLimitingAnalyzer;
use M2Performance\Analyzer\CacheAnalyzer;
use M2Performance\Analyzer\CodebaseAnalyzer;
use M2Performance\Analyzer\ConfigurationAnalyzer;
use M2Performance\Analyzer\DatabaseAnalyzer;
use M2Performance\Analyzer\FrontendAnalyzer;
use M2Performance\Analyzer\IndexersAnalyzer;
use M2Performance\Analyzer\LayoutCacheAnalyzer;
use M2Performance\Analyzer\ModulesAnalyzer;
use M2Performance\Analyzer\OpCacheAnalyzer;
use M2Performance\Analyzer\RedisEnhancedAnalyzer;
use M2Performance\Analyzer\SecurityChecklistAnalyzer;
use M2Performance\Analyzer\ServerUptimeAnalyzer;
use M2Performance\Analyzer\HttpProtocolAnalyzer;
use M2Performance\Analyzer\VarnishPerformanceAnalyzer;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Service\AsyncCheckRunner;
use M2Performance\Service\EnvironmentLoader;
use M2Performance\Service\InfrastructureGenerator;
use M2Performance\Service\ConfigCommandGenerator;
use M2Performance\Service\SystemCommandGenerator;
use M2Performance\Service\DatabaseCommandGenerator;
use M2Performance\Service\FrontendCommandGenerator;
use M2Performance\Service\XmlReader;
use M2Performance\Service\CacheMetricsService;
use M2Performance\Service\UpdateChecker;
use M2Performance\Service\EnhancedConfigLoader;
use M2Performance\Service\BaseScriptGenerator;
use M2Performance\Service\VarnishCommandGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use M2Performance\Trait\DevModeAwareTrait;

class M2PerformanceCommand extends Command
{
    use DevModeAwareTrait;

    protected static $defaultName = 'm2:performance:analyze';

    protected $toolVersion = '1.0.3';

    private ?string $magentoRoot = null;
    private array $analyzers = [];
    private array $allAvailableAnalyzers = [];
    private ?RecommendationCollector $collector = null;
    private array $analyzerTimings = [];
    private float $totalExecutionTime = 0;
    private int $executedAnalyzers = 0;

    // Dev Mode Awareness Properties
    protected bool $isDevModeAware = false;
    private string $magentoMode = 'production';

    // Services
    private ?ConfigCommandGenerator $configGenerator = null;
    private ?InfrastructureGenerator $infraGenerator = null;

    protected function configure(): void
    {
        $this
            ->setDescription('Analyze Magento 2 performance and provide recommendations')
            ->setHelp('Analyzes Magento 2 configuration, security, caching, and provides actionable recommendations.')
            ->addOption('areas', 'a', InputOption::VALUE_REQUIRED, 'Comma-separated list of areas to analyze')
            ->addOption('export', 'e', InputOption::VALUE_REQUIRED, 'Export results to file (json, csv, html, markdown)')
            ->addOption('priority', 'p', InputOption::VALUE_REQUIRED, 'Filter by minimum priority (low, medium, high)')
            ->addOption('generate-fix', 'f', InputOption::VALUE_NONE, 'Generate fix scripts for recommendations')
            ->addOption('allow-dev-mode', null, InputOption::VALUE_NONE, 'Enable developer mode awareness for adjusted recommendations')
            ->addOption('summary', 's', InputOption::VALUE_NONE, 'Show only summary statistics')
            ->addOption('verbose-explanation', null, InputOption::VALUE_NONE, 'Show detailed explanations for each recommendation')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root', getcwd())
            ->addOption('async', null, InputOption::VALUE_NONE, 'Run analyzers asynchronously')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch mode - continuous monitoring')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Check profile (basic, full, security)', 'full')
            ->addOption('generate-config', null, InputOption::VALUE_NONE, 'Generate Magento config commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->magentoRoot = $input->getOption('magento-root');
        $profile = $input->getOption('profile');
        $this->collector = new RecommendationCollector();

        // Clear the screen first
        $this->clearScreen($output);

        // Detect Magento mode early
        $this->magentoMode = $this->detectMagentoMode($this->magentoRoot);

        // Check developer mode awareness BEFORE rendering header
        if ($this->magentoMode === 'developer') {
            if ($input->getOption('allow-dev-mode')) {
                $this->isDevModeAware = true;
            } elseif ($input->isInteractive()) {
                $this->isDevModeAware = $this->promptForDevModeAwareness($input, $output);
            }
        }

        // Now render header with dev mode status
        $this->renderHeader($output);

        // Check for updates and notify
        $this->checkAndNotifyUpdates($output);

        $this->initializeAnalyzers($this->magentoRoot, $profile);

        // Configure analyzers with mode information
        $this->configureAnalyzersForMode();

        // Filter by areas if specified
        if ($areas = $input->getOption('areas')) {
            $this->filterAnalyzersByAreas($areas);
        }

        if ($input->getOption('watch')) {
            return $this->runWatchMode($input, $output);
        }

        // Execute analyzers
        if ($input->getOption('async')) {
            $this->runAsync($output);
        } else {
            $this->runSync($output);
        }

        // Get recommendations
        $recommendations = $this->collector->getRecommendations();

        // Filter by priority if specified
        $priority = $input->getOption('priority');
        if ($priority) {
            $minPriority = match (strtolower($priority)) {
                'high' => Recommendation::PRIORITY_HIGH,
                'medium' => Recommendation::PRIORITY_MEDIUM,
                'low' => Recommendation::PRIORITY_LOW,
                default => null
            };

            if ($minPriority !== null) {
                $recommendations = array_filter(
                    $recommendations,
                    fn($r) => $r->getPriority() >= $minPriority
                );
            }
        }

        // Sort by priority (high to low)
        usort($recommendations, fn($a, $b) => $b->getPriority() <=> $a->getPriority());

        // Export if requested
        if ($exportFormat = $input->getOption('export')) {
            $this->exportResults($recommendations, $exportFormat, $output);
            return Command::SUCCESS;
        }

        // Show summary if requested
        if ($input->getOption('summary')) {
            $this->showSummary($recommendations, $output);
            return Command::SUCCESS;
        }

        // Display recommendations
        if (empty($recommendations)) {
            $output->writeln('');
            $output->writeln('<bg=green;fg=black> ✓ Perfect Score! No Issues Found ✓ </>');
            $output->writeln('<info>Your Magento Installation is Optimally Configured!</info>');
        } else {
            $this->displayRecommendations($recommendations, $output, $input->getOption('verbose-explanation'));
        }

        // Display dashboard statistics
        $this->displayDashboardStatistics($output);

        // Generate fix scripts if requested
        if ($input->getOption('generate-fix')) {
            $this->generateFixScripts($recommendations, $output);
        }

        // Generate config commands if requested
        if ($input->getOption('generate-config')) {
            $this->generateConfigCommands($recommendations, $output);
        }

        $executionTime = round($this->totalExecutionTime / 1000, 2);
        $output->writeln(sprintf("\n<comment>Analysis completed in %s seconds</comment>", $executionTime));

        return Command::SUCCESS;
    }

    private function clearScreen(OutputInterface $output): void
    {
        // Check if we're in a terminal that supports clearing
        if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            // Clear screen using ANSI escape codes
            $output->write("\033[2J\033[H");
        } elseif (stripos(PHP_OS, 'WIN') === 0) {
            // Windows
            system('cls');
        } else {
            // Unix/Linux/Mac
            system('clear');
        }
    }

    private function detectMagentoMode(string $magentoRoot): string
    {
        // Check MAGE_MODE environment variable first
        $mageMode = getenv('MAGE_MODE');
        if ($mageMode) {
            return $mageMode;
        }

        // Check env.php
        $envPath = $magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            return $env['MAGE_MODE'] ?? 'default';
        }

        return 'default';
    }

    private function promptForDevModeAwareness(InputInterface $input, OutputInterface $output): bool
    {
        $output->writeln('');
        $output->writeln('<comment>⚠️  Developer Mode Detected  ⚠️</comment>');
        $output->writeln('');
        $output->writeln('You are running Magento in Developer Mode.');
        $output->writeln('Some performance recommendations may not apply to development environments.');
        $output->writeln('');

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Continue with Developer Mode aware analysis? [y/N]: ',
            false
        );

        $devModeAware = $helper->ask($input, $output, $question);

        if ($devModeAware) {
            $output->writeln('<info>→ Running Developer Mode aware analysis</info>');
        } else {
            $output->writeln('<info>→ Running standard production-focused analysis</info>');
        }

        $output->writeln('');

        return $devModeAware;
    }

    private function configureAnalyzersForMode(): void
    {
        foreach ($this->analyzers as $analyzer) {
            // Set dev mode awareness
            if (method_exists($analyzer, 'setDevModeAware')) {
                $analyzer->setDevModeAware($this->isDevModeAware);
            }
            // Set actual Magento mode
            if (method_exists($analyzer, 'setMagentoMode')) {
                $analyzer->setMagentoMode($this->magentoMode);
            }
        }
    }

    private function initializeAnalyzers(string $magentoRoot, string $profile): void
    {
        // Use enhanced configuration loader
        $configLoader = new EnhancedConfigLoader($magentoRoot);
        $coreConfig = $configLoader->getAllConfig();
        
        // Legacy XML reader for module detection and Adobe Commerce check
        $xmlReader = new XmlReader($magentoRoot);
        $isAdobeCommerce = $xmlReader->isAdobeCommerce();
        
        $envLoader = new EnvironmentLoader($magentoRoot);

        $this->allAvailableAnalyzers = [
            'config' => new ConfigurationAnalyzer($magentoRoot, $coreConfig, $isAdobeCommerce, $this->collector),
            'cache' => new CacheAnalyzer($magentoRoot, $coreConfig, $this->collector),
            'database' => new DatabaseAnalyzer($magentoRoot, $this->collector),
            'modules' => new ModulesAnalyzer($magentoRoot, $this->collector),
            'codebase' => new CodebaseAnalyzer($magentoRoot, $this->collector),
            'frontend' => new FrontendAnalyzer($magentoRoot, $coreConfig, $this->collector),
            'indexers' => new IndexersAnalyzer($magentoRoot, $coreConfig, $this->collector),
            'opcache' => new OpCacheAnalyzer($this->collector),
            'redis' => new RedisEnhancedAnalyzer($magentoRoot, $this->collector),
            'api-security' => new APIRateLimitingAnalyzer($magentoRoot, $this->collector),
            'security' => new SecurityChecklistAnalyzer($magentoRoot, $this->collector),
            'layout-cache' => new LayoutCacheAnalyzer($magentoRoot, $this->collector),
            'uptime' => new ServerUptimeAnalyzer($this->collector),
            'protocol' => new HttpProtocolAnalyzer($magentoRoot, $coreConfig, $this->collector),
            'varnish' => new VarnishPerformanceAnalyzer($magentoRoot, $this->collector),
        ];

        if ($isAdobeCommerce) {
            $this->allAvailableAnalyzers['commerce'] = new AdobeCommerceAnalyzer(
                $magentoRoot, 
                $coreConfig, 
                true, 
                $envLoader, 
                $this->collector
            );
        }

        // Add debug output if requested
        if (getenv('M2PERFORMANCE_DEBUG') === '1') {
            $this->collector->add(
                'debug',
                'Configuration Debug Information',
                Recommendation::PRIORITY_LOW,
                $this->generateConfigDebugInfo($coreConfig),
                'This shows how configuration values are being detected.'
            );
        }

        $this->analyzers = match($profile) {
            'basic' => [
                'config' => $this->allAvailableAnalyzers['config'],
                'cache' => $this->allAvailableAnalyzers['cache'],
                'redis' => $this->allAvailableAnalyzers['redis'],
                'opcache' => $this->allAvailableAnalyzers['opcache'],
                'uptime' => $this->allAvailableAnalyzers['uptime'],
                'varnish' => $this->allAvailableAnalyzers['varnish']
            ],
            'security' => [
                'security' => $this->allAvailableAnalyzers['security'],
                'api-security' => $this->allAvailableAnalyzers['api-security'],
                'uptime' => $this->allAvailableAnalyzers['uptime']
            ],
            default => $this->allAvailableAnalyzers
        };
    }

    private function filterAnalyzersByAreas(string $areas): void
    {
        $selectedAreas = array_map('trim', explode(',', $areas));
        $areaMap = [
            'cache' => ['cache'],
            'caching' => ['cache', 'layout-cache', 'varnish'],
            'database' => ['database'],
            'frontend' => ['frontend'],
            'modules' => ['modules'],
            'security' => ['security', 'api-security'],
            'config' => ['config'],
            'opcache' => ['opcache'],
            'redis' => ['redis'],
            'indexing' => ['indexers'],
            'codebase' => ['codebase'],
            'protocol' => ['protocol'],
            'commerce' => ['commerce'],
            'varnish' => ['varnish']
        ];

        $analyzersToKeep = [];
        foreach ($selectedAreas as $area) {
            if (isset($areaMap[$area])) {
                foreach ($areaMap[$area] as $analyzerKey) {
                    if (isset($this->analyzers[$analyzerKey])) {
                        $analyzersToKeep[$analyzerKey] = $this->analyzers[$analyzerKey];
                    }
                }
            }
        }

        $this->analyzers = $analyzersToKeep;
    }

    private function runSync(OutputInterface $output): void
    {
        $totalStartTime = microtime(true);

        $progress = new ProgressBar($output, count($this->analyzers));
        $progress->start();

        foreach ($this->analyzers as $name => $analyzer) {
            $startTime = microtime(true);
            $analyzer->analyze();
            $endTime = microtime(true);

            $this->analyzerTimings[$this->getAnalyzerName($name)] = ($endTime - $startTime) * 1000;
            $this->executedAnalyzers++;
            $progress->advance();
        }

        $this->totalExecutionTime = (microtime(true) - $totalStartTime) * 1000;

        $progress->finish();

        // Clear the progress bar line
        $output->write("\r\033[K");
    }

    private function runAsync(OutputInterface $output): void
    {
        $startTime = microtime(true);

        $runner = new AsyncCheckRunner();
        foreach ($this->analyzers as $analyzer) {
            $runner->addAnalyzer($analyzer);
        }

        $runner->runAsync($output);

        $this->totalExecutionTime = (microtime(true) - $startTime) * 1000;
        $this->executedAnalyzers = count($this->analyzers);

        // For async mode, individual timings aren't meaningful
        $this->analyzerTimings = [];
    }

    private function runWatchMode(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Starting watch mode... Press Ctrl+C to stop</comment>');

        while (true) {
            $output->write("\033[2J\033[H");
            $output->writeln('<info>Performance Monitor - ' . date('Y-m-d H:i:s') . '</info>');

            $this->collector = new RecommendationCollector();

            foreach ($this->analyzers as $analyzer) {
                try {
                    $analyzer->analyze();
                } catch (\Exception $e) {
                    $output->writeln("<error>Error: {$e->getMessage()}</error>");
                }
            }

            $this->displayCompactResults($this->collector->getRecommendations(), $output);
            sleep(5);
        }
    }

    private function displayRecommendations(array $recommendations, OutputInterface $output, bool $verbose): void
    {
        $table = new Table($output);

        // Configure table style for better rendering
        $style = clone Table::getStyleDefinition('box');
        $style->setVerticalBorderChars('║', '║', '║');
        $style->setHorizontalBorderChars('═', '─');
        $style->setCrossingChars('╔', '╤', '╗', '╟', '┼', '╢', '╚', '╧', '╝', '╠', '╪', '╣');
        $table->setStyle($style);

        // Set headers
        $headers = ['Area', 'Priority', 'Recommendation', 'Details'];
        if ($verbose) {
            $headers[] = 'Explanation';
        }
        $table->setHeaders($headers);

        $lastArea = null;
        foreach ($recommendations as $recommendation) {
            $area = $recommendation->getArea();

            // Add separator between different areas
            if ($lastArea !== null && $lastArea !== $area) {
                $table->addRow(new TableSeparator());
            }

            $priority = match ($recommendation->getPriority()) {
                Recommendation::PRIORITY_HIGH => "🔴\nHigh",
                Recommendation::PRIORITY_MEDIUM => "🟡\nMedium",
                Recommendation::PRIORITY_LOW => "🟢\nLow",
                default => 'Unknown'
            };

            $details = $recommendation->getDetails();

            // Handle file lists in details
            if ($recommendation->hasFiles()) {
                $fileCount = count($recommendation->getFiles());
                $details .= sprintf("\n\n💡 Full list of %d files available with --export", $fileCount);
            }

            // Prepare row data
            $row = [
                $area,
                $priority,
                $recommendation->getTitle(),
                $details
            ];

            if ($verbose) {
                $row[] = $recommendation->getExplanation() ?: '';
            }

            $table->addRow($row);
            $lastArea = $area;
        }

        $table->render();
    }

    private function displayCompactResults(array $recommendations, OutputInterface $output): void
    {
        $byArea = [];
        foreach ($recommendations as $rec) {
            $byArea[$rec->getArea()][] = $rec;
        }

        foreach ($byArea as $area => $recs) {
            $high = count(array_filter($recs, fn($r) => $r->getPriority() === Recommendation::PRIORITY_HIGH));
            $medium = count(array_filter($recs, fn($r) => $r->getPriority() === Recommendation::PRIORITY_MEDIUM));
            $low = count(array_filter($recs, fn($r) => $r->getPriority() === Recommendation::PRIORITY_LOW));

            $output->writeln(sprintf(
                '%-15s: <error>%d High</error> | <comment>%d Medium</comment> | <info>%d Low</info>',
                $area, $high, $medium, $low
            ));
        }
    }

    private function showSummary(array $recommendations, OutputInterface $output): void
    {
        $stats = [
            'total' => count($recommendations),
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'by_area' => []
        ];

        foreach ($recommendations as $rec) {
            switch ($rec->getPriority()) {
                case Recommendation::PRIORITY_HIGH:
                    $stats['high']++;
                    break;
                case Recommendation::PRIORITY_MEDIUM:
                    $stats['medium']++;
                    break;
                case Recommendation::PRIORITY_LOW:
                    $stats['low']++;
                    break;
            }

            $area = $rec->getArea();
            if (!isset($stats['by_area'][$area])) {
                $stats['by_area'][$area] = 0;
            }
            $stats['by_area'][$area]++;
        }

        $output->writeln("\n<info>Summary:</info>");
        $output->writeln(sprintf("Total issues found: <comment>%d</comment>", $stats['total']));
        $output->writeln(sprintf("High priority: <error>%d</error>", $stats['high']));
        $output->writeln(sprintf("Medium priority: <comment>%d</comment>", $stats['medium']));
        $output->writeln(sprintf("Low priority: <info>%d</info>", $stats['low']));

        if (!empty($stats['by_area'])) {
            $output->writeln("\n<info>Issues by area:</info>");
            foreach ($stats['by_area'] as $area => $count) {
                $output->writeln(sprintf("  %s: %d", $area, $count));
            }
        }
    }

    private function exportResults(array $recommendations, string $format, OutputInterface $output): void
    {
        $data = array_map(function ($rec) {
            $result = [
                'area' => $rec->getArea(),
                'priority' => $rec->getPriority(),
                'priority_label' => match ($rec->getPriority()) {
                    Recommendation::PRIORITY_HIGH => 'High',
                    Recommendation::PRIORITY_MEDIUM => 'Medium',
                    Recommendation::PRIORITY_LOW => 'Low',
                    default => 'Unknown'
                },
                'title' => $rec->getTitle(),
                'details' => $rec->getDetails(),
                'explanation' => $rec->getExplanation()
            ];

            // Include full file lists in export
            if ($rec->hasFiles()) {
                $result['affected_files'] = $rec->getFiles();
            }

            // Include metadata if available
            if ($rec->hasMetadata()) {
                $result['metadata'] = $rec->getMetadata();
            }

            return $result;
        }, $recommendations);

        $filename = 'm2_performance_report_' . date('Y-m-d_H-i-s') . '.' . $format;

        switch ($format) {
            case 'json':
                file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                break;

            case 'csv':
                $fp = fopen($filename, 'w');
                fputcsv($fp, ['Area', 'Priority', 'Title', 'Details', 'Explanation', 'Affected Files']);
                foreach ($data as $row) {
                    fputcsv($fp, [
                        $row['area'],
                        $row['priority_label'],
                        $row['title'],
                        $row['details'],
                        $row['explanation'] ?? '',
                        isset($row['affected_files']) ? implode('; ', $row['affected_files']) : ''
                    ]);
                }
                fclose($fp);
                break;

            case 'html':
                $html = $this->generateHtmlReport($data);
                file_put_contents($filename, $html);
                break;

            case 'markdown':
                $markdown = $this->generateMarkdownReport($data);
                file_put_contents($filename, $markdown);
                break;

            default:
                $output->writeln('<error>Unsupported export format: ' . $format . '</error>');
                return;
        }

        $output->writeln('<info>Results exported to: ' . $filename . '</info>');
    }

    private function generateHtmlReport(array $data): string
    {
        $timestamp = date('Y-m-d H:i:s');

        // Use heredoc without sprintf to avoid format specifier issues
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Magento 2 Performance Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; vertical-align: top; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .priority-high { color: #d32f2f; font-weight: bold; }
        .priority-medium { color: #f57c00; font-weight: bold; }
        .priority-low { color: #388e3c; }
        .details { white-space: pre-wrap; font-size: 0.9em; }
        .files { background-color: #f5f5f5; padding: 10px; margin-top: 10px; border-radius: 5px; }
        .file-list { font-family: monospace; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>Magento 2 Performance Analysis Report</h1>
    <p>Generated: {$timestamp}</p>
    <table>
        <thead>
            <tr>
                <th>Area</th>
                <th>Priority</th>
                <th>Recommendation</th>
                <th>Details</th>
                <th>Explanation</th>
            </tr>
        </thead>
        <tbody>
HTML;

        foreach ($data as $row) {
            $priorityClass = 'priority-' . strtolower($row['priority_label']);
            $filesHtml = '';

            if (!empty($row['affected_files'])) {
                $filesHtml = '<div class="files"><strong>Affected Files:</strong><div class="file-list">';
                foreach ($row['affected_files'] as $file) {
                    $filesHtml .= htmlspecialchars($file) . "<br>";
                }
                $filesHtml .= '</div></div>';
            }

            $area = htmlspecialchars($row['area']);
            $priorityLabel = htmlspecialchars($row['priority_label']);
            $title = htmlspecialchars($row['title']);
            $details = htmlspecialchars($row['details']);
            $explanation = htmlspecialchars($row['explanation'] ?? '');

            $html .= <<<ROW
            <tr>
                <td>{$area}</td>
                <td class="{$priorityClass}">{$priorityLabel}</td>
                <td>{$title}</td>
                <td class="details">{$details}{$filesHtml}</td>
                <td class="details">{$explanation}</td>
            </tr>
ROW;
        }

        $html .= '</tbody></table></body></html>';
        return $html;
    }

    private function generateMarkdownReport(array $data): string
    {
        $md = "# Magento 2 Performance Analysis Report\n\n";
        $md .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        // Group by area
        $byArea = [];
        foreach ($data as $row) {
            $byArea[$row['area']][] = $row;
        }

        foreach ($byArea as $area => $items) {
            $md .= "## " . ucfirst($area) . "\n\n";

            foreach ($items as $item) {
                $icon = match ($item['priority_label']) {
                    'High' => '🔴',
                    'Medium' => '🟡',
                    'Low' => '🟢',
                    default => '⚪'
                };

                $md .= "### {$icon} {$item['title']}\n\n";
                $md .= "**Priority:** {$item['priority_label']}\n\n";
                $md .= "**Details:**\n{$item['details']}\n\n";

                if (!empty($item['explanation'])) {
                    $md .= "**Explanation:**\n{$item['explanation']}\n\n";
                }

                if (!empty($item['affected_files'])) {
                    $md .= "**Affected Files:**\n";
                    foreach ($item['affected_files'] as $file) {
                        $md .= "- `{$file}`\n";
                    }
                    $md .= "\n";
                }

                if (!empty($item['metadata'])) {
                    $md .= "**Additional Data:**\n```json\n";
                    $md .= json_encode($item['metadata'], JSON_PRETTY_PRINT);
                    $md .= "\n```\n\n";
                }

                $md .= "---\n\n";
            }
        }

        return $md;
    }

    private function generateFixScripts(array $recommendations, OutputInterface $output): void
    {
        $output->writeln("\n<info>Generating fix scripts...</info>");

        // Group recommendations by type
        $configRecs = [];
        $systemRecs = [];
        $databaseRecs = [];
        $frontendRecs = [];

        foreach ($recommendations as $rec) {
            $area = $rec->getArea();

            // Config-related
            if (in_array($area, ['config', 'caching', 'cache', 'commerce', 'search', 'modules'])) {
                $configRecs[] = $rec;
            }

            // System-related
            if (in_array($area, ['opcache', 'system', 'protocol', 'security', 'api-security'])) {
                $systemRecs[] = $rec;
            }

            // Database-related
            if (in_array($area, ['database', 'indexing', 'indexers'])) {
                $databaseRecs[] = $rec;
            }

            // Frontend-related
            if (in_array($area, ['frontend', 'codebase']) ||
                ($area === 'caching' && stripos($rec->getTitle(), 'cacheable') !== false)) {
                $frontendRecs[] = $rec;
            }

            // Varnish-related
            if ($area === 'varnish') {
                $varnishRecs[] = $rec;
            }
        }

        $scriptsGenerated = 0;

        // Generate Config Script
        if (!empty($configRecs)) {
            $configGenerator = new ConfigCommandGenerator();
            $filename = $configGenerator->generate($configRecs);
            if ($filename) {
                $output->writeln("<comment>Generated Magento config script: {$filename}</comment>");
                $scriptsGenerated++;
            }
        }

        // Generate System Script
        if (!empty($systemRecs)) {
            $systemGenerator = new SystemCommandGenerator();
            $filename = $systemGenerator->generate($systemRecs);
            if ($filename) {
                $output->writeln("<comment>Generated system config script: {$filename}</comment>");
                $scriptsGenerated++;
            }
        }

        // Generate Database Script
        if (!empty($databaseRecs)) {
            $dbGenerator = new DatabaseCommandGenerator();
            $filename = $dbGenerator->generate($databaseRecs);
            if ($filename) {
                $output->writeln("<comment>Generated database optimization script: {$filename}</comment>");
                $scriptsGenerated++;
            }
        }

        // Generate Frontend Script
        if (!empty($frontendRecs)) {
            $frontendGenerator = new FrontendCommandGenerator();
            $filename = $frontendGenerator->generate($frontendRecs);
            if ($filename) {
                $output->writeln("<comment>Generated frontend optimization script: {$filename}</comment>");
                $scriptsGenerated++;
            }
        }

        // Generate Varnish Script
        if (!empty($varnishRecs)) {
            $varnishGenerator = new VarnishCommandGenerator();
            $filename = $varnishGenerator->generate($varnishRecs);
            if ($filename) {
                $output->writeln("<comment>Generated Varnish optimization script: {$filename}</comment>");
                $scriptsGenerated++;
            }
        }

        if ($scriptsGenerated === 0) {
            $output->writeln("<comment>No automated fixes available for current recommendations.</comment>");
        } else {
            $output->writeln(sprintf("\n<info>Generated %d fix scripts. Review before executing!</info>", $scriptsGenerated));
        }
    }

    private function displayDashboardStatistics(OutputInterface $output): void
    {
        $output->writeln("\n📊 <comment>Dashboard Statistics 📊</comment>");

        $systemInfo = $this->getSystemInfo();
        $recommendationsSummary = $this->getRecommendationsSummary();
        $performanceInfo = $this->getPerformanceInfo();

        $table = new Table($output);
        $table->setHeaders(['System Information', 'Performance Score & Issues', 'Execution Statistics'])
            ->setStyle('box')
            ->setRows([[
                implode("\n", $systemInfo),
                implode("\n", $recommendationsSummary),
                implode("\n", $performanceInfo)
            ]]);
        $table->render();
    }

    private function getSystemInfo(): array
    {
        $info = [];

        $uptime = $this->getSystemUptime();
        if ($uptime !== null) {
            $days = intdiv((int)$uptime, 86400);
            $hours = intdiv((int)$uptime % 86400, 3600);
            $status = $uptime < 3600 ? '<error>Recently Restarted</error>' : '<info>Stable</info>';
            $info[] = sprintf('Server Uptime: %d days, %d hours [%s]', $days, $hours, $status);
        }

        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpuCount = $this->getCpuCount();
            $status = '<info>Normal</info>';
            if ($cpuCount && $load[0] > $cpuCount * 0.8) {
                $status = '<error>High</error>';
            } elseif ($cpuCount && $load[0] > $cpuCount * 0.5) {
                $status = '<comment>Moderate</comment>';
            }
            $info[] = sprintf('Load Avg (1m): %.2f [%s]', $load[0], $status);
        }

        $memInfo = $this->getMemoryInfo();
        if ($memInfo) {
            $usedPercent = (($memInfo['total'] - $memInfo['available']) / $memInfo['total']) * 100;
            $status = '<info>Normal</info>';
            if ($usedPercent > 90) {
                $status = '<error>Critical</error>';
            } elseif ($usedPercent > 80) {
                $status = '<comment>High</comment>';
            }
            $info[] = sprintf('Memory Usage: %.1f%% [%s]', $usedPercent, $status);
        }

        return $info;
    }

    private function getRecommendationsSummary(): array
    {
        $recommendations = $this->collector->getRecommendations();
        $high = 0;
        $medium = 0;
        $low = 0;

        foreach ($recommendations as $rec) {
            switch ($rec->getPriority()) {
                case Recommendation::PRIORITY_HIGH:
                    $high++;
                    break;
                case Recommendation::PRIORITY_MEDIUM:
                    $medium++;
                    break;
                case Recommendation::PRIORITY_LOW:
                    $low++;
                    break;
            }
        }

        $total = $high + $medium + $low;
        $score = $this->calculatePerformanceScore($high, $medium, $low);

        $summary = [
            sprintf('Performance Score: %s', $this->formatPerformanceScore($score)),
            sprintf('Total Issues: %d', $total),
            sprintf('🔴 <error>High: %d</error>', $high),
            sprintf('🟡 <comment>Medium: %d</comment>', $medium),
            sprintf('🟢 <info>Low: %d</info>', $low)
        ];

        // Add mode information
        if ($this->magentoMode === 'developer' && $this->isDevModeAware) {
            $summary[] = sprintf('Mode: <comment>Developer (Aware)</comment>');
        } elseif ($this->magentoMode === 'developer') {
            $summary[] = sprintf('Mode: <error>Developer</error>');
        } else {
            $summary[] = sprintf('Mode: <info>%s</info>', ucfirst($this->magentoMode));
        }

        return $summary;
    }

    private function getPerformanceInfo(): array
    {
        $info = [];

        $info[] = sprintf('Analyzers: %d/%d Executed', $this->executedAnalyzers, count($this->allAvailableAnalyzers));
        $info[] = sprintf('Total Time: %s', $this->formatExecutionTime($this->totalExecutionTime));

        if (!empty($this->analyzerTimings)) {
            $info[] = '<comment>Top Slowest:</comment>';
            arsort($this->analyzerTimings);
            $count = 0;
            foreach ($this->analyzerTimings as $name => $timeMs) {
                if ($count >= 3) break; // Show top 3
                $info[] = sprintf('  %s: %s', $name, $this->formatExecutionTime($timeMs));
                $count++;
            }
        } else {
            $info[] = '<comment>Async Mode:</comment>';
            $info[] = 'Individual timings';
            $info[] = 'not available';
        }

        return $info;
    }

    private function calculatePerformanceScore(int $high, int $medium, int $low): float
    {
        // Performance score algorithm (0-100 scale)
        // Base score starts at 100, deduct points for issues
        $baseScore = 100.0;

        // Weights for different priority issues
        $highWeight = 8.0;    // High priority issues are very bad
        $mediumWeight = 3.0;  // Medium priority issues are moderate
        $lowWeight = 0.5;     // Low priority issues are minor

        // Calculate deductions
        $deductions = ($high * $highWeight) + ($medium * $mediumWeight) + ($low * $lowWeight);

        // Apply exponential decay for many issues
        if ($deductions > 20) {
            $deductions = 20 + (($deductions - 20) * 1.5);
        }

        $score = max(0, $baseScore - $deductions);

        // Bonus for perfect execution
        if ($high === 0 && $medium === 0 && $low === 0) {
            $score = 100.0;
        }

        return round($score, 1);
    }

    private function formatPerformanceScore(float $score): string
    {
        $grade = match(true) {
            $score >= 95 => ['A+', 'info'],
            $score >= 90 => ['A', 'info'],
            $score >= 85 => ['A-', 'info'],
            $score >= 80 => ['B+', 'comment'],
            $score >= 75 => ['B', 'comment'],
            $score >= 70 => ['B-', 'comment'],
            $score >= 65 => ['C+', 'comment'],
            $score >= 60 => ['C', 'comment'],
            $score >= 55 => ['C-', 'error'],
            $score >= 50 => ['D+', 'error'],
            $score >= 45 => ['D', 'error'],
            default => ['F', 'error']
        };

        return sprintf('<%s>%.1f/100 (%s)</%s>', $grade[1], $score, $grade[0], $grade[1]);
    }

    // Utility methods
    private function getAnalyzerName(string $key): string
    {
        return ucfirst(str_replace(['-', '_'], '', $key));
    }

    private function formatExecutionTime(float $timeMs): string
    {
        return $timeMs < 1000 ? sprintf('%.0f ms', $timeMs) : sprintf('%.2f s', $timeMs / 1000);
    }

    private function getSystemUptime(): ?float
    {
        if (file_exists('/proc/uptime')) {
            $uptimeData = file_get_contents('/proc/uptime');
            if ($uptimeData) {
                return (float)explode(' ', trim($uptimeData))[0];
            }
        }
        return null;
    }

    private function getCpuCount(): ?int
    {
        if (file_exists('/proc/cpuinfo')) {
            return substr_count(file_get_contents('/proc/cpuinfo'), 'processor');
        }
        return null;
    }

    private function getMemoryInfo(): ?array
    {
        if (!file_exists('/proc/meminfo')) return null;

        $data = [];
        foreach (explode("\n", file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s*(\d+)\s*kB/', $line, $matches)) {
                $data[strtolower($matches[1])] = (int)$matches[2] * 1024;
            }
        }

        return [
            'total' => $data['memtotal'] ?? 0,
            'available' => $data['memavailable'] ?? ($data['memfree'] ?? 0),
        ];
    }

    // Additional features
    private function generateConfigCommands(array $recommendations, OutputInterface $output): void
    {
        $generator = new ConfigCommandGenerator();

        $output->writeln("\n<comment>🔧 Magento Configuration Commands:</comment>");
        $output->writeln(str_repeat('=', 50));

        $filename = $generator->generate($recommendations);

        if ($filename) {
            $output->writeln("<info>✅ Configuration script generated: <comment>{$filename}</comment></info>");
            $output->writeln("<comment>Features:</comment>");
            $output->writeln("  • Handles locked configuration values automatically");
            $output->writeln("  • Skips invalid paths for your Magento edition");
            $output->writeln("  • Provides detailed success/failure feedback");
            $output->writeln("  • Includes safety checks and error handling");
            $output->writeln("\n<info>Run with:</info> <comment>./{$filename}</comment>");
        } else {
            $output->writeln('<info>✅ No configuration commands needed - your settings are optimal!</info>');
        }
    }

    private function renderHeader(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<bg=blue;fg=white>                                                    </>');
        $output->writeln('<bg=blue;fg=white>   🚀 Magento 2 Performance Review Tool v'.$this->toolVersion.' 🚀   </>');
        $output->writeln('<bg=blue;fg=white>                                                    </>');

        // Show dev mode status if applicable
        if ($this->isDevModeAware && $this->magentoMode === 'developer') {
            $output->writeln('');
            $output->writeln('<bg=yellow;fg=black>         Developer Mode Aware Analysis              </>');
        }

        $output->writeln('');

        $logo = <<<'LOGO'
  __  __ ____    ____            __                                        
 |  \/  |___ \  |  _ \ ___ _ __ / _| ___  _ __ _ __ ___   __ _ _ __   ___ ___
 | |\/| | __) | | |_) / _ \ '__| |_ / _ \| '__| '_ ` _ \ / _` | '_ \ / __/ _ \
 | |  | |/ __/  |  __/  __/ |  |  _| (_) | |  | | | | | | (_| | | | | (_|  __/
 |_|  |_|_____| |_|   \___|_|  |_|  \___/|_|  |_| |_| |_|\__,_|_| |_|\___\___|
 
                           by Piotr Siejczuk: https://github.com/PiotrSiejczuk
LOGO;
        $output->writeln($logo);
    }

    private function checkAndNotifyUpdates(OutputInterface $output): void
    {
        // Only check if running from PHAR
        if (!\Phar::running()) {
            return;
        }
        
        // Check if update notification file exists
        $notificationFile = sys_get_temp_dir() . '/.m2-performance-update-available';
        if (file_exists($notificationFile)) {
            $updateInfo = json_decode(file_get_contents($notificationFile), true);
            if ($updateInfo && isset($updateInfo['version']) && isset($updateInfo['checked'])) {
                // Only show if checked within last 24 hours
                if (time() - $updateInfo['checked'] < 86400) {
                    $output->writeln('');
                    $output->writeln('<bg=yellow;fg=black> ⚡ Update Available ⚡ </>');
                    $output->writeln(sprintf(
                        '<comment>A new version (%s) is available. Run <info>self-update</info> to upgrade.</comment>',
                        $updateInfo['version']
                    ));
                    $output->writeln('');
                }
            }
        }
    }

    private function generateConfigDebugInfo(array $coreConfig): string
    {
        $debugInfo = "Configuration Detection Results:\n\n";
        
        $keyConfigs = [
            'catalog/search/engine' => 'Search Engine',
            'web/secure/use_in_frontend' => 'Frontend HTTPS',
            'web/secure/use_in_adminhtml' => 'Admin HTTPS', 
            'system/full_page_cache/caching_application' => 'FPC Backend',
            'web/secure/base_url' => 'Secure Base URL',
            'web/unsecure/base_url' => 'Unsecure Base URL'
        ];
        
        foreach ($keyConfigs as $path => $label) {
            $value = $coreConfig[$path] ?? 'NOT SET';
            $debugInfo .= sprintf("%-25s: %s\n", $label, var_export($value, true));
        }
        
        $debugInfo .= "\nTotal config entries loaded: " . count($coreConfig);
        $debugInfo .= "\nConfig sources checked: Database → config.php → env.php → Environment Variables";
        
        return $debugInfo;
    }
}
