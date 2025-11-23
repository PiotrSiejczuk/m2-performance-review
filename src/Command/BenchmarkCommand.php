<?php
namespace M2Performance\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use M2Performance\Service\BenchmarkRunner;

class BenchmarkCommand extends Command
{
    protected static $defaultName = 'benchmark';

    protected function configure(): void
    {
        $this
            ->setDescription('Run performance benchmarks')
            ->setHelp('Runs comprehensive performance benchmarks against your Magento installation')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root', getcwd())
            ->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'Base URL of Magento installation')
            ->addOption('scenarios', 's', InputOption::VALUE_REQUIRED, 'Comma-separated list of scenarios to run', 'all')
            ->addOption('export', 'e', InputOption::VALUE_REQUIRED, 'Export results to file')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Export format (json, csv)', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magentoRoot = $input->getOption('magento-root');
        $scenarios = $input->getOption('scenarios');

        $output->writeln('<info>M2 Performance Benchmark Tool</info>');
        $output->writeln('============================');
        $output->writeln('');

        // Initialize benchmark runner
        $runner = new BenchmarkRunner($magentoRoot);

        // Set base URL if provided
        if ($url = $input->getOption('url')) {
            putenv('MAGENTO_BASE_URL=' . $url);
        }

        $output->writeln('<comment>Running performance benchmarks...</comment>');
        $output->writeln('');

        try {
            $results = $runner->runBenchmarks($output);
            $this->displayResults($results, $output);

            // Export if requested
            if ($exportFile = $input->getOption('export')) {
                $this->exportResults($results, $exportFile, $input->getOption('format'), $output);
            }

            // Show summary
            $this->showSummary($results, $output);

        } catch (\Exception $e) {
            $output->writeln('<e>Benchmark failed: ' . $e->getMessage() . '</e>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function displayResults(array $results, OutputInterface $output): void
    {
        foreach ($results as $name => $benchmark) {
            if (is_array($benchmark) && isset($benchmark['name'])) {
                $output->writeln('<comment>' . $benchmark['name'] . '</comment>');

                $table = new Table($output);
                $table->setHeaders(['Metric', 'Value', 'Status']);

                $rows = [];
                foreach ($benchmark as $key => $value) {
                    if (in_array($key, ['name', 'status', 'recommendation'])) {
                        continue;
                    }

                    if (is_array($value)) {
                        continue; // Skip complex data for now
                    }

                    $status = $this->getStatusIcon($benchmark['status'] ?? 'unknown');
                    $rows[] = [
                        $this->formatMetricName($key),
                        $value,
                        $status
                    ];
                }

                $table->setRows($rows);
                $table->render();

                if (!empty($benchmark['recommendation'])) {
                    $output->writeln('<info>Recommendation:</info> ' . $benchmark['recommendation']);
                }

                $output->writeln('');
            }
        }
    }

    private function showSummary(array $results, OutputInterface $output): void
    {
        $output->writeln('<info>Benchmark Summary</info>');
        $output->writeln('-----------------');

        $goodCount = 0;
        $warningCount = 0;
        $criticalCount = 0;

        foreach ($results as $benchmark) {
            if (isset($benchmark['status'])) {
                switch ($benchmark['status']) {
                    case 'good':
                        $goodCount++;
                        break;
                    case 'warning':
                        $warningCount++;
                        break;
                    case 'critical':
                        $criticalCount++;
                        break;
                }
            }
        }

        $output->writeln(sprintf(
            '<info>✓ Good:</info> %d | <comment>⚠ Warning:</comment> %d | <e>✗ Critical:</e> %d',
            $goodCount,
            $warningCount,
            $criticalCount
        ));

        if ($criticalCount > 0) {
            $output->writeln('');
            $output->writeln('<e>Critical performance issues detected. Immediate action recommended.</e>');
        } elseif ($warningCount > 0) {
            $output->writeln('');
            $output->writeln('<comment>Some performance optimizations available.</comment>');
        } else {
            $output->writeln('');
            $output->writeln('<info>Performance is within acceptable parameters.</info>');
        }
    }

    private function exportResults(array $results, string $filename, string $format, OutputInterface $output): void
    {
        $content = match($format) {
            'json' => json_encode($results, JSON_PRETTY_PRINT),
            'csv' => $this->formatAsCsv($results),
            default => json_encode($results, JSON_PRETTY_PRINT)
        };

        file_put_contents($filename, $content);
        $output->writeln("<info>Results exported to: $filename</info>");
    }

    private function formatAsCsv(array $results): string
    {
        $csv = "Benchmark,Metric,Value,Status\n";

        foreach ($results as $name => $benchmark) {
            if (!is_array($benchmark)) {
                continue;
            }

            $benchmarkName = $benchmark['name'] ?? $name;

            foreach ($benchmark as $key => $value) {
                if (in_array($key, ['name', 'status', 'recommendation']) || is_array($value)) {
                    continue;
                }

                $csv .= sprintf(
                    '"%s","%s","%s","%s"' . "\n",
                    $benchmarkName,
                    $this->formatMetricName($key),
                    $value,
                    $benchmark['status'] ?? 'unknown'
                );
            }
        }

        return $csv;
    }

    private function formatMetricName(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }

    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'good' => '<info>✓</info>',
            'warning' => '<comment>⚠</comment>',
            'critical' => '<e>✗</e>',
            default => '?'
        };
    }
}
