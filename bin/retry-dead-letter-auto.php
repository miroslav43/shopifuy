<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\OrderSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('dead-letter-retry');

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

/**
 * Attempt to fix common validation issues
 */
function attemptDataFix($orderData): array
{
    $fixed = false;
    $fixes = [];
    
    // Fix missing customer email by using shipping address email or generating one
    if (!isset($orderData['customer']['email']) || empty($orderData['customer']['email'])) {
        if (isset($orderData['shipping_address']['email']) && !empty($orderData['shipping_address']['email'])) {
            $orderData['customer']['email'] = $orderData['shipping_address']['email'];
            $fixed = true;
            $fixes[] = "Used shipping address email for customer";
        } elseif (isset($orderData['billing_address']['email']) && !empty($orderData['billing_address']['email'])) {
            $orderData['customer']['email'] = $orderData['billing_address']['email'];
            $fixed = true;
            $fixes[] = "Used billing address email for customer";
        } else {
            // Generate a placeholder email using customer name and order number
            $firstName = $orderData['customer']['first_name'] ?? 'customer';
            $lastName = $orderData['customer']['last_name'] ?? '';
            $orderNumber = $orderData['order_number'] ?? $orderData['id'] ?? 'unknown';
            $email = strtolower($firstName . '.' . $lastName . '+order' . $orderNumber . '@noemail.local');
            $orderData['customer']['email'] = $email;
            $fixed = true;
            $fixes[] = "Generated placeholder email: $email";
        }
    }
    
    // Copy customer email to shipping and billing addresses if missing
    if (isset($orderData['customer']['email']) && !empty($orderData['customer']['email'])) {
        if (!isset($orderData['shipping_address']['email']) || empty($orderData['shipping_address']['email'])) {
            $orderData['shipping_address']['email'] = $orderData['customer']['email'];
            $fixed = true;
            $fixes[] = "Added customer email to shipping address";
        }
        
        if (!isset($orderData['billing_address']['email']) || empty($orderData['billing_address']['email'])) {
            $orderData['billing_address']['email'] = $orderData['customer']['email'];
            $fixed = true;
            $fixes[] = "Added customer email to billing address";
        }
    }
    
    return ['data' => $orderData, 'fixed' => $fixed, 'fixes' => $fixes];
}

// Get command line options
$options = getopt('', ['max-attempts:', 'skip-validation-fixes', 'dry-run']);
$maxAttempts = isset($options['max-attempts']) ? (int)$options['max-attempts'] : 1;
$skipValidationFixes = isset($options['skip-validation-fixes']);
$dryRun = isset($options['dry-run']);

// Main execution
try {
    $logger->info('=== Automated Dead Letter Retry Process ===', [
        'max_attempts' => $maxAttempts,
        'skip_validation_fixes' => $skipValidationFixes,
        'dry_run' => $dryRun
    ]);
    
    $storageDir = __DIR__ . '/../storage';
    $attempts = 0;
    $processed = 0;
    $successful = 0;
    
    while ($attempts < $maxAttempts) {
        $attempts++;
        
        // Find the latest dead letter file
        $latestFile = findLatestDeadLetterFile($storageDir);
        
        if (!$latestFile) {
            $logger->info('No more dead letter files found to retry', ['attempt' => $attempts]);
            break;
        }
        
        $logger->info("Attempt $attempts/$maxAttempts - Processing dead letter file", [
            'file' => basename($latestFile),
            'attempt' => $attempts
        ]);
        
        // Load and validate the order data
        $orderData = json_decode(file_get_contents($latestFile), true);
        
        if (!$orderData) {
            $logger->error('Invalid JSON in dead letter file', ['file' => $latestFile]);
            // Mark as failed
            rename($latestFile, $latestFile . '.invalid_json_' . date('YmdHis'));
            continue;
        }
        
        $orderInfo = getOrderInfo($orderData);
        $logger->info('Processing order', $orderInfo);
        
        // Analyze potential failure reasons
        $failureReasons = analyzeFailureReasons($orderData, $latestFile);
        if (!empty($failureReasons)) {
            $logger->info('Detected failure reasons', ['reasons' => $failureReasons]);
        }
        
        // Attempt to fix validation issues
        $originalOrderData = $orderData;
        if (!$skipValidationFixes && strpos($latestFile, 'validation_failed') !== false) {
            $logger->info('Attempting to fix validation issues');
            $fixResult = attemptDataFix($orderData);
            $orderData = $fixResult['data'];
            
            if ($fixResult['fixed']) {
                $logger->info('Applied data fixes', ['fixes' => $fixResult['fixes']]);
            } else {
                $logger->info('No automatic fixes available for this order');
            }
        }
        
        if ($dryRun) {
            $logger->info('DRY RUN: Would attempt to retry order processing', $orderInfo);
            $processed++;
            // Mark as dry-run processed
            rename($latestFile, $latestFile . '.dry_run_' . date('YmdHis'));
            continue;
        }
        
        // Initialize OrderSync and attempt retry
        $logger->info('Initializing OrderSync for retry attempt');
        $orderSync = new OrderSync();
        
        $logger->info('Attempting to retry order processing');
        $success = $orderSync->processSpecificOrder($orderData);
        
        $processed++;
        
        if ($success) {
            $successful++;
            $logger->info('✅ Successfully retried order!', $orderInfo);
            
            // Mark file as processed
            $processedFile = $latestFile . '.processed_' . date('YmdHis');
            if (rename($latestFile, $processedFile)) {
                $logger->info('Marked dead letter file as processed', ['new_file' => basename($processedFile)]);
            }
        } else {
            $logger->error('❌ Failed to retry order - order processing returned false', $orderInfo);
            
            // Mark file as failed retry
            $failedFile = $latestFile . '.failed_retry_' . date('YmdHis');
            if (rename($latestFile, $failedFile)) {
                $logger->info('Marked dead letter file as failed retry', ['new_file' => basename($failedFile)]);
            }
        }
        
        // Add a small delay between attempts
        if ($attempts < $maxAttempts) {
            sleep(2);
        }
    }
    
    // Final statistics
    $remainingFiles = count(glob($storageDir . '/dead_letter_order_*.json')) - 
                     count(glob($storageDir . '/dead_letter_order_*.json.processed*')) - 
                     count(glob($storageDir . '/dead_letter_order_*.json.failed*'));
    
    $logger->info('=== Automated retry process completed ===', [
        'attempts_made' => $attempts,
        'files_processed' => $processed,
        'successful_retries' => $successful,
        'failed_retries' => $processed - $successful,
        'remaining_dead_letters' => $remainingFiles
    ]);
    
    exit($successful > 0 ? 0 : 1);
    
} catch (Exception $e) {
    $logger->error('❌ Exception during automated dead letter retry', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
} 