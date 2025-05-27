<?php
/**
 * Order Cache Manager
 * 
 * Command line tool for managing the Shopify order cache
 * 
 * Usage:
 *   php bin/order-cache-manager.php help
 *   php bin/order-cache-manager.php status
 *   php bin/order-cache-manager.php list
 *   php bin/order-cache-manager.php view <order_id>
 *   php bin/order-cache-manager.php clear
 *   php bin/order-cache-manager.php refresh
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\OrderSync;
use App\Logger\Factory as LoggerFactory;
use DateTime;

// Set up logger
$logger = LoggerFactory::getInstance('order-cache-manager');
$storageDir = __DIR__ . '/../storage';
$orderCacheDir = $storageDir . '/cache/orders';

// Ensure cache directory exists
if (!is_dir($orderCacheDir)) {
    mkdir($orderCacheDir, 0755, true);
}

// Parse command line arguments
$command = $argv[1] ?? 'help';
$parameter = $argv[2] ?? null;

switch ($command) {
    case 'status':
        displayCacheStatus();
        break;
        
    case 'list':
        listCachedOrders();
        break;
        
    case 'view':
        if (!$parameter) {
            echo "Error: Please provide an order ID to view\n";
            echo "Usage: php bin/order-cache-manager.php view <order_id>\n";
            exit(1);
        }
        viewOrder($parameter);
        break;
        
    case 'clear':
        clearCache();
        break;
        
    case 'refresh':
        refreshCache();
        break;
        
    case 'help':
    default:
        displayHelp();
        break;
}

/**
 * Display help information
 */
function displayHelp(): void
{
    echo "Shopify Order Cache Manager\n";
    echo "==========================\n\n";
    echo "Usage:\n";
    echo "  php bin/order-cache-manager.php help         - Display this help message\n";
    echo "  php bin/order-cache-manager.php status       - Display cache status\n";
    echo "  php bin/order-cache-manager.php list         - List all cached orders\n";
    echo "  php bin/order-cache-manager.php view <id>    - View details of specific order\n";
    echo "  php bin/order-cache-manager.php clear        - Clear the order cache\n";
    echo "  php bin/order-cache-manager.php refresh      - Force refresh the order cache\n";
}

/**
 * Display cache status
 */
function displayCacheStatus(): void
{
    global $orderCacheDir;
    
    $latestFile = $orderCacheDir . '/latest.json';
    
    if (!file_exists($latestFile)) {
        echo "No cache found.\n";
        return;
    }
    
    try {
        $cacheData = json_decode(file_get_contents($latestFile), true);
        
        if (!$cacheData || !isset($cacheData['timestamp']) || !isset($cacheData['expiration'])) {
            echo "Invalid cache data format.\n";
            return;
        }
        
        $createdAt = new DateTime($cacheData['timestamp']);
        $expiresAt = new DateTime($cacheData['expiration']);
        $now = new DateTime();
        
        $isExpired = ($now > $expiresAt);
        $orderCount = $cacheData['count'] ?? count($cacheData['orders'] ?? []);
        
        echo "Cache Status:\n";
        echo "-------------\n";
        echo "Created:       " . $createdAt->format('Y-m-d H:i:s') . "\n";
        echo "Expires:       " . $expiresAt->format('Y-m-d H:i:s') . "\n";
        echo "Status:        " . ($isExpired ? "EXPIRED" : "VALID") . "\n";
        echo "Order Count:   " . $orderCount . "\n";
        
        // Display other cache files
        $cacheFiles = glob($orderCacheDir . '/shopify_orders_*.json');
        echo "\nOther Cache Files:\n";
        echo "-----------------\n";
        
        if (empty($cacheFiles)) {
            echo "No cache history files found.\n";
        } else {
            foreach ($cacheFiles as $file) {
                $fileName = basename($file);
                $fileSize = round(filesize($file) / 1024, 2);
                echo "{$fileName} ({$fileSize} KB)\n";
            }
        }
    } catch (Exception $e) {
        echo "Error reading cache: " . $e->getMessage() . "\n";
    }
}

/**
 * List all cached orders
 */
function listCachedOrders(): void
{
    global $orderCacheDir;
    
    $latestFile = $orderCacheDir . '/latest.json';
    
    if (!file_exists($latestFile)) {
        echo "No cache found.\n";
        return;
    }
    
    try {
        $cacheData = json_decode(file_get_contents($latestFile), true);
        
        if (!$cacheData || !isset($cacheData['orders'])) {
            echo "Invalid cache data format.\n";
            return;
        }
        
        $orders = $cacheData['orders'];
        
        if (empty($orders)) {
            echo "No orders in cache.\n";
            return;
        }
        
        echo "Cached Orders:\n";
        echo "-------------\n";
        echo str_pad("ID", 15) . str_pad("Order #", 10) . str_pad("Date", 20) . str_pad("Customer", 30) . str_pad("Total", 10) . "Status\n";
        echo str_repeat("-", 100) . "\n";
        
        foreach ($orders as $order) {
            $id = $order['id'];
            $orderNumber = $order['order_number'] ?? 'N/A';
            $date = isset($order['created_at']) ? substr($order['created_at'], 0, 19) : 'N/A';
            
            $customerName = 'Anonymous';
            if (isset($order['customer']) && isset($order['customer']['first_name'])) {
                $firstName = $order['customer']['first_name'];
                $lastName = $order['customer']['last_name'] ?? '';
                $customerName = trim($firstName . ' ' . $lastName);
            }
            
            $total = isset($order['total_price']) ? $order['currency'] . ' ' . $order['total_price'] : 'N/A';
            $status = $order['financial_status'] ?? 'N/A';
            
            echo str_pad($id, 15) . str_pad($orderNumber, 10) . str_pad($date, 20) . str_pad($customerName, 30) . str_pad($total, 10) . $status . "\n";
        }
    } catch (Exception $e) {
        echo "Error reading cache: " . $e->getMessage() . "\n";
    }
}

/**
 * View specific order details
 */
function viewOrder(string $orderId): void
{
    global $orderCacheDir;
    
    $latestFile = $orderCacheDir . '/latest.json';
    
    if (!file_exists($latestFile)) {
        echo "No cache found.\n";
        return;
    }
    
    try {
        $cacheData = json_decode(file_get_contents($latestFile), true);
        
        if (!$cacheData || !isset($cacheData['orders'])) {
            echo "Invalid cache data format.\n";
            return;
        }
        
        $orders = $cacheData['orders'];
        $foundOrder = null;
        
        foreach ($orders as $order) {
            if ($order['id'] == $orderId || $order['order_number'] == $orderId) {
                $foundOrder = $order;
                break;
            }
        }
        
        if (!$foundOrder) {
            echo "Order not found in cache: {$orderId}\n";
            return;
        }
        
        // Display order details
        echo "Order Details:\n";
        echo "-------------\n";
        echo "ID:             " . $foundOrder['id'] . "\n";
        echo "Order Number:   " . ($foundOrder['order_number'] ?? 'N/A') . "\n";
        echo "Created:        " . ($foundOrder['created_at'] ?? 'N/A') . "\n";
        echo "Status:         " . ($foundOrder['financial_status'] ?? 'N/A') . "\n";
        echo "Fulfillment:    " . ($foundOrder['fulfillment_status'] ?? 'Unfulfilled') . "\n";
        
        if (isset($foundOrder['customer'])) {
            echo "\nCustomer:\n";
            echo "  Name:     " . ($foundOrder['customer']['first_name'] ?? '') . " " . ($foundOrder['customer']['last_name'] ?? '') . "\n";
            echo "  Email:    " . ($foundOrder['customer']['email'] ?? $foundOrder['contact_email'] ?? 'N/A') . "\n";
            echo "  Phone:    " . ($foundOrder['customer']['phone'] ?? 'N/A') . "\n";
        }
        
        if (isset($foundOrder['shipping_address'])) {
            echo "\nShipping Address:\n";
            $address = $foundOrder['shipping_address'];
            echo "  Name:     " . ($address['first_name'] ?? '') . " " . ($address['last_name'] ?? '') . "\n";
            echo "  Address:  " . ($address['address1'] ?? 'N/A') . "\n";
            if (!empty($address['address2'])) {
                echo "            " . $address['address2'] . "\n";
            }
            echo "  City:     " . ($address['city'] ?? 'N/A') . "\n";
            echo "  Zip:      " . ($address['zip'] ?? 'N/A') . "\n";
            echo "  Country:  " . ($address['country'] ?? 'N/A') . " (" . ($address['country_code'] ?? 'N/A') . ")\n";
        }
        
        if (isset($foundOrder['line_items']) && !empty($foundOrder['line_items'])) {
            echo "\nLine Items:\n";
            echo str_pad("SKU", 15) . str_pad("Product", 50) . str_pad("Qty", 5) . str_pad("Price", 10) . "Total\n";
            echo str_repeat("-", 100) . "\n";
            
            foreach ($foundOrder['line_items'] as $item) {
                $sku = $item['sku'] ?? 'N/A';
                $name = substr($item['name'] ?? 'N/A', 0, 47) . (strlen($item['name'] ?? '') > 47 ? '...' : '');
                $qty = $item['quantity'] ?? 0;
                $price = isset($item['price']) ? $foundOrder['currency'] . ' ' . $item['price'] : 'N/A';
                $total = isset($item['price'], $item['quantity']) 
                    ? $foundOrder['currency'] . ' ' . number_format($item['price'] * $item['quantity'], 2) 
                    : 'N/A';
                
                echo str_pad($sku, 15) . str_pad($name, 50) . str_pad($qty, 5) . str_pad($price, 10) . $total . "\n";
            }
        }
        
        echo "\nTotal: " . ($foundOrder['currency'] ?? '') . " " . ($foundOrder['total_price'] ?? 'N/A') . "\n";
        
    } catch (Exception $e) {
        echo "Error reading cache: " . $e->getMessage() . "\n";
    }
}

/**
 * Clear the order cache
 */
function clearCache(): void
{
    global $orderCacheDir;
    
    try {
        $files = glob($orderCacheDir . '/*.json');
        $count = count($files);
        
        if ($count == 0) {
            echo "No cache files to clear.\n";
            return;
        }
        
        foreach ($files as $file) {
            unlink($file);
        }
        
        echo "Successfully cleared {$count} cache files.\n";
    } catch (Exception $e) {
        echo "Error clearing cache: " . $e->getMessage() . "\n";
    }
}

/**
 * Force refresh the order cache
 */
function refreshCache(): void
{
    try {
        echo "Refreshing order cache...\n";
        
        // Clear existing cache
        clearCache();
        
        // Create a new instance of OrderSync and run the sync method
        $orderSync = new OrderSync();
        
        // This will trigger a fresh fetch of orders and caching
        $orders = $orderSync->getUnfulfilledShopifyOrders();
        
        echo "Successfully refreshed cache with " . count($orders) . " orders.\n";
    } catch (Exception $e) {
        echo "Error refreshing cache: " . $e->getMessage() . "\n";
    }
} 