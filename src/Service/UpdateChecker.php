<?php

namespace M2Performance\Service;

class UpdateChecker
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/PiotrSiejczuk/m2-performance-review/releases/latest';
    private const NOTIFICATION_FILE = '/.m2-performance-update-available';
    private const CHECK_FILE = '/.m2-performance-update-check';
    private const CHECK_INTERVAL = 86400; // 24 hours
    
    public static function checkInBackground(): void
    {
        // Only works on Unix-like systems with pcntl
        if (!function_exists('pcntl_fork')) {
            return;
        }
        
        // Only check if running from PHAR
        if (!\Phar::running()) {
            return;
        }
        
        // Check if we should check (once per day)
        if (!self::shouldCheck()) {
            return;
        }
        
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            // Fork failed
            return;
        } elseif ($pid === 0) {
            // Child process
            self::performCheck();
            exit(0);
        }
        
        // Parent process continues immediately
    }
    
    private static function shouldCheck(): bool
    {
        $checkFile = sys_get_temp_dir() . self::CHECK_FILE;
        
        if (!file_exists($checkFile)) {
            return true;
        }
        
        $lastCheck = (int) file_get_contents($checkFile);
        return (time() - $lastCheck) > self::CHECK_INTERVAL;
    }
    
    private static function performCheck(): void
    {
        try {
            // Get current version
            $currentVersion = 'unknown';
            $pharPath = \Phar::running(false);
            if ($pharPath) {
                try {
                    $phar = new \Phar($pharPath);
                    $metadata = $phar->getMetadata();
                    $currentVersion = $metadata['version'] ?? 'unknown';
                } catch (\Exception $e) {
                    // Ignore
                }
            }
            
            // Get latest version from GitHub
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: M2-Performance-Tool',
                        'Accept: application/vnd.github.v3+json'
                    ],
                    'timeout' => 10
                ]
            ]);
            
            $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
            
            if ($response === false) {
                return;
            }
            
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['tag_name'])) {
                return;
            }
            
            $latestVersion = ltrim($data['tag_name'], 'v');
            
            // Save check timestamp
            file_put_contents(sys_get_temp_dir() . self::CHECK_FILE, time());
            
            // Check if update is available
            if (version_compare($currentVersion, $latestVersion, '<')) {
                // Save notification
                $notificationData = [
                    'version' => $latestVersion,
                    'current' => $currentVersion,
                    'checked' => time()
                ];
                file_put_contents(
                    sys_get_temp_dir() . self::NOTIFICATION_FILE,
                    json_encode($notificationData)
                );
            } else {
                // Remove notification if no update available
                $notificationFile = sys_get_temp_dir() . self::NOTIFICATION_FILE;
                if (file_exists($notificationFile)) {
                    unlink($notificationFile);
                }
            }
            
        } catch (\Exception $e) {
            // Silently ignore errors in background check
        }
    }
    
    public static function getUpdateInfo(): ?array
    {
        $notificationFile = sys_get_temp_dir() . self::NOTIFICATION_FILE;
        
        if (!file_exists($notificationFile)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($notificationFile), true);
        
        if (!$data || !isset($data['version'])) {
            return null;
        }
        
        // Only return if checked within last 24 hours
        if (time() - $data['checked'] > 86400) {
            return null;
        }
        
        return $data;
    }
}
