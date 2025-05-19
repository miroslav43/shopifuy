<?php

namespace App\Sync;

use App\Core\Worker;
use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Core\Database;
use Exception;

class OrderSyncWorker extends Worker
{
    private PowerBodyLink $powerbody;
    private ShopifyLink $shopify;
    private Database $db;
    private array $failedItems = [];
    private array $successItems = [];
    
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
     * Run the worker on the provided orders
     */
    public function run(array $orders): array
    {
        $this->initialize($orders);
        $this->logger->info("Starting order sync worker #{$this->workerId} with " . count($orders) . " orders");
        
        foreach ($orders as $order) {
            if ($this->shouldStop) {
                $this->logger->info("Worker #{$this->workerId} stopping as requested");
                break;
            }
            
            try {
                $result = $this->processItem($order);
                if ($result) {
                    $this->successItems[] = $order;
                } else {
                    $this->failedItems[] = $order;
                }
            } catch (Exception $e) {
                $this->logger->error("Error processing order: " . $e->getMessage(), [
                    'order_id' => $order['id'] ?? 'unknown'
                ]);
                $this->failedItems[] = $order;
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
     * Process a single order
     */
    protected function processItem($order): bool
    {
        if (!isset($order['id'])) {
            $this->logger->warning("Skipping order without ID");
            return false;
        }
        
        $shopifyOrderId = $order['id'];
        
        // First check if this order is already synced to PowerBody
        $powerbodyOrderId = $this->db->getPowerbodyOrderId($shopifyOrderId);
        
        if ($powerbodyOrderId) {
            // This order is already synced, check for updates
            return $this->updateExistingOrder($order, $powerbodyOrderId);
        } else {
            // This is a new order, create it in PowerBody
            return $this->createNewOrder($order);
        }
    }
    
    /**
     * Create a new order in PowerBody
     */
    private function createNewOrder(array $shopifyOrder): bool
    {
        $this->logger->debug("Creating new order in PowerBody for Shopify order {$shopifyOrder['id']}");
        
        try {
            // Map Shopify order to PowerBody format
            $powerbodyOrder = $this->mapToPowerbodyOrder($shopifyOrder);
            
            // Create order in PowerBody
            $result = $this->powerbody->createOrder($powerbodyOrder);
            
            if (empty($result) || !isset($result['order_id'])) {
                $this->logger->warning("Empty or invalid result from PowerBody API for order creation");
                return false;
            }
            
            $powerbodyOrderId = $result['order_id'];
            
            // Save mapping to database
            $this->db->saveOrderMapping($shopifyOrder['id'], $powerbodyOrderId);
            
            $this->logger->info("Successfully created PowerBody order {$powerbodyOrderId} for Shopify order {$shopifyOrder['id']}");
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to create PowerBody order: " . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrder['id']
            ]);
            return false;
        }
    }
    
    /**
     * Update an existing order in PowerBody
     */
    private function updateExistingOrder(array $shopifyOrder, string $powerbodyOrderId): bool
    {
        $this->logger->debug("Updating PowerBody order {$powerbodyOrderId} for Shopify order {$shopifyOrder['id']}");
        
        try {
            // First check if there are any updates needed (e.g., fulfillment status, cancellations)
            if ($this->isOrderFulfilled($shopifyOrder) && !$this->isOrderFulfilledInPowerbody($powerbodyOrderId)) {
                // Order has been fulfilled in Shopify but not in PowerBody
                return $this->fulfillOrderInPowerbody($powerbodyOrderId, $shopifyOrder);
            }
            
            if ($this->isOrderCancelled($shopifyOrder) && !$this->isOrderCancelledInPowerbody($powerbodyOrderId)) {
                // Order has been cancelled in Shopify but not in PowerBody
                return $this->cancelOrderInPowerbody($powerbodyOrderId, $shopifyOrder);
            }
            
            // No updates needed
            $this->logger->debug("No updates needed for PowerBody order {$powerbodyOrderId}");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to update PowerBody order: " . $e->getMessage(), [
                'shopify_order_id' => $shopifyOrder['id'],
                'powerbody_order_id' => $powerbodyOrderId
            ]);
            return false;
        }
    }
    
    /**
     * Map Shopify order to PowerBody format
     */
    private function mapToPowerbodyOrder(array $shopifyOrder): array
    {
        // Get customer information
        $customer = [
            'email' => $shopifyOrder['email'] ?? '',
            'first_name' => $shopifyOrder['billing_address']['first_name'] ?? '',
            'last_name' => $shopifyOrder['billing_address']['last_name'] ?? '',
            'phone' => $shopifyOrder['billing_address']['phone'] ?? ''
        ];
        
        // Get shipping address
        $shippingAddress = $shopifyOrder['shipping_address'] ?? $shopifyOrder['billing_address'] ?? [];
        
        $address = [
            'address1' => $shippingAddress['address1'] ?? '',
            'address2' => $shippingAddress['address2'] ?? '',
            'city' => $shippingAddress['city'] ?? '',
            'zip' => $shippingAddress['zip'] ?? '',
            'province' => $shippingAddress['province'] ?? '',
            'country' => $shippingAddress['country_code'] ?? ''
        ];
        
        // Get line items
        $lineItems = [];
        foreach ($shopifyOrder['line_items'] as $item) {
            $sku = $item['sku'] ?? '';
            
            // Find product in PowerBody by SKU
            $productMap = $this->db->getProductBysku($sku);
            
            if ($productMap) {
                $lineItems[] = [
                    'product_id' => $productMap['powerbody_product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price']
                ];
            } else {
                $this->logger->warning("Product with SKU {$sku} not found in mapping database");
            }
        }
        
        // Build order data
        $orderData = [
            'customer' => $customer,
            'shipping_address' => $address,
            'line_items' => $lineItems,
            'shipping_method' => $shopifyOrder['shipping_lines'][0]['title'] ?? 'Standard',
            'total_price' => $shopifyOrder['total_price'] ?? 0,
            'note' => $shopifyOrder['note'] ?? '',
            'shopify_order_id' => $shopifyOrder['id'],
            'shopify_order_number' => $shopifyOrder['order_number'] ?? $shopifyOrder['id']
        ];
        
        return $orderData;
    }
    
    /**
     * Check if order is fulfilled in Shopify
     */
    private function isOrderFulfilled(array $shopifyOrder): bool
    {
        if (isset($shopifyOrder['fulfillment_status']) && $shopifyOrder['fulfillment_status'] === 'fulfilled') {
            return true;
        }
        
        if (isset($shopifyOrder['fulfillments']) && !empty($shopifyOrder['fulfillments'])) {
            foreach ($shopifyOrder['fulfillments'] as $fulfillment) {
                if ($fulfillment['status'] === 'success') {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if order is cancelled in Shopify
     */
    private function isOrderCancelled(array $shopifyOrder): bool
    {
        return isset($shopifyOrder['cancelled_at']) && !empty($shopifyOrder['cancelled_at']);
    }
    
    /**
     * Check if order is fulfilled in PowerBody
     */
    private function isOrderFulfilledInPowerbody(string $powerbodyOrderId): bool
    {
        try {
            $orderDetails = $this->powerbody->getOrders(['order_id' => $powerbodyOrderId]);
            
            if (!empty($orderDetails)) {
                $order = $orderDetails[0];
                return $order['status'] === 'fulfilled' || $order['status'] === 'completed';
            }
            
            return false;
        } catch (Exception $e) {
            $this->logger->warning("Error checking PowerBody order status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if order is cancelled in PowerBody
     */
    private function isOrderCancelledInPowerbody(string $powerbodyOrderId): bool
    {
        try {
            $orderDetails = $this->powerbody->getOrders(['order_id' => $powerbodyOrderId]);
            
            if (!empty($orderDetails)) {
                $order = $orderDetails[0];
                return $order['status'] === 'cancelled';
            }
            
            return false;
        } catch (Exception $e) {
            $this->logger->warning("Error checking PowerBody order status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Fulfill order in PowerBody
     */
    private function fulfillOrderInPowerbody(string $powerbodyOrderId, array $shopifyOrder): bool
    {
        try {
            $updateData = [
                'order_id' => $powerbodyOrderId,
                'status' => 'fulfilled'
            ];
            
            // If there's tracking information, add it
            if (isset($shopifyOrder['fulfillments'][0]['tracking_number'])) {
                $updateData['tracking_number'] = $shopifyOrder['fulfillments'][0]['tracking_number'];
                $updateData['tracking_company'] = $shopifyOrder['fulfillments'][0]['tracking_company'] ?? '';
            }
            
            $result = $this->powerbody->updateOrder($updateData);
            
            if (empty($result)) {
                $this->logger->warning("Empty result from PowerBody API for order fulfillment");
                return false;
            }
            
            $this->logger->info("Successfully fulfilled PowerBody order {$powerbodyOrderId}");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to fulfill PowerBody order: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cancel order in PowerBody
     */
    private function cancelOrderInPowerbody(string $powerbodyOrderId, array $shopifyOrder): bool
    {
        try {
            $updateData = [
                'order_id' => $powerbodyOrderId,
                'status' => 'cancelled',
                'cancel_reason' => $shopifyOrder['cancel_reason'] ?? 'Cancelled in Shopify'
            ];
            
            $result = $this->powerbody->updateOrder($updateData);
            
            if (empty($result)) {
                $this->logger->warning("Empty result from PowerBody API for order cancellation");
                return false;
            }
            
            $this->logger->info("Successfully cancelled PowerBody order {$powerbodyOrderId}");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to cancel PowerBody order: " . $e->getMessage());
            return false;
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