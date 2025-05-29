<?php
/**
 * Shopify Order Test Fetch Script
 * 
 * This script fetches all orders from Shopify and saves the complete raw data to a JSON file.
 * Useful for testing and debugging the order structure and data received from Shopify.
 * 
 * Usage:
 *   php bin/test_fetch_orders.php [--days=30] [--output=orders.json]
 * 
 * Options:
 *   --days=N       Number of days back to fetch orders (default: 30)
 *   --output=FILE  Output file name (default: shopify_orders_YYYYMMDD_HHMMSS.json)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ShopifyLink;
use App\Logger\Factory as LoggerFactory;
use DateTime;

// Set up logger
$logger = LoggerFactory::getInstance('test-fetch-orders');

// Parse command line arguments
$options = getopt('', ['days::', 'output::']);
$days = isset($options['days']) ? (int)$options['days'] : 30;
$outputFile = isset($options['output']) ? $options['output'] : null;

// Default output file name if not specified
if (!$outputFile) {
    $timestamp = date('Ymd_His');
    $outputFile = dirname(__DIR__) . '/storage/shopify_orders_' . $timestamp . '.json';
}

// Ensure the output directory exists
$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Initialize Shopify API client
$shopify = new ShopifyLink();

echo "Fetching Shopify orders from the last $days days...\n";

// Calculate date range
$startDate = (new DateTime("-{$days} days"))->format('c');

// Fetch all orders
$allOrders = [];
$params = [
    'status' => 'any', // Get all orders regardless of status
    'created_at_min' => $startDate,
    'limit' => 250, // Maximum allowed by Shopify
    'fields' => 'id,email,created_at,updated_at,name,order_number,number,note,token,gateway,test,total_price,subtotal_price,total_weight,total_tax,taxes_included,currency,financial_status,confirmed,total_discounts,total_line_items_price,cart_token,buyer_accepts_marketing,note_attributes,processed_at,source_url,fulfillment_status,tax_lines,tags,contact_email,shipping_lines,billing_address,shipping_address,fulfillments,refunds,customer,line_items,payment_details,discount_codes'
];

$page = 1;
$totalOrders = 0;

do {
    echo "Fetching page $page of orders...\n";
    
    // Get orders for the current page
    $orders = $shopify->getOrders($params);
    $count = count($orders);
    $totalOrders += $count;
    
    echo "Retrieved $count orders on this page.\n";
    
    // Enrich each order with complete customer data
    foreach ($orders as &$order) {
        // If customer data is incomplete, fetch it separately
        if (isset($order['customer']) && isset($order['customer']['id'])) {
            $customerId = $order['customer']['id'];
            
            // Check if customer data is incomplete
            if (!isset($order['customer']['email']) || 
                !isset($order['customer']['first_name']) || 
                !isset($order['customer']['last_name'])) {
                
                echo "Fetching complete customer data for order #{$order['order_number']}...\n";
                
                try {
                    // Fetch complete customer data
                    $customerResponse = $shopify->get("customers/{$customerId}");
                    if (isset($customerResponse['customer'])) {
                        $order['customer'] = $customerResponse['customer'];
                    }
                } catch (Exception $e) {
                    echo "Warning: Could not fetch complete customer data for order #{$order['order_number']}: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // If shipping address is incomplete, try to get it from customer's default address
        if ((!isset($order['shipping_address']) || 
             !isset($order['shipping_address']['first_name']) || 
             !isset($order['shipping_address']['last_name'])) &&
            isset($order['customer']['default_address'])) {
            
            $defaultAddress = $order['customer']['default_address'];
            
            // Merge or replace shipping address with more complete data
            if (!isset($order['shipping_address'])) {
                $order['shipping_address'] = [];
            }
            
            $addressFields = [
                'first_name', 'last_name', 'company', 'address1', 'address2', 
                'city', 'province', 'country', 'zip', 'phone', 'province_code', 
                'country_code', 'country_name'
            ];
            
            foreach ($addressFields as $field) {
                if (empty($order['shipping_address'][$field]) && !empty($defaultAddress[$field])) {
                    $order['shipping_address'][$field] = $defaultAddress[$field];
                }
            }
        }
        
        // If billing address is incomplete, try to get it from customer's default address
        if ((!isset($order['billing_address']) || 
             !isset($order['billing_address']['first_name']) || 
             !isset($order['billing_address']['last_name'])) &&
            isset($order['customer']['default_address'])) {
            
            $defaultAddress = $order['customer']['default_address'];
            
            // Merge or replace billing address with more complete data
            if (!isset($order['billing_address'])) {
                $order['billing_address'] = [];
            }
            
            $addressFields = [
                'first_name', 'last_name', 'company', 'address1', 'address2', 
                'city', 'province', 'country', 'zip', 'phone', 'province_code', 
                'country_code', 'country_name'
            ];
            
            foreach ($addressFields as $field) {
                if (empty($order['billing_address'][$field]) && !empty($defaultAddress[$field])) {
                    $order['billing_address'][$field] = $defaultAddress[$field];
                }
            }
        }
        
        // Ensure contact_email is set
        if (empty($order['contact_email']) && isset($order['customer']['email'])) {
            $order['contact_email'] = $order['customer']['email'];
        }
    }
    unset($order); // Break the reference
    
    // Add to our collection
    $allOrders = array_merge($allOrders, $orders);
    
    // Get next page URL if available
    $nextPageUrl = $shopify->getNextPageUrl();
    if ($nextPageUrl) {
        // Extract the page_info parameter from the next URL
        parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $queryParams);
        
        // Keep the original parameters but update with page_info
        $params['page_info'] = $queryParams['page_info'] ?? null;
        
        // Remove any page parameter if it exists
        unset($params['page']);
        
        $page++;
    } else {
        $params = null; // No more pages
    }
} while ($params !== null && isset($params['page_info']));

echo "\nTotal orders fetched: " . count($allOrders) . "\n";

// Organize orders by status
$ordersByStatus = [];
foreach ($allOrders as $order) {
    $status = $order['financial_status'] ?? 'unknown';
    if (!isset($ordersByStatus[$status])) {
        $ordersByStatus[$status] = 0;
    }
    $ordersByStatus[$status]++;
}

// Display order summary by status
echo "\nOrders by status:\n";
foreach ($ordersByStatus as $status => $count) {
    echo "  $status: $count\n";
}

// Display fulfillment summary
$fulfillmentStatus = [
    'fulfilled' => 0,
    'partial' => 0,
    'unfulfilled' => 0,
    'other' => 0
];

foreach ($allOrders as $order) {
    $status = $order['fulfillment_status'] ?? 'unfulfilled';
    if (isset($fulfillmentStatus[$status])) {
        $fulfillmentStatus[$status]++;
    } else {
        $fulfillmentStatus['other']++;
    }
}

echo "\nOrders by fulfillment status:\n";
foreach ($fulfillmentStatus as $status => $count) {
    if ($count > 0) {
        echo "  $status: $count\n";
    }
}

// Count tagged orders
$taggedOrders = 0;
foreach ($allOrders as $order) {
    $tags = isset($order['tags']) ? explode(',', $order['tags']) : [];
    $tags = array_map('trim', $tags);
    
    if (in_array('PB_SYNCED', $tags)) {
        $taggedOrders++;
    }
}

echo "\nOrders with PB_SYNCED tag: $taggedOrders\n";
echo "Orders without PB_SYNCED tag: " . ($totalOrders - $taggedOrders) . "\n";

// Create output data structure
$outputData = [
    'fetch_date' => date('c'),
    'days_fetched' => $days,
    'total_orders' => count($allOrders),
    'orders_by_status' => $ordersByStatus,
    'orders_by_fulfillment' => $fulfillmentStatus,
    'tagged_orders' => $taggedOrders,
    'untagged_orders' => $totalOrders - $taggedOrders,
    'orders' => $allOrders
];

// Save all data to JSON file
echo "\nSaving complete order data to $outputFile...\n";
file_put_contents($outputFile, json_encode($outputData, JSON_PRETTY_PRINT));

echo "Done! Complete order data saved to $outputFile\n";
echo "File size: " . round(filesize($outputFile) / 1024 / 1024, 2) . " MB\n"; 