# Changelog

All notable changes to the Magento 2 Performance Review Tool will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.4.1] - 2026-11-22

Hotfix release for v1.0.4. Upgrade recommended for every store that uses Varnish as Full Page Cache.

### Fixed
- **Crash on stores with Varnish as Full Page Cache** - `review`, `baseline` and MCP `run_analysis` aborted with
  `ArgumentCountError: Too few arguments to function CacheAnalyzer::checkCacheWarmer()` whenever
  `system/full_page_cache/caching_application` resolved to `2` (Varnish). `--continue-on-error` runs reported the
  analyzer as failed instead. The cache-warmer check now receives the configured backend and runs as intended.
  - Regression vs the v1.0.3 download: the published v1.0.3 PHAR had been built from older sources than the
    tagged v1.0.3 code; v1.0.4 was the first PHAR built from the refactored `CacheAnalyzer` that carried the bug.
- Generated Varnish fix script: VCL backup filename is now quoted (ShellCheck SC2046).

### Technical Improvements
- Validation matrix extended with a Varnish / Luma (non-Fastly, non-Hyvä) store in Developer and Production mode.
- Version scheme note: `1.0.4.1` is a four-part hotfix version; `self-update` and the update check compare it
  correctly (`1.0.4` < `1.0.4.1` < `1.0.5`).

## [1.0.4] - 2026-11-21

Feature release plus a full validation pass of every command and export in Magento Developer
and Production mode (Adobe Commerce 2.4.8, PHP 8.4, Hyvä, Fastly, Valkey).

### Added
- **MCP server mode** (`mcp`) - JSON-RPC 2.0 over stdio for AI coding agents (Claude Code, Cursor, Copilot).
  Tools: `run_analysis`, `list_analyzers`, `get_recommendations`, `export_sarif`, `save_baseline`,
  `compare_baseline`, `list_baselines`. Diagnostics go to stderr; stdout stays protocol-clean.
- **Baselines & regression detection** (`baseline --save | --list | --compare`) with
  `--fail-on-regression` CI gate and `review --compare-to=<name>`. Baselines record profile and
  analysis mode (production-focused vs developer-aware) and warn on like-for-unlike comparisons.
- **SARIF 2.1.0 export** (`--export=sarif`) for GitHub Code Scanning.
- **Interactive single-file HTML report** - filter chips, search, sort, expandable findings, target store URL.
- **Tool configuration file** `.m2perf.json` (auto-discovered in Magento root / CWD, or `--config`):
  disabled analyzers, custom profiles, scoring weights, `thresholds.min_score` (enforced by
  `baseline --compare --fail-on-regression`).
- **StaticCodeAnalyzer** - N+1 patterns (per-item repository `get()` / loads in loops),
  `cache->save()` without TTL, int-typed parameters on VARCHAR-keyed concepts (index bypass risk).
- **StoreContext** - one-time detection of Hyvä / Hyvä Checkout, Fastly, Magento mode and the target
  store base URL, shared by analyzers, reports and the dashboard ("Target store: ...").
- **AnalysisRunner** - single orchestration path for `review`, `baseline` and `mcp`.
- **SafeExec** - timeouts around external commands (`find`, `sysctl`, `varnishstat`, DB probes).
- `--continue-on-error` - a failing analyzer is reported as a finding instead of aborting the run.
- `baseline --allow-dev-mode`, `cache:monitor --magento-root`, benchmark scenario validation.
- `build-phar.php` writes PHAR metadata (version, build date) and `m2-performance.phar.sha256`.

### Changed
- `review` now runs through `AnalysisRunner`: `--config` (disabled analyzers, custom profiles, weights),
  `--continue-on-error` and developer-mode awareness behave identically in CLI, baselines and MCP.
- **One scoring model everywhere** - the CLI dashboard, HTML report, baselines and MCP all use the
  weighted High=10 / Medium=4 / Low=1 penalty (configurable). Previously the dashboard used a different
  formula, so the same run showed different scores.
- `--areas` also accepts analyzer identifiers (e.g. `uptime`, `layout-cache`) and fails with exit code 2
  and the list of valid values when nothing matches (previously: silent "Perfect Score").
- Frontend checks are Hyvä-aware (no Luma minify/merge/bundle advice on Hyvä); Varnish checks are skipped
  when Fastly is detected; Varnish fix scripts are not generated for Fastly stores.
- Configuration precedence now matches Magento: `CONFIG__DEFAULT__*` env vars > `env.php` > `config.php` >
  database (the database used to win, reporting values that file/env overrides had replaced).
- Background update check removed; the daily check runs synchronously (3 s timeout) only in interactive
  sessions - never in CI, pipes, `-n` runs or MCP.
- `self-update`: version taken from the binary itself, SHA-256 verification against the published checksum,
  fallback to the tagged raw PHAR when a release has no PHAR asset, safe replace on Windows.
- `--async` is documented as reserved: execution is sequential until real parallelism lands.

### Fixed
- **Intermittent `Command "mcp" is not defined` / PHP parse errors inside the PHAR** - the forked background
  update check shared the PHAR file descriptor with the parent (~1 in 6 runs failed). Root-caused and removed.
- **Generated system fix script wrote `opcache.max_accelerated_files=0`** (PHP clamps 0 to the 200-file
  minimum - a severe regression on a 100k+ file codebase) and wrote `validate_timestamps=0` on
  developer-mode machines. Now uses the next OPcache prime covering the detected file count, emits the
  ini block once, never disables timestamp validation in developer mode, and leaves development tuning manual. The analyzer's
  "set to 0 for automatic" advice is corrected as well.
- Fix scripts: titles/values are single-quoted (a title containing `"` or `$` broke or expanded in bash),
  the generic frontend fallback no longer deletes `pub/static/_cache` for unrelated findings,
  indexer fixes reindex only the reported indexers, `nginx -t`/`apachectl` checks work under `set -e`.
- `self-update --check` reported "No PHAR file found in latest release" and treated the running version as
  `unknown` (which would have *downgraded* newer builds).
- Redis/Valkey: Magento 2.4.8 `Valkey` cache backend and the Adobe Commerce Cloud L2
  `RemoteSynchronizedCache` (Redis remote tier) were reported as "Configure Redis for cache storage" (High).
  Redis probes now use the configured host/port instead of `127.0.0.1:6379`.
- Adobe Commerce not detected for `magento/product-enterprise-edition` / `magento/magento-cloud-metapackage`
  installs (the Commerce analyzer never ran).
- Search engine check failed when the hostname contains a scheme (`http://...`), and read the database value
  instead of the `env.php` override.
- Target store URL now resolves `CONFIG__DEFAULT__WEB__SECURE__BASE_URL` and `host:port` DB hosts.
- `--watch` showed empty results on every refresh (new collector never reached the analyzers).
- `review --compare-to` reported a false REGRESSION in developer mode (developer-aware run vs
  production-focused baseline).
- `--export=<unsupported>` exited 0; it now exits 1 and lists supported formats.
- Non-TTY runs printed `TERM environment variable not set` (shelled out to `clear`); screen clearing is
  now TTY-only.
- Result table borders rendered with wrong corner characters (Symfony `setCrossingChars` argument order).
- Async progress bar showed a literal `%message%` placeholder; in non-ANSI output (CI logs, pipes) the
  progress bar printed one line per step and glued its last line to the results table - plain output
  now prints a single "Running N analyzers..." line.
- `benchmark`: `--url` and `--scenarios` were ignored (always `http://localhost`, all scenarios), failed
  requests were averaged as 0 ms and reported as "good", cache/database results rendered as empty tables,
  Redis benchmark ignored the configured endpoint, `<e>` style tags printed literally.
- `cache:monitor` ignored the Magento root (used CWD) and printed an empty screen when nothing was reachable;
  it now also reads the cache backend endpoint and no longer fatals when phpredis is not installed.
- `generate recommendations` claimed "AI-powered" output without an API key (now states rule-based fallback).
- PHP 8.4 deprecation: implicitly nullable parameter in `EnvironmentLoader`.
- `GenerateCommand` / `MonitoringServer` crashes on legacy `getMessage()` / `getDetail()` / `getAll()` calls.

### Removed
- `AsyncCheckRunner` (sequential placeholder that bypassed `--continue-on-error` and timings).
- Legacy static HTML export in the review command (superseded by the interactive report).

## [1.0.3] - 2025-11-24

### Added
- **Enhanced Fastly CDN Detection** - Multi-method detection system:
  - Composer.json package scanning
  - env.php configuration checking
  - HTTP header analysis (X-Served-By, X-Cache, Via headers)
  - Module configuration validation
  - Automatic adaptation of caching recommendations based on CDN type
- **Intelligent Caching Recommendations** - Context-aware suggestions:
  - Automatically detects Fastly vs Varnish setups
  - Provides CDN-specific optimization paths
  - Skips irrelevant recommendations based on detected infrastructure
- **Enhanced Configuration Loading** - More reliable configuration detection:
  - Uses EnhancedConfigLoader for better config precedence handling
  - Improved fallback mechanisms for missing configurations

### Changed
- **Refactored VarnishCommandGenerator** - Complete rewrite for better maintainability:
  - Modular command generation architecture
  - Dynamic script generation instead of hardcoded VCL snippets
  - Better error handling and status checking
  - More flexible command composition
- **Improved CacheAnalyzer** - Better separation of concerns:
  - Separated Fastly and Varnish detection logic
  - More granular recommendation generation
  - Cleaner code organization
- Increased total checks from 60+ to 65+
- Improved detection accuracy for edge caching solutions

### Fixed
- False positive Varnish recommendations when Fastly is present
- VarnishCommandGenerator script generation issues
- Configuration detection edge cases
- Redundant caching recommendations for CDN users

### Technical Improvements
- Better separation between CDN and on-premise caching logic
- More robust HTTP header parsing for CDN detection
- Improved code modularity in command generators
- Enhanced error handling in configuration detection

## [1.0.2] - 2025-07-24

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

## [1.0.1] - 2025-07-21

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

## [1.0.0] - 2025-07-20

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