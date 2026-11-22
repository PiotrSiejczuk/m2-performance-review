# Magento 2 Performance Review Tool - Roadmap

## Current Status: v1.0.4.1
v1.0.4 feature release (v1.0.4.1 hotfix: Varnish full page cache crash): MCP server, baselines & regression gates, SARIF export, interactive HTML report,
`.m2perf.json` configuration, StaticCodeAnalyzer, Hyvä / Fastly / Valkey / Adobe Commerce Cloud awareness,
plus a full validation pass of every command in Developer and Production mode.

---

## Potential Roadmap Flow

Phases are ordered by dependency, not by calendar. Each phase ends with a **gate**: continue, pivot or kill
the remaining scope based on evidence (usage signal, false-positive rate, maintenance cost).

```
Phase 0  Release hygiene & trust      ->  v1.0.5
Phase 1  Signal quality               ->  v1.1
Phase 2  Execution & runtime          ->  v1.2
Phase 3  Ecosystem & AI integration   ->  v2.0
```

### Phase 0 - Release Hygiene & Trust (v1.0.5) - prerequisite for everything else
Without automated builds and tests every analyzer change risks silent regressions (the 1.0.4 validation
pass found 20+ defects that only manual end-to-end runs revealed).

- **CI pipeline (GitHub Actions)**: lint, PHPStan, PHPUnit, PHAR build on every PR
- **Release automation**: attach `m2-performance.phar` + `m2-performance.phar.sha256` as release assets
  (self-update already prefers release assets and verifies SHA-256)
- **Unit test suite**: AnalysisRunner, BaselineManager, StoreContext, EnhancedConfigLoader precedence,
  fix-script generators (bash `-n` + shellcheck on generated output)
- **Fixture Magento roots**: Luma/on-prem + Varnish, Hyvä + Fastly, Adobe Commerce Cloud (L2 cache), Valkey 2.4.8
  (the v1.0.4 Varnish crash slipped through because only a Fastly store was in the matrix)
- **End-to-end smoke matrix**: every command x Developer/Production mode (the 1.0.4 manual matrix, automated)
- **Cleanup**: retire remaining legacy paths (static HTML generator in `generate`, duplicated update-check logic)

**Gate:** CI green on all fixture stacks; zero known false positives on the "clean" fixture.

### Phase 1 - Signal Quality (v1.1)
Make every finding trustworthy and every score meaningful.

- **Informational tier**: positive detections ("Fastly detected", "Hyvä detected") stop counting as issues,
  stop landing in fix scripts and SARIF
- **Score v2 (non-saturating)**: per-area capped penalties so large estates do not flatten at 0 / F;
  keeps trend value for baselines
- **Stable rule IDs** per finding (baselines keyed by rule ID instead of area + title)
- **SARIF locations**: real file/line anchors only (module-name "files" and config findings mapped to
  `app/etc/config.php` / `env.php` regions instead of placeholder URIs)
- **Fix scripts**: locate the loaded OPcache ini via `php --ini` instead of Debian `/etc/php/<ver>` paths
- **`review --fail-below=<score>`** exit code (today the gate lives in `baseline --fail-on-regression`)
- **Multiple export formats per run** (`--export=json,html,sarif`), input validation for `--priority`
- **Terminal UX**: wrapped table columns, compact mode for CI logs
- **False-positive sweep** across analyzers using the Phase 0 fixtures (e.g. config precedence,
  multi-website base URLs, Cloud-managed settings)

**Gate:** fewer than 5% findings dismissed as false positives in real-store runs; otherwise pivot to
fixing detection before adding new analyzers.

### Phase 2 - Execution & Runtime (v1.2)
- **Real parallel execution** for `--async` (isolated worker processes per analyzer; no `pcntl_fork`
  inside the PHAR - shared file descriptors corrupt archive reads)
- **Monitoring dashboard decision**: bundle ReactPHP into the PHAR or retire `monitor` in favour of
  `cache:monitor` + exports (kill/pivot gate on usage)
- **Benchmark realism**: URL discovery from sitemap / category tree instead of sample-data paths,
  authenticated REST scenario (integration token), percentile reporting
- **Synthetic load testing integration (k6)**: crawler-driven scenarios with results fed into the report
- **Machine-readable `cache:monitor`** output for dashboards

**Gate:** benchmark and load-test numbers reproducible within +/-10% across runs on the same stack.

### Phase 3 - Ecosystem & AI Integration (v2.0)
- **MCP expansion**: resources (latest report, baselines), prompts (guided remediation),
  fix-suggestion tool with write-guarded apply
- **APM correlation**: map findings to real transaction cost (New Relic / Datadog) to rank by impact
- **Plugin architecture** for custom analyzers and fix generators
- **Official Docker image** (ghcr.io) with `pdo_mysql`, `redis`, `opcache`
- **Cloud-provider checks**: AWS (ElastiCache, RDS, CloudFront), GCP, Azure, Kubernetes

**Gate:** external adoption signal (CI integrations, MCP installs, community analyzers) before investing
in a REST API / SaaS surface.

---

## Backlog - Exploratory (unscheduled)

- **Security compliance packs**: PCI DSS, GDPR, custom rule sets
- **Advanced analytics**: anomaly detection, capacity planning, cost optimization
- **Static analysis bridges**: PHPStan / Psalm rule packs for Magento performance anti-patterns
- **Git hooks** for pre-merge performance regression checks
- **Interactive help**: `explain --issue=<rule-id>` with impact estimates

---

## Completed Features

### v1.0.4.1
✅ **Hotfix**: Varnish full page cache crash; release PHAR built from the tagged source

### v1.0.4
✅ **MCP server** for AI coding agents (7 tools over stdio)
✅ **Baselines & regression detection** with CI gates and mode/profile mismatch warnings
✅ **SARIF 2.1.0 export** and **interactive HTML report**
✅ **Configuration file** (`.m2perf.json`): disabled analyzers, custom profiles, scoring weights, min score
✅ **Continue-on-error** execution (graceful degradation per analyzer)
✅ **StaticCodeAnalyzer** (N+1, cache writes without TTL, index-bypassing type mismatches)
✅ **Context awareness**: Hyvä, Fastly, Valkey, Adobe Commerce Cloud L2 cache, Magento config precedence
✅ **Unified scoring** across CLI, HTML, baselines and MCP
✅ **Safe self-update** (SHA-256 verification, no background fork)

### v1.0.3
✅ **Enhanced CDN Detection** - multi-method Fastly detection, CDN-aware recommendations
✅ **Improved Configuration Loading** - EnhancedConfigLoader with fallback mechanisms

---

## Community & Ecosystem Goals

### **Short Term (v1.1)**
- [ ] Contribution guidelines and developer documentation
- [ ] Integration with popular Magento agencies and hosting providers
- [ ] Performance optimization training materials

### **Medium Term (v1.2)**
- [ ] Magento Marketplace listing
- [ ] Conference presentations and workshops
- [ ] Case studies and success stories
- [ ] Partner program with hosting providers

### **Long Term (v2.0+)**
- [ ] Community plugin marketplace
- [ ] Deeper Adobe Commerce Cloud integration
- [ ] Certification program for Magento performance optimization

---

## Technical Debt & Maintenance

### **Ongoing Priorities**
- **Code Quality**: PSR-12, PHPStan, growing unit test coverage (Phase 0)
- **Documentation**: Keep README, examples and CHANGELOG current with every release
- **Performance**: Full analysis should stay under 5 seconds on a 150k-file codebase
- **Compatibility**: PHP 8.1+ and Magento 2.4.x
- **Security**: Regular dependency updates, checksummed releases

### **Breaking Changes Policy**
- Major versions (v2.x) may include breaking changes with a deprecation notice in the prior minor release
- Minor and patch versions (v1.x) maintain backward compatibility
- Configuration file format changes include migration notes

---

## Success Metrics

### **v1.0.5 Goals**
- CI pipeline green on every merge; releases ship PHAR + checksum assets
- Zero critical error reports from production usage

### **v1.1 Goals**
- Less than 5% false-positive rate on real-store runs
- Score v2 adopted by baselines without breaking existing comparisons

### **v1.2 Goals**
- Parallel execution at least 2x faster on the full profile
- Reproducible benchmark / load-test results

### **v2.0 Goals**
- Measurable MCP and CI/CD adoption
- First community-contributed analyzers

---

## Contributing

We welcome contributions! Priority areas for community involvement:

1. **New Analyzers**: GraphQL, PWA, B2B modules
2. **Cloud Provider Support**: Platform-specific optimizations
3. **Integration Development**: APIs, webhooks, and third-party tools
4. **Documentation**: Tutorials, best practices, and case studies
5. **Testing**: Real-world testing across different environments

Open an issue or pull request on GitHub.

---

## Feedback & Feature Requests

- **GitHub Issues**: Bug reports and feature requests
- **Discussions**: General questions and community discussions
- **Email**: piotr.siejczuk@gmail.com for enterprise features
- **Slack**: Join our community workspace for real-time discussions
