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

        // --url wins over the store's configured base URL
        $url = $input->getOption('url');
        $runner = new BenchmarkRunner($magentoRoot, is_string($url) === true ? $url : null);

        $selectedScenarios = [];
        if (is_string($scenarios) === true && $scenarios !== '' && strtolower($scenarios) !== 'all') {
            $selectedScenarios = array_values(array_filter(array_map('trim', explode(',', strtolower($scenarios)))));
            $unknown = array_diff($selectedScenarios, BenchmarkRunner::SCENARIOS);
            if (count($unknown) > 0) {
                $output->writeln(sprintf(
                    '<error>Unknown scenario(s): %s. Available: %s</error>',
                    implode(', ', $unknown),
                    implode(', ', BenchmarkRunner::SCENARIOS)
                ));

                return Command::INVALID;
            }
        }

        $output->writeln('<comment>Running performance benchmarks...</comment>');
        $output->writeln('');

        try {
            $results = $runner->runBenchmarks($output, $selectedScenarios);
            $this->displayResults($results, $output);

            // Export if requested
            if ($exportFile = $input->getOption('export')) {
                $this->exportResults($results, $exportFile, $input->getOption('format'), $output);
            }

            // Show summary
            $this->showSummary($results, $output);

        } catch (\Exception $e) {
            $output->writeln('<error>Benchmark failed: ' . $e->getMessage() . '</error>');
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

                    // Cache/database scenarios nest per-probe results; they used to be skipped (empty tables)
                    if ($key === 'results' && is_array($value)) {
                        foreach ($value as $probe => $probeValue) {
                            if (is_array($probeValue) === false) {
                                $rows[] = [$this->formatMetricName((string)$probe), $probeValue, ''];
                                continue;
                            }
                            foreach ($probeValue as $metric => $metricValue) {
                                if ($metric === 'status' || is_array($metricValue)) {
                                    continue;
                                }
                                $rows[] = [
                                    $this->formatMetricName($probe . ' ' . $metric),
                                    $metricValue,
                                    $this->getStatusIcon((string)($probeValue['status'] ?? 'unknown')),
                                ];
                            }
                        }
                        continue;
                    }

                    if (is_array($value)) {
                        continue;
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
        $errorCount = 0;

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
                    case 'error':
                        $errorCount++;
                        break;
                }
            }
        }

        $output->writeln(sprintf(
            '<info>✓ Good:</info> %d | <comment>⚠ Warning:</comment> %d | <error>✗ Critical:</error> %d | <error>✗ Failed:</error> %d',
            $goodCount,
            $warningCount,
            $criticalCount,
            $errorCount
        ));

        if ($errorCount > 0) {
            $output->writeln('');
            $output->writeln('<error>Some scenarios could not be measured (see recommendations above).</error>');
        } elseif ($criticalCount > 0) {
            $output->writeln('');
            $output->writeln('<error>Critical performance issues detected. Immediate action recommended.</error>');
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
            'critical' => '<error>✗</error>',
            'error' => '<error>✗ failed</error>',
            default => '?'
        };
    }
}
