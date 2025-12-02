# LoyaltyEngage Plugin Troubleshooting Guide

This guide provides solutions for common issues with the LoyaltyEngage plugin.

## 500 Error When Changing Order Delivery Status

If you encounter a 500 error (AxiosError: Request failed with status code 500) when changing the order delivery status, follow these troubleshooting steps:

### 1. Verify API Configuration

The API configuration appears to be correct. We've tested the API connection directly and it's working:

```bash
# Test API connection
docker exec shopware php /var/www/html/test-api.php
```

This test successfully sent a test event to the LoyaltyEngage API and received a 200 OK response.

### 2. Check Shopware State Machine Configuration

The issue might be related to the Shopware state machine configuration. Here's how to check it:

```bash
# List all state machines
docker exec shopware bin/console state-machine:dump
```

### 3. Check for Errors in the Shopware Log

Look for specific errors related to the state machine transition:

```bash
# Search for state machine errors
docker exec shopware grep -i "state machine" /var/www/html/var/log/dev.log | grep -i error
```

### 4. Possible Solutions

#### Solution 1: Clear Shopware Cache

```bash
docker exec shopware bin/console cache:clear
```

#### Solution 2: Rebuild Shopware State Machine

```bash
docker exec shopware bin/console state-machine:rebuild
```

#### Solution 3: Check for Missing Order Data

The error might be caused by missing data in the order. Make sure the order has:
- A valid customer email
- Line items with product IDs
- A delivery associated with it

#### Solution 4: Use the Direct API Script

We've created a script that directly uses the Shopware API to update the order delivery state, bypassing the Shopware Admin interface:

1. Get the order ID and delivery ID from the order details page in the Shopware Admin
2. Run the script:
   ```bash
   docker exec shopware php /var/www/html/update-order-state.php <order-id> <delivery-id>
   ```
3. Check the logs to see if the state change was successful:
   ```bash
   docker exec shopware grep "Order delivery state update" /var/www/html/var/log/dev.log | tail -n 5
   ```

This script will:
- Get an access token from the Shopware API
- Update the order delivery state to "done"
- Log the result to the dev.log file

**Note**: When using the direct API script, the event subscribers may not be triggered. This is because the API call bypasses the normal Shopware event dispatching mechanism. The script successfully updates the order delivery state, but the purchase and return events may not be sent to the loyalty system.

#### Solution 5: Fix Event Subscriber Registration

The issue might be related to how the event subscribers are registered. Make sure the event subscribers are registered correctly in the services.xml file:

```xml
<service id="LoyaltyEngage\Subscriber\PurchaseSubscriber">
    <argument type="service" id="LoyaltyEngage\Service\LoyaltyEngageApiService"/>
    <argument type="service" id="order_delivery.repository"/>
    <argument type="service" id="logger"/>
    <tag name="kernel.event_subscriber"/>
</service>

<service id="LoyaltyEngage\Subscriber\ReturnSubscriber">
    <argument type="service" id="LoyaltyEngage\Service\LoyaltyEngageApiService"/>
    <argument type="service" id="order_delivery.repository"/>
    <argument type="service" id="logger"/>
    <tag name="kernel.event_subscriber"/>
</service>
```

Make sure the repository service IDs are correct and that the event subscribers are tagged as kernel.event_subscriber.

#### Solution 4: Modify the PurchaseSubscriber to Handle Errors

Update the PurchaseSubscriber to catch and handle errors that might occur during the state machine transition:

1. Edit `src/Subscriber/PurchaseSubscriber.php`
2. Wrap the entire `onOrderDeliveryStateChanged` method in a try-catch block:

```php
public function onOrderDeliveryStateChanged(StateMachineTransitionEvent $event): void
{
    try {
        // Existing code...
    } catch (\Exception $e) {
        $this->logger->error('Error in order delivery state change handler: ' . $e->getMessage(), [
            'exception' => $e,
            'deliveryId' => $event->getEntityId(),
            'toState' => $event->getToPlace()
        ]);
    }
}
```

#### Solution 5: Check for Circular Dependencies

The error might be caused by circular dependencies in the service configuration. Check the services.xml file for any circular dependencies.

### 5. Test with a Simplified Order

Create a simple test order with minimal data to see if the issue persists:

1. Create a new order with a single product
2. Add a customer with a valid email
3. Try to change the delivery state to "shipped"

### 6. Enable Debug Mode

Enable debug mode in Shopware to get more detailed error messages:

1. Edit the `.env` file in the Shopware root directory
2. Set `APP_ENV=dev` and `APP_DEBUG=1`
3. Clear the cache:
   ```bash
   docker exec shopware bin/console cache:clear
   ```

### 7. Check Browser Console for JavaScript Errors

When changing the order delivery state, check the browser console for any JavaScript errors that might provide more information about the issue.

## Logs Not Appearing

If you're not seeing any logs in the Shopware log files, follow these steps:

### 1. Verify Logging is Enabled

Make sure logging is enabled in the plugin configuration:

```bash
docker exec shopware bin/console system:config:get LoyaltyEngage.config.loggerEnable
```

This should return `1` if logging is enabled.

### 2. Test Logging Directly

Test logging directly to the Shopware log file:

```bash
docker exec shopware php -r "file_put_contents('/var/www/html/var/log/dev.log', '['.date('Y-m-d H:i:s').'] app.INFO: Test log entry'.PHP_EOL, FILE_APPEND);"
```

Then check if the log entry was added:

```bash
docker exec shopware grep "Test log entry" /var/www/html/var/log/dev.log
```

### 3. Check Log File Permissions

Make sure the log file has the correct permissions:

```bash
docker exec shopware ls -la /var/www/html/var/log/
```

The log file should be writable by the web server user (www-data).

### 4. Check for Log Rotation

The log file might have been rotated. Check for older log files:

```bash
docker exec shopware ls -la /var/www/html/var/log/
```

### 5. Increase Log Level

Increase the log level to get more detailed logs:

```bash
docker exec shopware bin/console system:config:set Shopware.logger.level debug
```

Then clear the cache:

```bash
docker exec shopware bin/console cache:clear
```
