<?php

namespace M2Performance\Service;

use M2Performance\Contract\AnalyzerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class AsyncCheckRunner
{
    private array $analyzers = [];
    private int $processLimit = 5;
    private array $results = [];

    public function addAnalyzer(AnalyzerInterface $analyzer): void
    {
        $this->analyzers[] = $analyzer;
    }

    public function setProcessLimit(int $limit): void
    {
        $this->processLimit = max(1, $limit);
    }

    public function runAsync(OutputInterface $output): array
    {
        if (empty($this->analyzers)) {
            return [];
        }

        $progressBar = new ProgressBar($output, count($this->analyzers));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->start();

        $chunks = array_chunk($this->analyzers, $this->processLimit);

        foreach ($chunks as $chunk) {
            $processes = [];

            foreach ($chunk as $index => $analyzer) {
                $className = get_class($analyzer);
                $progressBar->setMessage("Analyzing " . basename(str_replace('\\', '/', $className)));

                // For simplicity, run synchronously
                // In production, this would serialize analyzer and run in subprocess
                try {
                    $analyzer->analyze();
                    $progressBar->advance();
                } catch (\Exception $e) {
                    $output->writeln("\n<error>Error in {$className}: {$e->getMessage()}</error>");
                }
            }
        }

        $progressBar->finish();
        $output->writeln('');

        return $this->results;
    }
}
