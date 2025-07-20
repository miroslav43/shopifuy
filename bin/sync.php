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

if (!$syncProducts && !$syncOrders) {
    // Default to run everything if no parameters
    $syncProducts = true;
    $syncOrders = true;
}

$exitCode = 0;

try {
    if ($syncProducts) {
        $logger->info('Starting product sync');
        $productSync = new ProductSync();
        $productSync->sync();
        $logger->info('Product sync completed');
    }
    
    if ($syncOrders) {
        $logger->info('Starting order sync (includes comments and returns)');
        $orderSync = new OrderSync();
        $orderSync->sync();
        $logger->info('Order sync completed');
    }
} catch (Exception $e) {
    $logger->error('Sync process encountered an error: ' . $e->getMessage());
    $exitCode = 1;
}

$logger->info('Sync process finished');
exit($exitCode); 