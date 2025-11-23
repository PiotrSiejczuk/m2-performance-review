<?php
// Debug build script to identify PHAR building issues
// Usage: php -d phar.readonly=0 build-phar-debug.php

$binDir  = __DIR__ . '/bin';
$pharFile = $binDir . '/m2-performance.phar';

fwrite(STDOUT, "=== DEBUG PHAR BUILD ===\n\n");

// Check phar.readonly
if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Error: phar.readonly is enabled\n");
    exit(1);
}

// Ensure bin directory exists
if (!is_dir($binDir)) {
    mkdir($binDir, 0755, true);
}

// Remove old PHAR
if (file_exists($pharFile)) {
    unlink($pharFile);
}

// Check for problematic files
fwrite(STDOUT, "Checking source files...\n");
$problematicFiles = [];
$sourceFiles = [
    'src/Command/MonitorCommand.php',
    'src/Command/CacheMonitorCommand.php',
    'src/Command/SelfUpdateCommand.php',
    'src/Service/CacheMetricsService.php',
    'src/Service/UpdateChecker.php',
    'src/Service/VarnishCommandGenerator.php',
    'src/Analyzer/VarnishPerformanceAnalyzer.php'
];

foreach ($sourceFiles as $file) {
    if (!file_exists($file)) {
        fwrite(STDERR, "❌ Missing: $file\n");
        $problematicFiles[] = $file;
    } else {
        $size = filesize($file);
        $content = file_get_contents($file);
        
        // Check for BOM
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            fwrite(STDERR, "❌ BOM detected in: $file\n");
            $problematicFiles[] = $file;
        }
        
        // Check for null bytes
        if (strpos($content, "\0") !== false) {
            fwrite(STDERR, "❌ Null bytes in: $file\n");
            $problematicFiles[] = $file;
        }
        
        // Check syntax
        $output = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $return);
        if ($return !== 0) {
            fwrite(STDERR, "❌ Syntax error in: $file\n");
            fwrite(STDERR, "   " . implode("\n   ", $output) . "\n");
            $problematicFiles[] = $file;
        } else {
            fwrite(STDOUT, "✓ OK: $file ($size bytes)\n");
        }
    }
}

if (!empty($problematicFiles)) {
    fwrite(STDERR, "\n⚠️  Found " . count($problematicFiles) . " problematic files. Fix these before building.\n");
    exit(1);
}

fwrite(STDOUT, "\nAll source files OK. Building PHAR...\n");

try {
    $phar = new Phar($pharFile, 0, 'm2-performance.phar');
    
    // Disable compression initially
    $phar->startBuffering();
    
    // Add files one by one with verification
    $fileCount = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.(php|bash|fish|zsh)$/', $file->getFilename())) {
            $relativePath = str_replace(__DIR__ . '/', '', $file->getPathname());
            
            // Skip build scripts and test files
            if (preg_match('/(build-phar|test|Test|\.git)/i', $relativePath)) {
                continue;
            }
            
            try {
                // Read file content
                $content = file_get_contents($file->getPathname());
                
                // Add to PHAR
                $phar->addFromString($relativePath, $content);
                $fileCount++;
                
                if ($fileCount % 50 === 0) {
                    fwrite(STDOUT, "Added $fileCount files...\n");
                }
                
            } catch (Exception $e) {
                fwrite(STDERR, "❌ Error adding $relativePath: " . $e->getMessage() . "\n");
            }
        }
    }
    
    fwrite(STDOUT, "Added $fileCount files total\n");
    
    // Create stub
    $stub = <<<'STUB'
#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    exit('This tool must be run from the command line.' . PHP_EOL);
}
Phar::mapPhar('m2-performance.phar');
require 'phar://m2-performance.phar/vendor/autoload.php';
require 'phar://m2-performance.phar/src/index.php';
__HALT_COMPILER();
STUB;
    
    $phar->setStub($stub);
    
    // Stop buffering WITHOUT compression first
    $phar->stopBuffering();
    
    fwrite(STDOUT, "\nVerifying PHAR...\n");
    
    // Verify the PHAR
    $testPhar = new Phar($pharFile);
    $verified = true;
    
    // Check critical files
    $criticalFiles = [
        'src/Command/MonitorCommand.php',
        'src/Command/M2PerformanceCommand.php',
        'src/Application.php',
        'vendor/autoload.php'
    ];
    
    foreach ($criticalFiles as $file) {
        if (isset($testPhar[$file])) {
            fwrite(STDOUT, "✓ Found: $file\n");
        } else {
            fwrite(STDERR, "❌ Missing: $file\n");
            $verified = false;
        }
    }
    
    if ($verified) {
        // Now try compression
        fwrite(STDOUT, "\nCompressing PHAR...\n");
        try {
            if (Phar::canCompress(Phar::GZ)) {
                $phar->compressFiles(Phar::GZ);
                fwrite(STDOUT, "✓ Compressed with GZ\n");
            }
        } catch (Exception $e) {
            fwrite(STDERR, "⚠️  Compression failed: " . $e->getMessage() . "\n");
            fwrite(STDERR, "   PHAR will work without compression\n");
        }
        
        // Make executable
        chmod($pharFile, 0755);
        
        $size = round(filesize($pharFile) / 1024 / 1024, 2);
        fwrite(STDOUT, "\n✅ PHAR created successfully: $pharFile ({$size} MB)\n");
        
        // Test execution
        fwrite(STDOUT, "\nTesting PHAR execution...\n");
        $output = [];
        $return = 0;
        exec('php ' . escapeshellarg($pharFile) . ' --version 2>&1', $output, $return);
        
        if ($return === 0) {
            fwrite(STDOUT, "✅ Execution test passed\n");
            fwrite(STDOUT, "   " . implode("\n   ", $output) . "\n");
        } else {
            fwrite(STDERR, "❌ Execution test failed\n");
            fwrite(STDERR, "   " . implode("\n   ", $output) . "\n");
        }
    } else {
        fwrite(STDERR, "\n❌ PHAR verification failed\n");
        exit(1);
    }
    
} catch (Exception $e) {
    fwrite(STDERR, "\n❌ Build failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

/**
 * Updated PHAR File Generation
 *
 * #1: composer dump-autoload --optimize
 * #2: php -d phar.readonly=0 build-phar.php
 */