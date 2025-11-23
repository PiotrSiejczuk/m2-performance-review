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
