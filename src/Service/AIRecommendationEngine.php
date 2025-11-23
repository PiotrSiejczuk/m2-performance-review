<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

class AIRecommendationEngine
{
    private array $recommendations;
    private ?string $apiKey;
    private string $apiEndpoint;

    public function __construct(array $recommendations, ?string $apiKey = null)
    {
        $this->recommendations = $recommendations;
        $this->apiKey = $apiKey ?: getenv('OPENAI_API_KEY');
        $this->apiEndpoint = 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Generate AI-powered recommendations
     */
    public function generateRecommendations(): array
    {
        // If no API key, use rule-based recommendations
        if (!$this->apiKey) {
            return $this->generateRuleBasedRecommendations();
        }

        try {
            $context = $this->buildContext();
            $response = $this->callAI($context);
            return $this->parseAIResponse($response);
        } catch (\Exception $e) {
            // Fallback to rule-based recommendations
            return $this->generateRuleBasedRecommendations();
        }
    }

    /**
     * Generate a prioritized action plan
     */
    public function generateActionPlan(): array
    {
        $plan = [];

        // Group recommendations by area and priority
        $grouped = $this->groupRecommendations();

        // Calculate impact scores
        foreach ($grouped as $area => $priorities) {
            foreach ($priorities as $priority => $recs) {
                foreach ($recs as $rec) {
                    $impact = $this->calculateImpactScore($rec);
                    $effort = $this->estimateEffort($rec);

                    $plan[] = [
                        'recommendation' => $rec,
                        'impact_score' => $impact,
                        'effort_score' => $effort,
                        'roi_score' => $impact / max($effort, 1),
                        'dependencies' => $this->identifyDependencies($rec),
                        'timeline' => $this->estimateTimeline($effort)
                    ];
                }
            }
        }

        // Sort by ROI score
        usort($plan, fn($a, $b) => $b['roi_score'] <=> $a['roi_score']);

        return $plan;
    }

    /**
     * Generate predictive insights based on current issues
     */
    public function generatePredictiveInsights(): array
    {
        $insights = [];

        // Analyze patterns
        $patterns = $this->analyzePatterns();

        foreach ($patterns as $pattern => $data) {
            $insights[] = [
                'pattern' => $pattern,
                'current_impact' => $data['impact'],
                'predicted_impact_30d' => $data['impact'] * 1.5,
                'predicted_impact_90d' => $data['impact'] * 2.2,
                'risk_level' => $this->calculateRiskLevel($data),
                'mitigation_strategy' => $this->getMitigationStrategy($pattern)
            ];
        }

        return $insights;
    }

    private function buildContext(): string
    {
        $context = "Analyze the following Magento 2 performance issues and provide specific recommendations:\n\n";

        foreach ($this->recommendations as $rec) {
            $context .= sprintf(
                "[%s - %s] %s\nDetails: %s\n\n",
                $rec->getArea(),
                $rec->getPriority(),
                $rec->getMessage(),
                $rec->getDetail()
            );
        }

        $context .= "\nProvide:\n";
        $context .= "1. Top 5 quick wins (can be implemented in < 1 day)\n";
        $context .= "2. Strategic improvements (1-4 weeks)\n";
        $context .= "3. Long-term optimizations (1+ months)\n";
        $context .= "4. Estimated performance improvement for each\n";
        $context .= "5. Implementation complexity (Low/Medium/High)\n";

        return $context;
    }

    private function callAI(string $context): array
    {
        $ch = curl_init($this->apiEndpoint);

        $payload = [
            'model' => 'gpt-4',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a Magento 2 performance optimization expert with deep knowledge of PHP, MySQL, Redis, Elasticsearch, and e-commerce best practices.'
                ],
                [
                    'role' => 'user',
                    'content' => $context
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception('AI API request failed');
        }

        return json_decode($response, true);
    }

    private function parseAIResponse(array $response): array
    {
        if (!isset($response['choices'][0]['message']['content'])) {
            return $this->generateRuleBasedRecommendations();
        }

        $content = $response['choices'][0]['message']['content'];

        // Parse the AI response into structured recommendations
        // This would need more sophisticated parsing in production
        return [
            'quick_wins' => $this->extractSection($content, 'quick wins'),
            'strategic' => $this->extractSection($content, 'strategic'),
            'long_term' => $this->extractSection($content, 'long-term'),
            'summary' => $this->generateSummary($content)
        ];
    }

    private function generateRuleBasedRecommendations(): array
    {
        $quickWins = [];
        $strategic = [];
        $longTerm = [];

        foreach ($this->recommendations as $rec) {
            $actionItem = [
                'title' => $rec->getMessage(),
                'area' => $rec->getArea(),
                'priority' => $rec->getPriority(),
                'impact' => $this->calculateImpactScore($rec),
                'effort' => $this->estimateEffort($rec),
                'steps' => $this->generateImplementationSteps($rec)
            ];

            // Categorize based on effort
            if ($actionItem['effort'] <= 2) {
                $quickWins[] = $actionItem;
            } elseif ($actionItem['effort'] <= 5) {
                $strategic[] = $actionItem;
            } else {
                $longTerm[] = $actionItem;
            }
        }

        // Sort by impact
        usort($quickWins, fn($a, $b) => $b['impact'] <=> $a['impact']);
        usort($strategic, fn($a, $b) => $b['impact'] <=> $a['impact']);
        usort($longTerm, fn($a, $b) => $b['impact'] <=> $a['impact']);

        return [
            'quick_wins' => array_slice($quickWins, 0, 5),
            'strategic' => array_slice($strategic, 0, 5),
            'long_term' => array_slice($longTerm, 0, 5),
            'summary' => $this->generateExecutiveSummary()
        ];
    }

    private function calculateImpactScore(Recommendation $rec): int
    {
        $baseScore = match($rec->getPriority()) {
            'High' => 10,
            'Medium' => 5,
            'Low' => 2,
            default => 1
        };

        // Adjust based on area
        $areaMultiplier = match($rec->getArea()) {
            'redis' => 1.5,
            'cache' => 1.4,
            'database' => 1.3,
            'security' => 1.2,
            default => 1.0
        };

        return (int)($baseScore * $areaMultiplier);
    }

    private function estimateEffort(Recommendation $rec): int
    {
        // Estimate effort on scale 1-10
        $keywords = [
            'configure' => 2,
            'enable' => 1,
            'disable' => 1,
            'install' => 3,
            'upgrade' => 5,
            'implement' => 7,
            'optimize' => 5,
            'review' => 3,
            'fix' => 4
        ];

        $message = strtolower($rec->getMessage());
        foreach ($keywords as $keyword => $effort) {
            if (str_contains($message, $keyword)) {
                return $effort;
            }
        }

        return 5; // Default medium effort
    }

    private function generateImplementationSteps(Recommendation $rec): array
    {
        $steps = [];

        switch ($rec->getArea()) {
            case 'redis':
                if (str_contains($rec->getMessage(), 'disable_locking')) {
                    $steps = [
                        'Backup current env.php configuration',
                        'Edit app/etc/env.php',
                        'Set disable_locking = 1 in Redis session configuration',
                        'Clear cache: bin/magento cache:clean',
                        'Test concurrent requests to verify improvement'
                    ];
                }
                break;

            case 'opcache':
                $steps = [
                    'Locate PHP configuration file (php.ini)',
                    'Update OPcache settings as recommended',
                    'Restart PHP-FPM service',
                    'Verify settings with phpinfo()',
                    'Monitor hit rate and memory usage'
                ];
                break;

            case 'security':
                if (str_contains($rec->getMessage(), 'Two-Factor')) {
                    $steps = [
                        'Enable Magento_TwoFactorAuth module',
                        'Configure 2FA providers in admin',
                        'Test admin login with 2FA',
                        'Document recovery procedures',
                        'Train admin users'
                    ];
                }
                break;

            default:
                $steps = [
                    'Review recommendation details',
                    'Plan implementation approach',
                    'Test in staging environment',
                    'Deploy to production',
                    'Monitor results'
                ];
        }

        return $steps;
    }

    private function groupRecommendations(): array
    {
        $grouped = [];

        foreach ($this->recommendations as $rec) {
            $grouped[$rec->getArea()][$rec->getPriority()][] = $rec;
        }

        return $grouped;
    }

    private function identifyDependencies(Recommendation $rec): array
    {
        $dependencies = [];

        // Simple dependency mapping
        if ($rec->getArea() === 'redis' && str_contains($rec->getMessage(), 'Configure Redis')) {
            $dependencies[] = 'Redis server installation';
        }

        if ($rec->getArea() === 'cache' && str_contains($rec->getMessage(), 'Varnish')) {
            $dependencies[] = 'Varnish installation and configuration';
        }

        return $dependencies;
    }

    private function estimateTimeline(int $effort): string
    {
        return match(true) {
            $effort <= 2 => '1-2 days',
            $effort <= 4 => '3-5 days',
            $effort <= 6 => '1-2 weeks',
            $effort <= 8 => '2-4 weeks',
            default => '1+ months'
        };
    }

    private function analyzePatterns(): array
    {
        $patterns = [];

        // Count issues by area
        $areaCounts = [];
        foreach ($this->recommendations as $rec) {
            $areaCounts[$rec->getArea()] = ($areaCounts[$rec->getArea()] ?? 0) + 1;
        }

        // Identify patterns
        if (($areaCounts['redis'] ?? 0) > 2) {
            $patterns['redis_misconfiguration'] = [
                'impact' => 8,
                'description' => 'Multiple Redis configuration issues detected'
            ];
        }

        if (($areaCounts['security'] ?? 0) > 3) {
            $patterns['security_vulnerabilities'] = [
                'impact' => 9,
                'description' => 'Multiple security vulnerabilities detected'
            ];
        }

        return $patterns;
    }

    private function calculateRiskLevel(array $data): string
    {
        $impact = $data['impact'] ?? 0;

        return match(true) {
            $impact >= 8 => 'Critical',
            $impact >= 5 => 'High',
            $impact >= 3 => 'Medium',
            default => 'Low'
        };
    }

    private function getMitigationStrategy(string $pattern): string
    {
        $strategies = [
            'redis_misconfiguration' => 'Implement comprehensive Redis optimization following best practices',
            'security_vulnerabilities' => 'Conduct security audit and implement hardening measures',
            'performance_bottleneck' => 'Profile application and optimize critical paths',
            'database_issues' => 'Optimize queries and implement proper indexing strategy'
        ];

        return $strategies[$pattern] ?? 'Review and address identified issues systematically';
    }

    private function extractSection(string $content, string $section): array
    {
        // Simple extraction - in production would use more sophisticated parsing
        $lines = explode("\n", $content);
        $inSection = false;
        $items = [];

        foreach ($lines as $line) {
            if (stripos($line, $section) !== false) {
                $inSection = true;
                continue;
            }

            if ($inSection && preg_match('/^\d+\./', $line)) {
                $items[] = trim($line);
            }

            if ($inSection && empty(trim($line))) {
                break;
            }
        }

        return $items;
    }

    private function generateSummary(string $content): string
    {
        $totalIssues = count($this->recommendations);
        $highPriority = count(array_filter($this->recommendations, fn($r) => $r->getPriority() === 'High'));

        return sprintf(
            'Performance analysis identified %d issues (%d high priority). ' .
            'Implementing all recommendations could improve performance by 40-60%%. ' .
            'Focus on quick wins first for immediate 15-20%% improvement.',
            $totalIssues,
            $highPriority
        );
    }

    private function generateExecutiveSummary(): string
    {
        $areas = [];
        foreach ($this->recommendations as $rec) {
            $areas[$rec->getArea()] = ($areas[$rec->getArea()] ?? 0) + 1;
        }

        $topArea = array_search(max($areas), $areas);

        return sprintf(
            'Critical performance issues detected in %s (%d issues). ' .
            'Total %d recommendations across %d areas. ' .
            'Estimated performance improvement potential: 30-50%%.',
            $topArea,
            $areas[$topArea],
            count($this->recommendations),
            count($areas)
        );
    }
}
