<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Sync\ProductSync;

// Get starting batch index from command line argument, default to 0
$startBatchIndex = isset($argv[1]) ? (int)$argv[1] : 0;

// Initialize the product sync
$sync = new ProductSync();

echo "Starting product sync from batch {$startBatchIndex}...\n";

// Run the sync process starting from the specified batch
$sync->sync($startBatchIndex);

echo "Sync completed successfully.\n"; 