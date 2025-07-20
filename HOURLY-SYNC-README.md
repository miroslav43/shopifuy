# Hourly Sync Setup

## Overview
The hourly sync automatically fetches orders from Shopify (via JavaScript OAuth) and processes orders without ANY tags to PowerBody, running every hour via cron.

## Files Created
- `sync-hourly.sh` - Main hourly sync wrapper (cron-friendly)
- `monitor-sync.sh` - Log monitoring and status tool

## Cron Job
```bash
0 * * * * /root/Miro/Shopify2/shopifuy/sync-hourly.sh
```
This runs at the top of every hour (00:00, 01:00, 02:00, etc.)

## Log Files
- `logs/sync-hourly.log` - Main execution log (rotated at 1000 lines)
- `logs/sync-hourly-error.log` - Error log (if any)
- `logs/js-server.log` - JavaScript server log

## Monitoring Commands

### Quick Status
```bash
./monitor-sync.sh
```

### Follow Logs in Real-Time
```bash
./monitor-sync.sh --tail
```

### Statistics
```bash
./monitor-sync.sh --count
```

### Check for Errors
```bash
./monitor-sync.sh --errors
```

### View All Information
```bash
./monitor-sync.sh --all
```

## How It Works

1. **STEP 1**: JavaScript server fetches fresh orders from Shopify (OAuth)
2. **STEP 2**: PHP processes orders without ANY tags to PowerBody
3. **STEP 3**: Successful orders get tagged to prevent reprocessing

## Order Processing Logic

- ✅ **Processes**: Orders with NO tags at all
- ❌ **Skips**: Orders with ANY tags (already processed)
- 🏷️ **Tags**: Successfully processed orders get `PB_SYNCED` tag

## Troubleshooting

### Check if JavaScript server is running:
```bash
curl -s http://localhost:3000/
```

### Check cron job:
```bash
crontab -l
```

### Manual run (test):
```bash
./sync-hourly.sh
```

### View recent log entries:
```bash
tail -50 logs/sync-hourly.log
```

## Environment Requirements

- Node.js server must be running on port 3000
- `.env` file must be properly configured
- Shopify app must be authenticated with current ngrok host
- PowerBody API credentials must be valid

## Success Indicators

When working correctly, you should see:
- ✅ Cron job active
- ✅ JavaScript server running
- ✅ No errors in error log
- ✅ Recent successful syncs in main log
- ✅ Orders getting processed and tagged 