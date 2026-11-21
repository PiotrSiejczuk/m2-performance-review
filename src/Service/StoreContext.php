<?php

declare(strict_types=1);

namespace M2Performance\Service;

/**
 * Centralized store environment detection, computed once per Magento root
 * and shared by analyzers, the CLI dashboard, reports and MCP output.
 *
 * Solves two classes of contradictory findings:
 *  - Hyvä (and Hyvä Checkout) present, yet Luma-oriented frontend checks
 *    (JS/CSS minification, bundling/merging) still fire
 *  - Fastly present (built on Varnish, managed at the edge), yet the Varnish
 *    analyzer reports "Varnish not configured"
 */
class StoreContext
{
    private string $magentoRoot;

    private ?bool $isHyva = null;

    private ?bool $isHyvaCheckout = null;

    private ?bool $isFastly = null;

    private ?string $baseSecureUrl = null;

    private bool $isBaseUrlResolved = false;

    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(string $magentoRoot)
    {
        $this->magentoRoot = rtrim($magentoRoot, '/');
    }

    public static function forRoot(string $magentoRoot): self
    {
        $key = rtrim($magentoRoot, '/');
        if (isset(self::$instances[$key]) === false) {
            self::$instances[$key] = new self($key);
        }

        return self::$instances[$key];
    }

    /**
     * Reset cached instances (tests, MCP fresh-run semantics).
     */
    public static function reset(): void
    {
        self::$instances = [];
    }

    public function isHyva(): bool
    {
        if ($this->isHyva === null) {
            $this->isHyva = $this->hasComposerPackagePrefix('hyva-themes/')
                || is_dir($this->magentoRoot . '/vendor/hyva-themes');
        }

        return $this->isHyva;
    }

    public function isHyvaCheckout(): bool
    {
        if ($this->isHyvaCheckout === null) {
            $this->isHyvaCheckout = $this->hasComposerPackagePrefix('hyva-themes/magento2-hyva-checkout')
                || is_dir($this->magentoRoot . '/vendor/hyva-themes/magento2-hyva-checkout');
        }

        return $this->isHyvaCheckout;
    }

    public function isFastly(): bool
    {
        if ($this->isFastly === null) {
            $this->isFastly = $this->detectFastly();
        }

        return $this->isFastly;
    }

    /**
     * Magento mode as Magento itself resolves it: MAGE_MODE environment
     * variable first, then app/etc/env.php.
     */
    public function getMagentoMode(): string
    {
        $mageMode = getenv('MAGE_MODE');
        if (is_string($mageMode) === true && $mageMode !== '') {
            return $mageMode;
        }

        $env = $this->safeInclude($this->magentoRoot . '/app/etc/env.php');

        return is_string($env['MAGE_MODE'] ?? null) === true ? $env['MAGE_MODE'] : 'default';
    }

    /**
     * The store's secure base URL (web/secure/base_url) — the URL the checks
     * are effectively being executed for. Resolution order follows Magento's
     * precedence: CONFIG__DEFAULT__* environment variables -> env.php system
     * overrides -> config.php system section -> database core_config_data
     * (best-effort; DB may be unavailable on a local static run).
     */
    public function getBaseSecureUrl(): ?string
    {
        if ($this->isBaseUrlResolved === true) {
            return $this->baseSecureUrl;
        }
        $this->isBaseUrlResolved = true;

        // 0. Environment variable override (Adobe Commerce Cloud, Docker setups)
        $url = getenv('CONFIG__DEFAULT__WEB__SECURE__BASE_URL');
        if (is_string($url) === true && $url !== '') {
            return $this->baseSecureUrl = $url;
        }

        // 1. env.php system config override
        $env = $this->safeInclude($this->magentoRoot . '/app/etc/env.php');
        $url = $env['system']['default']['web']['secure']['base_url'] ?? null;
        if (is_string($url) === true && $url !== '') {
            return $this->baseSecureUrl = $url;
        }
        $url = $env['system']['default']['web']['unsecure']['base_url'] ?? null;
        if (is_string($url) === true && $url !== '') {
            return $this->baseSecureUrl = $url;
        }

        // 2. config.php system section (app:config:dump output)
        $config = $this->safeInclude($this->magentoRoot . '/app/etc/config.php');
        $url = $config['system']['default']['web']['secure']['base_url'] ?? null;
        if (is_string($url) === true && $url !== '') {
            return $this->baseSecureUrl = $url;
        }

        // 3. Database core_config_data (best effort)
        try {
            $db = $env['db']['connection']['default'] ?? null;
            if (is_array($db) === true && extension_loaded('pdo_mysql') === true) {
                // Magento allows "host:port" in env.php; PDO needs them split
                [$host, $port] = array_pad(explode(':', (string)($db['host'] ?? '127.0.0.1'), 2), 2, '3306');
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $db['dbname'] ?? '');
                $pdo = new \PDO($dsn, (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [
                    \PDO::ATTR_TIMEOUT => 3,
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                $stmt = $pdo->query(
                    "SELECT value FROM core_config_data WHERE path = 'web/secure/base_url' "
                    . "AND scope = 'default' LIMIT 1"
                );
                $value = $stmt !== false ? $stmt->fetchColumn() : false;
                if (is_string($value) === true && $value !== '') {
                    return $this->baseSecureUrl = $value;
                }
            }
        } catch (\Throwable $e) {
            // DB unavailable on a static/local run — acceptable
        }

        // 4. Unsecure env override only when no secure URL is configured anywhere
        $url = getenv('CONFIG__DEFAULT__WEB__UNSECURE__BASE_URL');
        if (is_string($url) === true && $url !== '') {
            return $this->baseSecureUrl = $url;
        }

        return $this->baseSecureUrl = null;
    }

    /**
     * Backend options of a Redis-compatible cache frontend, or null when the
     * frontend is not backed by Redis. Covers plain Redis, Valkey (Magento
     * 2.4.8+) and the two-tier RemoteSynchronizedCache (Adobe Commerce Cloud
     * default) whose remote tier carries the Redis connection options.
     *
     * @param array<string, mixed> $frontend One entry of env.php cache/frontend
     * @return array<string, mixed>|null
     */
    public static function getRedisCacheBackendOptions(array $frontend): ?array
    {
        $isRedisLike = static fn($backend): bool => is_string($backend) === true
            && (stripos($backend, 'redis') !== false || stripos($backend, 'valkey') !== false);

        $options = is_array($frontend['backend_options'] ?? null) === true ? $frontend['backend_options'] : [];
        if ($isRedisLike($frontend['backend'] ?? null) === true) {
            return $options;
        }

        if ($isRedisLike($options['remote_backend'] ?? null) === true) {
            return is_array($options['remote_backend_options'] ?? null) === true
                ? $options['remote_backend_options']
                : [];
        }

        return null;
    }

    private function hasComposerPackagePrefix(string $prefix): bool
    {
        foreach (['/composer.json', '/composer.lock'] as $file) {
            $path = $this->magentoRoot . $file;
            if (is_readable($path) === false) {
                continue;
            }
            $data = json_decode((string)file_get_contents($path), true);
            if (is_array($data) === false) {
                continue;
            }

            foreach (array_keys($data['require'] ?? []) as $package) {
                if (str_starts_with((string)$package, $prefix) === true) {
                    return true;
                }
            }
            foreach ($data['packages'] ?? [] as $package) {
                if (str_starts_with((string)($package['name'] ?? ''), $prefix) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    private function detectFastly(): bool
    {
        // Composer package
        if ($this->hasComposerPackagePrefix('fastly/') === true) {
            return true;
        }

        $env = $this->safeInclude($this->magentoRoot . '/app/etc/env.php');

        // Fastly cache backend
        $backend = $env['cache']['frontend']['default']['backend'] ?? '';
        if (is_string($backend) === true && stripos($backend, 'fastly') !== false) {
            return true;
        }

        // Fastly system config present
        if (isset($env['system']['default']['system']['full_page_cache']['fastly']) === true
            || isset($env['system']['default']['system']['fastly']) === true
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeInclude(string $path): array
    {
        if (is_readable($path) === false) {
            return [];
        }
        try {
            $data = include $path;

            return is_array($data) === true ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
