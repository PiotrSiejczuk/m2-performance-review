<?php

namespace M2Performance;

use Symfony\Component\Console\Application as ConsoleApplication;
use M2Performance\Command\M2PerformanceCommand;
use M2Performance\Command\SelfUpdateCommand;

class Application extends ConsoleApplication
{
    public const VERSION = ToolVersion::VERSION;
    
    public function __construct()
    {
        parent::__construct('M2 Performance Review Tool', self::VERSION);
        
        // Add main command
        $command = new M2PerformanceCommand();
        $this->add($command);
        // Default to the review command but keep multi-command routing enabled
        // (single-command mode previously blocked self-update/benchmark/mcp routing)
        $this->setDefaultCommand($command->getName(), false);
        
        // Add self-update command
        // NOTE: no background update check here. A pcntl_fork()ed child shares
        // the parent's open PHAR file descriptor (and its offset), so concurrent
        // class loading corrupted reads at random ("Command ... is not defined",
        // parse errors inside vendor files). The daily check now runs
        // synchronously from the review command, interactive sessions only.
        if (\Phar::running()) {
            $this->add(new SelfUpdateCommand());
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
            'M2Performance\Command\CacheMonitorCommand',
            'M2Performance\Command\McpServerCommand',
            'M2Performance\Command\BaselineCommand'
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