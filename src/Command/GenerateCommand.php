<?php
namespace M2Performance\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use M2Performance\Service\InfrastructureGenerator;
use M2Performance\Service\VarnishGenerator;
use M2Performance\Service\AIRecommendationEngine;
use M2Performance\Helper\RecommendationCollector;

class GenerateCommand extends Command
{
    protected static $defaultName = 'generate';

    protected function configure(): void
    {
        $this
            ->setDescription('Generate infrastructure code or optimization scripts')
            ->setHelp('Generates Terraform, Ansible, Docker configurations or AI recommendations based on analysis')
            ->addArgument('type', InputArgument::OPTIONAL, 'Type of output to generate (infra, recommendations, report)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Infrastructure format (terraform, ansible, docker) or report format (html, pdf)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root', getcwd())
            ->addOption('ai-key', null, InputOption::VALUE_REQUIRED, 'OpenAI API key for AI recommendations')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file for infrastructure generation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument('type');

        // If no type specified, ask interactively
        if (!$type) {
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                'What would you like to generate?',
                ['infra' => 'Infrastructure Code', 'recommendations' => 'AI Recommendations', 'report' => 'Performance Report'],
                'infra'
            );
            $type = $helper->ask($input, $output, $question);
        }

        $output->writeln('<info>M2 Performance Code Generator</info>');
        $output->writeln('=============================');
        $output->writeln('');

        try {
            switch ($type) {
                case 'infra':
                    return $this->generateInfrastructure($input, $output);

                case 'recommendations':
                    return $this->generateAIRecommendations($input, $output);

                case 'report':
                    return $this->generateReport($input, $output);

                default:
                    $output->writeln('<e>Unknown generation type: ' . $type . '</e>');
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln('<e>Generation failed: ' . $e->getMessage() . '</e>');
            return Command::FAILURE;
        }
    }

    private function generateInfrastructure(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!$format) {
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                'Select infrastructure format:',
                ['terraform', 'ansible', 'docker'],
                'terraform'
            );
            $format = $helper->ask($input, $output, $question);
        }

        // Run analysis first to get recommendations
        $output->writeln('<comment>Analyzing current setup...</comment>');
        $recommendations = $this->runAnalysis($input->getOption('magento-root'), $output);

        $output->writeln('');
        $output->writeln('<comment>Generating ' . ucfirst($format) . ' configuration...</comment>');

        // Load configuration if provided
        $config = [];
        if ($configFile = $input->getOption('config')) {
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?: [];
            }
        }

        // Generate infrastructure code
        $generator = new InfrastructureGenerator($recommendations, $config);
        $content = $generator->generate($format);

        // Determine output file
        $outputFile = $input->getOption('output') ?: $this->getDefaultFilename($format);

        // Write to file
        file_put_contents($outputFile, $content);

        $output->writeln('<info>✓ Generated ' . ucfirst($format) . ' configuration</info>');
        $output->writeln('  File: ' . $outputFile);
        $output->writeln('  Size: ' . $this->formatFileSize(strlen($content)));
        $output->writeln('');

        // Show next steps
        $this->showNextSteps($format, $outputFile, $output);

        return Command::SUCCESS;
    }

    private function generateAIRecommendations(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Analyzing current setup...</comment>');
        $recommendations = $this->runAnalysis($input->getOption('magento-root'), $output);

        $output->writeln('');
        $output->writeln('<comment>Generating AI-powered recommendations...</comment>');

        // Get API key
        $apiKey = $input->getOption('ai-key') ?: getenv('OPENAI_API_KEY');

        // Generate recommendations
        $engine = new AIRecommendationEngine($recommendations, $apiKey);
        $aiRecommendations = $engine->generateRecommendations();
        $actionPlan = $engine->generateActionPlan();
        $insights = $engine->generatePredictiveInsights();

        // Format output
        $content = $this->formatAIRecommendations($aiRecommendations, $actionPlan, $insights);

        // Determine output file
        $outputFile = $input->getOption('output') ?: 'ai-recommendations.md';

        // Write to file
        file_put_contents($outputFile, $content);

        $output->writeln('<info>✓ Generated AI recommendations</info>');
        $output->writeln('  File: ' . $outputFile);
        $output->writeln('');

        // Show summary
        $this->showAISummary($aiRecommendations, $output);

        return Command::SUCCESS;
    }

    private function generateReport(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format') ?: 'html';

        $output->writeln('<comment>Generating performance report...</comment>');

        // Run full analysis
        $recommendations = $this->runAnalysis($input->getOption('magento-root'), $output);

        // Generate report content based on format
        $content = match($format) {
            'html' => $this->generateHtmlReport($recommendations),
            'pdf' => $this->generatePdfReport($recommendations),
            default => $this->generateHtmlReport($recommendations)
        };

        // Determine output file
        $outputFile = $input->getOption('output') ?: 'performance-report.' . $format;

        // Write to file
        file_put_contents($outputFile, $content);

        $output->writeln('<info>✓ Generated performance report</info>');
        $output->writeln('  File: ' . $outputFile);
        $output->writeln('  Format: ' . strtoupper($format));

        return Command::SUCCESS;
    }

    private function runAnalysis(string $magentoRoot, OutputInterface $output): array
    {
        $collector = new RecommendationCollector();

        // Initialize and run all analyzers
        $envLoader = new \M2Performance\Service\EnvironmentLoader($magentoRoot);
        $xmlReader = new \M2Performance\Service\XmlReader($magentoRoot);

        $analyzers = [
            new \M2Performance\Analyzer\ConfigurationAnalyzer($magentoRoot, $xmlReader->getCoreConfig(), $xmlReader->isAdobeCommerce(), $collector),
            new \M2Performance\Analyzer\CacheAnalyzer($magentoRoot, $xmlReader->getCoreConfig(), $collector),
            new \M2Performance\Analyzer\DatabaseAnalyzer($magentoRoot, $collector),
            new \M2Performance\Analyzer\ModulesAnalyzer($magentoRoot, $collector),
            new \M2Performance\Analyzer\CodebaseAnalyzer($magentoRoot, $collector),
            new \M2Performance\Analyzer\FrontendAnalyzer($magentoRoot, $xmlReader->getCoreConfig(), $collector),
            new \M2Performance\Analyzer\IndexersAnalyzer($magentoRoot, $xmlReader->getCoreConfig(), $collector),
            new \M2Performance\Analyzer\OpCacheAnalyzer($collector),
            new \M2Performance\Analyzer\RedisEnhancedAnalyzer($magentoRoot, $collector),
            new \M2Performance\Analyzer\APIRateLimitingAnalyzer($magentoRoot, $collector),
            new \M2Performance\Analyzer\SecurityChecklistAnalyzer($magentoRoot, $collector),
        ];

        $progress = new \Symfony\Component\Console\Helper\ProgressBar($output, count($analyzers));
        $progress->start();

        foreach ($analyzers as $analyzer) {
            try {
                $analyzer->analyze();
            } catch (\Exception $e) {
                // Skip failed analyzers
            }
            $progress->advance();
        }

        $progress->finish();
        $output->writeln('');

        return $collector->getAll();
    }

    private function getDefaultFilename(string $format): string
    {
        return match($format) {
            'terraform' => 'magento-infrastructure.tf',
            'ansible' => 'magento-playbook.yml',
            'docker' => 'docker-compose.yml',
            default => 'output.' . $format
        };
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    private function showNextSteps(string $format, string $file, OutputInterface $output): void
    {
        $output->writeln('<comment>Next steps:</comment>');

        switch ($format) {
            case 'terraform':
                $output->writeln('  1. Review and customize the generated configuration');
                $output->writeln('  2. Initialize Terraform: terraform init');
                $output->writeln('  3. Plan deployment: terraform plan');
                $output->writeln('  4. Apply changes: terraform apply');
                break;

            case 'ansible':
                $output->writeln('  1. Review and customize the playbook');
                $output->writeln('  2. Update inventory file with target hosts');
                $output->writeln('  3. Run playbook: ansible-playbook -i inventory ' . $file);
                break;

            case 'docker':
                $output->writeln('  1. Review and customize docker-compose.yml');
                $output->writeln('  2. Create necessary directories and config files');
                $output->writeln('  3. Start services: docker-compose up -d');
                $output->writeln('  4. Check logs: docker-compose logs -f');
                break;
        }
    }

    private function formatAIRecommendations(array $recommendations, array $actionPlan, array $insights): string
    {
        $markdown = "# Magento 2 Performance Optimization Plan\n\n";
        $markdown .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        $markdown .= "## Executive Summary\n\n";
        $markdown .= $recommendations['summary'] ?? 'Analysis complete.';
        $markdown .= "\n\n";

        $markdown .= "## Quick Wins (< 1 day)\n\n";
        foreach ($recommendations['quick_wins'] ?? [] as $item) {
            $markdown .= "### " . $item['title'] . "\n";
            $markdown .= "- **Impact**: " . $item['impact'] . "/10\n";
            $markdown .= "- **Effort**: " . $item['effort'] . "/10\n";
            $markdown .= "- **Steps**:\n";
            foreach ($item['steps'] ?? [] as $step) {
                $markdown .= "  - " . $step . "\n";
            }
            $markdown .= "\n";
        }

        $markdown .= "## Strategic Improvements (1-4 weeks)\n\n";
        foreach ($recommendations['strategic'] ?? [] as $item) {
            $markdown .= "### " . $item['title'] . "\n";
            $markdown .= "- **Impact**: " . $item['impact'] . "/10\n";
            $markdown .= "- **Effort**: " . $item['effort'] . "/10\n";
            $markdown .= "\n";
        }

        $markdown .= "## Prioritized Action Plan\n\n";
        $markdown .= "| Priority | Task | Impact | Effort | ROI Score |\n";
        $markdown .= "|----------|------|--------|--------|----------|\n";

        foreach (array_slice($actionPlan, 0, 10) as $i => $action) {
            $markdown .= sprintf(
                "| %d | %s | %d | %d | %.2f |\n",
                $i + 1,
                $action['recommendation']->getMessage(),
                $action['impact_score'],
                $action['effort_score'],
                $action['roi_score']
            );
        }

        $markdown .= "\n## Predictive Insights\n\n";
        foreach ($insights as $insight) {
            $markdown .= "- **" . $insight['pattern'] . "**: " . $insight['mitigation_strategy'] . "\n";
        }

        return $markdown;
    }

    private function showAISummary(array $recommendations, OutputInterface $output): void
    {
        $output->writeln('<comment>Summary:</comment>');
        $output->writeln('  Quick wins identified: ' . count($recommendations['quick_wins'] ?? []));
        $output->writeln('  Strategic improvements: ' . count($recommendations['strategic'] ?? []));
        $output->writeln('  Long-term optimizations: ' . count($recommendations['long_term'] ?? []));
    }

    private function generateHtmlReport(array $recommendations): string
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Magento 2 Performance Report</title>
    <meta charset="utf-8">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        h1 {
            color: #1a73e8;
            border-bottom: 3px solid #1a73e8;
            padding-bottom: 1rem;
        }
        h2 {
            color: #333;
            margin-top: 2rem;
        }
        .summary {
            background: #f8f9fa;
            border-left: 4px solid #1a73e8;
            padding: 1rem;
            margin: 2rem 0;
        }
        .recommendations {
            display: grid;
            gap: 1rem;
        }
        .recommendation {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .priority-high {
            border-left: 4px solid #d23f31;
        }
        .priority-medium {
            border-left: 4px solid #f9ab00;
        }
        .priority-low {
            border-left: 4px solid #0f9d58;
        }
        .metric {
            display: inline-block;
            background: #e8f5e9;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            margin-right: 0.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        th, td {
            text-align: left;
            padding: 0.5rem;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .footer {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            color: #666;
        }
    </style>
</head>
<body>
    <h1>Magento 2 Performance Analysis Report</h1>
    
    <div class="summary">
        <h2>Executive Summary</h2>
        <p>Analysis completed on <?= date('Y-m-d H:i:s') ?></p>
        <p>Total issues found: <strong><?= count($recommendations) ?></strong></p>
    </div>
    
    <h2>Recommendations by Priority</h2>
    
HTML;

        // Group by priority
        $byPriority = [];
        foreach ($recommendations as $rec) {
            $byPriority[$rec->getPriority()][] = $rec;
        }

        foreach (['High', 'Medium', 'Low'] as $priority) {
            if (!empty($byPriority[$priority])) {
                $html .= "<h3>$priority Priority Issues</h3>\n";
                $html .= '<div class="recommendations">';

                foreach ($byPriority[$priority] as $rec) {
                    $html .= sprintf(
                        '<div class="recommendation priority-%s">
                            <h4>%s</h4>
                            <p><span class="metric">Area: %s</span></p>
                            <p>%s</p>
                            %s
                        </div>',
                        strtolower($priority),
                        htmlspecialchars($rec->getMessage()),
                        htmlspecialchars($rec->getArea()),
                        htmlspecialchars($rec->getDetail()),
                        $rec->getDetail() ? '<pre>' . htmlspecialchars($rec->getDetail()) . '</pre>' : ''
                    );
                }

                $html .= '</div>';
            }
        }

        $html .= <<<'HTML'
    
    <div class="footer">
        <p>Generated by M2 Performance Review Tool v1.0</p>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    private function generatePdfReport(array $recommendations): string
    {
        // For now, return HTML that can be converted to PDF
        // In production, use a proper PDF library like TCPDF or Dompdf
        return $this->generateHtmlReport($recommendations);
    }
}
