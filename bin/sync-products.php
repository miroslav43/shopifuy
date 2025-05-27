<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\ProductSync;

// Process command line options
$options = getopt('h', ['debug', 'help', 'skip-draft']);

// Show help message
if (isset($options['h']) || isset($options['help'])) {
    echo "PowerBody to Shopify Product Sync\n";
    echo "--------------------------------\n";
    echo "Usage: php bin/sync-products.php [batch_index] [options]\n\n";
    echo "Arguments:\n";
    echo "  batch_index   Optional. Start synchronization from specific batch index (default: 0)\n\n";
    echo "Options:\n";
    echo "  --debug       Save detailed product data from API as JSON files\n";
    echo "  --skip-draft  Skip creating products with zero inventory\n";
    echo "  -h, --help    Show this help message\n";
    exit(0);
}

// Check if debug mode is enabled
$debug = isset($options['debug']);
if ($debug) {
    echo "Debug mode enabled - detailed product info will be saved to storage/debug/\n";
}

// Check if skip-draft mode is enabled
$skipDraft = isset($options['skip-draft']);
if ($skipDraft) {
    echo "Skip draft mode enabled - products with zero inventory will not be created\n";
}

// Get batch index from command line arguments
$startBatchIndex = 0;
if (isset($argv[1]) && is_numeric($argv[1]) && !isset($options[$argv[1]])) {
    $startBatchIndex = (int)$argv[1];
    echo "Starting from batch index $startBatchIndex\n";
}

// Show start message
echo "Starting product synchronization...\n";
$startTime = microtime(true);

try {
    // Create product sync instance
    $productSync = new ProductSync($debug, $skipDraft);
    
    // Run the product sync from specified batch
    $productSync->sync($startBatchIndex);
    
    // Show success message
    $duration = round(microtime(true) - $startTime, 2);
    echo "✅ Product synchronization completed successfully in {$duration} seconds.\n";
} catch (\Exception $e) {
    // Show error message
    echo "❌ Error during product synchronization: " . $e->getMessage() . "\n";
    exit(1);
} 