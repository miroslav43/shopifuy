<?php

namespace App\Sync;

use App\Core\Worker;
use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Core\Database;
use Exception;

class ProductSyncWorker extends Worker
{
    private PowerBodyLink $powerbody;
    private ShopifyLink $shopify;
    private Database $db;
    private array $failedItems = [];
    private array $successItems = [];
    private array $existingProducts = [];
    
    public function __construct(int $workerId)
    {
        parent::__construct($workerId);
        
        // Initialize API connections
        $this->powerbody = new PowerBodyLink();
        $this->shopify = new ShopifyLink();
        $this->db = Database::getInstance();
        
        // Lower the update interval for more frequent logging
        $this->updateIntervalSeconds = 5;
    }
    
    /**
     * Run the worker on the provided products
     */
    public function run(array $products): array
    {
        $this->initialize($products);
        $this->logger->info("Starting product sync worker #{$this->workerId} with " . count($products) . " products");
        
        // Preload existing products to avoid API calls for each item
        $this->preloadExistingProducts();
        
        foreach ($products as $product) {
            if ($this->shouldStop) {
                $this->logger->info("Worker #{$this->workerId} stopping as requested");
                break;
            }
            
            try {
                $result = $this->processItem($product);
                if ($result) {
                    $this->successItems[] = $product;
                } else {
                    $this->failedItems[] = $product;
                }
            } catch (Exception $e) {
                $this->logger->error("Error processing product: " . $e->getMessage(), [
                    'product_id' => $product['product_id'] ?? 'unknown'
                ]);
                $this->failedItems[] = $product;
            }
            
            $this->processedItems++;
            $this->logProgressIfNeeded();
            
            // Write progress to result file for monitoring
            $this->writeProgressToFile();
            
            // Small delay to prevent API rate limiting
            usleep(100000); // 100ms
        }
        
        $this->logger->info("Worker #{$this->workerId} completed processing. Success: " . 
            count($this->successItems) . ", Failed: " . count($this->failedItems));
        
        return [
            'success' => $this->successItems,
            'failed' => $this->failedItems
        ];
    }
    
    /**
     * Process a single product
     */
    protected function processItem($product): bool
    {
        if (!isset($product['product_id'])) {
            $this->logger->warning("Skipping product without ID");
            return false;
        }
        
        $productId = $product['product_id'];
        
        // 1. Get detailed product info from PowerBody or cache
        try {
            $productDetails = $this->powerbody->getProductInfo($productId);
            
            if (empty($productDetails)) {
                $this->logger->warning("Empty product details returned for ID {$productId}");
                return false;
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to get product details for ID {$productId}: " . $e->getMessage());
            return false;
        }
        
        // 2. Map PowerBody product to Shopify format
        $shopifyProduct = $this->mapToShopifyProduct($productDetails);
        
        // 3. Check if product already exists in Shopify
        $existingShopifyId = $this->findExistingShopifyProduct($productDetails);
        
        // 4. Create or update in Shopify
        try {
            if ($existingShopifyId) {
                // Update existing product
                $this->logger->debug("Updating existing Shopify product ID {$existingShopifyId} for PowerBody product {$productId}");
                $shopifyProduct['id'] = $existingShopifyId;
                $result = $this->shopify->updateProduct($existingShopifyId, $shopifyProduct);
            } else {
                // Create new product
                $this->logger->debug("Creating new Shopify product for PowerBody product {$productId}");
                $result = $this->shopify->createProduct($shopifyProduct);
            }
            
            if (empty($result)) {
                $this->logger->warning("Empty result from Shopify API for product {$productId}");
                return false;
            }
            
            // 5. Save mapping to database
            $shopifyProductId = $result['id'];
            $sku = $productDetails['sku'] ?? '';
            
            $this->db->saveProductMapping($shopifyProductId, $productId, $sku);
            
            // 6. Update inventory if needed
            if (isset($result['variants']) && !empty($result['variants'])) {
                $variant = $result['variants'][0];
                $inventoryItemId = $variant['inventory_item_id'];
                $quantity = $productDetails['quantity'] ?? 0;
                
                $this->updateInventory($inventoryItemId, (int)$quantity);
            }
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to sync product {$productId} to Shopify: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Preload existing products from Shopify to reduce API calls
     */
    private function preloadExistingProducts(): void
    {
        $this->logger->info("Worker #{$this->workerId} preloading existing Shopify products");
        
        try {
            $page = 1;
            $limit = 250; // Maximum allowed by Shopify
            $this->existingProducts = [];
            
            do {
                $params = [
                    'limit' => $limit,
                    'page' => $page,
                    'fields' => 'id,variants,title,handle'
                ];
                
                $products = $this->shopify->getProducts($params);
                
                if (!empty($products)) {
                    foreach ($products as $product) {
                        if (isset($product['variants']) && !empty($product['variants'])) {
                            foreach ($product['variants'] as $variant) {
                                if (isset($variant['sku']) && !empty($variant['sku'])) {
                                    $this->existingProducts[$variant['sku']] = [
                                        'id' => $product['id'],
                                        'inventory_item_id' => $variant['inventory_item_id'] ?? 0
                                    ];
                                }
                            }
                        }
                    }
                }
                
                $page++;
                
                // Small delay to prevent API rate limiting
                usleep(500000); // 500ms
                
            } while (!empty($products) && count($products) >= $limit);
            
            $this->logger->info("Worker #{$this->workerId} preloaded " . count($this->existingProducts) . " products from Shopify");
        } catch (Exception $e) {
            $this->logger->error("Failed to preload existing products: " . $e->getMessage());
        }
    }
    
    /**
     * Find existing Shopify product ID for a PowerBody product
     */
    private function findExistingShopifyProduct(array $product): ?int
    {
        if (!isset($product['sku']) || empty($product['sku'])) {
            return null;
        }
        
        $sku = $product['sku'];
        
        // First check our preloaded products
        if (isset($this->existingProducts[$sku])) {
            return $this->existingProducts[$sku]['id'];
        }
        
        // Then check database
        $shopifyId = $this->db->getShopifyProductId($product['product_id']);
        
        return $shopifyId;
    }
    
    /**
     * Map PowerBody product to Shopify format
     */
    private function mapToShopifyProduct(array $pbProduct): array
    {
        $name = $pbProduct['name'] ?? '';
        $description = $pbProduct['description'] ?? '';
        $sku = $pbProduct['sku'] ?? '';
        $price = $pbProduct['price'] ?? 0;
        $imageUrl = $pbProduct['image_url'] ?? '';
        
        $shopifyProduct = [
            'title' => $name,
            'body_html' => $description,
            'vendor' => 'PowerBody',
            'product_type' => $pbProduct['category'] ?? 'Supplement',
            'tags' => 'powerbody, import',
            'variants' => [
                [
                    'price' => $price,
                    'sku' => $sku,
                    'inventory_management' => 'shopify',
                    'inventory_policy' => 'deny',
                    'weight' => $pbProduct['weight'] ?? 0,
                    'weight_unit' => 'g',
                    'requires_shipping' => true
                ]
            ]
        ];
        
        // Add images if available
        if (!empty($imageUrl)) {
            $shopifyProduct['images'] = [
                [
                    'src' => $imageUrl
                ]
            ];
        }
        
        return $shopifyProduct;
    }
    
    /**
     * Update inventory level for a product
     */
    private function updateInventory(int $inventoryItemId, int $quantity): void
    {
        if ($inventoryItemId <= 0) {
            $this->logger->warning("Invalid inventory item ID: {$inventoryItemId}");
            return;
        }
        
        try {
            $locationId = $this->shopify->getLocationId();
            $result = $this->shopify->updateInventoryLevel($inventoryItemId, $locationId, $quantity);
            
            if (empty($result)) {
                $this->logger->warning("Empty result from inventory update for item {$inventoryItemId}");
            } else {
                $this->logger->debug("Updated inventory for item {$inventoryItemId} to {$quantity}");
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to update inventory for item {$inventoryItemId}: " . $e->getMessage());
        }
    }
    
    /**
     * Write progress information to a file for the manager to monitor
     */
    private function writeProgressToFile(): void
    {
        static $resultFile = null;
        
        if ($resultFile === null) {
            $args = $_SERVER['argv'] ?? [];
            if (count($args) >= 5) {
                $resultFile = $args[4];
            } else {
                return;
            }
        }
        
        $data = [
            'progress' => $this->getStatus(),
            'data' => $this->successItems
        ];
        
        file_put_contents($resultFile, json_encode($data));
    }
} 