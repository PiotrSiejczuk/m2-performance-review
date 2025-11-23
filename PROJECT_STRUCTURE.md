# M2 Performance Review Tool - Project Structure

## Enhanced Directory Structure

```
m2-performance-review/
├── src/
│   ├── Analyzer/
│   │   ├── AdobeCommerceAnalyzer.php      # Adobe Commerce specific checks
│   │   ├── APIRateLimitingAnalyzer.php    # NEW: API rate limiting validation
│   │   ├── CacheAnalyzer.php              # Cache configuration analysis
│   │   ├── CodebaseAnalyzer.php           # Custom code analysis
│   │   ├── ConfigurationAnalyzer.php      # Magento configuration checks
│   │   ├── DatabaseAnalyzer.php           # Database performance analysis
│   │   ├── FrontendAnalyzer.php           # Frontend optimization checks
│   │   ├── IndexersAnalyzer.php           # Indexer configuration validation
│   │   ├── ModulesAnalyzer.php            # Module impact analysis
│   │   ├── OpCacheAnalyzer.php            # PHP OPcache optimization
│   │   ├── RedisEnhancedAnalyzer.php      # NEW: Enhanced Redis analysis
│   │   └── SecurityChecklistAnalyzer.php   # NEW: Security compliance checks
│   │
│   ├── Command/
│   │   ├── M2PerformanceCommand.php       # ENHANCED: Main review command
│   │   ├── MonitorCommand.php             # NEW: Real-time monitoring
│   │   ├── BenchmarkCommand.php           # NEW: Performance benchmarks
│   │   └── GenerateCommand.php            # NEW: Code generation
│   │
│   ├── Contract/
│   │   └── AnalyzerInterface.php          # Analyzer contract
│   │
│   ├── Helper/
│   │   └── RecommendationCollector.php    # Recommendation management
│   │
│   ├── Model/
│   │   └── Recommendation.php             # Recommendation model
│   │
│   ├── Service/
│   │   ├── AIRecommendationEngine.php    # NEW: AI-powered insights
│   │   ├── AsyncCheckRunner.php           # NEW: Parallel execution
│   │   ├── BenchmarkRunner.php            # NEW: Benchmark execution
│   │   ├── EnvironmentLoader.php          # Environment configuration
│   │   ├── InfrastructureGenerator.php    # NEW: IaC generation
│   │   ├── MagentoDbConnection.php        # Database connection
│   │   ├── MonitoringServer.php           # NEW: Real-time monitoring
│   │   └── XmlReader.php                  # XML configuration reader
│   │
│   ├── Utils/
│   │   └── SocketChecker.php              # Network utilities
│   │
│   └── Application.php                     # ENHANCED: Main application
│
├── bin/
│   └── m2-performance                     # CLI executable
│
├── tests/                                 # Unit tests (to be added)
│
├── build-phar.php                         # PHAR build script
├── composer.json                          # Dependencies
├── README.md                              # Documentation
├── LICENSE                                # GPL-3.0 License
└── .gitignore                            # Git ignore rules
```

## Key Enhancements

### 1. **New Analyzers**

#### RedisEnhancedAnalyzer
- Implements LinkedIn article recommendations
- Checks critical `disable_locking` setting
- Validates compression (LZ4 recommended)
- Monitors Redis performance metrics
- Checks persistent connections

#### APIRateLimitingAnalyzer
- Multi-layer rate limiting validation
- Nginx configuration checks
- Varnish throttling validation
- Magento API security settings
- CDN-level protection detection

#### SecurityChecklistAnalyzer
- Based on talesh/magento-security-checklist
- File permission validation
- Admin security audit
- Security headers check
- SSL/TLS configuration
- Backup security

### 2. **New Services**

#### AsyncCheckRunner
- Parallel analyzer execution
- Process pool management
- Progress tracking
- Configurable concurrency

#### BenchmarkRunner
- Homepage performance testing
- Category/Product page benchmarks
- API endpoint testing
- Cache performance analysis
- Database query benchmarks

#### InfrastructureGenerator
- Terraform AWS infrastructure
- Ansible optimization playbooks
- Docker Compose configurations
- Customizable templates

#### AIRecommendationEngine
- OpenAI API integration
- Contextual recommendations
- ROI-based prioritization
- Predictive insights
- Action plan generation

#### MonitoringServer
- Real-time web dashboard
- WebSocket updates
- Performance metrics
- Live recommendations
- Historical tracking

### 3. **New Commands**

#### monitor
```bash
./m2-performance.phar monitor --port=8080
```
- Starts web-based monitoring dashboard
- Real-time performance metrics
- WebSocket live updates

#### benchmark
```bash
./m2-performance.phar benchmark --url=https://mystore.com
```
- Runs performance benchmarks
- Tests multiple scenarios
- Exports results

#### generate
```bash
./m2-performance.phar generate infra --format=terraform
./m2-performance.phar generate recommendations --ai-key=sk-...
./m2-performance.phar generate report --format=html
```
- Infrastructure code generation
- AI recommendations
- Performance reports

### 4. **Enhanced Features**

#### Main Command Enhancements
- `--async` - Parallel execution
- `--watch` - Continuous monitoring
- `--profile` - Predefined check sets
- `--severity` - Filter by severity
- Multiple export formats

#### Output Formats
- Table (default)
- JSON
- CSV
- HTML reports
- Prometheus metrics

## Integration Points

### 1. **Existing Analyzers Enhanced**
- All analyzers now support async execution
- Standardized recommendation format
- Improved error handling
- Performance metrics collection

### 2. **Backward Compatibility**
- Original command structure preserved
- Default behavior unchanged
- New features are opt-in
- No breaking changes

### 3. **Extensibility**
- Plugin architecture for custom analyzers
- Configurable check profiles
- Custom output formatters
- Hook system for integrations

## Usage Examples

### Basic Review (Original)
```bash
./m2-performance.phar review
```

### Enhanced Security Review
```bash
./m2-performance.phar review --profile=security --export=security-audit.json
```

### Full Analysis with AI
```bash
./m2-performance.phar review --async --export=analysis.json
./m2-performance.phar generate recommendations --ai-key=$OPENAI_KEY
```

### Production Infrastructure
```bash
./m2-performance.phar review --profile=full
./m2-performance.phar generate infra --format=terraform --config=prod.json
```

### Continuous Monitoring
```bash
# Terminal 1: Start monitoring server
./m2-performance.phar monitor

# Terminal 2: Run continuous checks
./m2-performance.phar review --watch
```

## Configuration

### Environment Variables
```bash
export OPENAI_API_KEY=sk-...          # For AI Recommendations
export MAGENTO_BASE_URL=https://...   # For Benchmarks
export M2_PERF_CONFIG=/path/config    # Custom Configuration
```

### Custom Configuration (config.json)
```json
{
  "analyzers": {
    "redis": {
      "enabled": true,
      "async": true,
      "options": {
        "check_memory": true,
        "check_persistence": true,
        "alert_threshold": 80
      }
    }
  },
  "output": {
    "format": "json",
    "verbose": true
  },
  "monitoring": {
    "port": 8080,
    "refresh_interval": 5
  }
}
```

## Building and Distribution

### Build PHAR
```bash
composer install --no-dev
php -d phar.readonly=0 build-phar.php
```

### Install via Composer
```bash
composer require piotrsiejczuk/m2-performance-review
```

### Docker Distribution
```dockerfile
FROM php:8.1-cli
COPY m2-performance.phar /usr/local/bin/m2-performance
RUN chmod +x /usr/local/bin/m2-performance
ENTRYPOINT ["m2-performance"]
```

## Future Enhancements

1. **GraphQL Performance Analysis**
2. **PWA/Headless Checks**
3. **Kubernetes Deployment**
4. **Multi-store Analysis**
5. **Historical Tracking Database**
6. **Custom Metric Plugins**
7. **Integration with APM Tools**
8. **Automated Fix Application**