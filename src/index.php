<?php
/**
 * M2 Performance Review Tool
 * 
 * @author Piotr Siejczuk
 * @link https://github.com/PiotrSiejczuk
 */

// Ensure we're running from CLI
if (PHP_SAPI !== 'cli') {
    exit('This tool must be run from the command line.' . PHP_EOL);
}

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    fwrite(STDERR, sprintf('Error: PHP 7.4 or higher is required. You are using PHP %s.' . PHP_EOL, PHP_VERSION));
    exit(1);
}

// Check required extensions
$requiredExtensions = ['json', 'pdo', 'pdo_mysql'];
$missingExtensions = array_filter($requiredExtensions, function($ext) {
    return !extension_loaded($ext);
});

if (!empty($missingExtensions)) {
    fwrite(STDERR, 'Error: Missing required PHP extensions: ' . implode(', ', $missingExtensions) . PHP_EOL);
    exit(1);
}

// Find and load autoloader
$autoloaderPaths = [
    __DIR__ . '/../vendor/autoload.php',     // Development
    __DIR__ . '/../../vendor/autoload.php',   // Alternative structure
    'phar://m2-performance.phar/vendor/autoload.php', // PHAR
];

$autoloaderFound = false;
foreach ($autoloaderPaths as $path) {
    if (file_exists($path)) {
        require $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    fwrite(STDERR, "Error: Composer autoloader not found. Please run 'composer install'." . PHP_EOL);
    exit(1);
}

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Run application
try {
    $application = new \M2Performance\Application();
    $exitCode = $application->run();
    exit($exitCode);
} catch (Exception $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    if (getenv('DEBUG') || in_array('--debug', $_SERVER['argv'] ?? [])) {
        fwrite(STDERR, 'Stack trace:' . PHP_EOL);
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
