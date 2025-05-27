<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\ProductSync;
use App\Sync\OrderSync;
use App\Sync\CommentSync;
use App\Sync\ReturnSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');
$logger->info('Starting sync process');

// Parameters
$syncProducts = isset($argv[1]) && ($argv[1] === 'all' || $argv[1] === 'products');
$syncOrders = isset($argv[1]) && ($argv[1] === 'all' || $argv[1] === 'orders');
$syncComments = isset($argv[1]) && ($argv[1] === 'all' || $argv[1] === 'comments');
$syncReturns = isset($argv[1]) && ($argv[1] === 'all' || $argv[1] === 'returns');

if (!$syncProducts && !$syncOrders && !$syncComments && !$syncReturns) {
    // Default to run everything if no parameters
    $syncProducts = true;
    $syncOrders = true;
    $syncComments = true;
    $syncReturns = true;
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
        $logger->info('Starting order sync');
        $orderSync = new OrderSync();
        $orderSync->sync();
        $logger->info('Order sync completed');
    }
    
    if ($syncComments) {
        $logger->info('Starting comment sync');
        $commentSync = new CommentSync();
        $commentSync->sync();
        $logger->info('Comment sync completed');
    }
    
    if ($syncReturns) {
        $logger->info('Starting return/refund sync');
        $returnSync = new ReturnSync();
        $returnSync->sync();
        $logger->info('Return/refund sync completed');
    }
} catch (Exception $e) {
    $logger->error('Sync process encountered an error: ' . $e->getMessage());
    $exitCode = 1;
}

$logger->info('Sync process finished');
exit($exitCode); 