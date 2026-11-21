<?php
namespace M2Performance\Command;

use M2Performance\Model\Recommendation;
use M2Performance\Service\AnalysisRunner;
use M2Performance\Service\BaselineManager;
use M2Performance\Service\ConfigCommandGenerator;
use M2Performance\Service\DatabaseCommandGenerator;
use M2Performance\Service\EnhancedConfigLoader;
use M2Performance\Service\FrontendCommandGenerator;
use M2Performance\Service\HtmlReportRenderer;
use M2Performance\Service\InfrastructureGenerator;
use M2Performance\Service\SarifExporter;
use M2Performance\Service\StoreContext;
use M2Performance\Service\SystemCommandGenerator;
use M2Performance\Service\ToolConfigLoader;
use M2Performance\Service\UpdateChecker;
use M2Performance\Service\VarnishCommandGenerator;
use M2Performance\ToolVersion;
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

    protected $toolVersion = ToolVersion::VERSION;

    /** CLI area aliases -> analyzer identifiers (identifiers themselves are accepted too). */
    private const AREA_MAP = [
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
        'codebase' => ['codebase', 'static-code'],
        'static-code' => ['static-code'],
        'static' => ['static-code'],
        'protocol' => ['protocol'],
        'commerce' => ['commerce'],
        'varnish' => ['varnish'],
    ];

    private ?string $magentoRoot = null;
    private ?AnalysisRunner $runner = null;
    private ?ToolConfigLoader $toolConfig = null;
    /** @var Recommendation[] Unfiltered results of the last run (score, baseline, dashboard). */
    private array $lastRecommendations = [];
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
            ->setAliases(['review'])
            ->setDescription('Analyze Magento 2 performance and provide recommendations')
            ->setHelp('Analyzes Magento 2 configuration, security, caching, and provides actionable recommendations.')
            ->addOption('areas', 'a', InputOption::VALUE_REQUIRED, 'Comma-separated list of areas to analyze')
            ->addOption('export', 'e', InputOption::VALUE_REQUIRED, 'Export results to file (json, csv, html, markdown, sarif)')
            ->addOption('priority', 'p', InputOption::VALUE_REQUIRED, 'Filter by minimum priority (low, medium, high)')
            ->addOption('generate-fix', 'f', InputOption::VALUE_NONE, 'Generate fix scripts for recommendations')
            ->addOption('allow-dev-mode', null, InputOption::VALUE_NONE, 'Enable developer mode awareness for adjusted recommendations')
            ->addOption('summary', 's', InputOption::VALUE_NONE, 'Show only summary statistics')
            ->addOption('verbose-explanation', null, InputOption::VALUE_NONE, 'Show detailed explanations for each recommendation')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root', getcwd())
            ->addOption('async', null, InputOption::VALUE_NONE, 'Reserved: parallel execution is not implemented yet (analyzers run sequentially)')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch mode - continuous monitoring')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Check profile (basic, full, security)', 'full')
            ->addOption('generate-config', null, InputOption::VALUE_NONE, 'Generate Magento config commands')
            ->addOption('compare-to', null, InputOption::VALUE_REQUIRED, 'Compare results against a saved baseline (see baseline command)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to .m2perf.json tool configuration file')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Continue analysis when an individual analyzer fails (CI/CD friendly)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->magentoRoot = $input->getOption('magento-root');
        $profile = (string)$input->getOption('profile');

        // Clear the screen first
        $this->clearScreen($output);

        // Detect Magento mode early
        $this->magentoMode = StoreContext::forRoot($this->magentoRoot)->getMagentoMode();

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
        $this->checkAndNotifyUpdates($input, $output);

        $configPath = $input->getOption('config');
        $this->toolConfig = new ToolConfigLoader(
            is_string($configPath) === true ? $configPath : null,
            $this->magentoRoot
        );
        $this->runner = $this->createRunner();

        $selected = $this->resolveSelectedAnalyzers($profile, $input->getOption('areas'));
        if (count($selected) === 0) {
            $output->writeln(sprintf(
                '<error>No analyzers selected (profile "%s", areas "%s").</error>',
                $profile,
                (string)$input->getOption('areas')
            ));
            $output->writeln('Valid areas: ' . implode(', ', array_keys(self::AREA_MAP)));
            $output->writeln('Valid analyzers: ' . implode(', ', $this->runner->getAvailableAnalyzerNames()));

            return Command::INVALID;
        }

        if ($input->getOption('watch')) {
            return $this->runWatchMode($profile, $selected, $output);
        }

        if ($input->getOption('async')) {
            $output->writeln('<comment>Note: --async is not parallel yet; analyzers run sequentially.</comment>');
        }

        $this->runAnalysis($profile, $selected, $input->getOption('continue-on-error') === true, $output);

        $recommendations = $this->lastRecommendations;

        if (getenv('M2PERFORMANCE_DEBUG') === '1') {
            $recommendations[] = new Recommendation(
                'debug',
                'Configuration Debug Information',
                Recommendation::PRIORITY_LOW,
                $this->generateConfigDebugInfo((new EnhancedConfigLoader($this->magentoRoot))->getAllConfig()),
                'This shows how configuration values are being detected.'
            );
        }

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

        // Compare against a saved baseline if requested
        if ($baselineName = $input->getOption('compare-to')) {
            $this->compareToBaseline((string)$baselineName, $profile, $output);
        }

        // Export if requested
        if ($exportFormat = $input->getOption('export')) {
            return $this->exportResults($recommendations, $exportFormat, $output) === true
                ? Command::SUCCESS
                : Command::FAILURE;
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
        // Only clear an interactive terminal: shelling out to clear/cls in CI or
        // when piping printed "TERM environment variable not set" and escape noise
        if (function_exists('stream_isatty') && @stream_isatty(STDOUT)) {
            $output->write("\033[2J\033[H");
        }
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

    private function createRunner(): AnalysisRunner
    {
        $runner = new AnalysisRunner($this->magentoRoot, $this->toolConfig);
        $runner->configureMode($this->magentoMode, $this->isDevModeAware);

        return $runner;
    }

    /**
     * Analyzer identifiers for the profile, narrowed by --areas when given.
     *
     * @return string[]
     */
    private function resolveSelectedAnalyzers(string $profile, ?string $areas): array
    {
        $profileAnalyzers = array_keys($this->runner->resolveProfile($profile));
        if ($areas === null || trim($areas) === '') {
            return $profileAnalyzers;
        }

        $requested = [];
        foreach (array_map('trim', explode(',', $areas)) as $area) {
            foreach (self::AREA_MAP[$area] ?? [$area] as $analyzerKey) {
                $requested[] = $analyzerKey;
            }
        }

        return array_values(array_intersect($profileAnalyzers, $requested));
    }

    /**
     * @param string[] $selected
     */
    private function runAnalysis(string $profile, array $selected, bool $continueOnError, OutputInterface $output): void
    {
        // Progress bar only on ANSI terminals: in plain output (CI, pipes) Symfony prints
        // one line per redraw and its throttled final redraw glues onto the next output
        $progress = null;
        if ($output->isDecorated() === true) {
            $progress = new ProgressBar($output, count($selected));
            $progress->start();
        } else {
            $output->writeln(sprintf('Running %d analyzers...', count($selected)));
        }

        $this->lastRecommendations = $this->runner->run(
            $profile,
            $selected,
            $continueOnError,
            static function () use ($progress): void {
                $progress?->advance();
            }
        );

        if ($progress !== null) {
            $progress->finish();
            $progress->clear();
        }

        $this->analyzerTimings = [];
        foreach ($this->runner->getAnalyzerTimings() as $name => $timeMs) {
            $this->analyzerTimings[$this->getAnalyzerName($name)] = $timeMs;
        }
        $this->executedAnalyzers = count($this->analyzerTimings);
        $this->totalExecutionTime = $this->runner->getTotalExecutionTime();
    }

    /**
     * @param string[] $selected
     */
    private function runWatchMode(string $profile, array $selected, OutputInterface $output): int
    {
        $output->writeln('<comment>Starting watch mode... Press Ctrl+C to stop</comment>');

        while (true) {
            // Fresh runner + context per cycle so configuration changes are picked up
            StoreContext::reset();
            $recommendations = $this->createRunner()->run($profile, $selected, true);

            $this->clearScreen($output);
            $output->writeln('<info>Performance Monitor - ' . date('Y-m-d H:i:s') . '</info>');
            $this->displayCompactResults($recommendations, $output);
            sleep(5);
        }
    }

    private function displayRecommendations(array $recommendations, OutputInterface $output, bool $verbose): void
    {
        $table = new Table($output);

        // Configure table style for better rendering
        $style = clone Table::getStyleDefinition('box');
        $style->setVerticalBorderChars('║', '│');
        $style->setHorizontalBorderChars('═', '─');
        $style->setCrossingChars('┼', '╔', '╤', '╗', '╢', '╝', '╧', '╚', '╟', '╠', '╪', '╣');
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

    private function exportResults(array $recommendations, string $format, OutputInterface $output): bool
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
                fputcsv($fp, ['Area', 'Priority', 'Title', 'Details', 'Explanation', 'Affected Files'], ',', '"', '\\');
                foreach ($data as $row) {
                    fputcsv($fp, [
                        $row['area'],
                        $row['priority_label'],
                        $row['title'],
                        $row['details'],
                        $row['explanation'] ?? '',
                        isset($row['affected_files']) ? implode('; ', $row['affected_files']) : ''
                    ], ',', '"', '\\');
                }
                fclose($fp);
                break;

            case 'html':
                $score = $this->runner->computeScore($this->lastRecommendations);
                $renderer = new HtmlReportRenderer();
                file_put_contents($filename, $renderer->render(
                    array_values($recommendations),
                    StoreContext::forRoot($this->magentoRoot)->getBaseSecureUrl(),
                    ['score' => $score['score'], 'grade' => $score['grade']],
                    ToolVersion::VERSION
                ));
                break;

            case 'markdown':
                $markdown = $this->generateMarkdownReport($data);
                file_put_contents($filename, $markdown);
                break;

            case 'sarif':
                $exporter = new SarifExporter($this->magentoRoot);
                file_put_contents($filename, $exporter->export($data, ToolVersion::VERSION));
                break;

            default:
                $output->writeln('<error>Unsupported export format: ' . $format
                    . ' (supported: json, csv, html, markdown, sarif)</error>');
                return false;
        }

        $output->writeln('<info>Results exported to: ' . $filename . '</info>');

        return true;
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
        $varnishRecs = [];
        // Fastly is managed Varnish at the edge: a local varnishstat/VCL script cannot apply
        $isFastly = StoreContext::forRoot($this->magentoRoot)->isFastly();

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
            if ($area === 'varnish' && $isFastly === false) {
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
            $systemGenerator = new SystemCommandGenerator($this->magentoMode === 'developer');
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

        $baseUrl = StoreContext::forRoot($this->magentoRoot)->getBaseSecureUrl();
        $output->writeln(sprintf(
            '<info>Target store:</info> %s',
            $baseUrl !== null
                ? $baseUrl
                : '(not resolvable - no default-scope base URL in env vars, env.php, config.php or the database)'
        ));

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
        // Same weighted model as HTML reports, baselines and MCP (configurable via .m2perf.json)
        $score = $this->runner->computeScore($this->lastRecommendations);
        $counts = $score['counts'];

        $summary = [
            sprintf('Performance Score: %s', $this->formatPerformanceScore($score['score'], $score['grade'])),
            sprintf('Total Issues: %d', $counts['high'] + $counts['medium'] + $counts['low']),
            sprintf('🔴 <error>High: %d</error>', $counts['high']),
            sprintf('🟡 <comment>Medium: %d</comment>', $counts['medium']),
            sprintf('🟢 <info>Low: %d</info>', $counts['low'])
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

        $info[] = sprintf(
            'Analyzers: %d/%d Executed',
            $this->executedAnalyzers,
            count($this->runner->getAvailableAnalyzerNames())
        );
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

    private function formatPerformanceScore(float $score, string $grade): string
    {
        $style = match (true) {
            $score >= 90 => 'info',
            $score >= 60 => 'comment',
            default => 'error'
        };

        return sprintf('<%s>%.1f/100 (%s)</%s>', $style, $score, $grade, $style);
    }

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

    private function checkAndNotifyUpdates(InputInterface $input, OutputInterface $output): void
    {
        // Only check if running from PHAR
        if (!\Phar::running()) {
            return;
        }

        // Network check only for humans at a terminal: never in CI, pipes or -n runs
        if ($input->isInteractive() === true) {
            UpdateChecker::checkIfDue();
        }

        $updateInfo = UpdateChecker::getUpdateInfo();
        if ($updateInfo !== null && version_compare((string)$updateInfo['version'], ToolVersion::VERSION, '>') === true) {
            $output->writeln('');
            $output->writeln('<bg=yellow;fg=black> ⚡ Update Available ⚡ </>');
            $output->writeln(sprintf(
                '<comment>A new version (%s) is available. Run <info>self-update</info> to upgrade.</comment>',
                $updateInfo['version']
            ));
            $output->writeln('');
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

    private function compareToBaseline(string $baselineName, string $profile, OutputInterface $output): void
    {
        $manager = new BaselineManager($this->magentoRoot);
        $comparison = $manager->compare(
            $baselineName,
            $this->runner->toArray($this->lastRecommendations),
            $this->runner->computeScore($this->lastRecommendations),
            $profile,
            $this->runner->getAnalysisMode()
        );

        if (isset($comparison['error']) === true) {
            $output->writeln('<error>' . $comparison['error'] . '</error>');
            return;
        }

        $output->writeln('');
        $output->writeln('<info>=== Baseline Comparison: ' . $baselineName . ' ===</info>');
        $output->writeln($comparison['summary']);
        if (isset($comparison['warning']) === true) {
            $output->writeln('<comment>' . $comparison['warning'] . '</comment>');
        }
        if ($comparison['is_regression'] === true) {
            $output->writeln('<error>Status: REGRESSION detected</error>');
        } else {
            $output->writeln('<info>Status: OK (no regression)</info>');
        }
        $output->writeln('');
    }
}
