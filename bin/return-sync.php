<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');

// TODO: Implement return sync once PowerBody API supports it
$logger->info('Return sync is not implemented yet');
exit(0); 