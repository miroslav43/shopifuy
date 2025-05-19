<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Logger\Factory as LoggerFactory;

// Initialize logger
$logger = LoggerFactory::getInstance('monitor');
$logger->info('Starting sync process monitor');

// Configuration
$tempDir = dirname(__DIR__) . '/storage/temp';
$refreshInterval = 1; // seconds
$clearScreen = true;
$showDetails = false;

// Parse command line arguments
foreach ($argv as $arg) {
    if ($arg === '--no-clear') {
        $clearScreen = false;
    } elseif ($arg === '--details') {
        $showDetails = true;
    } elseif (strpos($arg, '--interval=') === 0) {
        $interval = (int)substr($arg, 11);
        if ($interval > 0) {
            $refreshInterval = $interval;
        }
    }
}

// Initialize stats
$stats = [
    'start_time' => time(),
    'workers' => [],
    'processed' => 0,
    'total' => 0,
    'success' => 0,
    'failed' => 0
];

// Main monitoring loop
while (true) {
    // Clear screen if enabled
    if ($clearScreen) {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }
    
    // Scan temp directory for worker result files
    $resultFiles = glob($tempDir . '/worker_*_result.json');
    
    // Reset worker counts
    $stats['workers'] = [];
    $stats['processed'] = 0;
    $stats['total'] = 0;
    $stats['success'] = 0;
    $stats['failed'] = 0;
    
    // Get job IDs
    $jobIds = [];
    foreach ($resultFiles as $file) {
        if (preg_match('/worker_(\d+)_result\.json$/', $file, $matches)) {
            $jobIds[$matches[1]] = $file;
        }
    }
    
    // Process result files
    if (!empty($resultFiles)) {
        foreach ($jobIds as $workerId => $file) {
            $data = @json_decode(file_get_contents($file), true);
            
            if ($data) {
                // Store worker info
                $progress = $data['progress'] ?? [];
                
                if (!empty($progress)) {
                    $stats['workers'][$workerId] = $progress;
                    $stats['processed'] += $progress['processed_items'] ?? 0;
                    $stats['total'] += $progress['total_items'] ?? 0;
                }
                
                // Count success/failure
                $stats['success'] += isset($data['success']) ? count($data['success']) : 0;
                $stats['failed'] += isset($data['failed']) ? count($data['failed']) : 0;
            }
        }
    }
    
    // Display header
    echo "======================================================\n";
    echo "          WORKER SYNCHRONIZATION MONITOR              \n";
    echo "======================================================\n";
    echo "Monitoring directory: " . $tempDir . "\n";
    echo "Running time: " . formatTime(time() - $stats['start_time']) . "\n";
    echo "Active workers: " . count($stats['workers']) . "\n";
    echo "Progress: " . ($stats['total'] > 0 ? round(($stats['processed'] / $stats['total']) * 100, 1) : 0) . "% (" . $stats['processed'] . "/" . $stats['total'] . ")\n";
    echo "Success: " . $stats['success'] . ", Failed: " . $stats['failed'] . "\n\n";
    
    // Display worker details
    if (!empty($stats['workers'])) {
        echo "Worker Status:\n";
        echo "------------------------------------------------------\n";
        echo sprintf("%-8s %-12s %-12s %-12s %-12s\n", "Worker", "Progress", "Items/sec", "Elapsed", "Remaining");
        echo "------------------------------------------------------\n";
        
        foreach ($stats['workers'] as $workerId => $progress) {
            $percent = $progress['progress_percent'] ?? 0;
            $itemsPerSec = $progress['items_per_second'] ?? 0;
            $elapsed = $progress['elapsed_time'] ?? 0;
            $remaining = $progress['estimated_time_remaining'] ?? 'unknown';
            
            echo sprintf(
                "%-8s %-12s %-12s %-12s %-12s\n",
                "#" . $workerId,
                $percent . "%",
                number_format($itemsPerSec, 2),
                formatTime($elapsed),
                is_numeric($remaining) ? formatTime($remaining) : $remaining
            );
        }
        
        echo "\n";
    } else {
        echo "No active workers found.\n\n";
    }
    
    if ($showDetails && !empty($stats['workers'])) {
        echo "Detailed Worker Information:\n";
        echo "------------------------------------------------------\n";
        
        foreach ($stats['workers'] as $workerId => $progress) {
            echo "Worker #" . $workerId . ":\n";
            foreach ($progress as $key => $value) {
                echo "  " . str_pad($key . ":", 25) . " " . (is_numeric($value) ? number_format($value, 2) : $value) . "\n";
            }
            echo "\n";
        }
    }
    
    echo "Press Ctrl+C to exit\n";
    
    // Sleep for refresh interval
    sleep($refreshInterval);
}

/**
 * Format time in seconds to human-readable format
 */
function formatTime($seconds) {
    if (!is_numeric($seconds)) {
        return $seconds;
    }
    
    $seconds = (int)$seconds;
    
    if ($seconds < 60) {
        return $seconds . "s";
    }
    
    $minutes = floor($seconds / 60);
    $seconds = $seconds % 60;
    
    if ($minutes < 60) {
        return $minutes . "m " . $seconds . "s";
    }
    
    $hours = floor($minutes / 60);
    $minutes = $minutes % 60;
    
    return $hours . "h " . $minutes . "m " . $seconds . "s";
} 