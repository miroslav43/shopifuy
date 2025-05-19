<?php

namespace App\Core;

use App\Logger\Factory as LoggerFactory;
use Exception;

abstract class Worker
{
    protected $logger;
    protected $workerId;
    protected $shouldStop = false;
    protected $processedItems = 0;
    protected $totalItems = 0;
    protected $startTime;
    protected $lastUpdateTime;
    protected $updateIntervalSeconds = 10;

    public function __construct(int $workerId)
    {
        $this->workerId = $workerId;
        $this->logger = LoggerFactory::getInstance('worker-' . $workerId);
        $this->startTime = microtime(true);
        $this->lastUpdateTime = $this->startTime;
    }

    /**
     * Initialize the worker with items to process
     */
    public function initialize(array $items): void
    {
        $this->totalItems = count($items);
        $this->processedItems = 0;
        $this->logger->info("Worker #{$this->workerId} initialized with {$this->totalItems} items");
    }

    /**
     * Run the worker on the provided items
     */
    abstract public function run(array $items): array;

    /**
     * Process a single item
     */
    abstract protected function processItem($item): bool;

    /**
     * Signal the worker to stop after completing current item
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        $this->logger->info("Worker #{$this->workerId} received stop signal");
    }

    /**
     * Get worker status
     */
    public function getStatus(): array
    {
        $now = microtime(true);
        $elapsedTime = $now - $this->startTime;
        $itemsPerSecond = $elapsedTime > 0 ? $this->processedItems / $elapsedTime : 0;
        
        $remainingItems = $this->totalItems - $this->processedItems;
        $estimatedTimeRemaining = $itemsPerSecond > 0 ? $remainingItems / $itemsPerSecond : -1;
        
        return [
            'worker_id' => $this->workerId,
            'processed_items' => $this->processedItems,
            'total_items' => $this->totalItems,
            'progress_percent' => $this->totalItems > 0 ? round(($this->processedItems / $this->totalItems) * 100, 1) : 0,
            'elapsed_time' => round($elapsedTime, 2),
            'items_per_second' => round($itemsPerSecond, 2),
            'estimated_time_remaining' => $estimatedTimeRemaining > 0 ? round($estimatedTimeRemaining, 2) : 'unknown'
        ];
    }

    /**
     * Log progress if update interval has passed
     */
    protected function logProgressIfNeeded(): void
    {
        $now = microtime(true);
        if (($now - $this->lastUpdateTime) >= $this->updateIntervalSeconds) {
            $status = $this->getStatus();
            $this->logger->info("Progress: {$status['progress_percent']}% ({$status['processed_items']}/{$status['total_items']}) - {$status['items_per_second']} items/sec");
            $this->lastUpdateTime = $now;
        }
    }
} 