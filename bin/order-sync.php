<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\OrderSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');

// Catch any errors
try {
    $logger->info('Starting order sync CLI script');
    $orderSync = new OrderSync();
    $orderSync->sync();
    $logger->info('Order sync completed');
    exit(0);
} catch (Exception $e) {
    $logger->error('Order sync failed: ' . $e->getMessage());
    exit(1);
} 