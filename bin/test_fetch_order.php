<?php
/**
 * Shopify Single Order Test Fetch Script
 * 
 * This script fetches a specific order from Shopify by ID and saves the complete raw data to a JSON file.
 * Useful for testing and debugging the order structure and data received from Shopify.
 * 
 * Usage:
 *   php bin/test_fetch_order.php <order_id> [--output=order_123.json]
 * 
 * Arguments:
 *   order_id       The Shopify order ID to fetch
 * 
 * Options:
 *   --output=FILE  Output file name (default: shopify_order_[ID]_YYYYMMDD_HHMMSS.json)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ShopifyLink;
use App\Logger\Factory as LoggerFactory;

// Set up logger
$logger = LoggerFactory::getInstance('test-fetch-order');

// Check if order ID is provided
if ($argc < 2) {
    echo "Error: Order ID is required.\n";
    echo "Usage: php bin/test_fetch_order.php <order_id> [--output=order.json]\n";
    exit(1);
}

// Get order ID from arguments
$orderId = $argv[1];
if (!is_numeric($orderId)) {
    echo "Error: Order ID must be a number.\n";
    exit(1);
}

// Parse command line arguments for output file
$options = getopt('', ['output::']);
$outputFile = isset($options['output']) ? $options['output'] : null;

// Default output file name if not specified
if (!$outputFile) {
    $timestamp = date('Ymd_His');
    $outputFile = dirname(__DIR__) . "/storage/shopify_order_{$orderId}_{$timestamp}.json";
}

// Ensure the output directory exists
$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Initialize Shopify API client
$shopify = new ShopifyLink();

echo "Fetching Shopify order with ID: $orderId\n";

try {
    // Get the specific order with all fields
    // We'll use the raw API call to ensure we get complete data
    $order = fetchCompleteOrderData($shopify, $orderId);
    
    if (!$order) {
        echo "Error: Order not found or API error occurred.\n";
        exit(1);
    }
    
    echo "\nOrder #{$order['order_number']} fetched successfully.\n";
    echo "Order date: " . date('Y-m-d H:i:s', strtotime($order['created_at'])) . "\n";
    
    // Customer information
    if (isset($order['customer'])) {
        echo "Customer: " . ($order['customer']['first_name'] ?? '') . " " . ($order['customer']['last_name'] ?? '') . "\n";
        echo "Email: " . ($order['contact_email'] ?? $order['customer']['email'] ?? 'N/A') . "\n";
        echo "Customer ID: " . ($order['customer']['id'] ?? 'N/A') . "\n";
    } else {
        echo "Customer: Not available\n";
    }
    
    echo "Financial status: " . ($order['financial_status'] ?? 'unknown') . "\n";
    echo "Fulfillment status: " . ($order['fulfillment_status'] ?? 'unfulfilled') . "\n";
    echo "Total price: " . ($order['total_price'] ?? '0.00') . " " . ($order['currency'] ?? 'EUR') . "\n";
    
    // Check if the order has PB_SYNCED tag
    $tags = isset($order['tags']) ? explode(',', $order['tags']) : [];
    $tags = array_map('trim', $tags);
    $hasPbSyncedTag = in_array('PB_SYNCED', $tags);
    
    echo "Has PB_SYNCED tag: " . ($hasPbSyncedTag ? 'Yes' : 'No') . "\n";
    
    // Show all tags
    echo "All tags: " . ($order['tags'] ?? 'None') . "\n";
    
    // Check line items
    $lineItems = $order['line_items'] ?? [];
    echo "Number of line items: " . count($lineItems) . "\n";
    
    if (!empty($lineItems)) {
        echo "\nLine items:\n";
        foreach ($lineItems as $index => $item) {
            echo ($index + 1) . ". " . ($item['name'] ?? 'Unknown product') . 
                 " (SKU: " . ($item['sku'] ?? 'N/A') . ") - " . 
                 "Qty: " . ($item['quantity'] ?? 0) . ", " . 
                 "Price: " . ($item['price'] ?? 0) . " " . ($order['currency'] ?? 'EUR') . "\n";
            
            // Show variant information if available
            if (isset($item['variant_id']) && !empty($item['variant_id'])) {
                echo "   Variant ID: " . $item['variant_id'] . "\n";
            }
            
            // Show product ID if available
            if (isset($item['product_id']) && !empty($item['product_id'])) {
                echo "   Product ID: " . $item['product_id'] . "\n";
            }
        }
    }
    
    // Show shipping address
    if (isset($order['shipping_address'])) {
        $address = $order['shipping_address'];
        echo "\nShipping address:\n";
        echo $address['first_name'] . " " . $address['last_name'] . "\n";
        echo $address['address1'] . "\n";
        if (!empty($address['address2'])) {
            echo $address['address2'] . "\n";
        }
        echo $address['zip'] . " " . $address['city'] . "\n";
        echo $address['country'] . " (" . $address['country_code'] . ")\n";
        echo "Phone: " . ($address['phone'] ?? 'N/A') . "\n";
    } else {
        echo "\nNo shipping address found!\n";
    }
    
    // Show billing address
    if (isset($order['billing_address'])) {
        $address = $order['billing_address'];
        echo "\nBilling address:\n";
        echo $address['first_name'] . " " . $address['last_name'] . "\n";
        echo $address['address1'] . "\n";
        if (!empty($address['address2'])) {
            echo $address['address2'] . "\n";
        }
        echo $address['zip'] . " " . $address['city'] . "\n";
        echo $address['country'] . " (" . $address['country_code'] . ")\n";
        echo "Phone: " . ($address['phone'] ?? 'N/A') . "\n";
    } 
    
    // Show shipping lines
    if (!empty($order['shipping_lines'])) {
        echo "\nShipping method:\n";
        foreach ($order['shipping_lines'] as $shipping) {
            echo "Title: " . ($shipping['title'] ?? 'N/A') . "\n";
            echo "Price: " . ($shipping['price'] ?? '0.00') . " " . ($order['currency'] ?? 'EUR') . "\n";
            
            if (!empty($shipping['code'])) {
                echo "Code: " . $shipping['code'] . "\n";
            }
        }
    }
    
    // Create output data structure
    $outputData = [
        'fetch_date' => date('c'),
        'order_id' => $orderId,
        'has_pb_synced_tag' => $hasPbSyncedTag,
        'order' => $order
    ];
    
    // Save order data to JSON file
    echo "\nSaving complete order data to $outputFile...\n";
    file_put_contents($outputFile, json_encode($outputData, JSON_PRETTY_PRINT));
    
    echo "Done! Complete order data saved to $outputFile\n";
    echo "File size: " . round(filesize($outputFile) / 1024, 2) . " KB\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Fetch complete order data using Shopify API
 * This function ensures we get all fields and nested data
 * 
 * @param ShopifyLink $shopify
 * @param int $orderId
 * @return array|null
 */
function fetchCompleteOrderData(ShopifyLink $shopify, $orderId) {
    try {
        // First, get the order with all possible fields
        $params = [
            'fields' => 'id,email,created_at,updated_at,name,order_number,number,note,token,gateway,test,total_price,subtotal_price,total_weight,total_tax,taxes_included,currency,financial_status,confirmed,total_discounts,total_line_items_price,cart_token,buyer_accepts_marketing,note_attributes,processed_at,source_url,fulfillment_status,tax_lines,tags,contact_email,shipping_lines,billing_address,shipping_address,fulfillments,refunds,customer,line_items,payment_details,discount_codes'
        ];
        
        $order = $shopify->getOrder($orderId, $params);
        
        if (!$order) {
            return null;
        }
        
        // If customer data is incomplete, fetch it separately
        if (isset($order['customer']) && isset($order['customer']['id'])) {
            $customerId = $order['customer']['id'];
            
            // Check if customer data is incomplete
            if (!isset($order['customer']['email']) || 
                !isset($order['customer']['first_name']) || 
                !isset($order['customer']['last_name'])) {
                
                echo "Customer data incomplete, fetching full customer details...\n";
                
                try {
                    // Fetch complete customer data
                    $customerResponse = $shopify->get("customers/{$customerId}");
                    if (isset($customerResponse['customer'])) {
                        $order['customer'] = $customerResponse['customer'];
                        echo "Successfully fetched complete customer data.\n";
                    }
                } catch (Exception $e) {
                    echo "Warning: Could not fetch complete customer data: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // If shipping address is incomplete, try to get it from customer's default address
        if ((!isset($order['shipping_address']) || 
             !isset($order['shipping_address']['first_name']) || 
             !isset($order['shipping_address']['last_name'])) &&
            isset($order['customer']['default_address'])) {
            
            echo "Shipping address incomplete, using customer's default address...\n";
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
            
            echo "Billing address incomplete, using customer's default address...\n";
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
        
        return $order;
        
    } catch (Exception $e) {
        echo "Warning: Error fetching complete order data: " . $e->getMessage() . "\n";
        
        // Fallback to standard method
        return $shopify->getOrder($orderId);
    }
} 