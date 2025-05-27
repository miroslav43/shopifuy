<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\PowerBodyLink;
use App\Logger\Factory as LoggerFactory;

// Initialize logger
$logger = LoggerFactory::getInstance('cache-manager');
$logger->info('Starting cache management');

// Create PowerBody instance
$powerbody = new PowerBodyLink();

// Parse command-line arguments
$command = isset($argv[1]) ? strtolower($argv[1]) : 'help';
$productId = isset($argv[2]) ? (int)$argv[2] : null;

// Process commands
switch ($command) {
    case 'clear':
        if ($productId) {
            $logger->info("Clearing cache for product ID: {$productId}");
            $success = $powerbody->clearProductCache($productId);
            if ($success) {
                echo "Cache cleared for product ID: {$productId}\n";
            } else {
                echo "No cache found for product ID: {$productId}\n";
            }
        } else {
            $logger->info("Clearing all product caches");
            $powerbody->clearProductCache();
            echo "All product caches have been cleared\n";
        }
        break;
        
    case 'refresh':
        if (!$productId) {
            echo "Error: Product ID required for refresh command\n";
            echo "Usage: php cache-manager.php refresh <product_id>\n";
            exit(1);
        }
        
        $logger->info("Refreshing cache for product ID: {$productId}");
        $success = $powerbody->refreshProductCache($productId);
        if ($success) {
            echo "Successfully refreshed cache for product ID: {$productId}\n";
        } else {
            echo "Failed to refresh cache for product ID: {$productId}\n";
        }
        break;
        
    case 'list':
        $cacheDir = dirname(__DIR__) . '/storage/cache/products';
        
        if (!file_exists($cacheDir)) {
            echo "No cache directory found\n";
            exit(0);
        }
        
        $files = glob($cacheDir . "/product_*.json");
        $count = count($files);
        
        echo "Found {$count} cached product files\n";
        
        if ($count > 0) {
            echo "\nCACHE STATISTICS:\n";
            echo str_repeat('-', 80) . "\n";
            echo sprintf("%-20s %-30s %-30s\n", "PRODUCT ID", "CACHED AT", "EXPIRES AT");
            echo str_repeat('-', 80) . "\n";
            
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $data = json_decode($content, true);
                
                if ($data) {
                    $productId = str_replace(['product_', '.json'], '', basename($file));
                    if ($productId === 'list') $productId = 'LIST';
                    
                    $cachedAt = date('Y-m-d H:i:s', $data['cached_at'] ?? 0);
                    $expiresAt = date('Y-m-d H:i:s', $data['expires_at'] ?? 0);
                    
                    echo sprintf("%-20s %-30s %-30s\n", $productId, $cachedAt, $expiresAt);
                }
            }
            echo str_repeat('-', 80) . "\n";
        }
        break;
        
    case 'status':
        $cacheDir = dirname(__DIR__) . '/storage/cache/products';
        
        if (!file_exists($cacheDir)) {
            echo "No cache directory found\n";
            exit(0);
        }
        
        $files = glob($cacheDir . "/product_*.json");
        $count = count($files);
        $size = 0;
        $expired = 0;
        $valid = 0;
        $now = time();
        
        foreach ($files as $file) {
            $size += filesize($file);
            
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            if ($data && isset($data['expires_at'])) {
                if ($data['expires_at'] < $now) {
                    $expired++;
                } else {
                    $valid++;
                }
            }
        }
        
        $sizeMB = round($size / (1024 * 1024), 2);
        
        echo "CACHE STATUS SUMMARY:\n";
        echo "Total cache files: {$count}\n";
        echo "Valid cache files: {$valid}\n";
        echo "Expired cache files: {$expired}\n";
        echo "Total cache size: {$sizeMB} MB\n";
        break;
        
    case 'help':
    default:
        echo "PowerBody Product Cache Manager\n\n";
        echo "Usage: php cache-manager.php [command] [options]\n\n";
        echo "Available commands:\n";
        echo "  list                     - List all cached products\n";
        echo "  status                   - Show cache status summary\n";
        echo "  clear [product_id]       - Clear all caches or specific product cache\n";
        echo "  refresh <product_id>     - Refresh cache for specific product\n";
        echo "  help                     - Show this help message\n\n";
        break;
}

$logger->info('Cache management completed'); 