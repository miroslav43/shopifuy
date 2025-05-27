<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ShopifyLink;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('update-zero-inventory');
$logger->info('Starting zero inventory update');

// Process command line options
$options = getopt('hf', ['help', 'force']);

// Show help message
if (isset($options['h']) || isset($options['help'])) {
    echo "Update Zero Inventory Products Tool\n";
    echo "--------------------------------\n";
    echo "This script will set all products with zero inventory to draft status.\n\n";
    echo "Usage: php bin/update-zero-inventory.php [options]\n\n";
    echo "Options:\n";
    echo "  -f, --force       Run without confirmation prompt\n";
    echo "  -h, --help        Show this help message\n";
    exit(0);
}

// Check for force mode
$force = isset($options['f']) || isset($options['force']);

// Initialize Shopify client
$shopify = new ShopifyLink();

try {
    // Fetch all products
    $allProducts = [];
    $limit = 250; // Shopify max limit
    $params = ['limit' => $limit];
    
    echo "Fetching products from Shopify...\n";
    
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
    
    // Filter for products with zero inventory that are not draft
    $zeroInventoryProducts = [];
    
    foreach ($allProducts as $product) {
        if (isset($product['variants']) && is_array($product['variants'])) {
            foreach ($product['variants'] as $variant) {
                // Check for zero inventory
                $hasInventory = false;
                
                // If inventory_quantity exists and is greater than 0
                if (isset($variant['inventory_quantity']) && $variant['inventory_quantity'] > 0) {
                    $hasInventory = true;
                }
                
                // If inventory_management is not set to track inventory
                if (isset($variant['inventory_management']) && $variant['inventory_management'] != 'shopify') {
                    $hasInventory = true; // Assume it has inventory if not tracked
                }
                
                if (!$hasInventory && $product['status'] !== 'draft') {
                    $zeroInventoryProducts[] = $product;
                    break; // Only need to find one variant with zero inventory
                }
            }
        }
    }
    
    $count = count($zeroInventoryProducts);
    
    if ($count === 0) {
        echo "No products with zero inventory found that are not already in draft status.\n";
        exit(0);
    }
    
    echo "\nFound {$count} products with zero inventory that are not in draft status:\n";
    
    // Show a sample of products
    $sample = array_slice($zeroInventoryProducts, 0, min(5, $count));
    foreach ($sample as $index => $product) {
        echo ($index + 1) . ". {$product['title']} (ID: {$product['id']}, Status: {$product['status']})\n";
    }
    
    if ($count > 5) {
        echo "... and " . ($count - 5) . " more products\n";
    }
    
    // Confirm before proceeding
    if (!$force) {
        echo "\nDo you want to set these {$count} products to draft status? (y/n): ";
        $confirmation = trim(fgets(STDIN));
        
        if (strtolower($confirmation) !== 'y') {
            echo "Operation cancelled.\n";
            exit(0);
        }
    }
    
    // Update products to draft status
    echo "\nUpdating {$count} products to draft status...\n";
    $updated = 0;
    $failed = 0;
    
    foreach ($zeroInventoryProducts as $index => $product) {
        $productId = $product['id'];
        $title = $product['title'];
        
        echo "Updating [{$index}/{$count}] {$title}... ";
        
        try {
            $updateData = [
                'id' => $productId,
                'status' => 'draft'
            ];
            
            $result = $shopify->updateProduct($productId, $updateData);
            
            if ($result) {
                echo "✓\n";
                $updated++;
            } else {
                echo "✗\n";
                $failed++;
            }
            
            // Add a small delay to avoid hitting rate limits
            usleep(250000); // 250ms
            
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            $failed++;
        }
        
        // Show progress every 10 products
        if (($index + 1) % 10 === 0) {
            echo "Progress: " . ($index + 1) . "/" . $count . " (" . round((($index + 1) / $count) * 100, 1) . "%)\n";
        }
    }
    
    echo "\nDone! Updated {$updated} products to draft status. Failed: {$failed}\n";
    
} catch (Exception $e) {
    $logger->error('Update failed: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 