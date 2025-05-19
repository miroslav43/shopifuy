<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');

// TODO: Implement comment sync class
$logger->info('Comment sync is not implemented yet');
exit(0); 