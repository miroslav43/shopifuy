<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ShopifyLink;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('export-products');
$logger->info('Starting Shopify products export');

// Process command line options
$options = getopt('ho:p:', ['help', 'output:', 'pretty']);

// Show help message
if (isset($options['h']) || isset($options['help'])) {
    echo "Shopify Products Export Tool\n";
    echo "-------------------------\n";
    echo "Usage: php bin/export-products.php [options]\n\n";
    echo "Options:\n";
    echo "  -o, --output=FILE   Output file path (default: storage/shopify_products_YYYYMMDD.json)\n";
    echo "  -p, --pretty        Format JSON with pretty print\n";
    echo "  -h, --help          Show this help message\n";
    exit(0);
}

// Get output file path
$outputFile = $options['o'] ?? $options['output'] ?? null;
if (!$outputFile) {
    $outputFile = dirname(__DIR__) . '/storage/shopify_products_' . date('Ymd') . '.json';
}

// Check if pretty print is enabled
$prettyPrint = isset($options['p']) || isset($options['pretty']);

// Show start message
echo "Fetching all products from Shopify...\n";
$startTime = microtime(true);

try {
    // Initialize Shopify client
    $shopify = new ShopifyLink();
    
    // Fetch all products with pagination
    $allProducts = [];
    $limit = 250; // Shopify max limit
    $params = ['limit' => $limit];
    $page = 1;
    
    do {
        echo "Fetching page $page...\n";
        $products = $shopify->getProducts($params);
        $allProducts = array_merge($allProducts, $products);
        $page++;
        
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
        
        // Add a small delay to respect rate limits
        if ($params !== null) {
            usleep(500000); // 500ms delay
        }
        
    } while ($params !== null);
    
    // Add metadata to output
    $result = [
        'exported_at' => date('c'),
        'product_count' => count($allProducts),
        'products' => $allProducts
    ];
    
    // Create directory if it doesn't exist
    $outputDir = dirname($outputFile);
    if (!file_exists($outputDir)) {
        if (!mkdir($outputDir, 0755, true)) {
            throw new Exception("Failed to create output directory: $outputDir");
        }
    }
    
    // Save to file
    $jsonFlags = $prettyPrint ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0;
    $jsonData = json_encode($result, $jsonFlags);
    if ($jsonData === false) {
        throw new Exception("Failed to encode products to JSON: " . json_last_error_msg());
    }
    
    file_put_contents($outputFile, $jsonData);
    
    // Show success message
    $duration = round(microtime(true) - $startTime, 2);
    $fileSize = round(filesize($outputFile) / (1024 * 1024), 2);
    
    echo "✓ Successfully exported " . count($allProducts) . " products to $outputFile ($fileSize MB) in {$duration} seconds.\n";
    $logger->info("Successfully exported " . count($allProducts) . " products to $outputFile ($fileSize MB)");
} catch (Exception $e) {
    // Show error message
    echo "✗ Error exporting products: " . $e->getMessage() . "\n";
    $logger->error("Error exporting products: " . $e->getMessage());
    exit(1);
} 