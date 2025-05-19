<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\ProductSync;
use App\Sync\OrderSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');
$logger->info('Starting sync process');

// Parameters
$syncProducts = isset($argv[1]) && ($argv[1] === 'all' || $argv[1] === 'products');
$syncOrders = isset($argv[1]) && ($argv[1] === 'all' || $argv[1] === 'orders');

// Worker configuration
$useWorkers = true;
$workerCount = 4;

// Parse additional arguments
foreach ($argv as $arg) {
    if ($arg === '--no-workers') {
        $useWorkers = false;
        $logger->info('Worker mode disabled by command line argument');
    } elseif (strpos($arg, '--workers=') === 0) {
        $count = (int)substr($arg, 10);
        if ($count > 0) {
            $workerCount = $count;
            $logger->info("Worker count set to {$workerCount} from command line");
        }
    }
}

if (!$syncProducts && !$syncOrders) {
    // Default to run everything if no parameters
    $syncProducts = true;
    $syncOrders = true;
}

$exitCode = 0;

try {
    if ($syncProducts) {
        $logger->info('Starting product sync');
        
        if ($useWorkers) {
            $logger->info("Using worker-based architecture with {$workerCount} workers for product sync");
        } else {
            $logger->info("Using direct synchronization for product sync (no workers)");
        }
        
        $productSync = new ProductSync($useWorkers, $workerCount);
        $productSync->sync();
        $logger->info('Product sync completed');
    }
    
    if ($syncOrders) {
        $logger->info('Starting order sync');
        
        if ($useWorkers) {
            $logger->info("Using worker-based architecture with {$workerCount} workers for order sync");
        } else {
            $logger->info("Using direct synchronization for order sync (no workers)");
        }
        
        $orderSync = new OrderSync($useWorkers, $workerCount);
        $orderSync->sync();
        $logger->info('Order sync completed');
    }
} catch (Exception $e) {
    $logger->error('Sync process encountered an error: ' . $e->getMessage());
    $exitCode = 1;
}

$logger->info('Sync process finished');
exit($exitCode); 