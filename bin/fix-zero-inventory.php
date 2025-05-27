<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ShopifyLink;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('fix-zero-inventory');
$logger->info('Starting zero inventory fix script');

// Process command line options
$options = getopt('hfd', ['help', 'force', 'delete']);

// Show help message
if (isset($options['h']) || isset($options['help'])) {
    echo "Fix Zero Inventory Products Tool\n";
    echo "-----------------------------\n";
    echo "This script fixes all products with zero inventory.\n\n";
    echo "Usage: php bin/fix-zero-inventory.php [options]\n\n";
    echo "Options:\n";
    echo "  -f, --force       Skip confirmation prompts\n";
    echo "  -d, --delete      Delete zero inventory products instead of setting to draft\n";
    echo "  -h, --help        Show this help message\n";
    exit(0);
}

// Check for options
$force = isset($options['f']) || isset($options['force']);
$delete = isset($options['d']) || isset($options['delete']);

echo "Zero Inventory Fix Tool\n";
echo "--------------------\n";

if ($delete) {
    echo "⚠️ WARNING: DELETE MODE ENABLED - Products with zero inventory will be PERMANENTLY DELETED!\n";
} else {
    echo "Products with zero inventory will be set to draft status.\n";
}

if (!$force) {
    echo "\nContinue? (y/n): ";
    $confirmation = strtolower(trim(fgets(STDIN)));
    if ($confirmation !== 'y') {
        echo "Operation cancelled.\n";
        exit(0);
    }
}

// Initialize Shopify client
$shopify = new ShopifyLink();

try {
    // Fetch all products
    $allProducts = [];
    $limit = 250; // Shopify max limit
    $params = ['limit' => $limit];
    
    echo "\nFetching products from Shopify...\n";
    
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
    
    // Filter products with zero inventory
    $zeroInventoryProducts = [];
    
    foreach ($allProducts as $product) {
        $hasInventory = false;
        
        if (isset($product['variants']) && is_array($product['variants'])) {
            foreach ($product['variants'] as $variant) {
                if (isset($variant['inventory_management']) && $variant['inventory_management'] === 'shopify') {
                    $inventory = isset($variant['inventory_quantity']) ? (int)$variant['inventory_quantity'] : 0;
                    
                    if ($inventory > 0) {
                        $hasInventory = true;
                        break;
                    }
                } else {
                    // If not tracking inventory, consider it has inventory
                    $hasInventory = true;
                    break;
                }
            }
        }
        
        if (!$hasInventory) {
            $zeroInventoryProducts[] = $product;
        }
    }
    
    $count = count($zeroInventoryProducts);
    
    if ($count === 0) {
        echo "\nNo products with zero inventory found.\n";
        exit(0);
    }
    
    echo "\nFound {$count} products with zero inventory:\n";
    
    // Show a sample of products
    $sample = array_slice($zeroInventoryProducts, 0, min(5, $count));
    foreach ($sample as $index => $product) {
        echo ($index + 1) . ". {$product['title']} (ID: {$product['id']}, Status: {$product['status']})\n";
    }
    
    if ($count > 5) {
        echo "... and " . ($count - 5) . " more products\n";
    }
    
    // Final confirmation before proceeding
    if (!$force) {
        if ($delete) {
            echo "\n⚠️ WARNING: You are about to DELETE {$count} products with zero inventory.\n";
            echo "This action CANNOT be undone. Type 'DELETE' to confirm: ";
            $confirmation = trim(fgets(STDIN));
            if ($confirmation !== 'DELETE') {
                echo "Operation cancelled.\n";
                exit(0);
            }
        } else {
            echo "\nSet {$count} products to draft status? (y/n): ";
            $confirmation = strtolower(trim(fgets(STDIN)));
            if ($confirmation !== 'y') {
                echo "Operation cancelled.\n";
                exit(0);
            }
        }
    }
    
    // Process products
    $successCount = 0;
    $failCount = 0;
    
    echo "\nProcessing {$count} products...\n";
    
    foreach ($zeroInventoryProducts as $index => $product) {
        $productId = $product['id'];
        $title = $product['title'];
        
        if ($delete) {
            echo "Deleting [{$index}/{$count}] {$title}... ";
            
            try {
                $success = $shopify->deleteProduct($productId);
                
                if ($success) {
                    echo "✓\n";
                    $successCount++;
                    $logger->info("Deleted zero inventory product '{$title}' (ID: {$productId})");
                } else {
                    echo "✗\n";
                    $failCount++;
                    $logger->error("Failed to delete product '{$title}' (ID: {$productId})");
                }
            } catch (Exception $e) {
                echo "✗ Error: " . $e->getMessage() . "\n";
                $failCount++;
                $logger->error("Error deleting product '{$title}' (ID: {$productId}): " . $e->getMessage());
            }
        } else {
            echo "Setting [{$index}/{$count}] {$title} to draft... ";
            
            try {
                $updateData = [
                    'id' => $productId,
                    'status' => 'draft'
                ];
                
                $result = $shopify->updateProduct($productId, $updateData);
                
                if ($result) {
                    echo "✓\n";
                    $successCount++;
                    $logger->info("Set zero inventory product '{$title}' (ID: {$productId}) to draft");
                } else {
                    echo "✗\n";
                    $failCount++;
                    $logger->error("Failed to update product '{$title}' (ID: {$productId})");
                }
            } catch (Exception $e) {
                echo "✗ Error: " . $e->getMessage() . "\n";
                $failCount++;
                $logger->error("Error updating product '{$title}' (ID: {$productId}): " . $e->getMessage());
            }
        }
        
        // Add a delay to avoid rate limits
        usleep(250000); // 250ms
        
        // Show progress every 10 products
        if (($index + 1) % 10 === 0) {
            $percent = round((($index + 1) / $count) * 100, 1);
            echo "Progress: {$index}/{$count} ({$percent}%)\n";
        }
    }
    
    // Final summary
    if ($delete) {
        echo "\nFinished deleting zero inventory products.\n";
    } else {
        echo "\nFinished setting zero inventory products to draft.\n";
    }
    
    echo "Success: {$successCount}, Failed: {$failCount}\n";
    
    // Next steps
    echo "\nRecommended next steps:\n";
    echo "1. Run a full product sync with the latest code updates:\n";
    echo "   php bin/product-sync.php\n";
    echo "2. Verify that no active products have zero inventory:\n";
    echo "   php bin/inventory-test.php\n";
    
} catch (Exception $e) {
    $logger->error('Fix failed: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 