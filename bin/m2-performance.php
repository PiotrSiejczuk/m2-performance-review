#!/usr/bin/env php
<?php
//declare(strict_types=1);
//
//require __DIR__ . '/../vendor/autoload.php';
//
//use M2Performance\Application;
//
//// The Application class is in src/Application.php
//$app = new Application();
//exit($app->run());
/**
 * Magento 2 Performance Review Tool
 *
 * @author Piotr Siejczuk
 * @license GPL-3.0
 */

// Find autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',           // Local development
    __DIR__ . '/../../../autoload.php',            // Installed as dependency
    __DIR__ . '/../autoload.php',                  // Alternative structure
];

$autoloaderFound = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    fwrite(STDERR,
        "Error: Unable to find autoloader.\n" .
        "Please run 'composer install' first.\n"
    );
    exit(1);
}

// Check PHP version
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "Error: PHP 7.4 or higher is required. Current version: " . PHP_VERSION . "\n");
    exit(1);
}

// Check if running from Magento root (unless --magento-root is specified)
$hasRootOption = false;
foreach ($argv as $arg) {
    if (strpos($arg, '--magento-root') === 0) {
        $hasRootOption = true;
        break;
    }
}

if (!$hasRootOption && !file_exists(getcwd() . '/app/etc/env.php')) {
    fwrite(STDERR,
        "Warning: Not running from Magento root directory.\n" .
        "Please run from Magento root or specify: --magento-root=/path/to/magento\n\n"
    );
}

// Run application
use M2Performance\Application;

try {
    $application = new Application();
    $exitCode = $application->run();
    exit($exitCode);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}