<?php

namespace App\Sync;

use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Core\Database;
use App\Logger\Factory as LoggerFactory;
use Exception;

class ProductSync
{
    private PowerBodyLink $powerbody;
    private ShopifyLink $shopify;
    private Database $db;
    private $logger;
    private string $storageDir;
    private bool $debug;
    private bool $skipDraft;

    public function __construct(bool $debug = false, bool $skipDraft = false)
    {
        $this->logger = LoggerFactory::getInstance('product-sync');
        $this->powerbody = new PowerBodyLink();
        $this->shopify = new ShopifyLink();
        $this->db = Database::getInstance();
        $this->storageDir = dirname(__DIR__, 2) . '/storage';
        $this->debug = $debug;
        $this->skipDraft = $skipDraft;
        
        if ($this->debug) {
            $this->logger->info("Debug mode enabled - saving detailed product info");
        }
        
        // Log that zero inventory products are never pushed to Shopify
        $this->logger->info("Zero inventory verification enabled - products with 0 inventory will not be pushed to Shopify");
        
        if ($this->skipDraft) {
            $this->logger->info("Skip draft mode enabled (legacy) - products with zero inventory will be skipped");
        }
    }

    public function sync(int $startBatchIndex = 0): void
    {
        try {
            $this->logger->info('Starting product sync' . ($startBatchIndex > 0 ? ' from batch ' . $startBatchIndex : ''));
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
            
            // 3. Process products in batches with direct sync to Shopify, starting from specified batch
            $this->processProductsBatchDirect($powerbodyProducts, $startBatchIndex);
            
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
     * Save snapshot synchronously
     */
    private function saveSnapshotAsync(array $products): void
    {
        $filename = $this->storageDir . '/products_' . date('Ymd') . '.json';
        
        // Save file synchronously since we can't use fork on Windows
        $this->logger->info('Saving product snapshot to ' . $filename);
        $jsonData = json_encode($products, JSON_PRETTY_PRINT);
        file_put_contents($filename, $jsonData);
        $this->logger->info('Saved product snapshot to ' . $filename);
    }
    
    /**
     * Fetch product details sequentially from cache or API
     * @param array $productIds List of product IDs to fetch
     * @param int $batchSize Size of batches to process
     * @return array Product details keyed by product ID
     */
    private function fetchProductDetailsConcurrently(array $productIds, int $batchSize = 5): array
    {
        if (empty($productIds)) {
            return [];
        }

        $this->logger->info('Fetching details for ' . count($productIds) . ' products in batches');
        
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
        
        // Fetch remaining products sequentially in small batches
        $batches = array_chunk($productIds, $batchSize);
        foreach ($batches as $batchIndex => $batch) {
            $this->logger->debug("Processing batch {$batchIndex} with " . count($batch) . " items");
            
            foreach ($batch as $productId) {
                try {
                    $details = $this->powerbody->getProductInfo($productId);
                    if ($details) {
                        $results[$productId] = $details;
                        $apiCalls++;
                        
                        // Save detailed product info to JSON for debugging
                        $sku = $details['sku'] ?? $productId;
                        $this->saveProductDebugData($details, $sku);
                    }
                    
                    // Small delay between requests to avoid overwhelming the API
                    usleep(200000); // 200ms
                } catch (Exception $e) {
                    $this->logger->warning("Failed to fetch product details: " . $e->getMessage(), [
                        'product_id' => $productId
                    ]);
                }
            }
            
            // Small delay between batches
            if (count($batches) > 1 && $batchIndex < count($batches) - 1) {
                sleep(1);
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
     * 
     * @param array $powerbodyProducts Array of products from PowerBody
     * @param int $startBatchIndex The batch index to start processing from (0-based)
     */
    private function processProductsBatchDirect(array $powerbodyProducts, int $startBatchIndex = 0): void
    {
        $this->logger->info('Processing products in batches with direct Shopify sync' . 
                          ($startBatchIndex > 0 ? ', starting from batch ' . $startBatchIndex : ''));
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
        
        // 3. Process in batches of 50 products (larger batch for more efficient processing)
        $batches = array_chunk($powerbodyProducts, 50);
        $batchCount = count($batches);
        $locationId = $this->shopify->getLocationId();
        
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalArchived = 0;
        $totalInventoryUpdates = 0;
        
        foreach ($batches as $batchIndex => $batch) {
            // Skip batches before the start index
            if ($batchIndex < $startBatchIndex) {
                $this->logger->info("Skipping batch {$batchIndex}/{$batchCount} as requested to start from batch {$startBatchIndex}");
                continue;
            }
            
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
                $productDetails = $this->fetchProductDetailsConcurrently($productsNeedingDetails, 5); // Reduced concurrency
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
                
                // Always verify inventory before pushing to Shopify
                $quantity = isset($pbProduct['qty']) ? (int)$pbProduct['qty'] : 0;
                
                // For new products, skip pushing to Shopify if inventory is zero
                if ($quantity <= 0 && !isset($skuToExistingProduct[$pbProduct['sku']])) {
                    $this->logger->info("INVENTORY CHECK: Skipping product '{$pbProduct['name']}' (SKU: {$pbProduct['sku']}) - zero inventory product will not be pushed to Shopify");
                    continue;
                }
                
                // Legacy support for skip-draft flag (redundant now but kept for backward compatibility)
                if ($this->skipDraft && $quantity <= 0 && !isset($skuToExistingProduct[$pbProduct['sku']])) {
                    $this->logger->debug("Skip-draft flag used: Skipping product '{$pbProduct['name']}' (SKU: {$pbProduct['sku']}) due to 0 quantity and skip-draft flag");
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
                                    
                                    // If quantity is 0, always update product status to draft
                                    if ((int)$pbProduct['qty'] <= 0) {
                                        $shopifyProduct['status'] = 'draft';
                                        $this->logger->info("INVENTORY CHECK: Setting product '{$shopifyProduct['title']}' (ID: {$existingProduct['id']}) to draft status due to 0 quantity");
                                    }
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
                    // Process in smaller groups (max 10 at a time) to avoid rate limits
                    $createGroups = array_chunk($productsToCreate, 10);
                    foreach ($createGroups as $group) {
                        $createdProducts = $this->shopify->createProductsBatch($group);
                        $createdCount += count($createdProducts);
                        
                        // Initialize inventory for newly created products
                        $newInventoryUpdates = [];
                        $locationId = $this->shopify->getLocationId();
                        
                        foreach ($createdProducts as $createdProduct) {
                            // Find the original PowerBody product data to get quantity
                            $pbProductData = null;
                            $createdSku = null;
                            
                            if (!empty($createdProduct['variants'][0]['sku'])) {
                                $createdSku = $createdProduct['variants'][0]['sku'];
                                
                                // Find matching PowerBody product from original group
                                foreach ($group as $pbProduct) {
                                    if (!empty($pbProduct['variants'][0]['sku']) && 
                                        $pbProduct['variants'][0]['sku'] === $createdSku) {
                                        
                                        // Find the original product data with inventory
                                        foreach ($batch as $originalPb) {
                                            if (isset($originalPb['sku']) && $originalPb['sku'] === $createdSku) {
                                                $pbProductData = $originalPb;
                                                break;
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                            
                            // Set inventory if product data was found and it has inventory
                            if ($pbProductData && 
                                isset($pbProductData['qty']) && 
                                (int)$pbProductData['qty'] > 0 && 
                                isset($createdProduct['variants'][0]['inventory_item_id'])) {
                                
                                $inventoryItemId = $createdProduct['variants'][0]['inventory_item_id'];
                                $quantity = (int)$pbProductData['qty'];
                                
                                $newInventoryUpdates[] = [
                                    'inventory_item_id' => $inventoryItemId,
                                    'location_id' => $locationId,
                                    'available' => $quantity
                                ];
                                
                                $this->logger->info("Setting initial inventory for new product '{$createdProduct['title']}' (SKU: {$createdSku}) to {$quantity}");
                            }
                        }
                        
                        // Update inventory for new products if any updates are needed
                        if (!empty($newInventoryUpdates)) {
                            $this->logger->info("Updating inventory for " . count($newInventoryUpdates) . " newly created products");
                            $this->shopify->bulkUpdateInventory($newInventoryUpdates);
                            $totalInventoryUpdates += count($newInventoryUpdates);
                        }
                        
                        // Save mappings
                        foreach ($createdProducts as $product) {
                            if (!empty($product['variants'][0]['sku'])) {
                                $sku = $product['variants'][0]['sku'];
                                
                                foreach ($group as $pbProduct) {
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
                        
                        // Add a pause between groups to respect rate limits
                        if (count($createGroups) > 1) {
                            sleep(1);
                        }
                    }
                    
                    $totalCreated += $createdCount;
                    $this->logger->info("Created {$createdCount} products in Shopify");
                    
                    // Add newly created products to collections based on their categories
                    $this->addProductsToCollections($createdProducts);
                } catch (Exception $e) {
                    $this->logger->error("Failed to create products: " . $e->getMessage());
                    $this->saveDeadLetter('create', $productsToCreate);
                }
            }
            
            // Update products - use smaller batches (max 10 at a time)
            if (!empty($productsToUpdate)) {
                $this->logger->info("Updating " . count($productsToUpdate) . " products in Shopify");
                $updatedCount = 0;
                
                try {
                    // Process in smaller groups to avoid rate limits
                    $updateGroups = array_chunk($productsToUpdate, 10);
                    foreach ($updateGroups as $group) {
                        $updatedProducts = $this->shopify->bulkUpdateProducts($group);
                        $updatedCount += count($updatedProducts);
                        
                        // Add a pause between groups
                        if (count($updateGroups) > 1) {
                            sleep(1);
                        }
                    }
                    
                    $totalUpdated += $updatedCount;
                    $this->logger->info("Updated {$updatedCount} products in Shopify");
                    
                    // Add updated products to collections based on their categories
                    $this->addProductsToCollections($updatedProducts);
                } catch (Exception $e) {
                    $this->logger->error("Failed to update products: " . $e->getMessage());
                    $this->saveDeadLetter('update', $productsToUpdate);
                }
            }
            
            // Archive products - smaller batches (max 10 at a time)
            if (!empty($productsToArchive)) {
                $this->logger->info("Archiving " . count($productsToArchive) . " products in Shopify");
                $archivedCount = 0;
                
                try {
                    $archiveGroups = array_chunk($productsToArchive, 10);
                    foreach ($archiveGroups as $group) {
                        $archiveData = [];
                        foreach ($group as $productId) {
                            $archiveData[] = [
                                'id' => $productId,
                                'status' => 'archived'
                            ];
                        }
                        
                        $archivedProducts = $this->shopify->bulkUpdateProducts($archiveData);
                        $archivedCount += count($archivedProducts);
                        
                        // Add a pause between groups
                        if (count($archiveGroups) > 1) {
                            sleep(1);
                        }
                    }
                    
                    $totalArchived += $archivedCount;
                    $this->logger->info("Archived {$archivedCount} products in Shopify");
                } catch (Exception $e) {
                    $this->logger->error("Failed to archive products: " . $e->getMessage());
                }
            }
            
            // Update inventory - smaller batches (max 20 at a time)
            if (!empty($inventoryUpdates)) {
                $this->logger->info("Updating inventory for " . count($inventoryUpdates) . " items in Shopify");
                $inventoryCount = 0;
                
                try {
                    $inventoryGroups = array_chunk($inventoryUpdates, 20);
                    foreach ($inventoryGroups as $group) {
                        $updatedInventory = $this->shopify->bulkUpdateInventory($group);
                        $inventoryCount += count($updatedInventory);
                        
                        // Add a pause between groups
                        if (count($inventoryGroups) > 1) {
                            sleep(1);
                        }
                    }
                    
                    $totalInventoryUpdates += $inventoryCount;
                    $this->logger->info("Updated inventory for {$inventoryCount} items in Shopify");
                } catch (Exception $e) {
                    $this->logger->error("Failed to update inventory: " . $e->getMessage());
                }
            }
            
            $batchTime = round(microtime(true) - $batchStartTime, 2);
            $this->logger->info("Completed batch {$batchIndex}/{$batchCount} in {$batchTime} seconds");
            
            // Sleep longer between batches to respect API rate limits
            if ($batchIndex < $batchCount - 1) {
                $sleepTime = min(5, max(2, $batchTime * 0.25)); // 25% of batch time or between 2-5 seconds
                $this->logger->info("Sleeping for {$sleepTime} seconds before next batch");
                sleep((int)$sleepTime);
            }
        }
        
        $totalTime = round(microtime(true) - $startTime, 2);
        $this->logger->info("Total processing time: {$totalTime} seconds");
        $this->logger->info("Summary: Created {$totalCreated}, Updated {$totalUpdated}, Archived {$totalArchived}, Inventory updates {$totalInventoryUpdates}");
        
        // Preload next batch of product details to warm up cache with reduced batch size
        if (!empty($powerbodyProducts)) {
            $processedIds = array_column($powerbodyProducts, 'product_id');
            $this->preloadNextProductBatch($powerbodyProducts, $processedIds, 50); // Reduced from 100
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
        
        // Remove EAN pattern from title if present
        $title = preg_replace('/\s*\(EAN\s+\d+\)\s*/', '', $title);
        
        $vendor = $pbProduct['manufacturer'] ?? 'Powerbody';
        $sku = $pbProduct['sku'] ?? '';
        $price = $pbProduct['price_tax'] ?? $pbProduct['price'] ?? 0;
        
        // Apply 22% markup to the price
        $price = round($price * 1.22, 2);
        
        $quantity = (int)($pbProduct['qty'] ?? 0);
        $status = 'active';
        
        // Set out of stock products to draft status instead of showing as sold out
        if ($quantity <= 0) {
            $status = 'draft';
            $this->logger->info("INVENTORY CHECK: Product '{$title}' (SKU: {$sku}) has 0 quantity - setting to draft status");
        } else if (isset($pbProduct['status'])) {
            if (in_array($pbProduct['status'], ['disabled', 'archival'])) {
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
        
        // Add metafields for additional product data
        $metafields = [];
        
        // Add supplier metafield - consistent for all products
        $metafields[] = [
            'namespace' => 'powerbody',
            'key' => 'supplier',
            'value' => 'Powerbody',
            'type' => 'single_line_text_field'
        ];
        
        // Add manufacturer as metafield (even though it's also in vendor field)
        if (!empty($pbProduct['manufacturer'])) {
            $metafields[] = [
                'namespace' => 'powerbody',
                'key' => 'manufacturer',
                'value' => $pbProduct['manufacturer'],
                'type' => 'single_line_text_field'
            ];
        }
        
        // Add portion count (servings)
        if (!empty($pbProduct['portion_count'])) {
            $metafields[] = [
                'namespace' => 'powerbody',
                'key' => 'portion_count',
                'value' => $pbProduct['portion_count'],
                'type' => 'number_integer'
            ];
        }
        
        // Add price per serving
        if (!empty($pbProduct['price_per_serving'])) {
            // Also apply the same 22% markup to price_per_serving
            $pricePerServing = round(floatval($pbProduct['price_per_serving']) * 1.22, 2);
            $metafields[] = [
                'namespace' => 'powerbody',
                'key' => 'price_per_serving',
                'value' => (string)$pricePerServing,
                'type' => 'number_decimal'
            ];
        }
        
        // Add metafields to product if we have any
        if (!empty($metafields)) {
            $product['metafields'] = $metafields;
        }
        
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
        
        // Add category as product_type in Shopify
        if (!empty($pbProduct['category'])) {
            $product['product_type'] = $pbProduct['category'];
        }
        
        // Add tags for better categorization and searchability
        $tags = [];
        
        // Add category as a tag
        if (!empty($pbProduct['category'])) {
            $tags[] = $pbProduct['category'];
        }
        
        // Add manufacturer as a tag if it's not already the vendor
        if (!empty($pbProduct['manufacturer']) && $pbProduct['manufacturer'] !== $vendor) {
            $tags[] = $pbProduct['manufacturer'];
        }
        
        // Add any product tags from PowerBody
        if (!empty($pbProduct['tags']) && is_array($pbProduct['tags'])) {
            foreach ($pbProduct['tags'] as $key => $tagValue) {
                // In PowerBody, tags are like { "2": "Gluten free", "8": "Halal" }
                if (is_string($tagValue)) {
                    $tags[] = $tagValue;
                }
            }
        }
        
        // Add "New" tag if product is marked as new
        if (isset($pbProduct['is_new']) && $pbProduct['is_new']) {
            $tags[] = 'New';
        }
        
        // Add expiry date tag if available
        if (!empty($pbProduct['expiry_date'])) {
            $tags[] = 'Expires: ' . $pbProduct['expiry_date'];
        }
        
        // Set tags if we have any
        if (!empty($tags)) {
            $product['tags'] = implode(', ', $tags);
        }
        
        // Add barcode/EAN if available
        if (!empty($pbProduct['ean']) && isset($product['variants'][0])) {
            $product['variants'][0]['barcode'] = $pbProduct['ean'];
        }
        
        // Add weight if available
        if (!empty($pbProduct['weight']) && isset($product['variants'][0])) {
            $product['variants'][0]['weight'] = (float)$pbProduct['weight'];
            $product['variants'][0]['weight_unit'] = 'kg'; // PowerBody uses kg
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
    private function preloadNextProductBatch(array $allProducts, array $alreadyLoaded, int $limit = 50): void
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
        
        $this->logger->info("Preloading {$preloadCount} products for next sync");
        
        // Process synchronously with reduced impact
        $processedCount = 0;
        foreach ($productsToPreload as $index => $productId) {
            // Only preload every other product to reduce impact
            if ($index % 2 === 0) {
                try {
                    $this->powerbody->refreshProductCache($productId);
                    $processedCount++;
                    
                    // Add delay to avoid overwhelming the API
                    if ($index % 5 === 0 && $index > 0) {
                        usleep(500000); // 500ms
                    } else {
                        usleep(200000); // 200ms between requests
                    }
                } catch (Exception $e) {
                    // Just log and continue with next product
                    $this->logger->debug("Failed to preload product {$productId}: " . $e->getMessage());
                }
            }
        }
        
        $this->logger->info("Preloaded {$processedCount} products for next sync");
    }

    /**
     * Save the raw product data from the API for debugging
     * @param array $productData The raw product data from PowerBody API
     * @param string $sku The product SKU for reference
     */
    private function saveProductDebugData(array $productData, string $sku = ''): void
    {
        // Only save debug data if debug mode is enabled
        if (!$this->debug) {
            return;
        }
        
        $debugDir = $this->storageDir . '/debug';
        
        // Ensure debug directory exists
        if (!file_exists($debugDir)) {
            if (!mkdir($debugDir, 0755, true)) {
                $this->logger->warning('Failed to create debug directory: ' . $debugDir);
                return;
            }
        }
        
        // Generate a filename with timestamp and SKU if available
        $timestamp = date('YmdHis');
        $filename = $debugDir . '/product_' . ($sku ? $sku . '_' : '') . $timestamp . '.json';
        
        try {
            $jsonData = json_encode($productData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($filename, $jsonData);
            $this->logger->info('Saved detailed product data to ' . basename($filename));
        } catch (Exception $e) {
            $this->logger->warning('Failed to save detailed product data: ' . $e->getMessage());
        }
    }

    private function addProductsToCollections(array $products): void
    {
        if (empty($products)) {
            return;
        }
        
        $this->logger->info('Adding ' . count($products) . ' products to collections based on categories');
        $collectionsCache = []; // Cache for collection lookups
        $collectionsAdded = 0;
        
        foreach ($products as $product) {
            // Skip if product has no ID or product_type (category)
            if (empty($product['id']) || empty($product['product_type'])) {
                continue;
            }
            
            $productId = $product['id'];
            $category = $product['product_type'];
            
            // Lookup or create the collection (using cache to reduce API calls)
            if (!isset($collectionsCache[$category])) {
                $collection = $this->shopify->getOrCreateCollection($category);
                if ($collection) {
                    $collectionsCache[$category] = $collection;
                } else {
                    $this->logger->warning("Failed to get or create collection for category: {$category}");
                    continue;
                }
            }
            
            // Add product to collection
            $collectionId = $collectionsCache[$category]['id'];
            if ($this->shopify->addProductToCollection($productId, $collectionId)) {
                $collectionsAdded++;
            }
            
            // Add small delay to respect API limits
            usleep(100000); // 100ms
        }
        
        $this->logger->info("Added {$collectionsAdded} products to collections");
    }
} 