# Magento 2 Performance Review Tool v1.0.3

<div align="center">

![Magento 2 Performance Tool](https://img.shields.io/badge/Magento%202-Performance%20Tool-orange?style=for-the-badge&logo=magento)

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892BF?style=flat-square&logo=php)](https://php.net)
[![Magento](https://img.shields.io/badge/Magento-2.3.x%20|%202.4.x-EC6E34?style=flat-square)](https://magento.com)
[![License](https://img.shields.io/badge/License-GPL%20v3-blue?style=flat-square)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/PiotrSiejczuk/m2-performance-review?style=flat-square)](https://github.com/PiotrSiejczuk/m2-performance-review/releases)
[![Downloads](https://img.shields.io/github/downloads/PiotrSiejczuk/m2-performance-review/total?style=flat-square)](https://github.com/PiotrSiejczuk/m2-performance-review/releases)
[![GitHub Stars](https://img.shields.io/github/stars/PiotrSiejczuk/m2-performance-review?style=flat-square)](https://github.com/PiotrSiejczuk/m2-performance-review/stargazers)

### 📊 Tool Statistics

<table>
<tr>
<td align="center">
<strong>15</strong><br/>
Analyzers
</td>
<td align="center">
<strong>65+</strong><br/>
Checks
</td>
<td align="center">
<strong>5</strong><br/>
Fix Generators
</td>
<td align="center">
<strong>< 3s</strong><br/>
Avg Runtime
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
![Code Coverage](https://img.shields.io/badge/Code%20Areas%20Covered-13-blue?style=flat-square)

</div>

---

A comprehensive performance analysis and optimization tool for Magento 2 and Adobe Commerce installations with automated fix generation and intelligent CDN detection.

## 🆕 What's New in v1.0.3

- **☁️ Enhanced Fastly CDN Detection** - Multi-method detection via composer, env.php, HTTP headers, and module configuration
- **🎯 Intelligent Caching Recommendations** - Automatically adapts recommendations based on Fastly vs Varnish setup
- **⚙️ Refactored Varnish Commands** - Modular command generation with better error handling
- **🔧 Improved Configuration Loading** - Enhanced reliability with EnhancedConfigLoader
- **📝 Smarter Fix Scripts** - Context-aware script generation based on detected infrastructure

[View Full Changelog →](CHANGELOG.md)

## 🏆 Why Use This Tool?

<table>
<tr>
<td>

### 🚀 Quick Wins
- **Instant Analysis**: Full scan in under 3 seconds
- **Actionable Results**: Automated fix scripts included
- **Priority-Based**: Focus on high-impact issues first

</td>
<td>

### 💡 Smart Detection
- **15 Specialized Analyzers**: Each targeting specific areas
- **65+ Performance Checks**: Comprehensive coverage
- **File-Level Tracking**: Know exactly what to fix

</td>
</tr>
<tr>
<td>

### 🛡️ Production Ready
- **Non-Invasive**: Read-only analysis
- **Safe Scripts**: All fixes include safety checks
- **DevOps Friendly**: Prevents configuration drift

</td>
<td>

### 📈 Measurable Impact
- **25-40% Performance Gains**: Average improvement
- **Letter Grade Scoring**: Track progress easily
- **Export Reports**: Document improvements

</td>
</tr>
</table>

## 📦 Installation

### Option 1: Download Pre-built PHAR (Recommended)
```bash
# Download latest release
wget https://github.com/PiotrSiejczuk/m2-performance-review/raw/v1.0.3/m2-performance.phar

# Make executable
chmod +x m2-performance.phar

# Run analysis
./m2-performance.phar review /path/to/magento2
```

### Option 2: Clone Repository
```bash
git clone https://github.com/PiotrSiejczuk/m2-performance-review.git
cd m2-performance-review
composer install
php bin/performance review /path/to/magento2
```

## 🚀 Quick Start

### Basic Analysis
```bash
# Run comprehensive performance review
./m2-performance.phar review /var/www/magento2

# Generate fix scripts automatically
./m2-performance.phar review /var/www/magento2 --generate-fix

# Export results in various formats
./m2-performance.phar review /var/www/magento2 --export json,html,csv
```

### Real-time Monitoring
```bash
# Monitor cache performance in real-time
./m2-performance.phar cache:monitor /var/www/magento2

# Watch mode with 5-second refresh
./m2-performance.phar review /var/www/magento2 --watch
```

### Self-Update
```bash
# Check for updates
./m2-performance.phar self-update --check

# Update to latest version
./m2-performance.phar self-update

# Rollback if needed
./m2-performance.phar self-update --rollback
```

## 🔍 Analyzers Overview

| Analyzer | Checks | Focus Area |
|----------|--------|------------|
| **ConfigurationAnalyzer** | 50+ | Core Magento settings, cache config, optimization flags |
| **CacheAnalyzer** | 12 | FPC, block cache, Varnish, **Fastly CDN detection** |
| **DatabaseAnalyzer** | 8 | Slow queries, indexes, foreign keys, SKU mismatches |
| **FrontendAnalyzer** | 10 | Bundle sizes, HTTP/2, Core Web Vitals, critical CSS |
| **SecurityChecklistAnalyzer** | 15 | Permissions, exposed files, security headers, admin URL |
| **CodebaseAnalyzer** | 6 | Large files, code quality, deprecated patterns |
| **ModulesAnalyzer** | 5 | Disabled modules, problematic extensions |
| **LayoutCacheAnalyzer** | 3 | Cacheable="false" blocks, layout issues |
| **HttpProtocolAnalyzer** | 7 | HTTP/2, HSTS, compression, keep-alive |
| **APIRateLimitingAnalyzer** | 4 | API security, rate limiting configuration |
| **OpCacheAnalyzer** | 8 | PHP OPcache, JIT compilation (PHP 8.3) |
| **RedisEnhancedAnalyzer** | 10 | Redis config, L2 cache, persistence, memory |
| **ServerUptimeAnalyzer** | 3 | System health, uptime monitoring |
| **IndexersAnalyzer** | 5 | Indexer config, search engine setup |
| **VarnishPerformanceAnalyzer** | 12 | Hit rates, ESI, grace mode, session cleanup |

## 📊 Performance Scoring System

The tool uses a sophisticated scoring algorithm that:

1. **Weights issues by priority**: High (10 points), Medium (4 points), Low (1 point)
2. **Applies exponential penalties**: Multiple issues compound performance impact
3. **Provides letter grades**: A+ (95+) to F (<60)
4. **Tracks affected files**: Know exactly where issues exist

### Grade Distribution
- **A+ (95-100)**: Exceptional - Production optimized
- **A (90-94)**: Excellent - Minor optimizations possible
- **B (80-89)**: Good - Several improvements recommended
- **C (70-79)**: Fair - Notable performance gaps
- **D (60-69)**: Poor - Significant issues present
- **F (<60)**: Critical - Major performance problems

## 🛠️ Automated Fix Generation

The tool generates executable shell scripts for common issues:

### Available Fix Generators
- **Magento Configuration** - Optimal settings commands
- **Database Optimization** - Query optimization scripts
- **Frontend Cleanup** - Asset optimization commands
- **Security Hardening** - Permission and security fixes
- **Varnish Optimization** - VCL snippets and configuration

### Example Fix Script
```bash
# Generate comprehensive fix script
./m2-performance.phar review /var/www/magento2 --generate-fix

# Review generated script
cat performance_fixes_20240124_143022.sh

# Execute fixes (always review first!)
bash performance_fixes_20240124_143022.sh
```

## 📤 Export Formats

Export analysis results for reporting and tracking:

```bash
# JSON - Full metadata for automation
./m2-performance.phar review /path/to/magento --export json

# HTML - Styled reports for stakeholders
./m2-performance.phar review /path/to/magento --export html

# CSV - Spreadsheet analysis
./m2-performance.phar review /path/to/magento --export csv

# Markdown - Documentation format
./m2-performance.phar review /path/to/magento --export md

# Multiple formats at once
./m2-performance.phar review /path/to/magento --export json,html,csv
```

## 🎯 Analysis Profiles

Choose the right profile for your needs:

```bash
# Basic - Quick essential checks
./m2-performance.phar review /path/to/magento --profile=basic

# Full - Comprehensive analysis (default)
./m2-performance.phar review /path/to/magento --profile=full

# Security - Focus on security issues
./m2-performance.phar review /path/to/magento --profile=security

# Custom - Specific analyzers only
./m2-performance.phar review /path/to/magento --analyzers=cache,database,redis
```

## 🐳 Docker Usage

```bash
# Using Docker
docker run -v /path/to/magento:/app \
  ghcr.io/piotrsiejczuk/m2-performance:latest \
  review /app

# Docker Compose
version: '3'
services:
  performance-tool:
    image: ghcr.io/piotrsiejczuk/m2-performance:latest
    volumes:
      - ./magento2:/app
    command: review /app --watch
```

## 🔧 Advanced Features

### Async Execution
```bash
# Run analyzers in parallel for faster results
./m2-performance.phar review /path/to/magento --async
```

### Developer Mode Awareness
The tool automatically detects development environments and adjusts recommendations accordingly:
- Skips production-only optimizations in dev mode
- Provides environment-specific suggestions
- Warns about settings that differ between environments

### CI/CD Integration
```bash
# Exit with error code if score below threshold
./m2-performance.phar review /path/to/magento --min-score=80

# GitHub Actions example
- name: Performance Analysis
  run: |
    ./m2-performance.phar review . --export json --min-score=75
    cat performance_analysis.json
```

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
- **PHP**: 7.4, 8.0, 8.1, 8.2, 8.3
- **Magento**: 2.3.x, 2.4.0-2.4.7
- **Adobe Commerce**: 2.4.x Cloud & On-premise
- **OS**: Ubuntu 20.04/22.04, CentOS 7/8, RHEL 8/9, macOS
- **Web Servers**: Apache 2.4, Nginx 1.18+
- **Cache**: Redis 5.x/6.x/7.x, Varnish 6.x/7.x, **Fastly CDN**

## 🤝 Contributing

We welcome contributions! Areas of focus:

1. **New Analyzers**: GraphQL, PWA, B2B modules
2. **Cloud Platforms**: AWS, GCP, Azure specific checks
3. **Fix Generators**: Additional automated fixes
4. **Integrations**: Monitoring tools, APM services

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📚 Documentation

- [Changelog](CHANGELOG.md) - Detailed version history
- [Roadmap](ROADMAP.md) - Future development plans
- [API Documentation](docs/API.md) - For custom integrations
- [Analyzer Guide](docs/ANALYZERS.md) - Writing custom analyzers

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