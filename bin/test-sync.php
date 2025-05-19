<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Sync\ProductSync;
use App\Logger\Factory as LoggerFactory;

// Initialize logger
$logger = LoggerFactory::getInstance('test-sync');
$logger->info('Starting API integration test');

// Get command line option for what to test
$testType = isset($argv[1]) ? strtolower($argv[1]) : 'all';

// Create API instances
$powerbody = new PowerBodyLink();
$shopify = new ShopifyLink();

// Test PowerBody API
function testPowerbodyAPI($powerbody, $logger) {
    $logger->info("=== Testing PowerBody API ===");
    
    try {
        // 1. Test product list
        $logger->info("Testing getProductList...");
        $productList = $powerbody->getProductList();
        
        if (empty($productList)) {
            $logger->error("Failed to get product list");
            return false;
        }
        
        $logger->info("Success! Fetched " . count($productList) . " products");
        
        // 2. Test product info
        if (!empty($productList)) {
            $testProductId = $productList[0]['product_id'];
            $logger->info("Testing getProductInfo for product ID: " . $testProductId);
            
            $productInfo = $powerbody->getProductInfo($testProductId);
            
            if (empty($productInfo)) {
                $logger->error("Failed to get product info");
                return false;
            }
            
            $logger->info("Success! Fetched product info for " . ($productInfo['name'] ?? 'Unknown Product'));
        }
        
        // 3. Test order fetching
        $logger->info("Testing getOrders with date filter...");
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        $filter = [
            'from' => $yesterday,
            'to' => $today
        ];
        
        $orders = $powerbody->getOrders($filter);
        $logger->info("Success! Fetched " . count($orders) . " orders from the last 2 days");
        
        return true;
    } catch (Exception $e) {
        $logger->error("PowerBody API test failed: " . $e->getMessage());
        return false;
    }
}

// Test Shopify API
function testShopifyAPI($shopify, $logger) {
    $logger->info("=== Testing Shopify API ===");
    
    try {
        // 1. Test product fetching
        $logger->info("Testing getProducts...");
        $params = ['limit' => 5];
        $products = $shopify->getProducts($params);
        
        if (empty($products)) {
            $logger->warning("No products found in Shopify");
        } else {
            $logger->info("Success! Fetched " . count($products) . " products");
            
            // 2. Test product updates
            if (!empty($products)) {
                $testProduct = $products[0];
                $testProductId = $testProduct['id'];
                
                $logger->info("Testing updateProduct for product ID: " . $testProductId);
                
                // Create a small update
                $update = [
                    'id' => $testProductId,
                    'tags' => 'test-tag-' . date('Ymd')
                ];
                
                $updated = $shopify->updateProduct($testProductId, $update);
                
                if (empty($updated)) {
                    $logger->error("Failed to update product");
                    return false;
                }
                
                $logger->info("Success! Updated product: " . $updated['title']);
                
                // 3. Test inventory fetching
                if (!empty($testProduct['variants'])) {
                    $variant = $testProduct['variants'][0];
                    
                    if (isset($variant['inventory_item_id'])) {
                        $inventoryItemId = $variant['inventory_item_id'];
                        $locationId = $shopify->getLocationId();
                        
                        $logger->info("Testing inventory level update...");
                        
                        // Test getting the current level first
                        $params = [
                            'inventory_item_ids' => $inventoryItemId,
                            'location_ids' => $locationId
                        ];
                        
                        $levels = $shopify->getInventoryLevels($params);
                        
                        if (!empty($levels)) {
                            $currentLevel = $levels[0]['available'] ?? 0;
                            $logger->info("Current inventory level: " . $currentLevel);
                            
                            // Test updating with the same value
                            $result = $shopify->updateInventoryLevel($inventoryItemId, $locationId, $currentLevel);
                            
                            if (empty($result)) {
                                $logger->error("Failed to update inventory level");
                            } else {
                                $logger->info("Success! Updated inventory level");
                            }
                        } else {
                            $logger->warning("No inventory levels found for testing");
                        }
                    }
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        $logger->error("Shopify API test failed: " . $e->getMessage());
        return false;
    }
}

// Test product sync with caching
function testProductSync($logger) {
    $logger->info("=== Testing Product Sync with Improved Caching ===");
    
    try {
        $productSync = new ProductSync();
        
        // Run a small sync
        $logger->info("Running a limited product sync test...");
        
        // Use reflection to access private method
        $reflection = new ReflectionClass('App\Sync\ProductSync');
        $processMethod = $reflection->getMethod('processProductsBatchDirect');
        $processMethod->setAccessible(true);
        
        // Get 10 products to test with
        $powerbody = new PowerBodyLink();
        $productList = $powerbody->getProductList();
        $testProducts = array_slice($productList, 0, 10);
        
        // Test the processing
        $startTime = microtime(true);
        $processMethod->invokeArgs($productSync, [$testProducts]);
        $endTime = microtime(true);
        
        $logger->info("Product sync completed in " . round($endTime - $startTime, 2) . " seconds");
        
        return true;
    } catch (Exception $e) {
        $logger->error("Product sync test failed: " . $e->getMessage());
        return false;
    }
}

// Run tests based on command line option
$allSuccess = true;

if ($testType == 'all' || $testType == 'powerbody') {
    $pbSuccess = testPowerbodyAPI($powerbody, $logger);
    $allSuccess = $allSuccess && $pbSuccess;
}

if ($testType == 'all' || $testType == 'shopify') {
    $shopifySuccess = testShopifyAPI($shopify, $logger);
    $allSuccess = $allSuccess && $shopifySuccess;
}

if ($testType == 'all' || $testType == 'sync') {
    $syncSuccess = testProductSync($logger);
    $allSuccess = $allSuccess && $syncSuccess;
}

// Print final result
if ($allSuccess) {
    echo "\n✅ All tests completed successfully!\n";
} else {
    echo "\n❌ Some tests failed. Please check the logs for details.\n";
}

$logger->info('API integration test completed'); 