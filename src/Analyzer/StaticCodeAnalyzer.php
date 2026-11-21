<?php

declare(strict_types=1);

namespace M2Performance\Analyzer;

use M2Performance\Contract\AnalyzerInterface;
use M2Performance\Helper\RecommendationCollector;
use M2Performance\Model\Recommendation;

/**
 * Static analysis of custom PHP in app/code for performance anti-patterns
 * that are invisible to configuration inspection.
 *
 * Detects (line-accurate, so findings carry file:line for SARIF):
 *   - N+1: batch method called with a single-element array inside a loop
 *   - N+1: per-item repository->get()/getById() inside a loop
 *   - cache->save() with no TTL/lifetime argument
 *   - int-typed parameters whose name implies a VARCHAR-keyed domain concept
 *     (index-bypass risk, e.g. getById(int $sourceCode))
 *
 * These are heuristics (regex over source, loop-scope tracked by brace depth),
 * not a full AST. False positives are possible; each finding names the file
 * and line so a human can confirm quickly. Scanning is capped and skips
 * generated/vendor/test paths to keep runtime bounded on large stores.
 */
class StaticCodeAnalyzer implements AnalyzerInterface
{
    private const MAX_FILES = 5000;
    private const MAX_FILE_BYTES = 500000;

    /** Parameter names that are VARCHAR-keyed domain concepts in Magento. */
    private const STRING_KEYED_PARAMS = [
        'sourceCode', 'sku', 'storeCode', 'websiteCode', 'attributeCode',
    ];

    private string $magentoRoot;

    private RecommendationCollector $collector;

    /** @var array<string, array<int, int>> area => [high, medium, low counts] (unused placeholder for grouping) */
    private array $stats = [];

    public function __construct(string $magentoRoot, RecommendationCollector $collector)
    {
        $this->magentoRoot = rtrim($magentoRoot, '/');
        $this->collector = $collector;
    }

    public function analyze(): void
    {
        $appCode = $this->magentoRoot . '/app/code';
        if (is_dir($appCode) === false) {
            return;
        }

        $files = $this->collectPhpFiles($appCode);

        $n1Batch = [];
        $n1RepoGet = [];
        $saveNoTtl = [];
        $typeMismatch = [];

        foreach ($files as $file) {
            if (filesize($file) > self::MAX_FILE_BYTES) {
                continue;
            }
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            $lines = preg_split('/\r\n|\r|\n/', $source);
            if (is_array($lines) === false) {
                continue;
            }

            $this->scanFile($file, $lines, $n1Batch, $n1RepoGet, $saveNoTtl, $typeMismatch);
        }

        $this->reportN1Batch($n1Batch);
        $this->reportN1RepoGet($n1RepoGet);
        $this->reportSaveNoTtl($saveNoTtl);
        $this->reportTypeMismatch($typeMismatch);
    }

    /**
     * @param string[] $lines
     * @param array<int, array{file: string, line: int, code: string}> $n1Batch
     * @param array<int, array{file: string, line: int, code: string}> $n1RepoGet
     * @param array<int, array{file: string, line: int, code: string}> $saveNoTtl
     * @param array<int, array{file: string, line: int, code: string}> $typeMismatch
     */
    private function scanFile(
        string $file,
        array $lines,
        array &$n1Batch,
        array &$n1RepoGet,
        array &$saveNoTtl,
        array &$typeMismatch
    ): void {
        $relative = $this->relative($file);
        // An Exception's int $code is legitimate, not a VARCHAR key.
        $isExceptionClass = preg_match('/\bException\b/', $relative) === 1
            || preg_match('/\bextends\s+[A-Za-z_\\\\]*Exception\b/', implode("\n", $lines)) === 1;
        $loopDepth = 0;
        $loopBraceStack = [];
        $braceDepth = 0;

        foreach ($lines as $index => $rawLine) {
            $line = $this->stripCommentsAndStrings($rawLine);
            $lineNo = $index + 1;

            // Track whether a for/foreach/while header opens on this line
            $opensLoop = preg_match('/\b(foreach|for|while)\s*\(/', $line) === 1;

            // Count braces to know when a loop body ends
            $opens = substr_count($line, '{');
            $closes = substr_count($line, '}');

            if ($opensLoop === true) {
                // Loop body starts at the next '{'. Record the brace depth at
                // which this loop will close.
                $loopBraceStack[] = $braceDepth;
                $loopDepth = count($loopBraceStack);
            }

            $insideLoop = $loopDepth > 0;

            if ($insideLoop === true) {
                // N+1: single-element inline array passed to a *batch-looking* method
                if (preg_match('/->\s*(get[A-Za-z]*|load[A-Za-z]*|fetch[A-Za-z]*)\s*\(\s*\[\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\]\s*\)/', $line) === 1
                    && preg_match('/(batch|multiple|byids|forskus|byskus)/i', $line) === 1
                ) {
                    $n1Batch[] = ['file' => $relative, 'line' => $lineNo, 'code' => trim($rawLine)];
                }

                // N+1: per-item repository get with a scalar argument
                if (preg_match('/->\s*(repository|Repository)\s*->\s*(get|getById)\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\)/', $line) === 1
                    || preg_match('/\$this->[A-Za-z_][A-Za-z0-9_]*Repository->\s*(get|getById)\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\)/', $line) === 1
                ) {
                    $n1RepoGet[] = ['file' => $relative, 'line' => $lineNo, 'code' => trim($rawLine)];
                }
            }

            // cache->save() with no lifetime: ->save($data, $id, $tags) is 3 args;
            // flag when there is no 3rd (lifetime) argument at all.
            if (preg_match('/->\s*save\s*\(/', $line) === 1
                && preg_match('/(cache|Cache|_cache)\b/', $line) === 1
            ) {
                if ($this->saveCallLacksLifetime($line) === true) {
                    $saveNoTtl[] = ['file' => $relative, 'line' => $lineNo, 'code' => trim($rawLine)];
                }
            }

            // int-typed parameter with a string-keyed name (index bypass)
            if ($isExceptionClass === false) {
                foreach (self::STRING_KEYED_PARAMS as $paramName) {
                    if (preg_match('/\bint\s+\$' . preg_quote($paramName, '/') . '\b/', $rawLine) === 1) {
                        $typeMismatch[] = [
                            'file' => $relative,
                            'line' => $lineNo,
                            'code' => trim($rawLine),
                        ];
                        break;
                    }
                }
            }

            // Update brace depth, then close any loops whose body just ended
            $braceDepth += $opens - $closes;
            if ($braceDepth < 0) {
                $braceDepth = 0;
            }
            while (count($loopBraceStack) > 0 && $braceDepth <= $loopBraceStack[count($loopBraceStack) - 1]) {
                array_pop($loopBraceStack);
            }
            $loopDepth = count($loopBraceStack);
        }
    }

    /**
     * Decide whether a ->save(...) call has a third (lifetime) argument.
     * Counts top-level commas inside the outermost parentheses of the call.
     */
    private function saveCallLacksLifetime(string $line): bool
    {
        $pos = strpos($line, '->save(');
        if ($pos === false) {
            $pos = strpos($line, '->save (');
            if ($pos === false) {
                return false;
            }
        }

        $open = strpos($line, '(', $pos);
        if ($open === false) {
            return false;
        }

        $depth = 0;
        $topLevelCommas = 0;
        $closed = false;
        for ($i = $open, $len = strlen($line); $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $closed = true;
                    break;
                }
            } elseif ($ch === ',' && $depth === 1) {
                $topLevelCommas++;
            }
        }

        // Multi-line call we cannot resolve on a single line: do not flag
        // (avoids false positives when args wrap).
        if ($closed === false) {
            return false;
        }

        // 0 commas => save($data) ; 1 => save($data,$id) ; both lack lifetime.
        // 2+ => save($data,$id,$lifetime,...) => has lifetime.
        return $topLevelCommas < 2;
    }

    private function stripCommentsAndStrings(string $line): string
    {
        // Drop // and # line comments (best-effort; not string-aware)
        $line = preg_replace('/(\/\/|#).*$/', '', $line) ?? $line;

        return $line;
    }

    /**
     * @return string[]
     */
    private function collectPhpFiles(string $root): array
    {
        $result = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (count($result) >= self::MAX_FILES) {
                    break;
                }
                if ($file->isFile() === false || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if ($this->isSkippablePath($path) === true) {
                    continue;
                }
                $result[] = $path;
            }
        } catch (\Exception $e) {
            // permission / unreadable tree: return what we have
        }

        return $result;
    }

    private function isSkippablePath(string $path): bool
    {
        $needles = ['/Test/', '/Tests/', '/vendor/', '/generated/', '/dev/tests/', '/_files/'];
        foreach ($needles as $needle) {
            if (strpos($path, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $file): string
    {
        if (strpos($file, $this->magentoRoot) === 0) {
            return ltrim(substr($file, strlen($this->magentoRoot)), '/');
        }

        return $file;
    }

    /**
     * @param array<int, array{file: string, line: int, code: string}> $hits
     */
    private function reportN1Batch(array $hits): void
    {
        if (count($hits) === 0) {
            return;
        }

        $this->collector->addWithFiles(
            'codebase',
            'Potential N+1: batch method called with a single-element array inside a loop',
            Recommendation::PRIORITY_HIGH,
            $this->summariseHits($hits),
            $this->hitFiles($hits),
            'A batch/list method is being invoked with a one-element array (e.g. getBatch([$sku])) '
            . 'from within a loop, producing one database round-trip per iteration instead of one '
            . 'batched query per request. Pre-collect the identifiers and call the batch method once '
            . 'before the loop, then index the result set in memory. This was the single largest '
            . 'DB-cost category in the reference production review.',
            ['occurrences' => count($hits), 'check_id' => 'PERF-N1-BATCH']
        );
    }

    /**
     * @param array<int, array{file: string, line: int, code: string}> $hits
     */
    private function reportN1RepoGet(array $hits): void
    {
        if (count($hits) === 0) {
            return;
        }

        $this->collector->addWithFiles(
            'codebase',
            'Potential N+1: per-item repository get() inside a loop',
            Recommendation::PRIORITY_HIGH,
            $this->summariseHits($hits),
            $this->hitFiles($hits),
            'A repository get()/getById() is called with a scalar id from inside a loop. Each call '
            . 'issues its own query. Replace with a single getList() using a search criteria "in" '
            . 'filter, or a dedicated batch loader, and map the results by id before the loop.',
            ['occurrences' => count($hits), 'check_id' => 'PERF-N1-REPO']
        );
    }

    /**
     * @param array<int, array{file: string, line: int, code: string}> $hits
     */
    private function reportSaveNoTtl(array $hits): void
    {
        if (count($hits) === 0) {
            return;
        }

        $this->collector->addWithFiles(
            'cache',
            'cache->save() call with no TTL/lifetime argument',
            Recommendation::PRIORITY_MEDIUM,
            $this->summariseHits($hits),
            $this->hitFiles($hits),
            'A cache save omits the lifetime argument, so the entry never expires on its own and '
            . 'relies on explicit invalidation or eviction. At scale this contributes to Redis '
            . 'filling with no-TTL keys; under memory pressure the eviction policy then drops hot '
            . 'keys at random. Pass an explicit lifetime (seconds) as the third argument.',
            ['occurrences' => count($hits), 'check_id' => 'PERF-CACHE-NOTTL']
        );
    }

    /**
     * @param array<int, array{file: string, line: int, code: string}> $hits
     */
    private function reportTypeMismatch(array $hits): void
    {
        if (count($hits) === 0) {
            return;
        }

        $this->collector->addWithFiles(
            'database',
            'Int-typed parameter on a VARCHAR-keyed domain concept (index bypass risk)',
            Recommendation::PRIORITY_HIGH,
            $this->summariseHits($hits),
            $this->hitFiles($hits),
            'A method parameter is typed int but its name (e.g. $sourceCode, $sku, $storeCode) '
            . 'corresponds to a VARCHAR column. PHP binds the value as a number, MySQL then casts '
            . 'the VARCHAR column per row to compare, and the index on that column is silently '
            . 'bypassed — a full scan on every cache miss. Type the parameter as string and ensure '
            . 'the bound value matches the column type.',
            ['occurrences' => count($hits), 'check_id' => 'PERF-TYPE-IDX']
        );
    }

    /**
     * @param array<int, array{file: string, line: int, code: string}> $hits
     */
    private function summariseHits(array $hits): string
    {
        $shown = array_slice($hits, 0, 8);
        $parts = [];
        foreach ($shown as $hit) {
            $parts[] = sprintf('%s:%d', $hit['file'], $hit['line']);
        }
        $suffix = count($hits) > count($shown)
            ? sprintf(' (+%d more)', count($hits) - count($shown))
            : '';

        return sprintf('%d occurrence(s): %s%s', count($hits), implode(', ', $parts), $suffix);
    }

    /**
     * @param array<int, array{file: string, line: int, code: string}> $hits
     * @return string[]
     */
    private function hitFiles(array $hits): array
    {
        $files = [];
        foreach ($hits as $hit) {
            $files[] = $hit['file'];
        }

        return array_values(array_unique($files));
    }
}
