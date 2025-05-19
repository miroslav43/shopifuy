<?php

namespace App\Core;

use App\Config\EnvLoader;
use App\Logger\Factory as LoggerFactory;
use SoapClient;
use SoapFault;
use Exception;

class PowerBodyLink
{
    private ?SoapClient $client = null;
    private ?string $session = null;
    private $logger;
    private $config;
    private $retryCount = 3;
    private $retrySleepMs = 1000;
    private $lastSessionRefresh = 0; // Timestamp when session was last refreshed
    private $sessionLifetime = 600; // Session lifetime in seconds (10 minutes)
    private $cacheDir;
    private $cacheExpiry = 604800; // One week in seconds (7 * 24 * 60 * 60)

    public function __construct()
    {
        $this->logger = LoggerFactory::getInstance('powerbody');
        $this->config = EnvLoader::getInstance();
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/cache/products';
        
        // Ensure cache directory exists
        $this->ensureCacheDirectory();
        
        $this->initClient();
    }

    private function ensureCacheDirectory(): void
    {
        if (!file_exists($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true)) {
                $this->logger->warning('Failed to create product cache directory: ' . $this->cacheDir);
            } else {
                $this->logger->info('Created product cache directory: ' . $this->cacheDir);
            }
        }
    }

    private function initClient(): void
    {
        try {
            $this->client = new SoapClient(
                $this->config->get('POWERBODY_API_WSDL'),
                [
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'trace' => true,
                    'exceptions' => true,
                    'keep_alive' => false // Don't keep the connection alive
                ]
            );
            $this->login();
        } catch (SoapFault $e) {
            $this->logger->error('Failed to initialize SOAP client: ' . $e->getMessage());
            throw $e;
        }
    }

    private function login(): void
    {
        // Close previous session if exists
        $this->endCurrentSession();
        
        try {
            $username = $this->config->get('POWERBODY_USER');
            $password = $this->config->get('POWERBODY_PASS');
            
            $this->session = $this->client->login($username, $password);
            $this->lastSessionRefresh = time();
            $this->logger->info('Successfully logged into PowerBody API');
        } catch (SoapFault $e) {
            $this->session = null;
            $this->logger->error('Failed to login to PowerBody API: ' . $e->getMessage());
            throw $e;
        }
    }

    private function endCurrentSession(): void
    {
        if ($this->session !== null && $this->client !== null) {
            try {
                $this->client->endSession($this->session);
                $this->logger->debug('Ended previous PowerBody API session');
            } catch (Exception $e) {
                // Just log warning, we'll create a new session anyway
                $this->logger->warning('Error ending PowerBody API session: ' . $e->getMessage());
            }
            $this->session = null;
        }
    }

    private function ensureSession(): void
    {
        $currentTime = time();
        
        // If no session or session is older than sessionLifetime, login again
        if ($this->session === null || ($currentTime - $this->lastSessionRefresh) > $this->sessionLifetime) {
            if ($this->session !== null) {
                $this->logger->info('PowerBody API session expired based on lifetime, renewing');
            }
            $this->login();
        }
    }

    public function __destruct()
    {
        $this->endCurrentSession();
    }

    private function callWithRetry(string $method, $params = null)
    {
        $this->ensureSession();
        
        $attempt = 0;
        
        while ($attempt < $this->retryCount) {
            try {
                // Get a fresh session if we're retrying
                if ($attempt > 0) {
                    $this->login();
                }
                
                $result = $this->client->call($this->session, $method, $params);
                
                // Debug response
                $this->logger->debug('PowerBody API raw response type: ' . gettype($result));
                if (is_string($result)) {
                    $this->logger->debug('PowerBody API raw response (first 200 chars): ' . substr($result, 0, 200));
                } elseif (is_array($result)) {
                    $this->logger->debug('PowerBody API raw response (array keys): ' . implode(', ', array_keys($result)));
                }
                
                // Handle case where result is JSON string
                if (is_string($result) && $this->isJson($result)) {
                    $result = json_decode($result, true);
                    $this->logger->debug('PowerBody API response decoded from JSON to array');
                }
                
                // Update session refresh time on successful call
                $this->lastSessionRefresh = time();
                
                return $result;
            } catch (SoapFault $e) {
                $attempt++;
                $this->logger->warning(
                    "PowerBody API call failed (attempt $attempt/$this->retryCount): " . $e->getMessage()
                );
                
                // Force session renewal on any error
                if (strpos($e->getMessage(), 'session') !== false) {
                    $this->session = null;
                    $this->logger->info('Session error detected, will force login on next attempt');
                }
                
                if ($attempt >= $this->retryCount) {
                    $this->logger->error('PowerBody API call failed after ' . $this->retryCount . ' attempts');
                    throw $e;
                }
                
                // Exponential backoff
                $sleepMs = $this->retrySleepMs * pow(2, $attempt - 1);
                $this->logger->debug("Sleeping for {$sleepMs}ms before retry");
                usleep($sleepMs * 1000);
            }
        }
        
        throw new Exception('PowerBody API call failed after ' . $this->retryCount . ' attempts');
    }
    
    // Helper method to check if a string is valid JSON
    private function isJson($string) {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get the cached product info file path for a specific product ID
     */
    private function getCacheFilePath(int $productId): string
    {
        return $this->cacheDir . "/product_" . $productId . ".json";
    }

    /**
     * Check if a cached product info file exists and is still valid
     */
    private function isCacheValid(string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return false;
        }
        
        $fileTime = filemtime($cacheFile);
        $now = time();
        
        // Check if file is older than cache expiry period
        if (($now - $fileTime) > $this->cacheExpiry) {
            $this->logger->debug("Cache expired for: " . basename($cacheFile));
            return false;
        }
        
        return true;
    }

    /**
     * Load product info from cache
     */
    private function loadFromCache(string $cacheFile)
    {
        try {
            $content = file_get_contents($cacheFile);
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning("Invalid JSON in cache file: " . basename($cacheFile));
                return null;
            }
            
            $this->logger->debug("Loaded from cache: " . basename($cacheFile));
            return $data;
        } catch (Exception $e) {
            $this->logger->warning("Failed to load cache file: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Save product info to cache
     */
    private function saveToCache(int $productId, $productInfo): void
    {
        $cacheFile = $this->getCacheFilePath($productId);
        
        try {
            // Create cache data with metadata
            $cacheData = [
                'cached_at' => time(),
                'expires_at' => time() + $this->cacheExpiry,
                'product_data' => $productInfo
            ];
            
            $jsonData = json_encode($cacheData, JSON_PRETTY_PRINT);
            file_put_contents($cacheFile, $jsonData);
            $this->logger->debug("Saved to cache: product_" . $productId . ".json");
        } catch (Exception $e) {
            $this->logger->warning("Failed to save to cache: " . $e->getMessage());
        }
    }

    public function getProductList()
    {
        $this->logger->info('Fetching product list from PowerBody API');
        
        // Check if we have a product list cache
        $cacheFile = $this->cacheDir . "/product_list.json";
        
        if ($this->isCacheValid($cacheFile)) {
            $cachedData = $this->loadFromCache($cacheFile);
            if ($cachedData !== null && isset($cachedData['product_data'])) {
                $this->logger->info('Using cached product list (expires: ' . date('Y-m-d H:i:s', $cachedData['expires_at']) . ')');
                return $cachedData['product_data'];
            }
        }
        
        // Not in cache or invalid cache, fetch from API
        $result = $this->callWithRetry('dropshipping.getProductList');
        
        // Ensure the result is an array
        if (empty($result)) {
            return [];
        }
        
        // Log the result type for debugging
        $this->logger->debug('ProductList result type: ' . gettype($result));
        
        // If result is unexpectedly still a string, try to decode it
        if (is_string($result)) {
            $this->logger->warning('ProductList result is still a string after callWithRetry, attempting to decode');
            $result = json_decode($result, true) ?: [];
        }
        
        // Cache the result
        if (!empty($result)) {
            try {
                $cacheData = [
                    'cached_at' => time(),
                    'expires_at' => time() + $this->cacheExpiry,
                    'product_data' => $result
                ];
                
                $jsonData = json_encode($cacheData, JSON_PRETTY_PRINT);
                file_put_contents($cacheFile, $jsonData);
                $this->logger->info("Saved product list to cache (expires: " . date('Y-m-d H:i:s', $cacheData['expires_at']) . ")");
            } catch (Exception $e) {
                $this->logger->warning("Failed to save product list to cache: " . $e->getMessage());
            }
        }
        
        return $result;
    }

    public function getProductInfo(int $productId)
    {
        $this->logger->info('Getting product info for ID: ' . $productId);
        
        // Check if we have a cache for this product
        $cacheFile = $this->getCacheFilePath($productId);
        
        if ($this->isCacheValid($cacheFile)) {
            $cachedData = $this->loadFromCache($cacheFile);
            if ($cachedData !== null && isset($cachedData['product_data'])) {
                $this->logger->info('Using cached product info for ID ' . $productId . ' (expires: ' . date('Y-m-d H:i:s', $cachedData['expires_at']) . ')');
                return $cachedData['product_data'];
            }
        }
        
        // Not in cache or invalid cache, fetch from API
        $this->logger->info('Fetching product info from API for ID: ' . $productId);
        $productInfo = $this->callWithRetry('dropshipping.getProductInfo', $productId);
        
        // Save to cache if we got valid data
        if (!empty($productInfo)) {
            $this->saveToCache($productId, $productInfo);
        }
        
        return $productInfo;
    }

    /**
     * Create a new order in PowerBody
     * Response statuses:
     * - 'SUCCESS' - Order has been created
     * - 'ALREADY_EXISTS' - Order already exists, cannot be created again
     * - 'FAIL' - Error in creating an order
     *
     * @param array $orderData Order data formatted according to PowerBody API docs
     * @return array Response with api_response status field
     */
    public function createOrder(array $orderData)
    {
        // Ensure all required fields are present according to API docs
        $requiredFields = ['id', 'currency_rate', 'transport_code', 'address', 'products'];
        foreach ($requiredFields as $field) {
            if (!isset($orderData[$field])) {
                $this->logger->warning("Missing required field '{$field}' in order data", [
                    'order_id' => $orderData['id'] ?? 'unknown'
                ]);
            }
        }

        $jsonData = json_encode($orderData);
        $this->logger->info('Creating order in PowerBody', ['order_id' => $orderData['id'] ?? 'unknown']);
        $result = $this->callWithRetry('dropshipping.createOrder', $jsonData);
        
        // Check response status
        if (is_array($result) && isset($result['api_response'])) {
            switch ($result['api_response']) {
                case 'SUCCESS':
                    $this->logger->info('Successfully created order in PowerBody', [
                        'order_id' => $orderData['id'] ?? 'unknown',
                        'status' => 'SUCCESS'
                    ]);
                    break;
                    
                case 'ALREADY_EXISTS':
                    $this->logger->warning('Order already exists in PowerBody', [
                        'order_id' => $orderData['id'] ?? 'unknown',
                        'status' => 'ALREADY_EXISTS'
                    ]);
                    break;
                    
                case 'FAIL':
                    $this->logger->error('Failed to create order in PowerBody', [
                        'order_id' => $orderData['id'] ?? 'unknown',
                        'status' => 'FAIL'
                    ]);
                    break;
                    
                default:
                    $this->logger->warning('Unknown response status from PowerBody', [
                        'order_id' => $orderData['id'] ?? 'unknown',
                        'status' => $result['api_response']
                    ]);
            }
        } else {
            $this->logger->error('Invalid response format from PowerBody for createOrder', [
                'order_id' => $orderData['id'] ?? 'unknown'
            ]);
        }
        
        return $result;
    }

    /**
     * Update an existing order in PowerBody
     * Response statuses:
     * - 'UPDATE_SUCCESS' - Order has been updated
     * - 'UPDATE_FAIL' - Error in updating an order
     *
     * @param array $orderData Order data formatted according to PowerBody API docs
     * @return array Response with api_response status field
     */
    public function updateOrder(array $orderData)
    {
        if (!isset($orderData['id'])) {
            $this->logger->error('Missing required field "id" in order update data');
            throw new Exception('Order ID is required for updating an order');
        }

        $jsonData = json_encode($orderData);
        $this->logger->info('Updating order in PowerBody', ['order_id' => $orderData['id']]);
        $result = $this->callWithRetry('dropshipping.updateOrder', $jsonData);
        
        // Check response status
        if (is_array($result) && isset($result['api_response'])) {
            switch ($result['api_response']) {
                case 'UPDATE_SUCCESS':
                    $this->logger->info('Successfully updated order in PowerBody', [
                        'order_id' => $orderData['id'],
                        'status' => 'UPDATE_SUCCESS'
                    ]);
                    break;
                    
                case 'UPDATE_FAIL':
                    $this->logger->error('Failed to update order in PowerBody', [
                        'order_id' => $orderData['id'],
                        'status' => 'UPDATE_FAIL'
                    ]);
                    break;
                    
                default:
                    $this->logger->warning('Unknown response status from PowerBody for updateOrder', [
                        'order_id' => $orderData['id'],
                        'status' => $result['api_response']
                    ]);
            }
        } else {
            $this->logger->error('Invalid response format from PowerBody for updateOrder', [
                'order_id' => $orderData['id']
            ]);
        }
        
        return $result;
    }

    /**
     * Get orders from PowerBody with optional date filtering
     * 
     * @param array $filter Optional filter parameters (from, to, ids)
     * @return array List of orders
     */
    public function getOrders(array $filter = [])
    {
        $jsonData = empty($filter) ? null : json_encode($filter);
        
        // Validate date format if provided
        if (isset($filter['from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter['from'])) {
            $this->logger->warning('Invalid date format for "from" parameter, should be YYYY-MM-DD', [
                'provided' => $filter['from']
            ]);
        }
        
        if (isset($filter['to']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter['to'])) {
            $this->logger->warning('Invalid date format for "to" parameter, should be YYYY-MM-DD', [
                'provided' => $filter['to']
            ]);
        }
        
        $this->logger->info('Fetching orders from PowerBody', ['filter' => $filter]);
        return $this->callWithRetry('dropshipping.getOrders', $jsonData);
    }

    /**
     * Get refund orders from PowerBody with optional date filtering
     * 
     * @param array $filter Optional filter parameters (from, to, ids)
     * @return array List of refund orders
     */
    public function getRefundOrders(array $filter = [])
    {
        $jsonData = empty($filter) ? null : json_encode($filter);
        
        // Validate date format if provided
        if (isset($filter['from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter['from'])) {
            $this->logger->warning('Invalid date format for "from" parameter, should be YYYY-MM-DD', [
                'provided' => $filter['from']
            ]);
        }
        
        if (isset($filter['to']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter['to'])) {
            $this->logger->warning('Invalid date format for "to" parameter, should be YYYY-MM-DD', [
                'provided' => $filter['to']
            ]);
        }
        
        $this->logger->info('Fetching refund orders from PowerBody', ['filter' => $filter]);
        return $this->callWithRetry('dropshipping.getRefundOrders', $jsonData);
    }

    /**
     * Insert comment for an order
     * 
     * @param array $commentData Comment data with id, comments array
     * @return array Response with comments divided by side_api and side_powerbody
     */
    public function insertComment(array $commentData)
    {
        if (!isset($commentData['id'])) {
            $this->logger->error('Missing required field "id" in comment data');
            throw new Exception('Order ID is required for adding a comment');
        }
        
        if (!isset($commentData['comments']) || !is_array($commentData['comments'])) {
            $this->logger->error('Missing or invalid "comments" array in comment data');
            throw new Exception('Comments array is required for adding comments');
        }
        
        $jsonData = json_encode($commentData);
        $this->logger->info('Inserting comment to PowerBody', ['order_id' => $commentData['id']]);
        $result = $this->callWithRetry('dropshipping.insertComment', $jsonData);
        
        // Check response status
        if (is_array($result) && isset($result['api_response'])) {
            if ($result['api_response'] === 'SUCCESS') {
                $this->logger->info('Successfully added comment to order in PowerBody', [
                    'order_id' => $commentData['id']
                ]);
            } else {
                $this->logger->warning('Failed to add comment to order in PowerBody', [
                    'order_id' => $commentData['id'],
                    'status' => $result['api_response']
                ]);
            }
        }
        
        return $result;
    }

    /**
     * Get comments for all orders from the last 7 days
     * 
     * @return array Comments for all orders
     */
    public function getComments()
    {
        $this->logger->info('Fetching comments from PowerBody');
        return $this->callWithRetry('dropshipping.getComments');
    }
    
    /**
     * Force refresh cached product info
     */
    public function refreshProductCache(int $productId): bool
    {
        try {
            $this->logger->info('Forcefully refreshing cache for product ID: ' . $productId);
            $productInfo = $this->callWithRetry('dropshipping.getProductInfo', $productId);
            
            if (!empty($productInfo)) {
                $this->saveToCache($productId, $productInfo);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            $this->logger->error('Failed to refresh product cache: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear product cache for specific product or all products
     */
    public function clearProductCache(?int $productId = null): bool
    {
        try {
            if ($productId !== null) {
                // Clear specific product cache
                $cacheFile = $this->getCacheFilePath($productId);
                if (file_exists($cacheFile) && unlink($cacheFile)) {
                    $this->logger->info('Cleared cache for product ID: ' . $productId);
                    return true;
                }
            } else {
                // Clear all product caches
                $files = glob($this->cacheDir . "/product_*.json");
                $count = 0;
                
                foreach ($files as $file) {
                    if (unlink($file)) {
                        $count++;
                    }
                }
                
                $this->logger->info("Cleared {$count} product cache files");
                return $count > 0;
            }
            
            return false;
        } catch (Exception $e) {
            $this->logger->error('Failed to clear product cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get shipping methods available from PowerBody
     * 
     * @return array Available shipping methods
     */
    public function getShippingMethods()
    {
        $this->logger->info('Fetching shipping methods from PowerBody');
        return $this->callWithRetry('dropshipping.getShippingMethod');
    }

    /**
     * Get a session token for API calls without making the call itself
     * Used for concurrent API requests
     * @return string The session token
     */
    public function prepareSession(): string
    {
        $this->ensureSession();
        return $this->session;
    }
    
    /**
     * Get the API URL for SOAP requests
     * @return string The API URL
     */
    public function getApiUrl(): string
    {
        return $this->config->get('POWERBODY_API_WSDL');
    }
    
    /**
     * Get the cache expiry period in seconds
     * @return int Cache expiry in seconds
     */
    public function getCacheExpiry(): int
    {
        return $this->cacheExpiry;
    }
    
    /**
     * Save product info to cache directly
     * Used by the concurrent fetching method
     * @param int $productId Product ID to cache
     * @param mixed $productInfo Product info to cache
     * @return bool Success indicator
     */
    public function saveProductToCache(int $productId, $productInfo): bool
    {
        try {
            $cacheFile = $this->getCacheFilePath($productId);
            
            // Create cache data with metadata
            $cacheData = [
                'cached_at' => time(),
                'expires_at' => time() + $this->cacheExpiry,
                'product_data' => $productInfo
            ];
            
            $jsonData = json_encode($cacheData, JSON_PRETTY_PRINT);
            file_put_contents($cacheFile, $jsonData);
            $this->logger->debug("Saved to cache: product_" . $productId . ".json");
            return true;
        } catch (Exception $e) {
            $this->logger->warning("Failed to save to cache: " . $e->getMessage());
            return false;
        }
    }
} 