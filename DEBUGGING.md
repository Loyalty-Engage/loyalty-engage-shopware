# LoyaltyEngage Plugin Debugging Guide

This guide explains how to debug the LoyaltyEngage plugin, including how to view logs for purchase events, return events, and cart operations.

## Enabling Logging

By default, the LoyaltyEngage plugin has logging disabled. To enable logging:

1. Log in to your Shopware Admin Panel
2. Navigate to **Settings > System > Plugins**
3. Find the **LoyaltyEngage** plugin and click on the **Configuration** button (gear icon)
4. Set the **Logger Enable** option to **Yes**
5. Save the configuration

## Viewing Logs

Shopware stores logs in the `var/log` directory of your Shopware installation. The LoyaltyEngage plugin logs are part of the main Shopware log files.

### Accessing Logs via Command Line

In development mode, logs are stored in the `dev.log` file:

```bash
# View the latest log entries
docker exec shopware tail -f /var/www/html/var/log/dev.log

# Search for LoyaltyEngage logs
docker exec shopware grep "LoyaltyEngage" /var/www/html/var/log/dev.log

# Search for order delivery state changes
docker exec shopware grep "Order delivery state changed" /var/www/html/var/log/dev.log
```

In production mode, logs are stored in date-specific files:

```bash
# View the latest log file
docker exec shopware tail -f /var/www/html/var/log/prod-$(date +%Y-%m-%d).log

# Search for LoyaltyEngage logs
docker exec shopware grep "LoyaltyEngage" /var/www/html/var/log/prod-$(date +%Y-%m-%d).log
```

### Accessing Logs via Shopware Admin

1. Log in to your Shopware Admin Panel
2. Navigate to **Settings > System > Logs**
3. Filter the logs by searching for "LoyaltyEngage"

### Testing the Plugin with Order State Changes

To test the plugin and see logs for purchase events:

1. Create a test order in the Shopware Admin Panel:
   - Go to **Orders > Create order**
   - Add products, customer information, and complete the order

2. Change the order delivery state to "shipped":
   - Go to **Orders** and find your test order
   - Click on the order to view details
   - In the "Deliveries" section, click on the state dropdown
   - Select "Ship" to change the state to "shipped"

3. View the logs to see the purchase event:
   ```bash
   docker exec shopware grep "Order delivery state changed" /var/www/html/var/log/dev.log | tail -n 20
   ```

4. You should see log entries like:
   ```
   [2025-04-02 10:00:00] app.INFO: Order delivery state changed {"deliveryId":"123","fromState":"open","toState":"shipped","purchaseExportEnabled":true}
   ```

5. You should also see logs for the API call to send the purchase event:
   ```bash
   docker exec shopware grep "LoyaltyEngage sending event to API" /var/www/html/var/log/dev.log | tail -n 20
   ```

## Log Types and Interpretation

The LoyaltyEngage plugin logs different types of events:

### 1. Cart Operations Logs

When products are added to or removed from the loyalty cart, the plugin logs the following information:

#### Adding Products

```
[2025-04-02 09:00:00] app.INFO: LoyaltyEngage Add to Cart Response: {"email":"customer@example.com","sku":"PRODUCT-ID","quantity":1,"response_code":200,"response_body":"{\"success\":true,\"message\":\"Product added to cart successfully\"}"}
```

#### Removing Products

```
[2025-04-02 09:00:00] app.ERROR: LoyaltyEngage Remove Item Error: {"email":"customer@example.com","sku":"PRODUCT-ID","error":"Connection timeout"}
```

#### Removing All Products

```
[2025-04-02 09:00:00] app.ERROR: LoyaltyEngage Remove All Items Error: {"email":"customer@example.com","error":"API returned 404"}
```

### 2. Purchase Event Logs

When an order is shipped and sent to the loyalty system:

```
[2025-04-02 09:00:00] app.ERROR: Failed to send purchase event {"orderId":"10000","response":400}
```

Or in case of an exception:

```
[2025-04-02 09:00:00] app.ERROR: Error sending purchase event: Connection refused {"orderId":"10000","exception":"[object] (Exception(code: 0): Connection refused)"}
```

Note: Purchase events are sent when an order's delivery status changes to "shipped", not when the order is completed.

### 3. Return Event Logs

When an order is returned and the event is sent to the loyalty system:

```
[2025-04-02 09:00:00] app.ERROR: Failed to send return event {"orderId":"10000","response":400}
```

Or in case of an exception:

```
[2025-04-02 09:00:00] app.ERROR: Error sending return event: Connection refused {"orderId":"10000","exception":"[object] (Exception(code: 0): Connection refused)"}
```

### 4. Scheduled Task Logs

The plugin also logs information about scheduled tasks:

#### Cart Expiry Task

```
[2025-04-02 09:00:00] app.INFO: No expired carts found to process.
```

Or when processing expired carts:

```
[2025-04-02 09:00:00] app.INFO: Cart ID 12345 processed successfully.
```

#### Order Place Task

```
[2025-04-02 09:00:00] app.INFO: Order 10000 placed successfully in loyalty system.
```

## Debugging API Requests

To debug API requests to the LoyaltyEngage API, you can use the following approaches:

### 1. Enable Verbose Logging in Shopware

Edit your `.env` file in your Shopware root directory and set:

```
APP_ENV=dev
APP_DEBUG=1
```

This will enable more detailed logging, including HTTP requests.

### 2. Use a Network Monitoring Tool

You can use tools like:

- **Browser Developer Tools**: Open the Network tab to monitor AJAX requests
- **Postman**: To manually test API endpoints
- **Charles Proxy** or **Fiddler**: To intercept and inspect HTTP traffic

### 3. Test API Endpoints Directly

You can test the API endpoints directly using curl:

```bash
# Add a product to the loyalty cart
curl -X POST \
  "http://your-shopware-domain/api/v1/loyalty/shop/customer@example.com/cart/add" \
  -H "Content-Type: application/json" \
  -d '{"productId":"PRODUCT-ID"}'

# Remove a product from the loyalty cart
curl -X POST \
  "http://your-shopware-domain/api/v1/loyalty/shop/customer@example.com/cart/remove" \
  -H "Content-Type: application/json" \
  -d '{"productId":"PRODUCT-ID"}'

# Remove all products from the loyalty cart
curl -X DELETE \
  "http://your-shopware-domain/api/v1/loyalty/shop/customer@example.com/cart"
```

## Common Issues and Solutions

### 1. No Logs Appearing

- Ensure logging is enabled in the plugin configuration
- Check that you have sufficient disk space
- Verify the log directory is writable by the web server

### 2. API Calls Failing

- Verify the API URL, Tenant ID, and Bearer Token in the plugin configuration
- Check network connectivity to the LoyaltyEngage API
- Ensure the API endpoints are correctly formatted

### 3. Events Not Being Sent

- Verify that Purchase Export and/or Return Export are enabled in the plugin configuration
- Check that the order state transitions are working correctly
- Ensure the customer email is correctly associated with the order

## Increasing Log Detail Level

For more detailed debugging, you can temporarily modify the plugin code to add additional log statements:

1. Open the relevant PHP file (e.g., `src/Service/LoyaltyEngageApiService.php`)
2. Add additional logging statements:

```php
$this->logger->debug('Detailed debug information', [
    'variable' => $variable,
    'context' => $context
]);
```

3. Clear the cache:

```bash
docker exec shopware bin/console cache:clear
```

Remember to remove these additional logging statements before deploying to production.
