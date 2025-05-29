<?php
/**
 * OAuth Token Helper for Shopify Partner App
 * 
 * This script helps you get an OAuth access token for your Partner app.
 * 
 * Usage:
 *   php bin/get_oauth_token.php --store=your-store.myshopify.com
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Partner App Credentials
const PB_API_CLIENT = '694cc0c660b3ba0e1b1e71517e1ca8a3';
const PB_API_SECRET = 'b4fe69a51d814fa3457954837a081255';

// Parse command line arguments
$options = getopt('', ['store::']);
$storeDomain = isset($options['store']) ? $options['store'] : null;

// Get store domain
if (!$storeDomain) {
    $storeDomain = readline("Enter your store domain (e.g., your-store.myshopify.com): ");
}

if (empty($storeDomain)) {
    echo "Error: Store domain is required.\n";
    exit(1);
}

// Remove protocol if provided
$storeDomain = str_replace(['https://', 'http://'], '', $storeDomain);

echo "=== Shopify OAuth Token Generator ===\n";
echo "Store: $storeDomain\n";
echo "Client ID: " . PB_API_CLIENT . "\n\n";

// Required scopes for order and customer data
$scopes = [
    'read_orders',
    'read_customers',
    'read_products',
    'write_orders',  // For adding tags
    'read_shipping'
];

$scopeString = implode(',', $scopes);

// Generate OAuth URL
$redirectUri = 'https://localhost/oauth/callback'; // You can change this
$state = bin2hex(random_bytes(16)); // Random state for security

$oauthUrl = "https://{$storeDomain}/admin/oauth/authorize?" . http_build_query([
    'client_id' => PB_API_CLIENT,
    'scope' => $scopeString,
    'redirect_uri' => $redirectUri,
    'state' => $state,
    'grant_options[]' => 'per-user'
]);

echo "Step 1: Install the app by visiting this URL:\n";
echo "----------------------------------------\n";
echo $oauthUrl . "\n";
echo "----------------------------------------\n\n";

echo "Step 2: After authorization, you'll be redirected to:\n";
echo "$redirectUri?code=AUTHORIZATION_CODE&state=$state\n\n";

echo "Step 3: Copy the 'code' parameter from the redirect URL and enter it below:\n";
$authCode = readline("Enter the authorization code: ");

if (empty($authCode)) {
    echo "Error: Authorization code is required.\n";
    exit(1);
}

echo "\nStep 4: Exchanging authorization code for access token...\n";

// Exchange authorization code for access token
$tokenUrl = "https://{$storeDomain}/admin/oauth/access_token";

$postData = [
    'client_id' => PB_API_CLIENT,
    'client_secret' => PB_API_SECRET,
    'code' => trim($authCode)
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "Error: Failed to get access token. HTTP Code: $httpCode\n";
    echo "Response: $response\n";
    exit(1);
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    echo "Error: No access token in response.\n";
    echo "Response: $response\n";
    exit(1);
}

$accessToken = $tokenData['access_token'];
$scope = $tokenData['scope'] ?? 'unknown';

echo "✓ Access token obtained successfully!\n\n";
echo "=== SAVE THESE DETAILS ===\n";
echo "Store: $storeDomain\n";
echo "Access Token: $accessToken\n";
echo "Scope: $scope\n";
echo "==========================\n\n";

// Test the token
echo "Step 5: Testing the access token...\n";

$testUrl = "https://{$storeDomain}/admin/api/2024-10/shop.json";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Shopify-Access-Token: ' . $accessToken,
    'Content-Type: application/json'
]);

$testResponse = curl_exec($ch);
$testHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($testHttpCode === 200) {
    $shopData = json_decode($testResponse, true);
    echo "✓ Token test successful!\n";
    echo "Shop: " . ($shopData['shop']['name'] ?? 'Unknown') . "\n";
    echo "Plan: " . ($shopData['shop']['plan_name'] ?? 'Unknown') . "\n";
    echo "Domain: " . ($shopData['shop']['domain'] ?? 'Unknown') . "\n\n";
    
    echo "You can now use this access token with the OAuth test script:\n";
    echo "php bin/test_fetch_orders_oauth.php --store=$storeDomain\n";
    echo "When prompted, enter the access token: $accessToken\n";
} else {
    echo "⚠ Token test failed. HTTP Code: $testHttpCode\n";
    echo "Response: $testResponse\n";
}

echo "\n=== OAuth Setup Complete ===\n"; 