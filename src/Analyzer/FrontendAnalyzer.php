<?php

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;
use M2Performance\Trait\DevModeAwareTrait;

class FrontendAnalyzer implements AnalyzerInterface
{
    use DevModeAwareTrait;

    private string $magentoRoot;
    private array $coreConfig;
    private RecommendationCollector $collector;

    public function __construct(string $magentoRoot, array $coreConfig, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, "/\\");
        $this->coreConfig = $coreConfig;
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $this->analyzeFrontend();
    }

    private function analyzeFrontend(): void
    {
        // Check Theme Usage
        $this->analyzeThemeUsage();

        // Modern Frontend Optimization Checks (Less Critical in Dev)
        $this->analyzeModernOptimizations();

        // Check for Oroblematic Merging/Bundling with HTTP/2 Considerations
        $this->analyzeBundlingConfiguration();

        // Check for Varnish/Fastly (Production Focused)
        if (!$this->isInDeveloperMode()) {
            $this->analyzeCachingConfiguration();
        }

        // Check for Lazy Loading
        $this->analyzeLazyLoading();

        // Check for Problematic Layout Practices
        $this->analyzeLayoutPractices();

        // Detect Performance Focused Themes
        $this->analyzePerformanceThemes();

        // Check for Optimal Bundle Sizes (Production Focused)
        if (!$this->isInDeveloperMode()) {
            $this->analyzeBundleSizes();
        }

        // Check for "FAKE" SVG Logos
        $this->analyzeImageOptimization();

        // Check for Heavy CSS/JS in Head Configuration
        $this->analyzeHeadAssets();
    }

    private function analyzeThemeUsage(): void
    {
        $themePath = $this->magentoRoot . '/app/design/frontend';
        $usingCustomTheme = false;
        $usingDefaultTheme = false;

        if (is_dir($themePath)) {
            foreach ((array)scandir($themePath) as $vendor) {
                if (in_array($vendor, ['.', '..'], true)) continue;

                foreach ((array)scandir($themePath . '/' . $vendor) as $theme) {
                    if (in_array($theme, ['.', '..'], true)) continue;

                    $path = $themePath . '/' . $vendor . '/' . $theme;
                    if (!is_dir($path)) continue;

                    if ($vendor === 'Magento' && in_array($theme, ['luma', 'blank'], true)) {
                        $usingDefaultTheme = true;
                    } else {
                        $usingCustomTheme = true;
                    }
                }
            }
        }

        if ($usingDefaultTheme && !$usingCustomTheme) {
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;
            $this->collector->add(
                'frontend',
                'Consider performance-optimized theme',
                $priority,
                'Default Luma/Blank themes are not optimized for performance. Consider Hyvä, PWA Studio, or custom performance-focused theme.',
                'Luma/Blank themes load excessive JS/CSS and use outdated RequireJS. Modern themes like Hyvä reduce JS by 90%+ and improve Core Web Vitals significantly.'
            );
        }
    }

    private function analyzeModernOptimizations(): void
    {
        // HTML minification - less critical in dev mode
        $htmlMinify = $this->getConfigValue('dev/template/minify_html');
        if ($htmlMinify !== '1' && !$this->isInDeveloperMode()) {
            $this->collector->add(
                'frontend',
                'Enable HTML minification',
                Recommendation::PRIORITY_LOW,
                'HTML minification reduces payload size without blocking issues.',
                'HTML minification removes whitespace and comments, reducing document size by 10-20% without any rendering impact.'
            );
        }

        // Check for static content signing optimization (production-only concern)
        if (!$this->isInDeveloperMode()) {
            $this->analyzeStaticContentSigning();
        }
    }

    private function analyzeBundlingConfiguration(): void
    {
        // In dev mode, bundling issues are less critical as performance isn't the focus
        $priorityModifier = $this->isInDeveloperMode() ? -1 : 0;

        $jsMerge = $this->getConfigValue('dev/js/merge_files');
        $jsMinify = $this->getConfigValue('dev/js/minify_files');
        $cssMerge = $this->getConfigValue('dev/css/merge_css_files');
        $cssMinify = $this->getConfigValue('dev/css/minify_files');
        $jsBundle = $this->getConfigValue('dev/js/enable_js_bundling');

        if ($jsMerge === '1') {
            $priority = max(Recommendation::PRIORITY_LOW, Recommendation::PRIORITY_HIGH + $priorityModifier);
            $message = $this->isInDeveloperMode()
                ? 'JS merging enabled - consider disabling for easier debugging in development'
                : 'Reconsider JavaScript file merging for HTTP/2';

            $this->collector->add(
                'frontend',
                $message,
                $priority,
                'JS merging creates large monolithic bundles that increase Total Blocking Time (TBT). With HTTP/2, browsers can handle multiple concurrent requests efficiently.',
                'In HTTP/1 era, browsers limited to 6-8 concurrent connections, so bundling made sense. HTTP/2 removes this limit - downloading 1x500KB file is worse than 5x100KB files because: ' .
                '1) Large JS files block main thread longer, 2) Even async/defer JS competes for bandwidth with render-blocking CSS, ' .
                '3) Parsing/executing 500KB of JS at once spikes CPU. Recommendation: Create 2-3 mid-size bundles (100-150KB each) for optimal balance.'
            );
        }

        if ($cssMerge === '1') {
            $priority = max(Recommendation::PRIORITY_LOW, Recommendation::PRIORITY_HIGH + $priorityModifier);
            $message = $this->isInDeveloperMode()
                ? 'CSS merging enabled - consider disabling for easier debugging in development'
                : 'Optimize CSS delivery strategy';

            $this->collector->add(
                'frontend',
                $message,
                $priority,
                'CSS merging duplicates styles across page types and creates render-blocking monoliths. Consider page-specific CSS bundles.',
                'Merged CSS forces browsers to download/parse styles for ALL pages on EVERY page. This delays First Contentful Paint (FCP). ' .
                'Better approach: 1) Critical inline CSS for above-fold, 2) Page-specific bundles (category.css, product.css, checkout.css), ' .
                '3) Async load non-critical styles. This reduces render-blocking CSS from ~200KB to ~20-30KB per page type.'
            );
        }

        if ($jsBundle === '1') {
            $priority = max(Recommendation::PRIORITY_LOW, Recommendation::PRIORITY_HIGH + $priorityModifier);
            $this->collector->add(
                'frontend',
                'Review RequireJS bundling strategy',
                $priority,
                'RequireJS bundling often creates oversized bundles. Consider modern bundling with 2-3 mid-size chunks.',
                'RequireJS bundling typically creates 300-800KB bundles that must be parsed entirely before execution. ' .
                'Modern approach: Split into logical chunks - vendor bundle (~100KB), common bundle (~100KB), page-specific bundles (~50-100KB each). ' .
                'This allows parallel downloading and progressive enhancement while keeping parse/compile times low.'
            );
        }

        // Minification recommendations - always beneficial but lower priority in dev
        $minifyPriority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_LOW;

        if ($jsMinify !== '1') {
            $this->collector->add(
                'frontend',
                'Enable JavaScript minification',
                $minifyPriority,
                'Individual file minification reduces bandwidth without bundling downsides.',
                'Minification removes whitespace/comments and shortens variable names, reducing file sizes by 30-50% without functionality changes.'
            );
        }

        if ($cssMinify !== '1') {
            $this->collector->add(
                'frontend',
                'Enable CSS minification',
                $minifyPriority,
                'Individual CSS minification is beneficial when not using bundling.',
                'CSS minification typically reduces file sizes by 20-30% through whitespace removal and optimization.'
            );
        }
    }

    private function analyzeBundleSizes(): void
    {
        $pubStaticPath = $this->magentoRoot . '/pub/static';
        $problematicBundles = [];
        $allBundleFiles = [];

        if (is_dir($pubStaticPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pubStaticPath),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['js', 'css'])) {
                    $size = $file->getSize();
                    $sizeKb = round($size / 1024);
                    $relativePath = str_replace($this->magentoRoot, '', $file->getPathname());

                    // Track all bundles for analysis data
                    $allBundleFiles[] = [
                        'path' => $relativePath,
                        'size_kb' => $sizeKb,
                        'type' => $file->getExtension()
                    ];

                    // Flag bundles over 200KB as problematic
                    if ($sizeKb > 200) {
                        $problematicBundles[] = [
                            'file' => basename($relativePath),
                            'path' => $relativePath,
                            'size' => $sizeKb,
                            'type' => $file->getExtension()
                        ];
                    }
                }
            }
        }

        if (!empty($problematicBundles)) {
            // Sort by size descending
            usort($problematicBundles, fn($a, $b) => $b['size'] - $a['size']);

            $largestBundle = $problematicBundles[0];
            $bundleList = array_slice($problematicBundles, 0, 3);
            $bundleInfo = implode(', ', array_map(fn($b) => "{$b['file']} ({$b['size']}KB)", $bundleList));

            // Extract file paths for file list
            $fileList = array_map(fn($b) => $b['path'], $problematicBundles);

            $this->collector->addWithFiles(
                'frontend',
                'Large asset bundles detected',
                Recommendation::PRIORITY_HIGH,
                "Found " . count($problematicBundles) . " bundles over 200KB. Largest: {$largestBundle['file']} ({$largestBundle['size']}KB). Top bundles: $bundleInfo\n\n💡 Full list of " . count($problematicBundles) . " large bundles available with --export",
                $fileList,
                'Large bundles harm performance: 1) Block rendering longer, 2) Increase memory usage, 3) Spike CPU during parsing. ' .
                'Mobile devices struggle with bundles >200KB. Optimal sizes: CSS chunks 50-100KB, JS chunks 100-150KB. ' .
                'Split by: route (category/product/checkout), frequency (vendor/common/page-specific), or feature (core/theme/custom).',
                [
                    'total_bundles' => count($allBundleFiles),
                    'problematic_bundles' => count($problematicBundles),
                    'largest_size_kb' => $largestBundle['size'],
                    'bundle_breakdown' => $problematicBundles
                ]
            );
        }
    }

    private function analyzeCachingConfiguration(): void
    {
        $cachingApp = $this->getConfigValue('system/full_page_cache/caching_application');
        if ($cachingApp !== '2') {
            $this->collector->add(
                'frontend',
                'Use Varnish/Fastly for full page cache',
                Recommendation::PRIORITY_HIGH,
                'Varnish or Fastly provides better performance than built-in FPC, especially for frontend delivery.',
                'Built-in FPC still hits PHP/MySQL. Varnish serves from memory at edge, reducing TTFB from 200-500ms to 10-50ms. ' .
                'Fastly adds global CDN distribution. Both handle thousands of concurrent requests vs hundreds with built-in FPC.'
            );
        }
    }

    private function analyzeLazyLoading(): void
    {
        $lazyLoading = $this->getConfigValue('cms/pagebuilder/lazy_loading');
        if ($lazyLoading !== '1') {
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_MEDIUM;
            $this->collector->add(
                'frontend',
                'Enable native lazy loading for images',
                $priority,
                'Modern browsers support native lazy loading which improves Largest Contentful Paint (LCP) scores.',
                'Native lazy loading (loading="lazy") defers off-screen images until needed, reducing initial payload by 50-80%. ' .
                'Improves LCP by prioritizing visible content. Supported by 95%+ of browsers. Fallback: Intersection Observer polyfill.'
            );
        }
    }

    private function analyzeLayoutPractices(): void
    {
        // Check for CSS/JS in default.xml (bad practice)
        $this->checkDefaultLayoutAssets();

        // Check for page-specific optimizations (less critical in dev)
        if (!$this->isInDeveloperMode()) {
            $this->checkPageSpecificLayouts();
        }

        // Check for render-blocking resources
        $this->checkRenderBlockingResources();
    }

    private function checkDefaultLayoutAssets(): void
    {
        $defaultLayoutPaths = [
            '/app/design/*/*/*/layout/default.xml',
            '/app/code/*/*/view/*/layout/default.xml'
        ];

        $problematicFiles = [];
        foreach ($defaultLayoutPaths as $pattern) {
            $files = glob($this->magentoRoot . $pattern, GLOB_NOSORT);
            if (!$files) continue;

            foreach ($files as $file) {
                if (!is_readable($file)) continue;

                $content = file_get_contents($file);
                if ($content === false) continue;

                // Check for CSS/JS references in default.xml
                if (preg_match('/<head>.*?<\/head>/s', $content, $matches) ||
                    preg_match('/<css|<script|addItem.*\.css|addItem.*\.js/i', $content)) {

                    $relativePath = str_replace($this->magentoRoot, '', $file);
                    $problematicFiles[] = $relativePath;
                }
            }
        }

        if (!empty($problematicFiles)) {
            $fileCount = count($problematicFiles);
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_MEDIUM : Recommendation::PRIORITY_HIGH;

            $displayFiles = array_slice($problematicFiles, 0, 3);
            $displayList = implode("\n  - ", $displayFiles);
            if ($fileCount > 3) {
                $displayList .= "\n  - ... and " . ($fileCount - 3) . " more";
            }

            $this->collector->addWithFiles(
                'frontend',
                'Remove CSS/JS from default.xml layouts',
                $priority,
                "Found $fileCount default.xml files with CSS/JS assets. These load on every page, harming performance.\n\nFound files:\n  - $displayList\n\n💡 Full list of $fileCount files available with --export",
                $problematicFiles,
                'default.xml assets load on EVERY page - homepage, category, product, checkout, CMS. This wastes bandwidth and blocks rendering. ' .
                'Example: checkout CSS (50KB) loading on homepage. Move to: catalog_category_view.xml, catalog_product_view.xml, checkout_index_index.xml. ' .
                'Result: 30-50% reduction in blocking resources per page.',
                [
                    'total_files' => $fileCount,
                    'file_breakdown' => $problematicFiles
                ]
            );
        }
    }

    private function checkRenderBlockingResources(): void
    {
        // Check for opportunities to defer non-critical CSS/JS
        $criticalCssEnabled = $this->getConfigValue('dev/css/use_css_critical_path');

        if ($criticalCssEnabled !== '1' && !$this->isInDeveloperMode()) {
            $this->collector->add(
                'frontend',
                'Implement critical CSS strategy',
                Recommendation::PRIORITY_MEDIUM,
                'Extract and inline critical CSS for above-the-fold content to improve FCP/LCP.',
                'Critical CSS inlines only styles needed for initial viewport (~10-20KB), deferring rest. Reduces render-blocking CSS by 80-90%. ' .
                'Implementation: 1) Extract critical CSS per template, 2) Inline in <head>, 3) Load full CSS async. Tools: Critical, Penthouse, or Magento modules.'
            );
        }
    }

    private function checkPageSpecificLayouts(): void
    {
        $criticalPageLayouts = [
            'catalog_category_view.xml' => 'Category pages',
            'catalog_product_view.xml' => 'Product pages',
            'checkout_index_index.xml' => 'Checkout pages',
            'cms_index_index.xml' => 'Homepage'
        ];

        $missingOptimizations = [];
        foreach ($criticalPageLayouts as $layout => $pageName) {
            $layoutExists = !empty(glob($this->magentoRoot . '/app/design/*/*/*/layout/' . $layout)) ||
                !empty(glob($this->magentoRoot . '/app/code/*/*/view/*/layout/' . $layout));

            if (!$layoutExists) {
                $missingOptimizations[] = "$pageName ($layout)";
            }
        }

        if (count($missingOptimizations) > 2) {
            $this->collector->add(
                'frontend',
                'Implement page-specific layout optimizations',
                Recommendation::PRIORITY_MEDIUM,
                'Create page-specific layouts to load only necessary CSS/JS per page type: ' .
                implode(', ', $missingOptimizations),
                'Page-specific layouts prevent loading irrelevant assets. Example savings: Category page doesn\'t need checkout JS (100KB), ' .
                'Product page doesn\'t need category filters JS (50KB). Implement dedicated layouts to reduce page weight by 30-40%.'
            );
        }
    }

    private function analyzePerformanceThemes(): void
    {
        // Detect Hyvä Theme
        $composerJsonPath = $this->magentoRoot . '/composer.json';
        if (file_exists($composerJsonPath)) {
            $composerJson = json_decode(file_get_contents($composerJsonPath), true);
            if (!empty($composerJson['require'])) {
                foreach ($composerJson['require'] as $package => $version) {
                    if (strpos($package, 'hyva-themes/') === 0) {
                        $this->collector->add(
                            'frontend',
                            'Hyvä Theme detected - excellent choice!',
                            Recommendation::PRIORITY_LOW,
                            'Hyvä Theme is optimized for performance. Most traditional frontend recommendations do not apply.',
                            'Hyvä reduces JS from ~1.5MB to ~150KB by replacing RequireJS/KnockoutJS with Alpine.js. Typical improvements: ' .
                            'LCP <1.5s, TBT <150ms, CLS <0.05. Traditional bundling/merging recommendations don\'t apply to Hyvä.'
                        );
                        return; // Skip other checks for Hyvä
                    }
                }
            }
        }

        // Check for PWA Studio
        if (file_exists($this->magentoRoot . '/pwa-studio') ||
            file_exists($this->magentoRoot . '/packages')) {
            $this->collector->add(
                'frontend',
                'PWA Studio detected',
                Recommendation::PRIORITY_LOW,
                'PWA Studio uses modern frontend architecture. Traditional Magento frontend optimizations may not apply.',
                'PWA Studio uses React with code splitting and modern bundling. Assets load on-demand with service workers. ' .
                'Different optimization focus: API performance, GraphQL query optimization, and client-side caching strategies.'
            );
        }
    }

    private function analyzeStaticContentSigning(): void
    {
        $envPath = $this->magentoRoot . '/app/etc/env.php';
        if (file_exists($envPath)) {
            $env = include $envPath;

            // Check static content signing - only relevant in production
            if (isset($env['static_content_on_demand_in_production']) &&
                $env['static_content_on_demand_in_production'] == 1) {

                $this->collector->add(
                    'frontend',
                    'Disable on-demand static content in production',
                    Recommendation::PRIORITY_HIGH,
                    'On-demand static content generation in production causes performance issues. Pre-deploy all assets.',
                    'On-demand generation triggers PHP processing for every missing asset, adding 50-200ms latency per file. ' .
                    'With 50+ assets per page, this adds seconds to load time. Solution: Run setup:static-content:deploy during deployment.'
                );
            }
        }
    }

    private function analyzeImageOptimization(): void
    {
        // Check for fake SVGs (base64 encoded PNGs/JPGs inside SVG)
        $this->checkFakeSvgImages();

        // Check for inline base64 images (less critical in dev mode)
        if (!$this->isInDeveloperMode()) {
            $this->checkInlineBase64Images();
        }
    }

    private function checkFakeSvgImages(): void
    {
        $themePaths = [
            '/app/design/frontend',
            '/pub/static/frontend'
        ];

        $fakeSvgFiles = [];
        $examples = [];

        foreach ($themePaths as $basePath) {
            $path = $this->magentoRoot . $basePath;
            if (!is_dir($path)) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'svg') {
                    $content = file_get_contents($file->getPathname());

                    // Check for base64 encoded images inside SVG
                    if (preg_match('/data:image\/(png|jpeg|jpg|gif);base64,([A-Za-z0-9+\/=]+)/i', $content, $matches)) {
                        $base64Data = $matches[2];
                        $decodedSize = strlen(base64_decode($base64Data));
                        $encodedSize = strlen($base64Data);

                        $relativePath = str_replace($this->magentoRoot, '', $file->getPathname());
                        $fakeSvgFiles[] = $relativePath;

                        $examples[] = sprintf(
                            "%s (%.1fKB base64 → %.1fKB decoded %s)",
                            basename($relativePath),
                            $encodedSize / 1024,
                            $decodedSize / 1024,
                            $matches[1]
                        );

                        if (count($examples) >= 3) break 2;
                    }
                }
            }
        }

        if (!empty($fakeSvgFiles)) {
            $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_MEDIUM : Recommendation::PRIORITY_HIGH;
            $count = count($fakeSvgFiles);

            $displayFiles = array_slice($fakeSvgFiles, 0, 3);
            $displayList = implode("\n  - ", array_map('basename', $displayFiles));
            if ($count > 3) {
                $displayList .= "\n  - ... and " . ($count - 3) . " more";
            }

            $this->collector->addWithFiles(
                'frontend',
                'Replace fake SVGs with real vector graphics',
                $priority,
                "Found $count SVG files containing base64-encoded raster images. This increases file size by ~33%.\n\nFake SVG files:\n  - $displayList\n\n💡 Full list of $count files available with --export",
                $fakeSvgFiles,
                'Base64 encoding adds 33% overhead to image size. A 100KB PNG becomes 133KB when base64-encoded. ' .
                'For logos (loaded early with CSS/JS), this causes: 1) Bandwidth contention delaying FCP, ' .
                '2) HTTP/2 stream blocking, 3) Triple decode cost (SVG→Base64→PNG). ' .
                'Solution: Convert to real SVG vectors (typically 10-15x smaller) or use external image files.',
                [
                    'total_fake_svgs' => $count,
                    'examples' => $examples,
                    'overhead_percentage' => 33
                ]
            );
        }
    }

    private function checkInlineBase64Images(): void
    {
        $patterns = [
            '*.phtml' => '/app/design/frontend',
            '*.css' => '/pub/static/frontend',
            '*.less' => '/app/design/frontend'
        ];

        $totalBase64 = 0;
        $criticalPathBase64 = 0;
        $largestBase64 = 0;
        $examples = [];
        $allBase64Files = [];

        foreach ($patterns as $pattern => $basePath) {
            $path = $this->magentoRoot . $basePath;
            if (!is_dir($path)) continue;

            $files = glob($path . '/**/' . $pattern, GLOB_BRACE);

            foreach ($files as $file) {
                if (!is_readable($file)) continue;

                $content = file_get_contents($file);

                // Find all base64 images
                if (preg_match_all('/data:image\/[^;]+;base64,([A-Za-z0-9+\/=]+)/i', $content, $matches)) {
                    $relativePath = str_replace($this->magentoRoot, '', $file);

                    foreach ($matches[1] as $base64Data) {
                        $size = strlen($base64Data);
                        $totalBase64++;

                        if ($size > $largestBase64) {
                            $largestBase64 = $size;
                        }

                        // Check if in critical path (header, above-fold templates)
                        if (strpos($file, 'header') !== false ||
                            strpos($file, 'head.phtml') !== false ||
                            strpos($file, 'styles-m.css') !== false ||
                            strpos($file, 'styles-l.css') !== false) {
                            $criticalPathBase64++;
                        }
                    }

                    $allBase64Files[] = $relativePath;

                    if (count($examples) < 3) {
                        $examples[] = basename($relativePath);
                    }
                }
            }
        }

        if ($totalBase64 > 10) {
            $priority = $criticalPathBase64 > 5 ? Recommendation::PRIORITY_HIGH : Recommendation::PRIORITY_MEDIUM;

            $displayFiles = array_slice($allBase64Files, 0, 3);
            $displayList = implode("\n  - ", array_map('basename', $displayFiles));
            if (count($allBase64Files) > 3) {
                $displayList .= "\n  - ... and " . (count($allBase64Files) - 3) . " more";
            }

            $this->collector->addWithFiles(
                'frontend',
                'Remove inline base64 images',
                $priority,
                sprintf(
                    "Found %d inline base64 images (%d in critical path). Largest: %.1fKB.\n\nFiles with base64 images:\n  - %s\n\n💡 Full list of %d files available with --export",
                    $totalBase64,
                    $criticalPathBase64,
                    $largestBase64 / 1024,
                    $displayList,
                    count($allBase64Files)
                ),
                $allBase64Files,
                'Inline base64 images hurt performance: 1) 33% size overhead, 2) Cannot be cached separately, ' .
                '3) Block HTML/CSS parsing, 4) Cannot lazy load, 5) Increase memory usage. ' .
                'Better approaches: External files with proper caching, CSS sprites for tiny icons, ' .
                'SVG for vector graphics, or modern formats (WebP/AVIF) with <picture> element.',
                [
                    'total_base64_images' => $totalBase64,
                    'critical_path_count' => $criticalPathBase64,
                    'largest_size_kb' => $largestBase64 / 1024,
                    'files_with_base64' => count($allBase64Files)
                ]
            );
        }
    }

    private function getConfigValue(string $path, $default = null): mixed
    {
        return $this->coreConfig[$path] ?? $default;
    }

    private function analyzeHeadAssets(): void
    {
        $this->checkMiscHeadAssets();
        $this->checkHeadScriptsAcrossScopes();
    }

    private function checkMiscHeadAssets(): void
    {
        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (!file_exists($envPath)) {
                return;
            }

            $env = include $envPath;
            $dbConfig = $env['db']['connection']['default'] ?? null;
            if (!$dbConfig) {
                return;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['dbname'] ?? ''
            );

            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Query for misc head assets across all scopes
            $query = "
            SELECT 
                scope,
                scope_id,
                path,
                value,
                CASE 
                    WHEN scope = 'default' THEN 'Global'
                    WHEN scope = 'websites' THEN CONCAT('Website ID: ', scope_id)
                    WHEN scope = 'stores' THEN CONCAT('Store ID: ', scope_id)
                END as scope_name
            FROM core_config_data
            WHERE path IN (
                'design/head/includes',
                'design/head/demonotice',
                'design/head/default_title',
                'design/head/default_description',
                'design/head/default_keywords',
                'design/head/default_robots',
                'design/head/shortcut_icon'
            )
            AND (value IS NOT NULL AND value != '')
            ORDER BY scope, scope_id, path
        ";

            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $headAssets = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $problematicAssets = [];
            $heavyIncludes = [];

            foreach ($headAssets as $asset) {
                // Check for misc includes (CSS/JS)
                if ($asset['path'] === 'design/head/includes' && !empty($asset['value'])) {
                    // Check for heavy assets
                    $value = $asset['value'];
                    $sizeEstimate = strlen($value);

                    // Look for external scripts/styles
                    $hasExternalAssets = false;
                    $assetTypes = [];

                    if (preg_match_all('/<script[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $value, $scriptMatches)) {
                        $hasExternalAssets = true;
                        $assetTypes[] = count($scriptMatches[1]) . ' JS files';
                    }

                    if (preg_match_all('/<link[^>]*href=["\']([^"\']+\.css[^"\']*)["\'][^>]*>/i', $value, $cssMatches)) {
                        $hasExternalAssets = true;
                        $assetTypes[] = count($cssMatches[1]) . ' CSS files';
                    }

                    // Check for inline scripts/styles
                    if (preg_match_all('/<script[^>]*>.*?<\/script>/is', $value, $inlineScripts)) {
                        $inlineSize = array_sum(array_map('strlen', $inlineScripts[0]));
                        if ($inlineSize > 1000) {
                            $assetTypes[] = sprintf('%.1fKB inline JS', $inlineSize / 1024);
                        }
                    }

                    if (preg_match_all('/<style[^>]*>.*?<\/style>/is', $value, $inlineStyles)) {
                        $inlineSize = array_sum(array_map('strlen', $inlineStyles[0]));
                        if ($inlineSize > 1000) {
                            $assetTypes[] = sprintf('%.1fKB inline CSS', $inlineSize / 1024);
                        }
                    }

                    if ($sizeEstimate > 500 || $hasExternalAssets) {
                        $heavyIncludes[] = [
                            'scope' => $asset['scope_name'],
                            'size' => $sizeEstimate,
                            'types' => $assetTypes,
                            'preview' => substr(strip_tags($value), 0, 100) . '...'
                        ];
                    }
                }

                // Check for problematic shortcut icons
                if ($asset['path'] === 'design/head/shortcut_icon' && !empty($asset['value'])) {
                    if (strpos($asset['value'], 'base64') !== false) {
                        $problematicAssets[] = [
                            'scope' => $asset['scope_name'],
                            'issue' => 'Base64 encoded favicon detected',
                            'path' => $asset['path']
                        ];
                    }
                }
            }

            if (!empty($heavyIncludes)) {
                $details = "Found heavy assets in head configuration:\n\n";
                foreach ($heavyIncludes as $include) {
                    $details .= sprintf(
                        "• %s: %s (%.1fKB)\n",
                        $include['scope'],
                        implode(', ', $include['types']),
                        $include['size'] / 1024
                    );
                }

                $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_MEDIUM : Recommendation::PRIORITY_HIGH;

                $this->collector->add(
                    'frontend',
                    'Heavy CSS/JS assets in head configuration',
                    $priority,
                    $details,
                    'Assets in design/head/includes load on every page and block rendering. Move to layout XML for page-specific loading. ' .
                    'External scripts should use async/defer. Consider: 1) Moving to RequireJS for JS dependencies, ' .
                    '2) Using layout updates for page-specific assets, 3) Implementing critical CSS strategy instead of global styles.'
                );
            }

            // Also check for theme-specific head assets
            $this->checkThemeHeadAssets($pdo);

        } catch (\Exception $e) {
            // Skip if cannot connect to database
        }
    }

    private function checkThemeHeadAssets(\PDO $pdo): void
    {
        // Check for heavy assets in theme configurations
        $query = "
        SELECT 
            path,
            value,
            COUNT(*) as usage_count,
            GROUP_CONCAT(DISTINCT scope ORDER BY scope) as scopes
        FROM core_config_data
        WHERE path LIKE 'design/theme/%'
        AND value LIKE '%<script%'
        OR value LIKE '%<link%'
        OR value LIKE '%<style%'
        GROUP BY path, value
        HAVING LENGTH(value) > 200
    ";

        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $themeAssets = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($themeAssets)) {
            $this->collector->add(
                'frontend',
                'Scripts/styles found in theme configuration',
                Recommendation::PRIORITY_MEDIUM,
                sprintf('Found %d theme configuration entries with inline scripts/styles. These should be moved to proper layout XML files.', count($themeAssets)),
                'Theme configuration should only contain theme IDs and settings, not actual CSS/JS code. ' .
                'Move assets to: 1) Layout XML files for proper loading control, 2) Web assets for caching benefits, ' .
                '3) RequireJS for dependency management.'
            );
        }
    }

    private function checkHeadScriptsAcrossScopes(): void
    {
        try {
            $envPath = $this->magentoRoot . '/app/etc/env.php';
            if (!file_exists($envPath)) {
                return;
            }

            $env = include $envPath;
            $dbConfig = $env['db']['connection']['default'] ?? null;
            if (!$dbConfig) {
                return;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['dbname'] ?? ''
            );

            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password']);

            // Get all Google Analytics, Tag Manager, and tracking scripts
            $query = "
            SELECT 
                path,
                scope,
                scope_id,
                value,
                CASE 
                    WHEN path LIKE '%google%' THEN 'Google Analytics/Tag Manager'
                    WHEN path LIKE '%facebook%' THEN 'Facebook Pixel'
                    WHEN path LIKE '%tracking%' THEN 'Tracking Script'
                    ELSE 'Third-party Script'
                END as script_type
            FROM core_config_data
            WHERE (
                path LIKE '%/analytics/%'
                OR path LIKE '%/tracking/%'
                OR path LIKE '%/gtag/%'
                OR path LIKE '%/pixel/%'
                OR path LIKE '%/head/%script%'
            )
            AND value IS NOT NULL 
            AND value != ''
            AND (
                value LIKE '%<script%'
                OR value LIKE '%gtag(%'
                OR value LIKE '%analytics%'
            )
        ";

            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $trackingScripts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $syncScripts = [];
            $duplicateScripts = [];
            $scriptsByType = [];

            foreach ($trackingScripts as $script) {
                $scriptsByType[$script['script_type']][] = $script;

                // Check for synchronous loading
                if (!preg_match('/\b(async|defer)\b/i', $script['value'])) {
                    $syncScripts[] = $script;
                }
            }

            if (!empty($syncScripts)) {
                $priority = $this->isInDeveloperMode() ? Recommendation::PRIORITY_LOW : Recommendation::PRIORITY_HIGH;

                $this->collector->add(
                    'frontend',
                    'Synchronous third-party scripts detected',
                    $priority,
                    sprintf('Found %d tracking/analytics scripts loading synchronously. Add async/defer attributes.', count($syncScripts)),
                    'Synchronous third-party scripts block page rendering and hurt Core Web Vitals. ' .
                    'Google Analytics/Tag Manager should always use async. Facebook Pixel should use async. ' .
                    'Impact: Each sync script adds 100-500ms to Time to Interactive. Solution: Add async or defer attributes.'
                );
            }

        } catch (\Exception $e) {
            // Skip if Cannot Connect
        }
    }
}
