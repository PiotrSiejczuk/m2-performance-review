<?php
namespace M2Performance\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use M2Performance\Service\MonitoringServer;

class MonitorCommand extends Command
{
    protected static $defaultName = 'monitor';

    protected function configure(): void
    {
        $this
            ->setDescription('Start real-time performance monitoring server')
            ->setHelp('Starts a web-based monitoring dashboard with real-time metrics')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to run monitoring server on', 8080)
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = (int)$input->getOption('port');
        $magentoRoot = $input->getOption('magento-root');

        // Check if React/ReactPHP is available
        if (!class_exists('React\EventLoop\Loop')) {
            $output->writeln('<error>ReactPHP is required for the monitoring server.</error>');
            $output->writeln('Install it with: composer require react/event-loop react/socket react/http');
            return Command::FAILURE;
        }

        $output->writeln('<info>Starting M2 Performance Monitoring Server...</info>');
        $output->writeln('');

        try {
            $server = new MonitoringServer($magentoRoot, $port);
            $server->start();
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to start monitoring server: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
