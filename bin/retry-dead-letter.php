<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\OrderSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');

/**
 * Find the most recent dead letter file
 */
function findLatestDeadLetterFile($storageDir): ?string
{
    $pattern = $storageDir . '/dead_letter_order_*.json';
    $files = glob($pattern);
    
    if (empty($files)) {
        return null;
    }
    
    // Filter out already processed files
    $files = array_filter($files, function($file) {
        return strpos($file, '.processed') === false && strpos($file, '.failed') === false;
    });
    
    if (empty($files)) {
        return null;
    }
    
    // Sort by modification time (newest first)
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    return $files[0];
}

/**
 * Extract order info for logging
 */
function getOrderInfo($orderData): array
{
    return [
        'order_id' => $orderData['id'] ?? 'unknown',
        'order_name' => $orderData['name'] ?? 'unknown',
        'order_number' => $orderData['order_number'] ?? 'unknown',
        'created_at' => $orderData['created_at'] ?? 'unknown',
        'customer_email' => $orderData['customer']['email'] ?? 'unknown',
        'total_price' => $orderData['total_price'] ?? 'unknown',
        'currency' => $orderData['currency'] ?? 'unknown'
    ];
}

/**
 * Analyze why an order might have failed
 */
function analyzeFailureReasons($orderData, $deadLetterFile): array
{
    $reasons = [];
    
    // Check for validation issues
    if (!isset($orderData['customer']['email']) || empty($orderData['customer']['email'])) {
        $reasons[] = "Missing customer email";
    }
    
    if (!isset($orderData['shipping_address']['address1']) || empty($orderData['shipping_address']['address1'])) {
        $reasons[] = "Missing shipping address";
    }
    
    if (!isset($orderData['line_items']) || empty($orderData['line_items'])) {
        $reasons[] = "No line items";
    } else {
        foreach ($orderData['line_items'] as $item) {
            if (!isset($item['sku']) || empty($item['sku'])) {
                $reasons[] = "Line item missing SKU: " . ($item['name'] ?? 'unknown item');
            }
        }
    }
    
    // Check file name for failure type
    if (strpos($deadLetterFile, 'validation_failed') !== false) {
        $reasons[] = "Failed validation step";
    } elseif (strpos($deadLetterFile, 'create_failed') !== false) {
        $reasons[] = "Failed PowerBody API creation";
    } elseif (strpos($deadLetterFile, 'invalid_response') !== false) {
        $reasons[] = "Invalid PowerBody API response";
    } elseif (strpos($deadLetterFile, 'exception') !== false) {
        $reasons[] = "Exception during processing";
    }
    
    return $reasons;
}

// Main execution
try {
    $logger->info('=== Starting Dead Letter Retry Process ===');
    
    $storageDir = __DIR__ . '/../storage';
    
    // Find the latest dead letter file
    $latestFile = findLatestDeadLetterFile($storageDir);
    
    if (!$latestFile) {
        $logger->info('No dead letter files found to retry');
        exit(0);
    }
    
    $logger->info('Found latest dead letter file', ['file' => basename($latestFile)]);
    
    // Load and validate the order data
    $orderData = json_decode(file_get_contents($latestFile), true);
    
    if (!$orderData) {
        $logger->error('Invalid JSON in dead letter file', ['file' => $latestFile]);
        exit(1);
    }
    
    $orderInfo = getOrderInfo($orderData);
    $logger->info('Order details', $orderInfo);
    
    // Analyze potential failure reasons
    $failureReasons = analyzeFailureReasons($orderData, $latestFile);
    if (!empty($failureReasons)) {
        $logger->warning('Potential failure reasons detected', ['reasons' => $failureReasons]);
    }
    
    // Initialize OrderSync and attempt retry
    $logger->info('Initializing OrderSync for retry attempt');
    $orderSync = new OrderSync();
    
    $logger->info('Attempting to retry order processing');
    $success = $orderSync->processSpecificOrder($orderData);
    
    if ($success) {
        $logger->info('✅ Successfully retried order!', $orderInfo);
        
        // Mark file as processed
        $processedFile = $latestFile . '.processed_' . date('YmdHis');
        if (rename($latestFile, $processedFile)) {
            $logger->info('Marked dead letter file as processed', ['new_file' => basename($processedFile)]);
        }
        
        exit(0);
    } else {
        $logger->error('❌ Failed to retry order - order processing returned false', $orderInfo);
        
        // Mark file as failed retry
        $failedFile = $latestFile . '.failed_retry_' . date('YmdHis');
        if (rename($latestFile, $failedFile)) {
            $logger->info('Marked dead letter file as failed retry', ['new_file' => basename($failedFile)]);
        }
        
        exit(1);
    }
    
} catch (Exception $e) {
    $logger->error('❌ Exception during dead letter retry', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
} 