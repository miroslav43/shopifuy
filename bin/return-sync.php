<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\ReturnSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');
$logger->info('Starting return/refund sync process');

$exitCode = 0;

try {
    $returnSync = new ReturnSync();
    $returnSync->sync();
    $logger->info('Return/refund sync completed successfully');
} catch (Exception $e) {
    $logger->error('Return/refund sync process encountered an error: ' . $e->getMessage());
    $exitCode = 1;
}

exit($exitCode); 