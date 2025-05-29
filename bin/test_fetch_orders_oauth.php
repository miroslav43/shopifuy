<?php
/**
 * Shopify Order Test Fetch Script with OAuth (Partner App)
 * 
 * This script uses OAuth authentication with a Shopify Partner app to fetch complete order data,
 * bypassing Basic plan PII restrictions.
 * 
 * Usage:
 *   php bin/test_fetch_orders_oauth.php [--days=30] [--output=orders.json] [--store=your-store.myshopify.com]
 * 
 * Options:
 *   --days=N       Number of days back to fetch orders (default: 30)
 *   --output=FILE  Output file name (default: shopify_orders_oauth_YYYYMMDD_HHMMSS.json)
 *   --store=STORE  Store domain (e.g., your-store.myshopify.com)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use DateTime;

// Partner App Credentials
const PB_API_CLIENT = '694cc0c660b3ba0e1b1e71517e1ca8a3';
const PB_API_SECRET = 'b4fe69a51d814fa3457954837a081255';

// Parse command line arguments
$options = getopt('', ['days::', 'output::', 'store::']);
$days = isset($options['days']) ? (int)$options['days'] : 30;
$outputFile = isset($options['output']) ? $options['output'] : null;
$storeDomain = isset($options['store']) ? $options['store'] : null;

// Default output file name if not specified
if (!$outputFile) {
    $timestamp = date('Ymd_His');
    $outputFile = dirname(__DIR__) . '/storage/shopify_orders_oauth_' . $timestamp . '.json';
}

// Ensure the output directory exists
$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Get store domain
if (!$storeDomain) {
    $storeDomain = readline("Enter your store domain (e.g., your-store.myshopify.com): ");
}

if (empty($storeDomain)) {
    echo "Error: Store domain is required.\n";
    exit(1);
}

// Remove protocol if provided
$storeDomain = str_replace(['https://', 'http://'], '', $storeDomain);

echo "=== Shopify OAuth Order Fetcher ===\n";
echo "Store: $storeDomain\n";
echo "Fetching orders from the last $days days...\n\n";

// Step 1: Get OAuth access token
echo "Step 1: Getting OAuth access token...\n";
$accessToken = getOAuthAccessToken($storeDomain);

if (!$accessToken) {
    echo "Error: Could not obtain access token.\n";
    exit(1);
}

echo "✓ Access token obtained successfully.\n\n";

// Step 2: Initialize HTTP client with OAuth token
$client = new Client([
    'base_uri' => "https://{$storeDomain}/admin/api/2024-10/",
    'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-Shopify-Access-Token' => $accessToken,
        'User-Agent' => 'PowerBody Shopify Integration/1.0'
    ]
]);

// Step 3: Fetch orders with complete data
echo "Step 2: Fetching orders with complete customer data...\n";

// Calculate date range
$startDate = (new DateTime("-{$days} days"))->format('c');

// Fetch all orders
$allOrders = [];
$params = [
    'status' => 'any',
    'created_at_min' => $startDate,
    'limit' => 250,
    'fields' => 'id,email,created_at,updated_at,name,order_number,number,note,token,gateway,test,total_price,subtotal_price,total_weight,total_tax,taxes_included,currency,financial_status,confirmed,total_discounts,total_line_items_price,cart_token,buyer_accepts_marketing,note_attributes,processed_at,source_url,fulfillment_status,tax_lines,tags,contact_email,shipping_lines,billing_address,shipping_address,fulfillments,refunds,customer,line_items,payment_details,discount_codes'
];

$page = 1;
$totalOrders = 0;
$nextPageUrl = null;

do {
    echo "Fetching page $page of orders...\n";
    
    try {
        // Build query string
        $queryString = http_build_query($params);
        $response = $client->get("orders.json?{$queryString}");
        $data = json_decode($response->getBody()->getContents(), true);
        
        $orders = $data['orders'] ?? [];
        $count = count($orders);
        $totalOrders += $count;
        
        echo "Retrieved $count orders on this page.\n";
        
        // Parse Link header for pagination
        $nextPageUrl = null;
        if ($response->hasHeader('Link')) {
            $links = $response->getHeader('Link');
            foreach ($links as $link) {
                if (strpos($link, 'rel="next"') !== false) {
                    preg_match('/<(.*)>/', $link, $matches);
                    if (isset($matches[1])) {
                        $nextPageUrl = $matches[1];
                    }
                }
            }
        }
        
        // Enrich each order with complete customer data
        foreach ($orders as &$order) {
            // Check if we have complete customer data
            if (isset($order['customer']) && isset($order['customer']['id'])) {
                $customerId = $order['customer']['id'];
                
                // Check if customer data is incomplete
                if (!isset($order['customer']['email']) || 
                    !isset($order['customer']['first_name']) || 
                    !isset($order['customer']['last_name'])) {
                    
                    echo "Fetching complete customer data for order #{$order['order_number']}...\n";
                    
                    try {
                        $customerResponse = $client->get("customers/{$customerId}.json");
                        $customerData = json_decode($customerResponse->getBody()->getContents(), true);
                        
                        if (isset($customerData['customer'])) {
                            $order['customer'] = $customerData['customer'];
                            echo "✓ Complete customer data fetched.\n";
                        }
                    } catch (Exception $e) {
                        echo "Warning: Could not fetch customer data: " . $e->getMessage() . "\n";
                    }
                }
            }
            
            // Enhance shipping address from customer default address if needed
            if ((!isset($order['shipping_address']) || 
                 !isset($order['shipping_address']['first_name']) || 
                 !isset($order['shipping_address']['last_name'])) &&
                isset($order['customer']['default_address'])) {
                
                $defaultAddress = $order['customer']['default_address'];
                
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
            
            // Enhance billing address from customer default address if needed
            if ((!isset($order['billing_address']) || 
                 !isset($order['billing_address']['first_name']) || 
                 !isset($order['billing_address']['last_name'])) &&
                isset($order['customer']['default_address'])) {
                
                $defaultAddress = $order['customer']['default_address'];
                
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
        
        // Prepare for next page
        if ($nextPageUrl) {
            parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $queryParams);
            $params = ['limit' => 250];
            if (isset($queryParams['page_info'])) {
                $params['page_info'] = $queryParams['page_info'];
            }
            $page++;
        } else {
            $params = null;
        }
        
    } catch (RequestException $e) {
        echo "Error fetching orders: " . $e->getMessage() . "\n";
        if ($e->hasResponse()) {
            echo "Response: " . $e->getResponse()->getBody()->getContents() . "\n";
        }
        break;
    }
    
} while ($params !== null);

echo "\nStep 3: Analyzing fetched data...\n";
echo "Total orders fetched: " . count($allOrders) . "\n";

// Analyze data completeness
$completeOrders = 0;
$incompleteOrders = 0;
$ordersByStatus = [];
$fulfillmentStatus = ['fulfilled' => 0, 'partial' => 0, 'unfulfilled' => 0, 'other' => 0];

foreach ($allOrders as $order) {
    // Check data completeness
    $hasCompleteData = true;
    
    // Check customer data
    if (!isset($order['customer']['email']) || 
        !isset($order['customer']['first_name']) || 
        !isset($order['customer']['last_name'])) {
        $hasCompleteData = false;
    }
    
    // Check shipping address
    if (!isset($order['shipping_address']['address1']) || 
        !isset($order['shipping_address']['city']) || 
        !isset($order['shipping_address']['zip'])) {
        $hasCompleteData = false;
    }
    
    if ($hasCompleteData) {
        $completeOrders++;
    } else {
        $incompleteOrders++;
    }
    
    // Count by status
    $status = $order['financial_status'] ?? 'unknown';
    if (!isset($ordersByStatus[$status])) {
        $ordersByStatus[$status] = 0;
    }
    $ordersByStatus[$status]++;
    
    // Count by fulfillment
    $fulfillment = $order['fulfillment_status'] ?? 'unfulfilled';
    if (isset($fulfillmentStatus[$fulfillment])) {
        $fulfillmentStatus[$fulfillment]++;
    } else {
        $fulfillmentStatus['other']++;
    }
}

// Display analysis
echo "\nData Completeness Analysis:\n";
echo "✓ Complete orders (full customer + address data): $completeOrders\n";
echo "⚠ Incomplete orders: $incompleteOrders\n";

echo "\nOrders by financial status:\n";
foreach ($ordersByStatus as $status => $count) {
    echo "  $status: $count\n";
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

echo "\nSync Status:\n";
echo "Orders with PB_SYNCED tag: $taggedOrders\n";
echo "Orders without PB_SYNCED tag: " . ($totalOrders - $taggedOrders) . "\n";

// Create output data structure
$outputData = [
    'fetch_date' => date('c'),
    'store_domain' => $storeDomain,
    'auth_method' => 'oauth_partner_app',
    'days_fetched' => $days,
    'total_orders' => count($allOrders),
    'complete_orders' => $completeOrders,
    'incomplete_orders' => $incompleteOrders,
    'orders_by_status' => $ordersByStatus,
    'orders_by_fulfillment' => $fulfillmentStatus,
    'tagged_orders' => $taggedOrders,
    'untagged_orders' => $totalOrders - $taggedOrders,
    'orders' => $allOrders
];

// Save all data to JSON file
echo "\nStep 4: Saving complete order data to $outputFile...\n";
file_put_contents($outputFile, json_encode($outputData, JSON_PRETTY_PRINT));

echo "✓ Complete order data saved successfully!\n";
echo "File size: " . round(filesize($outputFile) / 1024 / 1024, 2) . " MB\n";

// Display sample order data
if (!empty($allOrders)) {
    $sampleOrder = $allOrders[0];
    echo "\nSample Order Data:\n";
    echo "Order #" . $sampleOrder['order_number'] . "\n";
    echo "Customer: " . ($sampleOrder['customer']['first_name'] ?? 'N/A') . " " . ($sampleOrder['customer']['last_name'] ?? 'N/A') . "\n";
    echo "Email: " . ($sampleOrder['contact_email'] ?? $sampleOrder['customer']['email'] ?? 'N/A') . "\n";
    echo "Shipping Address: " . ($sampleOrder['shipping_address']['address1'] ?? 'N/A') . "\n";
    echo "City: " . ($sampleOrder['shipping_address']['city'] ?? 'N/A') . "\n";
    echo "ZIP: " . ($sampleOrder['shipping_address']['zip'] ?? 'N/A') . "\n";
    echo "Country: " . ($sampleOrder['shipping_address']['country'] ?? 'N/A') . "\n";
}

echo "\n=== OAuth Fetch Complete ===\n";

/**
 * Get OAuth access token for the store
 */
function getOAuthAccessToken(string $storeDomain): ?string
{
    // For this test script, we'll use a simplified approach
    // In production, you'd implement the full OAuth flow
    
    echo "To get an access token, you need to:\n";
    echo "1. Install your Partner app on the store: $storeDomain\n";
    echo "2. Complete the OAuth flow to get an access token\n";
    echo "3. Enter the access token below\n\n";
    
    $accessToken = readline("Enter your OAuth access token: ");
    
    if (empty($accessToken)) {
        echo "Error: Access token is required.\n";
        return null;
    }
    
    return trim($accessToken);
} 