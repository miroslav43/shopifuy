<?php
/**
 * Dead Letter Retry Script
 * 
 * This script attempts to reprocess orders that have been saved to dead letter files
 * due to previous failures in sending them to PowerBody.
 * 
 * Usage:
 *   php bin/retry-dead-letters.php [--all] [--verbose] [--dry-run]
 *   
 *   Options:
 *     --all       Process all dead letters, including very old ones (default: only last 7 days)
 *     --verbose   Show detailed information about each processed order
 *     --dry-run   Show what would be processed without actually processing
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\OrderSync;
use App\Core\Database;
use App\Logger\Factory as LoggerFactory;
use DateTime;

// Set up logger
$logger = LoggerFactory::getInstance('dead-letter-retry');

// Parse command line arguments
$options = getopt('', ['all', 'verbose', 'dry-run']);
$processAll = isset($options['all']);
$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// Storage directory
$storageDir = __DIR__ . '/../storage';

// Find dead letter files
echo "Searching for dead letter files...\n";

$pattern = $storageDir . '/dead_letter_order_*.json';
$deadLetterFiles = glob($pattern);

if (empty($deadLetterFiles)) {
    echo "No dead letter files found.\n";
    exit(0);
}

echo "Found " . count($deadLetterFiles) . " dead letter files.\n";

// Filter by date if not processing all
if (!$processAll) {
    $cutoffDate = new DateTime('-7 days');
    $filteredFiles = [];
    
    foreach ($deadLetterFiles as $file) {
        $fileDate = extractDateFromFilename($file);
        if ($fileDate && $fileDate >= $cutoffDate) {
            $filteredFiles[] = $file;
        }
    }
    
    echo "Filtered to " . count($filteredFiles) . " files from the last 7 days.\n";
    $deadLetterFiles = $filteredFiles;
}

if (empty($deadLetterFiles)) {
    echo "No recent dead letter files to process.\n";
    exit(0);
}

// Group by reason
$groupedFiles = [];
foreach ($deadLetterFiles as $file) {
    $reason = extractReasonFromFilename($file);
    if (!isset($groupedFiles[$reason])) {
        $groupedFiles[$reason] = [];
    }
    $groupedFiles[$reason][] = $file;
}

echo "\nDead letter files by reason:\n";
foreach ($groupedFiles as $reason => $files) {
    echo "- $reason: " . count($files) . " files\n";
}

// Create OrderSync instance
$orderSync = new OrderSync();
$db = Database::getInstance();

// Counters
$totalProcessed = 0;
$totalSuccess = 0;
$totalFailed = 0;

echo "\nBeginning retry process" . ($dryRun ? " (DRY RUN)" : "") . "...\n";
echo "------------------------------------------------\n";

// Process each file
foreach ($deadLetterFiles as $file) {
    $fileName = basename($file);
    $reason = extractReasonFromFilename($file);
    $orderId = extractOrderIdFromFilename($file);
    
    echo "Processing: $fileName\n";
    if ($verbose) {
        echo "  Reason: $reason\n";
        echo "  Order ID: $orderId\n";
    }
    
    // Load the order data
    try {
        $orderData = json_decode(file_get_contents($file), true);
        
        if (!$orderData) {
            echo "  ERROR: Could not parse JSON data in file.\n";
            $totalFailed++;
            continue;
        }
        
        // Skip if already processed (check in database)
        if (isOrderAlreadyProcessed($db, $orderData)) {
            echo "  SKIPPED: Order already processed and exists in the database.\n";
            continue;
        }
        
        if ($dryRun) {
            echo "  DRY RUN: Would attempt to process order.\n";
            $totalProcessed++;
            continue;
        }
        
        // Attempt to process
        list($result, $response, $errorDetails) = processOrder($orderSync, $orderData, $reason);
        $totalProcessed++;
        
        if ($result) {
            echo "  SUCCESS: Order processed successfully.\n";
            if ($verbose && $response) {
                echo "  API Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
            }
            $totalSuccess++;
            
            // Rename or move the file to indicate it's been processed
            $processedDir = $storageDir . '/processed';
            if (!is_dir($processedDir)) {
                mkdir($processedDir, 0755, true);
            }
            
            $newName = $processedDir . '/' . $fileName;
            rename($file, $newName);
        } else {
            echo "  FAILED: Order processing failed again.\n";
            
            // Display detailed error information
            if ($errorDetails) {
                echo "  Error Details: " . $errorDetails . "\n";
            }
            
            // Display API response if available
            if ($response) {
                echo "  API Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
                
                // Additional debug information based on API response
                if (isset($response['api_response']) && $response['api_response'] === 'FAIL') {
                    echo "  Failure Reason: API responded with FAIL status.\n";
                    
                    // Display mapped order data for debugging
                    if ($verbose) {
                        if (isset($response['address'])) {
                            echo "  Shipping Address Sent:\n";
                            foreach ($response['address'] as $key => $value) {
                                echo "    $key: $value\n";
                            }
                        }
                        
                        if (isset($response['products']) && is_array($response['products'])) {
                            echo "  Products Sent: " . count($response['products']) . "\n";
                            foreach ($response['products'] as $index => $product) {
                                echo "    Product #" . ($index+1) . ": " . ($product['name'] ?? 'N/A') . 
                                     " (SKU: " . ($product['sku'] ?? 'N/A') . ")\n";
                            }
                        }
                    }
                }
            } else {
                echo "  No API response available\n";
            }
            
            // Check for validation errors in the original order
            $shopifyOrder = extractShopifyOrder($orderData, $reason);
            if ($shopifyOrder) {
                $validationIssues = checkOrderData($shopifyOrder);
                if (!empty($validationIssues)) {
                    echo "  Order Data Issues:\n";
                    foreach ($validationIssues as $issue) {
                        echo "    - $issue\n";
                    }
                }
            }
            
            $totalFailed++;
        }
    } catch (Exception $e) {
        echo "  ERROR: Exception while processing: " . $e->getMessage() . "\n";
        $totalFailed++;
    }
    
    echo "------------------------------------------------\n";
}

// Summary
echo "\nRetry process complete.\n";
echo "Total processed: $totalProcessed\n";
echo "Successful: $totalSuccess\n";
echo "Failed: $totalFailed\n";

/**
 * Extract the reason from the dead letter filename
 */
function extractReasonFromFilename(string $filename): string
{
    $basename = basename($filename);
    if (preg_match('/dead_letter_order_([^_]+)_/', $basename, $matches)) {
        return $matches[1];
    }
    return 'unknown';
}

/**
 * Extract the order ID from the dead letter filename
 */
function extractOrderIdFromFilename(string $filename): string
{
    $basename = basename($filename);
    if (preg_match('/dead_letter_order_[^_]+_(\d+)_/', $basename, $matches)) {
        return $matches[1];
    }
    return 'unknown';
}

/**
 * Extract the date from the dead letter filename
 */
function extractDateFromFilename(string $filename): ?DateTime
{
    $basename = basename($filename);
    if (preg_match('/(\d{8})(\d{6})\.json$/', $basename, $matches)) {
        $dateStr = $matches[1] . ' ' . 
                   substr($matches[2], 0, 2) . ':' . 
                   substr($matches[2], 2, 2) . ':' . 
                   substr($matches[2], 4, 2);
        
        try {
            return new DateTime($dateStr);
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

/**
 * Check if an order is already processed
 */
function isOrderAlreadyProcessed(Database $db, array $orderData): bool
{
    $shopifyOrderId = null;
    
    // Extract Shopify order ID depending on the data structure
    if (isset($orderData['id'])) {
        $shopifyOrderId = $orderData['id'];
    } elseif (isset($orderData['shopify_order']['id'])) {
        $shopifyOrderId = $orderData['shopify_order']['id'];
    } elseif (isset($orderData['shopify_order_id'])) {
        $shopifyOrderId = $orderData['shopify_order_id'];
    }
    
    if (!$shopifyOrderId) {
        return false; // Can't determine if processed
    }
    
    // Check in database
    $powerbodyOrderId = $db->getPowerbodyOrderId($shopifyOrderId);
    return !empty($powerbodyOrderId);
}

/**
 * Process an order based on the reason it failed
 * 
 * @return array [bool $success, array|null $apiResponse, string|null $errorDetails]
 */
function processOrder(OrderSync $orderSync, array $orderData, string $reason): array
{
    global $verbose, $logger;
    
    // Extract the Shopify order based on the reason/structure
    $shopifyOrder = extractShopifyOrder($orderData, $reason);
    
    if (!$shopifyOrder) {
        if ($verbose) {
            echo "  Could not extract Shopify order data.\n";
        }
        return [false, null, "Could not extract Shopify order data"];
    }
    
    try {
        // Set up a temporary error handler to capture warnings
        $errorDetails = null;
        set_error_handler(function($errno, $errstr) use (&$errorDetails) {
            $errorDetails = $errstr;
            return true;
        });
        
        // Use a simpler approach without trying to access private methods
        $apiResponse = null;
        
        // Process the order
        $result = $orderSync->processSpecificOrder($shopifyOrder);
        
        // Restore the error handler
        restore_error_handler();
        
        // For failed orders, extract information directly from the order data
        if (!$result) {
            // Basic API response for failures
            $apiResponse = [
                'api_response' => 'FAIL',
                'id' => 'shopify_' . ($shopifyOrder['order_number'] ?? '')
            ];
            
            // Check for common issues
            $validationIssues = checkOrderData($shopifyOrder);
            if (!empty($validationIssues)) {
                $apiResponse['validation_errors'] = $validationIssues;
            }
            
            // Add shipping address info for debugging
            if (isset($shopifyOrder['shipping_address'])) {
                $address = $shopifyOrder['shipping_address'];
                $apiResponse['address'] = [
                    'name' => $address['first_name'] ?? 'N/A',
                    'surname' => $address['last_name'] ?? 'N/A',
                    'address1' => $address['address1'] ?? 'N/A',
                    'postcode' => $address['zip'] ?? 'N/A',
                    'city' => $address['city'] ?? 'N/A',
                    'country_name' => $address['country'] ?? 'N/A',
                    'country_code' => $address['country_code'] ?? 'N/A',
                    'phone' => $address['phone'] ?? 'N/A',
                    'email' => $shopifyOrder['contact_email'] ?? $shopifyOrder['customer']['email'] ?? 'N/A'
                ];
            }
            
            // Add products info for debugging
            if (isset($shopifyOrder['line_items'])) {
                $apiResponse['products'] = [];
                foreach ($shopifyOrder['line_items'] as $item) {
                    if (isset($item['vendor']) && $item['vendor'] === 'Powerbody' || 
                        (!empty($item['sku']) && strpos($item['sku'], 'P') === 0)) {
                        
                        $apiResponse['products'][] = [
                            'sku' => $item['sku'] ?? 'N/A',
                            'name' => $item['name'] ?? 'N/A',
                            'qty' => $item['quantity'] ?? 0,
                            'price' => $item['price'] ?? 'N/A',
                            'currency' => $shopifyOrder['currency'] ?? 'EUR'
                        ];
                    }
                }
            }
        } else {
            // For successful orders
            $apiResponse = [
                'api_response' => 'SUCCESS',
                'id' => 'shopify_' . ($shopifyOrder['order_number'] ?? '')
            ];
        }
        
        return [$result, $apiResponse, $errorDetails];
    } catch (Exception $e) {
        if ($verbose) {
            echo "  Error: " . $e->getMessage() . "\n";
        }
        return [false, null, $e->getMessage()];
    }
}

/**
 * Extract Shopify order from order data based on reason
 */
function extractShopifyOrder(array $orderData, string $reason): ?array
{
    // Extract the Shopify order based on the reason/structure
    if ($reason === 'validation_failed') {
        // For validation failures, we have both the Shopify order and PowerBody data
        if (isset($orderData['shopify_order'])) {
            return $orderData['shopify_order'];
        }
    } else {
        // For other reasons, we usually have just the Shopify order data
        return $orderData;
    }
    
    return null;
}

/**
 * Check for common issues in order data
 */
function checkOrderData(array $shopifyOrder): array
{
    $issues = [];
    
    // Check shipping address
    if (!isset($shopifyOrder['shipping_address'])) {
        $issues[] = "Missing shipping address";
    } else {
        $address = $shopifyOrder['shipping_address'];
        $requiredFields = [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'address1' => 'Address',
            'city' => 'City',
            'zip' => 'Postal/ZIP code',
            'country' => 'Country',
            'country_code' => 'Country code'
        ];
        
        foreach ($requiredFields as $field => $label) {
            if (empty($address[$field])) {
                $issues[] = "Missing shipping address field: {$label}";
            }
        }
    }
    
    // Check customer info
    if (!isset($shopifyOrder['customer'])) {
        $issues[] = "Missing customer information";
    }
    
    // Check contact email
    if (empty($shopifyOrder['contact_email']) && (empty($shopifyOrder['customer']) || empty($shopifyOrder['customer']['email']))) {
        $issues[] = "Missing customer email";
    }
    
    // Check line items
    if (empty($shopifyOrder['line_items'])) {
        $issues[] = "No line items in order";
    } else {
        $hasPowerbodyProducts = false;
        
        foreach ($shopifyOrder['line_items'] as $item) {
            if (isset($item['vendor']) && $item['vendor'] === 'Powerbody') {
                $hasPowerbodyProducts = true;
                break;
            }
            
            if (!empty($item['sku']) && strpos($item['sku'], 'P') === 0) {
                $hasPowerbodyProducts = true;
                break;
            }
        }
        
        if (!$hasPowerbodyProducts) {
            $issues[] = "No PowerBody products detected in order";
        }
    }
    
    return $issues;
} 