<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class APIRateLimitingAnalyzer implements AnalyzerInterface
{
    private string $magentoRoot;
    private RecommendationCollector $collector;

    public function __construct(string $magentoRoot, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $this->analyzeNginxRateLimiting();
        $this->analyzeVarnishRateLimiting();
        $this->analyzeMagentoApiSecurity();
        $this->analyzeCloudflareProtection();
    }

    private function analyzeNginxRateLimiting(): void
    {
        $nginxConfigs = [
            '/etc/nginx/sites-available/magento',
            '/etc/nginx/conf.d/magento.conf',
            '/etc/nginx/nginx.conf'
        ];

        $hasRateLimiting = false;
        $rateLimitConfig = [];

        foreach ($nginxConfigs as $configPath) {
            if (file_exists($configPath) && is_readable($configPath)) {
                $content = file_get_contents($configPath);

                // Check for rate limiting zones
                if (preg_match_all('/limit_req_zone.*?zone=(\w+):(\d+m)\s+rate=(\d+r\/[sm])/i', $content, $matches)) {
                    $hasRateLimiting = true;
                    foreach ($matches[0] as $i => $match) {
                        $rateLimitConfig[] = [
                            'zone' => $matches[1][$i],
                            'size' => $matches[2][$i],
                            'rate' => $matches[3][$i]
                        ];
                    }
                }

                // Check if limit_req is actually used
                if ($hasRateLimiting && !preg_match('/limit_req\s+zone=/i', $content)) {
                    $hasRateLimiting = false;
                }
            }
        }

        if (!$hasRateLimiting) {
            $this->collector->add(
                'api-security',
                'Configure Nginx rate limiting',
                Recommendation::PRIORITY_HIGH,
                "No Nginx rate limiting configured. This is critical for API protection:\n" .
                "limit_req_zone \$binary_remote_addr zone=api:10m rate=10r/s;\n" .
                "limit_req zone=api burst=20 nodelay;"
            );
        } else {
            // Check if rate limits are reasonable
            foreach ($rateLimitConfig as $config) {
                $rate = intval($config['rate']);
                if ($rate > 100) {
                    $this->collector->add(
                        'api-security',
                        'Review Nginx rate limits',
                        Recommendation::PRIORITY_MEDIUM,
                        "Rate limit for zone '{$config['zone']}' seems high: {$config['rate']}. Consider lowering for better protection."
                    );
                }
            }
        }
    }

    private function analyzeVarnishRateLimiting(): void
    {
        $vclPath = '/etc/varnish/default.vcl';
        if (!file_exists($vclPath) || !is_readable($vclPath)) {
            return;
        }

        $vcl = file_get_contents($vclPath);

        // Check for vsthrottle module
        if (!preg_match('/import\s+vsthrottle/i', $vcl)) {
            $this->collector->add(
                'api-security',
                'Add Varnish rate limiting',
                Recommendation::PRIORITY_MEDIUM,
                "Varnish rate limiting not configured. Add vsthrottle module:\n" .
                "import vsthrottle;\n" .
                "if (vsthrottle.is_denied(req.http.X-Real-IP, 15, 10s)) {\n" .
                "    return (synth(429, \"Too Many Requests\"));\n" .
                "}"
            );
        }
    }

    private function analyzeMagentoApiSecurity(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (!file_exists($envPath)) {
            return;
        }

        // Check OAuth consumers
        try {
            $this->checkOAuthSecurity();
        } catch (\Exception $e) {
            // Skip if can't check database
        }

        // Check API user permissions
        $this->checkApiUserPermissions();

        // Check if REST/GraphQL endpoints are properly secured
        $this->checkApiEndpointSecurity();
    }

    private function checkOAuthSecurity(): void
    {
        // This would connect to DB and check oauth_consumer table
        // Simplified for now
        $this->collector->add(
            'api-security',
            'Review OAuth consumer tokens',
            Recommendation::PRIORITY_MEDIUM,
            'Regularly review and rotate OAuth consumer tokens. Remove unused integrations.'
        );
    }

    private function checkApiUserPermissions(): void
    {
        $this->collector->add(
            'api-security',
            'Implement API user restrictions',
            Recommendation::PRIORITY_HIGH,
            "Configure API user restrictions:\n" .
            "- Limit permissions to minimum required\n" .
            "- Use IP whitelisting for admin APIs\n" .
            "- Implement request signing for webhooks"
        );
    }

    private function checkApiEndpointSecurity(): void
    {
        // Check if sensitive endpoints are exposed
        $webApiXml = $this->magentoRoot . '/app/code/*/*/etc/webapi.xml';
        $exposedEndpoints = [];

        foreach (glob($webApiXml) as $xmlFile) {
            if (file_exists($xmlFile)) {
                $xml = simplexml_load_file($xmlFile);
                foreach ($xml->xpath('//route[@method="GET" or @method="POST"]') as $route) {
                    $url = (string)$route['url'];
                    if (preg_match('/(customer|order|admin)/i', $url)) {
                        $exposedEndpoints[] = $url;
                    }
                }
            }
        }

        if (count($exposedEndpoints) > 10) {
            $this->collector->add(
                'api-security',
                'Review exposed API endpoints',
                Recommendation::PRIORITY_MEDIUM,
                sprintf('Found %d potentially sensitive API endpoints. Review and restrict access where appropriate.', count($exposedEndpoints))
            );
        }
    }

    private function analyzeCloudflareProtection(): void
    {
        // Check for Cloudflare headers
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $hasCloudflare = isset($headers['CF-Ray']) || isset($headers['CF-Connecting-IP']);

        if (!$hasCloudflare) {
            // Check if Cloudflare is mentioned in composer.json
            $composerPath = $this->magentoRoot . '/composer.json';
            if (file_exists($composerPath)) {
                $composer = json_decode(file_get_contents($composerPath), true);
                foreach ($composer['require'] ?? [] as $package => $version) {
                    if (stripos($package, 'cloudflare') !== false) {
                        $hasCloudflare = true;
                        break;
                    }
                }
            }
        }

        if (!$hasCloudflare) {
            $this->collector->add(
                'api-security',
                'Consider CDN-level rate limiting',
                Recommendation::PRIORITY_MEDIUM,
                'No CDN rate limiting detected. Cloudflare or similar CDN can provide additional DDoS protection and rate limiting.'
            );
        }
    }
}