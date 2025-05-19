<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Logger\Factory as LoggerFactory;

// Validate arguments
if ($argc < 5) {
    die("Usage: php worker.php <worker_type> <worker_id> <chunk_file> <result_file>\n");
}

$workerType = $argv[1];
$workerId = (int)$argv[2];
$chunkFile = $argv[3];
$resultFile = $argv[4];

// Initialize logger
$logger = LoggerFactory::getInstance('worker-runner');
$logger->info("Worker runner starting - Type: {$workerType}, ID: {$workerId}");

try {
    // Validate worker type
    $className = 'App\\Sync\\' . $workerType;
    if (!class_exists($className)) {
        throw new Exception("Worker class {$className} not found");
    }
    
    // Validate chunk file
    if (!file_exists($chunkFile)) {
        throw new Exception("Chunk file not found: {$chunkFile}");
    }
    
    // Load items from chunk file
    $json = file_get_contents($chunkFile);
    $items = json_decode($json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON in chunk file: " . json_last_error_msg());
    }
    
    if (!is_array($items)) {
        throw new Exception("Chunk file does not contain an array of items");
    }
    
    // Create worker instance
    $worker = new $className($workerId);
    $logger->info("Created worker instance of type {$className}");
    
    // Run the worker
    $result = $worker->run($items);
    
    // Write result to file
    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'worker_type' => $workerType,
        'processed' => count($items),
        'success' => count($result['success'] ?? []),
        'failed' => count($result['failed'] ?? []),
        'data' => $result['success'] ?? [],
        'progress' => [
            'processed_items' => count($items),
            'total_items' => count($items),
            'progress_percent' => 100,
            'elapsed_time' => 0,
            'items_per_second' => 0,
            'estimated_time_remaining' => 0
        ]
    ]));
    
    $logger->info("Worker completed successfully - Type: {$workerType}, ID: {$workerId}");
    exit(0);
    
} catch (Exception $e) {
    $logger->error("Worker error: " . $e->getMessage(), [
        'worker_type' => $workerType,
        'worker_id' => $workerId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Write error to result file
    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'worker_type' => $workerType,
        'error' => $e->getMessage(),
        'data' => [],
        'progress' => [
            'processed_items' => 0,
            'total_items' => 0,
            'progress_percent' => 0,
            'elapsed_time' => 0,
            'items_per_second' => 0,
            'estimated_time_remaining' => 0
        ]
    ]));
    
    exit(1);
} 