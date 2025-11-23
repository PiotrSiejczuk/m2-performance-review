<?php

namespace M2Performance\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use M2Performance\Service\CacheMetricsService;

class CacheMonitorCommand extends Command
{
    protected static $defaultName = 'cache:monitor';
    
    protected function configure(): void
    {
        $this
            ->setDescription('Monitor cache performance metrics in real-time')
            ->setHelp('Displays real-time Varnish and Redis cache metrics');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magentoRoot = getcwd();
        $metricsService = new CacheMetricsService($magentoRoot);
        
        while (true) {
            $output->write("\033[2J\033[H"); // Clear screen
            $output->writeln('<info>Cache Performance Monitor - ' . date('Y-m-d H:i:s') . '</info>');
            $output->writeln('');
            
            // Get Varnish metrics
            $varnishMetrics = $metricsService->getVarnishMetrics();
            if ($varnishMetrics['available']) {
                $output->writeln('<comment>Varnish Metrics:</comment>');
                $output->writeln(sprintf('  Hit Rate: %.2f%%', $varnishMetrics['hit_rate']));
                $output->writeln(sprintf('  Bypass Rate: %.2f%%', $varnishMetrics['bypass_rate']));
                $output->writeln(sprintf('  Total Requests: %s', number_format($varnishMetrics['client_requests'])));
                $output->writeln('');
            }
            
            // Get Redis metrics
            $redisMetrics = $metricsService->getRedisMetrics();
            if ($redisMetrics['available']) {
                $output->writeln('<comment>Redis Metrics:</comment>');
                $output->writeln(sprintf('  Hit Rate: %.2f%%', $redisMetrics['hit_rate']));
                $output->writeln(sprintf('  Memory Used: %s MB', round($redisMetrics['memory_used'] / 1024 / 1024, 2)));
                $output->writeln(sprintf('  Evicted Keys: %s', number_format($redisMetrics['evicted_keys'])));
            }
            
            $output->writeln('');
            $output->writeln('Press Ctrl+C to exit');
            
            sleep(5);
        }
        
        return Command::SUCCESS;
    }
}
