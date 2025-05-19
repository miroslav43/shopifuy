<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\PowerBodyLink;
use App\Core\WorkerManager;
use App\Sync\ProductSync;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('worker-test');
$logger->info('Starting worker-based architecture test');

// Get command line option for what to test
$testType = isset($argv[1]) ? strtolower($argv[1]) : 'comparison';
$workerCount = isset($argv[2]) ? (int)$argv[2] : 4;

// Create API instance
$powerbody = new PowerBodyLink();

// Function to test direct sync
function testDirectSync($powerbody, $logger)
{
    $logger->info("=== Testing Direct Sync ===");
    
    try {
        // Get product list
        $productList = $powerbody->getProductList();
        
        if (empty($productList)) {
            $logger->error("Failed to get product list");
            return false;
        }
        
        // Take a subset for testing
        $testProducts = array_slice($productList, 0, 20);
        $logger->info("Testing with " . count($testProducts) . " products");
        
        // Run sync with workers disabled
        $startTime = microtime(true);
        $productSync = new ProductSync(false);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass('App\Sync\ProductSync');
        $processMethod = $reflection->getMethod('processProductsBatchDirect');
        $processMethod->setAccessible(true);
        $processMethod->invokeArgs($productSync, [$testProducts]);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $logger->info("Direct sync completed in {$duration} seconds");
        
        return $duration;
    } catch (Exception $e) {
        $logger->error("Direct sync test failed: " . $e->getMessage());
        return false;
    }
}

// Function to test worker-based sync
function testWorkerSync($powerbody, $logger, $workerCount)
{
    $logger->info("=== Testing Worker-Based Sync with {$workerCount} workers ===");
    
    try {
        // Get product list
        $productList = $powerbody->getProductList();
        
        if (empty($productList)) {
            $logger->error("Failed to get product list");
            return false;
        }
        
        // Take a subset for testing
        $testProducts = array_slice($productList, 0, 20);
        $logger->info("Testing with " . count($testProducts) . " products");
        
        // Create worker manager
        $workerManager = new WorkerManager(
            'ProductSyncWorker',
            dirname(__DIR__) . '/bin/worker.php',
            $workerCount
        );
        
        // Process products with workers
        $startTime = microtime(true);
        $workerManager->processItems($testProducts);
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $logger->info("Worker-based sync completed in {$duration} seconds");
        
        return $duration;
    } catch (Exception $e) {
        $logger->error("Worker-based sync test failed: " . $e->getMessage());
        return false;
    }
}

// Run performance comparison
function runPerformanceComparison($powerbody, $logger, $workerCount)
{
    $logger->info("=== Running Performance Comparison ===");
    
    // Test direct sync
    $directTime = testDirectSync($powerbody, $logger);
    
    // Test worker-based sync
    $workerTime = testWorkerSync($powerbody, $logger, $workerCount);
    
    if ($directTime && $workerTime) {
        $speedup = $directTime > 0 ? round($directTime / $workerTime, 2) : 0;
        $improvement = $directTime > 0 ? round((($directTime - $workerTime) / $directTime) * 100, 2) : 0;
        
        $logger->info("Performance comparison results:");
        $logger->info("Direct sync: {$directTime} seconds");
        $logger->info("Worker-based sync ({$workerCount} workers): {$workerTime} seconds");
        $logger->info("Speedup: {$speedup}x");
        $logger->info("Improvement: {$improvement}%");
        
        return [
            'direct_time' => $directTime,
            'worker_time' => $workerTime,
            'speedup' => $speedup,
            'improvement' => $improvement
        ];
    }
    
    return false;
}

// Run different tests based on command line option
switch ($testType) {
    case 'direct':
        $duration = testDirectSync($powerbody, $logger);
        if ($duration) {
            $logger->info("Direct sync test completed in {$duration} seconds");
        }
        break;
    
    case 'worker':
        $duration = testWorkerSync($powerbody, $logger, $workerCount);
        if ($duration) {
            $logger->info("Worker-based sync test with {$workerCount} workers completed in {$duration} seconds");
        }
        break;
    
    case 'comparison':
    default:
        $results = runPerformanceComparison($powerbody, $logger, $workerCount);
        if ($results) {
            echo "\n";
            echo "=============================================\n";
            echo "           PERFORMANCE COMPARISON            \n";
            echo "=============================================\n";
            echo "Direct sync:            {$results['direct_time']} seconds\n";
            echo "Worker-based sync:      {$results['worker_time']} seconds\n";
            echo "Speed improvement:      {$results['improvement']}%\n";
            echo "Speedup factor:         {$results['speedup']}x\n";
            echo "Number of workers:      {$workerCount}\n";
            echo "=============================================\n";
        }
        break;
}

$logger->info('Test completed');
exit(0); 