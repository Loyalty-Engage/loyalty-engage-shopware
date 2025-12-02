# LoyaltyEngage External HTML Integration

This document explains how to integrate the LoyaltyEngage cart functionality into external HTML pages or CMS elements.

## Overview

The LoyaltyEngage plugin now exposes a global JavaScript function that allows you to add products to the loyalty cart from any HTML content that is loaded within a Shopware page where the plugin is active.

## Integration Methods

There are several ways to integrate the loyalty cart functionality into your external HTML:

1. **Simple HTML Snippet**: A self-contained HTML snippet that can be pasted into any CMS element
2. **Embeddable Widget**: A complete HTML file that can be loaded in an iframe
3. **Custom Implementation**: Using the global JavaScript function directly in your custom code

## Method 1: Simple HTML Snippet

Copy and paste the following snippet into your CMS element or HTML content. Replace `PRODUCT-ID-HERE` with your actual product ID.

```html
<!-- LoyaltyEngage Cart Button Snippet -->
<div style="font-family: Arial, sans-serif; padding: 15px; border: 1px solid #ddd; border-radius: 5px; max-width: 300px;">
    <h3 style="margin-top: 0;">Redeem with Loyalty Points</h3>
    <p>Use your loyalty points to get this product for free!</p>
    <button 
        id="loyaltyButton" 
        style="background-color: #6c2eb9; color: white; border: none; border-radius: 4px; padding: 10px 15px; cursor: pointer; font-weight: bold; width: 100%;"
        onclick="redeemWithLoyaltyPoints('PRODUCT-ID-HERE')">
        Redeem Now
    </button>
    <div id="loyaltyMessage" style="margin-top: 10px; padding: 8px; border-radius: 4px; display: none;"></div>
</div>

<script>
    function redeemWithLoyaltyPoints(productId) {
        // Get message element
        const messageEl = document.getElementById('loyaltyMessage');
        
        // Check if the global function exists
        if (typeof window.addProductToLoyaltyCart === 'function') {
            // Call the global function
            window.addProductToLoyaltyCart(productId, function(success, message) {
                // Show message
                messageEl.textContent = message;
                
                if (success) {
                    messageEl.style.backgroundColor = '#d4edda';
                    messageEl.style.color = '#155724';
                    messageEl.style.border = '1px solid #c3e6cb';
                } else {
                    messageEl.style.backgroundColor = '#f8d7da';
                    messageEl.style.color = '#721c24';
                    messageEl.style.border = '1px solid #f5c6cb';
                }
                
                messageEl.style.display = 'block';
            });
        } else {
            // Show error message
            messageEl.textContent = 'LoyaltyEngage plugin is not loaded.';
            messageEl.style.backgroundColor = '#f8d7da';
            messageEl.style.color = '#721c24';
            messageEl.style.border = '1px solid #f5c6cb';
            messageEl.style.display = 'block';
        }
    }
</script>
```

## Method 2: Embeddable Widget

You can use the `loyalty-cart-widget.html` file as an embeddable widget. Upload this file to your server and embed it in an iframe:

```html
<iframe src="loyalty-cart-widget.html?productId=YOUR-PRODUCT-ID" width="320" height="200" frameborder="0"></iframe>
```

The widget accepts a `productId` parameter in the URL to specify which product to add to the cart.

## Method 3: Custom Implementation

If you want to implement your own UI, you can directly use the global JavaScript function:

```javascript
// Check if the function is available
if (typeof window.addProductToLoyaltyCart === 'function') {
    // Call the function with product ID and callback
    window.addProductToLoyaltyCart('YOUR-PRODUCT-ID', function(success, message) {
        if (success) {
            // Handle success
            console.log('Product added successfully:', message);
            // You might want to update the UI or redirect
        } else {
            // Handle error
            console.error('Failed to add product:', message);
            // Show error message to the user
        }
    });
} else {
    console.error('LoyaltyEngage plugin is not loaded');
    // Show error message to the user
}
```

## Function Reference

### `window.addProductToLoyaltyCart(productId, callback)`

Adds a product to the loyalty cart.

**Parameters:**

- `productId` (string): The ID of the product to add to the cart
- `callback` (function, optional): A function that will be called when the operation completes
  - The callback receives two parameters:
    - `success` (boolean): Whether the operation was successful
    - `message` (string): A message describing the result

**Example:**

```javascript
window.addProductToLoyaltyCart('product-123', function(success, message) {
    console.log(success ? 'Success: ' : 'Error: ', message);
});
```

## Important Notes

1. The HTML page or CMS element must be loaded within a Shopware page where the LoyaltyEngage plugin is active
2. The user must be logged in to use the loyalty cart functionality
3. If the user is not logged in, they will be redirected to the login page
4. The product ID must be valid and the product must be available for redemption with loyalty points

## Troubleshooting

If the "Redeem with Points" button doesn't work:

1. Make sure the LoyaltyEngage plugin is installed and activated
2. Check that the user is logged in
3. Verify that the product ID is correct
4. Check the browser console for any JavaScript errors
