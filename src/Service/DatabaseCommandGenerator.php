<?php

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

class DatabaseCommandGenerator extends BaseScriptGenerator
{
    public function generate(array $recommendations): ?string
    {
        $dbRecommendations = array_filter($recommendations, function($rec) {
            return in_array($rec->getArea(), ['database', 'indexing', 'indexers']);
        });
        
        if (empty($dbRecommendations)) {
            return null;
        }
        
        $timestamp = date('Y-m-d_His');
        $filename = "fix_database_issues_{$timestamp}.sh";
        
        $script = $this->generateDatabaseScript($dbRecommendations);
        
        if ($this->writeScript($filename, $script)) {
            return $filename;
        }
        
        return null;
    }
    
    private function generateDatabaseScript(array $recommendations): string
    {
        $script = $this->generateHeader('Database Optimization Script');
        
        $script .= "echo \"🔧 Database Optimization\"\n";
        $script .= "echo \"======================\"\n\n";
        
        // Check if we're in Magento root
        $script .= "# Verify we're in Magento root directory\n";
        $script .= "if [ ! -f \"bin/magento\" ]; then\n";
        $script .= "    echo \"❌ Error: bin/magento not found. Please run from Magento root directory.\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";
        
        foreach ($recommendations as $rec) {
            $script .= $this->generateDatabaseCommands($rec);
        }
        
        $script .= "echo \"\"\n";
        $script .= "echo \"✅ Database optimization completed!\"\n";
        $script .= "echo \"Remember to:\"\n";
        $script .= "echo \"  1. Monitor database performance\"\n";
        $script .= "echo \"  2. Regular maintenance tasks\"\n";
        $script .= "echo \"  3. Monitor slow query log\"\n";
        
        return $script;
    }
    
    private function generateDatabaseCommands(Recommendation $rec): string
    {
        $script = "";
        $title = $rec->getTitle();
        
        $script .= "echo \"📋 {$title}\"\n";
        $script .= "echo \"" . str_repeat('-', strlen($title) + 5) . "\"\n";
        
        if (stripos($title, 'indexer') !== false) {
            $script .= "echo \"  → Running indexer operations...\"\n";
            $script .= "php bin/magento indexer:status\n";
            $script .= "php bin/magento indexer:reindex\n";
            $script .= "echo \"    ✓ Reindexing completed\"\n";
        } elseif (stripos($title, 'deadlock') !== false) {
            $script .= "echo \"  → Checking for deadlocks...\"\n";
            $script .= "echo \"    Manual investigation required - check MySQL error logs\"\n";
        } else {
            $script .= "echo \"  → Manual action required\"\n";
            $script .= "echo \"    See analysis report for details\"\n";
        }
        
        $script .= "echo \"\"\n\n";
        
        return $script;
    }
}
