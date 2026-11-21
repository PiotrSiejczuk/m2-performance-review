# Magento 2 Performance Review Tool v1.0.4

<div align="center">

![Magento 2 Performance Tool](https://img.shields.io/badge/Magento%202-Performance%20Tool-orange?style=for-the-badge&logo=magento)

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.1-8892BF?style=flat-square&logo=php)](https://php.net)
[![Magento](https://img.shields.io/badge/Magento-2.4.x-EC6E34?style=flat-square)](https://business.adobe.com/products/magento/magento-commerce.html)
[![License](https://img.shields.io/badge/License-GPL%20v3-blue?style=flat-square)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/PiotrSiejczuk/m2-performance-review?style=flat-square)](https://github.com/PiotrSiejczuk/m2-performance-review/releases)
[![Downloads](https://img.shields.io/github/downloads/PiotrSiejczuk/m2-performance-review/total?style=flat-square)](https://github.com/PiotrSiejczuk/m2-performance-review/releases)
[![GitHub Stars](https://img.shields.io/github/stars/PiotrSiejczuk/m2-performance-review?style=flat-square)](https://github.com/PiotrSiejczuk/m2-performance-review/stargazers)

### 📊 Tool Statistics

<table>
<tr>
<td align="center">
<strong>17</strong><br/>
Analyzers
</td>
<td align="center">
<strong>170+</strong><br/>
Finding Rules
</td>
<td align="center">
<strong>5</strong><br/>
Fix Generators
</td>
<td align="center">
<strong>5</strong><br/>
Export Formats
</td>
<td align="center">
<strong>7</strong><br/>
MCP Tools
</td>
<td align="center">
<strong>100%</strong><br/>
Open Source
</td>
</tr>
</table>

### 🎯 Performance Impact

![Issues Detected](https://img.shields.io/badge/Avg%20Issues%20Found-48-yellow?style=flat-square)
![Critical Issues](https://img.shields.io/badge/Critical%20Issues-~14-red?style=flat-square)
![Performance Gain](https://img.shields.io/badge/Avg%20Performance%20Gain-25--40%25-green?style=flat-square)
![Code Coverage](https://img.shields.io/badge/Code%20Areas%20Covered-17-blue?style=flat-square)

</div>

---

A comprehensive performance analysis and optimization tool for Magento 2 and Adobe Commerce installations with automated fix generation, intelligent CDN/theme detection, CI/CD regression gates and an MCP server for AI coding agents.

## 🆕 What's New in v1.0.4

- **🤖 MCP Server** - `mcp` command exposes analysis, findings, SARIF export and baselines to AI coding agents (Claude Code, Cursor, Copilot) over stdio
- **📉 Baselines & Regression Gates** - `baseline --save/--compare --fail-on-regression` and `review --compare-to` for CI/CD
- **🛡️ SARIF 2.1.0 Export** - findings straight into GitHub Code Scanning
- **📊 Interactive HTML Report** - filter, search, sort and expand findings in a single self-contained file
- **⚙️ `.m2perf.json` Configuration** - disable analyzers, define custom profiles, tune scoring weights, set a minimum score
- **🔍 StaticCodeAnalyzer** - N+1 loops, `cache->save()` without TTL, int-typed params on VARCHAR keys
- **🧭 Context Awareness** - Hyvä, Fastly, Valkey (2.4.8+), Adobe Commerce Cloud L2 cache and config precedence handled like Magento does
- **🩹 Reliability** - intermittent PHAR failures root-caused and fixed, safe fix scripts, one scoring model everywhere

[View Full Changelog →](CHANGELOG.md)

## 🏆 Why Use This Tool?

<table>
<tr>
<td>

### 🚀 Quick Wins
- **Fast Analysis**: Full scan in seconds, even on 150k+ file codebases
- **Actionable Results**: Automated fix scripts included
- **Priority-Based**: Focus on high-impact issues first

</td>
<td>

### 💡 Smart Detection
- **17 Specialized Analyzers**: Each targeting specific areas
- **170+ Finding Rules**: Configuration, code, infrastructure
- **File-Level Tracking**: Know exactly what to fix

</td>
</tr>
<tr>
<td>

### 🛡️ Production Ready
- **Non-Invasive**: Read-only analysis
- **Safe Scripts**: Quoted, reviewed-before-run fix scripts
- **CI/CD Friendly**: Exit codes, SARIF, baselines, `--continue-on-error`

</td>
<td>

### 📈 Measurable Impact
- **Weighted Scoring**: One score across CLI, HTML, baselines and MCP
- **Regression Detection**: Compare every release against a baseline
- **Export Reports**: Document improvements

</td>
</tr>
</table>

## 📦 Installation

### Option 1: Download Pre-built PHAR (Recommended)
```bash
# Download the release PHAR and its checksum
wget https://github.com/PiotrSiejczuk/m2-performance-review/raw/v1.0.4/m2-performance.phar
wget https://github.com/PiotrSiejczuk/m2-performance-review/raw/v1.0.4/m2-performance.phar.sha256

# Verify integrity
sha256sum -c m2-performance.phar.sha256

# Make executable and run from the Magento root
chmod +x m2-performance.phar
./m2-performance.phar review --magento-root=/var/www/magento2
```

Requirements: PHP 8.1+ CLI with `json`, `pdo`, `pdo_mysql` (optional: `redis`, `opcache`, `curl`).

### Option 2: Clone Repository
```bash
git clone https://github.com/PiotrSiejczuk/m2-performance-review.git
cd m2-performance-review
composer install
php bin/m2-performance.php review --magento-root=/var/www/magento2

# Build your own PHAR
composer dump-autoload --optimize --no-dev
php -d phar.readonly=0 build-phar.php   # -> bin/m2-performance.phar (+ .sha256)
```

## 🚀 Quick Start

`review` (alias of `m2:performance:analyze`) is the default command. The Magento root defaults to the current directory; use `--magento-root` to point elsewhere.

### Basic Analysis
```bash
# Comprehensive performance review
./m2-performance.phar review --magento-root=/var/www/magento2

# Only high priority findings, with explanations
./m2-performance.phar review --priority=high --verbose-explanation

# Generate fix scripts (always review before running)
./m2-performance.phar review --generate-fix

# Non-interactive run on a developer machine (developer-mode aware)
./m2-performance.phar review --allow-dev-mode -n
```

### Real-time Monitoring
```bash
# Cache metrics (Varnish + Redis/Valkey from env.php)
./m2-performance.phar cache:monitor --magento-root=/var/www/magento2

# Re-run the analysis every 5 seconds
./m2-performance.phar review --watch
```

### Self-Update
```bash
./m2-performance.phar self-update --check   # Check only
./m2-performance.phar self-update           # Update (SHA-256 verified)
./m2-performance.phar self-update --rollback
```

## 🤖 MCP Server for AI Coding Agents

Run the analyzer suite as an [MCP](https://modelcontextprotocol.io) server over stdio:

```bash
# Claude Code
claude mcp add m2-performance -- php /path/to/m2-performance.phar mcp --magento-root=/var/www/magento2
```

| Tool | Purpose |
|------|---------|
| `run_analysis` | Run a profile (`basic`, `full`, `security`) or analyzer subset; returns score, grade, counts, timings |
| `list_analyzers` | Available analyzer identifiers and Adobe Commerce detection |
| `get_recommendations` | Findings from the last run, filterable by `min_priority`, `area`, `limit` |
| `export_sarif` | Write the last run as SARIF 2.1.0 |
| `save_baseline` / `compare_baseline` / `list_baselines` | Regression tracking from inside the agent loop |

Typical agent loop: `run_analysis` → `get_recommendations(min_priority=high)` → fix → `run_analysis` → `compare_baseline`.

## 🔍 Analyzers Overview

| Analyzer | Area id | Focus Area |
|----------|---------|------------|
| **ConfigurationAnalyzer** | `config` | Core Magento settings, cache backends, optimization flags |
| **CacheAnalyzer** | `cache` | FPC, block cache, Varnish, **Fastly CDN detection** |
| **DatabaseAnalyzer** | `database` | Slow queries, indexes, foreign keys, SKU mismatches |
| **ModulesAnalyzer** | `modules` | Disabled modules, problematic extensions |
| **CodebaseAnalyzer** | `codebase` | Plugins, preferences, observers, codebase size |
| **StaticCodeAnalyzer** | `static-code` | N+1 patterns, cache writes without TTL, index-bypassing type mismatches |
| **FrontendAnalyzer** | `frontend` | Bundling, lazy loading, SVGs, third-party scripts (**Hyvä-aware**) |
| **IndexersAnalyzer** | `indexers` | Indexer modes/state, search engine connectivity |
| **OpCacheAnalyzer** | `opcache` | OPcache sizing, JIT, realpath cache |
| **RedisEnhancedAnalyzer** | `redis` | Redis/**Valkey** sessions & cache, L2 cache, persistence, memory |
| **APIRateLimitingAnalyzer** | `api-security` | API security, rate limiting configuration |
| **SecurityChecklistAnalyzer** | `security` | Permissions, exposed files, security headers, admin URL |
| **LayoutCacheAnalyzer** | `layout-cache` | `cacheable="false"` blocks, layout issues |
| **ServerUptimeAnalyzer** | `uptime` | System health, uptime |
| **HttpProtocolAnalyzer** | `protocol` | HTTP/2, HSTS, compression, keep-alive |
| **VarnishPerformanceAnalyzer** | `varnish` | Hit rates, ESI, grace mode (skipped when Fastly is detected) |
| **AdobeCommerceAnalyzer** | `commerce` | Adobe Commerce specific checks (EE / Cloud only) |

`--areas` accepts the ids above plus aliases: `caching` (cache, layout-cache, varnish), `security` (security, api-security), `codebase` (codebase, static-code), `indexing` (indexers), `static`.

## 📊 Performance Scoring System

One weighted model is used everywhere (CLI dashboard, HTML report, baselines, MCP):

1. **Penalty per finding**: High = 10, Medium = 4, Low = 1 (configurable in `.m2perf.json`)
2. **Score** = max(0, 100 - total penalty)
3. **Grade**: A+ (95+), A (90+), B (80+), C (70+), D (60+), F (<60)

> Large estates with many findings saturate at 0 / F. For trend tracking, rely on baseline comparisons (introduced vs resolved issues) - see the [Roadmap](ROADMAP.md) for the planned non-saturating score.

## 🛠️ Automated Fix Generation

`--generate-fix` writes reviewable bash scripts into the current directory:

| Script | Covers |
|--------|--------|
| `fix_magento_config_<ts>.sh` | `bin/magento config:set` commands extracted from findings |
| `fix_system_config_<ts>.sh` | OPcache ini tuning (root), web server config checks |
| `fix_database_issues_<ts>.sh` | Targeted reindex of reported indexers |
| `fix_frontend_performance_<ts>.sh` | Minify/bundling settings (Luma), manual guidance otherwise |
| `fix_varnish_config_<ts>.sh` | Varnish checks (never generated when Fastly is detected) |

`--generate-config` writes only the Magento configuration script.

```bash
./m2-performance.phar review --generate-fix
less fix_system_config_*.sh   # always review first
sudo bash fix_system_config_*.sh
```

## 📤 Export Formats

One format per run; files are written as `m2_performance_report_<timestamp>.<ext>`.

```bash
./m2-performance.phar review --export=json      # Full metadata for automation
./m2-performance.phar review --export=html      # Interactive report for stakeholders
./m2-performance.phar review --export=csv       # Spreadsheet analysis
./m2-performance.phar review --export=markdown  # Documentation
./m2-performance.phar review --export=sarif     # GitHub Code Scanning
```

## 🎯 Analysis Profiles

```bash
./m2-performance.phar review --profile=basic     # config, cache, redis, opcache, uptime, varnish
./m2-performance.phar review --profile=full      # all analyzers (default)
./m2-performance.phar review --profile=security  # security, api-security, uptime
./m2-performance.phar review --areas=cache,database,redis   # explicit subset
./m2-performance.phar review --profile=pre-release --config=.m2perf.json   # custom profile
```

## ⚙️ Configuration File (`.m2perf.json`)

Auto-discovered in the Magento root or current directory, or passed with `--config`:

```json
{
  "analyzers": { "disabled": ["uptime"] },
  "profiles": { "pre-release": ["config", "cache", "redis", "database", "static-code"] },
  "scoring": { "high_weight": 10, "medium_weight": 4, "low_weight": 1 },
  "thresholds": { "min_score": 70 }
}
```

## 🔁 Baselines & CI/CD Integration

```bash
# Capture the state before a release
./m2-performance.phar baseline --save=pre-release-2.4.8

# Compare later runs; exit 1 on regression or when score < thresholds.min_score
./m2-performance.phar baseline --compare=pre-release-2.4.8 --fail-on-regression

# Same comparison inside a normal review
./m2-performance.phar review --compare-to=pre-release-2.4.8

./m2-performance.phar baseline --list
```

Baselines are stored in `<magento-root>/var/m2-performance/baselines` (fallback `~/.m2-performance/baselines`). They record the profile and analysis mode; comparing a developer-aware run with a production-focused baseline prints a mismatch warning (use `--allow-dev-mode` on both sides for like-for-like).

```yaml
# GitHub Actions example
- name: Magento performance review
  run: |
    php m2-performance.phar review -n --continue-on-error --export=sarif
    php m2-performance.phar baseline -n --compare=main --fail-on-regression
- name: Upload SARIF
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: .
```

## ⏱️ Benchmark

```bash
./m2-performance.phar benchmark --url=https://store.example.com \
  --scenarios=homepage,search,cache,database --export=bench.json --format=json
```

Scenarios: `homepage`, `category`, `product`, `search`, `api`, `admin`, `cache`, `database`. The URL defaults to the store's configured base URL. Unreachable or non-2xx/3xx pages are reported as failed, not as fast.

## 🧰 Other Commands

```bash
./m2-performance.phar generate infra -o infra.tf          # Terraform/Ansible/Docker templates
./m2-performance.phar generate report -o report.html      # Standalone interactive report
./m2-performance.phar generate recommendations            # Action plan (AI with --ai-key / OPENAI_API_KEY, rule-based otherwise)
./m2-performance.phar monitor --port=8080                 # Web dashboard - source install with ReactPHP only
```

## 🐳 Docker Usage

Run the PHAR inside the container that already runs Magento's PHP (it needs `pdo_mysql` and network access to MySQL/Redis):

```bash
# Copy into the PHP container and run from the Magento root
docker cp m2-performance.phar <php-container>:/var/www/html/
docker exec -it <php-container> php m2-performance.phar review --magento-root=/var/www/html

# docker compose / markshust/docker-magento style wrappers
docker compose exec phpfpm php m2-performance.phar review -n --allow-dev-mode
```

An official image is on the [Roadmap](ROADMAP.md).

## 🔧 Advanced Features

### Developer Mode Awareness
In developer mode the tool asks whether to run a developer-mode aware analysis (or use `--allow-dev-mode` / `-n`):
- Skips production-only optimizations in dev mode
- Provides environment-specific suggestions
- `MAGE_MODE` environment variable overrides `env.php`, exactly like Magento

### Resilient Runs
`--continue-on-error` turns a failing analyzer (e.g. Redis or MySQL unreachable) into a finding instead of aborting the run.

### Execution Notes
- `--async` is reserved: analyzers currently run sequentially (parallel execution is on the roadmap).
- Update checks run at most once a day, only in interactive sessions (never in CI, pipes or MCP).

## 📈 Performance Metrics

### Typical Issues Found
- **Configuration**: 10-15 suboptimal settings
- **Caching**: 3-5 cache configuration issues
- **Database**: 5-10 index/query problems
- **Frontend**: 8-12 optimization opportunities
- **Security**: 2-4 security recommendations

### Expected Improvements
After implementing recommended fixes:
- **Page Load Time**: 20-35% reduction
- **Server Response Time**: 15-25% improvement
- **Database Query Time**: 30-40% optimization
- **Cache Hit Rate**: 10-20% increase

## 🧪 Testing & Compatibility

### Tested Environments
- **PHP**: 8.1 - 8.4
- **Magento / Adobe Commerce**: 2.4.x, validated on 2.4.8 (Cloud & on-premise layouts)
- **Themes**: Luma, Hyvä (incl. Hyvä Checkout)
- **Cache**: Redis 6.x/7.x, Valkey (2.4.8+), L2 `RemoteSynchronizedCache`, Varnish 6.x/7.x, **Fastly CDN**
- **Search**: OpenSearch, Elasticsearch
- **OS**: Ubuntu 20.04/22.04/24.04, RHEL 8/9, macOS, Docker / WSL2

## 🤝 Contributing

We welcome contributions! Areas of focus:

1. **New Analyzers**: GraphQL, PWA, B2B modules
2. **Cloud Platforms**: AWS, GCP, Azure specific checks
3. **Fix Generators**: Additional automated fixes
4. **Integrations**: Monitoring tools, APM services

Open an issue or pull request on GitHub.

## 📚 Documentation

- [Changelog](CHANGELOG.md) - Detailed version history
- [Roadmap](ROADMAP.md) - Future development plans
- [Project Structure](PROJECT_STRUCTURE.md) - Code layout and extension points

## 📄 License

GPL v3 License - see [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Magento/Adobe Commerce Community
- Contributors and testers
- Performance optimization experts who provided feedback

## 📧 Contact & Support

- **GitHub Issues**: Bug reports and feature requests
- **Email**: piotr.siejczuk@gmail.com
- **LinkedIn**: [Piotr Siejczuk](https://www.linkedin.com/in/piotrsiejczuk/)

---

<div align="center">

**Made with ❤️ for the Magento Community**

If you find this tool helpful, please ⭐ star the repository!

</div>
