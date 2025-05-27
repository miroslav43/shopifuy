<?php

namespace App\Logger;

use App\Core\Database;
use SQLite3;
use Exception;

/**
 * Class for tracking detailed sync statistics
 */
class SyncStats
{
    private static ?SyncStats $instance = null;
    private SQLite3 $db;
    private $logger;
    
    private function __construct()
    {
        $this->logger = Factory::getInstance('stats');
        $dbPath = dirname(__DIR__, 2) . '/storage/sync_stats.db';
        
        try {
            $this->db = new SQLite3($dbPath);
            $this->db->enableExceptions(true);
            
            // Create necessary tables if they don't exist
            $this->initTables();
            
            $this->logger->info('Stats database connection established');
        } catch (Exception $e) {
            $this->logger->error('Stats database connection failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function initTables(): void
    {
        // Sync run table - track each sync execution
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sync_run (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_type VARCHAR(50) NOT NULL,
                start_time DATETIME NOT NULL,
                end_time DATETIME,
                status VARCHAR(20),
                items_processed INTEGER DEFAULT 0,
                items_succeeded INTEGER DEFAULT 0,
                items_failed INTEGER DEFAULT 0
            )
        ");
        
        // Sync details table - track details for each item processed
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sync_detail (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_run_id INTEGER NOT NULL,
                item_id VARCHAR(255) NOT NULL,
                item_type VARCHAR(50) NOT NULL,
                operation VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sync_run_id) REFERENCES sync_run(id)
            )
        ");
        
        // Daily summary table - for quick statistics
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sync_daily_summary (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_date DATE NOT NULL,
                sync_type VARCHAR(50) NOT NULL,
                runs_total INTEGER DEFAULT 0,
                runs_succeeded INTEGER DEFAULT 0,
                runs_failed INTEGER DEFAULT 0,
                items_processed INTEGER DEFAULT 0,
                items_succeeded INTEGER DEFAULT 0,
                items_failed INTEGER DEFAULT 0,
                UNIQUE(sync_date, sync_type)
            )
        ");
    }
    
    public static function getInstance(): SyncStats
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Start tracking a new sync run
     * 
     * @param string $syncType Type of sync (product, order, comment, refund)
     * @return int ID of the sync run
     */
    public function startSync(string $syncType): int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_run (sync_type, start_time, status)
                VALUES (:type, datetime('now'), 'running')
            ");
            $stmt->bindValue(':type', $syncType, SQLITE3_TEXT);
            $stmt->execute();
            
            $runId = $this->db->lastInsertRowID();
            $this->logger->info("Started tracking sync run", [
                'run_id' => $runId,
                'sync_type' => $syncType
            ]);
            
            return $runId;
        } catch (Exception $e) {
            $this->logger->error("Failed to start sync tracking: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * End tracking a sync run
     * 
     * @param int $runId ID of the sync run
     * @param string $status Status of the sync (success, failure)
     * @return bool Success or failure
     */
    public function endSync(int $runId, string $status = 'success'): bool
    {
        if ($runId <= 0) {
            return false;
        }
        
        try {
            // Get current counts
            $stmt = $this->db->prepare("
                SELECT items_processed, items_succeeded, items_failed, sync_type 
                FROM sync_run WHERE id = :id
            ");
            $stmt->bindValue(':id', $runId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$row) {
                $this->logger->warning("Sync run not found", ['run_id' => $runId]);
                return false;
            }
            
            // Update the sync run
            $stmt = $this->db->prepare("
                UPDATE sync_run
                SET end_time = datetime('now'), status = :status
                WHERE id = :id
            ");
            $stmt->bindValue(':id', $runId, SQLITE3_INTEGER);
            $stmt->bindValue(':status', $status, SQLITE3_TEXT);
            $stmt->execute();
            
            // Update daily summary
            $this->updateDailySummary($row['sync_type'], $status, $row);
            
            $this->logger->info("Completed tracking sync run", [
                'run_id' => $runId,
                'status' => $status,
                'items_processed' => $row['items_processed'],
                'items_succeeded' => $row['items_succeeded'],
                'items_failed' => $row['items_failed']
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to end sync tracking: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log a sync item operation
     * 
     * @param int $runId ID of the sync run
     * @param string $itemId ID of the item being processed
     * @param string $itemType Type of item (product, order, comment, refund)
     * @param string $operation Operation being performed (create, update, etc.)
     * @param string $status Status of the operation (success, failure)
     * @param string $message Optional message
     * @return bool Success or failure
     */
    public function logItem(int $runId, string $itemId, string $itemType, string $operation, string $status, string $message = ''): bool
    {
        if ($runId <= 0) {
            return false;
        }
        
        try {
            // Log the item detail
            $stmt = $this->db->prepare("
                INSERT INTO sync_detail (sync_run_id, item_id, item_type, operation, status, message)
                VALUES (:run_id, :item_id, :item_type, :operation, :status, :message)
            ");
            $stmt->bindValue(':run_id', $runId, SQLITE3_INTEGER);
            $stmt->bindValue(':item_id', $itemId, SQLITE3_TEXT);
            $stmt->bindValue(':item_type', $itemType, SQLITE3_TEXT);
            $stmt->bindValue(':operation', $operation, SQLITE3_TEXT);
            $stmt->bindValue(':status', $status, SQLITE3_TEXT);
            $stmt->bindValue(':message', $message, SQLITE3_TEXT);
            $stmt->execute();
            
            // Update the run counters
            $stmt = $this->db->prepare("
                UPDATE sync_run
                SET items_processed = items_processed + 1,
                    items_succeeded = items_succeeded + CASE WHEN :status = 'success' THEN 1 ELSE 0 END,
                    items_failed = items_failed + CASE WHEN :status = 'failure' THEN 1 ELSE 0 END
                WHERE id = :run_id
            ");
            $stmt->bindValue(':run_id', $runId, SQLITE3_INTEGER);
            $stmt->bindValue(':status', $status, SQLITE3_TEXT);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to log sync item: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update the daily summary table
     * 
     * @param string $syncType Type of sync
     * @param string $status Status of the run
     * @param array $counts Item counts
     * @return bool Success or failure
     */
    private function updateDailySummary(string $syncType, string $status, array $counts): bool
    {
        try {
            // Check if there's already an entry for today
            $stmt = $this->db->prepare("
                SELECT id FROM sync_daily_summary
                WHERE sync_date = date('now') AND sync_type = :type
            ");
            $stmt->bindValue(':type', $syncType, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($row) {
                // Update existing record
                $stmt = $this->db->prepare("
                    UPDATE sync_daily_summary
                    SET runs_total = runs_total + 1,
                        runs_succeeded = runs_succeeded + CASE WHEN :status = 'success' THEN 1 ELSE 0 END,
                        runs_failed = runs_failed + CASE WHEN :status = 'failure' THEN 1 ELSE 0 END,
                        items_processed = items_processed + :processed,
                        items_succeeded = items_succeeded + :succeeded,
                        items_failed = items_failed + :failed
                    WHERE id = :id
                ");
                $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            } else {
                // Insert new record
                $stmt = $this->db->prepare("
                    INSERT INTO sync_daily_summary (
                        sync_date, sync_type, runs_total, 
                        runs_succeeded, runs_failed,
                        items_processed, items_succeeded, items_failed
                    )
                    VALUES (
                        date('now'), :type, 1,
                        CASE WHEN :status = 'success' THEN 1 ELSE 0 END,
                        CASE WHEN :status = 'failure' THEN 1 ELSE 0 END,
                        :processed, :succeeded, :failed
                    )
                ");
            }
            
            $stmt->bindValue(':type', $syncType, SQLITE3_TEXT);
            $stmt->bindValue(':status', $status, SQLITE3_TEXT);
            $stmt->bindValue(':processed', $counts['items_processed'], SQLITE3_INTEGER);
            $stmt->bindValue(':succeeded', $counts['items_succeeded'], SQLITE3_INTEGER);
            $stmt->bindValue(':failed', $counts['items_failed'], SQLITE3_INTEGER);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to update daily summary: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get statistics for a specific date range and sync type
     * 
     * @param string $syncType Type of sync (product, order, comment, refund, all)
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * @return array Statistics data
     */
    public function getStats(string $syncType = 'all', string $startDate = null, string $endDate = null): array
    {
        $startDate = $startDate ?? date('Y-m-d', strtotime('-7 days'));
        $endDate = $endDate ?? date('Y-m-d');
        
        try {
            $sql = "
                SELECT sync_date, sync_type,
                       runs_total, runs_succeeded, runs_failed,
                       items_processed, items_succeeded, items_failed
                FROM sync_daily_summary
                WHERE sync_date BETWEEN :start AND :end
            ";
            
            if ($syncType !== 'all') {
                $sql .= " AND sync_type = :type";
            }
            
            $sql .= " ORDER BY sync_date DESC, sync_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
            $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
            
            if ($syncType !== 'all') {
                $stmt->bindValue(':type', $syncType, SQLITE3_TEXT);
            }
            
            $result = $stmt->execute();
            
            $stats = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $stats[] = $row;
            }
            
            return $stats;
        } catch (Exception $e) {
            $this->logger->error("Failed to get sync stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get detailed log for a specific sync run
     * 
     * @param int $runId ID of the sync run
     * @return array Log details
     */
    public function getRunDetails(int $runId): array
    {
        try {
            // Get run info
            $stmt = $this->db->prepare("
                SELECT * FROM sync_run WHERE id = :id
            ");
            $stmt->bindValue(':id', $runId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $run = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$run) {
                return [];
            }
            
            // Get details
            $stmt = $this->db->prepare("
                SELECT * FROM sync_detail 
                WHERE sync_run_id = :run_id
                ORDER BY timestamp
            ");
            $stmt->bindValue(':run_id', $runId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $details = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $details[] = $row;
            }
            
            return [
                'run' => $run,
                'details' => $details
            ];
        } catch (Exception $e) {
            $this->logger->error("Failed to get run details: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent sync runs
     * 
     * @param int $limit Number of runs to fetch
     * @return array Recent runs
     */
    public function getRecentRuns(int $limit = 10): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM sync_run
                ORDER BY start_time DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $runs = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $runs[] = $row;
            }
            
            return $runs;
        } catch (Exception $e) {
            $this->logger->error("Failed to get recent runs: " . $e->getMessage());
            return [];
        }
    }
    
    public function close(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
    
    public function __destruct()
    {
        $this->close();
    }
} 