<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class ServerUptimeAnalyzer implements AnalyzerInterface
{
    private RecommendationCollector $collector;

    public function __construct(RecommendationCollector $collector)
    {
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $this->checkServerUptime();
        $this->checkLoadAverage();
        $this->checkMemoryUsage();
    }

    private function checkServerUptime(): void
    {
        // Get system uptime
        $uptime = $this->getSystemUptime();

        if ($uptime !== null) {
            $days  = intdiv((int) $uptime, 86400);
            $hours = intdiv((int) $uptime % 86400, 3600);

            if ($uptime < 3600) { // Less than 1 hour
                $this->collector->add(
                    'system',
                    'Server recently restarted',
                    Recommendation::PRIORITY_HIGH,
                    sprintf('Server uptime is only %.1f minutes. Check for unexpected restarts or issues.', $uptime / 60)
                );
            } elseif ($uptime > 31536000) { // More than 1 year
                $this->collector->add(
                    'system',
                    'Consider planned system maintenance',
                    Recommendation::PRIORITY_LOW,
                    sprintf('Server has been running for %d days. Consider scheduling maintenance for security updates.', $days)
                );
            }

            // Add informational note
            $uptimeStr = sprintf('%d days, %d hours', $days, $hours);
            $this->collector->add(
                'system',
                'Server uptime information',
                Recommendation::PRIORITY_LOW,
                "Current server uptime: $uptimeStr"
            );
        }
    }

    private function checkLoadAverage(): void
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpuCount = $this->getCpuCount();

            if ($cpuCount && $load[0] > $cpuCount * 0.8) {
                $this->collector->add(
                    'system',
                    'High server load detected',
                    Recommendation::PRIORITY_HIGH,
                    sprintf('1-minute load average (%.2f) is high for %d CPU cores. Consider optimizing or scaling.', $load[0], $cpuCount)
                );
            }
        }
    }

    private function checkMemoryUsage(): void
    {
        $meminfo = $this->getMemoryInfo();

        if ($meminfo) {
            $usedPercent = (($meminfo['total'] - $meminfo['available']) / $meminfo['total']) * 100;

            if ($usedPercent > 90) {
                $this->collector->add(
                    'system',
                    'High memory usage detected',
                    Recommendation::PRIORITY_HIGH,
                    sprintf('Memory usage is %.1f%%. Consider adding more RAM or optimizing memory usage.', $usedPercent)
                );
            } elseif ($usedPercent > 80) {
                $this->collector->add(
                    'system',
                    'Moderate memory usage',
                    Recommendation::PRIORITY_MEDIUM,
                    sprintf('Memory usage is %.1f%%. Monitor closely and consider optimization.', $usedPercent)
                );
            }
        }
    }

    private function getSystemUptime(): ?float
    {
        if (file_exists('/proc/uptime')) {
            $uptimeData = file_get_contents('/proc/uptime');
            if ($uptimeData) {
                $parts = explode(' ', trim($uptimeData));
                return (float)$parts[0];
            }
        }

        // Fallback for non-Linux systems
        $output = [];
        exec('uptime 2>/dev/null', $output);
        if (!empty($output[0])) {
            // Parse uptime output - this is basic parsing
            if (preg_match('/up\s+(\d+)\s+days?/i', $output[0], $matches)) {
                return (int)$matches[1] * 86400;
            }
        }

        return null;
    }

    private function getCpuCount(): ?int
    {
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            return substr_count($cpuinfo, 'processor');
        }

        // Fallback
        $output = [];
        exec('nproc 2>/dev/null', $output);
        return !empty($output[0]) ? (int)$output[0] : null;
    }

    private function getMemoryInfo(): ?array
    {
        if (!file_exists('/proc/meminfo')) {
            return null;
        }

        $meminfo = file_get_contents('/proc/meminfo');
        $data = [];

        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s*(\d+)\s*kB/', $line, $matches)) {
                $data[strtolower($matches[1])] = (int)$matches[2] * 1024; // Convert to bytes
            }
        }

        return [
            'total' => $data['memtotal'] ?? 0,
            'available' => $data['memavailable'] ?? ($data['memfree'] ?? 0),
            'free' => $data['memfree'] ?? 0,
            'cached' => $data['cached'] ?? 0,
            'buffers' => $data['buffers'] ?? 0
        ];
    }
}
