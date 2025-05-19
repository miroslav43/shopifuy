<?php

namespace App\Sync;

use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Core\Database;
use App\Core\WorkerManager;
use App\Logger\Factory as LoggerFactory;
use Exception;
use DateTime;

class OrderSync
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
        $this->logger = LoggerFactory::getInstance('order-sync');
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
            $this->logger->info('Starting order sync');
            $startTime = microtime(true);
            
            // 1. Get orders from Shopify that need to be sent to PowerBody
            $shopifyOrders = $this->getUnfulfilledShopifyOrders();
            
            if (empty($shopifyOrders)) {
                $this->logger->info('No new Shopify orders to sync');
            } else {
                $this->logger->info('Found ' . count($shopifyOrders) . ' Shopify orders to sync');
                
                // 2. Process orders using the appropriate method
                if ($this->useWorkers && count($shopifyOrders) > 1) {
                    $this->processOrdersWithWorkers($shopifyOrders);
                } else {
                    // Process orders sequentially for small batches or if workers are disabled
                    foreach ($shopifyOrders as $order) {
                        $this->processShopifyOrder($order);
                    }
                }
            }
            
            // 3. Check PowerBody for updates to existing orders
            $this->updateExistingOrders();
            
            // 4. Update sync state
            $this->db->updateSyncState('order');
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            $this->logger->info("Order sync completed successfully in {$duration} seconds");
        } catch (Exception $e) {
            $this->logger->error('Order sync failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process orders using parallel workers
     */
    private function processOrdersWithWorkers(array $shopifyOrders): void
    {
        $orderCount = count($shopifyOrders);
        $this->logger->info("Processing {$orderCount} orders with {$this->workerCount} parallel workers");
        
        // Create worker manager
        $workerManager = new WorkerManager(
            'OrderSyncWorker',
            dirname(__DIR__, 2) . '/bin/worker.php',
            $this->workerCount
        );
        
        // Process orders in parallel
        $results = $workerManager->processItems($shopifyOrders);
        
        // Log results
        $successCount = count($results);
        $failedCount = $orderCount - $successCount;
        
        $this->logger->info("Parallel processing completed: {$successCount} successes, {$failedCount} failures");
        
        if ($failedCount > 0) {
            $this->logger->warning("Some orders failed to sync ({$failedCount} of {$orderCount})");
        }
    }

    private function getUnfulfilledShopifyOrders(): array
    {
        // Get last sync time
        $lastSyncTime = $this->db->getLastSyncTime('order');
        $this->logger->info('Last order sync time: ' . $lastSyncTime);
        
        // Format date for Shopify query
        $createdAtMin = $lastSyncTime ?? (new DateTime('-1 day'))->format('c');
        
        // Get unfulfilled orders from Shopify
        $allOrders = [];
        $params = [
            'status' => 'any',
            'fulfillment_status' => 'unfulfilled',
            'created_at_min' => $createdAtMin,
            'limit' => 250 // Shopify max limit
        ];
        
        do {
            $this->logger->debug('Fetching orders from Shopify with params: ' . json_encode($params));
            $orders = $this->shopify->getOrders($params);
            $allOrders = array_merge($allOrders, $orders);
            
            // Get link header for pagination
            $nextPageUrl = $this->shopify->getNextPageUrl();
            if ($nextPageUrl) {
                // Extract the page_info parameter from the next URL
                parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $queryParams);
                // Keep the original parameters but update with page_info
                $params['page_info'] = $queryParams['page_info'] ?? null;
                // Remove any page parameter if it exists
                unset($params['page']);
            } else {
                $params = null;
            }
        } while ($params !== null && isset($params['page_info']));
        
        // Filter orders to only those with PowerBody products
        $filteredOrders = [];
        foreach ($allOrders as $order) {
            $hasPowerbodyProducts = false;
            
            foreach ($order['line_items'] as $item) {
                if ($this->isProductFromPowerbody($item)) {
                    $hasPowerbodyProducts = true;
                    break;
                }
            }
            
            if ($hasPowerbodyProducts) {
                // Check if this order is already sent to PowerBody
                $powerbodyOrderId = $this->db->getPowerbodyOrderId($order['id']);
                if (!$powerbodyOrderId) {
                    $filteredOrders[] = $order;
                }
            }
        }
        
        return $filteredOrders;
    }

    private function isProductFromPowerbody(array $lineItem): bool
    {
        // Check if vendor is PowerBody
        if (isset($lineItem['vendor']) && $lineItem['vendor'] === 'Powerbody') {
            return true;
        }
        
        // Check if SKU exists in our product mapping
        if (!empty($lineItem['sku'])) {
            $productMapping = $this->db->getProductBysku($lineItem['sku']);
            if ($productMapping) {
                return true;
            }
        }
        
        return false;
    }

    private function processShopifyOrder(array $shopifyOrder): void
    {
        $this->logger->info('Processing Shopify order', ['order_id' => $shopifyOrder['id']]);
        
        // Map Shopify order to PowerBody format
        $powerbodyOrder = $this->mapToPowerbodyOrder($shopifyOrder);
        
        // Create order in PowerBody
        try {
            $response = $this->powerbody->createOrder($powerbodyOrder);
            
            // PowerBody API returns our request with additional 'api_response' field
            if (!isset($response['api_response'])) {
                $this->logger->error('Invalid response from PowerBody API', [
                    'order_id' => $shopifyOrder['id'],
                    'response' => $response
                ]);
                $this->saveDeadLetter('invalid_response', $shopifyOrder);
                return;
            }
            
            $apiResponse = $response['api_response'];
            
            switch ($apiResponse) {
                case 'SUCCESS':
                    // Successfully created order
                    $this->db->saveOrderMapping($shopifyOrder['id'], $powerbodyOrder['id']);
                    
                    // Update Shopify order with tags and fulfillment status
                    $this->updateShopifyOrderAfterCreation($shopifyOrder['id']);
                    
                    $this->logger->info('Successfully created order in PowerBody', [
                        'shopify_order_id' => $shopifyOrder['id'],
                        'powerbody_order_id' => $powerbodyOrder['id']
                    ]);
                    break;
                    
                case 'ALREADY_EXISTS':
                    // Order already exists in PowerBody
                    $this->logger->warning('Order already exists in PowerBody', [
                        'shopify_order_id' => $shopifyOrder['id'],
                        'powerbody_order_id' => $powerbodyOrder['id']
                    ]);
                    
                    // Save mapping anyway to prevent future retries
                    $this->db->saveOrderMapping($shopifyOrder['id'], $powerbodyOrder['id']);
                    
                    // Check current status of order in PowerBody
                    $this->checkExistingOrderStatus($shopifyOrder['id'], $powerbodyOrder['id']);
                    break;
                    
                case 'FAIL':
                    // Failed to create order
                    $this->logger->error('Failed to create order in PowerBody', [
                        'shopify_order_id' => $shopifyOrder['id'],
                        'powerbody_order_id' => $powerbodyOrder['id'],
                        'api_response' => $apiResponse
                    ]);
                    
                    // Save to dead letter for retry
                    $this->saveDeadLetter('create_failed', $shopifyOrder);
                    break;
                    
                default:
                    // Unknown response
                    $this->logger->error('Unknown response from PowerBody API', [
                        'shopify_order_id' => $shopifyOrder['id'],
                        'powerbody_order_id' => $powerbodyOrder['id'],
                        'api_response' => $apiResponse
                    ]);
                    
                    // Save to dead letter for retry
                    $this->saveDeadLetter('unknown_response', $shopifyOrder);
                    break;
            }
        } catch (Exception $e) {
            $this->logger->error('Exception while creating order in PowerBody: ' . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrder['id']
            ]);
            
            // Save to dead letter for retry
            $this->saveDeadLetter('exception', $shopifyOrder);
        }
    }

    /**
     * Check status of an existing order in PowerBody
     */
    private function checkExistingOrderStatus(int $shopifyOrderId, string $powerbodyOrderId): void
    {
        try {
            // Get order details from PowerBody using order ID
            $filter = ['ids' => $powerbodyOrderId];
            $powerbodyOrders = $this->powerbody->getOrders($filter);
            
            if (empty($powerbodyOrders)) {
                $this->logger->warning('Order exists but not returned from PowerBody API', [
                    'shopify_order_id' => $shopifyOrderId,
                    'powerbody_order_id' => $powerbodyOrderId
                ]);
                return;
            }
            
            // Find the correct order
            foreach ($powerbodyOrders as $order) {
                if (isset($order['order_id']) && $order['order_id'] === $powerbodyOrderId) {
                    // Update Shopify order with PowerBody status
                    $this->updateShopifyOrderFromPowerBody($shopifyOrderId, $order);
                    return;
                }
            }
            
            $this->logger->warning('Order ID found but no matching order returned', [
                'shopify_order_id' => $shopifyOrderId,
                'powerbody_order_id' => $powerbodyOrderId
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error checking existing order status: ' . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrderId,
                'powerbody_order_id' => $powerbodyOrderId
            ]);
        }
    }

    private function mapToPowerbodyOrder(array $shopifyOrder): array
    {
        // Extract shipping info
        $shipping = $shopifyOrder['shipping_address'] ?? null;
        
        if (!$shipping) {
            $this->logger->warning('No shipping address in Shopify order', [
                'order_id' => $shopifyOrder['id']
            ]);
            $shipping = [
                'first_name' => $shopifyOrder['customer']['first_name'] ?? '',
                'last_name' => $shopifyOrder['customer']['last_name'] ?? '',
                'address1' => '',
                'address2' => '',
                'city' => '',
                'zip' => '',
                'province' => '',
                'country' => '',
                'country_code' => '',
                'phone' => $shopifyOrder['customer']['phone'] ?? '',
                'email' => $shopifyOrder['contact_email'] ?? ''
            ];
        }
        
        // Extract products
        $products = [];
        foreach ($shopifyOrder['line_items'] as $item) {
            if ($this->isProductFromPowerbody($item)) {
                $products[] = [
                    'product_id' => $item['product_id'],
                    'sku' => $item['sku'],
                    'name' => $item['name'],
                    'qty' => $item['quantity'],
                    'price' => $item['price'],
                    'currency' => $shopifyOrder['currency'],
                    'tax' => isset($item['tax_lines'][0]) ? ($item['tax_lines'][0]['rate'] * 100) : 0
                ];
            }
        }
        
        // Get shipping price
        $shippingPrice = 0;
        foreach ($shopifyOrder['shipping_lines'] as $shippingLine) {
            $shippingPrice += (float) $shippingLine['price'];
        }
        
        // Calculate total weight
        $weight = 0;
        foreach ($shopifyOrder['line_items'] as $item) {
            if (isset($item['grams'])) {
                $weight += ($item['grams'] * $item['quantity']) / 1000; // Convert to kg
            }
        }

        // Map to PowerBody format
        return [
            'id' => 'shopify_' . $shopifyOrder['order_number'], // Use unique ID
            'status' => 'pending', // Start with pending status
            'currency_rate' => 1, // Default
            'transport_code' => 'standard', // Default shipping method
            'weight' => $weight,
            'date_add' => $shopifyOrder['created_at'],
            'comment' => 'Order from Shopify #' . $shopifyOrder['order_number'],
            'shipping_price' => $shippingPrice,
            'address' => [
                'name' => $shipping['first_name'],
                'surname' => $shipping['last_name'],
                'address1' => $shipping['address1'],
                'address2' => $shipping['address2'] ?? '',
                'address3' => '',
                'postcode' => $shipping['zip'],
                'city' => $shipping['city'],
                'county' => $shipping['province'],
                'country_name' => $shipping['country'],
                'country_code' => $shipping['country_code'],
                'phone' => $shipping['phone'],
                'email' => $shopifyOrder['contact_email']
            ],
            'products' => $products
        ];
    }

    private function updateShopifyOrderAfterCreation(int $orderId): void
    {
        try {
            // Add tag to indicate the order has been sent to PowerBody
            $order = $this->shopify->getOrder($orderId);
            
            if (!$order) {
                $this->logger->warning('Could not get order from Shopify for tagging', [
                    'order_id' => $orderId
                ]);
                return;
            }
            
            $tags = $order['tags'] ?? '';
            $tagsArray = array_map('trim', explode(',', $tags));
            
            if (!in_array('powerbody-dropshipping', $tagsArray)) {
                $tagsArray[] = 'powerbody-dropshipping';
            }
            
            $updatedTags = implode(', ', $tagsArray);
            
            // Update order tags and set fulfillment status to 'on-hold'
            $updateData = [
                'tags' => $updatedTags,
                'note' => ($order['note'] ? $order['note'] . "\n\n" : '') . 'Order sent to PowerBody Dropshipping'
            ];
            
            $this->shopify->updateOrder($orderId, $updateData);
            
            // Create a fulfillment with 'on-hold' status
            $fulfillmentData = [
                'location_id' => $this->shopify->getLocationId(),
                'status' => 'open',
                'notify_customer' => false,
                'tracking_info' => [
                    'company' => 'PowerBody Dropshipping',
                    'number' => 'Awaiting processing'
                ]
            ];
            
            $this->shopify->createFulfillment($orderId, $fulfillmentData);
            
            $this->logger->info('Updated Shopify order status to on-hold', [
                'order_id' => $orderId
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update Shopify order status: ' . $e->getMessage(), [
                'order_id' => $orderId
            ]);
        }
    }

    private function updateExistingOrders(): void
    {
        $this->logger->info('Checking for updates to existing orders');
        
        try {
            // Get orders from PowerBody (last 7 days)
            $fromDate = (new DateTime('-7 days'))->format('Y-m-d');
            $toDate = (new DateTime())->format('Y-m-d');
            
            $filter = [
                'from' => $fromDate,
                'to' => $toDate
            ];
            
            $powerbodyOrders = $this->powerbody->getOrders($filter);
            
            if (empty($powerbodyOrders)) {
                $this->logger->info('No orders returned from PowerBody API');
                return;
            }
            
            $this->logger->info('Fetched ' . count($powerbodyOrders) . ' orders from PowerBody');
            
            foreach ($powerbodyOrders as $pbOrder) {
                if (empty($pbOrder['order_id']) || !isset($pbOrder['status'])) {
                    continue;
                }
                
                // Get corresponding Shopify order
                $shopifyOrderId = $this->db->getShopifyOrderId($pbOrder['order_id']);
                
                if (!$shopifyOrderId) {
                    // If not in our database, check if it's a shopify_XXX format ID
                    if (strpos($pbOrder['order_id'], 'shopify_') === 0) {
                        $orderNumber = substr($pbOrder['order_id'], 8);
                        
                        // Try to find by order number
                        $params = [
                            'name' => '#' . $orderNumber,
                            'status' => 'any'
                        ];
                        
                        $matchingOrders = $this->shopify->getOrders($params);
                        
                        if (!empty($matchingOrders)) {
                            $shopifyOrderId = $matchingOrders[0]['id'];
                            $this->db->saveOrderMapping($shopifyOrderId, $pbOrder['order_id']);
                        }
                    }
                }
                
                if ($shopifyOrderId) {
                    $this->updateShopifyOrderFromPowerBody($shopifyOrderId, $pbOrder);
                }
            }
            
            $this->logger->info('Finished checking for order updates');
        } catch (Exception $e) {
            $this->logger->error('Failed to update existing orders: ' . $e->getMessage());
        }
    }

    private function updateShopifyOrderFromPowerBody(int $shopifyOrderId, array $powerbodyOrder): void
    {
        $this->logger->info('Updating Shopify order from PowerBody', [
            'shopify_order_id' => $shopifyOrderId,
            'powerbody_order_id' => $powerbodyOrder['order_id']
        ]);
        
        try {
            $shopifyOrder = $this->shopify->getOrder($shopifyOrderId);
            
            if (!$shopifyOrder) {
                $this->logger->warning('Could not get order from Shopify for update', [
                    'order_id' => $shopifyOrderId
                ]);
                return;
            }
            
            // Update tracking information if available
            if (!empty($powerbodyOrder['tracking_number'])) {
                $this->updateShopifyOrderTracking($shopifyOrderId, $powerbodyOrder['tracking_number']);
            }
            
            // Update order status
            if (!empty($powerbodyOrder['status'])) {
                $this->updateShopifyOrderStatus($shopifyOrderId, $powerbodyOrder['status']);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to update Shopify order from PowerBody: ' . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrderId
            ]);
        }
    }

    private function updateShopifyOrderTracking(int $orderId, string $trackingNumber): void
    {
        try {
            // Look for existing fulfillments
            $order = $this->shopify->getOrder($orderId);
            
            if (!$order) {
                $this->logger->warning('Could not get order for tracking update', [
                    'order_id' => $orderId
                ]);
                return;
            }
            
            // Check if tracking is already set
            $fulfillments = $order['fulfillments'] ?? [];
            
            foreach ($fulfillments as $fulfillment) {
                if ($fulfillment['tracking_number'] === $trackingNumber) {
                    // Tracking already set
                    return;
                }
            }
            
            // If no fulfillments or tracking is different, create/update fulfillment
            $fulfillmentData = [
                'location_id' => $this->shopify->getLocationId(),
                'status' => 'success',
                'notify_customer' => true,
                'tracking_info' => [
                    'number' => $trackingNumber,
                    'url' => 'https://track-trace.com/' . $trackingNumber,
                    'company' => 'PowerBody Shipping'
                ]
            ];
            
            if (!empty($fulfillments)) {
                // Update existing fulfillment
                $this->shopify->updateFulfillment($fulfillments[0]['id'], $fulfillmentData);
            } else {
                // Create new fulfillment
                $this->shopify->createFulfillment($orderId, $fulfillmentData);
            }
            
            // Add tracking note to order
            $note = "Tracking number updated: {$trackingNumber}";
            $this->shopify->addNoteToOrder($orderId, $note);
            
            $this->logger->info('Updated Shopify order tracking', [
                'order_id' => $orderId,
                'tracking_number' => $trackingNumber
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update Shopify order tracking: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'tracking_number' => $trackingNumber
            ]);
        }
    }

    private function updateShopifyOrderStatus(int $orderId, string $powerbodyStatus): void
    {
        try {
            // Map PowerBody status to Shopify fulfillment status
            $statusMap = [
                'pending' => 'open',
                'processing' => 'open',
                'complete' => 'success',
                'cancelled' => 'cancelled'
            ];
            
            $shopifyStatus = $statusMap[$powerbodyStatus] ?? 'open';
            
            // Get the order
            $order = $this->shopify->getOrder($orderId);
            
            if (!$order) {
                $this->logger->warning('Could not get order for status update', [
                    'order_id' => $orderId
                ]);
                return;
            }
            
            // Update the order
            $updateData = [
                'note' => ($order['note'] ? $order['note'] . "\n\n" : '') . 
                          'PowerBody order status updated to: ' . $powerbodyStatus
            ];
            
            $this->shopify->updateOrder($orderId, $updateData);
            
            // Update fulfillment status if applicable
            $fulfillments = $order['fulfillments'] ?? [];
            
            if (!empty($fulfillments) && $shopifyStatus !== 'open') {
                foreach ($fulfillments as $fulfillment) {
                    if ($fulfillment['status'] !== $shopifyStatus) {
                        $this->shopify->updateFulfillment(
                            $fulfillment['id'],
                            ['status' => $shopifyStatus]
                        );
                    }
                }
            }
            
            $this->logger->info('Updated Shopify order status', [
                'order_id' => $orderId,
                'powerbody_status' => $powerbodyStatus,
                'shopify_status' => $shopifyStatus
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update Shopify order status: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'powerbody_status' => $powerbodyStatus
            ]);
        }
    }

    private function saveDeadLetter(string $reason, array $order): void
    {
        $filename = $this->storageDir . '/dead_letter_order_' . $reason . '_' . $order['id'] . '_' . date('YmdHis') . '.json';
        file_put_contents($filename, json_encode($order, JSON_PRETTY_PRINT));
        $this->logger->warning('Saved failed order to dead letter file: ' . $filename);
    }

    /**
     * Update existing order in PowerBody
     */
    private function updateOrderInPowerBody(int $shopifyOrderId, string $powerbodyOrderId, array $orderData): bool
    {
        try {
            // Ensure order ID is set in update data
            $orderData['id'] = $powerbodyOrderId;
            
            $response = $this->powerbody->updateOrder($orderData);
            
            if (!isset($response['api_response'])) {
                $this->logger->error('Invalid response from PowerBody API for updateOrder', [
                    'shopify_order_id' => $shopifyOrderId,
                    'powerbody_order_id' => $powerbodyOrderId
                ]);
                return false;
            }
            
            switch ($response['api_response']) {
                case 'UPDATE_SUCCESS':
                    $this->logger->info('Successfully updated order in PowerBody', [
                        'shopify_order_id' => $shopifyOrderId,
                        'powerbody_order_id' => $powerbodyOrderId
                    ]);
                    return true;
                    
                case 'UPDATE_FAIL':
                    $this->logger->error('Failed to update order in PowerBody', [
                        'shopify_order_id' => $shopifyOrderId,
                        'powerbody_order_id' => $powerbodyOrderId,
                        'api_response' => 'UPDATE_FAIL'
                    ]);
                    return false;
                    
                default:
                    $this->logger->warning('Unknown response from PowerBody API for updateOrder', [
                        'shopify_order_id' => $shopifyOrderId,
                        'powerbody_order_id' => $powerbodyOrderId,
                        'api_response' => $response['api_response']
                    ]);
                    return false;
            }
        } catch (Exception $e) {
            $this->logger->error('Exception while updating order in PowerBody: ' . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrderId,
                'powerbody_order_id' => $powerbodyOrderId
            ]);
            return false;
        }
    }
} 