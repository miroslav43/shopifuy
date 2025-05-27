<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Logger\SyncStats;
use App\Logger\Factory as LoggerFactory;

$logger = LoggerFactory::getInstance('cli');

// Parse command line arguments
$options = getopt('t:d:f:r:h', ['type:', 'days:', 'from:', 'to:', 'run:', 'help']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    showHelp();
    exit(0);
}

// Get type parameter
$type = $options['t'] ?? $options['type'] ?? 'all';
$validTypes = ['all', 'product', 'order', 'comment', 'refund'];
if (!in_array($type, $validTypes)) {
    $logger->error("Invalid type: $type. Valid types are: " . implode(', ', $validTypes));
    exit(1);
}

// Get date range
$startDate = null;
$endDate = date('Y-m-d');

if (isset($options['d']) || isset($options['days'])) {
    $days = (int)($options['d'] ?? $options['days']);
    $startDate = date('Y-m-d', strtotime("-$days days"));
} elseif (isset($options['f']) || isset($options['from'])) {
    $startDate = $options['f'] ?? $options['from'];
    if (!validateDate($startDate)) {
        $logger->error("Invalid from date: $startDate. Use format YYYY-MM-DD.");
        exit(1);
    }
}

if (isset($options['to'])) {
    $endDate = $options['to'];
    if (!validateDate($endDate)) {
        $logger->error("Invalid to date: $endDate. Use format YYYY-MM-DD.");
        exit(1);
    }
}

// Get run ID if provided
$runId = isset($options['r']) ? (int)($options['r']) : (isset($options['run']) ? (int)($options['run']) : null);

// Initialize the stats handler
$stats = SyncStats::getInstance();

// Display the requested statistics
if ($runId) {
    // Display details for a specific run
    displayRunDetails($stats, $runId);
} else {
    // Display general statistics for the date range
    displayStats($stats, $type, $startDate, $endDate);
    
    // Also show recent runs
    displayRecentRuns($stats);
}

/**
 * Display help information
 */
function showHelp(): void {
    echo "PowerBody Sync Statistics Tool\n";
    echo "-----------------------------\n";
    echo "Usage: php sync-stats.php [options]\n\n";
    echo "Options:\n";
    echo "  -t, --type TYPE    Sync type to show (all, product, order, comment, refund) [default: all]\n";
    echo "  -d, --days DAYS    Number of days to look back [default: 7]\n";
    echo "  -f, --from DATE    Start date (YYYY-MM-DD format)\n";
    echo "      --to DATE      End date (YYYY-MM-DD format) [default: today]\n";
    echo "  -r, --run ID       Show details for a specific sync run ID\n";
    echo "  -h, --help         Show this help message\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php sync-stats.php                  Show stats for all sync types in the last 7 days\n";
    echo "  php sync-stats.php -t order -d 14   Show order sync stats for the last 14 days\n";
    echo "  php sync-stats.php -r 123           Show detailed logs for sync run ID 123\n";
    echo "\n";
}

/**
 * Display statistics for a date range
 */
function displayStats(SyncStats $stats, string $type, ?string $startDate, string $endDate): void {
    $results = $stats->getStats($type, $startDate, $endDate);
    
    if (empty($results)) {
        echo "No statistics found for the specified criteria.\n";
        return;
    }
    
    echo "\nSync Statistics (" . ($startDate ?? 'last 7 days') . " to $endDate)\n";
    echo "=======================================================\n";
    
    // Setup table headers
    $headers = ['Date', 'Type', 'Runs', 'Success', 'Failed', 'Items', 'Success', 'Failed', 'Success %'];
    
    // Calculate column widths
    $widths = [];
    foreach ($headers as $i => $header) {
        $widths[$i] = strlen($header);
    }
    
    foreach ($results as $row) {
        $widths[0] = max($widths[0], strlen($row['sync_date']));
        $widths[1] = max($widths[1], strlen($row['sync_type']));
        $widths[2] = max($widths[2], strlen($row['runs_total']));
        $widths[3] = max($widths[3], strlen($row['runs_succeeded']));
        $widths[4] = max($widths[4], strlen($row['runs_failed']));
        $widths[5] = max($widths[5], strlen($row['items_processed']));
        $widths[6] = max($widths[6], strlen($row['items_succeeded']));
        $widths[7] = max($widths[7], strlen($row['items_failed']));
    }
    
    // Print header row
    foreach ($headers as $i => $header) {
        echo str_pad($header, $widths[$i] + 2);
    }
    echo "\n";
    
    // Print separator
    $separator = '';
    foreach ($widths as $width) {
        $separator .= str_repeat('-', $width + 2);
    }
    echo $separator . "\n";
    
    // Print data rows
    foreach ($results as $row) {
        $successRate = $row['items_processed'] > 0 
            ? round(($row['items_succeeded'] / $row['items_processed']) * 100, 1) 
            : 0;
            
        echo str_pad($row['sync_date'], $widths[0] + 2);
        echo str_pad($row['sync_type'], $widths[1] + 2);
        echo str_pad($row['runs_total'], $widths[2] + 2);
        echo str_pad($row['runs_succeeded'], $widths[3] + 2);
        echo str_pad($row['runs_failed'], $widths[4] + 2);
        echo str_pad($row['items_processed'], $widths[5] + 2);
        echo str_pad($row['items_succeeded'], $widths[6] + 2);
        echo str_pad($row['items_failed'], $widths[7] + 2);
        echo str_pad($successRate . '%', 8);
        echo "\n";
    }
    
    echo "\n";
}

/**
 * Display details for a specific run
 */
function displayRunDetails(SyncStats $stats, int $runId): void {
    $data = $stats->getRunDetails($runId);
    
    if (empty($data)) {
        echo "No data found for run ID: $runId\n";
        return;
    }
    
    $run = $data['run'];
    $details = $data['details'];
    
    echo "\nSync Run Details (ID: $runId)\n";
    echo "=======================================================\n";
    echo "Type: {$run['sync_type']}\n";
    echo "Status: {$run['status']}\n";
    echo "Start time: {$run['start_time']}\n";
    echo "End time: " . ($run['end_time'] ?? 'N/A') . "\n";
    echo "Items processed: {$run['items_processed']}\n";
    echo "Items succeeded: {$run['items_succeeded']}\n";
    echo "Items failed: {$run['items_failed']}\n";
    echo "Success rate: " . ($run['items_processed'] > 0 
        ? round(($run['items_succeeded'] / $run['items_processed']) * 100, 1) . '%' 
        : 'N/A') . "\n";
    echo "\n";
    
    if (empty($details)) {
        echo "No detailed logs available for this run.\n";
        return;
    }
    
    echo "Detailed Log:\n";
    echo "=======================================================\n";
    
    // Setup table headers
    $headers = ['Timestamp', 'Item ID', 'Type', 'Operation', 'Status', 'Message'];
    
    // Calculate column widths
    $widths = [];
    foreach ($headers as $i => $header) {
        $widths[$i] = strlen($header);
    }
    
    foreach ($details as $row) {
        $widths[0] = max($widths[0], strlen($row['timestamp']));
        $widths[1] = max($widths[1], min(strlen($row['item_id']), 20));
        $widths[2] = max($widths[2], strlen($row['item_type']));
        $widths[3] = max($widths[3], strlen($row['operation']));
        $widths[4] = max($widths[4], strlen($row['status']));
    }
    
    // Print header row
    foreach ($headers as $i => $header) {
        echo str_pad($header, $widths[$i] + 2);
    }
    echo "\n";
    
    // Print separator
    $separator = '';
    foreach ($widths as $width) {
        $separator .= str_repeat('-', $width + 2);
    }
    echo $separator . "\n";
    
    // Print data rows
    foreach ($details as $row) {
        $itemId = strlen($row['item_id']) > 20 
            ? substr($row['item_id'], 0, 17) . '...' 
            : $row['item_id'];
            
        echo str_pad($row['timestamp'], $widths[0] + 2);
        echo str_pad($itemId, $widths[1] + 2);
        echo str_pad($row['item_type'], $widths[2] + 2);
        echo str_pad($row['operation'], $widths[3] + 2);
        echo str_pad($row['status'], $widths[4] + 2);
        echo $row['message'] . "\n";
    }
    
    echo "\n";
}

/**
 * Display recent runs
 */
function displayRecentRuns(SyncStats $stats): void {
    $runs = $stats->getRecentRuns();
    
    if (empty($runs)) {
        echo "No recent sync runs found.\n";
        return;
    }
    
    echo "Recent Sync Runs\n";
    echo "=======================================================\n";
    
    // Setup table headers
    $headers = ['ID', 'Type', 'Start Time', 'Status', 'Items', 'Success', 'Failed', 'Success %'];
    
    // Calculate column widths
    $widths = [];
    foreach ($headers as $i => $header) {
        $widths[$i] = strlen($header);
    }
    
    foreach ($runs as $row) {
        $widths[0] = max($widths[0], strlen($row['id']));
        $widths[1] = max($widths[1], strlen($row['sync_type']));
        $widths[2] = max($widths[2], strlen($row['start_time']));
        $widths[3] = max($widths[3], strlen($row['status'] ?? 'N/A'));
        $widths[4] = max($widths[4], strlen($row['items_processed']));
        $widths[5] = max($widths[5], strlen($row['items_succeeded']));
        $widths[6] = max($widths[6], strlen($row['items_failed']));
    }
    
    // Print header row
    foreach ($headers as $i => $header) {
        echo str_pad($header, $widths[$i] + 2);
    }
    echo "\n";
    
    // Print separator
    $separator = '';
    foreach ($widths as $width) {
        $separator .= str_repeat('-', $width + 2);
    }
    echo $separator . "\n";
    
    // Print data rows
    foreach ($runs as $row) {
        $successRate = $row['items_processed'] > 0 
            ? round(($row['items_succeeded'] / $row['items_processed']) * 100, 1) 
            : 0;
            
        echo str_pad($row['id'], $widths[0] + 2);
        echo str_pad($row['sync_type'], $widths[1] + 2);
        echo str_pad($row['start_time'], $widths[2] + 2);
        echo str_pad($row['status'] ?? 'running', $widths[3] + 2);
        echo str_pad($row['items_processed'], $widths[4] + 2);
        echo str_pad($row['items_succeeded'], $widths[5] + 2);
        echo str_pad($row['items_failed'], $widths[6] + 2);
        echo str_pad($successRate . '%', 8);
        echo "\n";
    }
    
    echo "\nTip: Run 'php sync-stats.php -r ID' to see details for a specific run.\n\n";
}

/**
 * Validate date format
 */
function validateDate(string $date, string $format = 'Y-m-d'): bool {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
} 