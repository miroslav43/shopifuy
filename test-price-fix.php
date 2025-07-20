<?php
/**
 * Test script to validate the price calculation fix
 * This script simulates the price recalculation logic
 */

function recalculateLineItemPricesTest(array $shopifyOrder): array
{
    $totalPrice = (float)($shopifyOrder['total_price'] ?? 0);
    $lineItems = $shopifyOrder['line_items'] ?? [];
    
    if ($totalPrice <= 0 || empty($lineItems)) {
        echo "Cannot recalculate prices: total price is zero or no line items\n";
        return $shopifyOrder;
    }
    
    // Check if any line items have zero prices
    $hasZeroPrices = false;
    $zeroCount = 0;
    foreach ($lineItems as $item) {
        if (empty($item['price']) || $item['price'] === '0.00') {
            $hasZeroPrices = true;
            $zeroCount++;
        }
    }
    
    if (!$hasZeroPrices) {
        echo "All line items already have prices, no recalculation needed\n";
        return $shopifyOrder;
    }
    
    echo "Recalculating line item prices for order with total: €{$totalPrice}\n";
    echo "Items with zero prices: {$zeroCount} out of " . count($lineItems) . "\n\n";
    
    // Calculate total quantity for proportional distribution
    $totalQuantity = 0;
    foreach ($lineItems as $item) {
        $totalQuantity += (int)($item['quantity'] ?? 0);
    }
    
    if ($totalQuantity === 0) {
        echo "Total quantity is zero, cannot distribute prices\n";
        return $shopifyOrder;
    }
    
    // Recalculate prices proportionally
    $calculatedTotal = 0;
    $processedItems = 0;
    $totalItemsToProcess = 0;
    
    // Count how many items need price calculation
    foreach ($shopifyOrder['line_items'] as $item) {
        if (empty($item['price']) || $item['price'] === '0.00') {
            $totalItemsToProcess++;
        }
    }
    
    foreach ($shopifyOrder['line_items'] as &$item) {
        if (empty($item['price']) || $item['price'] === '0.00') {
            $itemQuantity = (int)($item['quantity'] ?? 0);
            $processedItems++;
            
            if ($processedItems === $totalItemsToProcess) {
                // For the last item, adjust to match the exact total
                $item['price'] = number_format($totalPrice - $calculatedTotal, 2, '.', '');
            } else {
                $proportionalPrice = $totalPrice * ($itemQuantity / $totalQuantity);
                $item['price'] = number_format($proportionalPrice, 2, '.', '');
                $calculatedTotal += (float)$item['price'];
            }
            
            $oldPrice = '0.00';
            echo "SKU: {$item['sku']}, Qty: {$itemQuantity}, Old Price: €{$oldPrice}, New Price: €{$item['price']}\n";
        }
    }
    
    return $shopifyOrder;
}

// Test with sample order data from the review file
$testOrder = [
    'id' => 11583809618269,
    'name' => '#1009',
    'total_price' => '141.18',
    'line_items' => [
        [
            'name' => 'Carb X, Orange Burst (EAN5056555206362) - 1200 grams',
            'sku' => 'P48213',
            'quantity' => 1,
            'price' => '0.00'
        ],
        [
            'name' => 'Critical Whey, Salted Caramel - 2000 grams',
            'sku' => 'P46040',
            'quantity' => 1,
            'price' => '0.00'
        ],
        [
            'name' => 'Switch On, Purple Haze - 225 grams',
            'sku' => 'P45771',
            'quantity' => 1,
            'price' => '0.00'
        ],
        [
            'name' => 'Glutamine Drive, Unflavored - 1000 grams',
            'sku' => 'P29849',
            'quantity' => 1,
            'price' => '0.00'
        ],
        [
            'name' => 'Horny Goat Weed Complex - 120 caps',
            'sku' => 'P42289',
            'quantity' => 1,
            'price' => '0.00'
        ]
    ]
];

echo "=== Price Calculation Fix Test ===\n\n";
echo "Before fix:\n";
foreach ($testOrder['line_items'] as $item) {
    echo "SKU: {$item['sku']}, Qty: {$item['quantity']}, Price: €{$item['price']}\n";
}

echo "\n--- Applying fix ---\n\n";
$fixedOrder = recalculateLineItemPricesTest($testOrder);

echo "\nAfter fix:\n";
$totalCheck = 0;
foreach ($fixedOrder['line_items'] as $item) {
    echo "SKU: {$item['sku']}, Qty: {$item['quantity']}, Price: €{$item['price']}\n";
    $totalCheck += (float)$item['price'] * (int)$item['quantity'];
}

echo "\nVerification:\n";
echo "Original total: €{$testOrder['total_price']}\n";
echo "Calculated total: €" . number_format($totalCheck, 2) . "\n";
echo "Match: " . (abs($totalCheck - (float)$testOrder['total_price']) < 0.01 ? 'YES ✅' : 'NO ❌') . "\n";
?> 