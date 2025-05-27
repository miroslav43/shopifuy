<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Sync\CommentSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');
$logger->info('Starting comment sync process');

$exitCode = 0;

try {
    $commentSync = new CommentSync();
    $commentSync->sync();
    $logger->info('Comment sync completed successfully');
} catch (Exception $e) {
    $logger->error('Comment sync process encountered an error: ' . $e->getMessage());
    $exitCode = 1;
}

exit($exitCode); 