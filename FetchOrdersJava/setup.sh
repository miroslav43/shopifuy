#!/bin/bash

echo "🚀 Setting up Shopify Orders Fetcher..."

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js is not installed. Please install Node.js 18.0.0 or higher."
    echo "   Visit: https://nodejs.org/"
    exit 1
fi

# Check Node.js version
NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
if [ "$NODE_VERSION" -lt 18 ]; then
    echo "❌ Node.js version is too old. Please install Node.js 18.0.0 or higher."
    echo "   Current version: $(node -v)"
    exit 1
fi

echo "✅ Node.js $(node -v) detected"

# Install dependencies
echo "📦 Installing dependencies..."
npm install

if [ $? -ne 0 ]; then
    echo "❌ Failed to install dependencies"
    exit 1
fi

echo "✅ Dependencies installed successfully"

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "📝 Creating .env file..."
    cat > .env << EOF
# Shopify App Credentials (from your Partner Dashboard)
SHOPIFY_API_KEY=your_shopify_api_key_here
SHOPIFY_API_SECRET=your_shopify_api_secret_here

# Your app's public URL (for OAuth callbacks and webhooks)
HOST=https://your-domain.com

# Shop Configuration (for direct API access)
SHOPIFY_SHOP_DOMAIN=your-shop.myshopify.com
SHOPIFY_ACCESS_TOKEN=shpca_your_access_token_here

# Optional: Specific Shopify API version
SHOPIFY_API_VERSION=2024-01

# Optional: Custom scopes (defaults to read_orders,read_customers)
SCOPES=read_orders,read_customers

# Optional: Batch size for fetching all orders (defaults to 50, max 250)
SHOPIFY_BATCH_SIZE=50

# Optional: Server port (defaults to 3000)
PORT=3000

# Optional: Environment (development or production)
NODE_ENV=development
EOF
    echo "✅ .env file created"
    echo "⚠️  Please edit the .env file with your actual Shopify app credentials"
else
    echo "✅ .env file already exists"
fi

# Create orders data directory
mkdir -p orders_data
echo "✅ Orders data directory created"

echo ""
echo "🎉 Setup complete!"
echo ""
echo "Next steps:"
echo "1. Edit the .env file with your Shopify app credentials:"
echo "   - SHOPIFY_API_KEY and SHOPIFY_API_SECRET from Partner Dashboard"
echo "   - SHOPIFY_SHOP_DOMAIN (e.g., mystore.myshopify.com)"
echo "   - SHOPIFY_ACCESS_TOKEN (get this from OAuth flow or admin)"
echo "2. For web interface:"
echo "   - Set up your Shopify app in the Partner Dashboard"
echo "   - App URL: https://your-domain.com/"
echo "   - Redirection URL: https://your-domain.com/auth/callback"
echo "   - Run: npm start"
echo "3. For direct API access:"
echo "   - Run: npm run fetch (for recent orders)"
echo "   - Run: npm run batch-fetch (for all orders)"
echo ""
echo "📖 For detailed instructions, see README.md" 