# Changelog

All notable changes to the Magento 2 Performance Review Tool will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2024-01-24

### Added
- **VarnishPerformanceAnalyzer** - Comprehensive Varnish cache analysis including:
  - Cache hit/bypass rate detection
  - ESI (Edge Side Includes) implementation validation
  - Grace mode configuration checks
  - Session cleanup detection for anonymous users
  - VCL configuration analysis
  - X-Magento-Vary cookie behavior validation
- **Self-Update Feature** - Built-in update mechanism similar to n98-magerun2:
  - Automatic daily update checks
  - Update notifications in main command output
  - Support for stable/beta/dev channels
  - Rollback capability
  - Behind-proxy support
- **CacheMonitorCommand** - Real-time cache performance monitoring
- **Enhanced OpCacheAnalyzer** - PHP 8.3 JIT optimization detection:
  - JIT compilation status and configuration
  - Optimal JIT settings for eCommerce workloads (opcache.jit=1205)
  - JIT buffer size recommendations
  - Enhanced realpath cache settings for PHP 8.3
- **Enhanced RedisEnhancedAnalyzer** - L2 cache architecture detection:
  - Two-tier caching (Redis + local file system)
  - RAM disk usage detection for local cache
  - Compression settings optimization
- **CacheMetricsService** - Backend service for cache performance metrics
- **VarnishCommandGenerator** - Automated Varnish fix script generation
- **UpdateChecker** - Background update checking service

### Changed
- Increased total analyzers from 14 to 15
- Increased total checks from 50+ to 60+
- Increased fix generators from 4 to 5
- Updated code coverage areas from 12 to 13
- Enhanced error handling in Application.php for missing commands
- Improved PHAR building process for better compatibility

### Fixed
- PHAR execution compatibility issues
- Command registration error handling
- Export functionality when using `--export` without format

## [1.0.1] - 2024-01-21

### Added
- **Advanced Frontend Head Analysis** - Database-level scanning for heavy CSS/JS in head configuration across all scopes (Global, Website, Store)
- **Elasticsearch/OpenSearch Configuration Validator** - Validates critical settings to prevent DevOps misconfigurations:
  - `index.max_result_window` validation
  - `index.mapping.total_fields.limit` checks
  - `indices.query.bool.max_clause_count` verification
- **Improved Search Engine Detection** - Better OpenSearch detection with multiple fallback mechanisms:
  - Multiple configuration path checks
  - env.php fallback detection
  - API-based engine type verification
- **Enhanced HTML Export** - Fixed format specifier issues for reliable report generation

### Fixed
- PHAR execution issues on CentOS/RHEL systems - proper shebang line handling
- HTML export "Unknown format specifier" error when CSS contains semicolons
- OpenSearch detection when using non-standard configuration paths
- Configuration drift detection for search engines

### Changed
- Improved error handling and fallback mechanisms across all analyzers
- Enhanced developer mode awareness prompts
- Better handling of missing configuration files

## [1.0.0] - 2024-01-17

### Added
- Initial release with 14 specialized analyzers:
  - **ConfigurationAnalyzer** - Reviews 50+ critical Magento settings
  - **CacheAnalyzer** - Full page cache and block cache analysis
  - **DatabaseAnalyzer** - Slow queries, indexes, SKU mismatches
  - **FrontendAnalyzer** - Bundle sizes, HTTP/2 optimization, Core Web Vitals
  - **SecurityChecklistAnalyzer** - File permissions, exposed files, security headers
  - **CodebaseAnalyzer** - Large files, code quality issues
  - **ModulesAnalyzer** - Disabled modules, problematic extensions
  - **LayoutCacheAnalyzer** - Detects cacheable="false" issues
  - **HttpProtocolAnalyzer** - HTTP/2, HSTS, compression
  - **APIRateLimitingAnalyzer** - API security configuration
  - **OpCacheAnalyzer** - PHP OPcache optimization
  - **RedisEnhancedAnalyzer** - Redis configuration and performance
  - **ServerUptimeAnalyzer** - System health monitoring
  - **IndexersAnalyzer** - Indexer configuration and search engine setup
- **Automated Fix Generation** - Generates executable shell scripts:
  - Magento configuration commands
  - System optimization scripts
  - Database optimization queries
  - Frontend cleanup helpers
- **Multiple Export Formats**:
  - JSON with full metadata
  - CSV for spreadsheet analysis
  - HTML with styled reports
  - Markdown for documentation
- **Developer Mode Awareness** - Adjusts recommendations based on environment
- **Performance Scoring System** - Letter grades (A+ to F) with numerical scores
- **Async Execution Mode** - Parallel analyzer execution for faster results
- **Watch Mode** - Continuous monitoring with 5-second refresh
- **Profile Support** - Basic, Full, and Security analysis profiles

### Technical Features
- File-level tracking for all issues
- Priority-based recommendations (High/Medium/Low)
- Detailed explanations for each issue
- Industry benchmark comparisons
- Real-time performance dashboard
- System resource monitoring

## Version Numbering

This project uses Semantic Versioning:
- **Major version (1.x.x)**: Incompatible API changes
- **Minor version (x.1.x)**: New functionality in a backwards compatible manner
- **Patch version (x.x.1)**: Backwards compatible bug fixes

## Unreleased

### Planned for Future Releases
- CloudInfrastructureAnalyzer for Adobe Commerce Cloud
- GraphQLAnalyzer for headless implementations
- MessageQueueAnalyzer for RabbitMQ/MySQL queues
- DeploymentAnalyzer for CI/CD pipeline validation
- IntegrationAnalyzer for third-party services
- B2B-specific performance analysis
- PWA/Headless commerce optimization
- AI-driven recommendations
- Multi-store performance comparison
- Historical performance tracking