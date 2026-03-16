
This document describes the Points Redemption feature that allows customers to redeem their loyalty points for discounts in the checkout.

## Overview

The Points Redemption feature enables customers to convert their loyalty points (coins) into euro discounts. The system works by purchasing discount code products from LoyaltyEngage - each €1 discount requires purchasing one discount product.

### How it works

1. Customer has loyalty points/coins available (stored in `le_available_coins` custom field)
2. Customer chooses how many points to redeem in checkout
3. The plugin calls the LoyaltyEngage API to buy discount code products (one per euro)
4. A discount line item is added to the Shopware cart
5. The discount is applied to the order total

## Configuration

Configure the Points Redemption feature in the Shopware Admin under:
**Settings → Extensions → LoyaltyEngage → Points Redemption Settings**

| Setting | Description | Default |
|---------|-------------|---------|
| Enable Points Redemption | Enable/disable the feature | `false` |
| Discount Product SKU | The SKU of the €1 discount product in LoyaltyEngage | - |
| Points per Euro | How many points are needed for €1 discount | `1` |
| Minimum Points to Redeem | Minimum points required per redemption | `1` |
| Maximum Points per Order | Maximum points that can be redeemed per order (0 = unlimited) | `0` |
| Maximum Discount Percentage | Maximum % of order total payable with points (0 = unlimited) | `0` |

### LoyaltyEngage Setup

Before using this feature, you need to create a discount code product in LoyaltyEngage:

1. Log in to LoyaltyEngage admin
2. Create a new discount code product with a value of €1
3. Note the SKU of this product
4. Enter the SKU in the Shopware plugin configuration

## API Endpoints

### Store API (Frontend)

These endpoints require customer authentication via session.

#### Redeem Points
```
POST /store-api/loyalty/redeem-points
```

**Request Body:**
```json
{
  "points": 10
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Successfully redeemed 10 points for €10 discount.",
  "discountAmount": 10,
  "pointsRedeemed": 10,
  "discountCodes": ["CODE1", "CODE2", "..."]
}
```

**Partial Success Response (200):**
```json
{
  "success": true,
  "partial": true,
  "message": "Partial redemption: €5 discount applied. Some points could not be redeemed.",
  "discountAmount": 5,
  "pointsRedeemed": 5,
  "discountCodes": ["CODE1", "CODE2", "..."]
}
```

**Error Response (400):**
```json
{
  "success": false,
  "message": "Insufficient points. You have 5 points available."
}
```

#### Remove Points Discount
```
DELETE /store-api/loyalty/redeem-points
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Loyalty points discount removed from cart."
}
```

#### Get Redemption Info
```
GET /store-api/loyalty/redeem-points/info
```

**Response (200):**
```json
{
  "enabled": true,
  "customerPoints": 100,
  "pointsPerEuro": 1,
  "minPointsToRedeem": 1,
  "maxPointsPerOrder": 50,
  "maxDiscountPercentage": 50,
  "cartTotal": 150.00,
  "maxRedeemablePoints": 50,
  "maxDiscountAmount": 50,
  "existingDiscount": null
}
```

**With existing discount:**
```json
{
  "enabled": true,
  "customerPoints": 90,
  "pointsPerEuro": 1,
  "minPointsToRedeem": 1,
  "maxPointsPerOrder": 50,
  "maxDiscountPercentage": 50,
  "cartTotal": 150.00,
  "maxRedeemablePoints": 40,
  "maxDiscountAmount": 40,
  "existingDiscount": {
    "amount": 10,
    "discountAmount": 10,
    "appliedAt": "2024-01-15T10:30:00+00:00"
  }
}
```

#### Preview Redemption
```
POST /store-api/loyalty/redeem-points/preview
```

**Request Body:**
```json
{
  "points": 10
}
```

**Response (200):**
```json
{
  "success": true,
  "points": 10,
  "discountAmount": 10,
  "currentCartTotal": 150.00,
  "newCartTotal": 140.00,
  "canRedeem": true,
  "message": "You can redeem 10 points for €10 discount.",
  "customerPoints": 100,
  "maxRedeemablePoints": 50
}
```

### Admin API (Backend)

These endpoints require bearer token authentication.

#### Redeem Points
```
POST /api/v1/loyalty/shop/{email}/redeem-points
```

#### Remove Points Discount
```
DELETE /api/v1/loyalty/shop/{email}/redeem-points
```

#### Get Redemption Info
```
GET /api/v1/loyalty/shop/{email}/redeem-points/info
```

#### Preview Redemption
```
POST /api/v1/loyalty/shop/{email}/redeem-points/preview
```

## JavaScript API

The LoyaltyCartPlugin exposes several global functions for use in custom frontend implementations:

### Redeem Points
```javascript
// Redeem 10 points for €10 discount
window.redeemLoyaltyPoints(10, (success, result) => {
  if (success) {
    console.log('Discount applied:', result.discountAmount);
  } else {
    console.error('Failed:', result.message);
  }
});
```

### Remove Points Discount
```javascript
window.removeLoyaltyPointsDiscount((success, result) => {
  if (success) {
    console.log('Discount removed');
  }
});
```

### Get Redemption Info
```javascript
window.getLoyaltyRedemptionInfo((result) => {
  if (result.enabled) {
    console.log('Available points:', result.customerPoints);
    console.log('Max redeemable:', result.maxRedeemablePoints);
  }
});
```

### Preview Redemption
```javascript
window.previewLoyaltyPointsRedemption(10, (result) => {
  if (result.canRedeem) {
    console.log('Discount would be:', result.discountAmount);
    console.log('New cart total:', result.newCartTotal);
  } else {
    console.log('Cannot redeem:', result.message);
  }
});
```

## HTML Integration

### Using Buttons with Data Attributes

```html
<!-- Redeem specific amount of points -->
<button class="loyalty-redeem-points-button" data-points="10">
  Redeem 10 Points (€10 discount)
</button>

<!-- Redeem points from input field -->
<input type="number" class="loyalty-points-input" min="1" placeholder="Enter points">
<button class="loyalty-redeem-points-button">
  Redeem Points
</button>

<!-- Remove existing discount -->
<button class="loyalty-remove-points-discount-button">
  Remove Points Discount
</button>
```

### Custom Input Selector

```html
<input type="number" id="my-points-input" min="1">
<button class="loyalty-redeem-points-button" data-points-input="#my-points-input">
  Redeem
</button>
```

## Error Handling

The API returns descriptive error messages for common scenarios:

| Error | Message |
|-------|---------|
| Feature disabled | "Points redemption is not enabled." |
| No SKU configured | "Discount product SKU is not configured." |
| Below minimum | "Minimum {X} points required to redeem." |
| Above maximum | "Maximum {X} points can be redeemed per order." |
| Exceeds cart total | "Discount cannot exceed cart total. You can redeem up to {X} points." |
| Exceeds percentage limit | "Maximum discount is {X}% of cart total. You can redeem up to {Y} points." |
| Insufficient points | "Insufficient points. You have {X} points available." |
| API failure | "Failed to redeem points. Please try again later." |

## Cart Line Item

When points are redeemed, a special line item is added to the cart:

- **Type:** `loyalty_points_discount`
- **ID:** `loyalty-points-discount`
- **Label:** "Loyalty Points Discount"
- **Price:** Negative value (discount)

The line item includes payload data:
```json
{
  "discountType": "loyalty_points",
  "discountAmount": 10,
  "appliedAt": "2024-01-15T10:30:00+00:00"
}
```

## Validation Rules

The service validates redemption requests against:

1. **Feature enabled** - Points redemption must be enabled in config
2. **SKU configured** - Discount product SKU must be set
3. **Minimum points** - Must meet minimum points threshold
4. **Maximum points** - Cannot exceed maximum per order (if set)
5. **Cart total** - Discount cannot exceed cart total
6. **Percentage limit** - Discount cannot exceed percentage of cart (if set)
7. **Available points** - Customer must have sufficient points

## LoyaltyEngage API Integration

The plugin uses the LoyaltyEngage API endpoint:

```
POST /api/v1/loyalty/shop/{email}/cart/buy_discount_code
```

**Request:**
```json
{
  "sku": "DISCOUNT-1EUR"
}
```

For a €10 discount, this endpoint is called 10 times (once per euro).

If any call fails during bulk purchase, the process stops and a partial discount is applied for the successful purchases.
