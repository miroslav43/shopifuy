<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ShopifyLink;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('delete-products');
$logger->info('Starting Shopify products deletion script');

// Process command line options
$options = getopt('hfy:v:', ['help', 'force', 'vendor:', 'yes:']);

// Show help message
if (isset($options['h']) || isset($options['help'])) {
    echo "Shopify Products Deletion Tool\n";
    echo "----------------------------\n";
    echo "WARNING: This tool will delete products from your Shopify store!\n\n";
    echo "Usage: php bin/delete-products.php [options]\n\n";
    echo "Options:\n";
    echo "  -f, --force       Skip confirmation prompts (DANGEROUS)\n";
    echo "  -y, --yes=TEXT    Text to automatically confirm deletion (must be 'DELETE ALL PRODUCTS')\n";
    echo "  -v, --vendor=NAME Only delete products from a specific vendor\n";
    echo "  -h, --help        Show this help message\n";
    exit(0);
}

// Check for force mode
$force = isset($options['f']) || isset($options['force']);

// Check for automatic confirmation
$autoConfirmText = $options['y'] ?? $options['yes'] ?? '';
$autoConfirm = ($autoConfirmText === 'DELETE ALL PRODUCTS');

// Check for vendor filter
$vendorFilter = $options['v'] ?? $options['vendor'] ?? null;

// Function to get user input with timeout
function readInput($prompt, $default = null, $timeout = 60) {
    echo $prompt;
    
    // Set stream to non-blocking with timeout
    stream_set_blocking(STDIN, false);
    $startTime = time();
    $input = '';
    
    while (time() - $startTime < $timeout) {
        $char = fgets(STDIN, 1);
        if ($char === false) {
            usleep(100000); // Sleep for 100ms to avoid high CPU usage
            continue;
        }
        if ($char === "\n") {
            break;
        }
        $input .= $char;
    }
    
    // Reset stream to blocking
    stream_set_blocking(STDIN, true);
    
    if (empty(trim($input)) && $default !== null) {
        return $default;
    }
    
    return trim($input);
}

// Initialize Shopify client
$shopify = new ShopifyLink();

// Show start message
echo "Fetching products from Shopify...\n";

try {
    // Fetch all products with pagination
    $allProducts = [];
    $limit = 250; // Shopify max limit
    $params = ['limit' => $limit];
    
    do {
        $products = $shopify->getProducts($params);
        
        // Apply vendor filter if specified
        if ($vendorFilter) {
            $products = array_filter($products, function($product) use ($vendorFilter) {
                return isset($product['vendor']) && $product['vendor'] === $vendorFilter;
            });
        }
        
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
        
    } while ($params !== null);
    
    // Check if we have products to delete
    $productCount = count($allProducts);
    
    if ($productCount === 0) {
        if ($vendorFilter) {
            echo "No products found for vendor '{$vendorFilter}'.\n";
        } else {
            echo "No products found in the Shopify store.\n";
        }
        exit(0);
    }
    
    // Warning and confirmation
    if ($vendorFilter) {
        echo "\n⚠️ WARNING: You are about to delete {$productCount} products from vendor '{$vendorFilter}'!\n\n";
    } else {
        echo "\n⚠️ WARNING: You are about to delete ALL {$productCount} products from your Shopify store!\n\n";
    }
    
    // Display first 5 products as a sample
    echo "Sample products to be deleted:\n";
    $sampleSize = min(5, $productCount);
    for ($i = 0; $i < $sampleSize; $i++) {
        $product = $allProducts[$i];
        echo " - {$product['title']} (ID: {$product['id']})\n";
    }
    
    if ($productCount > $sampleSize) {
        echo " - ... and " . ($productCount - $sampleSize) . " more products\n";
    }
    
    echo "\n";
    
    // Confirm deletion unless in force mode or with auto-confirm
    $proceed = $force || $autoConfirm;
    
    if (!$proceed) {
        echo "This action CANNOT be undone! Backup your data before proceeding.\n";
        $confirmation = readInput("Type 'DELETE ALL PRODUCTS' to confirm deletion or anything else to cancel: ");
        $proceed = ($confirmation === 'DELETE ALL PRODUCTS');
    }
    
    if (!$proceed) {
        echo "Operation cancelled. No products were deleted.\n";
        exit(0);
    }
    
    // Delete products
    echo "Starting deletion of {$productCount} products...\n";
    $startTime = microtime(true);
    
    $successCount = 0;
    $failCount = 0;
    
    // Process in small batches with rate limiting
    foreach ($allProducts as $index => $product) {
        $productId = $product['id'];
        $productTitle = $product['title'];
        
        echo "Deleting [{$index}/{$productCount}] {$productTitle} (ID: {$productId})... ";
        
        if ($shopify->deleteProduct($productId)) {
            echo "✓\n";
            $successCount++;
        } else {
            echo "✗\n";
            $failCount++;
        }
        
        // Add small delay to respect API rate limits
        usleep(500000); // 500ms delay
        
        // Progress report every 10 products
        if (($index + 1) % 10 === 0) {
            $duration = round(microtime(true) - $startTime, 2);
            $percent = round((($index + 1) / $productCount) * 100, 1);
            echo "Progress: {$index}/{$productCount} ({$percent}%) | Duration: {$duration}s\n";
        }
    }
    
    // Final results
    $duration = round(microtime(true) - $startTime, 2);
    
    echo "\nFinished:\n";
    echo "- Successfully deleted: {$successCount} products\n";
    echo "- Failed: {$failCount} products\n";
    echo "- Total duration: {$duration} seconds\n";
    
    // Log results
    $logger->info("Deletion completed", [
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'duration' => $duration
    ]);
    
    if ($failCount > 0) {
        echo "\nSome products could not be deleted. Check the log file for details.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    // Show error message
    echo "✗ Error during product deletion: " . $e->getMessage() . "\n";
    $logger->error("Error during product deletion: " . $e->getMessage());
    exit(1);
} 