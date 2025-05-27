<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\ProductSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');

// Process command line options
$options = getopt('hd', ['help', 'debug', 'skip-draft']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo "Product Sync Tool\n";
    echo "--------------\n";
    echo "Usage: php bin/product-sync.php [options]\n\n";
    echo "Options:\n";
    echo "  -h, --help       Show this help message\n";
    echo "  -d, --debug      Enable debug mode (save detailed product data)\n";
    echo "  --skip-draft     Skip products with zero inventory instead of creating them as draft\n";
    exit(0);
}

// Check for debug mode
$debug = isset($options['d']) || isset($options['debug']);

// Check for skip-draft flag
$skipDraft = isset($options['skip-draft']);

// Catch any errors
try {
    $logger->info('Starting product sync CLI script');
    
    // Pass the options to ProductSync constructor
    $productSync = new ProductSync($debug, $skipDraft);
    $productSync->sync();
    
    $logger->info('Product sync completed');
    exit(0);
} catch (Exception $e) {
    $logger->error('Product sync failed: ' . $e->getMessage());
    exit(1);
} 