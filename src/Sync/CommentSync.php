<?php

namespace App\Sync;

use App\Core\PowerBodyLink;
use App\Core\ShopifyLink;
use App\Core\Database;
use App\Logger\Factory as LoggerFactory;
use Exception;
use DateTime;

class CommentSync
{
    private PowerBodyLink $powerbody;
    private ShopifyLink $shopify;
    private Database $db;
    private $logger;
    private string $storageDir;

    public function __construct()
    {
        $this->logger = LoggerFactory::getInstance('comment-sync');
        $this->powerbody = new PowerBodyLink();
        $this->shopify = new ShopifyLink();
        $this->db = Database::getInstance();
        $this->storageDir = dirname(__DIR__, 2) . '/storage';
    }

    public function sync(): void
    {
        try {
            $this->logger->info('Starting comment sync');
            
            // 1. Get comments from PowerBody
            $powerbodyComments = $this->getCommentsFromPowerbody();
            
            // 2. Process comments and sync to Shopify
            $this->syncCommentsToShopify($powerbodyComments);
            
            // 3. Get comments from Shopify and sync to PowerBody
            $this->syncCommentsFromShopify();
            
            // 4. Update sync state
            $this->db->updateSyncState('comment');
            
            $this->logger->info('Comment sync completed successfully');
        } catch (Exception $e) {
            $this->logger->error('Comment sync failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get comments from PowerBody API
     * 
     * @return array PowerBody comments
     */
    private function getCommentsFromPowerbody(): array
    {
        $this->logger->info('Fetching comments from PowerBody');
        
        try {
            $comments = $this->powerbody->getComments();
            
            if (!is_array($comments)) {
                $this->logger->error('Expected array from PowerBody API, got ' . gettype($comments));
                return [];
            }
            
            $this->logger->info('Fetched ' . count($comments) . ' comment entries from PowerBody');
            return $comments;
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch comments from PowerBody: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync comments from PowerBody to Shopify
     * 
     * @param array $powerbodyComments Comments from PowerBody
     */
    private function syncCommentsToShopify(array $powerbodyComments): void
    {
        if (empty($powerbodyComments)) {
            $this->logger->info('No PowerBody comments to sync to Shopify');
            return;
        }
        
        $this->logger->info('Syncing PowerBody comments to Shopify');
        $processedCount = 0;
        
        foreach ($powerbodyComments as $commentData) {
            if (!isset($commentData['id']) || !isset($commentData['comments'])) {
                $this->logger->warning('Invalid comment data structure, skipping');
                continue;
            }
            
            $powerbodyOrderId = $commentData['id'];
            $shopifyOrderId = $this->db->getShopifyOrderId($powerbodyOrderId);
            
            if (!$shopifyOrderId) {
                $this->logger->debug('No matching Shopify order for PowerBody order ID: ' . $powerbodyOrderId);
                continue;
            }
            
            // Check if there are any PowerBody-side comments
            if (!isset($commentData['comments']['side_powerbody']) || empty($commentData['comments']['side_powerbody'])) {
                $this->logger->debug('No PowerBody-side comments for order: ' . $powerbodyOrderId);
                continue;
            }
            
            // Process each PowerBody comment
            foreach ($commentData['comments']['side_powerbody'] as $comment) {
                // Check if comment is already synced to Shopify
                $commentId = $this->getCommentIdentifier($comment);
                $isCommentSynced = $this->db->isCommentSynced('powerbody_to_shopify', $commentId);
                
                if ($isCommentSynced) {
                    $this->logger->debug('Comment already synced to Shopify, skipping', [
                        'comment_id' => $commentId,
                        'order_id' => $shopifyOrderId
                    ]);
                    continue;
                }
                
                // Add comment to Shopify order
                $commentText = '[PowerBody: ' . $comment['author_name'] . '] ' . $comment['comment'];
                
                try {
                    $result = $this->shopify->addNoteToOrder($shopifyOrderId, $commentText);
                    
                    if ($result) {
                        // Mark comment as synced
                        $this->db->markCommentSynced('powerbody_to_shopify', $commentId);
                        $processedCount++;
                        
                        $this->logger->info('Added PowerBody comment to Shopify order', [
                            'shopify_order_id' => $shopifyOrderId,
                            'powerbody_order_id' => $powerbodyOrderId
                        ]);
                    } else {
                        $this->logger->warning('Failed to add comment to Shopify order', [
                            'shopify_order_id' => $shopifyOrderId
                        ]);
                    }
                } catch (Exception $e) {
                    $this->logger->error('Error adding comment to Shopify order: ' . $e->getMessage(), [
                        'shopify_order_id' => $shopifyOrderId
                    ]);
                }
            }
        }
        
        $this->logger->info('Synced ' . $processedCount . ' PowerBody comments to Shopify');
    }

    /**
     * Sync comments from Shopify to PowerBody
     */
    private function syncCommentsFromShopify(): void
    {
        $this->logger->info('Syncing Shopify comments to PowerBody');
        
        // Get last sync time
        $lastSyncTime = $this->db->getLastSyncTime('comment');
        $createdAtMin = $lastSyncTime ?? (new DateTime('-1 day'))->format('c');
        
        // Get recent orders with notes from Shopify
        $params = [
            'updated_at_min' => $createdAtMin,
            'limit' => 250,
            'fields' => 'id,name,order_number,note,updated_at'
        ];
        
        $shopifyOrders = $this->shopify->getOrders($params);
        $processedCount = 0;
        
        foreach ($shopifyOrders as $order) {
            // Skip orders without notes
            if (empty($order['note'])) {
                continue;
            }
            
            // Get PowerBody order ID
            $powerbodyOrderId = $this->db->getPowerbodyOrderId($order['id']);
            
            if (!$powerbodyOrderId) {
                $this->logger->debug('No matching PowerBody order for Shopify order ID: ' . $order['id']);
                continue;
            }
            
            // Check if note is already synced to PowerBody
            $noteId = 'shopify_' . $order['id'] . '_' . md5($order['note']);
            $isNoteSynced = $this->db->isCommentSynced('shopify_to_powerbody', $noteId);
            
            if ($isNoteSynced) {
                $this->logger->debug('Note already synced to PowerBody, skipping', [
                    'note_id' => $noteId,
                    'order_id' => $order['id']
                ]);
                continue;
            }
            
            // Add comment to PowerBody order
            $commentData = [
                'id' => $powerbodyOrderId,
                'comments' => [
                    [
                        'author_name' => 'Shopify System',
                        'comment' => $order['note'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ]
            ];
            
            try {
                $result = $this->powerbody->insertComment($commentData);
                
                if (isset($result['api_response']) && $result['api_response'] === 'SUCCESS') {
                    // Mark note as synced
                    $this->db->markCommentSynced('shopify_to_powerbody', $noteId);
                    $processedCount++;
                    
                    $this->logger->info('Added Shopify note to PowerBody order', [
                        'shopify_order_id' => $order['id'],
                        'powerbody_order_id' => $powerbodyOrderId
                    ]);
                } else {
                    $this->logger->warning('Failed to add note to PowerBody order', [
                        'powerbody_order_id' => $powerbodyOrderId,
                        'response' => json_encode($result)
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->error('Error adding note to PowerBody order: ' . $e->getMessage(), [
                    'powerbody_order_id' => $powerbodyOrderId
                ]);
            }
        }
        
        $this->logger->info('Synced ' . $processedCount . ' Shopify notes to PowerBody');
    }

    /**
     * Generate a unique identifier for a comment
     * 
     * @param array $comment Comment data
     * @return string Unique identifier
     */
    private function getCommentIdentifier(array $comment): string
    {
        return 'powerbody_' . md5($comment['author_name'] . '_' . $comment['comment'] . '_' . $comment['created_at']);
    }
} 