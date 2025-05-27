<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ShopifyLink;
use App\Logger\Factory as LoggerFactory;

// Create a dedicated logger for this test
$logger = LoggerFactory::getInstance('inventory-test');
$logger->info('Starting inventory verification test');

// Process command line options
$options = getopt('hfa', ['help', 'fix', 'all', 'verbose']);

// Show help message
if (isset($options['h']) || isset($options['help'])) {
    echo "Inventory Verification Test\n";
    echo "-------------------------\n";
    echo "This script tests inventory handling and fixes products with zero inventory.\n\n";
    echo "Usage: php bin/inventory-test.php [options]\n\n";
    echo "Options:\n";
    echo "  -f, --fix         Fix zero inventory products (set to draft)\n";
    echo "  -a, --all         Show all products (not just problematic ones)\n";
    echo "  --verbose         Show detailed product information\n";
    echo "  -h, --help        Show this help message\n";
    exit(0);
}

// Check for options
$fixMode = isset($options['f']) || isset($options['fix']);
$showAll = isset($options['a']) || isset($options['all']);
$verbose = isset($options['verbose']);

echo "Inventory Verification Test\n";
echo "-------------------------\n";
echo "This test will scan your Shopify store for products with zero inventory.\n";

if ($fixMode) {
    echo "FIX MODE ENABLED: Products with zero inventory will be set to draft status.\n";
} else {
    echo "READ-ONLY MODE: Use --fix to automatically set zero inventory products to draft.\n";
}

echo "\nFetching products from Shopify...\n";

// Initialize Shopify client
$shopify = new ShopifyLink();

try {
    // Fetch all products
    $allProducts = [];
    $limit = 250; // Shopify max limit
    $params = ['limit' => $limit];
    
    do {
        $products = $shopify->getProducts($params);
        $allProducts = array_merge($allProducts, $products);
        
        // Get link header for pagination
        $nextPageUrl = $shopify->getNextPageUrl();
        if ($nextPageUrl) {
            // Extract the page_info parameter from the next URL
            parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $queryParams);
            $params = ['limit' => $limit];
            if (isset($queryParams['page_info'])) {
                $params['page_info'] = $queryParams['page_info'];
            }
        } else {
            $params = null;
        }
        
        echo "Fetched " . count($allProducts) . " products so far...\n";
        
    } while ($params !== null);
    
    // Detailed inventory analysis
    $activeWithZeroInventory = [];
    $draftWithZeroInventory = [];
    $activeWithInventory = [];
    $notTracked = [];
    
    foreach ($allProducts as $product) {
        $productId = $product['id'];
        $title = $product['title'];
        $status = $product['status'];
        $inventoryTracked = false;
        $totalInventory = 0;
        $inventoryDetails = [];
        
        if (isset($product['variants']) && is_array($product['variants'])) {
            foreach ($product['variants'] as $variant) {
                $variantId = $variant['id'];
                $sku = $variant['sku'] ?? 'No SKU';
                $inventory = isset($variant['inventory_quantity']) ? (int)$variant['inventory_quantity'] : 0;
                $isTracked = isset($variant['inventory_management']) && $variant['inventory_management'] === 'shopify';
                
                if ($isTracked) {
                    $inventoryTracked = true;
                    $totalInventory += $inventory;
                }
                
                $inventoryDetails[] = [
                    'variant_id' => $variantId,
                    'sku' => $sku,
                    'inventory' => $inventory,
                    'tracked' => $isTracked
                ];
            }
        }
        
        $productInfo = [
            'id' => $productId,
            'title' => $title,
            'status' => $status,
            'inventory_tracked' => $inventoryTracked,
            'total_inventory' => $totalInventory,
            'variant_details' => $inventoryDetails
        ];
        
        // Categorize the product
        if (!$inventoryTracked) {
            $notTracked[] = $productInfo;
        } else if ($totalInventory <= 0 && $status === 'active') {
            $activeWithZeroInventory[] = $productInfo;
        } else if ($totalInventory <= 0 && $status === 'draft') {
            $draftWithZeroInventory[] = $productInfo;
        } else if ($totalInventory > 0 && $status === 'active') {
            $activeWithInventory[] = $productInfo;
        }
    }
    
    // Print analysis results
    echo "\n=== INVENTORY ANALYSIS ===\n";
    echo "Total products analyzed: " . count($allProducts) . "\n";
    echo "✅ Active products with inventory: " . count($activeWithInventory) . "\n";
    echo "⚠️ Active products with ZERO inventory: " . count($activeWithZeroInventory) . " (PROBLEM)\n";
    echo "✅ Draft products with zero inventory: " . count($draftWithZeroInventory) . "\n";
    echo "ℹ️ Products not using inventory tracking: " . count($notTracked) . "\n";
    
    // Show problematic products
    if (count($activeWithZeroInventory) > 0) {
        echo "\n⚠️ PROBLEM FOUND: These products have zero inventory but are still active:\n";
        
        foreach ($activeWithZeroInventory as $index => $product) {
            echo ($index + 1) . ". {$product['title']} (ID: {$product['id']})\n";
            
            if ($verbose) {
                echo "   Status: {$product['status']}, Total Inventory: {$product['total_inventory']}\n";
                echo "   Variants:\n";
                
                foreach ($product['variant_details'] as $variant) {
                    echo "   - SKU: {$variant['sku']}, Inventory: {$variant['inventory']}, Tracked: " . 
                         ($variant['tracked'] ? 'Yes' : 'No') . "\n";
                }
                
                echo "\n";
            }
        }
        
        // Fix mode - set these products to draft
        if ($fixMode) {
            echo "\nFIXING PRODUCTS: Setting " . count($activeWithZeroInventory) . " products to draft status...\n";
            $fixedCount = 0;
            $failedCount = 0;
            
            foreach ($activeWithZeroInventory as $product) {
                $productId = $product['id'];
                $title = $product['title'];
                
                echo "Setting '{$title}' (ID: {$productId}) to draft... ";
                
                try {
                    $updateData = [
                        'id' => $productId,
                        'status' => 'draft'
                    ];
                    
                    $result = $shopify->updateProduct($productId, $updateData);
                    
                    if ($result) {
                        echo "✓\n";
                        $fixedCount++;
                        $logger->info("Fixed product '{$title}' (ID: {$productId}) - set to draft status due to zero inventory");
                    } else {
                        echo "✗\n";
                        $failedCount++;
                        $logger->error("Failed to fix product '{$title}' (ID: {$productId})");
                    }
                    
                    // Add a delay to avoid rate limits
                    usleep(250000); // 250ms
                    
                } catch (Exception $e) {
                    echo "✗ Error: " . $e->getMessage() . "\n";
                    $failedCount++;
                    $logger->error("Error fixing product '{$title}' (ID: {$productId}): " . $e->getMessage());
                }
            }
            
            echo "\nFixed {$fixedCount} products. Failed: {$failedCount}\n";
        } else {
            echo "\nTo fix these products, run the script with the --fix option:\n";
            echo "php bin/inventory-test.php --fix\n";
        }
    } else {
        echo "\n✅ GOOD: No active products with zero inventory found.\n";
    }
    
    // Show all products if requested
    if ($showAll) {
        echo "\n=== ALL PRODUCTS ===\n";
        
        foreach ($allProducts as $index => $product) {
            $inventory = 0;
            $tracked = false;
            
            if (isset($product['variants']) && is_array($product['variants'])) {
                foreach ($product['variants'] as $variant) {
                    if (isset($variant['inventory_management']) && $variant['inventory_management'] === 'shopify') {
                        $tracked = true;
                        $inventory += isset($variant['inventory_quantity']) ? (int)$variant['inventory_quantity'] : 0;
                    }
                }
            }
            
            echo ($index + 1) . ". {$product['title']} (ID: {$product['id']})\n";
            echo "   Status: {$product['status']}, Inventory: " . ($tracked ? $inventory : 'Not Tracked') . "\n";
            
            if ($verbose && isset($product['variants'])) {
                echo "   Variants:\n";
                foreach ($product['variants'] as $variant) {
                    $variantInventory = isset($variant['inventory_quantity']) ? (int)$variant['inventory_quantity'] : 'N/A';
                    $variantTracked = isset($variant['inventory_management']) && $variant['inventory_management'] === 'shopify';
                    
                    echo "   - SKU: " . ($variant['sku'] ?? 'No SKU') . 
                         ", Inventory: " . $variantInventory . 
                         ", Tracked: " . ($variantTracked ? 'Yes' : 'No') . "\n";
                }
            }
            
            echo "\n";
        }
    }
    
    // Check for modified sync code
    echo "\n=== CODE VERIFICATION ===\n";
    $syncFile = __DIR__ . '/../src/Sync/ProductSync.php';
    $codeVerified = false;
    
    if (file_exists($syncFile)) {
        $content = file_get_contents($syncFile);
        if (strpos($content, 'INVENTORY CHECK') !== false) {
            echo "✅ ProductSync.php contains updated inventory check code.\n";
            $codeVerified = true;
        }
    }
    
    if (!$codeVerified) {
        echo "⚠️ WARNING: Could not verify that ProductSync.php has been updated with the latest inventory check code.\n";
        echo "Make sure the code changes were properly applied.\n";
    }
    
    // Final recommendations
    echo "\n=== RECOMMENDATIONS ===\n";
    
    if (count($activeWithZeroInventory) > 0) {
        echo "1. Run this script with --fix to set zero inventory products to draft status.\n";
        echo "2. Verify that the product sync code changes have been applied correctly.\n";
        echo "3. Run a full product sync with the updated code:\n";
        echo "   php bin/product-sync.php\n";
    } else {
        echo "Your inventory handling appears to be working correctly. All zero inventory products are in draft status.\n";
    }
    
} catch (Exception $e) {
    $logger->error('Test failed: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 