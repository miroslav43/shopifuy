<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\PowerBodyLink;
use App\Logger\Factory as LoggerFactory;
use App\Sync\ProductSync;

// Initialize logger
$logger = LoggerFactory::getInstance('concurrent-test');
$logger->info('Starting concurrent fetching test');

// Create PowerBody instance
$powerbody = new PowerBodyLink();

try {
    // Get product list to find some test products
    $logger->info('Fetching product list to find test products');
    $productList = $powerbody->getProductList();
    
    if (empty($productList)) {
        echo "No products found in PowerBody API\n";
        exit(1);
    }
    
    // Select the first 15 products for testing
    $testProducts = array_slice($productList, 0, 15);
    $productIds = array_column($testProducts, 'product_id');
    
    echo "Selected " . count($productIds) . " products for testing\n";
    
    // Test 1: Regular sequential fetching
    echo "\n=== Test 1: Regular Sequential Fetching ===\n";
    $regularStart = microtime(true);
    
    foreach ($productIds as $index => $productId) {
        echo "Fetching product {$index}: {$productId}... ";
        $productInfo = $powerbody->getProductInfo($productId);
        
        if ($productInfo) {
            echo "SUCCESS\n";
        } else {
            echo "FAILED\n";
        }
    }
    
    $regularTime = microtime(true) - $regularStart;
    echo "Total regular fetching time: " . round($regularTime, 2) . " seconds\n";
    
    // Clear cache for fair comparison (if needed)
    if (isset($argv[1]) && $argv[1] === '--clear-cache') {
        echo "\nClearing cache for fair comparison...\n";
        $powerbody->clearProductCache();
    }
    
    // Test 2: Concurrent fetching
    echo "\n=== Test 2: Concurrent Fetching ===\n";
    
    // Use the ProductSync class for concurrent fetching
    $productSync = new ProductSync();
    $concurrentStart = microtime(true);
    
    // Get access to the protected method using reflection
    $reflectionClass = new ReflectionClass('App\Sync\ProductSync');
    $method = $reflectionClass->getMethod('fetchProductDetailsConcurrently');
    $method->setAccessible(true);
    
    // Call the method
    echo "Fetching " . count($productIds) . " products concurrently...\n";
    $results = $method->invokeArgs($productSync, [$productIds, 5]);
    
    $concurrentTime = microtime(true) - $concurrentStart;
    $successCount = count($results);
    
    echo "Successfully fetched {$successCount} products\n";
    echo "Total concurrent fetching time: " . round($concurrentTime, 2) . " seconds\n";
    
    // Compare results
    echo "\n=== Comparison ===\n";
    echo "Regular fetching time: " . round($regularTime, 2) . " seconds\n";
    echo "Concurrent fetching time: " . round($concurrentTime, 2) . " seconds\n";
    echo "Time saved: " . round($regularTime - $concurrentTime, 2) . " seconds (" . 
         round((($regularTime - $concurrentTime) / $regularTime) * 100, 2) . "% faster)\n";
    
} catch (Exception $e) {
    $logger->error('Test failed: ' . $e->getMessage());
    echo "Test failed: " . $e->getMessage() . "\n";
} 