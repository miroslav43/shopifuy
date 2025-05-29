# PowerShell script to test OAuth order fetching
# Usage: .\test_oauth_orders.ps1

Write-Host "=== Shopify OAuth Order Test ===" -ForegroundColor Green
Write-Host ""

# Check if PHP is available
try {
    $phpVersion = php -v 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "PHP not found"
    }
    Write-Host "✓ PHP is available" -ForegroundColor Green
} catch {
    Write-Host "✗ PHP is not available in PATH" -ForegroundColor Red
    Write-Host "Please install PHP or add it to your PATH" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "This script will help you test OAuth authentication with your Partner app." -ForegroundColor Cyan
Write-Host ""

# Get store domain
$storeDomain = Read-Host "Enter your store domain (e.g., your-store.myshopify.com)"

if ([string]::IsNullOrWhiteSpace($storeDomain)) {
    Write-Host "Error: Store domain is required" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Choose an option:" -ForegroundColor Yellow
Write-Host "1. Get OAuth access token (first time setup)"
Write-Host "2. Test order fetching with existing token"
Write-Host ""

$choice = Read-Host "Enter your choice (1 or 2)"

switch ($choice) {
    "1" {
        Write-Host ""
        Write-Host "=== Getting OAuth Access Token ===" -ForegroundColor Green
        Write-Host ""
        
        # Run the OAuth token helper
        php bin/get_oauth_token.php --store=$storeDomain
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host ""
            Write-Host "✓ OAuth token setup completed!" -ForegroundColor Green
            Write-Host "You can now run option 2 to test order fetching." -ForegroundColor Cyan
        } else {
            Write-Host "✗ OAuth token setup failed" -ForegroundColor Red
        }
    }
    
    "2" {
        Write-Host ""
        Write-Host "=== Testing Order Fetching ===" -ForegroundColor Green
        Write-Host ""
        
        # Ask for number of days
        $days = Read-Host "Number of days to fetch orders (default: 30)"
        if ([string]::IsNullOrWhiteSpace($days)) {
            $days = "30"
        }
        
        # Run the OAuth order fetcher
        php bin/test_fetch_orders_oauth.php --store=$storeDomain --days=$days
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host ""
            Write-Host "✓ Order fetching completed!" -ForegroundColor Green
            Write-Host "Check the storage/ directory for the output JSON file." -ForegroundColor Cyan
        } else {
            Write-Host "✗ Order fetching failed" -ForegroundColor Red
        }
    }
    
    default {
        Write-Host "Invalid choice. Please run the script again and choose 1 or 2." -ForegroundColor Red
        exit 1
    }
}

Write-Host ""
Write-Host "=== Script Complete ===" -ForegroundColor Green 