<?php

namespace App\Core;

use App\Logger\Factory as LoggerFactory;
use SQLite3;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private SQLite3 $db;
    private $logger;

    private function __construct()
    {
        $this->logger = LoggerFactory::getInstance('database');
        $dbPath = dirname(__DIR__, 2) . '/storage/sync.db';
        
        try {
            $this->db = new SQLite3($dbPath);
            $this->db->enableExceptions(true);
            
            // Create necessary tables if they don't exist
            $this->initTables();
            
            $this->logger->info('Database connection established');
        } catch (Exception $e) {
            $this->logger->error('Database connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function initTables(): void
    {
        // Orders mapping table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS order_mapping (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                shopify_order_id INTEGER NOT NULL,
                powerbody_order_id VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(shopify_order_id, powerbody_order_id)
            )
        ");
        
        // Products mapping table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS product_mapping (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                shopify_product_id INTEGER NOT NULL,
                powerbody_product_id INTEGER NOT NULL,
                sku VARCHAR(255) NOT NULL,
                last_synced DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(shopify_product_id, powerbody_product_id)
            )
        ");
        
        // Sync state table (for keeping track of last sync timestamps)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sync_state (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_type VARCHAR(50) NOT NULL,
                last_sync DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(sync_type)
            )
        ");
        
        // Initialize sync state records if they don't exist
        $types = ['product', 'order', 'comment', 'refund'];
        foreach ($types as $type) {
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO sync_state (sync_type, last_sync)
                VALUES (:type, datetime('now', '-1 day'))
            ");
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    public function close(): void
    {
        if (isset($this->db)) {
            $this->db->close();
            $this->logger->info('Database connection closed');
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // Order mapping methods
    
    public function saveOrderMapping(int $shopifyOrderId, string $powerbodyOrderId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO order_mapping (shopify_order_id, powerbody_order_id)
                VALUES (:shopify_id, :powerbody_id)
            ");
            $stmt->bindValue(':shopify_id', $shopifyOrderId, SQLITE3_INTEGER);
            $stmt->bindValue(':powerbody_id', $powerbodyOrderId, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            $this->logger->info("Saved order mapping", [
                'shopify_order_id' => $shopifyOrderId,
                'powerbody_order_id' => $powerbodyOrderId
            ]);
            
            return $result !== false;
        } catch (Exception $e) {
            $this->logger->error("Failed to save order mapping: " . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrderId,
                'powerbody_order_id' => $powerbodyOrderId
            ]);
            return false;
        }
    }

    public function getShopifyOrderId(string $powerbodyOrderId): ?int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT shopify_order_id FROM order_mapping
                WHERE powerbody_order_id = :powerbody_id
                LIMIT 1
            ");
            $stmt->bindValue(':powerbody_id', $powerbodyOrderId, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                return (int) $row['shopify_order_id'];
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error("Failed to get Shopify order ID: " . $e->getMessage(), [
                'powerbody_order_id' => $powerbodyOrderId
            ]);
            return null;
        }
    }

    public function getPowerbodyOrderId(int $shopifyOrderId): ?string
    {
        try {
            $stmt = $this->db->prepare("
                SELECT powerbody_order_id FROM order_mapping
                WHERE shopify_order_id = :shopify_id
                LIMIT 1
            ");
            $stmt->bindValue(':shopify_id', $shopifyOrderId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                return $row['powerbody_order_id'];
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error("Failed to get PowerBody order ID: " . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrderId
            ]);
            return null;
        }
    }

    // Product mapping methods
    
    public function saveProductMapping(int $shopifyProductId, int $powerbodyProductId, string $sku): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO product_mapping 
                (shopify_product_id, powerbody_product_id, sku, last_synced)
                VALUES (:shopify_id, :powerbody_id, :sku, CURRENT_TIMESTAMP)
            ");
            $stmt->bindValue(':shopify_id', $shopifyProductId, SQLITE3_INTEGER);
            $stmt->bindValue(':powerbody_id', $powerbodyProductId, SQLITE3_INTEGER);
            $stmt->bindValue(':sku', $sku, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            $this->logger->info("Saved product mapping", [
                'shopify_product_id' => $shopifyProductId,
                'powerbody_product_id' => $powerbodyProductId,
                'sku' => $sku
            ]);
            
            return $result !== false;
        } catch (Exception $e) {
            $this->logger->error("Failed to save product mapping: " . $e->getMessage(), [
                'shopify_product_id' => $shopifyProductId,
                'powerbody_product_id' => $powerbodyProductId
            ]);
            return false;
        }
    }

    public function getShopifyProductId(int $powerbodyProductId): ?int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT shopify_product_id FROM product_mapping
                WHERE powerbody_product_id = :powerbody_id
                LIMIT 1
            ");
            $stmt->bindValue(':powerbody_id', $powerbodyProductId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                return (int) $row['shopify_product_id'];
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error("Failed to get Shopify product ID: " . $e->getMessage(), [
                'powerbody_product_id' => $powerbodyProductId
            ]);
            return null;
        }
    }

    public function getProductBysku(string $sku): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM product_mapping
                WHERE sku = :sku
                LIMIT 1
            ");
            $stmt->bindValue(':sku', $sku, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                return $row;
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error("Failed to get product by SKU: " . $e->getMessage(), [
                'sku' => $sku
            ]);
            return null;
        }
    }

    // Sync state methods
    
    public function updateSyncState(string $syncType): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE sync_state
                SET last_sync = CURRENT_TIMESTAMP
                WHERE sync_type = :type
            ");
            $stmt->bindValue(':type', $syncType, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            $this->logger->info("Updated sync state", ['sync_type' => $syncType]);
            
            return $result !== false;
        } catch (Exception $e) {
            $this->logger->error("Failed to update sync state: " . $e->getMessage(), [
                'sync_type' => $syncType
            ]);
            return false;
        }
    }

    public function getLastSyncTime(string $syncType): ?string
    {
        try {
            $stmt = $this->db->prepare("
                SELECT last_sync FROM sync_state
                WHERE sync_type = :type
                LIMIT 1
            ");
            $stmt->bindValue(':type', $syncType, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                return $row['last_sync'];
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error("Failed to get last sync time: " . $e->getMessage(), [
                'sync_type' => $syncType
            ]);
            return null;
        }
    }
} 