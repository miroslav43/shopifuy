# Shopify Orders Fetcher

A Node.js Express application that fetches Shopify orders via GraphQL API and saves them to JSON files.

## Features

- OAuth authentication with Shopify stores
- Fetch the latest 50 orders from a Shopify store
- Save fetched orders to JSON files with timestamps
- Webhook support for real-time order notifications
- Clean, modern UI for easy interaction
- Command-line tools for direct API access
- Batch processing to fetch ALL orders using pagination

## Prerequisites

- Node.js 18.0.0 or higher
- A Shopify Partner account
- A Shopify app with the following permissions:
  - `read_orders`
  - `read_customers`

## Installation

1. Clone or navigate to this directory
2. Install dependencies:
```bash
npm install
# or use the setup script
npm run setup
```

## Configuration

Create a `.env` file in the FetchOrdersJava directory with the following variables:

```bash
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
```

## Setting Up Your Shopify App

1. Go to your [Shopify Partners Dashboard](https://partners.shopify.com/)
2. Create a new app or use an existing one
3. Set the following URLs in your app settings:
   - **App URL**: `https://your-domain.com/`
   - **Allowed redirection URL(s)**: `https://your-domain.com/auth/callback`
4. Copy your API key and API secret to the `.env` file

## Getting Access Token

To use the command-line tools, you need an access token. You can get this by:

1. **OAuth Flow** (Recommended): Use the web interface to authenticate and get the token
2. **Admin API**: Generate a private app access token from your Shopify admin
3. **Development**: Use your development store's admin API access token

## Usage

### Method 1: Web Interface (OAuth Flow)

1. Start the application:
```bash
npm start
```

2. Navigate to `http://localhost:3000` in your browser

3. To install the app on a Shopify store, visit:
```
http://localhost:3000/auth?shop=YOUR_SHOP_NAME.myshopify.com
```

4. After successful authentication, fetch orders by visiting:
```
http://localhost:3000/orders?shop=YOUR_SHOP_NAME.myshopify.com
```

### Method 2: Command Line (Direct API)

No parameters needed - all configuration is read from `.env` file:

```bash
# Fetch recent 50 orders
npm run fetch

# Fetch ALL orders using pagination
npm run batch-fetch

# Or run directly
node fetch-orders.js
node batch-fetch.js
```

### Method 3: NPM Scripts

```bash
npm run help          # Show available commands
npm run setup         # Run automated setup
npm start            # Start web server
npm run dev          # Start with auto-reload
npm run fetch        # Fetch recent orders
npm run batch-fetch  # Fetch all orders
```

## API Endpoints

- `GET /` - Home page with installation instructions
- `GET /auth?shop=SHOP_NAME` - Start OAuth flow
- `GET /auth/callback` - OAuth callback handler
- `GET /orders?shop=SHOP_NAME` - Fetch and save orders to JSON
- `POST /webhooks/orders-create` - Webhook endpoint for new orders

## JSON Output

Fetched orders are saved in the following format:
```json
{
  "timestamp": "2024-01-01T12:00:00.000Z",
  "shop": "your-shop.myshopify.com",
  "orders": {
    "orders": {
      "edges": [
        {
          "node": {
            "id": "gid://shopify/Order/123456789",
            "name": "#1001",
            "createdAt": "2024-01-01T10:00:00Z",
            "totalPriceSet": {
              "shopMoney": {
                "amount": "100.00",
                "currencyCode": "USD"
              }
            },
            "customer": {
              "firstName": "John",
              "lastName": "Doe",
              "email": "john@example.com",
              "phone": "+1234567890"
            },
            "shippingAddress": {
              "address1": "123 Main St",
              "city": "New York",
              "province": "NY",
              "country": "US",
              "zip": "10001"
            },
            "lineItems": {
              "edges": [
                {
                  "node": {
                    "title": "Product Name",
                    "quantity": 1,
                    "sku": "PROD-123"
                  }
                }
              ]
            }
          }
        }
      ]
    }
  },
  "totalOrders": 25
}
```

### File Naming Convention

- **Recent orders**: `orders_shop-domain_timestamp.json`
- **All orders**: `all_orders_shop-domain_timestamp.json`

All files are saved in the `orders_data/` directory with timestamps for easy tracking.

## Development

For development with automatic restart:
```bash
npm run dev
```

## Environment Variables Reference

| Variable | Required | Description | Example |
|----------|----------|-------------|---------|
| `SHOPIFY_API_KEY` | Yes | Your app's API key | `abc123...` |
| `SHOPIFY_API_SECRET` | Yes | Your app's API secret | `def456...` |
| `HOST` | Yes | Your app's public URL | `https://myapp.com` |
| `SHOPIFY_SHOP_DOMAIN` | For CLI | Shop domain | `mystore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | For CLI | Access token | `shpca_...` |
| `SHOPIFY_API_VERSION` | No | API version | `2024-01` |
| `SCOPES` | No | Required permissions | `read_orders,read_customers` |
| `SHOPIFY_BATCH_SIZE` | No | Batch size for pagination | `50` |
| `PORT` | No | Server port | `3000` |
| `NODE_ENV` | No | Environment | `development` |

## Troubleshooting

1. **Missing environment variables**: Make sure all required variables are set in `.env`
2. **OAuth issues**: Verify your app URLs in the Shopify Partner Dashboard
3. **Access token issues**: Check that `SHOPIFY_ACCESS_TOKEN` is valid and has required permissions
4. **Shop domain issues**: Ensure `SHOPIFY_SHOP_DOMAIN` includes `.myshopify.com`
5. **Webhook failures**: Ensure your HOST URL is publicly accessible
6. **Rate limiting**: Reduce `SHOPIFY_BATCH_SIZE` if you encounter rate limits

## Security Notes

- This is a demo application - implement proper session storage for production
- Use environment variables for all sensitive configuration
- Never commit `.env` files to version control
- Implement proper error handling and logging for production use
- Consider rate limiting and authentication for production deployments 