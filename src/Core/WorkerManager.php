<?php

namespace App\Core;

use App\Logger\Factory as LoggerFactory;
use Exception;

class WorkerManager
{
    private $logger;
    private $maxWorkers;
    private $workerType;
    private $workerScript;
    private $childProcesses = [];
    private $startTime;
    private $items = [];
    private $results = [];
    private $workerStatus = [];
    private $tempDir;

    /**
     * @param string $workerType Type of worker to use (must be a valid Worker class name without namespace)
     * @param string $workerScript Path to the PHP script that will run the worker
     * @param int $maxWorkers Maximum number of worker processes to spawn
     */
    public function __construct(string $workerType, string $workerScript, int $maxWorkers = 4)
    {
        $this->logger = LoggerFactory::getInstance('worker-manager');
        $this->workerType = $workerType;
        $this->workerScript = $workerScript;
        $this->maxWorkers = max(1, min($maxWorkers, 32)); // Limit to 1-32 workers
        $this->tempDir = dirname(__DIR__, 2) . '/storage/temp';
        
        // Create temp directory if it doesn't exist
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
        
        $this->logger->info("Worker manager initialized with max {$this->maxWorkers} workers of type {$workerType}");
    }

    /**
     * Process items in parallel using worker processes
     * 
     * @param array $items Items to process
     * @return array Results from all workers
     */
    public function processItems(array $items): array
    {
        if (empty($items)) {
            $this->logger->warning('No items to process');
            return [];
        }
        
        $this->startTime = microtime(true);
        $this->items = $items;
        $this->results = [];
        $itemCount = count($items);
        
        $this->logger->info("Starting parallel processing of {$itemCount} items with {$this->maxWorkers} workers");
        
        // Determine chunk size based on number of workers
        $workerCount = min($this->maxWorkers, $itemCount);
        $chunkSize = ceil($itemCount / $workerCount);
        
        // Split items into chunks for each worker
        $chunks = array_chunk($items, $chunkSize);
        
        $this->logger->info("Divided {$itemCount} items into {$workerCount} chunks of ~{$chunkSize} items each");
        
        // Launch worker processes
        foreach ($chunks as $workerIndex => $chunk) {
            $this->startWorker($workerIndex, $chunk);
        }
        
        // Wait for all workers to complete
        $this->waitForWorkers();
        
        // Collect and process results
        $this->collectResults();
        
        $duration = round(microtime(true) - $this->startTime, 2);
        $this->logger->info("Parallel processing completed in {$duration} seconds");
        
        return $this->results;
    }
    
    /**
     * Start a worker process
     */
    private function startWorker(int $workerIndex, array $chunk): void
    {
        $workerId = $workerIndex + 1; // Worker IDs start at 1
        
        // Create a temporary file for the worker's chunk
        $chunkFile = $this->tempDir . "/worker_{$workerId}_chunk.json";
        file_put_contents($chunkFile, json_encode($chunk));
        
        // Create a temporary file for the worker's results
        $resultFile = $this->tempDir . "/worker_{$workerId}_result.json";
        if (file_exists($resultFile)) {
            unlink($resultFile);
        }
        
        // Command to run the worker in background
        $cmd = sprintf(
            'php %s %s %d %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($this->workerScript),
            escapeshellarg($this->workerType),
            $workerId,
            escapeshellarg($chunkFile),
            escapeshellarg($resultFile)
        );
        
        $this->logger->debug("Starting worker #{$workerId} with command: {$cmd}");
        
        // Execute the command and get the process ID
        $output = [];
        exec($cmd, $output);
        $pid = (int) $output[0];
        
        if ($pid > 0) {
            $this->childProcesses[$workerId] = [
                'pid' => $pid,
                'chunk_file' => $chunkFile,
                'result_file' => $resultFile,
                'items' => count($chunk),
                'start_time' => microtime(true)
            ];
            
            $this->logger->info("Started worker #{$workerId} with PID {$pid} to process {$this->childProcesses[$workerId]['items']} items");
        } else {
            $this->logger->error("Failed to start worker #{$workerId}");
        }
    }
    
    /**
     * Wait for all worker processes to complete
     */
    private function waitForWorkers(): void
    {
        $this->logger->info("Waiting for all workers to complete...");
        
        while (!empty($this->childProcesses)) {
            foreach ($this->childProcesses as $workerId => $process) {
                // Check if process is still running
                $status = $this->getProcessStatus($process['pid']);
                
                if ($status === false) {
                    // Process has completed
                    $duration = round(microtime(true) - $process['start_time'], 2);
                    $this->logger->info("Worker #{$workerId} (PID {$process['pid']}) completed in {$duration} seconds");
                    unset($this->childProcesses[$workerId]);
                } else {
                    // Check if result file exists and has content
                    $resultFile = $process['result_file'];
                    if (file_exists($resultFile)) {
                        $progressInfo = $this->readProgressInfo($resultFile);
                        if ($progressInfo) {
                            $this->workerStatus[$workerId] = $progressInfo;
                        }
                    }
                }
            }
            
            // Log status of running workers
            $this->logWorkerStatus();
            
            // Wait before checking again
            if (!empty($this->childProcesses)) {
                sleep(1);
            }
        }
        
        $this->logger->info("All workers have completed");
    }
    
    /**
     * Read progress information from a worker's result file
     */
    private function readProgressInfo(string $resultFile)
    {
        if (!file_exists($resultFile)) {
            return null;
        }
        
        $content = file_get_contents($resultFile);
        if (empty($content)) {
            return null;
        }
        
        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $json['progress'] ?? null;
    }
    
    /**
     * Log status of running workers
     */
    private function logWorkerStatus(): void
    {
        static $lastStatusTime = 0;
        
        $now = microtime(true);
        if (($now - $lastStatusTime) < 5) {
            return; // Only log every 5 seconds
        }
        
        $lastStatusTime = $now;
        
        foreach ($this->workerStatus as $workerId => $status) {
            if (isset($this->childProcesses[$workerId])) {
                $this->logger->info("Worker #{$workerId}: {$status['progress_percent']}% complete, {$status['items_per_second']} items/sec");
            }
        }
    }
    
    /**
     * Check if a process is still running
     */
    private function getProcessStatus(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "tasklist /FI \"PID eq {$pid}\" 2>nul | find \"{$pid}\" >nul";
            exec($cmd, $output, $status);
            return $status === 0;
        } else {
            return file_exists("/proc/{$pid}");
        }
    }
    
    /**
     * Collect results from all worker processes
     */
    private function collectResults(): void
    {
        $this->logger->info("Collecting results from workers...");
        
        $allResults = [];
        
        foreach ($this->workerStatus as $workerId => $status) {
            $resultFile = $this->tempDir . "/worker_{$workerId}_result.json";
            
            if (file_exists($resultFile)) {
                $content = file_get_contents($resultFile);
                
                if (!empty($content)) {
                    $result = json_decode($content, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && isset($result['data'])) {
                        $this->logger->info("Collected " . count($result['data']) . " results from worker #{$workerId}");
                        $allResults = array_merge($allResults, $result['data']);
                    } else {
                        $this->logger->warning("Invalid or empty result from worker #{$workerId}");
                    }
                }
                
                // Clean up
                unlink($resultFile);
            } else {
                $this->logger->warning("No result file found for worker #{$workerId}");
            }
            
            // Also clean up chunk file
            $chunkFile = $this->tempDir . "/worker_{$workerId}_chunk.json";
            if (file_exists($chunkFile)) {
                unlink($chunkFile);
            }
        }
        
        $this->results = $allResults;
        $this->logger->info("Collected a total of " . count($this->results) . " results from all workers");
    }
} 