<?php

namespace App\Core;

use App\Config\EnvLoader;
use App\Logger\Factory as LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Exception;

class ShopifyLink
{
    private Client $client;
    private $logger;
    private $config;
    private $retryCount = 3;
    private $rateLimitRemaining = 40; // Default API call limit
    private $rateLimitMax = 80; // Default max API calls
    private $lastCallTimestamp = 0;
    private $callDelay = 500000; // 500ms in microseconds
    private $locationId;
    private $nextPageUrl = null;
    private $prevPageUrl = null;
    private $bucket = []; // Leaky bucket for rate limiting

    public function __construct()
    {
        $this->logger = LoggerFactory::getInstance('shopify');
        $this->config = EnvLoader::getInstance();
        $this->locationId = $this->config->get('SHOPIFY_LOCATION_ID');
        
        // Debug logging for location ID
        $this->logger->info('Shopify location ID from config: ' . $this->locationId);
        
        // If location ID is empty, try to get default location
        if (empty($this->locationId) || $this->locationId === '0') {
            $this->logger->warning('Invalid location ID in config, will attempt to fetch default location');
            $this->initClient();
            $this->fetchDefaultLocation();
        } else {
            $this->initClient();
        }
    }

    private function initClient(): void
    {
        $stack = HandlerStack::create();
        
        // Add rate-limit middleware
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            $this->handleRateLimits($response);
            return $response;
        }));
        
        // Add retry middleware
        $stack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));
        
        $this->client = new Client([
            'base_uri' => 'https://' . $this->config->get('SHOPIFY_STORE') . '/admin/api/2024-10/',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Shopify-Access-Token' => $this->config->get('SHOPIFY_ACCESS_TOKEN'),
                'User-Agent' => 'PHP Shopify Sync App/1.0' // Identifying the app according to Shopify guidelines
            ],
            'handler' => $stack
        ]);
    }

    private function fetchDefaultLocation(): void
    {
        try {
            $response = $this->request('GET', 'locations.json');
            if (!empty($response['locations'])) {
                // Get the first active location
                foreach ($response['locations'] as $location) {
                    if ($location['active']) {
                        $this->locationId = $location['id'];
                        $this->logger->info('Using default location: ' . $this->locationId);
                        break;
                    }
                }
            }
            
            if (empty($this->locationId) || $this->locationId === '0') {
                $this->logger->error('Could not find an active location in the store');
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch default location: ' . $e->getMessage());
        }
    }

    public function getLocationId(): int
    {
        return intval($this->locationId);
    }

    private function parseLinkHeader(ResponseInterface $response): void
    {
        $this->nextPageUrl = null;
        $this->prevPageUrl = null;
        
        if ($response->hasHeader('Link')) {
            $links = $response->getHeader('Link');
            
            foreach ($links as $link) {
                $linkParts = explode(',', $link);
                
                foreach ($linkParts as $linkPart) {
                    if (strpos($linkPart, 'rel="next"') !== false) {
                        preg_match('/<(.*)>/', $linkPart, $matches);
                        if (isset($matches[1])) {
                            $this->nextPageUrl = $matches[1];
                            $this->logger->debug("Next page URL: {$this->nextPageUrl}");
                        }
                    } elseif (strpos($linkPart, 'rel="previous"') !== false) {
                        preg_match('/<(.*)>/', $linkPart, $matches);
                        if (isset($matches[1])) {
                            $this->prevPageUrl = $matches[1];
                        }
                    }
                }
            }
        }
    }
    
    public function getNextPageUrl(): ?string
    {
        return $this->nextPageUrl;
    }
    
    public function getPrevPageUrl(): ?string
    {
        return $this->prevPageUrl;
    }

    private function handleRateLimits(ResponseInterface $response): void
    {
        // Parse Shopify's rate limit header
        if ($response->hasHeader('X-Shopify-Shop-Api-Call-Limit')) {
            $limitHeader = $response->getHeader('X-Shopify-Shop-Api-Call-Limit')[0];
            list($current, $max) = explode('/', $limitHeader);
            
            $current = (int)$current;
            $max = (int)$max;
            $this->rateLimitMax = $max;
            $this->rateLimitRemaining = $max - $current;
            
            // Only log when it's useful information, not every request
            if ($this->rateLimitRemaining < 10) {
                $this->logger->info("Shopify API call limit: $current/$max (remaining: {$this->rateLimitRemaining})");
            } else {
                $this->logger->debug("Shopify API call limit: $current/$max");
            }
            
            // More optimized rate limiting strategy - only slow down when absolutely necessary
            $percentUsed = ($current / $max) * 100;
            
            if ($percentUsed > 90) {
                // Only slow down significantly when very close to limit
                $this->logger->warning("Shopify API rate limit over 90% used. Slowing down requests.");
                usleep(500000); // 500ms delay instead of 2s
            } else if ($percentUsed > 75) {
                // Modest slowdown when getting close to limit
                usleep(250000); // 250ms
            }
            
            // Store timestamp in leaky bucket for tracking request frequency
            $now = time();
            
            // Clean out entries older than 30 seconds (instead of 60)
            $this->bucket = array_filter($this->bucket, function($timestamp) use ($now) {
                return ($now - $timestamp) < 30;
            });
            
            // Add current timestamp to bucket
            $this->bucket[] = $now;
            
            // Only add additional delay if we're extremely busy
            // Reduced threshold from 0.7 to 0.85 of max capacity
            if (count($this->bucket) > ($max * 0.85)) {
                // Calculate a smaller delay based on how close we are to the limit
                $delay = 0.5 + ((count($this->bucket) / $max) * 0.5); // Maximum 1 second
                $this->logger->info("Adding additional delay of {$delay}s due to high request frequency");
                usleep($delay * 1000000);
            }
        }
        
        // Parse Link header for pagination
        $this->parseLinkHeader($response);
        
        // Reduce base delay between API calls
        $minDelay = 100000; // 100ms minimum delay (down from 250ms)
        
        $now = microtime(true);
        $elapsed = ($now - $this->lastCallTimestamp) * 1000000; // to microseconds
        
        if ($elapsed < $minDelay) {
            usleep($minDelay - $elapsed);
        }
        
        $this->lastCallTimestamp = microtime(true);
    }

    private function retryDecider()
    {
        return function (
            $retries,
            Request $request,
            Response $response = null,
            RequestException $exception = null
        ) {
            // Don't retry if we've hit the max retries
            if ($retries >= $this->retryCount) {
                return false;
            }
            
            // Retry on rate limit exceeded (429) or server errors (>= 500)
            if ($response && ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500)) {
                $this->logger->warning("Retry {$retries}/{$this->retryCount} for request to {$request->getUri()}");
                return true;
            }
            
            // Retry on connection errors
            if ($exception instanceof RequestException && $exception->getCode() >= 500) {
                $this->logger->warning("Retry {$retries}/{$this->retryCount} for request to {$request->getUri()}");
                return true;
            }
            
            return false;
        };
    }

    private function retryDelay()
    {
        return function ($numberOfRetries) {
            // Exponential backoff with jitter
            $delay = (1000 * pow(2, $numberOfRetries)) + rand(0, 1000);
            $this->logger->debug("Retry delay: {$delay}ms");
            return $delay;
        };
    }

    private function request(string $method, string $endpoint, array $data = [])
    {
        try {
            $options = [];
            
            if (!empty($data)) {
                $options['json'] = $data;
            }
            
            $response = $this->client->request($method, $endpoint, $options);
            $responseBody = json_decode($response->getBody()->getContents(), true);
            
            return $responseBody;
        } catch (RequestException $e) {
            $this->logger->error('Shopify API request failed: ' . $e->getMessage(), [
                'method' => $method,
                'endpoint' => $endpoint
            ]);
            
            if ($e->hasResponse()) {
                $this->logger->error('Response: ' . $e->getResponse()->getBody()->getContents());
            }
            
            throw $e;
        }
    }

    public function getProducts(array $params = []): array
    {
        $this->logger->info('Fetching products from Shopify');
        $queryString = !empty($params) ? '?' . http_build_query($params) : '';
        $response = $this->request('GET', "products.json{$queryString}");
        return $response['products'] ?? [];
    }

    public function getProduct(int $productId): ?array
    {
        $this->logger->info('Fetching product from Shopify', ['product_id' => $productId]);
        $response = $this->request('GET', "products/{$productId}.json");
        return $response['product'] ?? null;
    }

    public function createProduct(array $productData): ?array
    {
        $this->logger->info('Creating product in Shopify');
        $response = $this->request('POST', 'products.json', ['product' => $productData]);
        return $response['product'] ?? null;
    }

    public function updateProduct(int $productId, array $productData): ?array
    {
        $this->logger->info('Updating product in Shopify', ['product_id' => $productId]);
        $response = $this->request('PUT', "products/{$productId}.json", ['product' => $productData]);
        return $response['product'] ?? null;
    }

    public function createProductsBatch(array $products): array
    {
        $result = [];
        $chunks = array_chunk($products, 100);
        $this->logger->info('Creating products batch in Shopify', ['count' => count($products), 'chunks' => count($chunks)]);
        
        foreach ($chunks as $index => $chunk) {
            $this->logger->debug("Processing chunk {$index} of {count($chunks)}");
            foreach ($chunk as $product) {
                try {
                    $createdProduct = $this->createProduct($product);
                    if ($createdProduct) {
                        $result[] = $createdProduct;
                    }
                } catch (Exception $e) {
                    $this->logger->error('Failed to create product: ' . $e->getMessage(), ['product' => $product]);
                }
            }
        }
        
        return $result;
    }

    public function updateProductsBatch(array $products): array
    {
        $result = [];
        $chunks = array_chunk($products, 100);
        $this->logger->info('Updating products batch in Shopify', ['count' => count($products), 'chunks' => count($chunks)]);
        
        foreach ($chunks as $index => $chunk) {
            $this->logger->debug("Processing chunk {$index} of {count($chunks)}");
            foreach ($chunk as $product) {
                try {
                    if (!isset($product['id'])) {
                        $this->logger->error('Cannot update product without ID', ['product' => $product]);
                        continue;
                    }
                    
                    $productId = $product['id'];
                    $updatedProduct = $this->updateProduct($productId, $product);
                    
                    if ($updatedProduct) {
                        $result[] = $updatedProduct;
                    }
                } catch (Exception $e) {
                    $this->logger->error('Failed to update product: ' . $e->getMessage(), ['product' => $product]);
                }
            }
        }
        
        return $result;
    }

    public function getOrders(array $params = []): array
    {
        $this->logger->info('Fetching orders from Shopify', ['params' => $params]);
        $queryString = !empty($params) ? '?' . http_build_query($params) : '';
        $response = $this->request('GET', "orders.json{$queryString}");
        return $response['orders'] ?? [];
    }

    public function getOrder(int $orderId, array $params = []): ?array
    {
        $this->logger->info('Fetching order from Shopify', ['order_id' => $orderId]);
        $queryString = !empty($params) ? '?' . http_build_query($params) : '';
        $response = $this->request('GET', "orders/{$orderId}.json{$queryString}");
        return $response['order'] ?? null;
    }

    public function updateOrder(int $orderId, array $orderData): ?array
    {
        $this->logger->info('Updating order in Shopify', ['order_id' => $orderId]);
        $response = $this->request('PUT', "orders/{$orderId}.json", ['order' => $orderData]);
        return $response['order'] ?? null;
    }

    public function getCustomer(int $customerId): ?array
    {
        $this->logger->info('Fetching customer from Shopify', ['customer_id' => $customerId]);
        $response = $this->request('GET', "customers/{$customerId}.json");
        return $response['customer'] ?? null;
    }

    public function createFulfillment(int $orderId, array $fulfillmentData): ?array
    {
        $this->logger->info('Creating fulfillment in Shopify', ['order_id' => $orderId]);
        $response = $this->request(
            'POST',
            "orders/{$orderId}/fulfillments.json",
            ['fulfillment' => $fulfillmentData]
        );
        return $response['fulfillment'] ?? null;
    }

    public function updateFulfillment(int $fulfillmentId, array $fulfillmentData): ?array
    {
        $this->logger->info('Updating fulfillment in Shopify', ['fulfillment_id' => $fulfillmentId]);
        $response = $this->request(
            'PUT',
            "fulfillments/{$fulfillmentId}.json",
            ['fulfillment' => $fulfillmentData]
        );
        return $response['fulfillment'] ?? null;
    }

    public function addNoteToOrder(int $orderId, string $note): ?array
    {
        $this->logger->info('Adding note to order in Shopify', ['order_id' => $orderId]);
        $orderData = ['note' => $note];
        return $this->updateOrder($orderId, $orderData);
    }

    public function createRefund(int $orderId, array $refundData): ?array
    {
        $this->logger->info('Creating refund in Shopify', ['order_id' => $orderId]);
        $response = $this->request(
            'POST',
            "orders/{$orderId}/refunds.json",
            ['refund' => $refundData]
        );
        return $response['refund'] ?? null;
    }

    public function getInventoryLevels(array $params = []): array
    {
        $this->logger->info('Fetching inventory levels from Shopify', ['params' => $params]);
        $queryString = !empty($params) ? '?' . http_build_query($params) : '';
        $response = $this->request('GET', "inventory_levels.json{$queryString}");
        return $response['inventory_levels'] ?? [];
    }

    public function updateInventoryLevel(int $inventoryItemId, int $locationId, int $quantity): ?array
    {
        $this->logger->info('Updating inventory level in Shopify', [
            'inventory_item_id' => $inventoryItemId,
            'location_id' => $locationId,
            'quantity' => $quantity
        ]);
        
        $inventoryData = [
            'inventory_item_id' => $inventoryItemId,
            'location_id' => $locationId,
            'available' => $quantity
        ];
        
        $response = $this->request('POST', 'inventory_levels/set.json', $inventoryData);
        return $response['inventory_level'] ?? null;
    }

    /**
     * Bulk update products using Shopify's REST API
     * 
     * @param array $products Array of products to update
     * @return array Results from the bulk operation
     */
    public function bulkUpdateProducts(array $products): array
    {
        if (empty($products)) {
            return [];
        }
        
        $this->logger->info('Bulk updating ' . count($products) . ' products via Shopify REST API');
        
        $results = [];
        // Use smaller chunks to avoid 406 Not Acceptable errors
        $chunks = array_chunk($products, 5); 
        
        foreach ($chunks as $chunk) {
            // Process each product individually to avoid format issues
            foreach ($chunk as $product) {
                try {
                    if (!isset($product['id'])) {
                        $this->logger->error('Cannot update product without ID', ['product' => $product]);
                        continue;
                    }
                    
                    $productId = $product['id'];
                    
                    // Clean product data to ensure compatibility
                    $cleanProduct = $this->cleanProductData($product);
                    
                    // Update product individually instead of in bulk
                    $updatedProduct = $this->updateProduct($productId, $cleanProduct);
                    
                    if ($updatedProduct) {
                        $results[] = $updatedProduct;
                        
                        // Add a small delay between requests to avoid rate limits
                        usleep(100000); // 100ms
                    }
                } catch (Exception $e) {
                    $this->logger->error('Failed to update product: ' . $e->getMessage(), [
                        'product_id' => $product['id'] ?? 'unknown'
                    ]);
                }
            }
            
            // Add a short pause between chunks
            if (count($chunks) > 1) {
                usleep(500000); // 500ms
            }
        }
        
        return $results;
    }
    
    /**
     * Clean product data to ensure it's compatible with Shopify API
     * 
     * @param array $product Product data to clean
     * @return array Cleaned product data
     */
    private function cleanProductData(array $product): array
    {
        $cleanProduct = [];
        
        // Only include fields that are allowed to be updated
        $allowedFields = [
            'id', 'title', 'body_html', 'vendor', 'product_type', 
            'tags', 'published', 'status', 'variants', 'options',
            'images', 'metafields'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($product[$field])) {
                $cleanProduct[$field] = $product[$field];
            }
        }
        
        // Clean up variants if present
        if (isset($cleanProduct['variants']) && is_array($cleanProduct['variants'])) {
            foreach ($cleanProduct['variants'] as $key => $variant) {
                // Ensure each variant has an ID
                if (!isset($variant['id'])) {
                    unset($cleanProduct['variants'][$key]);
                    continue;
                }
                
                // Only keep fields that can be updated on variants
                $allowedVariantFields = [
                    'id', 'price', 'compare_at_price', 'inventory_management',
                    'inventory_policy', 'sku', 'barcode'
                ];
                
                $cleanVariant = [];
                foreach ($allowedVariantFields as $field) {
                    if (isset($variant[$field])) {
                        $cleanVariant[$field] = $variant[$field];
                    }
                }
                
                $cleanProduct['variants'][$key] = $cleanVariant;
            }
        }
        
        return $cleanProduct;
    }
    
    /**
     * Bulk update inventory levels for multiple items
     * 
     * @param array $inventoryUpdates Array of inventory updates
     * @return array Updated inventory levels
     */
    public function bulkUpdateInventory(array $inventoryUpdates): array
    {
        if (empty($inventoryUpdates)) {
            return [];
        }
        
        $this->logger->info('Bulk updating ' . count($inventoryUpdates) . ' inventory items');
        $results = [];
        
        // Process in smaller batches
        $chunks = array_chunk($inventoryUpdates, 20);
        
        foreach ($chunks as $chunk) {
            foreach ($chunk as $update) {
                try {
                    // Validate required fields
                    if (empty($update['inventory_item_id']) || 
                        empty($update['location_id']) || 
                        !isset($update['available'])) {
                        $this->logger->warning('Invalid inventory update data', ['data' => $update]);
                        continue;
                    }
                    
                    // Use the set endpoint directly (don't try to connect first)
                    $result = $this->updateInventoryLevel(
                        $update['inventory_item_id'],
                        $update['location_id'],
                        $update['available']
                    );
                    
                    if ($result) {
                        $results[] = $result;
                    }
                    
                    // Add a small delay between requests
                    usleep(100000); // 100ms
                } catch (Exception $e) {
                    $this->logger->error('Failed to update inventory: ' . $e->getMessage(), [
                        'inventory_item_id' => $update['inventory_item_id'] ?? 'unknown'
                    ]);
                }
            }
            
            // Add a short pause between chunks
            if (count($chunks) > 1) {
                usleep(500000); // 500ms
            }
        }
        
        return $results;
    }

    /**
     * Get a collection by its title
     * 
     * @param string $title Collection title to search for
     * @return array|null Collection data if found, null otherwise
     */
    public function getCollectionByTitle(string $title): ?array
    {
        $this->logger->info("Looking for collection with title: $title");
        
        // First check custom collections
        $params = [
            'title' => $title,
            'limit' => 1
        ];
        
        $response = $this->request('GET', "custom_collections.json?" . http_build_query($params));
        if (!empty($response['custom_collections'])) {
            $this->logger->info("Found existing custom collection: {$title}");
            return $response['custom_collections'][0];
        }
        
        // Then check smart collections
        $response = $this->request('GET', "smart_collections.json?" . http_build_query($params));
        if (!empty($response['smart_collections'])) {
            $this->logger->info("Found existing smart collection: {$title}");
            return $response['smart_collections'][0];
        }
        
        return null;
    }
    
    /**
     * Create a custom collection
     * 
     * @param string $title Collection title
     * @param string $description Optional collection description
     * @return array|null Created collection data
     */
    public function createCollection(string $title, string $description = ''): ?array
    {
        $this->logger->info("Creating collection: $title");
        
        $collectionData = [
            'custom_collection' => [
                'title' => $title,
                'body_html' => $description,
                'published' => true
            ]
        ];
        
        $response = $this->request('POST', 'custom_collections.json', $collectionData);
        
        if (!empty($response['custom_collection'])) {
            $this->logger->info("Successfully created collection: {$title}");
            return $response['custom_collection'];
        } else {
            $this->logger->error("Failed to create collection: {$title}");
            return null;
        }
    }
    
    /**
     * Add a product to a collection
     * 
     * @param int $productId Shopify product ID
     * @param int $collectionId Shopify collection ID
     * @return bool Success indicator
     */
    public function addProductToCollection(int $productId, int $collectionId): bool
    {
        $this->logger->info("Adding product {$productId} to collection {$collectionId}");
        
        // Add product to collection using collect endpoint
        $collectData = [
            'collect' => [
                'product_id' => $productId,
                'collection_id' => $collectionId
            ]
        ];
        
        try {
            $response = $this->request('POST', 'collects.json', $collectData);
            
            if (!empty($response['collect'])) {
                $this->logger->info("Successfully added product {$productId} to collection {$collectionId}");
                return true;
            }
        } catch (Exception $e) {
            // If it's a duplicate, consider it a success
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $this->logger->debug("Product {$productId} is already in collection {$collectionId}");
                return true;
            }
            
            $this->logger->error("Failed to add product {$productId} to collection {$collectionId}: {$e->getMessage()}");
        }
        
        return false;
    }
    
    /**
     * Get or create a collection by title
     * 
     * @param string $title Collection title
     * @return array|null Collection data
     */
    public function getOrCreateCollection(string $title): ?array
    {
        $collection = $this->getCollectionByTitle($title);
        
        if (!$collection) {
            $collection = $this->createCollection($title);
        }
        
        return $collection;
    }
    
    /**
     * Delete a product from Shopify
     * 
     * @param int $productId The product ID to delete
     * @return bool Success indicator
     */
    public function deleteProduct(int $productId): bool
    {
        $this->logger->info('Deleting product from Shopify', ['product_id' => $productId]);
        try {
            $this->request('DELETE', "products/{$productId}.json");
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to delete product: ' . $e->getMessage(), ['product_id' => $productId]);
            return false;
        }
    }

    /**
     * Execute a GraphQL query against Shopify Admin API
     * 
     * @param string $query The GraphQL query
     * @param array $variables Query variables
     * @return array Response data
     */
    public function graphqlQuery(string $query, array $variables = []): array
    {
        try {
            $payload = [
                'query' => $query,
                'variables' => $variables
            ];
            
            $response = $this->client->post('graphql.json', [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shopify-Access-Token' => $this->config->get('SHOPIFY_ACCESS_TOKEN')
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (isset($data['errors'])) {
                $this->logger->error('GraphQL query errors', [
                    'errors' => $data['errors'],
                    'query' => $query
                ]);
                throw new Exception('GraphQL query failed: ' . json_encode($data['errors']));
            }
            
            return $data;
        } catch (RequestException $e) {
            $this->logger->error('GraphQL request failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function get(string $endpoint, array $params = [], string $method = 'GET', array $data = []): array
    {
        $this->logger->info('Making direct API call to Shopify', [
            'endpoint' => $endpoint,
            'method' => $method
        ]);
        
        $queryString = !empty($params) ? '?' . http_build_query($params) : '';
        $fullEndpoint = "{$endpoint}.json{$queryString}";
        
        return $this->request($method, $fullEndpoint, $data);
    }
} 