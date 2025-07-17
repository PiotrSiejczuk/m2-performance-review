# Magento 2 Performance Review Tool v1.0.0

A comprehensive performance analysis and optimization tool for Magento 2 and Adobe Commerce installations with automated fix generation.

## 🚀 Features

### Core Analysis Capabilities
- **Configuration Analysis** - Reviews 50+ critical Magento settings
- **Cache Optimization** - Analyzes Redis, Varnish, OPcache configurations
- **Database Performance** - Detects slow queries, missing indexes, SKU type mismatches
- **Frontend Optimization** - HTTP/2 bundling strategies, Core Web Vitals improvements
- **Security Auditing** - Comprehensive security checks including file permissions
- **Code Quality** - Identifies performance anti-patterns and large files
- **Module Analysis** - Detects disabled and problematic modules
- **Layout Cache Analysis** - Finds `cacheable="false"` breaking FPC
- **HTTP Protocol Analysis** - HTTP/2, HSTS, compression checks
- **API Rate Limiting** - Validates rate limiting configurations
- **Server Uptime Monitoring** - System health and resource usage

### Enhanced Features (v1.2)
- **🎯 Automated Fix Generation** - Generates executable shell scripts for issues
- **📁 File Tracking** - Complete file lists for all identified issues
- **🖥️ Clear Screen UI** - Clean terminal output with ASCII logo
- **👨‍💻 Developer Mode Awareness** - Adjusts recommendations for dev environments
- **📊 Enhanced Exports** - Full file lists in JSON, CSV, HTML, and Markdown
- **📈 Performance Scoring** - Letter grades (A+ to F) with numerical scores
- **⚡ Async Execution** - Parallel analyzer execution for faster results
- **🔧 Multiple Fix Generators**:
    - Magento configuration scripts
    - System-level optimization scripts
    - Database optimization scripts
    - Frontend cleanup scripts with helpers

## 📦 Installation

### Option 1: PHAR (Recommended)
```bash
wget https://github.com/PiotrSiejczuk/m2-performance-review/releases/download/v1.0.0/m2-performance.phar
chmod +x m2-performance.phar
```

### Option 2: Build from Source
```bash
git clone https://github.com/PiotrSiejczuk/m2-performance-review.git
cd m2-performance-review
composer install
php -d phar.readonly=0 build-phar.php
```

## 🔧 Usage

### ⚠️ Important: Run from Magento Root Directory

The tool must be executed from your Magento installation's root directory (where `app/etc/env.php` is located).

```bash
# Navigate to your Magento root first
cd /path/to/your/magento

# Then run the tool
./m2-performance.phar
```

### Basic Analysis
```bash
# Run full performance review (default command)
./m2-performance.phar

# Or explicitly specify the command
./m2-performance.phar m2:performance:analyze

# With developer mode prompt (if in developer mode)
./m2-performance.phar
# Prompts: Continue with Developer Mode aware analysis? [y/N]

# Skip developer mode prompt
./m2-performance.phar --allow-dev-mode
```

### Command Options
```bash
--areas                Comma-separated areas to analyze (cache,database,frontend,etc)
--export               Export format: json, csv, html, markdown
--priority             Filter by priority: high, medium, low
--generate-fix         Generate automated fix scripts
--generate-config      Generate Magento configuration commands
--allow-dev-mode       Enable developer mode awareness without prompt
--summary              Show only summary statistics
--verbose-explanation  Show detailed technical explanations
--async                Run analyzers asynchronously for faster execution
--watch                Continuous monitoring mode
--profile              Analysis profile: basic, full, security (default: full)
--magento-root         Path to Magento root (if not running from there)
```

### Example Commands

#### Generate Fix Scripts
```bash
# Analyze and generate all applicable fix scripts
./m2-performance.phar --generate-fix

# This generates multiple scripts:
# - fix_magento_config_*.sh     (Magento configurations)
# - fix_system_config_*.sh      (OPcache, sysctl, web server)
# - fix_database_issues_*.sh    (Database optimizations)
# - fix_frontend_issues_*.sh    (Frontend improvements)
```

#### Export with Full File Lists
```bash
# Export to JSON with complete file lists
./m2-performance.phar --export=json

# Generate HTML report
./m2-performance.phar --export=html

# Markdown report for documentation
./m2-performance.phar --export=markdown
```

#### Targeted Analysis
```bash
# Frontend and caching issues only
./m2-performance.phar --areas=frontend,caching

# High priority issues with fixes
./m2-performance.phar --priority=high --generate-fix

# Developer mode with explanations
./m2-performance.phar --allow-dev-mode --verbose-explanation

# Quick analysis with basic profile
./m2-performance.phar --profile=basic

# Security-focused analysis
./m2-performance.phar --profile=security

# Async execution for faster results
./m2-performance.phar --async
```

#### Watch Mode
```bash
# Continuous monitoring (updates every 5 seconds)
./m2-performance.phar --watch
```

## 📊 Understanding Results

### Output Format
```
    🚀 Magento 2 Performance Review Tool v1.0 🚀
  __  __ ____    ____            __                                           
 |  \/  |___ \  |  _ \ ___ _ __ / _| ___  _ __ _ __ ___   __ _ _ __   ___ ___ 
 | |\/| | __) | | |_) / _ \ '__| |_ / _ \| '__| '_ ` _ \ / _` | '_ \ / __/ _ \
 | |  | |/ __/  |  __/  __/ |  |  _| (_) | |  | | | | | | (_| | | | | (_|  __/
 |_|  |_|_____| |_|   \___|_|  |_|  \___/|_|  |_| |_| |_|\__,_|_| |_|\___\___|
                           by Piotr Siejczuk: https://github.com/PiotrSiejczuk

⚠️  Developer Mode Detected ⚠️  (if applicable)

╔═════════════╤══════════╤════════════════════════════════════════╤══════════════════════════════════════════════════╗
║    Area     │ Priority │             Recommendation             │                     Details                      ║
╠═════════════╪══════════╪════════════════════════════════════════╪══════════════════════════════════════════════════╣
║ frontend    │    🔴    │ Replace fake SVGs with real vector     │ Found 3 SVG files containing base64-encoded      ║
║             │   High   │ graphics                               │ raster images. This increases file size by ~33%. ║
║             │          │                                        │                                                  ║
║             │          │                                        │ 💡 Full list of 3 files available with --export  ║
╟─────────────┼──────────┼────────────────────────────────────────┼──────────────────────────────────────────────────╢
║ caching     │    🔴    │ Critical: cacheable="false" in         │ ⚠️  CRITICAL: Using cacheable="false" in         ║
║             │   High   │ default.xml                            │ default.xml DISABLES CACHING FOR ALL PAGES!      ║
║             │          │                                        │                                                  ║
║             │          │                                        │ 💡 Full list of 5 files available with --export  ║
╚═════════════╧══════════╧════════════════════════════════════════╧══════════════════════════════════════════════════╝

📊 Dashboard Statistics:
┌─────────────────────────────────────┬──────────────────────────────┬─────────────────────────────┐
│ System Information                  │ Performance Score & Issues   │ Execution Statistics        │
├─────────────────────────────────────┼──────────────────────────────┼─────────────────────────────┤
│ Server Uptime: 0 days, 10 hours     │ Performance Score: 87.5/100  │ Analyzers: 13/14 Executed   │
│ Load Avg (1m): 0.41 [Normal]        │ Total Issues: 48             │ Total Time: 2.31 s          │
│ Memory Usage: 26.8% [Normal]        │ 🔴 High: 14                  │ Top Slowest:                │
│                                     │ 🟡 Medium: 25                │   Modules: 685 ms           │
│                                     │ 🟢 Low: 9                    │   Indexers: 627 ms          │
│                                     │ Mode: Production             │   Database: 581 ms          │
└─────────────────────────────────────┴──────────────────────────────┴─────────────────────────────┘
```

### Performance Score
- **A+ (95-100)**: Exceptional optimization
- **A (90-94)**: Excellent configuration
- **B (75-89)**: Good with room for improvement
- **C (60-74)**: Significant optimization opportunities
- **D (45-59)**: Major performance issues
- **F (0-44)**: Critical problems requiring immediate attention

### Priority Levels
- 🔴 **High**: Critical issues impacting performance/security (8 points each)
- 🟡 **Medium**: Important optimizations with moderate impact (3 points each)
- 🟢 **Low**: Minor improvements and best practices (0.5 points each)

### Available Areas for Analysis
- `cache` / `caching` - Cache configuration and layout cache
- `database` - Database performance and queries
- `frontend` - Frontend optimization and assets
- `modules` - Module analysis
- `security` - Security and API rate limiting
- `config` - Magento configuration
- `opcache` - PHP OPcache settings
- `redis` - Redis configuration
- `indexing` / `indexers` - Indexer settings
- `codebase` - Code quality analysis
- `protocol` - HTTP protocol optimization
- `commerce` - Adobe Commerce specific (if applicable)

## 🛠️ Generated Fix Scripts

### 1. Magento Configuration Script (`fix_magento_config_*.sh`)
Handles:
- Cache settings (HTML/JS/CSS minification)
- Session configuration (Redis)
- Async operations (emails, grid indexing)
- Security settings (HTTPS, HSTS)
- Search engine configuration
- Production mode switching

### 2. System Configuration Script (`fix_system_config_*.sh`)
Handles:
- OPcache settings (memory, file validation)
- Kernel parameters (sysctl optimizations)
- Web server configs (HTTP/2, Brotli, HSTS)
- Security headers
- API rate limiting

### 3. Database Optimization Script (`fix_database_issues_*.sh`)
Handles:
- Database profiler disabling
- Large table optimization
- SKU type mismatch fixes
- Indexer mode configuration
- Deadlock investigation

### 4. Frontend Optimization Script (`fix_frontend_issues_*.sh`)
Handles:
- File lists for manual review
- Helper scripts generation:
    - `find_fake_svgs.sh` - Locate fake SVG files
    - `find_cacheable_false.sh` - Find problematic layouts
    - `analyze_bundles.sh` - Check bundle sizes
- Bundle optimization commands
- Manual fix instructions

## 🎯 Key Issues Detected

### Frontend Performance
- **Fake SVGs**: Base64-encoded images inside SVG files (33% overhead)
- **Large Bundles**: JavaScript/CSS files >200KB causing render blocking
- **Default.xml Assets**: CSS/JS loaded on every page unnecessarily
- **Inline Base64**: Images embedded in HTML/CSS preventing caching

### Caching Issues
- **cacheable="false"**: Disables Full Page Cache on affected pages
- **Default.xml Impact**: Using cacheable="false" in default.xml breaks ALL pages

### Code Quality
- **Large PHP Files**: Files >50KB impacting autoloading
- **Disabled Modules**: Still present in vendor/ affecting deployment
- **Excessive Observers/Plugins**: Performance overhead from interceptors

### Security
- **File Permissions**: Incorrect ownership or permissions
- **Public Files**: Sensitive files accessible via web
- **Backup Files**: Database dumps in public directory

## 📋 Export Formats

### JSON Export
```json
{
  "area": "frontend",
  "priority": 3,
  "priority_label": "High",
  "title": "Replace fake SVGs with real vector graphics",
  "details": "Found 3 SVG files containing base64-encoded raster images...",
  "explanation": "Base64 encoding adds 33% overhead...",
  "affected_files": [
    "/app/design/frontend/Vendor/theme/web/images/logo.svg",
    "/app/design/frontend/Vendor/theme/web/images/icon.svg",
    "/app/design/frontend/Vendor/theme/web/images/banner.svg"
  ],
  "metadata": {
    "total_fake_svgs": 3,
    "examples": ["logo.svg (15.2KB)", "icon.svg (8.7KB)"],
    "overhead_percentage": 33
  }
}
```

### HTML Export
Generates a formatted HTML report with:
- Styled tables
- Color-coded priorities
- Expandable file lists
- Print-friendly layout

### Markdown Export
Creates documentation-ready reports:
- Grouped by area
- Includes all file paths
- Metadata in code blocks
- GitHub-compatible formatting

## 🔒 Security Considerations

The tool requires read access to:
- Magento file system
- Database configuration (env.php)
- Web server configurations (when checking protocols)

Generated scripts include:
- Safety checks (Magento root verification)
- Backup reminders
- Non-destructive operations by default
- Clear warnings for data-modifying commands

## 🚀 Performance Tips

1. **Run from Magento root** - Always execute from your Magento installation directory
2. **Use --areas** to focus on specific concerns
3. **Run --generate-fix** to get immediate action items
4. **Export to JSON** for programmatic processing
5. **Use --priority=high** to tackle critical issues first
6. **Enable --allow-dev-mode** in development environments
7. **Use --async** for faster execution on multi-core systems
8. **Try --profile=basic** for quick checks

## 📋 Requirements

- PHP 7.4 or higher
- Magento 2.3.x, 2.4.x or Adobe Commerce
- Command line access to Magento installation
- Read access to Magento files and database
- Must be run from Magento root directory

## 🤝 Contributing

Contributions welcome! Priority areas:
- Additional analyzers for new Magento features
- More fix generators for common issues
- Performance benchmarking integration
- Cloud-specific optimizations

## 📄 License

GPL-3.0 License

## 👨‍💻 Author

Piotr Siejczuk
- GitHub: [@PiotrSiejczuk](https://github.com/PiotrSiejczuk)
- Email: piotr.siejczuk@gmail.com

## 🙏 Acknowledgments

- Magento Community for feedback and testing
- Based on Adobe Commerce best practices and security guidelines
- Inspired by community performance optimization guides