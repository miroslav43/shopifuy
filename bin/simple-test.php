<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;

// Get command line option for what to test
$testType = isset($argv[1]) ? strtolower($argv[1]) : 'all';

echo "Starting API integration test\n";

// Create API instances
try {
    echo "Initializing PowerBody API...\n";
    $powerbody = new PowerBodyLink();
    echo "PowerBody API initialized successfully.\n";
} catch (Exception $e) {
    echo "Error initializing PowerBody API: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    echo "Initializing Shopify API...\n";
    $shopify = new ShopifyLink();
    echo "Shopify API initialized successfully.\n";
} catch (Exception $e) {
    echo "Error initializing Shopify API: " . $e->getMessage() . "\n";
    exit(1);
}

// Test PowerBody API
function testPowerbodyAPI($powerbody) {
    echo "=== Testing PowerBody API ===\n";
    
    try {
        // 1. Test product list
        echo "Testing getProductList...\n";
        $productList = $powerbody->getProductList();
        
        if (empty($productList)) {
            echo "Error: Failed to get product list\n";
            return false;
        }
        
        echo "Success! Fetched " . count($productList) . " products\n";
        
        // 2. Test product info
        if (!empty($productList)) {
            $testProductId = $productList[0]['product_id'];
            echo "Testing getProductInfo for product ID: " . $testProductId . "\n";
            
            $productInfo = $powerbody->getProductInfo($testProductId);
            
            if (empty($productInfo)) {
                echo "Error: Failed to get product info\n";
                return false;
            }
            
            echo "Success! Fetched product info for " . ($productInfo['name'] ?? 'Unknown Product') . "\n";
        }
        
        return true;
    } catch (Exception $e) {
        echo "PowerBody API test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

// Test Shopify API
function testShopifyAPI($shopify) {
    echo "=== Testing Shopify API ===\n";
    
    try {
        // 1. Test product fetching
        echo "Testing getProducts...\n";
        $params = ['limit' => 5];
        $products = $shopify->getProducts($params);
        
        if (empty($products)) {
            echo "Warning: No products found in Shopify\n";
        } else {
            echo "Success! Fetched " . count($products) . " products\n";
            
            // 2. Test product info for first product
            if (!empty($products)) {
                $testProduct = $products[0];
                $testProductId = $testProduct['id'];
                
                echo "Testing getProduct for product ID: " . $testProductId . "\n";
                
                $productDetails = $shopify->getProduct($testProductId);
                
                if (empty($productDetails)) {
                    echo "Error: Failed to get product details\n";
                    return false;
                }
                
                echo "Success! Fetched product details for: " . $productDetails['title'] . "\n";
            }
        }
        
        return true;
    } catch (Exception $e) {
        echo "Shopify API test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run tests based on command line option
$allSuccess = true;

if ($testType == 'all' || $testType == 'powerbody') {
    $pbSuccess = testPowerbodyAPI($powerbody);
    $allSuccess = $allSuccess && $pbSuccess;
}

if ($testType == 'all' || $testType == 'shopify') {
    $shopifySuccess = testShopifyAPI($shopify);
    $allSuccess = $allSuccess && $shopifySuccess;
}

// Print final result
if ($allSuccess) {
    echo "\n✅ All tests completed successfully!\n";
} else {
    echo "\n❌ Some tests failed. See above for details.\n";
}

echo "API integration test completed\n"; 