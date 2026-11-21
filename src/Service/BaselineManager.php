<?php

declare(strict_types=1);

namespace M2Performance\Service;

/**
 * Manages performance baselines: named snapshots of an analysis run
 * (score, grade, issue counts, serialized recommendations) stored under
 * <magento-root>/var/m2-performance/baselines/ (or ~/.m2-performance when
 * the Magento var directory is not writable, e.g. read-only mounts on
 * Adobe Commerce Cloud).
 */
class BaselineManager
{
    private string $storageDir;

    public function __construct(string $magentoRoot)
    {
        $primary = rtrim($magentoRoot, '/') . '/var/m2-performance/baselines';
        $fallback = ($this->resolveHomeDir()) . '/.m2-performance/baselines';

        $this->storageDir = $this->ensureWritableDir($primary) === true ? $primary : $fallback;
        $this->ensureWritableDir($this->storageDir);
    }

    private function resolveHomeDir(): string
    {
        $home = getenv('HOME');
        if (is_string($home) === true && $home !== '') {
            return $home;
        }

        return sys_get_temp_dir();
    }

    private function ensureWritableDir(string $dir): bool
    {
        if (is_dir($dir) === false) {
            // Suppress warning: parent may be read-only (Cloud deploy phase)
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) === true && is_writable($dir) === true;
    }

    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * @param array<int, array<string, mixed>> $recommendations Serialized recommendations.
     * @param array{score: float, grade: string, penalty: float, counts: array<string,int>} $score
     */
    public function save(string $name, array $recommendations, array $score, array $context = []): string
    {
        $name = $this->sanitizeName($name);
        $payload = [
            'name' => $name,
            'created_at' => date('c'),
            'tool_version' => \M2Performance\ToolVersion::VERSION,
            'score' => $score,
            'context' => $context,
            'recommendations' => $recommendations,
        ];

        $path = $this->pathFor($name);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    public function hasBaseline(string $name): bool
    {
        return is_readable($this->pathFor($this->sanitizeName($name))) === true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $name): ?array
    {
        $path = $this->pathFor($this->sanitizeName($name));
        if (is_readable($path) === false) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded) === false) {
            return null;
        }

        // Normalize: json_encode(89.0) emits 89, which decodes as int
        if (isset($decoded['score']['score']) === true) {
            $decoded['score']['score'] = (float)$decoded['score']['score'];
        }
        if (isset($decoded['score']['penalty']) === true) {
            $decoded['score']['penalty'] = (float)$decoded['score']['penalty'];
        }

        return $decoded;
    }

    /**
     * @return array<int, array{name: string, created_at: string, score: float, grade: string}>
     */
    public function listBaselines(): array
    {
        $result = [];
        $files = glob($this->storageDir . '/*.json');
        if (is_array($files) === false) {
            return [];
        }

        foreach ($files as $file) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded) === true) {
                $result[] = [
                    'name' => (string)($decoded['name'] ?? basename($file, '.json')),
                    'created_at' => (string)($decoded['created_at'] ?? ''),
                    'score' => (float)($decoded['score']['score'] ?? 0),
                    'grade' => (string)($decoded['score']['grade'] ?? '?'),
                ];
            }
        }

        usort($result, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $result;
    }

    /**
     * Compare current results against a stored baseline.
     *
     * Issues are keyed by area + title, which is stable across runs.
     *
     * @param array<int, array<string, mixed>> $currentRecommendations
     * @param array{score: float, grade: string} $currentScore
     * @return array<string, mixed>
     */
    public function compare(
        string $baselineName,
        array $currentRecommendations,
        array $currentScore,
        ?string $currentProfile = null,
        ?string $currentMode = null
    ): array {
        $baseline = $this->load($baselineName);
        if ($baseline === null) {
            return ['error' => sprintf('Baseline "%s" not found in %s', $baselineName, $this->storageDir)];
        }

        $baselineProfile = $baseline['context']['profile'] ?? null;
        $isProfileMismatch = $baselineProfile !== null
            && $currentProfile !== null
            && $baselineProfile !== $currentProfile;

        $baselineKeys = $this->keyRecommendations($baseline['recommendations'] ?? []);
        $currentKeys = $this->keyRecommendations($currentRecommendations);

        $resolved = array_values(array_diff_key($baselineKeys, $currentKeys));
        $introduced = array_values(array_diff_key($currentKeys, $baselineKeys));

        $baselineScore = (float)($baseline['score']['score'] ?? 0);
        $scoreDelta = round($currentScore['score'] - $baselineScore, 1);

        $isRegression = $scoreDelta < 0 || count($introduced) > 0;

        $result = [
            'baseline' => [
                'name' => $baseline['name'] ?? $baselineName,
                'created_at' => $baseline['created_at'] ?? '',
                'score' => $baselineScore,
                'grade' => $baseline['score']['grade'] ?? '?',
            ],
            'current' => [
                'score' => $currentScore['score'],
                'grade' => $currentScore['grade'],
            ],
            'score_delta' => $scoreDelta,
            'is_regression' => $isRegression,
            'resolved_issues' => $resolved,
            'introduced_issues' => $introduced,
            'summary' => sprintf(
                'Score %s%.1f (%.1f -> %.1f) | %d resolved, %d introduced',
                $scoreDelta >= 0 ? '+' : '',
                $scoreDelta,
                $baselineScore,
                $currentScore['score'],
                count($resolved),
                count($introduced)
            ),
        ];

        if ($isProfileMismatch === true) {
            $result['profile_mismatch'] = true;
            $result['warning'] = sprintf(
                'Profile mismatch: baseline was captured with profile "%s" but the current run used "%s". '
                . 'Scope differences will appear as introduced/resolved issues; re-run with --profile=%s '
                . 'for a like-for-like comparison.',
                (string)$baselineProfile,
                (string)$currentProfile,
                (string)$baselineProfile
            );
        }

        // Developer-mode-aware runs suppress production-only findings, so a
        // mode change alone shows up as resolved/introduced issues
        $baselineMode = $baseline['context']['analysis_mode'] ?? null;
        if ($baselineMode !== null && $currentMode !== null && $baselineMode !== $currentMode) {
            $result['mode_mismatch'] = true;
            $modeWarning = sprintf(
                'Analysis mode mismatch: baseline is "%s" but the current run is "%s". '
                . 'Mode-dependent checks will appear as introduced/resolved issues.',
                (string)$baselineMode,
                $currentMode
            );
            $result['warning'] = isset($result['warning']) === true
                ? $result['warning'] . ' ' . $modeWarning
                : $modeWarning;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $recommendations
     * @return array<string, array<string, mixed>>
     */
    private function keyRecommendations(array $recommendations): array
    {
        $keyed = [];
        foreach ($recommendations as $rec) {
            if (is_array($rec) === true) {
                $key = ($rec['area'] ?? '') . '::' . ($rec['title'] ?? '');
                $keyed[$key] = [
                    'area' => $rec['area'] ?? '',
                    'title' => $rec['title'] ?? '',
                    'priority_label' => $rec['priority_label'] ?? '',
                ];
            }
        }

        return $keyed;
    }

    private function sanitizeName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '-', $name);
        // Collapse dot sequences and strip leading dots: no traversal-shaped
        // names even though slashes are already excluded above
        $sanitized = preg_replace('/\.{2,}/', '.', (string)$sanitized);
        $sanitized = ltrim((string)$sanitized, '.');

        return is_string($sanitized) === true && $sanitized !== '' ? $sanitized : 'baseline';
    }

    private function pathFor(string $name): string
    {
        return $this->storageDir . '/' . $name . '.json';
    }
}
