<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\ProductSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');

// Parse command line arguments
$useWorkers = true; // Default to using workers
$workerCount = 4;   // Default worker count

// Parse arguments
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

// Catch any errors
try {
    $logger->info('Starting product sync CLI script');
    
    if ($useWorkers) {
        $logger->info("Using worker-based architecture with {$workerCount} workers");
    } else {
        $logger->info("Using direct synchronization (no workers)");
    }
    
    $productSync = new ProductSync($useWorkers, $workerCount);
    $productSync->sync();
    $logger->info('Product sync completed');
    exit(0);
} catch (Exception $e) {
    $logger->error('Product sync failed: ' . $e->getMessage());
    exit(1);
} 