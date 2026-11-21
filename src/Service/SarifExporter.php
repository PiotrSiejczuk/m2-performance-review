<?php

declare(strict_types=1);

namespace M2Performance\Service;

/**
 * Exports analysis results as SARIF 2.1.0 for GitHub Code Scanning.
 *
 * Upload via github/codeql-action/upload-sarif in a workflow to surface
 * findings directly as PR annotations on affected files.
 */
class SarifExporter
{
    private const SARIF_VERSION = '2.1.0';
    private const SARIF_SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';

    private string $magentoRoot;

    public function __construct(string $magentoRoot)
    {
        $this->magentoRoot = rtrim($magentoRoot, '/');
    }

    /**
     * @param array<int, array<string, mixed>> $recommendations Serialized recommendations (AnalysisRunner::toArray()).
     */
    public function export(array $recommendations, string $toolVersion): string
    {
        $rules = [];
        $results = [];

        foreach ($recommendations as $rec) {
            $ruleId = $this->buildRuleId($rec);

            if (isset($rules[$ruleId]) === false) {
                $rules[$ruleId] = [
                    'id' => $ruleId,
                    'name' => $this->toPascalCase((string)($rec['title'] ?? 'Unknown')),
                    'shortDescription' => ['text' => (string)($rec['title'] ?? 'Unknown issue')],
                    'fullDescription' => ['text' => (string)($rec['explanation'] ?? $rec['details'] ?? '')],
                    'helpUri' => 'https://github.com/PiotrSiejczuk/m2-performance-review',
                    'properties' => [
                        'tags' => ['performance', 'magento2', (string)($rec['area'] ?? 'general')],
                        'precision' => 'high',
                    ],
                    'defaultConfiguration' => [
                        'level' => $this->mapLevel((string)($rec['priority_label'] ?? 'Low')),
                    ],
                ];
            }

            $locations = $this->buildLocations($rec);

            $results[] = [
                'ruleId' => $ruleId,
                'level' => $this->mapLevel((string)($rec['priority_label'] ?? 'Low')),
                'message' => [
                    'text' => trim(($rec['title'] ?? '') . ': ' . ($rec['details'] ?? '')),
                ],
                'locations' => $locations,
                'properties' => [
                    'area' => (string)($rec['area'] ?? ''),
                    'priority' => (string)($rec['priority_label'] ?? ''),
                ],
            ];
        }

        $sarif = [
            '$schema' => self::SARIF_SCHEMA,
            'version' => self::SARIF_VERSION,
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'M2PerformanceReview',
                            'informationUri' => 'https://github.com/PiotrSiejczuk/m2-performance-review',
                            'version' => $toolVersion,
                            'rules' => array_values($rules),
                        ],
                    ],
                    'results' => $results,
                ],
            ],
        ];

        return (string)json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $rec
     * @return array<int, array<string, mixed>>
     */
    private function buildLocations(array $rec): array
    {
        $files = [];
        if (isset($rec['affected_files']) === true && is_array($rec['affected_files']) === true) {
            $files = $rec['affected_files'];
        }

        if (count($files) === 0) {
            // SARIF requires a location; anchor config-level findings on env.php
            $files = ['app/etc/env.php'];
        }

        $locations = [];
        foreach (array_slice($files, 0, 10) as $file) {
            $relative = $this->toRelativePath((string)$file);
            $locations[] = [
                'physicalLocation' => [
                    'artifactLocation' => [
                        'uri' => $relative,
                        'uriBaseId' => '%SRCROOT%',
                    ],
                    'region' => ['startLine' => 1],
                ],
            ];
        }

        return $locations;
    }

    private function toRelativePath(string $file): string
    {
        if (str_starts_with($file, $this->magentoRoot) === true) {
            return ltrim(substr($file, strlen($this->magentoRoot)), '/');
        }

        return ltrim($file, '/');
    }

    private function buildRuleId(array $rec): string
    {
        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', (string)($rec['title'] ?? 'unknown')));

        return 'm2perf.' . ($rec['area'] ?? 'general') . '.' . trim($slug, '-');
    }

    private function toPascalCase(string $value): string
    {
        return str_replace(' ', '', ucwords((string)preg_replace('/[^a-zA-Z0-9]+/', ' ', $value)));
    }

    private function mapLevel(string $priorityLabel): string
    {
        return match (strtolower($priorityLabel)) {
            'high' => 'error',
            'medium' => 'warning',
            default => 'note',
        };
    }
}
