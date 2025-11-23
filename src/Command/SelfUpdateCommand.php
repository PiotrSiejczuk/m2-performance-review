<?php

namespace M2Performance\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\ProgressBar;

class SelfUpdateCommand extends Command
{
    protected static $defaultName = 'self-update';
    
    private const GITHUB_API_URL = 'https://api.github.com/repos/PiotrSiejczuk/m2-performance-review/releases/latest';
    private const UPDATE_CHECK_FILE = '.m2-performance-update-check';
    private const UPDATE_CHECK_INTERVAL = 86400; // 24 hours
    
    protected function configure(): void
    {
        $this
            ->setAliases(['selfupdate', 'update'])
            ->setDescription('Update M2 Performance Tool to the latest version')
            ->setHelp('This command checks for updates and upgrades the tool to the latest version')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force update even if already on latest version')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only check for updates without installing')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Rollback to previous version')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Update channel (stable, beta, dev)', 'stable')
            ->addOption('no-progress', null, InputOption::VALUE_NONE, 'Disable progress bar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>M2 Performance Tool Self-Update</info>');
        $output->writeln('');
        
        // Check if running from PHAR
        $pharPath = \Phar::running(false);
        if (!$pharPath) {
            $output->writeln('<error>Self-update only works when running from a PHAR file.</error>');
            $output->writeln('Please download the PHAR version from: https://github.com/PiotrSiejczuk/m2-performance-review/releases');
            return Command::FAILURE;
        }
        
        // Get current version from PHAR metadata
        try {
            $phar = new \Phar($pharPath);
            $metadata = $phar->getMetadata();
            $currentVersion = $metadata['version'] ?? 'unknown';
        } catch (\Exception $e) {
            $currentVersion = 'unknown';
        }
        
        $output->writeln("Current version: <comment>{$currentVersion}</comment>");
        
        // Handle rollback
        if ($input->getOption('rollback')) {
            return $this->rollback($pharPath, $output);
        }
        
        // Check for updates
        $output->write('Checking for updates...');
        
        try {
            $latestRelease = $this->getLatestRelease($input->getOption('channel'));
            $latestVersion = $latestRelease['version'];
            $downloadUrl = $latestRelease['download_url'];
            
            $output->writeln(' done!');
            $output->writeln("Latest version: <comment>{$latestVersion}</comment>");
            
            if ($input->getOption('check')) {
                if (version_compare($currentVersion, $latestVersion, '<')) {
                    $output->writeln('<info>An update is available!</info>');
                    $output->writeln('Run <comment>./m2-performance.phar self-update</comment> to install it.');
                    return Command::SUCCESS;
                } else {
                    $output->writeln('<info>You are already using the latest version.</info>');
                    return Command::SUCCESS;
                }
            }
            
            // Check if update is needed
            if (!$input->getOption('force') && version_compare($currentVersion, $latestVersion, '>=')) {
                $output->writeln('<info>You are already using the latest version.</info>');
                return Command::SUCCESS;
            }
            
            // Confirm update
            if ($input->isInteractive() && !$input->getOption('force')) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion(
                    "Update to version <comment>{$latestVersion}</comment>? [Y/n] ",
                    true
                );
                
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('Update cancelled.');
                    return Command::SUCCESS;
                }
            }
            
            // Download update
            $output->writeln('');
            $output->writeln("Downloading version <comment>{$latestVersion}</comment>...");
            
            $tempFile = $this->downloadUpdate($downloadUrl, $output, !$input->getOption('no-progress'));
            
            // Verify download
            $output->write('Verifying download...');
            if (!$this->verifyDownload($tempFile, $latestRelease)) {
                unlink($tempFile);
                $output->writeln(' <error>failed!</error>');
                $output->writeln('<error>Download verification failed. Update aborted.</error>');
                return Command::FAILURE;
            }
            $output->writeln(' <info>OK</info>');
            
            // Backup current version
            $backupFile = $pharPath . '.backup';
            $output->write('Creating backup...');
            if (!copy($pharPath, $backupFile)) {
                unlink($tempFile);
                $output->writeln(' <error>failed!</error>');
                $output->writeln('<error>Could not create backup. Update aborted.</error>');
                return Command::FAILURE;
            }
            $output->writeln(' <info>OK</info>');
            
            // Replace current PHAR
            $output->write('Installing update...');
            if (!$this->replaceFile($tempFile, $pharPath)) {
                // Restore backup
                copy($backupFile, $pharPath);
                unlink($tempFile);
                unlink($backupFile);
                $output->writeln(' <error>failed!</error>');
                $output->writeln('<error>Could not install update. Original version restored.</error>');
                return Command::FAILURE;
            }
            $output->writeln(' <info>OK</info>');
            
            // Clean up
            unlink($tempFile);
            
            // Save update check timestamp
            $this->saveUpdateCheckTimestamp();
            
            $output->writeln('');
            $output->writeln('<info>✅ Successfully updated to version ' . $latestVersion . '</info>');
            $output->writeln('');
            $output->writeln('Please run the tool again to use the new version.');
            
            // Write changelog if available
            if (!empty($latestRelease['changelog'])) {
                $output->writeln('');
                $output->writeln('<comment>Changelog:</comment>');
                $output->writeln($latestRelease['changelog']);
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln(' <error>failed!</error>');
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
    
    private function getLatestRelease(string $channel): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: M2-Performance-Tool',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 30
            ]
        ]);
        
        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
        
        if ($response === false) {
            throw new \RuntimeException('Could not connect to GitHub API');
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['tag_name'])) {
            throw new \RuntimeException('Invalid response from GitHub API');
        }
        
        // Find PHAR asset
        $pharAsset = null;
        foreach ($data['assets'] ?? [] as $asset) {
            if (strpos($asset['name'], '.phar') !== false) {
                $pharAsset = $asset;
                break;
            }
        }
        
        if (!$pharAsset) {
            throw new \RuntimeException('No PHAR file found in latest release');
        }
        
        return [
            'version' => ltrim($data['tag_name'], 'v'),
            'download_url' => $pharAsset['browser_download_url'],
            'size' => $pharAsset['size'],
            'sha1' => $data['target_commitish'] ?? null,
            'changelog' => $data['body'] ?? ''
        ];
    }
    
    private function downloadUpdate(string $url, OutputInterface $output, bool $showProgress): string
    {
        $tempFile = sys_get_temp_dir() . '/m2-performance-' . uniqid() . '.phar';
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: M2-Performance-Tool'
                ],
                'timeout' => 300
            ]
        ]);
        
        $source = @fopen($url, 'rb', false, $context);
        if (!$source) {
            throw new \RuntimeException('Could not download update');
        }
        
        $dest = @fopen($tempFile, 'wb');
        if (!$dest) {
            fclose($source);
            throw new \RuntimeException('Could not create temporary file');
        }
        
        // Get file size for progress bar
        $headers = stream_get_meta_data($source)['wrapper_data'] ?? [];
        $fileSize = 0;
        foreach ($headers as $header) {
            if (stripos($header, 'content-length:') === 0) {
                $fileSize = (int) substr($header, 15);
                break;
            }
        }
        
        $progressBar = null;
        if ($showProgress && $fileSize > 0) {
            $progressBar = new ProgressBar($output, $fileSize);
            $progressBar->setFormat(' %current%/%max% bytes [%bar%] %percent:3s%% %elapsed:6s%');
            $progressBar->start();
        }
        
        $downloaded = 0;
        while (!feof($source)) {
            $chunk = fread($source, 8192);
            if ($chunk === false) {
                break;
            }
            fwrite($dest, $chunk);
            $downloaded += strlen($chunk);
            
            if ($progressBar) {
                $progressBar->setProgress($downloaded);
            }
        }
        
        if ($progressBar) {
            $progressBar->finish();
            $output->writeln('');
        }
        
        fclose($source);
        fclose($dest);
        
        if ($downloaded === 0) {
            unlink($tempFile);
            throw new \RuntimeException('Downloaded file is empty');
        }
        
        return $tempFile;
    }
    
    private function verifyDownload(string $file, array $release): bool
    {
        // Basic verification - check if it's a valid PHAR
        try {
            $phar = new \Phar($file);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function replaceFile(string $source, string $dest): bool
    {
        // On Windows, we need to use rename
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            unlink($dest);
            return rename($source, $dest);
        }
        
        // On Unix, we can use rename atomically
        return rename($source, $dest);
    }
    
    private function rollback(string $pharPath, OutputInterface $output): int
    {
        $backupFile = $pharPath . '.backup';
        
        if (!file_exists($backupFile)) {
            $output->writeln('<error>No backup file found. Cannot rollback.</error>');
            return Command::FAILURE;
        }
        
        $output->write('Rolling back to previous version...');
        
        if (!copy($backupFile, $pharPath)) {
            $output->writeln(' <error>failed!</error>');
            $output->writeln('<error>Could not restore backup file.</error>');
            return Command::FAILURE;
        }
        
        $output->writeln(' <info>OK</info>');
        $output->writeln('<info>Successfully rolled back to previous version.</info>');
        
        return Command::SUCCESS;
    }
    
    private function saveUpdateCheckTimestamp(): void
    {
        $file = sys_get_temp_dir() . '/' . self::UPDATE_CHECK_FILE;
        file_put_contents($file, time());
    }
    
    public static function shouldCheckForUpdates(): bool
    {
        $file = sys_get_temp_dir() . '/' . self::UPDATE_CHECK_FILE;
        
        if (!file_exists($file)) {
            return true;
        }
        
        $lastCheck = (int) file_get_contents($file);
        return (time() - $lastCheck) > self::UPDATE_CHECK_INTERVAL;
    }
}
