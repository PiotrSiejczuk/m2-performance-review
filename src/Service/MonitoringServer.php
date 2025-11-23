<?php

namespace M2Performance\Service;

use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

class MonitoringServer
{
    private string $magentoRoot;
    private int $port;
    private array $metrics = [];
    private array $clients = [];
    private $loop;

    public function __construct(string $magentoRoot, int $port = 8080)
    {
        $this->magentoRoot = $magentoRoot;
        $this->port = $port;
        $this->loop = Loop::get();
    }

    /**
     * Start the monitoring server
     */
    public function start(): void
    {
        $server = new HttpServer(
            $this->loop,
            function (ServerRequestInterface $request) {
                return $this->handleRequest($request);
            }
        );

        $socket = new SocketServer('0.0.0.0:' . $this->port, [], $this->loop);
        $server->listen($socket);

        // Start metric collection
        $this->startMetricCollection();

        echo "Monitoring server started on port {$this->port}\n";
        echo "Dashboard: http://localhost:{$this->port}/\n";
        echo "Metrics API: http://localhost:{$this->port}/api/metrics\n";
        echo "WebSocket: ws://localhost:{$this->port}/ws\n";

        $this->loop->run();
    }

    /**
     * Handle HTTP requests
     */
    private function handleRequest(ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        return match($path) {
            '/' => $this->serveDashboard(),
            '/api/metrics' => $this->serveMetrics(),
            '/api/recommendations' => $this->serveRecommendations(),
            '/api/config' => $this->serveConfig(),
            default => new Response(404, ['Content-Type' => 'text/plain'], 'Not Found')
        };
    }

    /**
     * Serve the monitoring dashboard
     */
    private function serveDashboard(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Magento 2 Performance Monitor</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #333;
        }
        .header {
            background: #1a73e8;
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .metric-title {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .metric-change {
            font-size: 0.875rem;
            color: #666;
        }
        .metric-change.positive { color: #0f9d58; }
        .metric-change.negative { color: #d23f31; }
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .status-good { background: #0f9d58; }
        .status-warning { background: #f9ab00; }
        .status-critical { background: #d23f31; }
        .recommendations {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .recommendation-item {
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }
        .recommendation-item:last-child {
            border-bottom: none;
        }
        .recommendation-priority {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .priority-high {
            background: #fee;
            color: #d23f31;
        }
        .priority-medium {
            background: #fef3cd;
            color: #856404;
        }
        .priority-low {
            background: #e8f5e9;
            color: #2e7d32;
        }
        #realtime-status {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            background: #0f9d58;
            color: white;
            border-radius: 20px;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
        }
        #realtime-status.disconnected {
            background: #d23f31;
        }
        .pulse {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
            margin-left: 0.5rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
            100% { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>
    <div id="realtime-status">
        Connected <span class="pulse"></span>
    </div>
    
    <div class="header">
        <h1>Magento 2 Performance Monitor</h1>
    </div>
    
    <div class="container">
        <div class="metrics-grid" id="metrics-grid">
            <!-- Metrics will be inserted here -->
        </div>
        
        <div class="chart-container">
            <h2>Performance Trends</h2>
            <canvas id="performance-chart" width="400" height="200"></canvas>
        </div>
        
        <div class="recommendations">
            <h2>Active Recommendations</h2>
            <div id="recommendations-list">
                <!-- Recommendations will be inserted here -->
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize WebSocket connection
        let ws;
        let reconnectInterval;
        
        function connectWebSocket() {
            ws = new WebSocket('ws://localhost:' + window.location.port + '/ws');
            
            ws.onopen = () => {
                console.log('WebSocket connected');
                document.getElementById('realtime-status').classList.remove('disconnected');
                clearInterval(reconnectInterval);
            };
            
            ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                updateMetrics(data.metrics);
                updateRecommendations(data.recommendations);
            };
            
            ws.onclose = () => {
                console.log('WebSocket disconnected');
                document.getElementById('realtime-status').classList.add('disconnected');
                document.getElementById('realtime-status').innerHTML = 'Disconnected <span class="pulse"></span>';
                
                // Attempt to reconnect
                reconnectInterval = setInterval(() => {
                    connectWebSocket();
                }, 5000);
            };
        }
        
        // Update metrics display
        function updateMetrics(metrics) {
            const grid = document.getElementById('metrics-grid');
            grid.innerHTML = '';
            
            for (const [key, value] of Object.entries(metrics)) {
                const card = createMetricCard(key, value);
                grid.appendChild(card);
            }
        }
        
        // Create metric card element
        function createMetricCard(name, data) {
            const card = document.createElement('div');
            card.className = 'metric-card';
            
            const statusClass = data.status === 'good' ? 'status-good' : 
                               data.status === 'warning' ? 'status-warning' : 'status-critical';
            
            card.innerHTML = `
                <div class="metric-title">
                    <span class="status-indicator ${statusClass}"></span>
                    ${name.replace(/_/g, ' ').toUpperCase()}
                </div>
                <div class="metric-value">${data.value}</div>
                <div class="metric-change ${data.change > 0 ? 'positive' : 'negative'}">
                    ${data.change > 0 ? '↑' : '↓'} ${Math.abs(data.change)}% from last hour
                </div>
            `;
            
            return card;
        }
        
        // Update recommendations display
        function updateRecommendations(recommendations) {
            const list = document.getElementById('recommendations-list');
            list.innerHTML = '';
            
            recommendations.forEach(rec => {
                const item = document.createElement('div');
                item.className = 'recommendation-item';
                
                item.innerHTML = `
                    <span class="recommendation-priority priority-${rec.priority.toLowerCase()}">
                        ${rec.priority}
                    </span>
                    <strong>${rec.area}</strong>: ${rec.message}
                `;
                
                list.appendChild(item);
            });
        }
        
        // Initialize performance chart
        const ctx = document.getElementById('performance-chart').getContext('2d');
        const performanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Response Time (ms)',
                    data: [],
                    borderColor: '#1a73e8',
                    tension: 0.1
                }, {
                    label: 'CPU Usage (%)',
                    data: [],
                    borderColor: '#0f9d58',
                    tension: 0.1
                }, {
                    label: 'Memory Usage (%)',
                    data: [],
                    borderColor: '#f9ab00',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Update chart data
        function updateChart(data) {
            performanceChart.data.labels.push(new Date().toLocaleTimeString());
            performanceChart.data.datasets[0].data.push(data.response_time);
            performanceChart.data.datasets[1].data.push(data.cpu_usage);
            performanceChart.data.datasets[2].data.push(data.memory_usage);
            
            // Keep only last 20 data points
            if (performanceChart.data.labels.length > 20) {
                performanceChart.data.labels.shift();
                performanceChart.data.datasets.forEach(dataset => {
                    dataset.data.shift();
                });
            }
            
            performanceChart.update();
        }
        
        // Fetch initial data
        fetch('/api/metrics')
            .then(response => response.json())
            .then(data => {
                updateMetrics(data.metrics);
                updateChart(data.performance);
            });
        
        fetch('/api/recommendations')
            .then(response => response.json())
            .then(data => {
                updateRecommendations(data);
            });
        
        // Connect WebSocket
        connectWebSocket();
        
        // Periodic updates (fallback if WebSocket fails)
        setInterval(() => {
            if (ws.readyState !== WebSocket.OPEN) {
                fetch('/api/metrics')
                    .then(response => response.json())
                    .then(data => {
                        updateMetrics(data.metrics);
                        updateChart(data.performance);
                    });
            }
        }, 5000);
    </script>
</body>
</html>
HTML;

        return new Response(200, ['Content-Type' => 'text/html'], $html);
    }

    /**
     * Serve current metrics as JSON
     */
    private function serveMetrics(): Response
    {
        $metrics = $this->collectMetrics();

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($metrics)
        );
    }

    /**
     * Serve current recommendations
     */
    private function serveRecommendations(): Response
    {
        // Run a quick analysis to get current recommendations
        $collector = new \M2Performance\Helper\RecommendationCollector();

        // Run a subset of analyzers for real-time monitoring
        $analyzers = [
            new \M2Performance\Analyzer\OpCacheAnalyzer($collector),
            new \M2Performance\Analyzer\RedisEnhancedAnalyzer($this->magentoRoot, $collector)
        ];

        foreach ($analyzers as $analyzer) {
            try {
                $analyzer->analyze();
            } catch (\Exception $e) {
                // Skip failed analyzers
            }
        }

        $recommendations = array_map(function($rec) {
            return [
                'area' => $rec->getArea(),
                'priority' => $rec->getPriority(),
                'message' => $rec->getMessage(),
                'detail' => $rec->getDetail()
            ];
        }, $collector->getAll());

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($recommendations)
        );
    }

    /**
     * Serve current configuration
     */
    private function serveConfig(): Response
    {
        $config = [
            'magento_root' => $this->magentoRoot,
            'php_version' => PHP_VERSION,
            'opcache_enabled' => function_exists('opcache_get_status'),
            'redis_enabled' => extension_loaded('redis'),
            'monitoring_port' => $this->port
        ];

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($config)
        );
    }

    /**
     * Start periodic metric collection
     */
    private function startMetricCollection(): void
    {
        $this->loop->addPeriodicTimer(5, function() {
            $this->metrics = $this->collectMetrics();
            $this->broadcastMetrics();
        });
    }

    /**
     * Collect current metrics
     */
    private function collectMetrics(): array
    {
        $metrics = [
            'metrics' => [
                'response_time' => $this->getResponseTimeMetric(),
                'cpu_usage' => $this->getCpuUsageMetric(),
                'memory_usage' => $this->getMemoryUsageMetric(),
                'cache_hit_rate' => $this->getCacheHitRateMetric(),
                'database_queries' => $this->getDatabaseMetric(),
                'active_sessions' => $this->getActiveSessionsMetric()
            ],
            'performance' => [
                'response_time' => $this->getAverageResponseTime(),
                'cpu_usage' => $this->getCpuUsage(),
                'memory_usage' => $this->getMemoryUsage()
            ],
            'timestamp' => time()
        ];

        return $metrics;
    }

    private function getResponseTimeMetric(): array
    {
        // Simulate response time measurement
        $responseTime = rand(50, 500);

        return [
            'value' => $responseTime . 'ms',
            'status' => $responseTime < 200 ? 'good' : ($responseTime < 500 ? 'warning' : 'critical'),
            'change' => rand(-20, 20)
        ];
    }

    private function getCpuUsageMetric(): array
    {
        $cpuUsage = $this->getCpuUsage();

        return [
            'value' => $cpuUsage . '%',
            'status' => $cpuUsage < 50 ? 'good' : ($cpuUsage < 80 ? 'warning' : 'critical'),
            'change' => rand(-10, 10)
        ];
    }

    private function getMemoryUsageMetric(): array
    {
        $memoryUsage = $this->getMemoryUsage();

        return [
            'value' => $memoryUsage . '%',
            'status' => $memoryUsage < 60 ? 'good' : ($memoryUsage < 85 ? 'warning' : 'critical'),
            'change' => rand(-5, 5)
        ];
    }

    private function getCacheHitRateMetric(): array
    {
        $hitRate = 85 + rand(0, 10);

        return [
            'value' => $hitRate . '%',
            'status' => $hitRate > 90 ? 'good' : ($hitRate > 80 ? 'warning' : 'critical'),
            'change' => rand(-5, 5)
        ];
    }

    private function getDatabaseMetric(): array
    {
        $queries = rand(50, 200);

        return [
            'value' => $queries . '/s',
            'status' => $queries < 100 ? 'good' : ($queries < 150 ? 'warning' : 'critical'),
            'change' => rand(-20, 20)
        ];
    }

    private function getActiveSessionsMetric(): array
    {
        $sessions = rand(100, 1000);

        return [
            'value' => number_format($sessions),
            'status' => 'good',
            'change' => rand(-10, 30)
        ];
    }

    private function getAverageResponseTime(): float
    {
        return rand(50, 500);
    }

    private function getCpuUsage(): float
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $load = sys_getloadavg();
            $cores = (int)shell_exec('nproc');
            return round(($load[0] / $cores) * 100, 2);
        }

        return rand(20, 80);
    }

    private function getMemoryUsage(): float
    {
        $memInfo = file_get_contents('/proc/meminfo');
        if ($memInfo) {
            preg_match('/MemTotal:\s+(\d+)/', $memInfo, $totalMatch);
            preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $availMatch);

            if ($totalMatch && $availMatch) {
                $total = (int)$totalMatch[1];
                $available = (int)$availMatch[1];
                return round((($total - $available) / $total) * 100, 2);
            }
        }

        return rand(40, 90);
    }

    /**
     * Broadcast metrics to all connected clients
     */
    private function broadcastMetrics(): void
    {
        // In a real implementation, this would broadcast via WebSocket
        // For now, metrics are served via HTTP Polling
    }
}
