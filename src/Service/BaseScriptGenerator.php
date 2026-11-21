<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

/**
 * Base class for script generators with proper line ending handling
 */
abstract class BaseScriptGenerator
{
    protected function normalizeLineEndings(string $content): string
    {
        // Convert all line endings to Unix style (LF only)
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        return $content;
    }
    
    protected function writeScript(string $filename, string $content): bool
    {
        $content = $this->normalizeLineEndings($content);
        $result = file_put_contents($filename, $content);
        
        if ($result !== false) {
            chmod($filename, 0755); // Make executable
            return true;
        }
        
        return false;
    }
    
    /**
     * Single-quote a value for bash. Titles/values come from analyzers and
     * may contain quotes or $; double-quoted echo lines broke on
     * 'cacheable="false"' and would expand anything shell-like.
     * (escapeshellarg() is avoided: it can drop multibyte chars under a C locale.)
     */
    protected function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    protected function echoLine(string $text): string
    {
        return 'echo ' . $this->shellQuote($text) . "\n";
    }

    protected function generateShebang(): string
    {
        return "#!/bin/bash\n";
    }
    
    protected function generateHeader(string $title): string
    {
        $timestamp = date('Y-m-d H:i:s');
        return $this->generateShebang() .
               "# {$title}\n" .
               "# Generated: {$timestamp}\n" .
               "# WARNING: Review all commands before execution\n\n" .
               "set -e  # Exit on any error\n\n";
    }
}
