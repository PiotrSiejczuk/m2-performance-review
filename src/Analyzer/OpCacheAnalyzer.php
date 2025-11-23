<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Trait\DevModeAwareTrait;

class OpCacheAnalyzer implements AnalyzerInterface
{
    use DevModeAwareTrait;

    private RecommendationCollector $collector;
    private static ?array $sysctlCache = null;

    // Dev mode recommended settings (different from production)
    private array $devModeRecommended = [
        'opcache.enable'                        => '1',
        'opcache.enable_cli'                    => '1',    // Enable for CLI in dev
        'opcache.validate_timestamps'           => '1',    // MUST be ON in dev
        'opcache.revalidate_freq'               => '0',    // Check every request in dev
        'opcache.consistency_checks'            => '0',
        'opcache.file_update_protection'        => '0',    // No delay in dev
        'opcache.memory_consumption'            => '512',  // Less memory needed in dev
        'opcache.interned_strings_buffer'       => '32',   // Less in dev
        // Other settings remain similar
    ];

    private array $productionRecommended = [
        'opcache.enable'                        => '1',
        'opcache.enable_cli'                    => '0',
        'opcache.use_cwd'                       => '1',
        'opcache.validate_timestamps'           => '0',
        'opcache.revalidate_freq'               => '0',
        'opcache.validate_permission'           => '0',
        'opcache.validate_root'                 => '0',
        'opcache.enable_file_override'          => '0',
        'opcache.memory_consumption'            => '1024',
        'opcache.interned_strings_buffer'       => '64',
        'opcache.max_wasted_percentage'         => '10',
        'opcache.consistency_checks'            => '0',
        'opcache.save_comments'                 => '1',
        'opcache.load_comments'                 => '1',
        'opcache.fast_shutdown'                 => '1',
        'opcache.optimization_level'            => '0x7FFEBFFF',
        'opcache.blacklist_filename'            => '',
        'opcache.max_file_size'                 => '0',
        'opcache.error_log'                     => '',
        'opcache.log_verbosity_level'           => '1',
        'opcache.preferred_memory_model'        => '',
        'opcache.protect_memory'                => '0',
        'opcache.mmap_base'                     => '',
        'opcache.restrict_api'                  => '',
        'opcache.file_cache'                    => '',
        'opcache.file_cache_only'               => '0',
        'opcache.file_cache_consistency_checks' => '1',
        'opcache.file_cache_fallback'           => '1',
        'opcache.huge_code_pages'               => '0',
        'opcache.lockfile_path'                 => '/tmp',
        'opcache.opt_debug_level'               => '0',
        'opcache.file_update_protection'        => '2',
        'opcache.jit'                           => 'off',
        'opcache.jit_buffer_size'               => '0'
    ];

    public function __construct(RecommendationCollector $collector)
    {
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $this->analyzeOpcache();
        $this->analyzeSysctl();
    }

    private function analyzeOpcache(): void
    {
        $directives = $this->getOpCacheConfig();
        if (!$directives) {
            $this->collector->add(
                'opcache',
                'Enable OPcache',
                Recommendation::PRIORITY_HIGH,
                'OPcache is Disabled or Unavailable. Enabling it can significantly improve PHP Performance.',
                'OPcache caches compiled PHP bytecode in memory, eliminating compilation overhead on every request. ' .
                'Performance improvement: 30-50% faster page loads, 60-80% reduction in CPU usage. ' .
                'Enable with: opcache.enable=1 in php.ini. Essential even for development environments.'
            );
            return;
        }

        // Choose recommendations based on mode
        $recommended = $this->getRecommendedSettings();

        $mismatches = [];
        $actionableCommands = [];

        foreach ($recommended as $key => $recVal) {
            if (!isset($directives[$key])) {
                continue;
            }

            $cur = (string)$directives[$key];

            // Skip deprecated directives based on PHP version
            if (PHP_VERSION_ID >= 70200 && $key === 'opcache.fast_shutdown') {
                continue;
            }
            if (PHP_VERSION_ID >= 70000 && $key === 'opcache.load_comments') {
                continue;
            }

            // Normalize Memory Strings
            if (in_array($key, ['opcache.memory_consumption', 'opcache.interned_strings_buffer'])) {
                $cur = preg_replace('/\D+/', '', $cur);
            }

            if ($cur !== $recVal) {
                $mismatches[$key] = ['current' => $cur, 'recommend' => $recVal];
                $actionableCommands[] = "echo '{$key}={$recVal}' >> /etc/php/*/fpm/conf.d/10-opcache.ini";
            }
        }

        // Report directive mismatches with context-aware message
        if ($mismatches) {
            $mode = $this->isInDeveloperMode() ? 'Development' : 'Production';
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;

            $lines = [];
            foreach ($mismatches as $param => $vals) {
                $lines[] = "{$param}: current={$vals['current']}, recommended={$vals['recommend']}";
            }

            $detail = "OPcache settings not optimal for {$mode} mode:\n" . implode("\n", $lines);

            if (!empty($actionableCommands)) {
                $detail .= "\n\nTo fix, run these commands:\n";
                $detail .= implode("\n", array_slice($actionableCommands, 0, 5));
                if (count($actionableCommands) > 5) {
                    $detail .= "\n... and " . (count($actionableCommands) - 5) . " more";
                }
            }

            $explanation = $this->isInDeveloperMode()
                ? 'Development mode requires validate_timestamps=1 to detect code changes immediately. ' .
                'revalidate_freq=0 ensures every request checks for updates. This impacts performance but enables rapid development. ' .
                'Other optimizations still apply to improve overall dev experience.'
                : 'Production settings optimize for performance: validate_timestamps=0 prevents file checks, ' .
                'saving I/O operations. Code changes require cache clear. memory_consumption=1024 provides ample bytecode storage.';

            $this->collector->add(
                'opcache',
                "Review OPcache Configuration for {$mode}",
                $priority,
                $detail,
                $explanation
            );
        }

        // Rest of the analysis remains the same
        $this->checkMaxAcceleratedFiles($directives);
        $this->checkMemoryUsage();
    }

    private function getRecommendedSettings(): array
    {
        if ($this->isInDeveloperMode()) {
            // Merge production settings with dev overrides
            return array_merge($this->productionRecommended, $this->devModeRecommended);
        }

        return $this->productionRecommended;
    }

    private function checkMemoryUsage(): void
    {
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if ($status) {
                $memoryUsage = $status['memory_usage'] ?? [];
                if (isset($memoryUsage['used_memory']) && isset($memoryUsage['free_memory'])) {
                    $usedPercent = ($memoryUsage['used_memory'] / ($memoryUsage['used_memory'] + $memoryUsage['free_memory'])) * 100;

                    // Different thresholds for dev vs production
                    $threshold = $this->isInDeveloperMode() ? 95 : 90;

                    if ($usedPercent > $threshold) {
                        $this->collector->add(
                            'opcache',
                            'Increase OPcache memory',
                            Recommendation::PRIORITY_HIGH,
                            sprintf('OPcache memory usage is %.1f%%. Increase opcache.memory_consumption.', $usedPercent),
                            'When OPcache memory fills up, it evicts older scripts causing recompilation. This defeats the purpose of caching. ' .
                            'Symptoms: increased CPU usage, slower response times. Solution: Double current memory_consumption value. ' .
                            'Monitor with opcache_get_status() to find optimal size for your codebase.'
                        );
                    }
                }
            }
        }
    }

    // Other methods remain the same...
    private function checkMaxAcceleratedFiles(array $directives): void
    {
        $maxFiles = (int) ($directives['opcache.max_accelerated_files'] ?? 0);

        // Only do expensive file counting if max_accelerated_files is set to a specific value
        if ($maxFiles > 0 && $maxFiles < 100000) {
            $phpCount = $this->getPhpFileCountFast();
            if ($phpCount && $phpCount > $maxFiles) {
                $this->collector->add(
                    'opcache',
                    'Adjust opcache.max_accelerated_files',
                    Recommendation::PRIORITY_HIGH,
                    "Detected approximately {$phpCount} PHP files but opcache.max_accelerated_files is set to {$maxFiles}. " .
                    "Consider increasing it to at least " . $phpCount . " or set to 0 for automatic.\n" .
                    "Command: echo 'opcache.max_accelerated_files=0' >> /etc/php/*/fpm/conf.d/10-opcache.ini",
                    'When max_accelerated_files is too low, OPcache stops caching new files silently. This causes random performance degradation. ' .
                    'Setting to 0 enables automatic sizing based on available memory. Prime numbers near your file count also work well: ' .
                    '65407, 100003, 200003. Magento typically has 50,000-150,000 PHP files including vendor directory.'
                );
            }
        }
    }

    private function getPhpFileCountFast(): ?int
    {
        // Use find command for faster counting
        $output = [];
        exec('find . -name "*.php" -type f | wc -l 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0 && !empty($output[0])) {
            return (int)trim($output[0]);
        }

        // Fallback to limited recursive scan (faster than full scan)
        return $this->countPhpFilesLimited(getcwd() ?: __DIR__, 50000);
    }

    private function countPhpFilesLimited(string $dir, int $maxFiles = 50000): int
    {
        $count = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $count++;
                    if ($count >= $maxFiles) {
                        return $count; // Stop early to avoid long execution
                    }
                }
            }
        } catch (\Exception $e) {
            // Return null on errors
            return 0;
        }
        return $count;
    }

    private function analyzeSysctl(): void
    {
        // Batch load all sysctl values at once
        $sysctlValues = $this->getAllSysctlValues();

        $sysctlRecommendations = [
            'net.core.somaxconn' => '1024',
            'net.core.netdev_max_backlog' => '5000',
            'net.ipv4.tcp_congestion_control' => 'bbr',
            'net.ipv4.tcp_notsent_lowat' => '16384',
            'net.ipv4.tcp_tw_reuse' => '1',
            'net.ipv4.ip_local_port_range' => '1024 65535',
            'net.ipv4.tcp_abort_on_overflow' => '1',
            'net.ipv4.tcp_fin_timeout' => '15',
            'net.ipv4.tcp_keepalive_time' => '300',
            'net.ipv4.tcp_keepalive_probes' => '5',
            'net.ipv4.tcp_keepalive_intvl' => '15',
            'net.ipv4.tcp_no_metrics_save' => '1',
            'net.ipv4.tcp_timestamps' => '1',
            'net.ipv4.tcp_sack' => '1',
            'net.ipv4.tcp_window_scaling' => '1',
        ];

        $misconfigurations = [];
        $commands = [];

        foreach ($sysctlRecommendations as $key => $recVal) {
            $current = $sysctlValues[$key] ?? null;
            if ($current !== null && $current !== $recVal) {
                $misconfigurations[] = "$key: current=$current, recommended=$recVal";
                $commands[] = "echo '$key = $recVal' >> /etc/sysctl.d/99-magento.conf";
            }
        }

        if (!empty($misconfigurations)) {
            $detail = "System kernel parameters need optimization:\n" . implode("\n", $misconfigurations);
            $detail .= "\n\nTo apply recommended settings:\n";
            $detail .= implode("\n", array_slice($commands, 0, 5));
            if (count($commands) > 5) {
                $detail .= "\n... and " . (count($commands) - 5) . " more";
            }
            $detail .= "\nsysctl -p /etc/sysctl.d/99-magento.conf";

            $this->collector->add(
                'system',
                'Optimize kernel parameters',
                Recommendation::PRIORITY_MEDIUM,
                $detail,
                'Key optimizations: tcp_congestion_control=bbr uses Google\'s BBR algorithm for 25% better throughput. ' .
                'somaxconn=1024 allows more pending connections preventing "connection refused" errors under load. ' .
                'tcp_tw_reuse=1 enables faster connection recycling. tcp_fin_timeout=15 reduces TIME_WAIT duration. ' .
                'These settings optimize for high-traffic e-commerce workloads with many concurrent connections.'
            );
        }
    }

    private function getAllSysctlValues(): array
    {
        if (self::$sysctlCache !== null) {
            return self::$sysctlCache;
        }

        self::$sysctlCache = [];

        // Try to get all values at once using sysctl -a (faster than individual calls)
        $output = [];
        exec('sysctl -a 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0) {
            foreach ($output as $line) {
                if (preg_match('/^([^=]+)\s*=\s*(.+)$/', trim($line), $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    self::$sysctlCache[$key] = $value;
                }
            }
        } else {
            // Fallback: read from /proc/sys/ for key parameters only
            $keys = [
                'net.core.somaxconn',
                'net.core.netdev_max_backlog',
                'net.ipv4.tcp_congestion_control',
                'net.ipv4.tcp_tw_reuse'
            ];

            foreach ($keys as $key) {
                $value = $this->getSysctlValueDirect($key);
                if ($value !== null) {
                    self::$sysctlCache[$key] = $value;
                }
            }
        }

        return self::$sysctlCache;
    }

    private function getSysctlValueDirect(string $key): ?string
    {
        $path = '/proc/sys/' . str_replace('.', '/', $key);
        if (file_exists($path) && is_readable($path)) {
            $value = trim(file_get_contents($path));
            return $value !== '' ? $value : null;
        }
        return null;
    }

    private function getOpCacheConfig(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $status = @opcache_get_status(false);
        if (!$status || !isset($status['directives'])) {
            $config = @opcache_get_configuration();
            return $config['directives'] ?? null;
        }

        return $status['directives'] ?? null;
    }

    private function checkPHP83Optimizations(): void
    {
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            return;
        }

        // Check JIT configuration
        $jitEnabled = ini_get('opcache.jit');
        $jitBuffer = ini_get('opcache.jit_buffer_size');
        
        if (!$jitEnabled || $jitEnabled === '0' || $jitEnabled === 'off') {
            $this->collector->add(
                'opcache',
                'PHP 8.3 JIT compilation not enabled',
                Recommendation::PRIORITY_HIGH,
                'JIT provides 50-300% performance improvements for CPU-intensive eCommerce operations.',
                'JIT compilation dramatically improves performance for complex calculations like pricing rules, ' .
                'tax calculations, and catalog filtering. Configure: opcache.jit=1205 (CPU-specific optimization ' .
                'with full register allocation) and opcache.jit_buffer_size=200M for optimal Adobe Commerce performance.'
            );
        } elseif ($jitEnabled !== '1205') {
            $this->collector->add(
                'opcache',
                'PHP 8.3 JIT not optimally configured',
                Recommendation::PRIORITY_MEDIUM,
                'Current JIT setting: ' . $jitEnabled . '. Recommended: 1205 for eCommerce workloads.',
                'JIT configuration 1205 enables CPU-specific optimization with full register allocation, ' .
                'providing optimal performance for Adobe Commerce\'s complex business logic. ' .
                'This setting balances compilation time with runtime performance.'
            );
        }

        // Check JIT buffer size
        $bufferSizeMB = $this->parseSize($jitBuffer) / 1024 / 1024;
        if ($bufferSizeMB < 200) {
            $this->collector->add(
                'opcache',
                'PHP 8.3 JIT buffer size too small',
                Recommendation::PRIORITY_MEDIUM,
                sprintf('Current JIT buffer: %dMB. Recommended: 200MB for large catalogs.', $bufferSizeMB),
                'Adobe Commerce\'s complex business logic requires adequate JIT buffer space. ' .
                'Insufficient buffer causes JIT compilation failures for complex methods, ' .
                'falling back to interpreted execution. Set opcache.jit_buffer_size=200M.'
            );
        }

        // Check enhanced realpath cache for PHP 8.3
        $realpathSize = ini_get('realpath_cache_size');
        $realpathTTL = ini_get('realpath_cache_ttl');
        
        $realpathSizeMB = $this->parseSize($realpathSize) / 1024 / 1024;
        if ($realpathSizeMB < 32) {
            $this->collector->add(
                'opcache',
                'Realpath cache not optimized for PHP 8.3',
                Recommendation::PRIORITY_MEDIUM,
                sprintf('Current realpath_cache_size: %dMB. Recommended: 32MB for large catalogs.', $realpathSizeMB),
                'PHP 8.3\'s enhanced realpath cache significantly improves file system operations. ' .
                'Adobe Commerce with extensive template hierarchies benefits from larger cache. ' .
                'Set realpath_cache_size=32M and realpath_cache_ttl=7200.'
            );
        }

        // Check garbage collection settings
        if (ini_get('zend.enable_gc') !== '1') {
            $this->collector->add(
                'opcache',
                'PHP 8.3 garbage collection disabled',
                Recommendation::PRIORITY_LOW,
                'Garbage collection is disabled. PHP 8.3 has improved GC with 10-20% less overhead.',
                'PHP 8.3\'s enhanced garbage collection reduces memory overhead while maintaining ' .
                'better performance consistency under high load. Enable with zend.enable_gc=1. ' .
                'This is particularly important for long-running processes like imports/exports.'
            );
        }

        // Check memory limit for PHP 8.3 recommendations
        $memoryLimit = $this->parseSize(ini_get('memory_limit'));
        if ($memoryLimit < 2 * 1024 * 1024 * 1024) { // 2GB
            $this->collector->add(
                'opcache',
                'Memory limit below PHP 8.3 recommendations',
                Recommendation::PRIORITY_MEDIUM,
                'Current memory_limit: ' . ini_get('memory_limit') . '. Recommended: 2G for optimal JIT performance.',
                'PHP 8.3 with JIT enabled requires additional memory for compilation and optimization. ' .
                '2GB ensures adequate space for JIT compilation alongside application memory needs. ' .
                'This prevents OOM errors during complex operations like full reindex.'
            );
        }
    }
}
