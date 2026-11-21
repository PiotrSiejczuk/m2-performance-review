# M2 Performance Review Tool - Project Structure

## Enhanced Directory Structure

```
m2-performance-review/
├── src/
│   ├── Analyzer/
│   │   ├── AdobeCommerceAnalyzer.php      # Adobe Commerce specific checks
│   │   ├── APIRateLimitingAnalyzer.php    # API rate limiting validation
│   │   ├── CacheAnalyzer.php              # Cache configuration analysis (Fastly-aware)
│   │   ├── CodebaseAnalyzer.php           # Custom code analysis
│   │   ├── ConfigurationAnalyzer.php      # Magento configuration checks
│   │   ├── DatabaseAnalyzer.php           # Database performance analysis
│   │   ├── FrontendAnalyzer.php           # Frontend optimization checks (Hyvä-aware)
│   │   ├── HttpProtocolAnalyzer.php       # HTTP/2, HSTS, compression
│   │   ├── IndexersAnalyzer.php           # Indexers + search engine connectivity
│   │   ├── LayoutCacheAnalyzer.php        # cacheable="false" detection
│   │   ├── ModulesAnalyzer.php            # Module impact analysis
│   │   ├── OpCacheAnalyzer.php            # PHP OPcache optimization
│   │   ├── RedisEnhancedAnalyzer.php      # Redis / Valkey / L2 cache analysis
│   │   ├── SecurityChecklistAnalyzer.php  # Security compliance checks
│   │   ├── ServerUptimeAnalyzer.php       # System health
│   │   ├── StaticCodeAnalyzer.php         # NEW 1.0.4: N+1, cache TTL, type mismatches
│   │   └── VarnishPerformanceAnalyzer.php # Varnish analysis (skipped on Fastly)
│   │
│   ├── Command/
│   │   ├── M2PerformanceCommand.php       # Main review command (alias: review)
│   │   ├── BaselineCommand.php            # NEW 1.0.4: baseline save/list/compare
│   │   ├── McpServerCommand.php           # NEW 1.0.4: MCP server over stdio
│   │   ├── BenchmarkCommand.php           # Performance benchmarks
│   │   ├── CacheMonitorCommand.php        # Real-time cache metrics
│   │   ├── GenerateCommand.php            # Infra / report / action-plan generation
│   │   ├── MonitorCommand.php             # Web dashboard (requires ReactPHP)
│   │   └── SelfUpdateCommand.php          # Self-update with SHA-256 verification
│   │
│   ├── Contract/
│   │   └── AnalyzerInterface.php          # Analyzer contract
│   │
│   ├── Helper/
│   │   ├── ImprovedTableFormatter.php     # Table formatting helpers
│   │   └── RecommendationCollector.php    # Recommendation management
│   │
│   ├── Model/
│   │   └── Recommendation.php             # Recommendation model
│   │
│   ├── Service/
│   │   ├── AnalysisRunner.php             # NEW 1.0.4: single orchestration path (review, baseline, mcp)
│   │   ├── BaselineManager.php            # NEW 1.0.4: baseline storage + comparison
│   │   ├── HtmlReportRenderer.php         # NEW 1.0.4: interactive single-file HTML report
│   │   ├── SafeExec.php                   # NEW 1.0.4: external commands with timeouts
│   │   ├── SarifExporter.php              # NEW 1.0.4: SARIF 2.1.0 export
│   │   ├── StoreContext.php               # NEW 1.0.4: Hyvä/Fastly/mode/base URL/Redis detection
│   │   ├── ToolConfigLoader.php           # NEW 1.0.4: .m2perf.json loader
│   │   ├── AIRecommendationEngine.php     # AI / rule-based action plan
│   │   ├── BenchmarkRunner.php            # Benchmark execution
│   │   ├── CacheMetricsService.php        # Varnish/Redis metrics
│   │   ├── EnhancedConfigLoader.php       # Config with Magento precedence
│   │   ├── EnvironmentLoader.php          # Environment configuration
│   │   ├── InfrastructureGenerator.php    # IaC generation
│   │   ├── MagentoDbConnection.php        # Database connection
│   │   ├── MonitoringServer.php           # Real-time monitoring (ReactPHP)
│   │   ├── UpdateChecker.php              # Daily update check (interactive only)
│   │   ├── XmlReader.php                  # env.php / edition detection
│   │   ├── BaseScriptGenerator.php        # Fix-script base (quoting, headers)
│   │   ├── ConfigCommandGenerator.php     # fix_magento_config_*.sh
│   │   ├── DatabaseCommandGenerator.php   # fix_database_issues_*.sh
│   │   ├── FrontendCommandGenerator.php   # fix_frontend_performance_*.sh
│   │   ├── SecurityCommandGenerator.php   # Security hardening snippets
│   │   ├── SystemCommandGenerator.php     # fix_system_config_*.sh
│   │   └── VarnishCommandGenerator.php    # fix_varnish_config_*.sh
│   │
│   ├── Trait/
│   │   └── DevModeAwareTrait.php          # Developer-mode awareness
│   │
│   ├── Utils/
│   │   └── SocketChecker.php              # Network utilities
│   │
│   ├── Application.php                    # Console application
│   ├── ToolVersion.php                    # NEW 1.0.4: single version source
│   └── index.php                          # PHAR entry point
│
├── bin/
│   └── m2-performance.php                 # CLI executable (source install)
│
├── build-phar.php                         # PHAR build script (+ metadata, .sha256)
├── m2-performance.phar                    # Release PHAR
├── m2-performance.phar.sha256             # Release checksum (self-update)
├── composer.json                          # Dependencies
├── README.md                              # Documentation
├── CHANGELOG.md                           # Version history
├── ROADMAP.md                             # Roadmap flow
├── LICENSE                                # GPL-3.0 License
└── .gitignore                             # Git ignore rules
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

#### AnalysisRunner (1.0.4)
- Single orchestration path for `review`, `baseline` and `mcp`
- Profiles (built-in and `.m2perf.json` custom), analyzer subsets
- Developer-mode propagation, `--continue-on-error`, per-analyzer timings
- Weighted scoring shared by CLI, HTML, baselines and MCP

#### StoreContext (1.0.4)
- Hyvä / Hyvä Checkout and Fastly detection
- Magento mode and target store base URL (Magento config precedence)
- Redis-compatible cache backend detection (Redis, Valkey, L2 RemoteSynchronizedCache)

#### BaselineManager / SarifExporter / HtmlReportRenderer (1.0.4)
- Named baselines with profile/mode context and regression detection
- SARIF 2.1.0 for GitHub Code Scanning
- Interactive single-file HTML report

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

#### baseline (1.0.4)
```bash
./m2-performance.phar baseline --save=pre-release
./m2-performance.phar baseline --compare=pre-release --fail-on-regression
```
- Regression gate for CI/CD (also enforces `thresholds.min_score`)

#### mcp (1.0.4)
```bash
claude mcp add m2-performance -- php m2-performance.phar mcp --magento-root=/var/www/magento2
```
- JSON-RPC 2.0 over stdio, 7 tools for AI coding agents

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
- `--async` - Reserved (sequential until parallel workers land)
- `--watch` - Continuous monitoring
- `--profile` - Predefined and custom check sets
- `--priority` - Filter by minimum priority
- `--compare-to`, `--config`, `--continue-on-error` (1.0.4)
- Export formats: json, csv, html, markdown, sarif

#### Output Formats
- Table (default)
- JSON
- CSV
- HTML (interactive)
- Markdown
- SARIF 2.1.0

## Integration Points

### 1. **Existing Analyzers Enhanced**
- All analyzers run through AnalysisRunner
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