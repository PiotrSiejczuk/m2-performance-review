<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Service\EnhancedConfigLoader;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

class HttpProtocolAnalyzer implements AnalyzerInterface
{
    private string $magentoRoot;
    private RecommendationCollector $collector;
    private array $coreConfig;
    private ?string $webServer = null;

    public function __construct(string $magentoRoot, array $coreConfig, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->coreConfig = $coreConfig;
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        // Detect web server first
        $this->webServer = $this->detectWebServer();

        // Run only relevant checks based on detected server
        if ($this->webServer === 'nginx') {
            $this->checkNginxConfiguration();
        } elseif ($this->webServer === 'apache') {
            $this->checkApacheConfiguration();
        } else {
            $this->collector->add(
                'protocol',
                'Unable to detect web server',
                Recommendation::PRIORITY_LOW,
                'Could not determine if using Nginx or Apache. Manual configuration review recommended.',
                'Web server detection helps provide specific optimization recommendations. Check for: ' .
                '1) Process names (nginx, apache2, httpd), 2) Config files (/etc/nginx/, /etc/apache2/), ' .
                '3) Server headers in HTTP responses. Common issue: running behind proxy/load balancer.'
            );
        }

        // These checks work regardless of web server
        $this->checkCloudflareFeatures();
        $this->checkModernProtocols();
        $this->checkHSTSConfiguration();
        $this->checkMagentoSecureUrls();
    }

    private function detectWebServer(): ?string
    {
        // Method 1: Check running processes
        $processes = shell_exec('ps aux 2>/dev/null');
        if ($processes) {
            if (preg_match('/nginx:\s+master\s+process/i', $processes)) {
                return 'nginx';
            }
            if (preg_match('/(apache2|httpd)\s/i', $processes)) {
                return 'apache';
            }
        }

        // Method 2: Check config file existence
        $nginxConfigs = [
            '/etc/nginx/nginx.conf',
            '/etc/nginx/sites-available/default',
            '/etc/nginx/conf.d/default.conf'
        ];

        foreach ($nginxConfigs as $config) {
            if (file_exists($config)) {
                return 'nginx';
            }
        }

        $apacheConfigs = [
            '/etc/apache2/apache2.conf',
            '/etc/httpd/conf/httpd.conf',
            '/etc/apache2/sites-available/000-default.conf'
        ];

        foreach ($apacheConfigs as $config) {
            if (file_exists($config)) {
                return 'apache';
            }
        }

        // Method 3: Check HTTP response headers
        $baseUrl = $this->detectBaseUrl();
        if ($baseUrl) {
            $headers = $this->getHttpHeaders($baseUrl);
            if ($headers && isset($headers['server'])) {
                $server = strtolower($headers['server']);
                if (strpos($server, 'nginx') !== false) {
                    return 'nginx';
                }
                if (strpos($server, 'apache') !== false) {
                    return 'apache';
                }
            }
        }

        // Method 4: Check for .htaccess (Apache indicator)
        if (file_exists($this->magentoRoot . '/.htaccess')) {
            return 'apache';
        }

        return null;
    }

    private function checkNginxConfiguration(): void
    {
        $nginxConfigs = [
            '/etc/nginx/sites-available/magento',
            '/etc/nginx/sites-enabled/magento',
            '/etc/nginx/conf.d/magento.conf',
            '/etc/nginx/nginx.conf'
        ];

        $http2Enabled = false;
        $http3Enabled = false;
        $brotliEnabled = false;
        $gzipEnabled = false;
        $configFound = false;

        foreach ($nginxConfigs as $config) {
            if (!file_exists($config) || !is_readable($config)) continue;

            $configFound = true;
            $content = file_get_contents($config);

            if (preg_match('/listen\s+.*\s+ssl\s+http2/i', $content) ||
                preg_match('/http2\s+on/i', $content)) {
                $http2Enabled = true;
            }

            if (preg_match('/listen\s+.*\s+quic/i', $content) ||
                preg_match('/http3\s+on/i', $content)) {
                $http3Enabled = true;
            }

            if (preg_match('/brotli\s+on/i', $content)) {
                $brotliEnabled = true;
            }

            if (preg_match('/gzip\s+on/i', $content)) {
                $gzipEnabled = true;
            }
        }

        if (!$configFound) {
            $this->collector->add(
                'protocol',
                'Nginx configuration not found',
                Recommendation::PRIORITY_MEDIUM,
                'Could not locate Nginx configuration files in standard locations.',
                'Nginx config typically in: /etc/nginx/sites-available/, /etc/nginx/conf.d/. ' .
                'Non-standard locations may indicate custom installation or containerized environment. ' .
                'Check nginx -t output for actual config location.'
            );
            return;
        }

        if (!$http2Enabled) {
            $this->collector->add(
                'protocol',
                'Enable HTTP/2 in Nginx',
                Recommendation::PRIORITY_HIGH,
                'HTTP/2 enables multiplexing, reducing connection overhead. Add "http2" to SSL listen directive: listen 443 ssl http2;',
                'HTTP/2 multiplexing allows multiple requests over single connection, eliminating head-of-line blocking. ' .
                'Benefits: 1) Reduced latency (no connection setup overhead), 2) Header compression (HPACK), ' .
                '3) Server push capability, 4) Binary protocol efficiency. Typical improvement: 15-30% faster page loads.'
            );
        }

        if (!$http3Enabled && $http2Enabled) {
            $this->collector->add(
                'protocol',
                'Consider HTTP/3 (QUIC) support',
                Recommendation::PRIORITY_MEDIUM,
                'HTTP/3 reduces latency and improves performance over unreliable networks. Requires Nginx 1.25+ with QUIC support.',
                'HTTP/3 uses QUIC (UDP-based) eliminating TCP head-of-line blocking. Benefits: 1) 0-RTT connection establishment, ' .
                '2) Better performance on mobile/lossy networks, 3) Connection migration across networks. ' .
                'Typical improvement: 10-15% faster on good connections, 30%+ on poor connections.'
            );
        }

        if (!$brotliEnabled) {
            $priority = $gzipEnabled ? Recommendation::PRIORITY_MEDIUM : Recommendation::PRIORITY_HIGH;
            $this->collector->add(
                'protocol',
                'Enable Brotli compression',
                $priority,
                'Brotli provides 15-25% better compression than gzip. Install nginx-module-brotli and configure: brotli on; brotli_comp_level 6;',
                'Brotli achieves better compression through: 1) Larger dictionary (120KB vs 32KB), 2) Context modeling, ' .
                '3) Advanced entropy encoding. Real-world gains: CSS 15-20% smaller, JS 10-15% smaller, HTML 20-25% smaller. ' .
                'Level 6 balances compression ratio vs CPU usage. Supported by 95%+ browsers.'
            );
        }

        if (!$gzipEnabled && !$brotliEnabled) {
            $this->collector->add(
                'protocol',
                'Enable compression (gzip minimum)',
                Recommendation::PRIORITY_HIGH,
                'No compression detected. Enable gzip as minimum: gzip on; gzip_vary on; gzip_min_length 1024;',
                'Text compression is critical for performance. Uncompressed HTML/CSS/JS wastes 60-80% bandwidth. ' .
                'Gzip reduces: HTML by 70-80%, CSS by 80-85%, JS by 65-75%. For 1MB of assets, saves 700KB+ transfer. ' .
                'Set gzip_min_length to avoid compressing tiny files where overhead exceeds benefit.'
            );
        }
    }

    private function checkApacheConfiguration(): void
    {
        $apacheConfigs = [
            '/etc/apache2/sites-available/magento.conf',
            '/etc/apache2/sites-enabled/magento.conf',
            '/etc/httpd/conf.d/magento.conf',
            $this->magentoRoot . '/.htaccess'
        ];

        $http2Enabled = false;
        $compressionEnabled = false;
        $configFound = false;

        foreach ($apacheConfigs as $config) {
            if (!file_exists($config) || !is_readable($config)) continue;

            $configFound = true;
            $content = file_get_contents($config);

            if (preg_match('/Protocols\s+h2/i', $content) ||
                preg_match('/LoadModule\s+http2_module/i', $content)) {
                $http2Enabled = true;
            }

            if (preg_match('/mod_deflate|mod_brotli|AddOutputFilterByType\s+DEFLATE/i', $content)) {
                $compressionEnabled = true;
            }
        }

        if (!$configFound) {
            $this->collector->add(
                'protocol',
                'Apache configuration review needed',
                Recommendation::PRIORITY_MEDIUM,
                'Could not verify Apache configuration. Check virtual host and .htaccess settings.',
                'Apache config locations vary by distribution. Common: /etc/apache2/, /etc/httpd/. ' .
                'For Magento, ensure .htaccess is present and not overridden by <Directory> blocks. ' .
                'Use apache2ctl -S to list active virtual hosts.'
            );
            return;
        }

        if (!$http2Enabled) {
            $this->collector->add(
                'protocol',
                'Enable HTTP/2 in Apache',
                Recommendation::PRIORITY_HIGH,
                'Enable HTTP/2: LoadModule http2_module modules/mod_http2.so and Protocols h2 http/1.1',
                'Apache HTTP/2 requires mod_http2. Benefits same as Nginx: multiplexing, header compression, server push. ' .
                'Note: Prefork MPM incompatible with HTTP/2 - use Event or Worker MPM. Also requires Apache 2.4.17+. ' .
                'Check MPM: apachectl -V | grep MPM. Switch to Event: a2dismod mpm_prefork && a2enmod mpm_event'
            );
        }

        if (!$compressionEnabled) {
            $this->collector->add(
                'protocol',
                'Enable compression in Apache',
                Recommendation::PRIORITY_HIGH,
                'Enable mod_deflate or mod_brotli for compression. Add to .htaccess or virtual host configuration.',
                'Apache compression via mod_deflate or mod_brotli. Configure proper MIME types: text/html, text/css, ' .
                'application/javascript, application/json, text/xml. Avoid compressing images, PDFs, or already compressed formats. ' .
                'Basic config: AddOutputFilterByType DEFLATE text/html text/css application/javascript'
            );
        }
    }

    private function checkCloudflareFeatures(): void
    {
        // Check for Cloudflare headers in a sample request
        $baseUrl = $this->detectBaseUrl();
        if (!$baseUrl) return;

        $headers = $this->getHttpHeaders($baseUrl);
        if (!$headers) return;

        $hasCloudflare = isset($headers['cf-ray']) || isset($headers['server']) && stripos($headers['server'], 'cloudflare') !== false;

        if ($hasCloudflare) {
            $this->analyzeCloudflareOptimizations($headers);
        } else {
            $this->collector->add(
                'protocol',
                'Consider Cloudflare for modern protocols',
                Recommendation::PRIORITY_LOW,
                'Cloudflare provides HTTP/3, Brotli, and other modern optimizations out-of-the-box for better performance.',
                'Cloudflare automatically enables: HTTP/2 & HTTP/3, Brotli compression, Global CDN, DDoS protection. ' .
                'Additional features: Auto-minification, Image optimization, Early Hints, 0-RTT. ' .
                'Free tier sufficient for most e-commerce sites. Reduces server load by 60-80%.'
            );
        }
    }

    private function analyzeCloudflareOptimizations(array $headers): void
    {
        // Check Brotli support
        if (!isset($headers['content-encoding']) || stripos($headers['content-encoding'], 'br') === false) {
            $this->collector->add(
                'protocol',
                'Enable Brotli in Cloudflare',
                Recommendation::PRIORITY_MEDIUM,
                'Cloudflare supports Brotli compression. Enable in Speed > Optimization > Brotli.'
            );
        }

        // Check for modern features
        if (!isset($headers['cf-cache-status'])) {
            $this->collector->add(
                'protocol',
                'Optimize Cloudflare caching',
                Recommendation::PRIORITY_MEDIUM,
                'Ensure static assets are being cached by Cloudflare. Check Page Rules and Cache Level settings.'
            );
        }
    }

    private function checkModernProtocols(): void
    {
        $baseUrl = $this->detectBaseUrl();
        if (!$baseUrl) return;

        $headers = $this->getHttpHeaders($baseUrl);
        if (!$headers) return;

        // Check protocol version
        $protocolVersion = $this->detectProtocolVersion($baseUrl);

        if ($protocolVersion < 2.0) {
            $this->collector->add(
                'protocol',
                'HTTP/1.1 detected - upgrade to HTTP/2',
                Recommendation::PRIORITY_HIGH,
                'Site is using HTTP/1.1. HTTP/2 provides multiplexing, header compression, and server push capabilities.'
            );
        } elseif ($protocolVersion >= 2.0 && $protocolVersion < 3.0) {
            $this->collector->add(
                'protocol',
                'HTTP/2 active - consider HTTP/3',
                Recommendation::PRIORITY_LOW,
                'HTTP/2 is working. HTTP/3 (QUIC) can provide additional latency improvements, especially on mobile networks.'
            );
        }

        // Check for modern security headers
        $this->checkSecurityHeaders($headers);
    }

    private function checkSecurityHeaders(array $headers): void
    {
        $modernHeaders = [
            'strict-transport-security' => 'HSTS for HTTPS enforcement',
            'x-content-type-options' => 'nosniff protection',
            'x-frame-options' => 'Clickjacking protection',
            'content-security-policy' => 'XSS protection',
            'permissions-policy' => 'Feature policy restrictions'
        ];

        $missing = [];
        foreach ($modernHeaders as $header => $description) {
            if (!isset($headers[strtolower($header)])) {
                $missing[] = "$header ($description)";
            }
        }

        if (count($missing) > 2) {
            $this->collector->add(
                'protocol',
                'Add modern security headers',
                Recommendation::PRIORITY_MEDIUM,
                'Missing security headers: ' . implode(', ', array_slice($missing, 0, 3)) .
                (count($missing) > 3 ? ' and ' . (count($missing) - 3) . ' more' : '')
            );
        }
    }

    private function detectBaseUrl(): ?string
    {
        // First try core config
        if (isset($this->coreConfig['web/unsecure/base_url'])) {
            return $this->coreConfig['web/unsecure/base_url'];
        }

        // Fallback to env.php
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;
            return $env['system']['default']['web']['base_url'] ?? null;
        }
        return null;
    }

    private function getHttpHeaders(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 5,
                'user_agent' => 'M2Performance/1.0'
            ]
        ]);

        $headers = @get_headers($url, 1, $context);
        if (!$headers) return null;

        // Normalize header keys to lowercase
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_string($key)) {
                $normalized[strtolower($key)] = $value;
            }
        }

        return $normalized;
    }

    private function detectProtocolVersion(string $url): float
    {
        // Parse URL to get host and port
        $parts = parse_url($url);
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        // Try to detect HTTP version using curl if available
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
            curl_close($ch);

            return match($httpCode) {
                CURL_HTTP_VERSION_1_0 => 1.0,
                CURL_HTTP_VERSION_1_1 => 1.1,
                CURL_HTTP_VERSION_2_0 => 2.0,
                CURL_HTTP_VERSION_3 => 3.0,
                default => 1.1
            };
        }

        return 1.1; // Default assumption
    }

    private function checkHSTSConfiguration(): void
    {
        // Check in web server configs
        $this->checkHSTSInWebServer();

        // Check actual HTTP response headers
        $this->checkHSTSInResponse();
    }

    private function checkHSTSInWebServer(): void
    {
        $hstsFound = false;
        $hstsConfig = [];

        // Check Nginx configs
        $nginxConfigs = [
            '/etc/nginx/sites-available/magento',
            '/etc/nginx/conf.d/magento.conf',
            '/etc/nginx/nginx.conf'
        ];

        foreach ($nginxConfigs as $config) {
            if (!file_exists($config) || !is_readable($config)) continue;

            $content = file_get_contents($config);

            // Look for HSTS header configuration
            if (preg_match('/add_header\s+Strict-Transport-Security\s+"([^"]+)"/i', $content, $matches)) {
                $hstsFound = true;
                $hstsConfig['nginx'] = $matches[1];
                break;
            }
        }

        // Check Apache configs
        $apacheConfigs = [
            '/etc/apache2/sites-available/magento.conf',
            '/etc/httpd/conf.d/magento.conf',
            $this->magentoRoot . '/.htaccess'
        ];

        foreach ($apacheConfigs as $config) {
            if (!file_exists($config) || !is_readable($config)) continue;

            $content = file_get_contents($config);

            // Look for HSTS header configuration
            if (preg_match('/Header\s+always\s+set\s+Strict-Transport-Security\s+"([^"]+)"/i', $content, $matches)) {
                $hstsFound = true;
                $hstsConfig['apache'] = $matches[1];
                break;
            }
        }

        if (!$hstsFound) {
            $this->collector->add(
                'protocol',
                'Enable HTTP Strict Transport Security (HSTS)',
                Recommendation::PRIORITY_HIGH,
                'HSTS header not found in web server configuration. Add to force HTTPS connections.',
                'HSTS prevents protocol downgrade attacks and cookie hijacking by forcing HTTPS-only connections. ' .
                'Without HSTS, attackers can intercept initial HTTP requests before redirect to HTTPS. ' .
                'Recommended config: Strict-Transport-Security: max-age=31536000; includeSubDomains; preload. ' .
                'Start with max-age=300 for testing, then increase to 31536000 (1 year) for production.'
            );
        } else {
            // Analyze HSTS configuration quality
            $this->analyzeHSTSConfig($hstsConfig);
        }
    }

    private function analyzeHSTSConfig(array $hstsConfig): void
    {
        $config = reset($hstsConfig); // Get first config found

        // Parse HSTS directives
        $maxAge = 0;
        $includeSubDomains = false;
        $preload = false;

        if (preg_match('/max-age=(\d+)/i', $config, $matches)) {
            $maxAge = (int)$matches[1];
        }

        if (stripos($config, 'includeSubDomains') !== false) {
            $includeSubDomains = true;
        }

        if (stripos($config, 'preload') !== false) {
            $preload = true;
        }

        // Check max-age value
        if ($maxAge < 31536000) { // Less than 1 year
            $this->collector->add(
                'protocol',
                'Increase HSTS max-age duration',
                Recommendation::PRIORITY_MEDIUM,
                sprintf('Current HSTS max-age is %d seconds (%.1f days). Recommended: 31536000 (1 year).',
                    $maxAge, $maxAge / 86400),
                'Short HSTS max-age provides limited protection. Browsers forget HSTS policy quickly, allowing downgrade attacks. ' .
                'Industry standard is 31536000 seconds (1 year). Chrome requires 1 year for HSTS preload list. ' .
                'Gradually increase: 300→86400→604800→2592000→31536000 to avoid lockout from misconfigurations.'
            );
        }

        // Check includeSubDomains
        if (!$includeSubDomains) {
            $this->collector->add(
                'protocol',
                'Add includeSubDomains to HSTS',
                Recommendation::PRIORITY_MEDIUM,
                'HSTS should include subdomains for complete protection.',
                'Without includeSubDomains, attackers can exploit subdomains (e.g., test.example.com) to bypass HSTS. ' .
                'This enables cookie stealing via insecure subdomains. Ensure all subdomains support HTTPS before enabling. ' .
                'Add: includeSubDomains to your Strict-Transport-Security header.'
            );
        }

        // Check preload
        if (!$preload && $maxAge >= 31536000 && $includeSubDomains) {
            $this->collector->add(
                'protocol',
                'Consider HSTS preload submission',
                Recommendation::PRIORITY_LOW,
                'Site meets requirements for HSTS preload list. Consider adding "preload" directive.',
                'HSTS preload hardcodes your domain in browsers, preventing first-visit attacks. Requirements: ' .
                '1) Valid certificate, 2) Redirect HTTP→HTTPS, 3) HTTPS on all subdomains, 4) max-age≥31536000, ' .
                '5) includeSubDomains directive. Submit at hstspreload.org. Warning: Hard to reverse - plan carefully.'
            );
        }
    }

    private function checkHSTSInResponse(): void
    {
        $baseUrl = $this->detectBaseUrl();
        if (!$baseUrl || strpos($baseUrl, 'https://') !== 0) {
            return; // Only check HTTPS URLs
        }

        $headers = $this->getHttpHeaders($baseUrl);
        if (!$headers) return;

        $hstsHeader = $headers['strict-transport-security'] ?? null;

        if (!$hstsHeader) {
            $this->collector->add(
                'protocol',
                'HSTS header missing in HTTP response',
                Recommendation::PRIORITY_HIGH,
                'Live site is not sending HSTS header. Verify web server configuration is applied.',
                'HSTS must be sent on every HTTPS response to be effective. Common issues: ' .
                '1) Header only set in specific location blocks, 2) Overridden by application, ' .
                '3) CDN stripping headers, 4) Load balancer not forwarding. Test: curl -I https://yoursite.com'
            );
        }
    }

    private function checkMagentoSecureUrls(): void
    {
        $configLoader = new EnhancedConfigLoader($this->magentoRoot);
        
        // Check if HTTPS is enforced with proper configuration hierarchy
        $useSecureUrls = $configLoader->getConfigValue('web/secure/use_in_frontend', '0');
        $useSecureUrlsAdmin = $configLoader->getConfigValue('web/secure/use_in_adminhtml', '0');
        $baseUrl = $configLoader->getConfigValue('web/unsecure/base_url', '');
        $secureBaseUrl = $configLoader->getConfigValue('web/secure/base_url', '');

        // Check frontend HTTPS enforcement
        if ($useSecureUrls !== '1') {
            $this->collector->add(
                'protocol',
                'Enable secure URLs for frontend',
                Recommendation::PRIORITY_HIGH,
                "HTTPS is not enforced for frontend (current value: '{$useSecureUrls}'). Set: bin/magento config:set web/secure/use_in_frontend 1",
                'Without secure frontend URLs, customer data (passwords, payment info, PII) transmits over HTTP. ' .
                'This violates PCI compliance, GDPR, and allows man-in-the-middle attacks. Modern browsers show ' .
                '"Not Secure" warnings. Google penalizes HTTP sites in search rankings. Always use HTTPS in production.'
            );
        }

        // Check admin HTTPS enforcement
        if ($useSecureUrlsAdmin !== '1') {
            $this->collector->add(
                'protocol',
                'Enable secure URLs for admin',
                Recommendation::PRIORITY_HIGH,
                "HTTPS is not enforced for admin (current value: '{$useSecureUrlsAdmin}'). Set: bin/magento config:set web/secure/use_in_adminhtml 1",
                'Admin panel must use HTTPS to protect administrative credentials and operations. Without HTTPS, ' .
                'admin sessions can be hijacked, allowing full store compromise. This is the #1 security requirement. ' .
                'Also enable admin URL secret key: bin/magento config:set admin/security/use_form_key 1'
            );
        }

        // Check base URL configuration
        if (!empty($baseUrl) && strpos($baseUrl, 'http://') === 0 && 
            (empty($secureBaseUrl) || strpos($secureBaseUrl, 'https://') !== 0)) {
            
            $this->collector->add(
                'protocol',
                'Configure secure base URL',
                Recommendation::PRIORITY_HIGH,
                "Secure base URL not properly configured. Current: '{$secureBaseUrl}'. Set: bin/magento config:set web/secure/base_url https://yourdomain.com/",
                'Magento needs separate secure URL configuration. Without it, HTTPS redirects fail, mixed content ' .
                'warnings appear, and payment gateways reject requests. Ensure URL ends with / and matches SSL certificate.'
            );
        }

        // Check offloader header configuration for load balancers
        $offloaderHeader = $configLoader->getConfigValue('web/secure/offloader_header', '');

        if (empty($offloaderHeader) && $this->isUsingLoadBalancer()) {
            $this->collector->add(
                'protocol',
                'Configure HTTPS offloader header',
                Recommendation::PRIORITY_MEDIUM,
                'Set offloader header for load balancer/proxy: bin/magento config:set web/secure/offloader_header X-Forwarded-Proto',
                'When using load balancer/CDN with SSL termination, Magento must detect HTTPS from proxy headers. ' .
                'Without this, Magento thinks all requests are HTTP, causing redirect loops and insecure cookies. ' .
                'Common headers: X-Forwarded-Proto (standard), X-Forwarded-Ssl, X-Url-Scheme. Check your LB docs.'
            );
        }
    }

    private function isUsingLoadBalancer(): bool
    {
        // Check for common load balancer indicators
        $baseUrl = $this->detectBaseUrl();
        if (!$baseUrl) return false;

        $headers = $this->getHttpHeaders($baseUrl);
        if (!$headers) return false;

        // Check for common LB/proxy headers
        $lbHeaders = [
            'x-forwarded-for',
            'x-forwarded-proto',
            'x-real-ip',
            'cf-ray', // Cloudflare
            'x-amz-cf-id' // AWS CloudFront
        ];

        foreach ($lbHeaders as $header) {
            if (isset($headers[$header])) {
                return true;
            }
        }

        return false;
    }
}
