<?php

namespace App\Sync;

use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Core\Database;
use App\Core\WorkerManager;
use App\Logger\Factory as LoggerFactory;
use Exception;

class ProductSync
{
    private PowerBodyLink $powerbody;
    private ShopifyLink $shopify;
    private Database $db;
    private $logger;
    private string $storageDir;
    private bool $useWorkers = true;
    private int $workerCount = 4;

    public function __construct(bool $useWorkers = true, int $workerCount = 4)
    {
        $this->logger = LoggerFactory::getInstance('product-sync');
        $this->powerbody = new PowerBodyLink();
        $this->shopify = new ShopifyLink();
        $this->db = Database::getInstance();
        $this->storageDir = dirname(__DIR__, 2) . '/storage';
        $this->useWorkers = $useWorkers;
        $this->workerCount = max(1, min($workerCount, 16)); // Limit to 1-16 workers
    }

    public function sync(): void
    {
        try {
            $this->logger->info('Starting product sync');
            $startTime = microtime(true);
            
            // 1. Fetch product list from PowerBody
            $powerbodyProducts = $this->powerbody->getProductList();
            
            // Ensure we have an array
            if (!is_array($powerbodyProducts)) {
                $this->logger->error('Expected array from PowerBody API, got ' . gettype($powerbodyProducts));
                if (is_string($powerbodyProducts)) {
                    $this->logger->debug('PowerBody API returned string: ' . substr($powerbodyProducts, 0, 200) . '...');
                    // Try to decode if it's a JSON string
                    $decoded = json_decode($powerbodyProducts, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $powerbodyProducts = $decoded;
                        $this->logger->info('Successfully decoded JSON string from PowerBody API');
                    } else {
                        $this->logger->error('Failed to decode string from PowerBody API: ' . json_last_error_msg());
                        $powerbodyProducts = [];
                    }
                } else {
                    $powerbodyProducts = [];
                }
            }
            
            if (empty($powerbodyProducts)) {
                $this->logger->warning('No products returned from PowerBody API');
                return;
            }
            
            $this->logger->info('Fetched ' . count($powerbodyProducts) . ' products from PowerBody');
            
            // 2. Save snapshot as JSON (async to not block main process)
            $this->saveSnapshotAsync($powerbodyProducts);
            
            // 3. Process products using the appropriate method
            if ($this->useWorkers) {
                $this->processProductsWithWorkers($powerbodyProducts);
            } else {
                $this->processProductsBatchDirect($powerbodyProducts);
            }
            
            // 4. Update sync state
            $this->db->updateSyncState('product');
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            $this->logger->info("Product sync completed successfully in {$duration} seconds");
        } catch (Exception $e) {
            $this->logger->error('Product sync failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process products using parallel workers
     */
    private function processProductsWithWorkers(array $powerbodyProducts): void
    {
        $this->logger->info("Processing products with {$this->workerCount} parallel workers");
        
        // Create worker manager
        $workerManager = new WorkerManager(
            'ProductSyncWorker',
            dirname(__DIR__, 2) . '/bin/worker.php',
            $this->workerCount
        );
        
        // Process products in parallel
        $results = $workerManager->processItems($powerbodyProducts);
        
        // Log results
        $successCount = count($results);
        $totalCount = count($powerbodyProducts);
        $failedCount = $totalCount - $successCount;
        
        $this->logger->info("Parallel processing completed: {$successCount} successes, {$failedCount} failures");
        
        if ($failedCount > 0) {
            $this->logger->warning("Some products failed to sync ({$failedCount} of {$totalCount})");
        }
    }
    
    /**
     * Save snapshot asynchronously to avoid blocking the main process
     */
    private function saveSnapshotAsync(array $products): void
    {
        $filename = $this->storageDir . '/products_' . date('Ymd') . '.json';
        
        // Use a background process for file saving
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            // Could not fork, do it synchronously
            $this->logger->warning('Could not fork process for async snapshot, falling back to sync');
            $jsonData = json_encode($products, JSON_PRETTY_PRINT);
            file_put_contents($filename, $jsonData);
            $this->logger->info('Saved product snapshot to ' . $filename);
        } else if ($pid) {
            // Parent process continues
            $this->logger->debug('Started background process for snapshot saving');
        } else {
            // Child process saves the file
            $jsonData = json_encode($products, JSON_PRETTY_PRINT);
            file_put_contents($filename, $jsonData);
            exit(0);
        }
    }
    
    /**
     * Process product details concurrently using curl_multi
     * @param array $productIds List of product IDs to fetch
     * @param int $concurrency Maximum number of concurrent requests 
     * @return array Product details keyed by product ID
     */
    private function fetchProductDetailsConcurrently(array $productIds, int $concurrency = 5): array
    {
        if (empty($productIds)) {
            return [];
        }

        $this->logger->info('Fetching details for ' . count($productIds) . ' products concurrently (max ' . $concurrency . ' connections)');
        
        $results = [];
        $cacheHits = 0;
        $apiCalls = 0;
        
        // First check cache for all products
        foreach ($productIds as $i => $productId) {
            // Try to load from cache first
            $cacheFile = dirname(__DIR__, 2) . '/storage/cache/products/product_' . $productId . '.json';
            
            if (file_exists($cacheFile)) {
                $fileTime = filemtime($cacheFile);
                $now = time();
                
                // Check if file is still valid (not expired)
                if (($now - $fileTime) <= $this->powerbody->getCacheExpiry()) {
                    $content = file_get_contents($cacheFile);
                    $data = json_decode($content, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && isset($data['product_data'])) {
                        $results[$productId] = $data['product_data'];
                        $cacheHits++;
                        unset($productIds[$i]); // Remove from list that needs API call
                    }
                }
            }
        }
        
        // Reset array keys
        $productIds = array_values($productIds);
        
        $this->logger->info("Found {$cacheHits} products in cache, need to fetch " . count($productIds) . " from API");
        
        if (empty($productIds)) {
            return $results;
        }
        
        // Fall back to using standard SOAP client as it handles redirects properly
        foreach ($productIds as $productId) {
            try {
                $details = $this->powerbody->getProductInfo($productId);
                if ($details) {
                    $results[$productId] = $details;
                    $apiCalls++;
                }
                
                // Small delay between requests to avoid overwhelming the API
                usleep(200000); // 200ms
            } catch (Exception $e) {
                $this->logger->warning("Failed to fetch product details: " . $e->getMessage(), [
                    'product_id' => $productId
                ]);
            }
        }
        
        $this->logger->info("Completed fetching: {$cacheHits} from cache, {$apiCalls} from API");
        
        return $results;
    }
    
    /**
     * Build a SOAP request for the PowerBody API
     */
    private function buildSoapRequest(string $session, string $method, $params): string
    {
        $paramsXml = is_array($params) ? json_encode($params) : $params;
        
        $request = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="' . $this->powerbody->getApiUrl() . '">
  <SOAP-ENV:Body>
    <ns1:call>
      <sessionId>' . $session . '</sessionId>
      <method>' . $method . '</method>
      <params>' . $paramsXml . '</params>
    </ns1:call>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        return $request;
    }
    
    /**
     * Parse SOAP response to extract product information
     */
    private function parseSoapResponse(string $response)
    {
        // Extract response from SOAP XML
        if (preg_match('/<return[^>]*>(.*?)<\/return>/s', $response, $matches)) {
            $result = $matches[1];
            
            // If it's a CDATA section, extract the content
            if (strpos($result, '<![CDATA[') !== false && preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $result, $cdataMatches)) {
                $result = $cdataMatches[1];
            }
            
            // If it's JSON, decode it
            if ($this->isJson($result)) {
                return json_decode($result, true);
            }
            
            return $result;
        }
        
        return null;
    }
    
    /**
     * Check if a string is valid JSON
     */
    private function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Process products in batches and sync directly to Shopify after each batch
     * This ensures we don't accumulate too many products in memory and provides faster syncing
     */
    private function processProductsBatchDirect(array $powerbodyProducts): void
    {
        $this->logger->info('Processing products in batches with direct Shopify sync');
        $startTime = microtime(true);
        
        // 1. Fetch existing products from Shopify (paginated with caching)
        $existingProducts = $this->getExistingShopifyProducts();
        $this->logger->info('Fetched ' . count($existingProducts) . ' existing products from Shopify in ' . 
                          round(microtime(true) - $startTime, 2) . ' seconds');
        
        // 2. Create lookup maps for faster processing
        $skuToExistingProduct = [];
        $idToExistingProduct = [];
        
        foreach ($existingProducts as $product) {
            $idToExistingProduct[$product['id']] = $product;
            
            if (!empty($product['variants'])) {
                foreach ($product['variants'] as $variant) {
                    if (!empty($variant['sku'])) {
                        $skuToExistingProduct[$variant['sku']] = [
                            'product' => $product,
                            'variant' => $variant
                        ];
                    }
                }
            }
        }
        
        // 3. Process in batches of 50 products
        $batches = array_chunk($powerbodyProducts, 50);
        $batchCount = count($batches);
        $locationId = $this->shopify->getLocationId();
        
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalArchived = 0;
        $totalInventoryUpdates = 0;
        
        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info("Processing batch {$batchIndex}/{$batchCount} with " . count($batch) . " products");
            $batchStartTime = microtime(true);
            
            // Prepare data for this batch
            $productsToCreate = [];
            $productsToUpdate = [];
            $productsToArchive = [];
            $inventoryUpdates = [];
            $productsNeedingDetails = [];
            
            // Identify products needing details
            foreach ($batch as $product) {
                if (empty($product['status']) || empty($product['manufacturer'])) {
                    $productsNeedingDetails[] = $product['product_id'];
                }
            }
            
            // Fetch details for this batch using concurrent fetching
            $productDetails = [];
            if (!empty($productsNeedingDetails)) {
                $detailsFetchStart = microtime(true);
                $productDetails = $this->fetchProductDetailsConcurrently($productsNeedingDetails, 10);
                $detailsFetchTime = round(microtime(true) - $detailsFetchStart, 2);
                $this->logger->info("Fetched " . count($productDetails) . " product details concurrently in {$detailsFetchTime}s");
            }
            
            // Process each product in the batch
            foreach ($batch as $pbProduct) {
                // Merge additional details if available
                if (isset($productDetails[$pbProduct['product_id']])) {
                    $pbProduct = array_merge($pbProduct, $productDetails[$pbProduct['product_id']]);
                }
                
                // Skip products that should be archived
                if (isset($pbProduct['status']) && in_array($pbProduct['status'], ['disabled', 'archival'])) {
                    if (isset($skuToExistingProduct[$pbProduct['sku']])) {
                        $productsToArchive[] = $skuToExistingProduct[$pbProduct['sku']]['product']['id'];
                    }
                    continue;
                }
                
                // Map to Shopify format
                $shopifyProduct = $this->mapToShopifyProduct($pbProduct);
                
                // Store PowerBody ID for reference
                $shopifyProduct['powerbody_id'] = $pbProduct['product_id'] ?? null;
                
                // Decide whether to create, update, or skip
                if (isset($skuToExistingProduct[$pbProduct['sku']])) {
                    // Update existing product
                    $existingProduct = $skuToExistingProduct[$pbProduct['sku']]['product'];
                    $shopifyProduct['id'] = $existingProduct['id'];
                    
                    // Clean up the product data to avoid metafield conflicts
                    unset($shopifyProduct['options']);
                    
                    // For variants, only update price and inventory, not option values
                    if (isset($shopifyProduct['variants']) && is_array($shopifyProduct['variants'])) {
                        foreach ($shopifyProduct['variants'] as $i => $variant) {
                            $existingVariant = null;
                            foreach ($existingProduct['variants'] as $ev) {
                                if (isset($ev['sku']) && $ev['sku'] === $pbProduct['sku']) {
                                    $existingVariant = $ev;
                                    break;
                                }
                            }
                            
                            if ($existingVariant) {
                                // Keep only inventory and price data
                                $cleanVariant = [
                                    'id' => $existingVariant['id'],
                                    'price' => $variant['price'] ?? null,
                                    'inventory_management' => $variant['inventory_management'] ?? null
                                ];
                                
                                // Remove null values
                                $cleanVariant = array_filter($cleanVariant, function($value) {
                                    return $value !== null;
                                });
                                
                                $shopifyProduct['variants'][$i] = $cleanVariant;
                                
                                // Add inventory update for this variant
                                if (isset($existingVariant['inventory_item_id']) && isset($pbProduct['qty'])) {
                                    $inventoryUpdates[] = [
                                        'inventory_item_id' => $existingVariant['inventory_item_id'],
                                        'location_id' => $locationId,
                                        'available' => (int)$pbProduct['qty']
                                    ];
                                }
                            }
                        }
                    }
                    
                    $productsToUpdate[] = $shopifyProduct;
                } else {
                    // Create new product
                    $productsToCreate[] = $shopifyProduct;
                }
            }
            
            // Now process each operation for this batch
            
            // Create products
            if (!empty($productsToCreate)) {
                $this->logger->info("Creating " . count($productsToCreate) . " products in Shopify");
                $createdCount = 0;
                
                try {
                    $createdProducts = $this->shopify->createProductsBatch($productsToCreate);
                    $createdCount = count($createdProducts);
                    $totalCreated += $createdCount;
                    
                    // Save mappings
                    foreach ($createdProducts as $product) {
                        if (!empty($product['variants'][0]['sku'])) {
                            $sku = $product['variants'][0]['sku'];
                            
                            foreach ($productsToCreate as $pbProduct) {
                                if (!empty($pbProduct['variants'][0]['sku']) && 
                                    $pbProduct['variants'][0]['sku'] === $sku) {
                                    
                                    $powerbodyProductId = $pbProduct['powerbody_id'] ?? null;
                                    
                                    if ($powerbodyProductId) {
                                        $this->db->saveProductMapping(
                                            $product['id'],
                                            $powerbodyProductId,
                                            $sku
                                        );
                                    }
                                    
                                    break;
                                }
                            }
                        }
                    }
                    
                    $this->logger->info("Created {$createdCount} products in Shopify");
                } catch (Exception $e) {
                    $this->logger->error("Failed to create products: " . $e->getMessage());
                    $this->saveDeadLetter('create', $productsToCreate);
                }
            }
            
            // Update products
            if (!empty($productsToUpdate)) {
                $this->logger->info("Updating " . count($productsToUpdate) . " products in Shopify");
                $updatedCount = 0;
                
                try {
                    $updatedProducts = $this->shopify->bulkUpdateProducts($productsToUpdate);
                    $updatedCount = count($updatedProducts);
                    $totalUpdated += $updatedCount;
                    
                    $this->logger->info("Updated {$updatedCount} products in Shopify");
                } catch (Exception $e) {
                    $this->logger->error("Failed to update products: " . $e->getMessage());
                    $this->saveDeadLetter('update', $productsToUpdate);
                }
            }
            
            // Archive products
            if (!empty($productsToArchive)) {
                $this->logger->info("Archiving " . count($productsToArchive) . " products in Shopify");
                $archivedCount = 0;
                
                try {
                    $archiveData = [];
                    foreach ($productsToArchive as $productId) {
                        $archiveData[] = [
                            'id' => $productId,
                            'status' => 'archived'
                        ];
                    }
                    
                    $archivedProducts = $this->shopify->bulkUpdateProducts($archiveData);
                    $archivedCount = count($archivedProducts);
                    $totalArchived += $archivedCount;
                    
                    $this->logger->info("Archived {$archivedCount} products in Shopify");
                } catch (Exception $e) {
                    $this->logger->error("Failed to archive products: " . $e->getMessage());
                }
            }
            
            // Update inventory
            if (!empty($inventoryUpdates)) {
                $this->logger->info("Updating inventory for " . count($inventoryUpdates) . " items in Shopify");
                $inventoryCount = 0;
                
                try {
                    $updatedInventory = $this->shopify->bulkUpdateInventory($inventoryUpdates);
                    $inventoryCount = count($updatedInventory);
                    $totalInventoryUpdates += $inventoryCount;
                    
                    $this->logger->info("Updated inventory for {$inventoryCount} items in Shopify");
                } catch (Exception $e) {
                    $this->logger->error("Failed to update inventory: " . $e->getMessage());
                }
            }
            
            $batchTime = round(microtime(true) - $batchStartTime, 2);
            $this->logger->info("Completed batch {$batchIndex}/{$batchCount} in {$batchTime} seconds");
            
            // Sleep a bit between batches to avoid overwhelming the Shopify API
            if ($batchIndex < $batchCount - 1) {
                sleep(1);
            }
        }
        
        $totalTime = round(microtime(true) - $startTime, 2);
        $this->logger->info("Total processing time: {$totalTime} seconds");
        $this->logger->info("Summary: Created {$totalCreated}, Updated {$totalUpdated}, Archived {$totalArchived}, Inventory updates {$totalInventoryUpdates}");
        
        // Preload next batch of product details to warm up cache
        if (!empty($powerbodyProducts)) {
            $processedIds = array_column($powerbodyProducts, 'product_id');
            $this->preloadNextProductBatch($powerbodyProducts, $processedIds); 
        }
    }

    private function getExistingShopifyProducts(): array
    {
        $allProducts = [];
        $limit = 250; // Shopify max limit
        $params = ['limit' => $limit];
        
        do {
            $this->logger->debug('Fetching products from Shopify with params: ' . json_encode($params));
            $products = $this->shopify->getProducts($params);
            $allProducts = array_merge($allProducts, $products);
            
            // Get link header for pagination
            $nextPageUrl = $this->shopify->getNextPageUrl();
            if ($nextPageUrl) {
                // Extract the page_info parameter from the next URL
                parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $queryParams);
                $params = ['limit' => $limit];
                if (isset($queryParams['page_info'])) {
                    $params['page_info'] = $queryParams['page_info'];
                }
            } else {
                $params = null;
            }
            
        } while ($params !== null);
        
        return $allProducts;
    }

    private function mapToShopifyProduct(array $pbProduct): array
    {
        $title = $pbProduct['name'] ?? 'Unknown Product';
        $vendor = $pbProduct['manufacturer'] ?? 'Powerbody';
        $sku = $pbProduct['sku'] ?? '';
        $price = $pbProduct['price_tax'] ?? $pbProduct['price'] ?? 0;
        $status = 'active';
        
        if (isset($pbProduct['status'])) {
            if ($pbProduct['status'] === 'out of stock') {
                // Product is out of stock but still available
                $status = 'active';
            } elseif (in_array($pbProduct['status'], ['disabled', 'archival'])) {
                $status = 'archived';
            }
        }
        
        // Prepare product data
        $product = [
            'title' => $title,
            'vendor' => $vendor,
            'status' => $status,
            'variants' => [
                [
                    'sku' => $sku,
                    'price' => $price,
                    'inventory_management' => 'shopify'
                ]
            ]
        ];
        
        // Add product image if available
        if (!empty($pbProduct['image'])) {
            $product['images'] = [
                ['src' => $pbProduct['image']]
            ];
        }
        
        // Add product description if available
        if (!empty($pbProduct['description_en'])) {
            $product['body_html'] = $pbProduct['description_en'];
        }
        
        return $product;
    }

    private function updateInventory(int $inventoryItemId, int $quantity): void
    {
        try {
            $locationId = $this->shopify->getLocationId();
            
            // Check if locationId is valid
            if (empty($locationId) || $locationId <= 0) {
                $this->logger->error('Invalid location ID for inventory update', [
                    'location_id' => $locationId
                ]);
                return;
            }
            
            $this->shopify->updateInventoryLevel($inventoryItemId, $locationId, $quantity);
            $this->logger->debug('Updated inventory', [
                'inventory_item_id' => $inventoryItemId,
                'location_id' => $locationId,
                'quantity' => $quantity
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update inventory: ' . $e->getMessage(), [
                'inventory_item_id' => $inventoryItemId
            ]);
        }
    }

    private function saveDeadLetter(string $action, array $products): void
    {
        $filename = $this->storageDir . '/dead_letter_' . $action . '_products_' . date('YmdHis') . '.json';
        file_put_contents($filename, json_encode($products, JSON_PRETTY_PRINT));
        $this->logger->warning('Saved failed products to dead letter file: ' . $filename);
    }

    /**
     * Preload the next batch of products to warm up the cache
     */
    private function preloadNextProductBatch(array $allProducts, array $alreadyLoaded, int $limit = 100): void
    {
        $productsToPreload = [];
        $preloadCount = 0;
        
        // Find products not already loaded
        foreach ($allProducts as $product) {
            if (isset($product['product_id']) && !in_array($product['product_id'], $alreadyLoaded)) {
                $productsToPreload[] = $product['product_id'];
                $preloadCount++;
                
                if ($preloadCount >= $limit) {
                    break;
                }
            }
        }
        
        if (empty($productsToPreload)) {
            return;
        }
        
        $this->logger->info("Preloading {$preloadCount} products in background for next sync");
        
        // Use a background process for cache warming
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            $this->logger->warning('Could not fork process for cache preloading');
        } else if ($pid) {
            // Parent process continues
            $this->logger->debug('Started background process for cache preloading');
        } else {
            // Child process preloads products
            try {
                foreach ($productsToPreload as $index => $productId) {
                    try {
                        $this->powerbody->refreshProductCache($productId);
                        
                        // Add delay to avoid overwhelming the API
                        if ($index % 5 === 0 && $index > 0) {
                            sleep(1);
                        } else {
                            usleep(200000); // 200ms between requests
                        }
                    } catch (Exception $e) {
                        // Just log and continue with next product
                    }
                }
            } catch (Exception $e) {
                // Ignore exceptions in background process
            }
            exit(0);
        }
    }
} 