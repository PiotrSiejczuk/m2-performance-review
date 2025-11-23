<?php

namespace M2Performance;

use Symfony\Component\Console\Application as ConsoleApplication;
use M2Performance\Command\M2PerformanceCommand;
use M2Performance\Command\SelfUpdateCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class Application extends ConsoleApplication
{
    private const VERSION = '1.0.3';
    
    public function __construct()
    {
        parent::__construct('M2 Performance Review Tool', self::VERSION);
        
        // Add main command
        $command = new M2PerformanceCommand();
        $this->add($command);
        $this->setDefaultCommand($command->getName(), true);
        
        // Add self-update command
        if (\Phar::running()) {
            $this->add(new SelfUpdateCommand());
            
            // Check for updates in background (non-blocking)
            $this->checkForUpdatesInBackground();
        }
        
        // Add other commands if they exist
        $this->registerAdditionalCommands();
    }
    
    private function registerAdditionalCommands(): void
    {
        // Register other commands if their classes exist
        $additionalCommands = [
            'M2Performance\Command\BenchmarkCommand',
            'M2Performance\Command\GenerateCommand',
            'M2Performance\Command\MonitorCommand',
            'M2Performance\Command\CacheMonitorCommand'
        ];
        
        foreach ($additionalCommands as $commandClass) {
            try {
                if (class_exists($commandClass)) {
                    // Additional check for file existence in PHAR
                    $classFile = str_replace('\\', '/', $commandClass) . '.php';
                    $classFile = str_replace('M2Performance/', 'src/', $classFile);
                    
                    // If running from PHAR, check if file exists
                    if (\Phar::running()) {
                        $pharPath = \Phar::running(false);
                        $fullPath = 'phar://' . $pharPath . '/' . $classFile;
                        if (!file_exists($fullPath)) {
                            continue; // Skip if file doesn't exist in PHAR
                        }
                    }
                    
                    $this->add(new $commandClass());
                }
            } catch (\Exception $e) {
                // Silently skip commands that can't be loaded
                // You can add logging here if needed
            }
        }
    }
    
    private function checkForUpdatesInBackground(): void
    {
        // Only check if we should (once per day)
        if (!SelfUpdateCommand::shouldCheckForUpdates()) {
            return;
        }
        
        // Run check in background to not slow down the main command
        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child process - check for updates
                try {
                    $command = $this->find('self-update');
                    $input = new ArrayInput(['command' => 'self-update', '--check' => true]);
                    $output = new NullOutput();
                    $command->run($input, $output);
                } catch (\Exception $e) {
                    // Ignore errors in background check
                }
                exit(0);
            }
        }
    }
    
    public function getLongVersion(): string
    {
        $version = parent::getLongVersion();
        
        if (\Phar::running()) {
            try {
                $phar = new \Phar(\Phar::running(false));
                $metadata = $phar->getMetadata();
                if (isset($metadata['build_date'])) {
                    $version .= ' (built: ' . $metadata['build_date'] . ')';
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }
        
        return $version;
    }
}