<?php

namespace App\Sync;

use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Core\Database;
use App\Logger\Factory as LoggerFactory;
use Exception;
use DateTime;

class ReturnSync
{
    private PowerBodyLink $powerbody;
    private ShopifyLink $shopify;
    private Database $db;
    private $logger;
    private string $storageDir;

    public function __construct()
    {
        $this->logger = LoggerFactory::getInstance('return-sync');
        $this->powerbody = new PowerBodyLink();
        $this->shopify = new ShopifyLink();
        $this->db = Database::getInstance();
        $this->storageDir = dirname(__DIR__, 2) . '/storage';
    }

    public function sync(): void
    {
        try {
            $this->logger->info('Starting return/refund sync');
            
            // 1. Get refund orders from PowerBody
            $powerbodyRefunds = $this->getRefundsFromPowerbody();
            
            // 2. Process refunds and create them in Shopify if needed
            $this->syncRefundsToShopify($powerbodyRefunds);
            
            // 3. Update sync state
            $this->db->updateSyncState('refund');
            
            $this->logger->info('Return/refund sync completed successfully');
        } catch (Exception $e) {
            $this->logger->error('Return/refund sync failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get refund orders from PowerBody API
     * 
     * @return array PowerBody refund orders
     */
    private function getRefundsFromPowerbody(): array
    {
        $this->logger->info('Fetching refund orders from PowerBody');
        
        // Get last sync time
        $lastSyncTime = $this->db->getLastSyncTime('refund');
        $fromDate = $lastSyncTime ? new DateTime($lastSyncTime) : new DateTime('-7 days');
        
        $filter = [
            'from' => $fromDate->format('Y-m-d'),
            'to' => (new DateTime())->format('Y-m-d')
        ];
        
        try {
            $refunds = $this->powerbody->getRefundOrders($filter);
            
            if (!is_array($refunds)) {
                $this->logger->error('Expected array from PowerBody API, got ' . gettype($refunds));
                return [];
            }
            
            $this->logger->info('Fetched ' . count($refunds) . ' refund orders from PowerBody');
            return $refunds;
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch refund orders from PowerBody: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync refunds from PowerBody to Shopify
     * 
     * @param array $powerbodyRefunds Refund orders from PowerBody
     */
    private function syncRefundsToShopify(array $powerbodyRefunds): void
    {
        if (empty($powerbodyRefunds)) {
            $this->logger->info('No PowerBody refunds to sync to Shopify');
            return;
        }
        
        $this->logger->info('Syncing PowerBody refunds to Shopify');
        $processedCount = 0;
        
        foreach ($powerbodyRefunds as $refund) {
            if (!isset($refund['parent_id']) || !isset($refund['items']) || !isset($refund['refund_grand_total'])) {
                $this->logger->warning('Invalid refund data structure, skipping');
                continue;
            }
            
            $powerbodyOrderId = $refund['parent_id'];
            $shopifyOrderId = $this->db->getShopifyOrderId($powerbodyOrderId);
            
            if (!$shopifyOrderId) {
                $this->logger->debug('No matching Shopify order for PowerBody order ID: ' . $powerbodyOrderId);
                continue;
            }
            
            // Generate a unique ID for this refund to track if it's been processed
            $refundId = $powerbodyOrderId . '_' . md5(json_encode($refund));
            $shopifyRefundId = $this->db->getShopifyRefundId($refundId);
            
            if ($shopifyRefundId) {
                $this->logger->debug('Refund already processed in Shopify, skipping', [
                    'powerbody_order_id' => $powerbodyOrderId,
                    'shopify_order_id' => $shopifyOrderId,
                    'shopify_refund_id' => $shopifyRefundId
                ]);
                continue;
            }
            
            // Get the Shopify order to prepare the refund
            try {
                $shopifyOrder = $this->shopify->getOrder($shopifyOrderId);
                
                if (!$shopifyOrder) {
                    $this->logger->warning('Could not find Shopify order: ' . $shopifyOrderId);
                    continue;
                }
                
                // Prepare the refund data for Shopify
                $refundData = $this->prepareShopifyRefundData($refund, $shopifyOrder);
                
                if (empty($refundData['refund']['refund_line_items'])) {
                    $this->logger->warning('No matching line items found for refund', [
                        'shopify_order_id' => $shopifyOrderId
                    ]);
                    continue;
                }
                
                // Create the refund in Shopify
                $shopifyRefundResult = $this->shopify->createRefund($shopifyOrderId, $refundData);
                
                if ($shopifyRefundResult && isset($shopifyRefundResult['id'])) {
                    // Save the mapping
                    $this->db->saveRefundMapping($shopifyRefundResult['id'], $refundId);
                    $processedCount++;
                    
                    $this->logger->info('Successfully created refund in Shopify', [
                        'shopify_order_id' => $shopifyOrderId,
                        'shopify_refund_id' => $shopifyRefundResult['id'],
                        'powerbody_order_id' => $powerbodyOrderId
                    ]);
                } else {
                    $this->logger->error('Failed to create refund in Shopify', [
                        'shopify_order_id' => $shopifyOrderId,
                        'response' => json_encode($shopifyRefundResult)
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->error('Error creating refund in Shopify: ' . $e->getMessage(), [
                    'shopify_order_id' => $shopifyOrderId
                ]);
            }
        }
        
        $this->logger->info('Synced ' . $processedCount . ' PowerBody refunds to Shopify');
    }

    /**
     * Prepare refund data for Shopify API
     * 
     * @param array $powerbodyRefund PowerBody refund data
     * @param array $shopifyOrder Shopify order data
     * @return array Shopify refund data
     */
    private function prepareShopifyRefundData(array $powerbodyRefund, array $shopifyOrder): array
    {
        $refundLineItems = [];
        $refundShipping = false;
        $shippingAmount = 0;
        
        // Check if we need to refund shipping
        if (isset($powerbodyRefund['is_refund_shipping']) && $powerbodyRefund['is_refund_shipping']) {
            $refundShipping = true;
            $shippingAmount = $powerbodyRefund['refund_shipping'] ?? 0;
        }
        
        // Match line items by SKU
        foreach ($powerbodyRefund['items'] as $refundItem) {
            if (!isset($refundItem['sku']) || !isset($refundItem['qty_refunded'])) {
                continue;
            }
            
            $sku = $refundItem['sku'];
            $qtyRefunded = (int)$refundItem['qty_refunded'];
            
            if ($qtyRefunded <= 0) {
                continue;
            }
            
            // Find matching line item in Shopify order
            foreach ($shopifyOrder['line_items'] as $lineItem) {
                if (isset($lineItem['sku']) && $lineItem['sku'] === $sku) {
                    $refundLineItems[] = [
                        'line_item_id' => $lineItem['id'],
                        'quantity' => $qtyRefunded,
                        'restock_type' => 'return' // Assuming items are physically returned
                    ];
                    break;
                }
            }
        }
        
        // Prepare shipping refund
        $shippingRefund = [];
        if ($refundShipping && $shippingAmount > 0) {
            foreach ($shopifyOrder['shipping_lines'] as $shippingLine) {
                $shippingRefund[] = [
                    'shipping_line_id' => $shippingLine['id'],
                    'amount' => $shippingAmount
                ];
                break; // Only refund the first shipping line
            }
        }
        
        // Prepare the final refund data
        $refundData = [
            'refund' => [
                'notify' => true,
                'refund_line_items' => $refundLineItems
            ]
        ];
        
        if (!empty($shippingRefund)) {
            $refundData['refund']['shipping'] = $shippingRefund;
        }
        
        // Add note about PowerBody refund
        $refundData['refund']['note'] = 'Refund from PowerBody. Original order ID: ' . $powerbodyRefund['parent_id'];
        
        return $refundData;
    }
} 